# packages/

Shared libraries consumed by two or more apps in this monorepo.

## When to add a package

Add a subdirectory here when a piece of logic (types, utilities, API clients, shared config) needs to be used by more than one app and keeping it duplicated across apps would cause drift.

## Example packages (to be created as needed)

| Package                    | Purpose                                                                                      |
| -------------------------- | -------------------------------------------------------------------------------------------- |
| `packages/license-client/` | Typed HTTP client for the backend licensing API, shared between `apps/plugin` and `apps/web` |
| `packages/types/`          | Shared TypeScript types for the web ↔ backend contract                                       |

## Conventions

- Each package has its own `README.md` and dependency manifest.
- Packages should have no knowledge of the apps that consume them.
