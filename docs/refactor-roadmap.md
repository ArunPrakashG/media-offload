# Media Offload — Refactor Roadmap

Maintainer: **Arun Prakash** (`contact@arunprakashg.com`)

## Why this refactor exists

This plugin already solves a real problem, but the current codebase mixes bootstrap, admin UI, settings validation, storage-provider concerns, and batch workflow logic too closely. It also carried old branding and legacy root-level tooling that did not belong in a clean commercial WordPress plugin story.

The goal of the refactor is to produce a paid, maintainable plugin with:

- clear module boundaries
- predictable storage-provider support
- subscription licensing
- testable business logic
- cleaner release packaging
- minimal backward-compatibility risk for existing installs

## Research summary

The WordPress Plugin Handbook consistently recommends:

- one main plugin bootstrap file with the plugin header
- most logic organized into subfolders/classes instead of a single large loader file
- strong, unique prefixes/namespaces to avoid collisions
- admin code loaded conditionally and capability-checked
- settings registered through the Settings API / Options API
- activation, deactivation, and uninstall responsibilities kept separate
- sanitizing/validating input and escaping output consistently

Those guidelines map well to this project and support a long-lived commercial plugin architecture.

## Current pain points in this repository

1. **Branding and identifiers are inconsistent**
   - Plugin header and Composer metadata were tied to the old brand.
   - Namespace and option/meta prefixes are still legacy (`Pathanamthitta`, `pt_media_offload`, `PT_MEDIA_OFFLOAD_*`).

2. **Bootstrap is too manual**
   - `media-offload.php` manually includes many class files.
   - Composition happens inside a singleton loader instead of a deliberate bootstrap/container layer.

3. **Settings and admin UI are doing too much**
   - `Settings_UI` handles page registration, rendering, validation, AJAX, admin-post workflows, dashboard rendering, and operational tasks.
   - This makes the admin layer difficult to test and modify.

4. **Provider-specific logic is spread across multiple classes**
   - R2 behavior exists in settings normalization, UI copy, and the S3 adapter.
   - Generic S3-compatible support is partially implicit rather than modeled explicitly.

5. **Operational workflows are coupled to UI and CLI paths**
   - Batch processing, URL updates, and attachment eligibility logic need clearer application services.

6. **Release/build tooling still needs a cleaner story**
   - Root-level Python metadata has been removed, which reduces confusion.
   - A dedicated release/build workflow is still needed so packaging remains explicit and reproducible.

## Target architecture

Recommended structure after refactor:

```text
media-offload/
  media-offload.php
  uninstall.php
  composer.json
  README.md
  assets/
  languages/
  docs/
  src/
    Bootstrap/
      Plugin.php
      ServiceProvider.php
      Container.php
    Domain/
      Media/
      Storage/
      Licensing/
      Billing/
    Application/
      Offload/
      Rewrite/
      Cleanup/
      Health/
    Infrastructure/
      WordPress/
      Aws/
      Cloudflare/
      Http/
      Persistence/
    Admin/
      Pages/
      Ajax/
      Notices/
      Settings/
    Cli/
    Compat/
      LegacyHooks.php
      LegacyOptionMigration.php
  tests/
    Unit/
    Integration/
```

## Architecture principles

### 1. Thin bootstrap

`media-offload.php` should only:

- define plugin metadata/constants needed at bootstrap time
- load Composer autoload
- instantiate a bootstrap class
- register activation/deactivation hooks

No business logic should live there.

### 2. Composer-first autoloading

Move from manual `require_once` loading to PSR-4 autoloading under `src/`.

Suggested namespace:

- `ArunPrakash\MediaOffload\...`

For backward compatibility, legacy classes/constants can be retained temporarily through compatibility shims.

### 3. Separate layers by responsibility

