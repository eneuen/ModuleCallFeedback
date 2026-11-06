// Builds public/assets/js/vendor/echarts.min.js (tree-shaken ECharts, with its
// LICENSE and NOTICE) and public/assets/js/call-feedback.js (the module page).
// Run: npm ci && npm run build
import { build } from 'esbuild';
import { copyFileSync, readFileSync, statSync } from 'node:fs';
import { gzipSync } from 'node:zlib';

const vendor = 'public/assets/js/vendor';
const page = 'public/assets/js/call-feedback.js';

await build({
  entryPoints: ['build/echarts-entry.js'],
  outfile: `${vendor}/echarts.min.js`,
  bundle: true,
  minify: true,
  format: 'iife',
  target: ['es2019'],
  legalComments: 'none',          // the full licence texts ship next to the bundle
  banner: { js: '/*! Apache ECharts 6.1.0 | Apache-2.0 | see LICENSE and NOTICE in this folder */' },
});
copyFileSync('node_modules/echarts/LICENSE', `${vendor}/LICENSE`);
copyFileSync('node_modules/echarts/NOTICE', `${vendor}/NOTICE`);

await build({
  entryPoints: ['public/assets/js/src/call-feedback.js'],
  outfile: page,
  bundle: true,
  minify: false,                  // kept readable; it is small
  format: 'iife',
  target: ['es2019'],
  banner: { js: '/* Call Feedback for MikoPBX | GPL-3.0-or-later | built from public/assets/js/src */' },
});

for (const file of [`${vendor}/echarts.min.js`, page]) {
  const bytes = statSync(file).size;
  const gz = gzipSync(readFileSync(file)).length;
  console.log(`${file}: ${(bytes / 1024).toFixed(0)} KiB, ${(gz / 1024).toFixed(0)} KiB gzip`);
}
