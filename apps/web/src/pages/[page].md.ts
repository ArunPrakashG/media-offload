import type { APIRoute, GetStaticPaths } from 'astro';
import { getCollection } from 'astro:content';
import { md } from '../lib/markdown';
import { SITE } from '../lib/site';

export const getStaticPaths: GetStaticPaths = async () =>
  (await getCollection('pages')).map((entry) => ({ params: { page: entry.id }, props: { entry } }));

export const GET: APIRoute = ({ props }) => {
  const { entry } = props;
  return md(`# ${entry.data.title}\n\nURL: ${SITE.url}/${entry.id}/\n\n${entry.body ?? ''}`);
};
