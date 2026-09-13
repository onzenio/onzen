package httpapi

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"io"
	"mime/multipart"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/google/uuid"

	"onefisc/wzap/internal/model"
	"onefisc/wzap/internal/storage"
)

// fakeIdempotency is an in-memory IdempotencyRepository for the middleware
// tests. It records the calls and can force acquisition failures.
type fakeIdempotency struct {
	records    map[string]*model.IdempotencyRecord
	acquireErr error

	acquires  []acquireCall
	completes []completeCall
	releases  []releaseCall
}

// acquireCall records one Acquire invocation.
type acquireCall struct {
	instanceID  uuid.UUID
	key         string
	fingerprint string
	expiresAt   time.Time
}

// completeCall records one Complete invocation.
type completeCall struct {
	instanceID uuid.UUID
	key        string
	status     int
	body       string
}

// releaseCall records one Release invocation.
type releaseCall struct {
	instanceID uuid.UUID
	key        string
}

func newFakeIdempotency() *fakeIdempotency {
	return &fakeIdempotency{records: make(map[string]*model.IdempotencyRecord)}
}

func fakeRecordKey(instanceID uuid.UUID, key string) string {
	return instanceID.String() + "\x00" + key
}

// Acquire owns a key that does not exist yet, replays a completed one and
// reports in-progress and mismatched keys.
func (f *fakeIdempotency) Acquire(
	_ context.Context, instanceID uuid.UUID, key, fingerprint string, expiresAt time.Time,
) (*model.IdempotencyRecord, bool, error) {
	f.acquires = append(f.acquires, acquireCall{
		instanceID: instanceID, key: key, fingerprint: fingerprint, expiresAt: expiresAt,
	})
	if f.acquireErr != nil {
		return nil, false, f.acquireErr
	}

	record, ok := f.records[fakeRecordKey(instanceID, key)]
	if !ok {
		record = &model.IdempotencyRecord{
			InstanceID:  instanceID,
			Key:         key,
			Fingerprint: fingerprint,
			Status:      "in_progress",
			ExpiresAt:   expiresAt,
		}
		f.records[fakeRecordKey(instanceID, key)] = record
		return record, true, nil
	}
	if record.Fingerprint != fingerprint {
		return nil, false, fmt.Errorf("acquire idempotency key: %w", storage.ErrFingerprintMismatch)
	}
	if record.Status != "completed" {
		return nil, false, fmt.Errorf("acquire idempotency key: %w", storage.ErrInProgress)
	}
	return record, false, nil
}

// Complete stores the response under key.
func (f *fakeIdempotency) Complete(_ context.Context, instanceID uuid.UUID, key string, status int, body []byte) error {
	f.completes = append(f.completes, completeCall{instanceID: instanceID, key: key, status: status, body: string(body)})
	record, ok := f.records[fakeRecordKey(instanceID, key)]
	if !ok {
		return fmt.Errorf("complete idempotency key: %w", storage.ErrNotFound)
	}
	record.Status = "completed"
	record.ResponseStatus = status
	record.ResponseBody = append([]byte(nil), body...)
	return nil
}

// Release drops key.
func (f *fakeIdempotency) Release(_ context.Context, instanceID uuid.UUID, key string) error {
	f.releases = append(f.releases, releaseCall{instanceID: instanceID, key: key})
	delete(f.records, fakeRecordKey(instanceID, key))
	return nil
}

// DeleteExpired is unused by the middleware.
func (f *fakeIdempotency) DeleteExpired(context.Context) (int64, error) { return 0, nil }

// putRecord stores a record directly so a test can start from a known state.
func (f *fakeIdempotency) putRecord(record model.IdempotencyRecord) {
	f.records[fakeRecordKey(record.InstanceID, record.Key)] = &record
}

