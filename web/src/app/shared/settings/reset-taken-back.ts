// SPDX-License-Identifier: AGPL-3.0-or-later

import type { TranslateService } from '@ngx-translate/core';
import type { Feedback } from '../feedback/feedback';
import { heldAt, versioned, versionsAt } from './setting-forms';
import type { SettingLevel, SettingRow, VersionedSettingChange } from './settings-types';

/** What a screen hands over to reset one key of its level and offer the value back. */
export interface ResetAtLevel {
  readonly key: string;
  readonly level: SettingLevel;
  /** The rows as the screen holds them now: read before the reset for the value, after it for the version. */
  readonly rows: () => readonly SettingRow[];
  readonly reset: () => Promise<boolean>;
  readonly save: (changes: readonly VersionedSettingChange[]) => Promise<boolean>;
  /** What the screen does after either write, such as showing the chain as the API now holds it. */
  readonly written: () => void;
}

/**
 * A reset gives up the level's own value, so it is never said as « enregistré »: the toast names the setting and offers
 * the value back, saved again on the version the reset left, and nothing asks first (undo first). A level holding no
 * value of its own has nothing to give back, so no « Annuler » is offered.
 */
export async function resetTakenBack(
  feedback: Feedback,
  translate: TranslateService,
  at: ResetAtLevel,
): Promise<void> {
  const rows = at.rows();
  const held = heldAt(rows, at.key, at.level);
  const labelKey = rows.find((row) => row.key === at.key)?.labelKey;
  const label: string = labelKey === undefined ? at.key : translate.instant(labelKey);
  if (!(await at.reset())) return;
  at.written();

  const putBack = async (value: VersionedSettingChange['value']): Promise<void> => {
    const changes = versioned([{ key: at.key, value }], versionsAt(at.rows(), at.level));
    if (await at.save(changes)) {
      at.written();
      feedback.success('settings.overrides.put_back', { label });
    }
  };
  feedback.success(
    'settings.overrides.reset_done',
    { label },
    held === null
      ? undefined
      : { key: 'settings.overrides.undo', run: () => void putBack(held.value) },
  );
}
