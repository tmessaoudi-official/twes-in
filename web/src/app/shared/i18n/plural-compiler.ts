// SPDX-License-Identifier: AGPL-3.0-or-later

import { Injectable } from '@angular/core';
import {
  type InterpolatableTranslation,
  type InterpolatableTranslationObject,
  type InterpolateFunction,
  type InterpolationParameters,
  TranslateCompiler,
  type TranslationObject,
} from '@ngx-translate/core';

/** Where a choice starts: `{name, plural,`. A parameter in `{{name}}` has two braces and never matches. */
const CHOICE = /\{(\w+),\s*plural,/g;
/** A parameter, as ngx-translate's default parser writes it. */
const PARAMETER = /{{\s?([^{}\s]*)\s?}}/g;

type Part = string | { readonly name: string; readonly forms: ReadonlyMap<string, Part[]> };

/**
 * Lets a screen text say a count in the form its language gives it, as an ICU plural choice:
 * `{{days}} {days, plural, one {jour restant} other {jours restants}}`. The form is the one `Intl.PluralRules` names
 * for the text's language (French says « 0 jour », English « 0 days »); « other » is taken when the count is missing
 * or its form is not written. Only `plural` is understood, which is what the screens need, and no dependency is
 * added for it. A text without a choice is left as it is, so ngx-translate interpolates it as before.
 */
@Injectable()
export class PluralCompiler extends TranslateCompiler {
  compile(value: string, lang: string): string | InterpolateFunction {
    if (!new RegExp(CHOICE.source).test(value)) return value;
    const parts = parse(value);
    const rules = new Intl.PluralRules(lang);
    return (params?: InterpolationParameters) => say(parts, rules, params ?? {});
  }

  compileTranslations(
    translations: TranslationObject,
    lang: string,
  ): InterpolatableTranslationObject {
    const compiled: InterpolatableTranslationObject = {};
    for (const [key, value] of Object.entries(translations)) compiled[key] = this.walk(value, lang);
    return compiled;
  }

  private walk(value: unknown, lang: string): InterpolatableTranslation {
    if (typeof value === 'string') return this.compile(value, lang);
    if (Array.isArray(value)) return value.map((item) => this.walk(item, lang));
    if (value !== null && typeof value === 'object') {
      return this.compileTranslations(value as TranslationObject, lang);
    }
    return value as InterpolatableTranslation;
  }
}

function say(
  parts: readonly Part[],
  rules: Intl.PluralRules,
  params: InterpolationParameters,
): string {
  return parts
    .map((part) => {
      if (typeof part === 'string') {
        return part.replace(PARAMETER, (whole, name: string) => {
          const value: unknown = params[name];
          return value === undefined || value === null ? whole : String(value);
        });
      }
      const given: unknown = params[part.name];
      const count = given === '' || given === null ? Number.NaN : Number(given);
      const form = Number.isFinite(count) ? rules.select(count) : 'other';
      const exact = Number.isFinite(count) ? part.forms.get(`=${count}`) : undefined;
      return say(exact ?? part.forms.get(form) ?? part.forms.get('other') ?? [], rules, params);
    })
    .join('');
}

/** Splits a text into its plain runs and its choices; a malformed choice is kept as text, never thrown on. */
function parse(text: string): Part[] {
  const parts: Part[] = [];
  let from = 0;
  // Its own expression: a choice nested in a form is parsed in the middle of this loop.
  const choice = new RegExp(CHOICE.source, 'g');
  for (let match = choice.exec(text); match !== null; match = choice.exec(text)) {
    const read = readForms(text, choice.lastIndex);
    if (read === null) continue;
    if (match.index > from) parts.push(text.slice(from, match.index));
    parts.push({ name: match[1] ?? '', forms: read.forms });
    from = read.end;
    choice.lastIndex = read.end;
  }
  if (from < text.length) parts.push(text.slice(from));
  return parts;
}

/** Reads `one {…} other {…}}` from `at`, up to and including the brace closing the choice. */
function readForms(text: string, at: number): { forms: Map<string, Part[]>; end: number } | null {
  const forms = new Map<string, Part[]>();
  let index = at;
  for (;;) {
    while (index < text.length && /\s/.test(text[index] ?? '')) index++;
    if (text[index] === '}') return forms.size > 0 ? { forms, end: index + 1 } : null;
    const name = /^(=\d+|zero|one|two|few|many|other)\s*\{/.exec(text.slice(index));
    if (name === null) return null;
    const open = index + name[0].length;
    let depth = 1;
    let close = open;
    for (; close < text.length && depth > 0; close++) {
      if (text[close] === '{') depth++;
      else if (text[close] === '}') depth--;
    }
    if (depth !== 0) return null;
    forms.set(name[1] ?? 'other', parse(text.slice(open, close - 1)));
    index = close;
  }
}
