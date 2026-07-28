import node from '@astrojs/node';
import { defineConfig } from 'astro/config';

export default defineConfig({
  site: 'https://usenimbus.dev',
  compressHTML: true,
  adapter: node({ mode: 'standalone' }),
});
