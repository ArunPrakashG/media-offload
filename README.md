# Nimbus

> Your media, elevated.

Monorepo for the Nimbus product — a free, open-source WordPress media offload plugin and its marketing/docs website. There is no subscription or licensing backend; Nimbus has no paid tier.

## Structure

```
nimbus/
├── apps/
│   ├── plugin/nimbus/  WordPress plugin (PHP) — offloads media to S3 / R2 / compatible storage
│   └── web/            Marketing & documentation website (Astro)
├── packages/           Shared libraries and utilities consumed across apps
├── infra/              Infrastructure-as-code (Docker / CI configs)
├── scripts/            Repo-level dev and automation scripts
├── docs/               Architecture notes; docs/history/ is archived pre-OSS planning docs
└── tests/e2e/          Playwright end-to-end tests
```

## Apps

| App                                          | Language     | Purpose                        |
| --------------------------------------------- | ------------ | ------------------------------- |
| [`apps/plugin/nimbus/`](apps/plugin/nimbus/) | PHP 8.1+     | WordPress media offload plugin |
| [`apps/web/`](apps/web/)                     | Astro/TS     | Marketing and docs site        |

## Quick start — plugin development

```bash
# Start local WordPress sandbox
docker compose -f infra/dev/docker-compose.yml up -d
# WP-CLI shortcut
docker compose -f infra/dev/docker-compose.yml --profile cli run --rm wpcli plugin list

# Stop
docker compose -f infra/dev/docker-compose.yml down
# Full reset (wipes database + uploads)
docker compose -f infra/dev/docker-compose.yml down -v
```

WordPress runs at `http://localhost:8080` · admin / password

See [`apps/plugin/nimbus/README.md`](apps/plugin/nimbus/README.md) for full plugin documentation.

## Contributing

See [`CONTRIBUTING.md`](CONTRIBUTING.md). Each app also has its own README with setup instructions — start with the relevant subdirectory.

## License

MIT. See [`LICENSE`](LICENSE).

## Security

See [`SECURITY.md`](SECURITY.md) for how to report a vulnerability.