// idempotencyRequest builds a POST with an idempotency key for instance id. An
// empty key omits the header.
func idempotencyRequest(id uuid.UUID, key, body string) *http.Request {
	req := httptest.NewRequest(http.MethodPost, "/api/v1/instances/"+id.String()+"/messages/text", strings.NewReader(body))
	req.SetPathValue("id", id.String())
	req.Header.Set("Content-Type", "application/json")
	if key != "" {
		req.Header.Set(idempotencyKeyHeader, key)
	}
	return req
}

// serveIdempotency runs req through the middleware wrapping next.
func serveIdempotency(repo storage.IdempotencyRepository, next http.Handler, req *http.Request) *httptest.ResponseRecorder {
	rec := httptest.NewRecorder()
	Idempotency(repo, discardLogger())(next).ServeHTTP(rec, req)
	return rec
}

// countingHandler counts the calls and answers with status and body.
func countingHandler(calls *int, status int, body string) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		*calls++
		JSON(w, status, map[string]string{"message_id": body})
	})
}

func TestIdempotencyWithoutKeyPassesThrough(t *testing.T) {
	repo := newFakeIdempotency()
	calls := 0
	rec := serveIdempotency(repo, countingHandler(&calls, http.StatusAccepted, "m1"),
		idempotencyRequest(uuid.New(), "", `{"to":"5547"}`))

	if calls != 1 {
		t.Fatalf("handler calls = %d, want 1", calls)
	}
	if rec.Code != http.StatusAccepted {
		t.Errorf("status = %d, want %d", rec.Code, http.StatusAccepted)
	}
	if len(repo.acquires) != 0 {
		t.Errorf("Acquire calls = %d, want none without a key", len(repo.acquires))
	}
}

func TestIdempotencyFirstRequestAcquiresAndCompletes(t *testing.T) {
	repo := newFakeIdempotency()
	id := uuid.New()
	calls := 0
	before := time.Now()

	rec := serveIdempotency(repo, countingHandler(&calls, http.StatusAccepted, "m1"),
		idempotencyRequest(id, "key-1", `{"to":"5547"}`))

	if calls != 1 {
		t.Fatalf("handler calls = %d, want 1", calls)
	}
	if rec.Code != http.StatusAccepted {
		t.Fatalf("status = %d, want %d", rec.Code, http.StatusAccepted)
	}
	if rec.Header().Get(idempotentReplayHeader) != "" {
		t.Errorf("%s = %q, want empty on the first request", idempotentReplayHeader, rec.Header().Get(idempotentReplayHeader))
	}
	if len(repo.acquires) != 1 {
		t.Fatalf("Acquire calls = %d, want 1", len(repo.acquires))
	}
	acquire := repo.acquires[0]
	if acquire.instanceID != id {
		t.Errorf("Acquire instance = %s, want %s", acquire.instanceID, id)
	}
	if acquire.key != "key-1" {
		t.Errorf("Acquire key = %q, want %q", acquire.key, "key-1")
	}
	if acquire.fingerprint == "" {
		t.Error("Acquire fingerprint is empty")
	}
	expiresIn := acquire.expiresAt.Sub(before)
	if expiresIn < 23*time.Hour+59*time.Minute || expiresIn > 24*time.Hour+time.Minute {
		t.Errorf("Acquire expiry is %v away, want ~24h", expiresIn)
	}

	if len(repo.completes) != 1 {
		t.Fatalf("Complete calls = %d, want 1", len(repo.completes))
	}
	complete := repo.completes[0]
	if complete.instanceID != id || complete.key != "key-1" {
		t.Errorf("Complete(%s, %q), want (%s, %q)", complete.instanceID, complete.key, id, "key-1")
	}
	if complete.status != http.StatusAccepted {
		t.Errorf("Complete status = %d, want %d", complete.status, http.StatusAccepted)
	}
	if complete.body != rec.Body.String() {
		t.Errorf("Complete body = %q, want the response %q", complete.body, rec.Body.String())
	}
	if len(repo.releases) != 0 {
		t.Errorf("Release calls = %d, want none", len(repo.releases))
	}
}

