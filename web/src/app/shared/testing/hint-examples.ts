// SPDX-License-Identifier: AGPL-3.0-or-later

import ar from '../../../../public/i18n/ar.json';
import en from '../../../../public/i18n/en.json';
import fr from '../../../../public/i18n/fr.json';
import arPlatform from '../../../../public/i18n/platform/ar.json';
import enPlatform from '../../../../public/i18n/platform/en.json';
import frPlatform from '../../../../public/i18n/platform/fr.json';
import arRefusals from '../../../../public/i18n/settings-refusals/ar.json';
import enRefusals from '../../../../public/i18n/settings-refusals/en.json';
import frRefusals from '../../../../public/i18n/settings-refusals/fr.json';
import classes from '../form/hint-examples.json';

/** The field an example is offered for, as `hint-examples.json` names it. */
export type HintKind = 'phone' | 'iban' | 'support_phone' | 'whatsapp' | 'late_fee_tiers';

/** The texts an example may sit in, each language's files together. */
const TEXTS: Readonly<Record<string, readonly unknown[]>> = {
  fr: [fr, frPlatform, frRefusals],
  en: [en, enPlatform, enRefusals],
  ar: [ar, arPlatform, arRefusals],
};

const PHONE = /\p{Cf}*\+[0-9][0-9\p{Zs}().-]*[0-9]/gu;
/** What an example of each kind looks like in its sentence, the marks a copy brings before it included. */
const EXAMPLE: Readonly<Record<HintKind, RegExp>> = {
  phone: PHONE,
  support_phone: PHONE,
  whatsapp: PHONE,
  iban: /[A-Z]{2}[0-9]{2}(?:\p{Zs}?[A-Z0-9])+/gu,
  late_fee_tiers: /[0-9]+(?:[.,][0-9]+)?(?:\s*;\s*[0-9]+(?:[.,][0-9]+)?)+(?:\s*%)?/gu,
};

/** The pattern the API sends with a setting, which a screen drawing it is handed (`settingRules`). */
export function apiPattern(kind: 'support_phone' | 'whatsapp' | 'late_fee_tiers'): string {
  return classes.apiPatterns[kind];
}

/**
 * Every example the hints and refusals offer for a kind of field, in every language, as a person copies it. Throws when
 * a text named for it is missing from a language or offers none: the texts moved, and the specs would check nothing.
 */
export function hintExamples(kind: HintKind): string[] {
  const keys = Object.entries(classes.examples as Record<string, string>)
    .filter(([, each]) => each === kind)
    .map(([key]) => key);
  if (keys.length === 0) throw new Error(`no text offers a ${kind} example`);
  return Object.entries(TEXTS).flatMap(([language, files]) =>
    keys.flatMap((key) => {
      const text = files.map((file) => textAt(file, key)).find((each) => each !== undefined);
      if (text === undefined) throw new Error(`${language} has no ${key}`);
      const offered = [...text.matchAll(EXAMPLE[kind])].map((match) => match[0]);
      if (offered.length === 0) throw new Error(`${language} ${key} offers no ${kind} example`);
      return offered;
    }),
  );
}

function textAt(file: unknown, key: string): string | undefined {
  let node = file;
  for (const part of key.split('.')) {
    if (typeof node !== 'object' || node === null) return undefined;
    node = (node as Record<string, unknown>)[part];
  }
  return typeof node === 'string' ? node : undefined;
}
