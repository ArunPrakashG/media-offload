# Contributing to Nimbus

Thanks for your interest in contributing! Nimbus is a free, open-source
WordPress plugin, and contributions of all kinds are welcome — bug reports,
feature requests, documentation fixes, and pull requests.

## Project layout

```
apps/
├── plugin/nimbus/   The WordPress plugin (PHP) — this is the product
└── web/             Marketing & documentation website (Astro)
```

See [`apps/plugin/nimbus/README.md`](apps/plugin/nimbus/README.md) for the
plugin's architecture, features, REST API, and hooks.

## Local development

The plugin ships a Docker-based WordPress sandbox for testing:

```bash
docker compose -f infra/dev/docker-compose.yml up -d
docker compose -f infra/dev/docker-compose.yml --profile cli run --rm wpcli plugin list
docker compose -f infra/dev/docker-compose.yml down
```

WordPress runs at `http://localhost:8080` (admin / password). It also
starts a local MinIO instance for testing S3-compatible offload without
real cloud credentials — see [`apps/plugin/nimbus/README.md`](apps/plugin/nimbus/README.md#local-development-sandbox).

Install PHP dependencies before testing storage-provider code:

```bash
cd apps/plugin/nimbus
composer install
```

## Reporting bugs

Open a GitHub issue with:
- WordPress and PHP version
- Storage provider in use
- Steps to reproduce
- Relevant entries from the Nimbus **Logs** admin tab, if applicable

## Submitting a pull request

1. Fork the repo and create a branch from `main`.
2. Keep changes focused — one logical change per PR.
3. Test against the local Docker sandbox before submitting.
4. Describe what changed and why in the PR description.

By submitting a contribution, you agree it will be licensed under the
project's [MIT license](LICENSE).