func TestIdempotencyReplayReturnsStoredResponse(t *testing.T) {
	repo := newFakeIdempotency()
	id := uuid.New()
	handler := countingHandler(new(int), http.StatusAccepted, "m1")

	first := serveIdempotency(repo, handler, idempotencyRequest(id, "key-1", `{"to":"5547"}`))
	originalBody := first.Body.String()

	calls := 0
	second := serveIdempotency(repo, countingHandler(&calls, http.StatusAccepted, "m2"),
		idempotencyRequest(id, "key-1", `{"to":"5547"}`))

	if calls != 0 {
		t.Fatalf("handler calls on replay = %d, want 0", calls)
	}
	if second.Code != first.Code {
		t.Errorf("replay status = %d, want %d", second.Code, first.Code)
	}
	if second.Body.String() != originalBody {
		t.Errorf("replay body = %q, want the original %q", second.Body.String(), originalBody)
	}
	if got := second.Header().Get(idempotentReplayHeader); got != "true" {
		t.Errorf("%s = %q, want %q", idempotentReplayHeader, got, "true")
	}
	if ct := second.Header().Get("Content-Type"); !strings.HasPrefix(ct, "application/json") {
		t.Errorf("replay Content-Type = %q, want application/json", ct)
	}
}

func TestIdempotencyInFlightConflict(t *testing.T) {
	repo := newFakeIdempotency()
	id := uuid.New()
	request := idempotencyRequest(id, "key-1", `{"to":"5547"}`)
	fingerprint, err := fingerprintRequest(request)
	if err != nil {
		t.Fatalf("fingerprintRequest: %v", err)
	}
	repo.putRecord(model.IdempotencyRecord{
		InstanceID: id, Key: "key-1", Fingerprint: fingerprint, Status: "in_progress",
	})

	calls := 0
	rec := serveIdempotency(repo, countingHandler(&calls, http.StatusAccepted, "m1"), request)

	if rec.Code != http.StatusConflict {
		t.Fatalf("status = %d, want %d", rec.Code, http.StatusConflict)
	}
	if code := errorCode(t, rec.Body.Bytes()); code != "conflict" {
		t.Errorf("error code = %q, want %q", code, "conflict")
	}
	if calls != 0 {
		t.Errorf("handler calls = %d, want 0", calls)
	}
}

func TestIdempotencyFingerprintMismatch(t *testing.T) {
	repo := newFakeIdempotency()
	id := uuid.New()
	handler := countingHandler(new(int), http.StatusAccepted, "m1")
	serveIdempotency(repo, handler, idempotencyRequest(id, "key-1", `{"to":"5547","text":"first"}`))

	calls := 0
	rec := serveIdempotency(repo, countingHandler(&calls, http.StatusAccepted, "m2"),
		idempotencyRequest(id, "key-1", `{"to":"5547","text":"second"}`))

	if rec.Code != http.StatusUnprocessableEntity {
		t.Fatalf("status = %d, want %d", rec.Code, http.StatusUnprocessableEntity)
	}
	if code := errorCode(t, rec.Body.Bytes()); code != "unprocessable_entity" {
		t.Errorf("error code = %q, want %q", code, "unprocessable_entity")
	}
	if calls != 0 {
		t.Errorf("handler calls = %d, want 0", calls)
	}
}

