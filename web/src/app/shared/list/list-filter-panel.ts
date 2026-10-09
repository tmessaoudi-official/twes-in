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
import { filterValues, joinValues, rangeKey, invertedRange, validRangeValue } from './list-filters';
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
            <!-- Beside an interval, whose legend stands above its fields, a picker keeps its label inside and so stood
                 a legend's height higher: it steps down by that height to share the fields' top. -->
            <app-pick-field
              class="w-full sm:w-72"
              [class.sm:pt-6]="ranges().length > 0"
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
                  [ngModel]="shown(range, end)"
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
                  [ngModel]="shown(range, end)"
                  (ngModelChange)="onRange(range, end, $event)"
                  [attr.data-testid]="testId() + '-' + range.id + '-' + end"
                />
              </mat-form-field>
            }
          }
          @if (inverted(range)) {
            <p
              class="basis-full text-sm text-error m-0"
              [attr.data-testid]="testId() + '-' + range.id + '-inverted'"
            >
              {{ 'list.range.inverted' | translate }}
            </p>
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

  /** What is typed in an interval end that is not a day or an amount yet, which the list does not filter by. */
  private readonly drafts = signal<Readonly<Record<string, string>>>({});

  /** Each picker starts again from an empty box once it has taken a record: its round is what tells it so. */
  protected readonly rounds = signal<Readonly<Record<string, number>>>({});

  protected ends(range: ListRangeFilter): readonly ('from' | 'to' | 'min' | 'max')[] {
    return range.kind === 'day' ? ['from', 'to'] : ['min', 'max'];
  }

  protected key(range: ListRangeFilter, end: 'from' | 'to' | 'min' | 'max'): string {
    return rangeKey(range.id, end);
  }

  /**
   * What was typed is kept only once it is a day or an amount; until then the field shows it and the list ignores it,
   * an end it held before included, so the list never filters by a value the field no longer shows.
   */
  protected onRange(
    range: ListRangeFilter,
    end: 'from' | 'to' | 'min' | 'max',
    value: unknown,
  ): void {
    const key = this.key(range, end);
    const typed = typeof value === 'string' ? value.trim() : '';
    const valid = typed !== '' && validRangeValue(range.kind, typed);
    this.drafts.update((drafts) => {
      const next = { ...drafts };
      if (valid || typed === '') delete next[key];
      else next[key] = typed;
      return next;
    });
    if (valid) this.patch.emit({ [key]: typed });
    else if (typed === '' || this.chosen()[key] !== undefined) this.patch.emit({ [key]: null });
  }

  /** Whether the interval's end comes before its start: the list leaves it out, and the panel says so. */
  protected inverted(range: ListRangeFilter): boolean {
    return invertedRange(this.chosen(), range);
  }

  /** What an end's field shows: what is being typed, until it is a value, else the value the list filters by. */
  protected shown(range: ListRangeFilter, end: 'from' | 'to' | 'min' | 'max'): string {
    const key = this.key(range, end);
    return this.drafts()[key] ?? this.chosen()[key] ?? '';
  }

  protected add(pick: ListPickFilter, option: PickOption | null): void {
    if (option === null) return;
    const held = filterValues(this.chosen()[pick.id]);
    if (!held.includes(option.id)) this.patch.emit({ [pick.id]: joinValues([...held, option.id]) });
    this.named.emit(option);
    this.rounds.update((rounds) => ({ ...rounds, [pick.id]: (rounds[pick.id] ?? 0) + 1 }));
  }
}
