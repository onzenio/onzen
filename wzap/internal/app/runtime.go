// Package app wires the wzap runtime: the session event sink that projects
// connection state into the database and the outbox.
package app

import (
	"context"
	"fmt"
	"log/slog"
	"sync"
	"time"

	"github.com/google/uuid"

	"onefisc/wzap/internal/events"
	"onefisc/wzap/internal/message"
	"onefisc/wzap/internal/session"
	"onefisc/wzap/internal/storage"
)

// connectionEventType is the event type of the instance connection events.
const connectionEventType = "connection"

// ReceiptApplier projects an inbound receipt into the stored messages and the
// event outbox. *message.Receipts implements it.
type ReceiptApplier interface {
	Apply(ctx context.Context, receipt session.Receipt) error
}

// The concrete projector satisfies the runtime contract; the assertion catches
// signature drift at build time.
var _ ReceiptApplier = (*message.Receipts)(nil)

// Runtime is the session.EventSink of the service. Connection changes are
// projected into the instances row and enqueued as events in the outbox;
// receipts are projected into the message rows; messages arrive in later tasks.
type Runtime struct {
	instances storage.InstanceRepository
	events    events.Writer
	receipts  ReceiptApplier
	log       *slog.Logger

	// mu serializes connection updates: the repository update is a
	// read-modify-write of the whole row, so concurrent session events could
	// otherwise overwrite each other.
	mu sync.Mutex
}

var _ session.EventSink = (*Runtime)(nil)

// NewRuntime builds the runtime over the instance repository, the outbox
// writer and the receipt projector. A nil logger falls back to the default
// one; a nil receipt projector makes OnReceipt a no-op.
func NewRuntime(instances storage.InstanceRepository, writer events.Writer, receipts ReceiptApplier, log *slog.Logger) *Runtime {
	if log == nil {
		log = slog.Default()
	}
	return &Runtime{instances: instances, events: writer, receipts: receipts, log: log}
}

// OnMessage handles an inbound message. Media download and the message event
// arrive in Task 17.
func (r *Runtime) OnMessage(context.Context, session.InboundMessage) {}

// OnReceipt applies a delivery/read receipt to the stored messages and
// enqueues its event. A failure is logged: the receipt is an observation and
// the next one may still apply.
func (r *Runtime) OnReceipt(ctx context.Context, receipt session.Receipt) {
	if r.receipts == nil {
		return
	}
	if err := r.receipts.Apply(ctx, receipt); err != nil {
		r.log.ErrorContext(ctx, "apply receipt", "instance_id", receipt.InstanceID, "error", err)
	}
}

// OnConnection projects a connection change into the instance row and enqueues
// the connection event. When the change cannot be recorded the event is
// skipped, so consumers are not told about a state the status endpoint does
// not report.
func (r *Runtime) OnConnection(ctx context.Context, instanceID uuid.UUID, status session.Status, jid, reason string) {
	r.mu.Lock()
	defer r.mu.Unlock()

	if err := applyConnection(ctx, r.instances, instanceID, status, jid, reason); err != nil {
		r.log.ErrorContext(ctx, "record connection change",
			"instance_id", instanceID, "status", status, "error", err)
		return
	}

	env, err := events.New(connectionEventType, instanceID, connectionPayload{
		Status:      string(status),
		WhatsAppJID: jid,
		Reason:      reason,
	})
	if err != nil {
		r.log.ErrorContext(ctx, "build connection event", "instance_id", instanceID, "error", err)
		return
	}
	if err := r.events.Write(ctx, events.Subjects.Connection(instanceID), env); err != nil {
		r.log.ErrorContext(ctx, "enqueue connection event", "instance_id", instanceID, "error", err)
	}
}

// applyConnection stores the connection state of instanceID. The JID is kept
// while it is unknown so a transient failure keeps the paired identity, and
// last_connected_at is stamped on every transition to connected.
func applyConnection(ctx context.Context, instances storage.InstanceRepository, instanceID uuid.UUID, status session.Status, jid, reason string) error {
	instance, err := instances.Get(ctx, instanceID)
	if err != nil {
		return fmt.Errorf("get instance: %w", err)
	}

	instance.Status = string(status)
	if jid != "" {
		instance.WhatsAppJID = jid
	}
	instance.LastError = reason
	if status == session.StatusConnected {
		now := time.Now().UTC()
		instance.LastConnectedAt = &now
	}

	if _, err := instances.Update(ctx, *instance); err != nil {
		return fmt.Errorf("update instance: %w", err)
	}
	return nil
}

// connectionPayload is the JSON body of a connection event.
type connectionPayload struct {
	Status      string `json:"status"`
	WhatsAppJID string `json:"whatsapp_jid,omitempty"`
	Reason      string `json:"reason,omitempty"`
}