func TestIdempotencyReleases4xxAndAllowsRetry(t *testing.T) {
	repo := newFakeIdempotency()
	id := uuid.New()
	calls := 0
	failing := http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		calls++
		Error(w, r, http.StatusUnprocessableEntity, "unprocessable_entity", "bad content")
	})
	rec := serveIdempotency(repo, failing, idempotencyRequest(id, "key-1", `{"to":"5547","text":"bad"}`))

	if rec.Code != http.StatusUnprocessableEntity {
		t.Fatalf("status = %d, want %d", rec.Code, http.StatusUnprocessableEntity)
	}
	if len(repo.releases) != 1 {
		t.Fatalf("Release calls = %d, want 1", len(repo.releases))
	}
	if len(repo.completes) != 0 {
		t.Errorf("Complete calls = %d, want none on 4xx", len(repo.completes))
	}

	retry := serveIdempotency(repo, countingHandler(&calls, http.StatusAccepted, "m1"),
		idempotencyRequest(id, "key-1", `{"to":"5547","text":"fixed"}`))
	if retry.Code != http.StatusAccepted {
		t.Errorf("retry status = %d, want %d", retry.Code, http.StatusAccepted)
	}
	if calls != 2 {
		t.Errorf("handler calls = %d, want 2 (failed + retry)", calls)
	}
}

func TestIdempotencyStoresAndReplays5xx(t *testing.T) {
	repo := newFakeIdempotency()
	id := uuid.New()
	failing := http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		Error(w, r, http.StatusInternalServerError, "internal_error", "boom")
	})
	rec := serveIdempotency(repo, failing, idempotencyRequest(id, "key-1", `{"to":"5547"}`))

	if rec.Code != http.StatusInternalServerError {
		t.Fatalf("status = %d, want %d", rec.Code, http.StatusInternalServerError)
	}
	if len(repo.completes) != 1 {
		t.Fatalf("Complete calls = %d, want 1 on 5xx", len(repo.completes))
	}
	if repo.completes[0].status != http.StatusInternalServerError {
		t.Errorf("Complete status = %d, want %d", repo.completes[0].status, http.StatusInternalServerError)
	}
	if len(repo.releases) != 0 {
		t.Errorf("Release calls = %d, want none on 5xx", len(repo.releases))
	}

	calls := 0
	replay := serveIdempotency(repo, countingHandler(&calls, http.StatusAccepted, "m1"),
		idempotencyRequest(id, "key-1", `{"to":"5547"}`))
	if calls != 0 {
		t.Errorf("handler calls on replay = %d, want 0", calls)
	}
	if replay.Code != http.StatusInternalServerError {
		t.Errorf("replay status = %d, want the stored %d", replay.Code, http.StatusInternalServerError)
	}
	if got := replay.Header().Get(idempotentReplayHeader); got != "true" {
		t.Errorf("%s = %q, want %q", idempotentReplayHeader, got, "true")
	}
}

func TestIdempotencyReleases503(t *testing.T) {
	repo := newFakeIdempotency()
	id := uuid.New()
	notReady := true
	calls := 0
	handler := http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		calls++
		if notReady {
			Error(w, r, http.StatusServiceUnavailable, "unavailable", "number resolution unavailable")
			return
		}
		JSON(w, http.StatusAccepted, map[string]string{"message_id": "m1"})
	})

	if rec := serveIdempotency(repo, handler, idempotencyRequest(id, "key-1", `{"to":"5547"}`)); rec.Code != http.StatusServiceUnavailable {
		t.Fatalf("status = %d, want %d", rec.Code, http.StatusServiceUnavailable)
	}
	if len(repo.releases) != 1 {
		t.Fatalf("Release calls = %d, want 1 on 503", len(repo.releases))
	}

	notReady = false
	retry := serveIdempotency(repo, handler, idempotencyRequest(id, "key-1", `{"to":"5547"}`))
	if retry.Code != http.StatusAccepted {
		t.Errorf("retry status = %d, want %d after a released 503", retry.Code, http.StatusAccepted)
	}
	if calls != 2 {
		t.Errorf("handler calls = %d, want 2", calls)
	}
}

func TestIdempotencyReleasesUnwrittenResponse(t *testing.T) {
	repo := newFakeIdempotency()
	id := uuid.New()
	silent := http.HandlerFunc(func(http.ResponseWriter, *http.Request) {})

	serveIdempotency(repo, silent, idempotencyRequest(id, "key-1", `{"to":"5547"}`))

	if len(repo.releases) != 1 {
		t.Fatalf("Release calls = %d, want 1", len(repo.releases))
	}
	if len(repo.completes) != 0 {
		t.Errorf("Complete calls = %d, want none", len(repo.completes))
	}
}

