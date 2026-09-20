// SPDX-License-Identifier: AGPL-3.0-or-later

import { computed, DestroyRef, inject, Injectable, type Signal, signal } from '@angular/core';
import type { ScreenAction } from './screen-action';
import { refuseReservedShortcut } from './shortcuts';

/**
 * What the screen on view offers, for everything that is not the screen itself: the shortcut dispatcher, the
 * command palette and the "?" sheet (docs/SPEC.md § 7, row 45).
 *
 * A screen declares a SIGNAL rather than a list, because its actions change with what it is showing — an invoice
 * offers "Émettre" while it is a draft and "Enregistrer un paiement" once it is issued — and a reader must see
 * the current answer, never the one that was true when the page opened. A declaration is dropped when the screen
 * that made it is destroyed, so a key bound on one page cannot fire on the next.
 */
@Injectable({ providedIn: 'root' })
export class ScreenActions {
  private readonly sources = signal<readonly Signal<readonly ScreenAction[]>[]>([]);

  /** Everything the screen on view offers right now, minus what it says does not apply. */
  readonly actions: Signal<readonly ScreenAction[]> = computed(() => {
    const declared = this.sources().flatMap((source) => source());
    const offered = declared.filter((action) => action.shown !== false);
    refuseImpossibleShortcuts(offered);

    return offered;
  });

  /** The actions a keyboard hint or the "?" sheet lists: those with a key, in the order the screen declared them. */
  readonly withShortcut: Signal<readonly ScreenAction[]> = computed(() =>
    this.actions().filter((action) => action.shortcut !== undefined),
  );

  /** Must be called from an injection context: the declaration lives exactly as long as the screen that made it. */
  declare(source: Signal<readonly ScreenAction[]>): void {
    this.sources.update((sources) => [...sources, source]);
    inject(DestroyRef).onDestroy(() => {
      this.sources.update((sources) => sources.filter((each) => each !== source));
    });
  }

  /** What this character asked for, if anything. The caller has already decided the keystroke is a shortcut. */
  forKey(key: string): ScreenAction | undefined {
    const wanted = key.toLowerCase();

    return this.actions().find(
      (action) => action.shortcut !== undefined && action.shortcut.toLowerCase() === wanted,
    );
  }
}

/**
 * Checked on every read rather than once at declaration, because a screen recomputes its list: a key that only
 * appears in one state would otherwise escape a check made when the page opened.
 */
function refuseImpossibleShortcuts(actions: readonly ScreenAction[]): void {
  const claimed = new Set<string>();
  for (const action of actions) {
    if (action.shortcut === undefined) continue;
    refuseReservedShortcut(action.shortcut);
    const key = action.shortcut.toLowerCase();
    if (claimed.has(key)) {
      throw new Error(`"${action.shortcut}" is claimed twice by the actions of one screen.`);
    }
    claimed.add(key);
  }
}
