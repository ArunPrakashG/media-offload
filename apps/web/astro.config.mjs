import node from '@astrojs/node';
import { defineConfig } from 'astro/config';

export default defineConfig({
  site: 'https://usenimbus.dev',
  compressHTML: true,
  output: 'hybrid',
  adapter: node({ mode: 'standalone' }),
});
