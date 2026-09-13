package httpapi

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"mime"
	"mime/multipart"
	"net/http"
	"slices"
	"strings"
	"time"

	"github.com/google/uuid"

	"onefisc/wzap/internal/model"
	"onefisc/wzap/internal/storage"
)

const (
	// idempotencyKeyHeader carries the caller supplied key. It is optional:
	// without it a send behaves exactly as before.
	idempotencyKeyHeader = "Idempotency-Key"
	// idempotentReplayHeader marks a response replayed from the idempotency
	// store instead of freshly produced.
	idempotentReplayHeader = "X-Idempotent-Replay"
	// idempotencyTTL is how long a stored response stays replayable.
	idempotencyTTL = 24 * time.Hour
	// idempotencyKeyMaxLen caps the key size accepted from clients.
	idempotencyKeyMaxLen = 255
	// fingerprintBodyLimit bounds the body the middleware buffers to compute the
	// request fingerprint, so a large upload cannot exhaust memory. A bigger
	// body falls back to a method+route fingerprint.
	fingerprintBodyLimit = 1 << 20
)

// Idempotency makes a retried send safe: the first request stores its response
// under the caller's key and a repeat replays it instead of enqueueing the same
// message twice. It scopes every key to the instance of the route and is meant
// to wrap only the send POSTs.
//
// Without the header the request goes straight through. While the original
// request runs the key is in flight and a repeat gets 409; a key reused with
// different content gets 422. A 4xx releases the key so the caller can fix the
// payload and retry; a 5xx is stored and replayed because the send may have
// reached WhatsApp before failing, so re-running it could duplicate the
// message. A 503 is the exception: the send path answers it before reaching
// WhatsApp, so the key is released and the retry the body asks for is possible.
// A panic or a handler that writes nothing also releases it.
func Idempotency(repo storage.IdempotencyRepository, log *slog.Logger) func(http.Handler) http.Handler {
	return func(next http.Handler) http.Handler {
		return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			if repo == nil || r.Method != http.MethodPost {
				next.ServeHTTP(w, r)
				return
			}

			key := strings.TrimSpace(r.Header.Get(idempotencyKeyHeader))
			if key == "" {
				next.ServeHTTP(w, r)
				return
			}
			if len(key) > idempotencyKeyMaxLen {
				Error(w, r, http.StatusBadRequest, "invalid_request", "Idempotency-Key exceeds 255 characters")
				return
			}

			// A route without a valid instance cannot be scoped, so the handler
			// answers as usual (typically 404) without touching the key.
			targetID, err := uuid.Parse(r.PathValue("id"))
			if err != nil {
				next.ServeHTTP(w, r)
				return
			}

			fingerprint, err := fingerprintRequest(r)
			if err != nil {
				Error(w, r, http.StatusBadRequest, "invalid_request", "invalid request body")
				return
			}

			record, acquired, err := repo.Acquire(r.Context(), targetID, key, fingerprint, time.Now().Add(idempotencyTTL))
			switch {
			case errors.Is(err, storage.ErrInProgress):
				Error(w, r, http.StatusConflict, "conflict", "request with this Idempotency-Key is already in progress")
				return
			case errors.Is(err, storage.ErrFingerprintMismatch):
				Error(w, r, http.StatusUnprocessableEntity, "unprocessable_entity", "Idempotency-Key was already used with different content")
				return
			case err != nil:
				// The store must not be the reason a send is dropped: log and
				// let the request through unprotected.
				log.WarnContext(r.Context(), "idempotency store unavailable, proceeding without it",
					"request_id", RequestIDFromContext(r.Context()),
					"error", err,
				)
				next.ServeHTTP(w, r)
				return
			}

			if !acquired {
				replay(w, record)
				return
			}

			capture := &responseCapture{ResponseWriter: w}
			defer func() {
				// The client may have disconnected by now; the outcome still has
				// to be recorded, so the cleanup ignores the request cancellation.
				ctx := context.WithoutCancel(r.Context())
				if !capture.wrote {
					releaseKey(ctx, repo, log, targetID, key)
					return
				}

				// A 4xx means the message never left, so the key is freed and
				// the caller can fix the payload and retry. A 503 comes from
				// the not-ready path before WhatsApp, so it is freed too: the
				// body tells the caller to retry, and replaying the 503 until
				// the key expired would make that instruction impossible.
				status := capture.status
				if (status >= http.StatusBadRequest && status < http.StatusInternalServerError) ||
					status == http.StatusServiceUnavailable {
					releaseKey(ctx, repo, log, targetID, key)
					return
				}
				// Any other result, including a 5xx, is stored and replayed: the
				// send may have reached WhatsApp before failing, so re-running
				// it could duplicate the message.
				if err := repo.Complete(ctx, targetID, key, status, capture.body.Bytes()); err != nil {
					log.WarnContext(ctx, "store idempotent response",
						"request_id", RequestIDFromContext(ctx),
						"error", err,
					)
				}
			}()

			next.ServeHTTP(capture, r)
		})
	}
}

