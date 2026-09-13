// Package whatsmeow implements the session contracts on top of the upstream
// go.mau.fi/whatsmeow library. All library types stay confined to this package.
package whatsmeow

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"math/rand/v2"
	"os"
	"sync"
	"time"

	"github.com/google/uuid"
	"go.mau.fi/whatsmeow"
	"go.mau.fi/whatsmeow/proto/waE2E"
	"go.mau.fi/whatsmeow/store"
	"go.mau.fi/whatsmeow/store/sqlstore"
	"go.mau.fi/whatsmeow/types"
	"google.golang.org/protobuf/proto"

	"onefisc/wzap/internal/model"
	"onefisc/wzap/internal/session"
	"onefisc/wzap/internal/storage"
)

const (
	// restoreConcurrency limits how many persisted sessions reconnect at once.
	restoreConcurrency = 2
	// restoreJitter spreads reconnections so they do not hit the server
	// together.
	restoreJitter = 500 * time.Millisecond
	// instancePageSize is the page size used to walk the instances table.
	instancePageSize = 100
)

// Manager owns the whatsmeow client of every instance and persists the
// sessions in the Postgres device store.
type Manager struct {
	devices   *sqlstore.Container
	instances storage.InstanceRepository
	log       *slog.Logger
	sink      session.EventSink

	mu       sync.RWMutex
	sessions map[uuid.UUID]*instanceSession
}

var _ session.Manager = (*Manager)(nil)

// NewManager opens the whatsmeow device store in the Postgres database
// addressed by databaseURL. instances lets RestoreAll map persisted devices
// back to their instance and sink receives the session events.
func NewManager(ctx context.Context, databaseURL string, instances storage.InstanceRepository, log *slog.Logger, sink session.EventSink) (*Manager, error) {
	if log == nil {
		log = slog.Default()
	}
	devices, err := openDeviceStore(ctx, databaseURL, log)
	if err != nil {
		return nil, err
	}
	return &Manager{
		devices:   devices,
		instances: instances,
		log:       log,
		sink:      sink,
		sessions:  make(map[uuid.UUID]*instanceSession),
	}, nil
}

// Close releases the whatsmeow database connections.
func (m *Manager) Close() error {
	if m == nil || m.devices == nil {
		return nil
	}
	return m.devices.Close()
}

// Get returns the session of instanceID when it is known.
func (m *Manager) Get(instanceID uuid.UUID) (session.Session, bool) {
	m.mu.RLock()
	defer m.mu.RUnlock()
	sess, ok := m.sessions[instanceID]
	if !ok {
		return nil, false
	}
	return sess, true
}

// Create returns the session of instance, loading the persisted device when
// the instance already has a whatsapp_jid and building a fresh device for a
// new pairing otherwise. Repeating it for a known instance returns the
// existing session.
func (m *Manager) Create(instance *model.Instance) (session.Session, error) {
	if instance == nil {
		return nil, errors.New("create session: nil instance")
	}

	m.mu.Lock()
	defer m.mu.Unlock()
	if sess, ok := m.sessions[instance.ID]; ok {
		return sess, nil
	}

	device, err := m.deviceFor(context.Background(), instance)
	if err != nil {
		return nil, err
	}
	sess, err := newSession(instance.ID, device, m.log, m.sink)
	if err != nil {
		return nil, err
	}
	m.sessions[instance.ID] = sess
	return sess, nil
}

// Remove disconnects the session and deletes its stored credentials.
func (m *Manager) Remove(ctx context.Context, instanceID uuid.UUID) error {
	m.mu.Lock()
	sess, ok := m.sessions[instanceID]
	if ok {
		delete(m.sessions, instanceID)
	}
	m.mu.Unlock()
	if !ok {
		return nil
	}
	return sess.remove(ctx)
}

