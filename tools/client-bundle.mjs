/** Node 18+; extract the shipped module for editing, or syntax-check and repack it. */
import { readFile, writeFile, mkdir } from 'node:fs/promises';
import { gzipSync, gunzipSync } from 'node:zlib';
import { spawnSync } from 'node:child_process';
const bundle = new URL('../nsc-accounting-r3.js.gz', import.meta.url);
const source = new URL('../client/nsc-accounting-r3.js', import.meta.url);
const mode = process.argv[2];
if (mode === 'extract') {
  await mkdir(new URL('../client/', import.meta.url), { recursive: true });
  await writeFile(source, gunzipSync(await readFile(bundle)));
  console.log('Extracted current browser module to client/nsc-accounting-r3.js');
} else if (mode === 'pack') {
  const code = await readFile(source);
  const checked = spawnSync(process.execPath, ['--check', '--input-type=module'], { input: code, encoding: 'utf8' });
  if (checked.status !== 0) { process.stderr.write(checked.stderr || 'JavaScript syntax check failed.\n'); process.exit(1); }
  await writeFile(bundle, gzipSync(code, { level: 9, mtime: 0 }));
  console.log('Syntax checked and packed nsc-accounting-r3.js.gz; bump its URL in workspace.php before release.');
} else {
  console.error('Usage: node tools/client-bundle.mjs extract|pack');
  process.exit(1);
}
