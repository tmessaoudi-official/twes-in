// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, input, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { TranslatePipe } from '@ngx-translate/core';
import { DayCalendarButton } from '../form/day-calendar-button';
import { DayInput } from '../form/day-input';
import { DecimalInput } from '../form/decimal-input';
import { PickField, type PickOption } from '../form/pick-field';
import { filterValues, joinValues, rangeKey, validRangeValue } from './list-filters';
import type {
  ListFilterValues,
  ListPickFilter,
  ListPickSource,
  ListRangeFilter,
} from './list-types';

/**
 * The « Filtres » panel of a list (docs/SPEC.md § 7, 2026-10-06): the pickers and the intervals that do not fit among
 * the quick facets. It only says what changed, as keys to patch in the list's query: the list owns the state, so the
 * chips, the address and the saved views all read the one place.
 */
@Component({
  selector: 'app-list-filter-panel',
  imports: [
    DayCalendarButton,
    DayInput,
    DecimalInput,
    FormsModule,
    MatButtonModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    PickField,
    TranslatePipe,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div
      class="twes-list-filter-panel flex flex-wrap items-start gap-4"
      role="group"
      [attr.aria-label]="'list.filters' | translate"
      [attr.data-testid]="testId()"
    >
      @for (pick of picks(); track pick.id) {
        @if (sources()[pick.id]; as source) {
          @for (round of [rounds()[pick.id] ?? 0]; track round) {
            <app-pick-field
              class="w-full sm:w-72"
              [label]="pick.label | translate"
              [testId]="testId() + '-' + pick.id"
              [search]="source.search"
              [value]="null"
              [hint]="'list.pick_hint' | translate"
              [noneFoundLabel]="'form.none_found' | translate"
              (picked)="add(pick, $event)"
            />
          }
        }
      }
      @for (range of ranges(); track range.id) {
        <fieldset class="flex flex-wrap items-start gap-2 border-0 p-0 m-0">
          <legend class="text-sm text-on-surface-variant pb-1">
            {{ range.label | translate }}
          </legend>
          @for (end of ends(range); track end) {
            <!-- One field per kind: a suffix inside an @if beside the input is not projected, and the calendar
                 button fell under the label instead of closing the field. -->
            @if (range.kind === 'day') {
              <mat-form-field class="w-40" subscriptSizing="dynamic">
                <mat-label>{{ 'list.range.' + end | translate }}</mat-label>
                <input
                  matInput
                  appDay
                  #dayField="appDay"
                  type="text"
                  inputmode="numeric"
                  autocomplete="off"
                  [ngModel]="chosen()[key(range, end)] ?? ''"
                  (ngModelChange)="onRange(range, end, $event)"
                  [attr.data-testid]="testId() + '-' + range.id + '-' + end"
                />
                <app-day-calendar-button matSuffix [field]="dayField" />
              </mat-form-field>
            } @else {
              <mat-form-field class="w-40" subscriptSizing="dynamic">
                <mat-label>{{ 'list.range.' + end | translate }}</mat-label>
                <input
                  matInput
                  appDecimal
                  autocomplete="off"
                  [ngModel]="chosen()[key(range, end)] ?? ''"
                  (ngModelChange)="onRange(range, end, $event)"
                  [attr.data-testid]="testId() + '-' + range.id + '-' + end"
                />
              </mat-form-field>
            }
          }
        </fieldset>
      }
    </div>
  `,
})
export class ListFilterPanel {
  readonly ranges = input<readonly ListRangeFilter[]>([]);
  readonly picks = input<readonly ListPickFilter[]>([]);
  readonly sources = input<Readonly<Record<string, ListPickSource>>>({});
  readonly chosen = input.required<ListFilterValues>();
  readonly testId = input('list-filter-panel');
  /** Keys to set, or null to clear, in the list's query. */
  readonly patch = output<Readonly<Record<string, string | null>>>();
  /** A record just picked, so the list can name it on its chip without asking again. */
  readonly named = output<PickOption>();

  /** Each picker starts again from an empty box once it has taken a record: its round is what tells it so. */
  protected readonly rounds = signal<Readonly<Record<string, number>>>({});

  protected ends(range: ListRangeFilter): readonly ('from' | 'to' | 'min' | 'max')[] {
    return range.kind === 'day' ? ['from', 'to'] : ['min', 'max'];
  }

  protected key(range: ListRangeFilter, end: 'from' | 'to' | 'min' | 'max'): string {
    return rangeKey(range.id, end);
  }

  /** What was typed is kept only once it is a day or an amount; until then the field shows it and the list ignores it. */
  protected onRange(
    range: ListRangeFilter,
    end: 'from' | 'to' | 'min' | 'max',
    value: unknown,
  ): void {
    const typed = typeof value === 'string' ? value.trim() : '';
    if (typed === '') this.patch.emit({ [this.key(range, end)]: null });
    else if (validRangeValue(range.kind, typed)) this.patch.emit({ [this.key(range, end)]: typed });
  }

  protected add(pick: ListPickFilter, option: PickOption | null): void {
    if (option === null) return;
    const held = filterValues(this.chosen()[pick.id]);
    if (!held.includes(option.id)) this.patch.emit({ [pick.id]: joinValues([...held, option.id]) });
    this.named.emit(option);
    this.rounds.update((rounds) => ({ ...rounds, [pick.id]: (rounds[pick.id] ?? 0) + 1 }));
  }
}
