// SPDX-License-Identifier: AGPL-3.0-or-later

import { DOCUMENT } from '@angular/common';
import {
  computed,
  DestroyRef,
  effect,
  inject,
  Injectable,
  type Signal,
  signal,
} from '@angular/core';
import { MatDialog } from '@angular/material/dialog';
import type { CanDeactivateFn, Route } from '@angular/router';
import { from, map, type Observable, of, switchMap, tap } from 'rxjs';
import type { ActionConfirm } from '../actions/screen-action';

/**
 * What is typed and not saved, anywhere on the page (row 45). Every record page counts its own through
 * `unsavedChanges`, which declares it here, so no page has to remember to take part and a page added later is
 * covered by the fact that it counts at all.
 */
@Injectable({ providedIn: 'root' })
export class UnsavedChanges {
  private readonly dialog = inject(MatDialog);
  private readonly sources = signal<readonly Signal<number>[]>([]);
  /** The question the next navigation asks is already answered: consumed by that navigation alone. */
  private answered = false;

  /** How many fields on the page hold something other than what was last saved. */
  readonly count: Signal<number> = computed(() =>
    this.sources().reduce((total, source) => total + source(), 0),
  );

  constructor() {
    // Closing or reloading the tab takes the page down with no navigation to guard: the browser's own question holds
    // it, registered only while something is unsaved, so a clean page never asks. Its words are the browser's.
    const view = inject(DOCUMENT).defaultView;
    effect((onCleanup) => {
      if (view === null || this.count() === 0) return;
      const hold = (event: BeforeUnloadEvent) => event.preventDefault();
      view.addEventListener('beforeunload', hold);
      onCleanup(() => view.removeEventListener('beforeunload', hold));
    });
  }

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
  /**
   * The page saved and is now going to the record it created. Its form still holds what was typed while what it
   * compares against is the API's answer, and that difference is not unsaved work — the record exists. The next
   * question is always the one that navigation asks, and it consumes this.
   */
  savedAndLeaving(): void {
    this.answered = true;
  }

  confirmLeave(): Observable<boolean> {
    if (this.answered) {
      this.answered = false;
      return of(true);
    }
    if (this.count() === 0) return of(true);

    // The guard sits on the first page through the routes; the dialog is fetched the first time it is needed.
    return from(import('../ui/confirm-dialog')).pipe(
      switchMap(({ ConfirmDialog }) =>
        this.dialog
          .open<InstanceType<typeof ConfirmDialog>, ActionConfirm, boolean>(ConfirmDialog, {
            // Typed work that was never saved cannot be got back, so leaving it is definitive.
            data: {
              kind: 'definitif',
              title: 'form.leave.title',
              message: 'form.leave.message',
              confirmLabel: 'form.leave.leave',
              keepLabel: 'form.leave.stay',
            },
            autoFocus: 'dialog',
          })
          .afterClosed(),
      ),
      map((answer) => answer === true),
    );
  }

  /**
   * For a way out that takes the page down before it navigates (signing out): the page still counts what was typed
   * when the navigation that follows asks, so a yes here answers that question too, and the person is asked once.
   */
  confirmLeaveBeforeNavigating(): Observable<boolean> {
    const unsaved = this.count() > 0;
    return this.confirmLeave().pipe(
      tap((leave) => {
        if (leave && unsaved) this.answered = true;
      }),
    );
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