// RestoreAll reconnects every persisted session, at most restoreConcurrency at
// a time and with jitter. Failures are reported per instance through the event
// sink; only listing the instances can fail the call.
func (m *Manager) RestoreAll(ctx context.Context) error {
	if m.instances == nil {
		return errors.New("restore sessions: instance repository not configured")
	}
	instances, err := m.listInstances(ctx)
	if err != nil {
		return err
	}

	sem := make(chan struct{}, restoreConcurrency)
	var wg sync.WaitGroup
	for _, instance := range instances {
		if instance.WhatsAppJID == "" {
			continue
		}
		if _, ok := m.Get(instance.ID); ok {
			continue
		}
		wg.Add(1)
		go func(instance model.Instance) {
			defer wg.Done()
			select {
			case sem <- struct{}{}:
			case <-ctx.Done():
				return
			}
			defer func() { <-sem }()

			if err := sleepCtx(ctx, time.Duration(rand.Int64N(int64(restoreJitter)))); err != nil {
				return
			}
			if err := m.restore(ctx, instance); err != nil {
				m.log.Warn("restore session failed", "instance_id", instance.ID, "jid", instance.WhatsAppJID, "error", err)
				m.emitConnection(instance.ID, session.StatusError, instance.WhatsAppJID, err.Error())
			}
		}(instance)
	}
	wg.Wait()
	return nil
}

// restore attaches the persisted device of instance and brings it online.
func (m *Manager) restore(ctx context.Context, instance model.Instance) error {
	jid, err := types.ParseJID(instance.WhatsAppJID)
	if err != nil {
		return fmt.Errorf("parse jid %q: %w", instance.WhatsAppJID, err)
	}
	device, err := m.devices.GetDevice(ctx, jid)
	if err != nil {
		return fmt.Errorf("load device %s: %w", jid, err)
	}
	if device == nil {
		return fmt.Errorf("device %s not found", jid)
	}

	sess, err := newSession(instance.ID, device, m.log, m.sink)
	if err != nil {
		return err
	}
	m.mu.Lock()
	if _, ok := m.sessions[instance.ID]; !ok {
		m.sessions[instance.ID] = sess
	}
	m.mu.Unlock()

	return sess.connectExisting(ctx)
}

// listInstances walks the instances table page by page.
func (m *Manager) listInstances(ctx context.Context) ([]model.Instance, error) {
	var all []model.Instance
	cursor := ""
	for {
		page, next, err := m.instances.List(ctx, instancePageSize, cursor)
		if err != nil {
			return nil, fmt.Errorf("list instances: %w", err)
		}
		all = append(all, page...)
		if next == "" {
			return all, nil
		}
		cursor = next
	}
}

// deviceFor returns the device store of instance, creating a fresh one when
// the instance was never paired.
func (m *Manager) deviceFor(ctx context.Context, instance *model.Instance) (*store.Device, error) {
	if instance.WhatsAppJID == "" {
		return m.devices.NewDevice(), nil
	}
	jid, err := types.ParseJID(instance.WhatsAppJID)
	if err != nil {
		return nil, fmt.Errorf("create session: parse jid %q: %w", instance.WhatsAppJID, err)
	}
	device, err := m.devices.GetDevice(ctx, jid)
	if err != nil {
		return nil, fmt.Errorf("create session: load device %s: %w", jid, err)
	}
	if device == nil {
		return nil, fmt.Errorf("create session: device %s not found", jid)
	}
	return device, nil
}

// emitConnection reports a connection change for an instance with no session
// (used when restoring fails before a session exists).
func (m *Manager) emitConnection(instanceID uuid.UUID, status session.Status, jid, reason string) {
	if m.sink == nil {
		return
	}
	m.sink.OnConnection(context.Background(), instanceID, status, jid, reason)
}

// sleepCtx waits for d unless the context is cancelled first.
func sleepCtx(ctx context.Context, d time.Duration) error {
	timer := time.NewTimer(d)
	defer timer.Stop()
	select {
	case <-timer.C:
		return nil
	case <-ctx.Done():
		return ctx.Err()
	}
}

// instanceSession is the session.Session implementation for one instance.
type instanceSession struct {
	instanceID uuid.UUID
	client     *whatsmeow.Client
	sink       session.EventSink
	log        *slog.Logger

	mu          sync.RWMutex
	status      session.Status
	jid         string
	qrCode      string
	qrExpiresAt time.Time
	firstQR     chan qrResult
	qrCancel    context.CancelFunc
	lastReason  string
}

var _ session.Session = (*instanceSession)(nil)

// qrResult carries the outcome of a pairing attempt to the caller waiting in
// Connect.
type qrResult struct {
	code      string
	expiresAt time.Time
	err       error
}

