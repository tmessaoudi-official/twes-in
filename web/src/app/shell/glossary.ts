// SPDX-License-Identifier: AGPL-3.0-or-later

/** A word the screens use, said in plain words; one country's word is shown to that country's companies only. */
export interface GlossaryEntry {
  readonly key: string;
  /** Translation keys. */
  readonly termKey: string;
  readonly definitionKey: string;
  /** The country whose law the word belongs to; without one it is everyone's. */
  readonly country?: string;
}

function entry(key: string, country?: string): GlossaryEntry {
  return {
    key,
    termKey: `glossary.${key}.term`,
    definitionKey: `glossary.${key}.definition`,
    ...(country === undefined ? {} : { country }),
  };
}

/** The help panel's glossary: the documents first, then each country's own words. */
export const GLOSSARY: readonly GlossaryEntry[] = [
  entry('invoice'),
  entry('credit_note'),
  entry('quote'),
  entry('delivery_note'),
  entry('stamp_duty', 'TN'),
  entry('withholding', 'TN'),
  entry('vat_franchise', 'FR'),
  entry('reverse_charge', 'FR'),
];

/** What a company of that country reads in the glossary. */
export function glossaryFor(country: string | null | undefined): readonly GlossaryEntry[] {
  return GLOSSARY.filter((each) => each.country === undefined || each.country === country);
}
