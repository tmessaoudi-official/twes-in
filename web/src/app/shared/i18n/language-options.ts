// SPDX-License-Identifier: AGPL-3.0-or-later

import type { SelectOption } from '../form/select';
import { nameOf, type Language } from './languages';

const fold = (text: string): string => text.normalize('NFD').replace(/\p{M}/gu, '').toLowerCase();

/** What a language dropdown draws for one language, given the screen's. */
export type LanguageNaming = Pick<SelectOption, 'label' | 'lang' | 'hint' | 'hintLang'>;

/**
 * A language as a language dropdown names it: in itself, in its own `lang`, so whoever reads it finds it whatever the
 * screen's language, and under it its name in the screen's language, which the browser knows for every language, so
 * the catalogue never carries one name per pair. No second line where it would only repeat the first.
 */
export function languageOption(tag: Language, screen: Language): LanguageNaming {
  const label = nameOf(tag);
  const known = new Intl.DisplayNames([screen], { type: 'language', fallback: 'none' }).of(tag);
  if (known === undefined || fold(known) === fold(label)) {
    return { label, lang: tag };
  }
  return {
    label,
    lang: tag,
    hint: known.charAt(0).toLocaleUpperCase(screen) + known.slice(1),
    hintLang: screen,
  };
}

/**
 * The options of a language dropdown, `Select` with `languages`, which names each one: a value, and the test id the
 * page's tests pick it by.
 */
export function languageChoices(tags: readonly Language[], testIdPrefix?: string): SelectOption[] {
  return tags.map((tag) => ({
    value: tag,
    label: tag,
    ...(testIdPrefix === undefined ? {} : { testId: `${testIdPrefix}-${tag}` }),
  }));
}
