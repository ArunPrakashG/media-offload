import { getEntry } from 'astro:content';
import { md } from '../lib/markdown';
import { SITE } from '../lib/site';

export const GET = async () => {
  const entry = await getEntry('docs', 'index');
  return md(`# ${entry?.data.title}\n\nURL: ${SITE.url}/docs/\n\n${entry?.body ?? ''}`);
};
