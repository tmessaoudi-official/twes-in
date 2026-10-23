// SPDX-License-Identifier: AGPL-3.0-or-later

import { type FieldRefusal, refusalsIn } from '../form/field-refusals';
import { fieldIdOf } from './setting-forms';

/**
 * The texts a settings refusal is said in beyond the screens' own file (`import.rejections.*` the settings engine
 * alone answers), opened into the root before a refusal is shown or a field with a pattern is drawn.
 */
export const SETTING_REFUSAL_TEXTS = 'settings-refusals';

/**
 * The refusals a settings save answered, each on the form field of its setting: the API names a setting by its key, a
 * list's entry as `key[index]`, both said on the one field the form shows for the setting. Loaded only when a refusal
 * comes back, so the parsing stays off the first page every screen waits for.
 */
export function settingRefusalsIn(body: unknown): readonly FieldRefusal[] {
  return refusalsIn(body).map((refusal) => ({
    ...refusal,
    field: fieldIdOf(refusal.field.replace(/(?:\[\d+\]|\.\d+)$/, '')),
  }));
}
