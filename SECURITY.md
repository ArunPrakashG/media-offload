# Security Policy

Plume handles cloud storage credentials and WordPress media, so security
reports are taken seriously.

## Reporting a vulnerability

**Please do not open a public GitHub issue for security vulnerabilities.**

Instead, email **hello@usenimbus.dev** with:
- A description of the vulnerability and its potential impact
- Steps to reproduce (a minimal WordPress setup is ideal)
- Affected version(s)

We aim to acknowledge reports within 3 business days. Once a fix is ready,
we'll coordinate a disclosure timeline with you and credit you in the
release notes, unless you'd prefer to remain anonymous.

## Scope

In scope:
- The Plume WordPress plugin (`apps/plugin/nimbus`)
- The marketing/documentation website (`apps/web`)

Out of scope:
- Vulnerabilities in third-party storage providers (Amazon S3, Cloudflare
  R2, etc.) or cache plugins Plume integrates with — report those to the
  relevant vendor.
- Issues requiring physical access to a server or already-compromised
  WordPress admin credentials.

## Supported versions

Only the latest released version of Plume receives security fixes.