// replay answers with a response stored under an idempotency key.
func replay(w http.ResponseWriter, record *model.IdempotencyRecord) {
	status := record.ResponseStatus
	if status == 0 {
		status = http.StatusOK
	}
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.Header().Set(idempotentReplayHeader, "true")
	w.WriteHeader(status)
	_, _ = w.Write(record.ResponseBody)
}

// releaseKey frees an idempotency key, logging a failure to free it.
func releaseKey(ctx context.Context, repo storage.IdempotencyRepository, log *slog.Logger, instanceID uuid.UUID, key string) {
	if err := repo.Release(ctx, instanceID, key); err != nil {
		log.WarnContext(ctx, "release idempotency key",
			"request_id", RequestIDFromContext(ctx),
			"error", err,
		)
	}
}

// responseCapture records the status and body written by the wrapped handler so
// the middleware can store them under the idempotency key.
type responseCapture struct {
	http.ResponseWriter
	status int
	body   bytes.Buffer
	wrote  bool
}

// WriteHeader records the first status written and forwards it.
func (c *responseCapture) WriteHeader(status int) {
	if c.wrote {
		return
	}
	c.wrote = true
	c.status = status
	c.ResponseWriter.WriteHeader(status)
}

// Write records the body bytes and forwards them, defaulting to 200.
func (c *responseCapture) Write(b []byte) (int, error) {
	if !c.wrote {
		c.wrote = true
		c.status = http.StatusOK
	}
	c.body.Write(b)
	return c.ResponseWriter.Write(b)
}

// Unwrap exposes the underlying writer to http.ResponseController.
func (c *responseCapture) Unwrap() http.ResponseWriter { return c.ResponseWriter }

// fingerprintRequest returns a stable hash of a send: method, route and body.
// Multipart bodies are hashed by their text fields only, because hashing the
// upload would require buffering it; a same-key retry with the same text fields
// therefore replays even when the file bytes differ (documented limitation).
// A body larger than fingerprintBodyLimit is hashed by method and route only,
// and is left intact for the handler.
func fingerprintRequest(r *http.Request) (string, error) {
	body, complete, err := readBodyPrefix(r)
	if err != nil {
		return "", err
	}
	if !complete {
		return routeFingerprint(r), nil
	}

	if fields, ok := multipartFields(r.Header.Get("Content-Type"), body); ok {
		return fieldsFingerprint(r, fields), nil
	}

	sum := sha256.New()
	writeRoute(sum, r)
	sum.Write(body)
	return hex.EncodeToString(sum.Sum(nil)), nil
}

// routeFingerprint hashes the method and route of r.
func routeFingerprint(r *http.Request) string {
	sum := sha256.New()
	writeRoute(sum, r)
	return hex.EncodeToString(sum.Sum(nil))
}

// fieldsFingerprint hashes the method, route and sorted multipart text fields.
func fieldsFingerprint(r *http.Request, fields []string) string {
	sum := sha256.New()
	writeRoute(sum, r)
	for _, field := range fields {
		sum.Write([]byte(field))
		sum.Write([]byte{'\n'})
	}
	return hex.EncodeToString(sum.Sum(nil))
}

// writeRoute writes the method and route pattern of r into h. The matched
// pattern is preferred over the raw path so the instance id does not take part
// in the fingerprint.
func writeRoute(h io.Writer, r *http.Request) {
	route := r.Pattern
	if route == "" {
		route = r.URL.Path
	}
	fmt.Fprintf(h, "%s\n%s\n", r.Method, route)
}

// readBodyPrefix reads at most fingerprintBodyLimit+1 bytes of the request body
// and restores the body for the handler. The second result reports whether the
// whole body fit in the limit.
func readBodyPrefix(r *http.Request) ([]byte, bool, error) {
	if r.Body == nil {
		return nil, true, nil
	}

	prefix, err := io.ReadAll(io.LimitReader(r.Body, fingerprintBodyLimit+1))
	if err != nil {
		return nil, false, err
	}
	if len(prefix) > fingerprintBodyLimit {
		r.Body = io.NopCloser(io.MultiReader(bytes.NewReader(prefix), r.Body))
		return prefix, false, nil
	}
	r.Body = io.NopCloser(bytes.NewReader(prefix))
	return prefix, true, nil
}

// multipartFields parses a multipart body and returns its text fields as sorted
// "name\x00value" pairs; file parts are skipped on purpose. The boolean is
// false when the content type is not multipart, the boundary is missing or the
// body is malformed.
func multipartFields(contentType string, body []byte) ([]string, bool) {
	mediaType, params, err := mime.ParseMediaType(contentType)
	if err != nil || !strings.HasPrefix(mediaType, "multipart/") {
		return nil, false
	}
	boundary := params["boundary"]
	if boundary == "" {
		return nil, false
	}

	reader := multipart.NewReader(bytes.NewReader(body), boundary)
	fields := []string{}
	for {
		part, err := reader.NextPart()
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			return nil, false
		}
		if part.FileName() != "" {
			// Draining the part advances the parser; the file content is
			// deliberately not fingerprinted.
			_, _ = io.Copy(io.Discard, part)
			continue
		}
		value, err := io.ReadAll(part)
		if err != nil {
			return nil, false
		}
		fields = append(fields, part.FormName()+"\x00"+string(value))
	}
	slices.Sort(fields)
	return fields, true
}
