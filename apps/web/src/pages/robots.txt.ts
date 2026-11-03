import { txt } from '../lib/markdown';
import { SITE } from '../lib/site';

export const GET = () =>
  txt(`User-agent: *
Content-Signal: search=yes, ai-input=yes, ai-train=no
Allow: /

User-agent: OAI-SearchBot
User-agent: ChatGPT-User
User-agent: Claude-SearchBot
User-agent: Claude-User
User-agent: PerplexityBot
User-agent: Perplexity-User
Allow: /

Sitemap: ${SITE.url}/sitemap.xml
`);
