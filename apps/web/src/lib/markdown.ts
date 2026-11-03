import { getCollection } from 'astro:content';
import { FAQ, PROVIDERS, SITE, docPath, getDocs } from './site';

export const md = (body: string) =>
  new Response(body, { headers: { 'Content-Type': 'text/markdown; charset=utf-8' } });

export const txt = (body: string, type = 'text/plain') =>
  new Response(body, { headers: { 'Content-Type': `${type}; charset=utf-8` } });

/** Markdown version of the home page. Keep in step with pages/index.astro. */
export function homeMarkdown(): string {
  return `# Nimbus

> ${SITE.description}

${SITE.tagline}

Nimbus offloads your Media Library to Amazon S3, Cloudflare R2 or any S3-compatible bucket, and rewrites every URL so pages load from storage or your CDN.

- License: MIT. No license server, no telemetry, no paid tier.
- Requirements: WordPress 6.0+, PHP 8.1+. Multisite ready.
- Install: [Installation](${SITE.url}/docs/)
- Source: [GitHub](${SITE.repo})

## Supported storage

${PROVIDERS.map((p) => `- [${p.name}](${SITE.url}/docs/providers/${p.slug}/)`).join('\n')}
- [Any S3-compatible API](${SITE.url}/docs/providers/s3-compatible/)

## How it works

1. Connect a bucket. Pick a provider, paste your keys and test the connection from the setup wizard.
2. Offload your library. New uploads go to storage automatically. Existing files move in a bulk job you can pause and resume.
3. Serve from the edge. URLs in posts and metadata point to your bucket or CDN domain, and caches are purged for you.

## Features

- Bulk migration: move thousands of existing files in the background. Pause, resume and retry failures.
- Restore any time: pull files back to your server and revert URLs, one attachment or the whole library.
- Cache purge: Cloudflare, LiteSpeed, WP Fastest Cache and proxy caches are cleared after each change.
- Page builders: URLs stored by Elementor, Slider Revolution, Smart Slider 3 and NextGEN Gallery are rewritten too.
- WooCommerce downloads: protected product files are delivered through short-lived signed storage URLs.
- Multisite: a network admin panel with offload totals for every site.
- WP-CLI: offload, rewrite, revert and download from the terminal. See [WP-CLI](${SITE.url}/docs/reference/wp-cli/).

## Questions

${FAQ.map((f) => `### ${f.q}\n\n${f.a}`).join('\n\n')}
`;
}

/** Every page as one Markdown document, for llms-full.txt. */
export async function fullMarkdown(): Promise<string> {
  const docs = await getDocs();
  const pages = await getCollection('pages');
  const parts = [homeMarkdown()];
  for (const d of docs) parts.push(`# ${d.data.title}\n\nURL: ${SITE.url}${docPath(d)}\n\n${d.body ?? ''}`);
  for (const p of pages) parts.push(`# ${p.data.title}\n\nURL: ${SITE.url}/${p.id}/\n\n${p.body ?? ''}`);
  return parts.join('\n\n---\n\n');
}
