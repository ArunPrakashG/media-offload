import { txt } from '../../lib/markdown';
import { SITE } from '../../lib/site';

// Expires one year after each build, so a deploy keeps it current.
export const GET = () => {
  const expires = new Date(Date.now() + 365 * 24 * 60 * 60 * 1000).toISOString().replace(/\.\d+Z$/, 'Z');
  return txt(`Contact: mailto:${SITE.author.email}
Expires: ${expires}
Preferred-Languages: en
Canonical: ${SITE.url}/.well-known/security.txt
Policy: ${SITE.repo}/blob/main/SECURITY.md
`);
};
