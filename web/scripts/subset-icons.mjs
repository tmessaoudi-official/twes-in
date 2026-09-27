// SPDX-License-Identifier: AGPL-3.0-or-later
// Cuts the Material Symbols Outlined font to the icons the application declares (src/app/shared/icons/icons.ts):
// the whole set is 3.98 MB, which a first visit on a slow phone waits about 20 s for with its icons shown as words.
// Run before `build` and `start` (package.json); the result is generated, gitignored, and hashed into media/ by the
// build like any other stylesheet asset.
//
// Each icon is a ligature: the font turns the letters of its name into its glyph. So the subset keeps the letters the
// names are written with and each name's glyph, found by shaping the name with the full font, and it skips the layout
// closure, which would otherwise bring back every ligature those letters can form, that is every icon. Variation axes
// are kept: styles.scss sets them.
import { readFile, stat, writeFile, mkdir } from 'node:fs/promises';
import { dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import * as hb from 'harfbuzzjs';
import wawoff2 from 'wawoff2';

const web = fileURLToPath(new URL('..', import.meta.url));
const REGISTRY = `${web}src/app/shared/icons/icons.ts`;
const FONT = `${web}node_modules/material-symbols/material-symbols-outlined.woff2`;
const OUTPUT = `${web}src/generated/material-symbols-outlined.woff2`;
const SUBSET_WASM = `${web}node_modules/harfbuzzjs/dist/harfbuzz-subset.wasm`;
// hb-subset.h: HB_SUBSET_FLAGS_NO_LAYOUT_CLOSURE.
const NO_LAYOUT_CLOSURE = 0x200;

const fail = (message) => {
  console.error(`subset-icons: ${message}`);
  process.exit(1);
};

const registry = await readFile(REGISTRY, 'utf8');
const list = /export const ICONS = \[([\s\S]*?)\] as const;/.exec(registry);
if (list === null) fail(`no "export const ICONS = [...] as const;" in ${REGISTRY}`);
const names = [...list[1].matchAll(/'([a-z0-9_]+)'/g)].map((match) => match[1]);
if (names.length === 0) fail(`ICONS in ${REGISTRY} lists no icon`);

const mtime = async (path) => (await stat(path).catch(() => null))?.mtimeMs ?? 0;
const inputs = Math.max(
  await mtime(REGISTRY),
  await mtime(FONT),
  await mtime(fileURLToPath(import.meta.url)),
);
if ((await mtime(OUTPUT)) > inputs) process.exit(0);

const full = await wawoff2.decompress(await readFile(FONT));
/** The one glyph the font draws for a name, or null when the name is not one of its icons. */
const glyphOf = (font, name) => {
  const face = new hb.Face(new hb.Blob(font), 0);
  const buffer = new hb.Buffer();
  buffer.addText(name);
  buffer.guessSegmentProperties();
  hb.shape(new hb.Font(face), buffer);
  const glyphs = buffer.getGlyphInfos();
  return glyphs.length === 1 && glyphs[0].codepoint !== 0 ? glyphs[0].codepoint : null;
};
const glyphs = new Map(names.map((name) => [name, glyphOf(full, name)]));
const unknown = names.filter((name) => glyphs.get(name) === null);
if (unknown.length > 0)
  fail(`not a Material Symbols icon, so it would show as letters: ${unknown.join(', ')}`);

const { instance } = await WebAssembly.instantiate(await readFile(SUBSET_WASM));
const hbs = instance.exports;
const at = hbs.malloc(full.byteLength);
new Uint8Array(hbs.memory.buffer).set(full, at);
const blob = hbs.hb_blob_create(at, full.byteLength, 2 /* HB_MEMORY_MODE_WRITABLE */, 0, 0);
const face = hbs.hb_face_create(blob, 0);
hbs.hb_blob_destroy(blob);
const input = hbs.hb_subset_input_create_or_fail();
const letters = hbs.hb_subset_input_unicode_set(input);
for (const letter of new Set(names.join(''))) hbs.hb_set_add(letters, letter.codePointAt(0));
const kept = hbs.hb_subset_input_glyph_set(input);
for (const glyph of glyphs.values()) hbs.hb_set_add(kept, glyph);
hbs.hb_subset_input_set_flags(input, hbs.hb_subset_input_get_flags(input) | NO_LAYOUT_CLOSURE);
const subset = hbs.hb_subset_or_fail(face, input);
if (subset === 0) fail('harfbuzz could not subset the font');
const result = hbs.hb_face_reference_blob(subset);
const data = hbs.hb_blob_get_data(result, 0);
const cut = new Uint8Array(hbs.memory.buffer).slice(data, data + hbs.hb_blob_get_length(result));
hbs.hb_blob_destroy(result);
hbs.hb_face_destroy(subset);
hbs.hb_subset_input_destroy(input);
hbs.hb_face_destroy(face);
hbs.free(at);

// The cut font must still draw every declared icon as one glyph.
const lost = names.filter((name) => glyphOf(cut, name) === null);
if (lost.length > 0) fail(`the cut font no longer draws: ${lost.join(', ')}`);

const woff2 = await wawoff2.compress(cut);
await mkdir(dirname(OUTPUT), { recursive: true });
await writeFile(OUTPUT, woff2);
console.log(
  `subset-icons: ${names.length} icons, ${woff2.byteLength} bytes (the whole set: ${(await stat(FONT)).size})`,
);
