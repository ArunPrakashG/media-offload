# ── Build stage ────────────────────────────────────────────────────────────────
FROM node:22-alpine AS builder

# Enable corepack so we can use pnpm without a global install.
RUN corepack enable && corepack prepare pnpm@9.0.0 --activate

WORKDIR /app

# Install dependencies first (better layer caching).
COPY package.json ./
RUN pnpm install --no-frozen-lockfile

# Copy source and build.
COPY . .
RUN pnpm build

# ── Runtime stage ──────────────────────────────────────────────────────────────
FROM node:22-alpine

WORKDIR /app

ENV NODE_ENV=production \
    HOST=0.0.0.0 \
    PORT=4321

# Copy only what the standalone Node server needs at runtime.
COPY --from=builder /app/dist ./dist
COPY --from=builder /app/node_modules ./node_modules
COPY package.json server.mjs ./

EXPOSE 4321

# server.mjs wraps the Astro handler to add security and cache headers.
CMD ["node", "server.mjs"]
