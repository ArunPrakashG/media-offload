import { DOC_SECTIONS, docPath, getDocs } from '../../lib/site';

/** Small search index for the docs search box: title, section and plain text. */
export const GET = async () => {
  const docs = await getDocs();
  const index = docs.map((d) => ({
    title: d.data.title,
    section: DOC_SECTIONS.find((s) => s.key === d.data.section)?.label ?? '',
    url: docPath(d),
    text: `${d.data.description} ${(d.body ?? '').replace(/```[\s\S]*?```/g, ' ').replace(/[#>*_`|[\]()-]+/g, ' ').replace(/\s+/g, ' ')}`.slice(0, 4000),
  }));
  return new Response(JSON.stringify(index), { headers: { 'Content-Type': 'application/json; charset=utf-8' } });
};
