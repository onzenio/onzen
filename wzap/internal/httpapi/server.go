// Package httpapi implements the wzap REST API: the server wiring, the
// response envelope and the shared middleware chain.
package httpapi

import (
	"context"
	"log/slog"
	"net/http"
	"time"

	"onefisc/wzap/internal/config"
)

// ReadyChecker reports whether the service dependencies are ready to serve
// traffic.
type ReadyChecker interface {
	Check(ctx context.Context) error
}

// Deps carries the dependencies consumed by HTTP handlers. It grows as later
// tasks register handlers; today it only reserves the readiness checker.
type Deps struct {
	ReadyChecker ReadyChecker
}

// New builds the HTTP server with the middleware chain, the health endpoints
// and the authenticated /api/v1 group.
func New(cfg config.Config, log *slog.Logger, deps Deps) *http.Server {
	mux := http.NewServeMux()
	mux.HandleFunc("GET /healthz", handleHealthz)
	mux.HandleFunc("GET /readyz", handleReadyz(deps.ReadyChecker, log))

	api := http.NewServeMux()
	mux.Handle("/api/v1/", Auth(cfg.ServiceToken)(api))

	return &http.Server{
		Addr:              cfg.HTTPAddr,
		Handler:           RequestID(Logging(log)(Recover(log)(mux))),
		ReadHeaderTimeout: 10 * time.Second,
	}
}
