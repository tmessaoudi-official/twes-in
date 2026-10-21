// SPDX-License-Identifier: AGPL-3.0-or-later

import { Injectable } from '@angular/core';

/**
 * Whether what runs now is a reload nobody on the screen asked for: a list read again after another person's change, or
 * after this tab's own change heard again. A facade's read leaves its busy mark as it is during one: marked busy, the
 * screen's actions would turn disabled for its moment, and a click or a key already on its way would be refused
 * unseen. A write is always marked, so it is never sent twice. Its own service, injecting nothing, so any facade can
 * ask it. The count is the tab's, not one screen's: a read a person starts meanwhile is taken for a quiet one too.
 */
@Injectable({ providedIn: 'root' })
export class QuietReads {
  private runs = 0;

  quiet(): boolean {
    return this.runs > 0;
  }

  async quietly<T>(work: () => Promise<T>): Promise<T> {
    this.runs++;
    try {
      return await work();
    } finally {
      this.runs--;
    }
  }
}
