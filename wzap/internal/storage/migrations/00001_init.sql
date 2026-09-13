-- +goose Up
CREATE TABLE instances (
  id uuid PRIMARY KEY,
  name text NOT NULL,
  external_ref text UNIQUE,
  status text NOT NULL DEFAULT 'disconnected',
  whatsapp_jid text,
  last_connected_at timestamptz,
  last_error text,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE outbound_messages (
  id uuid PRIMARY KEY,
  instance_id uuid NOT NULL REFERENCES instances(id) ON DELETE CASCADE,
  type text NOT NULL,
  recipient_jid text NOT NULL,
  payload jsonb NOT NULL DEFAULT '{}',
  media_id uuid,
  status text NOT NULL DEFAULT 'queued',
  whatsapp_message_id text,
  attempts int NOT NULL DEFAULT 0,
  last_error text,
  next_attempt_at timestamptz,
  delivered_at timestamptz,
  read_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX outbound_messages_instance_status_idx ON outbound_messages (instance_id, status);
CREATE INDEX outbound_messages_created_idx ON outbound_messages (created_at);
CREATE INDEX outbound_messages_wa_id_idx ON outbound_messages (whatsapp_message_id);
CREATE TABLE idempotency_keys (
  instance_id uuid NOT NULL REFERENCES instances(id) ON DELETE CASCADE,
  key text NOT NULL,
  request_fingerprint text NOT NULL,
  status text NOT NULL,
  response_status int,
  response_body jsonb,
  created_at timestamptz NOT NULL DEFAULT now(),
  expires_at timestamptz NOT NULL,
  PRIMARY KEY (instance_id, key)
);
CREATE INDEX idempotency_keys_expires_idx ON idempotency_keys (expires_at);
CREATE TABLE jid_cache (
  phone text PRIMARY KEY,
  jid text NOT NULL,
  expires_at timestamptz NOT NULL,
  created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX jid_cache_expires_idx ON jid_cache (expires_at);
CREATE TABLE media (
  id uuid PRIMARY KEY,
  instance_id uuid NOT NULL REFERENCES instances(id) ON DELETE CASCADE,
  direction text NOT NULL,
  message_id text,
  mimetype text NOT NULL,
  filename text,
  size_bytes bigint NOT NULL,
  storage_path text NOT NULL,
  sha256 text NOT NULL,
  created_at timestamptz NOT NULL DEFAULT now(),
  expires_at timestamptz NOT NULL
);
CREATE INDEX media_expires_idx ON media (expires_at);
CREATE TABLE event_outbox (
  id uuid PRIMARY KEY,
  subject text NOT NULL,
  envelope jsonb NOT NULL,
  attempts int NOT NULL DEFAULT 0,
  last_error text,
  created_at timestamptz NOT NULL DEFAULT now(),
  published_at timestamptz
);
CREATE INDEX event_outbox_pending_idx ON event_outbox (created_at) WHERE published_at IS NULL;
