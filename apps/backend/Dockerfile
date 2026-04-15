# ── Builder stage ──────────────────────────────────────────────────────────────
FROM golang:1.24-alpine AS builder

WORKDIR /app

# Cache module downloads first (layer invalidated only on go.mod/go.sum change).
COPY go.mod go.sum ./
RUN go mod download

COPY . .

RUN CGO_ENABLED=0 GOOS=linux go build \
    -ldflags="-s -w" \
    -o /server ./cmd/server

RUN CGO_ENABLED=0 GOOS=linux go build \
    -ldflags="-s -w" \
    -o /migrate ./scripts/migrate

RUN CGO_ENABLED=0 GOOS=linux go build \
    -ldflags="-s -w" \
    -o /nimbus-admin ./cmd/admin

# ── Final stage — alpine (small, has sh + wget for health checks) ──────────────
FROM alpine:3

# No extra packages needed; Go binaries are statically linked (CGO_ENABLED=0).
COPY --from=builder /server /server
COPY --from=builder /migrate /migrate
COPY --from=builder /nimbus-admin /nimbus-admin
COPY --from=builder /app/migrations /migrations

EXPOSE 8090
ENTRYPOINT ["/server"]
