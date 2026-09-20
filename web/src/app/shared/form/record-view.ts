// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import { TranslatePipe } from '@ngx-translate/core';
import { AmountPipe, DayPipe } from '../i18n/format-pipes';
import { fieldApplies } from './form-visibility';
import type { FieldKind, FormDescriptor, FormValues } from './form-types';

interface ShownField {
  id: string;
  label: string;
  /** How to read it: the kind decides, and the pipes already tested do the reading. */
  kind: FieldKind;
  value: string;
  span: 1 | 2;
}

interface ShownSection {
  id: string;
  title: string;
  fields: ShownField[];
}

/**
 * A locked document read rather than filled in (docs/SPEC.md § 7, 2026-09-19 23:17, design review finding 3). An
 * issued invoice was a form with every control disabled, which reads as "you may not change this" where what is
 * true is "this no longer changes" — and it kept a row of empty boxes for every field nobody filled in. This shows
 * what the document says and leaves out what it does not say.
 */
@Component({
  selector: 'app-record-view',
  imports: [TranslatePipe, AmountPipe, DayPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="flex flex-col gap-6" [attr.data-testid]="testId()">
      @for (section of sections(); track section.id) {
        <div class="flex flex-col gap-2">
          <h2 class="text-lg font-semibold">{{ section.title | translate }}</h2>
          <dl class="grid gap-x-6 gap-y-2 sm:grid-cols-2">
            @for (field of section.fields; track field.id) {
              <div [class]="field.span === 2 ? 'sm:col-span-2' : ''">
                <dt
                  class="text-sm text-on-surface-variant"
                  [attr.data-testid]="'view-label-' + field.id"
                >
                  {{ field.label | translate }}
                </dt>
                <dd class="whitespace-pre-line break-words" [attr.data-testid]="'view-' + field.id">
                  @switch (field.kind) {
                    @case ('date') {
                      {{ field.value | day }}
                    }
                    @case ('decimal') {
                      {{ field.value | amount: null }}
                    }
                    @default {
                      {{ field.value | translate }}
                    }
                  }
                </dd>
              </div>
            }
          </dl>
        </div>
      } @empty {
        <p class="text-on-surface-variant" [attr.data-testid]="testId() + '-empty'">
          {{ emptyKey() | translate }}
        </p>
      }
    </div>
  `,
})
export class RecordView {
  readonly descriptor = input.required<FormDescriptor>();
  readonly values = input.required<FormValues>();
  readonly testId = input('record-view');
  /** What to say when the document filled none of these fields in; a translation key. */
  readonly emptyKey = input('form.nothing_filled');
  /** How a `pick` field's chosen record reads, which only the screen knows: a customer's name, not its id. */
  readonly picked = input<Record<string, string>>({});

  /** Only what the document says: a section whose every field is empty is left out with them. */
  protected readonly sections = computed<ShownSection[]>(() => {
    const values = this.values();
    return this.descriptor()
      .sections.map((section) => ({
        id: section.id,
        title: section.title,
        fields: section.fields
          .filter((field) => fieldApplies(field, values))
          .map((field) => ({
            id: field.id,
            label: field.label,
            kind: field.kind,
            value: this.shown(field.id, field.kind, values[field.id], field.options),
            span: field.span ?? 1,
          }))
          .filter((field) => field.value !== ''),
      }))
      .filter((section) => section.fields.length > 0);
  });

  /**
   * The text to read, or '' for what the document does not say. A translation key is returned where the value is
   * one (a choice's label, a tick's word), because an unknown key resolves to itself — which is also how a custom
   * field's own configured label renders.
   */
  private shown(
    id: string,
    kind: FieldKind,
    value: unknown,
    options?: readonly { value: string; label: string }[],
  ): string {
    // A box left unticked is an answer, not a blank — but a field this document has no value for at all is.
    if (kind === 'checkbox' && typeof value === 'boolean') return value ? 'form.yes' : 'form.no';
    if (value === null || value === undefined || value === '') return '';
    if (kind === 'pick') return this.picked()[id] ?? String(value);
    if (kind === 'select') {
      return options?.find((option) => option.value === String(value))?.label ?? String(value);
    }
    return String(value);
  }
}
