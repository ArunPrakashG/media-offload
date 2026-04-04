# ── Builder stage ──────────────────────────────────────────────────────────────
FROM golang:1.22-alpine AS builder

WORKDIR /app

# Cache module downloads first (layer invalidated only on go.mod/go.sum change).
COPY go.mod go.sum ./
RUN go mod download

COPY . .

RUN CGO_ENABLED=0 GOOS=linux go build \
    -ldflags="-s -w" \
    -o /server ./cmd/server

# ── Final stage — distroless (no shell, minimal attack surface) ────────────────
FROM gcr.io/distroless/static-debian12

COPY --from=builder /server /server

EXPOSE 8090
ENTRYPOINT ["/server"]
