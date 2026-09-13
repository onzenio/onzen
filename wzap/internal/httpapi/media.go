package httpapi

import (
	"context"
	"errors"
	"io"
	"net/http"
	"strconv"

	"github.com/google/uuid"

	"onefisc/wzap/internal/media"
	"onefisc/wzap/internal/model"
)

// MediaStore is the media download contract consumed by the handlers.
type MediaStore interface {
	Open(ctx context.Context, id uuid.UUID) (io.ReadCloser, *model.Media, error)
}

// handleGetMedia streams one media content. The success body is the raw
// content, not the JSON envelope used by the other endpoints.
func handleGetMedia(store MediaStore) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		id, err := uuid.Parse(r.PathValue("id"))
		if err != nil {
			Error(w, r, http.StatusNotFound, "not_found", "media not found")
			return
		}

		body, record, err := store.Open(r.Context(), id)
		if err != nil {
			writeMediaError(w, r, err)
			return
		}
		defer func() { _ = body.Close() }()

		w.Header().Set("Content-Type", record.Mimetype)
		w.Header().Set("Content-Length", strconv.FormatInt(record.SizeBytes, 10))
		w.WriteHeader(http.StatusOK)
		_, _ = io.Copy(w, body)
	}
}

// writeMediaError maps a media storage error to its HTTP status and error
// envelope. Unknown and expired media are indistinguishable to the client.
func writeMediaError(w http.ResponseWriter, r *http.Request, err error) {
	switch {
	case errors.Is(err, media.ErrNotFound), errors.Is(err, media.ErrExpired):
		Error(w, r, http.StatusNotFound, "not_found", "media not found")
	default:
		Error(w, r, http.StatusInternalServerError, "internal_error", "internal server error")
	}
}
