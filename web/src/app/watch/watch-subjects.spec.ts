// SPDX-License-Identifier: AGPL-3.0-or-later

import ar from '../../../public/i18n/watch/ar.json';
import en from '../../../public/i18n/watch/en.json';
import fr from '../../../public/i18n/watch/fr.json';
import { hintKey, WATCH_SUBJECTS } from './watch-subjects';

/** The text at a dotted key of a texts file, or undefined. */
function at(texts: unknown, key: string): unknown {
  return key
    .split('.')
    .reduce<unknown>(
      (node, part) =>
        node !== null && typeof node === 'object'
          ? (node as Record<string, unknown>)[part]
          : undefined,
      texts,
    );
}

describe('hintKey', () => {
  // The key is built from the subject's kind, so no gate reading the templates finds it: each one a reader can be
  // shown is checked here, in every language the screens speak.
  it('names a text that exists, for a reader who may do every action and one who may do none', () => {
    const missing: string[] = [];
    for (const kind of Object.keys(WATCH_SUBJECTS)) {
      for (const may of [() => true, () => false]) {
        const key = hintKey(kind, may);
        for (const [language, texts] of [
          ['fr', fr],
          ['en', en],
          ['ar', ar],
        ] as const) {
          if (typeof at(texts, key) !== 'string') missing.push(`${language} ${key}`);
        }
      }
    }
    expect(missing).toEqual([]);
  });

  it('tells a reader who may not count who does, and one who may to count', () => {
    expect(hintKey('stock.below_zero', () => true)).toBe('watch.subjects.stock.below_zero.hint');
    expect(hintKey('stock.below_zero', (permission) => permission !== 'stock.write')).toBe(
      'watch.subjects.stock.below_zero.hint_reader',
    );
    // A subject asking nothing of its reader has one hint for everyone.
    expect(hintKey('stock.reorder_point', () => false)).toBe(
      'watch.subjects.stock.reorder_point.hint',
    );
  });
});
