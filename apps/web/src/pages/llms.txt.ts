import { getCollection } from 'astro:content';
import { txt } from '../lib/markdown';
import { DOC_SECTIONS, SITE, getDocs } from '../lib/site';

export const GET = async () => {
  const docs = await getDocs();
  const pages = await getCollection('pages');
  const mdUrl = (id: string) => (id === 'index' ? `${SITE.url}/docs.md` : `${SITE.url}/docs/${id}.md`);
  const sections = DOC_SECTIONS.map((s) => {
    const items = docs.filter((d) => d.data.section === s.key).map((d) => `- [${d.data.title}](${mdUrl(d.id)}): ${d.data.description}`);
    return `## ${s.label}\n\n${items.join('\n')}`;
  });
  const body = `# ${SITE.name}

> ${SITE.description}

Nimbus is MIT licensed and has no paid tier, license server or telemetry. It needs WordPress 6.0+ and PHP 8.1+. Every page on this site is also available as Markdown by adding \`.md\` to its path. The full site in one file is at ${SITE.url}/llms-full.txt.

- [Home](${SITE.url}/index.md): what Nimbus does, supported storage and features
- [Source code](${SITE.repo})

${sections.join('\n\n')}

## Optional

${pages.map((p) => `- [${p.data.title}](${SITE.url}/${p.id}.md): ${p.data.description}`).join('\n')}
`;
  return txt(body);
};
