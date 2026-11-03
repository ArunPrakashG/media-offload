import { getCollection } from 'astro:content';
import { SITE, docPath, getDocs } from '../lib/site';

export const GET = async () => {
  const docs = await getDocs();
  const pages = await getCollection('pages');
  const urls: { loc: string; lastmod?: string }[] = [
    { loc: '/' },
    ...docs.map((d) => ({ loc: docPath(d) })),
    ...pages.map((p) => ({ loc: `/${p.id}/`, lastmod: p.data.updated.toISOString().slice(0, 10) })),
  ];
  const body = `<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
${urls.map((u) => `  <url><loc>${SITE.url}${u.loc}</loc>${u.lastmod ? `<lastmod>${u.lastmod}</lastmod>` : ''}</url>`).join('\n')}
</urlset>
`;
  return new Response(body, { headers: { 'Content-Type': 'application/xml; charset=utf-8' } });
};