func TestIdempotencyReleasesOnPanic(t *testing.T) {
	repo := newFakeIdempotency()
	id := uuid.New()

	panicking := http.HandlerFunc(func(http.ResponseWriter, *http.Request) {
		panic("handler exploded")
	})
	recovered := http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		defer func() {
			if value := recover(); value == nil {
				t.Error("panic did not propagate to the outer recover")
			}
			Error(w, r, http.StatusInternalServerError, "internal_error", "internal server error")
		}()
		Idempotency(repo, discardLogger())(panicking).ServeHTTP(w, r)
	})

	rec := httptest.NewRecorder()
	recovered.ServeHTTP(rec, idempotencyRequest(id, "key-1", `{"to":"5547"}`))

	if rec.Code != http.StatusInternalServerError {
		t.Errorf("status = %d, want %d", rec.Code, http.StatusInternalServerError)
	}
	if len(repo.releases) != 1 {
		t.Errorf("Release calls = %d, want 1 after a panic", len(repo.releases))
	}
}

func TestIdempotencyAcquireFailurePassesThrough(t *testing.T) {
	repo := newFakeIdempotency()
	repo.acquireErr = errors.New("database down")
	calls := 0

	rec := serveIdempotency(repo, countingHandler(&calls, http.StatusAccepted, "m1"),
		idempotencyRequest(uuid.New(), "key-1", `{"to":"5547"}`))

	if calls != 1 {
		t.Fatalf("handler calls = %d, want 1", calls)
	}
	if rec.Code != http.StatusAccepted {
		t.Errorf("status = %d, want %d", rec.Code, http.StatusAccepted)
	}
}

func TestIdempotencyRejectsOversizedKey(t *testing.T) {
	repo := newFakeIdempotency()
	calls := 0
	rec := serveIdempotency(repo, countingHandler(&calls, http.StatusAccepted, "m1"),
		idempotencyRequest(uuid.New(), strings.Repeat("k", idempotencyKeyMaxLen+1), `{"to":"5547"}`))

	if rec.Code != http.StatusBadRequest {
		t.Fatalf("status = %d, want %d", rec.Code, http.StatusBadRequest)
	}
	if code := errorCode(t, rec.Body.Bytes()); code != "invalid_request" {
		t.Errorf("error code = %q, want %q", code, "invalid_request")
	}
	if calls != 0 {
		t.Errorf("handler calls = %d, want 0", calls)
	}
}

func TestIdempotencySkipsUnparseableInstanceID(t *testing.T) {
	repo := newFakeIdempotency()
	req := httptest.NewRequest(http.MethodPost, "/api/v1/instances/not-a-uuid/messages/text", strings.NewReader(`{"to":"5547"}`))
	req.SetPathValue("id", "not-a-uuid")
	req.Header.Set(idempotencyKeyHeader, "key-1")
	calls := 0

	rec := serveIdempotency(repo, countingHandler(&calls, http.StatusNotFound, "m1"), req)

	if calls != 1 {
		t.Fatalf("handler calls = %d, want 1", calls)
	}
	if rec.Code != http.StatusNotFound {
		t.Errorf("status = %d, want the handler response %d", rec.Code, http.StatusNotFound)
	}
	if len(repo.acquires) != 0 {
		t.Errorf("Acquire calls = %d, want none for an unparseable instance id", len(repo.acquires))
	}
}

func TestIdempotencySkipsNonPost(t *testing.T) {
	repo := newFakeIdempotency()
	req := httptest.NewRequest(http.MethodGet, "/api/v1/instances/"+uuid.NewString()+"/messages", nil)
	req.SetPathValue("id", uuid.NewString())
	req.Header.Set(idempotencyKeyHeader, "key-1")
	calls := 0

	serveIdempotency(repo, countingHandler(&calls, http.StatusOK, "m1"), req)

	if calls != 1 {
		t.Fatalf("handler calls = %d, want 1", calls)
	}
	if len(repo.acquires) != 0 {
		t.Errorf("Acquire calls = %d, want none for a GET", len(repo.acquires))
	}
}