// newSession wraps device in a connected-aware session and registers the event
// translation.
func newSession(instanceID uuid.UUID, device *store.Device, log *slog.Logger, sink session.EventSink) (*instanceSession, error) {
	if device == nil {
		return nil, errors.New("new session: nil device")
	}
	if log == nil {
		log = slog.Default()
	}
	sess := &instanceSession{instanceID: instanceID, sink: sink, log: log, status: session.StatusDisconnected}
	client := whatsmeow.NewClient(device, newWALogger(log))
	if device.ID != nil && !device.ID.IsEmpty() {
		sess.jid = device.ID.String()
	}
	client.AddEventHandler(sess.dispatch)
	sess.client = client
	return sess, nil
}

// Status returns the current lifecycle state.
func (s *instanceSession) Status() session.Status {
	s.mu.RLock()
	defer s.mu.RUnlock()
	return s.status
}

// JID returns the public JID, empty while the instance is not paired.
func (s *instanceSession) JID() string {
	s.mu.RLock()
	defer s.mu.RUnlock()
	return s.jid
}

// setStatus updates the lifecycle state and reports it to the sink only when
// it actually changed, so a pairing success followed by a reconnect does not
// emit duplicate connection events.
func (s *instanceSession) setStatus(status session.Status, jid, reason string) {
	s.mu.Lock()
	if s.status == status && s.lastReason == reason {
		s.mu.Unlock()
		return
	}
	s.status = status
	s.lastReason = reason
	if jid != "" {
		s.jid = jid
	}
	currentJID := s.jid
	s.mu.Unlock()

	if s.sink != nil {
		s.sink.OnConnection(context.Background(), s.instanceID, status, currentJID, reason)
	}
}

// Send delivers an outbound message through the whatsmeow client.
func (s *instanceSession) Send(ctx context.Context, msg session.OutboundMessage) (string, error) {
	if !s.client.IsConnected() {
		return "", fmt.Errorf("%w: send %s", session.ErrNotConnected, msg.Type)
	}
	recipient, err := types.ParseJID(msg.RecipientJID)
	if err != nil {
		return "", fmt.Errorf("%w: %s", session.ErrInvalidRecipient, msg.RecipientJID)
	}

	var waMsg *waE2E.Message
	if isMediaType(msg.Type) {
		waMsg, err = s.uploadMedia(ctx, msg)
	} else {
		waMsg, err = buildMessage(msg)
	}
	if err != nil {
		return "", err
	}

	resp, err := s.client.SendMessage(ctx, recipient, waMsg)
	if err != nil {
		return "", classifySessionError(err)
	}
	return string(resp.ID), nil
}

// IsOnWhatsApp resolves a phone number to its canonical JID.
func (s *instanceSession) IsOnWhatsApp(ctx context.Context, phone string) (string, bool, error) {
	if !s.client.IsConnected() {
		return "", false, fmt.Errorf("%w: is on whatsapp", session.ErrNotConnected)
	}
	responses, err := s.client.IsOnWhatsApp(ctx, []string{phone})
	if err != nil {
		return "", false, classifySessionError(err)
	}
	if len(responses) == 0 || !responses[0].IsIn || responses[0].JID.IsEmpty() {
		return "", false, nil
	}
	return responses[0].JID.String(), true, nil
}

// presenceRequest is a parsed SendPresence state.
type presenceRequest struct {
	isChat bool
	chat   types.ChatPresence
	user   types.Presence
}

// parsePresence classifies the presence states accepted by SendPresence.
func parsePresence(state string) (presenceRequest, error) {
	switch types.ChatPresence(state) {
	case types.ChatPresenceComposing, types.ChatPresencePaused:
		return presenceRequest{isChat: true, chat: types.ChatPresence(state)}, nil
	}
	switch types.Presence(state) {
	case types.PresenceAvailable, types.PresenceUnavailable:
		return presenceRequest{user: types.Presence(state)}, nil
	}
	return presenceRequest{}, fmt.Errorf("unsupported presence state %q", state)
}

