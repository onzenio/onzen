// Package httpapi implements the wzap REST API: the server wiring, the
// response envelope and the shared middleware chain.
package httpapi

import (
	"context"
	"log/slog"
	"net/http"
	"time"

	"onefisc/wzap/internal/config"
	"onefisc/wzap/internal/storage"
)

// ReadyChecker reports whether the service dependencies are ready to serve
// traffic.
type ReadyChecker interface {
	Check(ctx context.Context) error
}

// Deps carries the dependencies consumed by HTTP handlers. It grows as later
// tasks register handlers.
type Deps struct {
	ReadyChecker ReadyChecker
	Instances    InstanceService
	Numbers      NumberResolver
	Messages     MessageService
	Idempotency  storage.IdempotencyRepository
}

// New builds the HTTP server with the middleware chain, the health endpoints
// and the authenticated /api/v1 group.
func New(cfg config.Config, log *slog.Logger, deps Deps) *http.Server {
	mux := http.NewServeMux()
	mux.HandleFunc("GET /healthz", handleHealthz)
	mux.HandleFunc("GET /readyz", handleReadyz(deps.ReadyChecker, log))

	api := http.NewServeMux()
	api.HandleFunc("POST /api/v1/instances", handleCreateInstance(deps.Instances))
	api.HandleFunc("GET /api/v1/instances", handleListInstances(deps.Instances))
	api.HandleFunc("GET /api/v1/instances/{id}", handleGetInstance(deps.Instances))
	api.HandleFunc("PATCH /api/v1/instances/{id}", handleUpdateInstance(deps.Instances))
	api.HandleFunc("DELETE /api/v1/instances/{id}", handleDeleteInstance(deps.Instances))
	api.HandleFunc("POST /api/v1/instances/{id}/connect", handleConnectInstance(deps.Instances))
	api.HandleFunc("POST /api/v1/instances/{id}/disconnect", handleDisconnectInstance(deps.Instances))
	api.HandleFunc("GET /api/v1/instances/{id}/qr", handleQRInstance(deps.Instances))
	api.HandleFunc("GET /api/v1/instances/{id}/status", handleInstanceStatus(deps.Instances))
	api.HandleFunc("POST /api/v1/instances/{id}/numbers/check", handleCheckNumber(deps.Instances, deps.Numbers))
	api.Handle("POST /api/v1/instances/{id}/messages/text", Idempotency(deps.Idempotency, log)(handleSendText(deps.Messages)))
	api.Handle("POST /api/v1/instances/{id}/messages/location", Idempotency(deps.Idempotency, log)(handleSendLocation(deps.Messages)))
	api.Handle("POST /api/v1/instances/{id}/messages/contact", Idempotency(deps.Idempotency, log)(handleSendContact(deps.Messages)))
	api.HandleFunc("GET /api/v1/instances/{id}/messages", handleListMessages(deps.Messages))
	api.HandleFunc("GET /api/v1/instances/{id}/messages/{message_id}", handleGetMessage(deps.Messages))
	mux.Handle("/api/v1/", Auth(cfg.ServiceToken)(api))

	return &http.Server{
		Addr:              cfg.HTTPAddr,
		Handler:           RequestID(Logging(log)(Recover(log)(mux))),
		ReadHeaderTimeout: 10 * time.Second,
	}
}
