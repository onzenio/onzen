package postgres

import (
	"context"
	"os"
	"testing"
)

func TestMigrate(t *testing.T) {
	databaseURL := os.Getenv("WZAP_TEST_DATABASE_URL")
	if databaseURL == "" {
		t.Skip("set WZAP_TEST_DATABASE_URL to run the Postgres integration test")
	}

	ctx := context.Background()
	pool, err := Connect(ctx, databaseURL)
	if err != nil {
		t.Fatalf("Connect: %v", err)
	}
	t.Cleanup(pool.Close)

	if _, err := pool.Exec(ctx, `DROP SCHEMA IF EXISTS public CASCADE`); err != nil {
		t.Fatalf("drop public schema: %v", err)
	}
	if _, err := pool.Exec(ctx, `CREATE SCHEMA public`); err != nil {
		t.Fatalf("create public schema: %v", err)
	}

	if err := Migrate(ctx, pool); err != nil {
		t.Fatalf("first Migrate: %v", err)
	}

	for _, table := range []string{
		"instances",
		"outbound_messages",
		"idempotency_keys",
		"jid_cache",
		"media",
		"event_outbox",
	} {
		var exists bool
		err := pool.QueryRow(ctx, `
			SELECT EXISTS (
				SELECT 1 FROM information_schema.tables
				WHERE table_schema = 'public' AND table_name = $1
			)`, table).Scan(&exists)
		if err != nil {
			t.Fatalf("check table %s: %v", table, err)
		}
		if !exists {
			t.Errorf("table %s does not exist after Migrate", table)
		}
	}

	if err := Migrate(ctx, pool); err != nil {
		t.Fatalf("second Migrate (idempotence): %v", err)
	}
}