- **Domain**: attachment offload rules, provider capabilities, license state, feature gates
- **Application**: workflows/use-cases such as offload attachment, test connection, rewrite URLs, run queue, validate license
- **Infrastructure**: AWS SDK, WordPress options/meta access, remote license API client, Cloudflare purge client
- **Admin**: settings screens, notices, forms, AJAX controllers
- **CLI**: WP-CLI command registration and command handlers
- **Compat**: migration adapters for legacy keys/hooks/classes

### 4. Use explicit interfaces

Key interfaces to introduce:

- `StorageAdapterInterface`
- `StorageProviderProfileInterface`
- `LicenseGatewayInterface`
- `LicenseCacheInterface`
- `AttachmentRepositoryInterface`
- `SettingsRepositoryInterface`
- `QueueInterface`
- `LoggerInterface`

This allows testing without WordPress globals everywhere.

## Storage-provider strategy

The paid plugin should explicitly support three provider modes:

1. **Amazon S3**
2. **Cloudflare R2**
3. **Generic S3-compatible**

Recommended model:

- keep one low-level S3 protocol adapter using the AWS SDK
- introduce provider profiles/config normalizers on top of it
- each profile declares defaults and validation rules

Example profiles:

- `AmazonS3Profile`
- `CloudflareR2Profile`
- `GenericS3CompatibleProfile`

Why this helps:

- R2-specific rules (`region=auto`, account endpoint, path-style behavior) stay isolated
- generic S3-compatible support becomes a first-class product feature instead of a fallback
- the admin UI can render provider-specific requirements from profile metadata rather than hard-coded arrays

## Licensing architecture for a monthly paid plugin

Because the plugin will be sold on a monthly subscription, licensing should be treated as a separate module, not sprinkled across admin pages.

### Recommended licensing components

- `LicenseManager`
- `LicenseGatewayInterface`
- `RemoteLicenseClient`
- `LicenseStatusRepository`
- `LicenseValidator`
- `FeatureGate`
- `SubscriptionNoticeService`

### Recommended license flow

1. User enters a license key or account token in the plugin settings.
2. Plugin calls a remote licensing endpoint.
3. Response is cached locally with:
   - status
   - plan
   - expiry/renewal date
   - enabled features
   - last successful validation timestamp
4. Plugin revalidates on a schedule and on key admin events.
5. If the subscription lapses:
   - no hard front-end fatal behavior
   - admin notices become prominent
   - premium admin workflows can be limited
   - a grace period is applied before premium features are disabled

### Important commercialization rules

- Do not tie core media delivery on the public site to a live license check.
- Cache license status locally and fail gracefully.
- Use signed responses or HMAC verification to prevent trivial spoofing.
- Separate billing vendor concerns from plugin feature gating.
- Keep the license gateway replaceable in case you move between Lemon Squeezy, Paddle, WooCommerce, EDD, or a custom service.

## Backward compatibility plan

This is important because the plugin already exists on a live site.

### Keep temporarily

- existing option key: `pt_media_offload_settings`
- existing attachment meta key(s)
- existing WP-CLI command aliases
- old hooks/filters where practical

### Migrate gradually

Phase the rename rather than breaking everything at once:

- introduce new internal namespace/vendor prefix
- keep reading legacy settings/meta
- write migrations that can upgrade data safely
- add deprecation logging for old internals
- remove old shims only after one or two stable paid releases

## Tooling direction

Python is no longer part of the root plugin setup, and it should stay out of the runtime architecture.

If auxiliary tooling is added in the future, keep it isolated and explicit, for example under a dedicated path such as:

- `tools/`

Rules:

- no runtime dependency on non-PHP tooling for the WordPress plugin
- no mixed messaging in the root README
- clear developer docs describing when any auxiliary tooling is needed

## Testing and quality gates

For maintainability, introduce these in the refactor:

- PHPUnit for unit tests
- WordPress integration tests for hooks/settings/storage workflows
- PHPCS with WordPress coding standards
- PHPStan or Psalm for static analysis
- a CI workflow to run tests and static checks before release

Priority order:

1. storage config normalization tests
2. attachment offload workflow tests
3. URL rewrite tests
4. licensing tests
5. admin settings sanitization tests

## Release engineering recommendations

- keep development dependencies out of the release zip where possible
- build release artifacts from source, not from a hand-curated working tree
- add an `uninstall.php` file when uninstall behavior is finalized
- add a changelog and upgrade notes
- package premium releases with vendor dependencies included in the artifact, even if `vendor/` is ignored in Git

## Suggested implementation phases

### Phase 0 — Stabilize the repo

- initialize Git
- add `.gitignore`
- normalize branding
- document the refactor target

### Phase 1 — Rebuild the bootstrap

- add PSR-4 autoloading
- move classes from `includes/` into `src/`
- replace the singleton-heavy loader with a bootstrap/container approach
- register activation/deactivation hooks cleanly

### Phase 2 — Extract modules

- split `Settings_UI` into controllers, renderers, and validators
- extract settings repository and provider profile metadata
- extract URL rewrite and offload workflows into application services

### Phase 3 — Provider model cleanup

- formalize Amazon S3, Cloudflare R2, and Generic S3-compatible profiles
- centralize endpoint/region/path-style defaults
- add connection diagnostics per provider

### Phase 4 — Licensing module

- add license storage/settings UI
- add remote validation client and cache
- add scheduled revalidation + grace period logic
- introduce feature gates for premium-only capabilities

### Phase 5 — Queueing and batch workflows

- move off-hours/batch logic behind a dedicated queue abstraction
- strongly consider Action Scheduler for maintainable background processing
- unify admin-triggered and CLI-triggered workflows through shared services

### Phase 6 — Compatibility and data migration

- add legacy key readers/migrators
- test upgrade paths from the current plugin state
- add uninstall cleanup logic for the paid product

### Phase 7 — CI and release pipeline

- add static analysis, coding standards, tests, packaging
- produce a clean commercial release artifact

## Competitor-informed priorities

After reviewing WP Offload Media's public product pages, pricing, and docs structure, the most important priorities for `Media Offload` are:

1. **A stronger setup experience**
   - guided provider selection
   - credential validation
   - connection diagnostics
   - better explanation of delivery/CDN choices

2. **Background bulk tools**
   - offload existing library in the background
   - clear progress, pause/resume, and recovery states
   - reversible tools for download/remove/restore

3. **Media Library controls**
   - filter offloaded/local/failed items
   - bulk actions for offload, restore, and remote cleanup
   - attachment-level storage status details

4. **Commercial-grade licensing UX**
   - license screen, usage metering, plan visibility, renewal notices
   - graceful handling when limits are reached or subscriptions lapse

5. **Integration surface**
   - WooCommerce, Easy Digital Downloads, ACF, WPML, media replacement, image optimization, and builder compatibility

6. **A premium-looking admin interface**
   - cleaner onboarding flow
   - card-based layout
   - richer status surfaces
   - background job progress and diagnostics

## Recommended next implementation slice

Before adding any new feature, the next practical slice should be:

1. introduce PSR-4 autoloading and a `src/` structure
2. move the bootstrap and plugin composition into dedicated bootstrap classes
3. split `Settings_UI` into smaller admin-focused classes
4. isolate provider profiles for S3 / R2 / generic S3-compatible

That slice gives the biggest maintainability win with the least product risk.

## Immediate execution plan

The next best sequence for this repository is:

1. rebuild bootstrap and autoloading under `src/`
2. extract `Settings_UI` into separate admin page, settings registry, action controller, and diagnostics services
3. build a new admin information architecture inspired by leading commercial plugins:
   - Overview
   - Storage
   - Delivery
   - Tools
   - Integrations
   - License
   - Logs / Diagnostics
4. formalize provider profiles for Amazon S3, Cloudflare R2, and Generic S3-compatible
5. add a bulk operations engine with resumable jobs
6. add licensing and plan/usage tracking
7. expand integration coverage and polish