// SendPresence reports chat typing or user availability.
func (s *instanceSession) SendPresence(ctx context.Context, chatJID, state string) error {
	target, err := parsePresence(state)
	if err != nil {
		return err
	}
	jid, err := types.ParseJID(chatJID)
	if err != nil {
		return fmt.Errorf("%w: %s", session.ErrInvalidRecipient, chatJID)
	}
	if target.isChat {
		return s.client.SendChatPresence(ctx, jid, target.chat, types.ChatPresenceMediaText)
	}
	return s.client.SendPresence(ctx, target.user)
}

// Disconnect closes the connection without deleting the credentials.
func (s *instanceSession) Disconnect(context.Context) error {
	s.cancelQR()
	s.client.Disconnect()
	s.setStatus(session.StatusDisconnected, "", "disconnected by request")
	return nil
}

// remove disconnects the client and deletes its stored credentials.
func (s *instanceSession) remove(ctx context.Context) error {
	s.cancelQR()
	s.client.Disconnect()
	if s.client.Store.ID != nil {
		if err := s.client.Store.Delete(ctx); err != nil {
			return fmt.Errorf("delete device: %w", err)
		}
	}
	s.setStatus(session.StatusDisconnected, "", "session removed")
	return nil
}

// connectExisting brings an already paired device online.
func (s *instanceSession) connectExisting(ctx context.Context) error {
	if err := s.client.ConnectContext(ctx); err != nil {
		return classifySessionError(err)
	}
	return nil
}

// cancelQR stops the pairing channel of the session, when one is open.
func (s *instanceSession) cancelQR() {
	s.mu.Lock()
	cancel := s.qrCancel
	s.qrCancel = nil
	s.mu.Unlock()
	if cancel != nil {
		cancel()
	}
}

// classifySessionError maps a whatsmeow error onto the errors callers act on.
func classifySessionError(err error) error {
	if err == nil {
		return nil
	}
	if errors.Is(err, whatsmeow.ErrNotConnected) || errors.Is(err, whatsmeow.ErrNotLoggedIn) {
		return fmt.Errorf("%w: %v", session.ErrNotConnected, err)
	}
	return fmt.Errorf("%w: %v", session.ErrTransient, err)
}

// outboundPayload is the JSON body shared by every message type.
type outboundPayload struct {
	Text        string   `json:"text"`
	Latitude    *float64 `json:"latitude"`
	Longitude   *float64 `json:"longitude"`
	Name        string   `json:"name"`
	Address     string   `json:"address"`
	DisplayName string   `json:"display_name"`
	VCard       string   `json:"vcard"`
	Caption     string   `json:"caption"`
	Filename    string   `json:"filename"`
	MimeType    string   `json:"mime_type"`
	PTT         bool     `json:"ptt"`
}

// buildMessage builds a non-media message from its normalized payload.
func buildMessage(msg session.OutboundMessage) (*waE2E.Message, error) {
	payload, err := decodePayload(msg.Payload)
	if err != nil {
		return nil, err
	}
	switch msg.Type {
	case "text":
		if payload.Text == "" {
			return nil, errors.New("text payload is empty")
		}
		return &waE2E.Message{Conversation: proto.String(payload.Text)}, nil
	case "location":
		if payload.Latitude == nil || payload.Longitude == nil {
			return nil, errors.New("location payload is missing coordinates")
		}
		return &waE2E.Message{LocationMessage: &waE2E.LocationMessage{
			DegreesLatitude:  payload.Latitude,
			DegreesLongitude: payload.Longitude,
			Name:             optionalString(payload.Name),
			Address:          optionalString(payload.Address),
		}}, nil
	case "contact":
		if payload.VCard == "" {
			return nil, errors.New("contact payload is missing the vcard")
		}
		return &waE2E.Message{ContactMessage: &waE2E.ContactMessage{
			DisplayName: optionalString(payload.DisplayName),
			Vcard:       proto.String(payload.VCard),
		}}, nil
	}
	return nil, fmt.Errorf("unsupported message type %q", msg.Type)
}

