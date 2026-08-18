import { copyFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { defineConfig } from 'vite';

export default defineConfig({
  build: {
    outDir: 'src/Resources/public',
    emptyOutDir: false,
    lib: {
      entry: resolve(__dirname, 'src/Resources/assets/src/blog-kit.ts'),
      name: 'NowoBlogKit',
      formats: ['iife'],
      fileName: () => 'blog-kit.js',
    },
    minify: true,
    sourcemap: false,
  },
  plugins: [
    {
      name: 'copy-legacy-infinite-filename',
      closeBundle() {
        copyFileSync(
          resolve(__dirname, 'src/Resources/public/blog-kit.js'),
          resolve(__dirname, 'src/Resources/public/blog-infinite-controller.js'),
        );
      },
    },
  ],
});
