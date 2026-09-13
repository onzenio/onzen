// Command wzap runs the WhatsApp bridge service.
package main

import (
	"context"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"onefisc/wzap/internal/config"
	"onefisc/wzap/internal/httpapi"
	"onefisc/wzap/internal/storage/postgres"
	"onefisc/wzap/internal/version"
)

const (
	shutdownTimeout    = 10 * time.Second
	healthcheckTimeout = 5 * time.Second
)

func main() {
	if err := run(os.Args[1:]); err != nil {
		fmt.Fprintln(os.Stderr, "wzap:", err)
		os.Exit(1)
	}
}

// run dispatches the subcommands; serving is the default.
func run(args []string) error {
	command := "serve"
	if len(args) > 0 {
		command = args[0]
	}

	switch command {
	case "serve":
		return serve()
	case "migrate":
		return migrate()
	case "healthcheck":
		return healthcheck()
	default:
		return fmt.Errorf("unknown command %q, want serve, migrate or healthcheck", command)
	}
}

// serve runs the HTTP server until an interrupt or termination signal, then
// drains in-flight requests within shutdownTimeout.
func serve() error {
	cfg, err := config.Load()
	if err != nil {
		return err
	}
	log, err := newLogger(cfg)
	if err != nil {
		return err
	}

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	pool, err := postgres.Connect(ctx, cfg.DatabaseURL)
	if err != nil {
		return fmt.Errorf("connect database: %w", err)
	}
	defer pool.Close()

	if cfg.AutoMigrate {
		if err := postgres.Migrate(ctx, pool); err != nil {
			return fmt.Errorf("migrate database: %w", err)
		}
	}

	srv := httpapi.New(cfg, log, httpapi.Deps{ReadyChecker: httpapi.NewChecker(pool)})

	log.Info("wzap listening", "version", version.Version, "addr", cfg.HTTPAddr)

	serveErr := make(chan error, 1)
	go func() {
		if err := srv.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
			serveErr <- err
		}
	}()

	select {
	case err := <-serveErr:
		return fmt.Errorf("serve http: %w", err)
	case <-ctx.Done():
	}

	stop() // a second signal aborts the drain instead of being ignored

	shutdownCtx, cancel := context.WithTimeout(context.Background(), shutdownTimeout)
	defer cancel()
	if err := srv.Shutdown(shutdownCtx); err != nil {
		return fmt.Errorf("shutdown http server: %w", err)
	}

	// Background workers (outbox relay, session manager) are stopped here once
	// they exist; the HTTP server drains first so in-flight requests finish.

	log.Info("wzap stopped")
	return nil
}

// migrate applies the pending migrations and exits.
func migrate() error {
	cfg, err := config.Load()
	if err != nil {
		return err
	}

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	pool, err := postgres.Connect(ctx, cfg.DatabaseURL)
	if err != nil {
		return fmt.Errorf("connect database: %w", err)
	}
	defer pool.Close()

	if err := postgres.Migrate(ctx, pool); err != nil {
		return fmt.Errorf("migrate database: %w", err)
	}

	fmt.Println("migrations applied")
	return nil
}

// healthcheck calls the local readiness endpoint and exits 0 when the service
// is ready or 1 otherwise.
func healthcheck() error {
	cfg, err := config.Load()
	if err != nil {
		return err
	}

	url, err := readyURL(cfg.HTTPAddr)
	if err != nil {
		return err
	}

	client := &http.Client{Timeout: healthcheckTimeout}
	resp, err := client.Get(url)
	if err != nil {
		return fmt.Errorf("healthcheck %s: %w", url, err)
	}
	defer func() { _ = resp.Body.Close() }()
	_, _ = io.Copy(io.Discard, resp.Body)

	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("healthcheck %s: status %d", url, resp.StatusCode)
	}
	return nil
}

// readyURL builds the loopback readiness URL for a configured HTTP address,
// replacing empty or wildcard hosts with 127.0.0.1.
func readyURL(addr string) (string, error) {
	host, port, err := net.SplitHostPort(addr)
	if err != nil {
		return "", fmt.Errorf("invalid HTTP address %q: %w", addr, err)
	}

	switch host {
	case "", "0.0.0.0", "::":
		host = "127.0.0.1"
	}
	return "http://" + net.JoinHostPort(host, port) + "/readyz", nil
}

// newLogger builds the structured logger from the configuration.
func newLogger(cfg config.Config) (*slog.Logger, error) {
	var level slog.Level
	if err := level.UnmarshalText([]byte(cfg.LogLevel)); err != nil {
		return nil, fmt.Errorf("invalid WZAP_LOG_LEVEL %q: %w", cfg.LogLevel, err)
	}
	opts := &slog.HandlerOptions{Level: level}

	var handler slog.Handler
	switch cfg.LogFormat {
	case "json":
		handler = slog.NewJSONHandler(os.Stdout, opts)
	case "text":
		handler = slog.NewTextHandler(os.Stdout, opts)
	default:
		return nil, fmt.Errorf("invalid WZAP_LOG_FORMAT %q, want json or text", cfg.LogFormat)
	}
	return slog.New(handler), nil
}
