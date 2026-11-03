import { defineCollection } from 'astro:content';
import { glob } from 'astro/loaders';
import { z } from 'astro/zod';

const docs = defineCollection({
  loader: glob({ pattern: '**/*.md', base: './src/content/docs' }),
  schema: z.object({
    title: z.string(),
    nav: z.string(),
    description: z.string(),
    section: z.enum(['start', 'providers', 'guides', 'reference']),
    order: z.number(),
    logo: z.enum(['s3', 'cloudflare', 'gcs', 'backblaze', 'digitalocean', 'wasabi', 'minio']).optional(),
  }),
});

const pages = defineCollection({
  loader: glob({ pattern: '*.md', base: './src/content/pages' }),
  schema: z.object({
    title: z.string(),
    description: z.string(),
    updated: z.coerce.date(),
  }),
});

export const collections = { docs, pages };