// errReader fails on the first read so the middleware cannot fingerprint the
// body.
type errReader struct{}

func (errReader) Read([]byte) (int, error) { return 0, errors.New("read failed") }
func (errReader) Close() error             { return nil }

func TestIdempotencyRejectsUnreadableBody(t *testing.T) {
	repo := newFakeIdempotency()
	req := idempotencyRequest(uuid.New(), "key-1", "")
	req.Body = errReader{}
	calls := 0

	rec := serveIdempotency(repo, countingHandler(&calls, http.StatusAccepted, "m1"), req)

	if rec.Code != http.StatusBadRequest {
		t.Fatalf("status = %d, want %d", rec.Code, http.StatusBadRequest)
	}
	if calls != 0 {
		t.Errorf("handler calls = %d, want 0", calls)
	}
	if len(repo.acquires) != 0 {
		t.Errorf("Acquire calls = %d, want none", len(repo.acquires))
	}
}

func TestFingerprintCoversMethodRouteAndBody(t *testing.T) {
	id := uuid.New()
	base := idempotencyRequest(id, "key-1", `{"to":"5547","text":"hi"}`)
	baseFingerprint, err := fingerprintRequest(base)
	if err != nil {
		t.Fatalf("fingerprintRequest: %v", err)
	}
	same, err := fingerprintRequest(idempotencyRequest(id, "key-1", `{"to":"5547","text":"hi"}`))
	if err != nil {
		t.Fatalf("fingerprintRequest: %v", err)
	}
	if baseFingerprint != same {
		t.Errorf("equal requests got fingerprints %q and %q", baseFingerprint, same)
	}

	otherBody, err := fingerprintRequest(idempotencyRequest(id, "key-1", `{"to":"5547","text":"bye"}`))
	if err != nil {
		t.Fatalf("fingerprintRequest: %v", err)
	}
	if baseFingerprint == otherBody {
		t.Error("different bodies share a fingerprint")
	}

	otherPath := httptest.NewRequest(http.MethodPost, "/api/v1/instances/"+id.String()+"/messages/location", strings.NewReader(`{"to":"5547","text":"hi"}`))
	otherPath.SetPathValue("id", id.String())
	otherPathFingerprint, err := fingerprintRequest(otherPath)
	if err != nil {
		t.Fatalf("fingerprintRequest: %v", err)
	}
	if baseFingerprint == otherPathFingerprint {
		t.Error("different routes share a fingerprint")
	}
}

// multipartRequest builds a multipart POST with the given fields and one file
// part.
func multipartRequest(t *testing.T, id uuid.UUID, fields [][2]string, filename, fileContent string) *http.Request {
	t.Helper()
	var body bytes.Buffer
	writer := multipart.NewWriter(&body)
	for _, field := range fields {
		if err := writer.WriteField(field[0], field[1]); err != nil {
			t.Fatalf("WriteField: %v", err)
		}
	}
	part, err := writer.CreateFormFile("file", filename)
	if err != nil {
		t.Fatalf("CreateFormFile: %v", err)
	}
	if _, err := io.WriteString(part, fileContent); err != nil {
		t.Fatalf("write file part: %v", err)
	}
	if err := writer.Close(); err != nil {
		t.Fatalf("close writer: %v", err)
	}

	req := httptest.NewRequest(http.MethodPost, "/api/v1/instances/"+id.String()+"/messages/media", &body)
	req.SetPathValue("id", id.String())
	req.Header.Set("Content-Type", writer.FormDataContentType())
	return req
}

