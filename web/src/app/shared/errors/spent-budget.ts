// SPDX-License-Identifier: AGPL-3.0-or-later

import { computed, inject, Injectable, Injector, signal } from '@angular/core';
import { TranslateService } from '@ngx-translate/core';
import { FormatFacade } from '../i18n/format-facade';
import { TextsParts } from '../i18n/texts-part';

/** The texts saying when to come back, opened into the root by the first spent budget heard. */
export const SPENT_BUDGET_TEXTS = 'spent-budget';

/**
 * When a spent budget may be tried again: the API answers its 429 with `Retry-After` in seconds (`SpentBudget` there),
 * which this turns into the hour on this device's clock, so the screen says « Réessayez à 14:32 » rather than « plus
 * tard ». Every text saying a spent budget takes its sentence as `retry`, empty when the answer gave no wait. It holds
 * one moment, nothing of the person, so it needs no forgetting at sign-out.
 */
@Injectable({ providedIn: 'root' })
export class SpentBudget {
  // Everything is read only once a budget is spent: a screen that never meets one needs none of it. The texts service
  // above all: this is built by an interceptor, which the texts' own request runs through while that service is still
  // being built, so asking for it here stopped the application at start on a cycle (NG0200, app-config.spec.ts).
  private readonly injector = inject(Injector);
  private readonly until = signal<string | null>(null);
  private readonly worded = signal(false);

  /** The sentence for a text's `retry`: when to come back, or nothing when the last spent budget did not say. */
  readonly retry = computed(() => {
    const at = this.until();
    if (at === null || !this.worded()) return '';
    return String(
      this.injector.get(TranslateService).instant('spent_budget.retry_at', {
        time: this.injector.get(FormatFacade).time(at),
      }),
    );
  });

  /** What a 429 said: its `Retry-After`, or null. A later answer always replaces the earlier wait, even with none. */
  heard(retryAfter: string | null): void {
    const seconds =
      retryAfter !== null && /^\d+$/.test(retryAfter.trim()) ? Number(retryAfter) : null;
    this.until.set(seconds === null ? null : new Date(Date.now() + seconds * 1000).toISOString());
    if (seconds !== null && !this.worded()) {
      void this.injector
        .get(TextsParts)
        .open(SPENT_BUDGET_TEXTS)
        .then(() => this.worded.set(true));
    }
  }
}
