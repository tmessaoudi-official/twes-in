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
import { SCAN_GAP_MS } from '../scan/scan-wedge';

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
      @if (labelInside()) {
        <mat-label>{{ label() }}</mat-label>
      }
      <!-- The id below is matInput's own input, as every other field of a descriptor form binds it: an
           attribute binding is overwritten by matInput's host binding, which leaves the form's label
           pointing at nothing. Material falls back to its own generated id when this is empty. -->
      <input
        matInput
        [formControl]="typed"
        [matAutocomplete]="list"
        [id]="inputId()"
        [attr.data-testid]="testId()"
        [attr.aria-describedby]="hint() === '' ? null : testId() + '-hint'"
        autocomplete="off"
        (input)="clocked()"
        (keydown.enter)="resolve($event)"
        (blur)="scanning.set(false)"
      />
      @if (hint() !== '') {
        <mat-hint [id]="testId() + '-hint'">{{ hint() }}</mat-hint>
      }
      <!--
        The first row is held ready so that Enter takes it — which is how a scanner, which presses Enter itself,
        can pick anything at all. That is also why "none of them" is written LAST and hidden mid-burst: offered
        first, it would be the row held ready, and every Enter would empty the field instead of filling it.
      -->
      <mat-autocomplete
        #list="matAutocomplete"
        [displayWith]="shown"
        [autoActiveFirstOption]="true"
        (optionSelected)="take($event)"
        [attr.data-testid]="testId() + '-options'"
      >
        @for (option of offered(); track option.id) {
          <mat-option [value]="option" [attr.data-testid]="testId() + '-option-' + option.id">
            {{ option.code }} · {{ option.name }}
          </mat-option>
        }
        @if (clearable() && !scanning()) {
          <mat-option [value]="null" [attr.data-testid]="testId() + '-none'">{{
            noneLabel()
          }}</mat-option>
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
  /**
   * The pick as the box follows it: the same row handed over again as a new object — a document line builds it from
   * its controls on every check — is the same pick, and must not put its words back over what the person is typing.
   */
  private readonly pick = computed(() => this.value(), {
    equal: (a, b) =>
      a === b ||
      (a !== null && b !== null && a.id === b.id && a.code === b.code && a.name === b.name),
  });
  readonly disabled = input(false);
  /** Whether "none of them" is an answer: a line may name no product, a document must name a customer. */
  readonly clearable = input(false);
  readonly noneLabel = input('—');
  readonly noneFoundLabel = input('—');
  /** A line under the box saying what to type; left out, the input describes nothing, which is what axe asks. */
  readonly hint = input('');
  /**
   * Whether this field draws its own label inside the box. A picker standing alone does; one inside a descriptor
   * form does not, because that form already writes every field's label above its box, with "· optional" beside it.
   */
  readonly labelInside = input(true);
  /** The id the surrounding form's `<label for>` points at, when the label lives outside. */
  readonly inputId = input('');

  readonly picked = output<PickOption | null>();
  /**
   * What a scanner read, emitted after `picked` when a scan took its one match: the screen can then ask what the code
   * counts — a pack enters twelve — which the row itself does not say.
   */
  readonly scanned = output<string>();

  protected readonly typed = new FormControl<string | PickOption>('', { nonNullable: true });
  protected readonly offered = signal<readonly PickOption[]>([]);
  /** Whether an answer has come back for what is typed, so "nothing found" is never shown before asking. */
  protected readonly asked = signal(false);
  /** Whether the characters are arriving too fast for a hand — see `clocked` below. */
  protected readonly scanning = signal(false);
  private lastKeyAt = Number.NEGATIVE_INFINITY;
  /** Counts the questions asked; an answer to any but the latest is dropped. */
  private asking = 0;

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
      const value = this.pick();
      this.typed.setValue(value ?? '', { emitEvent: false });
    });
    effect(() => {
      if (this.disabled()) {
        this.typed.disable({ emitEvent: false });
      } else {
        this.typed.enable({ emitEvent: false });
      }
    });

    effect(async () => {
      const words = this.wanted();
      const search = this.search();
      const turn = ++this.asking;
      this.asked.set(false);
      const found = await search(words);
      // A later keystroke may have asked again while this one was answering; its answer wins.
      if (turn !== this.asking) return;
      this.offered.set(found);
      this.asked.set(true);
    });
  }

  /**
   * Notes how fast the characters arrive. A hand leaves tens of milliseconds between two; a wedge leaves one or
   * two, so the pause the field waits for never elapses and, left alone, nothing is ever asked.
   *
   * Recognising a burst also CLEARS what was on offer: the rows below belong to what was typed before, and Enter
   * is about to arrive. With the list empty and "none of them" hidden, there is no row held ready for it, so the
   * answer comes from `resolve` below and never from a leftover.
   */
  protected clocked(): void {
    const now = Date.now();
    const gap = now - this.lastKeyAt;
    this.lastKeyAt = now;
    this.scanning.set(gap < SCAN_GAP_MS);
    if (this.scanning()) {
      // A question still out was asked before the scan — on focus, on no words — and its rows must not come back
      // mid-burst with the first one held ready for the scanner's Enter.
      this.asking += 1;
      this.offered.set([]);
      this.asked.set(false);
    }
  }

  /**
   * The Enter a scanner presses itself. It is held back from the form around the field — which would read it as
   * "save this document" — and answered here: one match is taken without asking, several are offered as a
   * question, none leaves the code on screen to be dealt with.
   *
   * A person's Enter is left untouched: the list has a row held ready and Material takes it, as anywhere else.
   */
  protected async resolve(event: Event): Promise<void> {
    if (!this.scanning()) return;
    const typed = this.typed.value;
    const words = typeof typed === 'string' ? typed.trim() : '';
    if (words === '') return;

    event.preventDefault();
    this.scanning.set(false);
    const found = await this.search()(words);
    this.offered.set(found);
    this.asked.set(true);
    if (found.length === 1) {
      this.typed.setValue(found[0], { emitEvent: false });
      this.picked.emit(found[0]);
      this.scanned.emit(words);
    }
  }

  /** What the box reads once something is picked: the same two words the list showed. */
  protected readonly shown = (value: string | PickOption | null): string =>
    value === null || typeof value === 'string' ? (value ?? '') : `${value.code} · ${value.name}`;

  protected take(event: MatAutocompleteSelectedEvent): void {
    const option = event.option.value as PickOption | null;
    this.picked.emit(option);
  }
}
