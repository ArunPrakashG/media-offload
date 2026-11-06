# Plume design

Claude Design artifact: https://claude.ai/artifact/J118rUvLGZtnmQRiAyktX1 (private to the owner).
Local copies of every board live in `mockups/`, with PNG renders in `mockups/renders/`.

## Purpose

Moves WordPress media to the storage you choose, and keeps every URL working.

## Brand

- Name: Plume. Wordmark is lowercase `nimbus`, Geist 600, letter-spacing -0.03em.
- Mark: a volt cloud on an ink tile. One square corner, bottom left: the file inside the cloud.
  - `brand/mark.svg`: tile, for favicons, app icons and the wordpress.org icon.
  - `brand/mark-dark.svg`: tile with a 1px `#343B46` border, for dark backgrounds.
  - `brand/glyph.svg`: cloud only, `currentColor`, for the wp-admin menu icon.
- Voice: plain, exact, calm. Say what happens to the files. No hype.
- Signature: the rewrite. A URL changes host from `example.com/wp-content/uploads` to `media.example.com`, and a volt marker sweeps over the new host.

## Tokens

`tokens.css` maps one to one to code. Prefix `--nmb-`.

- Volt `#C6F135` means "in the cloud" and nothing else. Ink text on volt (14.5:1). Use `--nmb-volt-text` for volt-coloured text on light surfaces.
- Light and dark sets for bg, surface, surface-2, border, border-strong, text, text-2, text-3, volt-text, volt-tint, danger, warn.
- Type: Geist and Geist Mono, self-hosted. Display 72/76, h2 44/48, h3 20/28, body 17/28, small 14/20, mono 14/22.
- Spacing 4 8 12 16 24 32 48 64 96 128. Radii 6, 10, 16, full.
- Elevation: borders first. `--nmb-shadow` only on floating surfaces.

## Motion

- `--nmb-dur-1` 120ms hover and press, `--nmb-dur-2` 200ms state, `--nmb-dur-3` 600ms the rewrite.
- Ease `cubic-bezier(.22,1,.36,1)`, stagger 60ms.
- Animate transform and opacity only. Under `prefers-reduced-motion`, show the final state.
- The rewrite: 0ms old URL, 240ms old host fades and slides up 6px, 600ms volt marker sweeps left to right over the new host.

## Components

Only what the screens use.

- `Button.primary` (volt), `Button.ink`, `Button.secondary` (outline), `Link`.
- `Status`: icon plus text, never colour alone. Offloaded (check, volt tint), Local only (ring, surface-2), Failed (bang, danger).
- `ProviderTile`: logo on white, name, selected state with ink border and check.
- `Stat`: label, value, optional note.
- `Rewrite`: before/after URL block with the volt marker.

## Screens

| Board | What it proves |
|---|---|
| Foundations | The system above |
| Home (1440, light and dark) | usenimbus.dev landing page |
| Home, phone (390, dark) | Mobile layout |
| Docs | Docs page template, provider guide |
| WP admin: Overview | Plugin dashboard in the brand |
| WP admin: Connect storage | Setup wizard, provider picker |
| WP admin: Quick connect, token | R2 from one pasted token: account, bucket, public URL found for you |
| WP admin: Quick connect, key | B2, Wasabi, Spaces, MinIO: one admin key in, bucket-only key out |
| WP admin: Quick connect, command | S3 and Google Cloud: one command in the provider's browser shell, paste back one line |
| Open Graph 1200x630 | Social card |
| WordPress.org banner 1544x500 | Plugin directory banner |
| Plugin icon 256 | Plugin directory icon |

## Provider logos

`logos/` holds the provider and integration logos used on the site and in the admin.
Sources: Iconify `logos` set and Simple Icons, both CC0. Logos are trademarks of their owners and are used only to show compatibility.
