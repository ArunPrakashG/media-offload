import type { APIRoute, GetStaticPaths } from 'astro';
import { md } from '../../lib/markdown';
import { SITE, docPath, getDocs } from '../../lib/site';

export const getStaticPaths: GetStaticPaths = async () =>
  (await getDocs()).filter((d) => d.id !== 'index').map((entry) => ({ params: { slug: entry.id }, props: { entry } }));

export const GET: APIRoute = ({ props }) => {
  const { entry } = props;
  return md(`# ${entry.data.title}\n\nURL: ${SITE.url}${docPath(entry)}\n\n${entry.body ?? ''}`);
};
