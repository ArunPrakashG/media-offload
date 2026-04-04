# scripts/

Repo-level automation and developer convenience scripts.

## Usage

All scripts are run from the repo root.

```bash
bash scripts/<script-name>.sh
```

## Scripts (to be added)

| Script              | Purpose                                                       |
| ------------------- | ------------------------------------------------------------- |
| `bootstrap.sh`      | One-command dev environment setup (install deps for all apps) |
| `build-all.sh`      | Build every app in dependency order                           |
| `lint.sh`           | Run linters across all apps and packages                      |
| `release-plugin.sh` | Package `apps/plugin/` into a distributable `.zip`            |

## Guidelines

- Each script must be idempotent where possible.
- Scripts that have side effects outside the repo (deploys, releases) must prompt for confirmation.
- Add a short comment block at the top of every script describing what it does and any required env vars.