// newMediaMessage builds a media message from an uploaded attachment.
func newMediaMessage(msg session.OutboundMessage, upload whatsmeow.UploadResponse) (*waE2E.Message, error) {
	if !isMediaType(msg.Type) {
		return nil, fmt.Errorf("unsupported media type %q", msg.Type)
	}
	payload, err := decodePayload(msg.Payload)
	if err != nil {
		return nil, err
	}
	if payload.MimeType == "" {
		return nil, errors.New("media payload is missing the mime type")
	}
	switch msg.Type {
	case "image":
		return &waE2E.Message{ImageMessage: &waE2E.ImageMessage{
			URL:           proto.String(upload.URL),
			DirectPath:    proto.String(upload.DirectPath),
			MediaKey:      upload.MediaKey,
			FileSHA256:    upload.FileSHA256,
			FileEncSHA256: upload.FileEncSHA256,
			FileLength:    proto.Uint64(upload.FileLength),
			Mimetype:      proto.String(payload.MimeType),
			Caption:       optionalString(payload.Caption),
		}}, nil
	case "video":
		return &waE2E.Message{VideoMessage: &waE2E.VideoMessage{
			URL:           proto.String(upload.URL),
			DirectPath:    proto.String(upload.DirectPath),
			MediaKey:      upload.MediaKey,
			FileSHA256:    upload.FileSHA256,
			FileEncSHA256: upload.FileEncSHA256,
			FileLength:    proto.Uint64(upload.FileLength),
			Mimetype:      proto.String(payload.MimeType),
			Caption:       optionalString(payload.Caption),
		}}, nil
	case "audio":
		return &waE2E.Message{AudioMessage: &waE2E.AudioMessage{
			URL:           proto.String(upload.URL),
			DirectPath:    proto.String(upload.DirectPath),
			MediaKey:      upload.MediaKey,
			FileSHA256:    upload.FileSHA256,
			FileEncSHA256: upload.FileEncSHA256,
			FileLength:    proto.Uint64(upload.FileLength),
			Mimetype:      proto.String(payload.MimeType),
			PTT:           proto.Bool(payload.PTT),
		}}, nil
	case "document":
		return &waE2E.Message{DocumentMessage: &waE2E.DocumentMessage{
			URL:           proto.String(upload.URL),
			DirectPath:    proto.String(upload.DirectPath),
			MediaKey:      upload.MediaKey,
			FileSHA256:    upload.FileSHA256,
			FileEncSHA256: upload.FileEncSHA256,
			FileLength:    proto.Uint64(upload.FileLength),
			Mimetype:      proto.String(payload.MimeType),
			Caption:       optionalString(payload.Caption),
			FileName:      optionalString(payload.Filename),
			Title:         optionalString(payload.Filename),
		}}, nil
	}
	return nil, fmt.Errorf("unsupported media type %q", msg.Type)
}

// uploadMedia reads the file of a media message and uploads it to WhatsApp.
func (s *instanceSession) uploadMedia(ctx context.Context, msg session.OutboundMessage) (*waE2E.Message, error) {
	data, err := os.ReadFile(msg.MediaPath)
	if err != nil {
		return nil, fmt.Errorf("read media %s: %w", msg.MediaPath, err)
	}
	upload, err := s.client.Upload(ctx, data, mediaTypeFor(msg.Type))
	if err != nil {
		return nil, classifySessionError(err)
	}
	return newMediaMessage(msg, upload)
}

// decodePayload parses the JSON body of an outbound message. An empty payload
// decodes to its zero value.
func decodePayload(raw []byte) (outboundPayload, error) {
	var payload outboundPayload
	if len(raw) == 0 {
		return payload, nil
	}
	if err := json.Unmarshal(raw, &payload); err != nil {
		return outboundPayload{}, fmt.Errorf("decode payload: %w", err)
	}
	return payload, nil
}

// isMediaType reports whether type is delivered as an uploaded attachment.
func isMediaType(messageType string) bool {
	switch messageType {
	case "image", "video", "audio", "document":
		return true
	}
	return false
}

// mediaTypeFor maps a message type to the whatsmeow media key namespace.
func mediaTypeFor(messageType string) whatsmeow.MediaType {
	switch messageType {
	case "image":
		return whatsmeow.MediaImage
	case "video":
		return whatsmeow.MediaVideo
	case "audio":
		return whatsmeow.MediaAudio
	case "document":
		return whatsmeow.MediaDocument
	}
	return ""
}

// optionalString returns a proto string pointer, or nil for an empty value.
func optionalString(value string) *string {
	if value == "" {
		return nil
	}
	return proto.String(value)
}
