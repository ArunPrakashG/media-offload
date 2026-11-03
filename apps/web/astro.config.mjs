import node from '@astrojs/node';
import { defineConfig, fontProviders } from 'astro/config';

export default defineConfig({
  site: 'https://usenimbus.dev',
  trailingSlash: 'always',
  compressHTML: true,
  adapter: node({ mode: 'standalone' }),
  redirects: {
    '/refund': '/terms/',
  },
  build: {
    inlineStylesheets: 'never',
  },
  fonts: [
    {
      provider: fontProviders.fontsource(),
      name: 'Geist',
      cssVariable: '--font-geist',
      weights: ['400', '500', '600'],
      styles: ['normal'],
      subsets: ['latin'],
      fallbacks: ['ui-sans-serif', 'system-ui', 'sans-serif'],
    },
    {
      provider: fontProviders.fontsource(),
      name: 'Geist Mono',
      cssVariable: '--font-geist-mono',
      weights: ['400', '500'],
      styles: ['normal'],
      subsets: ['latin'],
      fallbacks: ['ui-monospace', 'monospace'],
    },
  ],
  security: {
    csp: {
      directives: [
        "default-src 'self'",
        "img-src 'self' data:",
        "font-src 'self'",
        "connect-src 'self'",
        "manifest-src 'self'",
      ],
    },
  },
  // Prism emits classes, not inline styles, so code blocks pass the CSP.
  markdown: {
    syntaxHighlight: 'prism',
  },
});
