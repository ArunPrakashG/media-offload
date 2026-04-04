# Nimbus

> Your media, elevated.

Monorepo for the Nimbus product — a WordPress media offload plugin, a marketing website, and a subscription/licensing backend.

## Structure

```
nimbus/
├── apps/
│   ├── plugin/     WordPress plugin (PHP) — offloads media to S3 / R2 / compatible storage
│   ├── web/        Marketing & documentation website
│   └── backend/    Subscription and license management API (Go / Python)
├── packages/       Shared libraries and utilities consumed across apps
├── infra/          Infrastructure-as-code (Terraform / Docker / CI configs)
├── scripts/        Repo-level dev and automation scripts
└── docker-compose.yml   Local WordPress sandbox for plugin development
```

## Apps

| App                              | Language    | Purpose                        |
| -------------------------------- | ----------- | ------------------------------ |
| [`apps/plugin/`](apps/plugin/)   | PHP 8.1+    | WordPress media offload plugin |
| [`apps/web/`](apps/web/)         | TBD         | Marketing and docs site        |
| [`apps/backend/`](apps/backend/) | Go / Python | License & subscription API     |

## Quick start — plugin development

```bash
# Start local WordPress sandbox
docker compose up -d

# WP-CLI shortcut
docker compose --profile cli run --rm wpcli plugin list

# Stop
docker compose down

# Full reset (wipes database + uploads)
docker compose down -v
```

WordPress runs at `http://localhost:8080` · admin / password

See [`apps/plugin/README.md`](apps/plugin/README.md) for full plugin documentation.

## Contributing

Each app and package has its own README with setup instructions. Start with the relevant subdirectory.
