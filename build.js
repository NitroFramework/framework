// Build the framework's browser runtimes: edit the readable sources under js/,
// then `npm run build` to bundle + minify them into the dist/ files that ship
// with the composer package and are served from /nitro/*.js. Never hand-edit
// the dist/ outputs — they are generated.
import { build } from 'esbuild';

const targets = [
  { in: 'js/livewire.js',        out: 'src/Livewire/dist/livewire.js' },
  { in: 'js/hx-component.js',    out: 'src/Htmx/dist/hx-component.js' },
  { in: 'js/nitro-nprogress.js', out: 'src/Htmx/dist/nitro-nprogress.js' },
];

await Promise.all(targets.map((t) =>
  build({
    entryPoints: [t.in],
    outfile: t.out,
    bundle: true,
    minify: true,
    format: 'iife',
    target: ['es2017'],
    legalComments: 'none',
  })
));

console.log(`Built ${targets.length} runtime bundle(s).`);
