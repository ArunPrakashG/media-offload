# scripts/

Repo-level automation and developer convenience scripts.

## Usage

All scripts are run from the repo root.

```bash
bash scripts/<script-name>.sh
```

## Scripts

| Script              | Purpose                                                                         |
| ------------------- | ------------------------------------------------------------------------------- |
| `release-plugin.sh` | Package `apps/plugin/nimbus` into `dist/nimbus-<version>.zip` and a `.sha256` file |

## Guidelines

- Each script must be idempotent where possible.
- Scripts that have side effects outside the repo (deploys, releases) must prompt for confirmation.
- Add a short comment block at the top of every script describing what it does and any required env vars.
