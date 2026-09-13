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
// and the authenticated /api/v1 group. Health endpoints are stubs and deps is
// reserved until the handlers land in a later task.
func New(cfg config.Config, log *slog.Logger, deps Deps) *http.Server {
	mux := http.NewServeMux()
	mux.HandleFunc("GET /healthz", handleHealthz)
	mux.HandleFunc("GET /readyz", handleReadyz)

	api := http.NewServeMux()
	mux.Handle("/api/v1/", Auth(cfg.ServiceToken)(api))

	return &http.Server{
		Addr:              cfg.HTTPAddr,
		Handler:           RequestID(Logging(log)(Recover(log)(mux))),
		ReadHeaderTimeout: 10 * time.Second,
	}
}

func handleHealthz(w http.ResponseWriter, _ *http.Request) {
	JSON(w, http.StatusOK, map[string]string{"status": "ok"})
}

func handleReadyz(w http.ResponseWriter, _ *http.Request) {
	JSON(w, http.StatusOK, map[string]string{"status": "ready"})
}
