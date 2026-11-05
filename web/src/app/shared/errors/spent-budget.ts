// SPDX-License-Identifier: AGPL-3.0-or-later

import { computed, inject, Injectable, Injector, signal } from '@angular/core';
import { TranslateService } from '@ngx-translate/core';
import { FormatFacade } from '../i18n/format-facade';
import { TextsParts } from '../i18n/texts-part';
import { typedText } from '../form/typed-text';

/** The texts saying when to come back, opened into the root by the first spent budget heard. */
export const SPENT_BUDGET_TEXTS = 'spent-budget';

/**
 * When a spent budget may be tried again: the API answers its 429 with `Retry-After` in seconds (`SpentBudget` there),
 * which this turns into the hour on this device's clock, so the screen says « Réessayez à 14:32 » rather than « plus
 * tard ». Every text saying a spent budget takes its sentence as `retry`. It holds
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

  /**
   * The sentence for a text's `retry`: the hour to come back at, with its day when that is not today (a lock of a day
   * read « à 14:32 » meant tomorrow's), or « plus tard » when the answer gave no wait, so a refusal never ends on
   * « Trop de tentatives. » alone. Empty only while its texts are on their way.
   */
  readonly retry = computed(() => {
    const at = this.until();
    if (!this.worded()) return '';
    const texts = this.injector.get(TranslateService);
    if (at === null) return String(texts.instant('spent_budget.retry_later'));
    const format = this.injector.get(FormatFacade);
    const time = format.time(at);
    const day = localDay(new Date(at));
    return day === localDay(new Date())
      ? String(texts.instant('spent_budget.retry_at', { time }))
      : String(texts.instant('spent_budget.retry_on', { day: format.day(day), time }));
  });

  /** What a 429 said: its `Retry-After`, or null. A later answer always replaces the earlier wait, even with none. */
  heard(retryAfter: string | null): void {
    const seconds =
      retryAfter !== null && /^\d+$/.test(typedText(retryAfter)) ? Number(retryAfter) : null;
    this.until.set(seconds === null ? null : new Date(Date.now() + seconds * 1000).toISOString());
    if (!this.worded()) {
      void this.injector
        .get(TextsParts)
        .open(SPENT_BUDGET_TEXTS)
        .then(() => this.worded.set(true));
    }
  }
}

/** The calendar day of a moment on this device's clock, as `YYYY-MM-DD`: the day its hour is written in. */
function localDay(at: Date): string {
  const pad = (n: number): string => String(n).padStart(2, '0');
  return `${at.getFullYear()}-${pad(at.getMonth() + 1)}-${pad(at.getDate())}`;
}
