// SPDX-License-Identifier: AGPL-3.0-or-later

import { DestroyRef, effect, inject, type Signal, signal } from '@angular/core';

/** The longest a browser timer waits: a deadline further off is reached in steps of this. */
const TIMER_MAX_MS = 2_147_483_647;

/**
 * The moment it is (milliseconds), moved on as each of the deadlines given passes: a screen showing what lapses at a
 * moment (« ouvert jusqu'à… », « Annuler cet import ») reads it against this, and so says it lapsed then, not only when
 * the screen is next loaded. No change is published when a deadline passes by itself, so a reload on live changes never
 * hears it. Deadlines are ISO moments, null for none; called in an injection context, it stops with it.
 */
export function deadlineClock(deadlines: () => readonly (string | null)[]): Signal<number> {
  const now = signal(Date.now());
  let timer: ReturnType<typeof setTimeout> | null = null;
  const clear = (): void => {
    if (timer !== null) clearTimeout(timer);
    timer = null;
  };
  const watching = effect(() => {
    const at = now();
    const next = deadlines()
      .map((deadline) => (deadline === null ? Number.NaN : Date.parse(deadline)))
      .filter((moment) => Number.isFinite(moment) && moment > at)
      .reduce((earliest, moment) => Math.min(earliest, moment), Number.POSITIVE_INFINITY);
    clear();
    if (next === Number.POSITIVE_INFINITY) return;
    // One millisecond past the deadline, so the moment read then is past it.
    const wait = Math.min(Math.max(0, next - Date.now() + 1), TIMER_MAX_MS);
    timer = setTimeout(() => now.set(Date.now()), wait);
  });
  inject(DestroyRef).onDestroy(() => {
    clear();
    watching.destroy();
  });
  return now.asReadonly();
}
