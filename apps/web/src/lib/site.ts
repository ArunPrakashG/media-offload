import { getCollection, type CollectionEntry } from 'astro:content';

export const SITE = {
  name: 'Plume',
  url: 'https://plume.arunprakashg.com',
  tagline: 'Move your media to the cloud. Keep your workflow.',
  description:
    'Plume is a free, open source WordPress plugin that offloads your Media Library to Amazon S3, Cloudflare R2 or any S3-compatible storage and rewrites every URL.',
  repo: 'https://github.com/ArunPrakashG/media-offload',
  releases: 'https://github.com/ArunPrakashG/media-offload/releases',
  contributing: 'https://github.com/ArunPrakashG/media-offload/blob/main/CONTRIBUTING.md',
  author: { name: 'Arun Prakash', url: 'https://arunprakashg.com', email: 'hello@usenimbus.dev' },
  version: '0.3.5',
} as const;

export const NAV = [
  { href: '/#features', label: 'Features' },
  { href: '/docs/', label: 'Docs' },
  { href: '/compare/', label: 'Compare' },
  { href: '/changelog/', label: 'Changelog' },
];

export const PROVIDERS = [
  { name: 'Amazon S3', logo: 's3', slug: 'amazon-s3' },
  { name: 'Cloudflare R2', logo: 'cloudflare', slug: 'cloudflare-r2' },
  { name: 'Google Cloud Storage', logo: 'gcs', slug: 'google-cloud-storage' },
  { name: 'Backblaze B2', logo: 'backblaze', slug: 'backblaze-b2' },
  { name: 'DigitalOcean Spaces', logo: 'digitalocean', slug: 'digitalocean-spaces' },
  { name: 'Wasabi', logo: 'wasabi', slug: 'wasabi' },
  { name: 'MinIO', logo: 'minio', slug: 'minio' },
] as const;

export const DOC_SECTIONS = [
  { key: 'start', label: 'Start' },
  { key: 'providers', label: 'Providers' },
  { key: 'guides', label: 'Guides' },
  { key: 'reference', label: 'Reference' },
] as const;

export type Doc = CollectionEntry<'docs'>;

/** Docs in reading order: section, then order. */
export async function getDocs(): Promise<Doc[]> {
  const rank = Object.fromEntries(DOC_SECTIONS.map((s, i) => [s.key, i]));
  const docs = await getCollection('docs');
  return docs.sort((a, b) => rank[a.data.section] - rank[b.data.section] || a.data.order - b.data.order);
}

/** URL path for a doc entry. The `index` entry is the docs home. */
export function docPath(doc: Doc): string {
  return doc.id === 'index' ? '/docs/' : `/docs/${doc.id}/`;
}

export const FAQ = [
  {
    q: 'Are local files deleted after offloading?',
    a: 'No. Local copies stay on your server unless you turn on Remove local copies. You can restore files from storage at any time.',
  },
  {
    q: 'Can I use my own CDN domain?',
    a: 'Yes. Set a custom domain such as media.example.com and Plume builds every media URL with it, so files are served through your CDN.',
  },
  {
    q: 'What happens if I deactivate Plume?',
    a: 'Scheduled jobs stop and your settings are kept. Files already in your bucket stay there. If you removed local copies, restore them first so your server can serve the Media Library on its own.',
  },
  {
    q: 'Does Plume send any data to you?',
    a: 'No. There is no license server and no telemetry. Your site talks only to the storage provider and cache services you configure.',
  },
];
