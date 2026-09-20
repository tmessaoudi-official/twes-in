// SPDX-License-Identifier: AGPL-3.0-or-later

import { computed, DestroyRef, inject, Injectable, type Signal, signal } from '@angular/core';
import { MatDialog } from '@angular/material/dialog';
import type { CanDeactivateFn, Route } from '@angular/router';
import { map, type Observable, of } from 'rxjs';
import { ConfirmDialog } from '../ui/confirm-dialog';

/**
 * What is typed and not saved, anywhere on the page (row 45). Every record page counts its own through
 * `unsavedChanges`, which declares it here, so no page has to remember to take part and a page added later is
 * covered by the fact that it counts at all.
 */
@Injectable({ providedIn: 'root' })
export class UnsavedChanges {
  private readonly dialog = inject(MatDialog);
  private readonly sources = signal<readonly Signal<number>[]>([]);

  /** How many fields on the page hold something other than what was last saved. */
  readonly count: Signal<number> = computed(() =>
    this.sources().reduce((total, source) => total + source(), 0),
  );

  /** Must be called from an injection context: the count lives exactly as long as the page that counts it. */
  declare(source: Signal<number>): void {
    this.sources.update((sources) => [...sources, source]);
    inject(DestroyRef).onDestroy(() => {
      this.sources.update((sources) => sources.filter((each) => each !== source));
    });
  }

  /**
   * Whether the person may leave. Nothing unsaved is not a question — asking on a page nobody changed is how a
   * person learns to dismiss the question without reading it, and then loses the one that mattered.
   */
  confirmLeave(): Observable<boolean> {
    if (this.count() === 0) return of(true);

    return this.dialog
      .open(ConfirmDialog, {
        data: {
          title: 'form.leave.title',
          message: 'form.leave.message',
          confirmLabel: 'form.leave.leave',
          keepLabel: 'form.leave.stay',
        },
        autoFocus: 'dialog',
      })
      .afterClosed()
      .pipe(map((answer) => answer === true));
  }
}

/** Angular asks this before it takes a page off screen. */
export const unsavedGuard: CanDeactivateFn<unknown> = () => inject(UnsavedChanges).confirmLeave();

/**
 * Puts the guard on every route given, rather than on each one by hand: a record page added later would otherwise
 * be unguarded, and nothing would say so — the whole class of defect this project keeps finding in checks that
 * enumerate by list. A route that already names its own `canDeactivate` keeps it and gains this one.
 */
export function guardUnsaved(routes: readonly Route[]): Route[] {
  return routes.map((route) => ({
    ...route,
    canDeactivate: [...(route.canDeactivate ?? []), unsavedGuard],
    ...(route.children === undefined ? {} : { children: guardUnsaved(route.children) }),
  }));
}
