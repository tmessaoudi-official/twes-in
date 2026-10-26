// SPDX-License-Identifier: AGPL-3.0-or-later
import type { TranslateService } from '@ngx-translate/core';
import type { FieldRefusal } from './field-refusals';
import type { FieldError } from './form-builder';

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

/**
 * What a form says under a field the API refused: the words of its reason when the screens have them, else the plain
 * « Valeur refusée. ». Every form placing a refusal says it through here, the descriptor forms and the hand-written ones
 * alike, so one reason reads the same on every screen. `params` are the refusal's own unless the form writes them first.
 */
export function refusedError(
  translate: TranslateService,
  refusal: FieldRefusal,
  params: FieldRefusal['params'] = refusal.params,
): FieldError {
  const key = `import.rejections.${refusal.reason ?? ''}`;
  const known = refusal.reason !== null && translate.instant(key) !== key;
  return {
    key: known ? key : 'import.rejections.invalid_value',
    params: refusalParams(translate, params),
  };
}
