// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  computed,
  effect,
  inject,
  input,
  output,
  signal,
} from '@angular/core';
import { takeUntilDestroyed, toSignal } from '@angular/core/rxjs-interop';
import { FormControl, ReactiveFormsModule } from '@angular/forms';
import {
  MatAutocompleteModule,
  type MatAutocompleteSelectedEvent,
} from '@angular/material/autocomplete';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { debounceTime } from 'rxjs';

/** One thing a person may pick: what they type to find it, and what they read to recognise it. */
export interface PickOption {
  id: string;
  /** A customer's number, a product's reference — the short thing people actually type. */
  code: string;
  name: string;
}

/** How long a person stops typing before the API is asked (docs/SPEC.md § 7, 2026-09-17, ruling 3). */
export const PICK_PAUSE_MS = 300;

/**
 * A field that asks the API for the few things a person means, instead of being handed every one of them
 * (docs/SPEC.md § 7, 2026-09-17, ruling 3: a 300 ms pause, 20 results).
 *
 * It owns no catalogue and no list: the page passes a `search` function, and what comes back is what is offered. What
 * it emits is the option itself, so the page can carry whatever else it knows about that row — a product's price, a
 * customer's regime — without this field knowing any of it.
 *
 * A record that was picked before is shown from `value`, which the page reads off the document rather than looking it
 * up: an invoice carries its customer's name and each line its product's, which is what lets this field exist at all.
 */
@Component({
  selector: 'app-pick-field',
  imports: [ReactiveFormsModule, MatFormFieldModule, MatInputModule, MatAutocompleteModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <mat-form-field class="w-full">
      <mat-label>{{ label() }}</mat-label>
      <input
        matInput
        [formControl]="typed"
        [matAutocomplete]="list"
        [attr.data-testid]="testId()"
        [attr.aria-describedby]="testId() + '-hint'"
        autocomplete="off"
      />
      <mat-autocomplete
        #list="matAutocomplete"
        [displayWith]="shown"
        (optionSelected)="take($event)"
        [attr.data-testid]="testId() + '-options'"
      >
        @if (clearable()) {
          <mat-option [value]="null" [attr.data-testid]="testId() + '-none'">{{
            noneLabel()
          }}</mat-option>
        }
        @for (option of offered(); track option.id) {
          <mat-option [value]="option" [attr.data-testid]="testId() + '-option-' + option.id">
            {{ option.code }} · {{ option.name }}
          </mat-option>
        }
        @if (asked() && offered().length === 0) {
          <mat-option disabled [attr.data-testid]="testId() + '-none-found'">{{
            noneFoundLabel()
          }}</mat-option>
        }
      </mat-autocomplete>
    </mat-form-field>
  `,
})
export class PickField {
  readonly label = input.required<string>();
  readonly testId = input.required<string>();
  /** What the API answers for these words. It is asked once the typing stops, never on every keystroke. */
  readonly search = input.required<(words: string) => Promise<readonly PickOption[]>>();
  /** What is picked now, as the record itself says it; null for a field nothing has been picked in. */
  readonly value = input<PickOption | null>(null);
  readonly disabled = input(false);
  /** Whether "none of them" is an answer: a line may name no product, a document must name a customer. */
  readonly clearable = input(false);
  readonly noneLabel = input('—');
  readonly noneFoundLabel = input('—');

  readonly picked = output<PickOption | null>();

  protected readonly typed = new FormControl<string | PickOption>('', { nonNullable: true });
  protected readonly offered = signal<readonly PickOption[]>([]);
  /** Whether an answer has come back for what is typed, so "nothing found" is never shown before asking. */
  protected readonly asked = signal(false);

  private readonly words = toSignal(
    this.typed.valueChanges.pipe(
      debounceTime(PICK_PAUSE_MS),
      takeUntilDestroyed(inject(DestroyRef)),
    ),
    { initialValue: '' as string | PickOption },
  );
  private readonly wanted = computed(() => {
    const value = this.words();
    return typeof value === 'string' ? value.trim() : '';
  });

  constructor() {
    // What the record says goes into the box, without asking the API: the document already carries the words.
    effect(() => {
      const value = this.value();
      this.typed.setValue(value ?? '', { emitEvent: false });
      if (this.disabled()) {
        this.typed.disable({ emitEvent: false });
      } else {
        this.typed.enable({ emitEvent: false });
      }
    });

    let asking = 0;
    effect(async () => {
      const words = this.wanted();
      const search = this.search();
      const turn = ++asking;
      this.asked.set(false);
      const found = await search(words);
      // A later keystroke may have asked again while this one was answering; its answer wins.
      if (turn !== asking) return;
      this.offered.set(found);
      this.asked.set(true);
    });
  }

  /** What the box reads once something is picked: the same two words the list showed. */
  protected readonly shown = (value: string | PickOption | null): string =>
    value === null || typeof value === 'string' ? (value ?? '') : `${value.code} · ${value.name}`;

  protected take(event: MatAutocompleteSelectedEvent): void {
    const option = event.option.value as PickOption | null;
    this.picked.emit(option);
  }
}
