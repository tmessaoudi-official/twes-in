// SPDX-License-Identifier: AGPL-3.0-or-later

import { TranslateDefaultParser } from '@ngx-translate/core';
import { PluralCompiler } from './plural-compiler';

describe('PluralCompiler', () => {
  const compiler = new PluralCompiler();
  const parser = new TranslateDefaultParser();
  const say = (text: string, lang: string, params: Record<string, unknown>): string | undefined =>
    parser.interpolate(compiler.compile(text, lang), params);

  const days = '{{days}} {days, plural, one {jour restant} other {jours restants}}';

  it('says a count in the form its language gives it', () => {
    expect(say(days, 'fr', { days: 1 })).toBe('1 jour restant');
    expect(say(days, 'fr', { days: 3 })).toBe('3 jours restants');
    // French says zero in the singular, English in the plural.
    expect(say(days, 'fr', { days: 0 })).toBe('0 jour restant');
    const en = '{{count}} {count, plural, one {day} other {days}} left';
    expect(say(en, 'en', { count: 0 })).toBe('0 days left');
    expect(say(en, 'en', { count: 1 })).toBe('1 day left');
  });

  it('reads a count that came as a decimal string, and takes « other » when there is none', () => {
    expect(say(days, 'fr', { days: '1' })).toBe('1 jour restant');
    expect(say(days, 'fr', { days: '2.500' })).toBe('2.500 jours restants');
    expect(say(days, 'fr', {})).toBe('{{days}} jours restants');
  });

  it('keeps the other parameters, and several choices in one sentence', () => {
    const text =
      '{{name}} : {count, plural, one {{{count}} ligne enregistrée} other {{{count}} lignes enregistrées}}, {rows, plural, one {une reprise} other {{{rows}} reprises}}';
    expect(say(text, 'fr', { name: 'Comptage', count: 2, rows: 1 })).toBe(
      'Comptage : 2 lignes enregistrées, une reprise',
    );
  });

  it('leaves a text without a choice as it is, and compiles every text of a file', () => {
    expect(compiler.compile('{{count}} Mo', 'fr')).toBe('{{count}} Mo');
    const compiled = compiler.compileTranslations(
      { a: { b: days, c: 'Plain' }, d: ['x'] },
      'fr',
    ) as { a: { b: unknown; c: unknown }; d: unknown };
    expect(typeof compiled.a.b).toBe('function');
    expect(compiled.a.c).toBe('Plain');
    expect(compiled.d).toEqual(['x']);
  });
});
