/// <reference types="vitest/config" />
import { resolve } from 'path';
import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    include: ['assets/src/**/*.{test,spec}.?(c|m)[jt]s?(x)'],
    environment: 'jsdom',
    setupFiles: [resolve(import.meta.dirname, './vitest.setup.ts')],
  },
});
