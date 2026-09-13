package httpapi

import (
	"bytes"
	"context"
	"errors"
	"io"
	"net/http"
	"strconv"
	"testing"

	"github.com/google/uuid"

	"onefisc/wzap/internal/config"
	"onefisc/wzap/internal/media"
	"onefisc/wzap/internal/model"
)

// fakeMediaStore is an in-memory MediaStore configured per test.
type fakeMediaStore struct {
	openFn func(ctx context.Context, id uuid.UUID) (io.ReadCloser, *model.Media, error)
	saveFn func(
		ctx context.Context, instanceID uuid.UUID, direction, messageID, mimetype, filename string, data []byte,
	) (*model.Media, error)

	saveCalls []mediaSaveCall
}

// mediaSaveCall records one Save invocation.
type mediaSaveCall struct {
	instanceID uuid.UUID
	direction  string
	messageID  string
	mimetype   string
	filename   string
	data       []byte
}

func (f *fakeMediaStore) Open(ctx context.Context, id uuid.UUID) (io.ReadCloser, *model.Media, error) {
	if f.openFn != nil {
		return f.openFn(ctx, id)
	}
	return nil, nil, media.ErrNotFound
}

// Save records the call and stores a fresh record, defaulting to deriving it
// from the arguments.
func (f *fakeMediaStore) Save(
	ctx context.Context, instanceID uuid.UUID, direction, messageID, mimetype, filename string, data []byte,
) (*model.Media, error) {
	f.saveCalls = append(f.saveCalls, mediaSaveCall{
		instanceID: instanceID,
		direction:  direction,
		messageID:  messageID,
		mimetype:   mimetype,
		filename:   filename,
		data:       append([]byte(nil), data...),
	})
	if f.saveFn != nil {
		return f.saveFn(ctx, instanceID, direction, messageID, mimetype, filename, data)
	}
	return &model.Media{
		ID:         uuid.New(),
		InstanceID: instanceID,
		Direction:  direction,
		Mimetype:   mimetype,
		Filename:   filename,
		SizeBytes:  int64(len(data)),
	}, nil
}

// mediaServer builds the server under test with the given media store.
func mediaServer(t *testing.T, store MediaStore) *http.Server {
	t.Helper()
	if store == nil {
		store = &fakeMediaStore{}
	}
	return New(config.Config{HTTPAddr: "127.0.0.1:0", ServiceToken: testToken}, discardLogger(),
		Deps{
			ReadyChecker: checkFunc(func(context.Context) error { return nil }),
			Media:        store,
		})
}

func TestGetMediaReturnsContent(t *testing.T) {
	id := uuid.New()
	payload := []byte("bytes da mídia")
	store := &fakeMediaStore{openFn: func(_ context.Context, got uuid.UUID) (io.ReadCloser, *model.Media, error) {
		if got != id {
			t.Errorf("Open id = %s, want %s", got, id)
		}
		return io.NopCloser(bytes.NewReader(payload)), &model.Media{
			ID:        id,
			Mimetype:  "image/jpeg",
			SizeBytes: int64(len(payload)),
		}, nil
	}}

	rec := serve(t, mediaServer(t, store), http.MethodGet, "/api/v1/media/"+id.String(), testToken)

	if rec.Code != http.StatusOK {
		t.Fatalf("status = %d, want %d", rec.Code, http.StatusOK)
	}
	if ct := rec.Header().Get("Content-Type"); ct != "image/jpeg" {
		t.Errorf("Content-Type = %q, want image/jpeg", ct)
	}
	if cl := rec.Header().Get("Content-Length"); cl != strconv.Itoa(len(payload)) {
		t.Errorf("Content-Length = %q, want %d", cl, len(payload))
	}
	if !bytes.Equal(rec.Body.Bytes(), payload) {
		t.Errorf("body = %q, want %q", rec.Body.Bytes(), payload)
	}
}

func TestGetMediaRequiresAuth(t *testing.T) {
	rec := serve(t, mediaServer(t, nil), http.MethodGet, "/api/v1/media/"+uuid.NewString(), "")

	if rec.Code != http.StatusUnauthorized {
		t.Fatalf("status = %d, want %d", rec.Code, http.StatusUnauthorized)
	}
	if code := errorCode(t, rec.Body.Bytes()); code != "unauthorized" {
		t.Errorf("error code = %q, want unauthorized", code)
	}
}

func TestGetMediaNotFound(t *testing.T) {
	tests := []struct {
		name string
		err  error
	}{
		{name: "unknown", err: media.ErrNotFound},
		{name: "expired", err: media.ErrExpired},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			store := &fakeMediaStore{openFn: func(context.Context, uuid.UUID) (io.ReadCloser, *model.Media, error) {
				return nil, nil, tt.err
			}}

			rec := serve(t, mediaServer(t, store), http.MethodGet, "/api/v1/media/"+uuid.NewString(), testToken)

			if rec.Code != http.StatusNotFound {
				t.Fatalf("status = %d, want %d", rec.Code, http.StatusNotFound)
			}
			if code := errorCode(t, rec.Body.Bytes()); code != "not_found" {
				t.Errorf("error code = %q, want not_found", code)
			}
		})
	}
}

func TestGetMediaMalformedID(t *testing.T) {
	rec := serve(t, mediaServer(t, nil), http.MethodGet, "/api/v1/media/not-a-uuid", testToken)

	if rec.Code != http.StatusNotFound {
		t.Fatalf("status = %d, want %d", rec.Code, http.StatusNotFound)
	}
}

func TestGetMediaInternalError(t *testing.T) {
	store := &fakeMediaStore{openFn: func(context.Context, uuid.UUID) (io.ReadCloser, *model.Media, error) {
		return nil, nil, errors.New("database down")
	}}

	rec := serve(t, mediaServer(t, store), http.MethodGet, "/api/v1/media/"+uuid.NewString(), testToken)

	if rec.Code != http.StatusInternalServerError {
		t.Fatalf("status = %d, want %d", rec.Code, http.StatusInternalServerError)
	}
	if code := errorCode(t, rec.Body.Bytes()); code != "internal_error" {
		t.Errorf("error code = %q, want internal_error", code)
	}
}
