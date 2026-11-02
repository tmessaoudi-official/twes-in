// SPDX-License-Identifier: AGPL-3.0-or-later

import { DestroyRef, DOCUMENT, inject, Injectable } from '@angular/core';

/** The key a follower hears: one key, or a test for a family of keys (`twes.settings.<person>.<setting>`). */
export type StorageKeyMatch = string | ((key: string) => boolean);

interface Follower {
  readonly match: StorageKeyMatch;
  readonly heard: () => void;
}

/**
 * What another tab of this browser writes in its local storage, said here: the browser tells every other document
 * (`storage`), never the one that wrote. A device setting kept there (the scanner's gap, the shortest scan, the text
 * size, the cookie notice closed, a signed-out page's display choices) is read again by whoever holds it, so a choice
 * in one tab shows in every open one, as every other outcome does. One listener for the whole page; a clear of the
 * storage (no key) is heard by every follower.
 */
@Injectable({ providedIn: 'root' })
export class StorageChanges {
  private readonly followers = new Set<Follower>();

  constructor() {
    const view = inject(DOCUMENT).defaultView;
    if (view === null) return;
    const said = (event: StorageEvent) => {
      for (const follower of [...this.followers]) {
        if (event.key === null || matches(follower.match, event.key)) follower.heard();
      }
    };
    view.addEventListener('storage', said);
    inject(DestroyRef).onDestroy(() => view.removeEventListener('storage', said));
  }

  /** Calls `heard` each time another tab writes a key `match` names; until `until` ends, else for the page's life. */
  follow(match: StorageKeyMatch, heard: () => void, until?: DestroyRef): void {
    const follower: Follower = { match, heard };
    this.followers.add(follower);
    until?.onDestroy(() => this.followers.delete(follower));
  }
}

function matches(match: StorageKeyMatch, key: string): boolean {
  return typeof match === 'string' ? match === key : match(key);
}
