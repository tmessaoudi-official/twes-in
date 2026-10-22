// SPDX-License-Identifier: AGPL-3.0-or-later
import type { TranslateService } from '@ngx-translate/core';

/** The refusal params the API sends as codes, and where the screens' words for each code are. */
const WORDED_PARAMS = [
  ['part', 'import.identifier_parts'],
  ['rule', 'import.identifier_rules'],
] as const;

/**
 * A refusal's params with the codes it names put in the reader's words: the part of a registration number that does
 * not fit and the rule between two parts (`IdentifierParts` on the API). A code with no words yet stays as it came,
 * which reads poorly but never blank. Every screen saying a refusal goes through it, the forms and the import alike.
 */
export function refusalParams<P extends Readonly<Record<string, unknown>>>(
  translate: TranslateService,
  params: P,
): P {
  const worded: Record<string, unknown> = { ...params };
  for (const [param, section] of WORDED_PARAMS) {
    const code = params[param];
    if (typeof code !== 'string') continue;
    const key = `${section}.${code}`;
    const said = translate.instant(key) as string;
    if (said !== key) worded[param] = said;
  }

  return worded as P;
}
