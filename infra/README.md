# infra/

Infrastructure-as-code and environment configuration for the Plume platform.

## Structure (to be populated)

```
infra/
├── terraform/      Cloud resource definitions (VPS, DNS, object storage, CDN)
├── docker/         Production Dockerfiles for apps/web
└── ci/             CI/CD pipeline configs (GitHub Actions workflows)
```

## Guidelines

- All secrets are injected via environment variables or a secrets manager — never committed.
- `.tfvars` files are gitignored; use `.tfvars.example` as a template.
- Each subdirectory should have its own `README.md` once populated.
