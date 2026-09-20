// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  computed,
  DestroyRef,
  effect,
  inject,
  type Signal,
  signal,
  untracked,
} from '@angular/core';
import type { AbstractControl } from '@angular/forms';
import type { Subscription } from 'rxjs';

/**
 * How many fields hold something other than what was last saved (docs/SPEC.md § 7, 2026-09-19 23:18, design review
 * finding 4). The count is what the bar shows beside the save, so it has to mean "unsaved changes" and nothing
 * looser: Angular's own `dirty` says a control was TOUCHED, and a person who types a character and deletes it
 * again has changed nothing.
 *
 * The baseline is what the page last got from the API, not the control's `defaultValue`: a control that is not
 * `nonNullable` keeps `null` there whatever it was built with, so `defaultValue` would count every optional field
 * as changed from the moment the page opened.
 */
export function dirtyCount(current: unknown, saved: unknown): number {
  if (Array.isArray(current) || Array.isArray(saved)) {
    const now = Array.isArray(current) ? current : [];
    const was = Array.isArray(saved) ? saved : [];
    let count = 0;
    for (let index = 0; index < Math.max(now.length, was.length); index += 1) {
      count += dirtyCount(now[index], was[index]);
    }
    return count;
  }
  if (isRecord(current) || isRecord(saved)) {
    const now = isRecord(current) ? current : {};
    const was = isRecord(saved) ? saved : {};
    let count = 0;
    for (const field of new Set([...Object.keys(now), ...Object.keys(was)])) {
      count += dirtyCount(now[field], was[field]);
    }
    return count;
  }
  return same(current, saved) ? 0 : 1;
}

/** Puts every field back to what was last saved, and marks the form untouched with it. */
export function revertToSaved(form: AbstractControl, saved: unknown): void {
  form.reset(saved as never);
}

/** `''`, `null` and a missing key are the same answer from a form's side: the field says nothing. */
function same(current: unknown, saved: unknown): boolean {
  if (current === saved) return true;
  const blank = (candidate: unknown): boolean =>
    candidate === null || candidate === undefined || candidate === '';
  return blank(current) && blank(saved);
}

function isRecord(candidate: unknown): candidate is Record<string, unknown> {
  return typeof candidate === 'object' && candidate !== null && !Array.isArray(candidate);
}

/**
 * The count as a signal, for the bar beside the title. A reactive form is not a signal, so what a person types
 * reaches nothing on its own: this follows `valueChanges` of whichever form is on screen, and re-follows the next
 * one when the page opens another record.
 */
export function unsavedChanges(
  form: Signal<AbstractControl | null>,
  saved: Signal<unknown>,
): Signal<number> {
  const typed = signal(0);
  let subscription: Subscription | null = null;
  effect(() => {
    const control = form();
    subscription?.unsubscribe();
    subscription =
      control?.valueChanges.subscribe(() => typed.update((count) => count + 1)) ?? null;
    untracked(() => typed.update((count) => count + 1));
  });
  inject(DestroyRef).onDestroy(() => subscription?.unsubscribe());

  return computed(() => {
    typed();
    const control = form();
    return control === null ? 0 : dirtyCount(control.getRawValue(), saved());
  });
}