func TestFingerprintMultipartUsesTextFieldsOnly(t *testing.T) {
	id := uuid.New()
	fields := [][2]string{{"to", "5547988359190"}, {"type", "image"}, {"caption", "olha"}}

	first, err := fingerprintRequest(multipartRequest(t, id, fields, "foto.jpg", "first bytes"))
	if err != nil {
		t.Fatalf("fingerprintRequest: %v", err)
	}
	sameFields, err := fingerprintRequest(multipartRequest(t, id, fields, "foto.jpg", "utterly different bytes"))
	if err != nil {
		t.Fatalf("fingerprintRequest: %v", err)
	}
	if first != sameFields {
		t.Error("multipart file content changed the fingerprint, want the documented limitation (file is not hashed)")
	}

	reordered, err := fingerprintRequest(multipartRequest(t, id, [][2]string{{"caption", "olha"}, {"to", "5547988359190"}, {"type", "image"}}, "foto.jpg", "first bytes"))
	if err != nil {
		t.Fatalf("fingerprintRequest: %v", err)
	}
	if first != reordered {
		t.Error("multipart field order changed the fingerprint, want field order to be ignored")
	}

	otherRecipient, err := fingerprintRequest(multipartRequest(t, id, [][2]string{{"to", "5547999999999"}, {"type", "image"}, {"caption", "olha"}}, "foto.jpg", "first bytes"))
	if err != nil {
		t.Fatalf("fingerprintRequest: %v", err)
	}
	if first == otherRecipient {
		t.Error("different text fields share a fingerprint")
	}
}

func TestFingerprintMultipartRestoresBodyForHandler(t *testing.T) {
	id := uuid.New()
	repo := newFakeIdempotency()
	var gotRecipient string
	var gotFile string
	handler := http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if err := r.ParseMultipartForm(1 << 20); err != nil {
			t.Errorf("ParseMultipartForm: %v", err)
		}
		gotRecipient = r.FormValue("to")
		file, _, err := r.FormFile("file")
		if err != nil {
			t.Errorf("FormFile: %v", err)
		} else {
			defer func() { _ = file.Close() }()
			content, _ := io.ReadAll(file)
			gotFile = string(content)
		}
		JSON(w, http.StatusAccepted, map[string]string{"message_id": "m1"})
	})

	rec := serveIdempotency(repo, handler, multipartRequest(t, id, [][2]string{{"to", "5547"}, {"caption", "olha"}}, "foto.jpg", "file bytes"))

	if rec.Code != http.StatusAccepted {
		t.Fatalf("status = %d, want %d", rec.Code, http.StatusAccepted)
	}
	if gotRecipient != "5547" {
		t.Errorf("handler FormValue(to) = %q, want %q", gotRecipient, "5547")
	}
	if gotFile != "file bytes" {
		t.Errorf("handler file content = %q, want %q", gotFile, "file bytes")
	}
}

func TestFingerprintLargeBodyFallsBackToRouteAndKeepsBody(t *testing.T) {
	id := uuid.New()
	large := strings.Repeat("x", fingerprintBodyLimit+1)
	otherLarge := strings.Repeat("y", fingerprintBodyLimit+1)

	fingerprint, err := fingerprintRequest(idempotencyRequest(id, "key-1", large))
	if err != nil {
		t.Fatalf("fingerprintRequest: %v", err)
	}
	otherFingerprint, err := fingerprintRequest(idempotencyRequest(id, "key-1", otherLarge))
	if err != nil {
		t.Fatalf("fingerprintRequest: %v", err)
	}
	if fingerprint != otherFingerprint {
		t.Error("large bodies were fingerprinted by content, want the documented route-only fallback")
	}

	req := idempotencyRequest(id, "key-1", large)
	if _, err := fingerprintRequest(req); err != nil {
		t.Fatalf("fingerprintRequest: %v", err)
	}
	restored, err := io.ReadAll(req.Body)
	if err != nil {
		t.Fatalf("read restored body: %v", err)
	}
	if string(restored) != large {
		t.Errorf("restored body length = %d, want %d", len(restored), len(large))
	}
}
