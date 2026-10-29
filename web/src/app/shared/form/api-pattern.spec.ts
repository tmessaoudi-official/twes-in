// SPDX-License-Identifier: AGPL-3.0-or-later

import { FormControl, Validators } from '@angular/forms';
import { describe, expect, it } from 'vitest';
import { settingRules } from '../settings/setting-forms';
import { browserPattern } from './api-pattern';
import vectors from './api-patterns.json';

/** What a screen checks a value against for a pattern the API sent, read as the screens read it. */
function screenPattern(pattern: string, delimited: boolean): string {
  if (!delimited) return pattern;
  const rules = settingRules({ pattern, patternReason: null, maxLength: null });
  if (rules.pattern === undefined) throw new Error(`no pattern read from ${pattern}`);
  return rules.pattern;
}

function takes(pattern: string, value: string): boolean {
  return new FormControl(value, Validators.pattern(pattern)).valid;
}

describe('browserPattern', () => {
  it('reads PHP’s delimiters and anchors, the form anchoring the pattern itself', () => {
    expect(browserPattern('/^[A-Z]{3}$/')).toBe('[A-Z]{3}');
    expect(browserPattern('/^(x)?$/D')).toBe('(x)?');
    expect(browserPattern('/^(x)?$/uD')).toBe('(x)?');
    expect(browserPattern('#^(https://[^\\s/?\\#]+)?$#')).toBe('(https://[^\\s/?\\#]+)?');
    expect(browserPattern('{^[0-9]+$}')).toBe('[0-9]+');
  });

  it('keeps a dollar sign that is escaped', () => {
    expect(browserPattern('/^[0-9]+\\$$/')).toBe('[0-9]+\\$');
    expect(browserPattern('/^[0-9]+\\$/')).toBe('[0-9]+\\$');
    expect(browserPattern('/^[0-9]+\\\\$/')).toBe('[0-9]+\\\\');
  });

  it.each(['/^a$/i', '/^a$/x', '/^a$/m', '/^a$/s', '/^a$/uDi'])(
    'refuses %s, whose flag a browser’s pattern cannot honour',
    (pattern) => {
      expect(() => browserPattern(pattern)).toThrow(/cannot honour/);
    },
  );

  it.each(['^a$', 'a', '/^a$', ''])('refuses %s, which has no delimiter it can read', (pattern) => {
    expect(() => browserPattern(pattern)).toThrow(/delimiter/);
  });
});

describe('every pattern the API sends a screen', () => {
  // ApiPatternsTest holds this file to every pattern the API sends, and the API to every value in it.
  it.each(Object.entries(vectors))('%s reads on screen as the API reads it', (pattern, vector) => {
    const read = screenPattern(pattern, vector.delimited);
    expect(vector.valid.filter((value) => !takes(read, value))).toEqual([]);
    expect(vector.invalid.filter((value) => takes(read, value))).toEqual([]);
  });

  it('runs at least the patterns the API sent when it was written', () => {
    expect(Object.keys(vectors).length).toBeGreaterThanOrEqual(14);
  });
});
