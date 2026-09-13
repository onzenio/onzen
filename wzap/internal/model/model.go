// Package model defines the persistence-agnostic types shared by the wzap
// storage and services.
package model

import (
	"time"

	"github.com/google/uuid"
)

// Instance is a WhatsApp instance registered with the wzap service.
type Instance struct {
	ID              uuid.UUID
	Name            string
	ExternalRef     string
	Status          string
	WhatsAppJID     string
	LastError       string
	LastConnectedAt *time.Time
	CreatedAt       time.Time
	UpdatedAt       time.Time
}

// OutboundMessage is a message queued for delivery through an instance.
type OutboundMessage struct {
	ID                uuid.UUID
	InstanceID        uuid.UUID
	Type              string
	RecipientJID      string
	Payload           []byte
	MediaID           *uuid.UUID
	Status            string
	WhatsAppMessageID string
	LastError         string
	Attempts          int
	DeliveredAt       *time.Time
	ReadAt            *time.Time
	CreatedAt         time.Time
	UpdatedAt         time.Time
}
