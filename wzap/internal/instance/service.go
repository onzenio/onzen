// Package instance implements the instance management service: creation,
// lookup, paginated listing, partial updates and removal of WhatsApp
// instances.
package instance

import (
	"context"
	"errors"
	"fmt"

	"github.com/google/uuid"

	"onefisc/wzap/internal/model"
	"onefisc/wzap/internal/session"
	"onefisc/wzap/internal/storage"
)

// Errors reported by the service and mapped to HTTP status codes by the
// handler layer.
var (
	// ErrNotFound reports that the requested instance does not exist.
	ErrNotFound = errors.New("instance not found")
	// ErrExternalRefTaken reports that an instance external_ref is already in use.
	ErrExternalRefTaken = errors.New("external ref already taken")
	// ErrInvalidCursor reports that a list cursor is not a valid identifier.
	ErrInvalidCursor = errors.New("invalid cursor")
)

// MediaRemover deletes the media files and rows of an instance. It is declared
// here, at the consumer, and will be satisfied by the media package (Task 16).
type MediaRemover interface {
	DeleteByInstance(ctx context.Context, instanceID uuid.UUID) error
}

// CreateInput is the payload accepted by Create. Name and ExternalRef are the
// only fields the REST contract exposes.
type CreateInput struct {
	Name        string
	ExternalRef string
}

// UpdateInput is a partial update: nil fields keep their stored value, while an
// explicit empty ExternalRef clears the reference.
type UpdateInput struct {
	Name        *string
	ExternalRef *string
}

// Service manages the lifecycle of WhatsApp instances over the instance
// repository, the session manager and the media remover.
type Service struct {
	repo     storage.InstanceRepository
	sessions session.Manager
	media    MediaRemover
}

// NewService builds the service over its dependencies. media may be nil until
// instance media exists (Task 16); Delete then skips media removal.
func NewService(repo storage.InstanceRepository, sessions session.Manager, media MediaRemover) *Service {
	return &Service{repo: repo, sessions: sessions, media: media}
}

// Create registers a new instance in the disconnected state.
func (s *Service) Create(ctx context.Context, input CreateInput) (*model.Instance, error) {
	instance := model.Instance{
		ID:          uuid.New(),
		Name:        input.Name,
		ExternalRef: input.ExternalRef,
		Status:      string(session.StatusDisconnected),
	}

	created, err := s.repo.Create(ctx, instance)
	if err != nil {
		return nil, mapError("create instance", err)
	}
	return created, nil
}

// Get returns the instance with the given id or ErrNotFound.
func (s *Service) Get(ctx context.Context, id uuid.UUID) (*model.Instance, error) {
	instance, err := s.repo.Get(ctx, id)
	if err != nil {
		return nil, mapError("get instance", err)
	}
	return instance, nil
}

// List returns a page of instances and the cursor of the next page, empty on
// the last page.
func (s *Service) List(ctx context.Context, limit int, cursor string) ([]model.Instance, string, error) {
	instances, next, err := s.repo.List(ctx, limit, cursor)
	if err != nil {
		return nil, "", mapError("list instances", err)
	}
	return instances, next, nil
}

// Update applies the fields present in input to the stored instance and
// returns the stored row.
func (s *Service) Update(ctx context.Context, id uuid.UUID, input UpdateInput) (*model.Instance, error) {
	instance, err := s.repo.Get(ctx, id)
	if err != nil {
		return nil, mapError("update instance", err)
	}

	if input.Name != nil {
		instance.Name = *input.Name
	}
	if input.ExternalRef != nil {
		instance.ExternalRef = *input.ExternalRef
	}

	updated, err := s.repo.Update(ctx, *instance)
	if err != nil {
		return nil, mapError("update instance", err)
	}
	return updated, nil
}

// Delete tears the session down, deletes the instance media and finally removes
// the row. A failure aborts before the row is removed, keeping the instance
// available so the removal can be retried instead of leaking credentials or
// media.
func (s *Service) Delete(ctx context.Context, id uuid.UUID) error {
	if _, err := s.repo.Get(ctx, id); err != nil {
		return mapError("delete instance", err)
	}
	if err := s.sessions.Remove(ctx, id); err != nil {
		return fmt.Errorf("delete instance: remove session: %w", err)
	}
	if s.media != nil {
		if err := s.media.DeleteByInstance(ctx, id); err != nil {
			return fmt.Errorf("delete instance: delete media: %w", err)
		}
	}
	if err := s.repo.Delete(ctx, id); err != nil {
		return mapError("delete instance", err)
	}
	return nil
}

// mapError translates a storage error into the service sentinel the HTTP layer
// maps to a status code, preserving the operation context for the logs.
func mapError(op string, err error) error {
	switch {
	case errors.Is(err, storage.ErrNotFound):
		return fmt.Errorf("%s: %w", op, ErrNotFound)
	case errors.Is(err, storage.ErrExternalRefTaken):
		return fmt.Errorf("%s: %w", op, ErrExternalRefTaken)
	case errors.Is(err, storage.ErrInvalidCursor):
		return fmt.Errorf("%s: %w", op, ErrInvalidCursor)
	default:
		return fmt.Errorf("%s: %w", op, err)
	}
}
