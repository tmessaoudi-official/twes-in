// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, inject, input } from '@angular/core';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';
import type { FieldRefusal } from '../form/field-refusals';
import { refusalParams } from '../form/refusal-words';
import { fieldIdOf } from './setting-forms';
import type { SettingRow } from './settings-types';

/** One refused setting said in words: its label, then the rule and the example its refusal is told with. */
interface RefusalLine {
  readonly field: string;
  readonly label: string;
  readonly reason: string;
  readonly params: Readonly<Record<string, unknown>>;
}

/**
 * The settings a save refused that no field on the screen can carry, each named by its label beside what was wrong
 * with it, under the page's « Corrigez les champs marqués. »: a screen without a field for a setting still says which
 * one was refused and why, never only « refusé ».
 */
@Component({
  selector: 'app-setting-refusal-lines',
  imports: [TranslatePipe],
  template: `
    @if (lines().length > 0) {
      <ul class="flex flex-col gap-1" [attr.data-testid]="testId()">
        @for (line of lines(); track line.field) {
          <li [attr.data-testid]="testId() + '-' + line.field">
            {{
              'settings.refused_line'
                | translate
                  : {
                      label: (line.label | translate),
                      reason: (line.reason | translate: line.params),
                    }
            }}
          </li>
        }
      </ul>
    }
  `,
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SettingRefusalLines {
  private readonly translate = inject(TranslateService);

  readonly rows = input.required<readonly SettingRow[]>();
  readonly refusals = input.required<readonly FieldRefusal[]>();
  readonly testId = input('setting-refusals');

  protected readonly lines = computed<RefusalLine[]>(() => {
    const labels = new Map(this.rows().map((row) => [fieldIdOf(row.key), row.labelKey]));
    return this.refusals().map((refusal) => {
      const key = `import.rejections.${refusal.reason ?? ''}`;
      const known = refusal.reason !== null && this.translate.instant(key) !== key;
      return {
        field: refusal.field,
        label: labels.get(refusal.field) ?? refusal.field,
        reason: known ? key : 'import.rejections.invalid_value',
        params: refusalParams(this.translate, refusal.params),
      };
    });
  });
}
