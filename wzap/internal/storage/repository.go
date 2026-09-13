// Package storage declares the persistence contracts consumed by the wzap
// services.
package storage

import (
	"context"
	"errors"
	"time"

	"github.com/google/uuid"

	"onefisc/wzap/internal/model"
)

var (
	// ErrNotFound reports that the requested record does not exist.
	ErrNotFound = errors.New("record not found")
	// ErrExternalRefTaken reports that an instance external_ref is already in use.
	ErrExternalRefTaken = errors.New("external ref already taken")
	// ErrInvalidCursor reports that a pagination cursor is not a valid identifier.
	ErrInvalidCursor = errors.New("invalid cursor")
)

// InstanceRepository persists WhatsApp instances. List pages backwards by
// created_at and returns the cursor of the next page, empty when the page is
// the last one.
type InstanceRepository interface {
	Create(ctx context.Context, instance model.Instance) (*model.Instance, error)
	Get(ctx context.Context, id uuid.UUID) (*model.Instance, error)
	GetByExternalRef(ctx context.Context, externalRef string) (*model.Instance, error)
	List(ctx context.Context, limit int, cursor string) ([]model.Instance, string, error)
	Update(ctx context.Context, instance model.Instance) (*model.Instance, error)
	Delete(ctx context.Context, id uuid.UUID) error
}

// MessageRepository persists outbound messages. ListByInstance pages backwards
// by created_at and returns the cursor of the next page, empty when the page is
// the last one.
type MessageRepository interface {
	Create(ctx context.Context, message model.OutboundMessage) (*model.OutboundMessage, error)
	Get(ctx context.Context, id uuid.UUID) (*model.OutboundMessage, error)
	ListByInstance(ctx context.Context, instanceID uuid.UUID, limit int, cursor string) ([]model.OutboundMessage, string, error)
	// ClaimQueued selects queued messages whose next_attempt_at is due, oldest
	// first, and atomically moves them to sending with FOR UPDATE SKIP LOCKED.
	ClaimQueued(ctx context.Context, limit int) ([]model.OutboundMessage, error)
	MarkSent(ctx context.Context, id uuid.UUID, whatsAppMessageID string) error
	MarkFailed(ctx context.Context, id uuid.UUID, errMsg string) error
	// MarkRetrying moves a message back to queued, incrementing attempts.
	MarkRetrying(ctx context.Context, id uuid.UUID, errMsg string, nextAttemptAt time.Time) error
	// UpdateReceipt maps delivered to delivered_at and read/played to read_at.
	// It reports false when no message matches or the status is unknown.
	UpdateReceipt(ctx context.Context, whatsAppMessageID, status string, at time.Time) (bool, error)
	// RequeueStuck moves sending messages updated before olderThan back to queued.
	RequeueStuck(ctx context.Context, olderThan time.Time) (int64, error)
}
