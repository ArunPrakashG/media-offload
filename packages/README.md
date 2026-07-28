# packages/

Shared libraries consumed by two or more apps in this monorepo.

## When to add a package

Add a subdirectory here when a piece of logic (types, utilities, API clients, shared config) needs to be used by more than one app and keeping it duplicated across apps would cause drift.

No packages exist yet. Add one here if logic needs to be shared between `apps/plugin` and `apps/web` (e.g. shared TypeScript types, config, or utilities) — there is no backend service for either app to share a client with.

## Conventions

- Each package has its own `README.md` and dependency manifest.
- Packages should have no knowledge of the apps that consume them.
