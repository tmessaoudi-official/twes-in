// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { TranslatePipe } from '@ngx-translate/core';
import { SETTINGS_STORAGE } from '../settings/settings-facade';
import { LegalLink } from './legal-link';

/** Where this browser remembers that the notice was closed. */
export const COOKIE_NOTICE_KEY = 'twes.cookie-notice';

/**
 * The cookie notice: informational, since only the session cookie, the person's own display choices and the device's
 * own settings are stored, which need no consent, so nothing waits for it. Shown until closed, then never again in this
 * browser. It is the last row of the scrolling area, held at its foot (`.twes-cookie-notice`): always in view, and
 * taking its own place, so it never lies over a button or the end of the page.
 */
@Component({
  selector: 'app-cookie-notice',
  imports: [LegalLink, MatButtonModule, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (open()) {
      <section
        class="twes-cookie-notice border-t border-outline-variant bg-surface-container-high text-on-surface"
        [attr.aria-label]="'legal.notice.title' | translate"
        data-testid="cookie-notice"
      >
        <div
          class="mx-auto flex w-full max-w-6xl flex-wrap items-center gap-x-4 gap-y-2 px-4 py-2 text-sm sm:px-6 lg:px-8"
        >
          <p class="m-0 min-w-0 flex-1 basis-72">{{ 'legal.notice.text' | translate }}</p>
          <a appLegalLink="cookies" data-testid="cookie-notice-more">{{
            'legal.notice.more' | translate
          }}</a>
          <button
            mat-stroked-button
            type="button"
            (click)="close()"
            data-testid="cookie-notice-close"
          >
            {{ 'legal.notice.close' | translate }}
          </button>
        </div>
      </section>
    }
  `,
})
export class CookieNotice {
  private readonly storage = inject(SETTINGS_STORAGE);
  protected readonly open = signal(this.firstVisit());

  protected close(): void {
    this.open.set(false);
    try {
      this.storage.setItem(COOKIE_NOTICE_KEY, 'closed');
    } catch {
      // Storage refused: closed for this page, shown again on the next.
    }
  }

  private firstVisit(): boolean {
    try {
      return this.storage.getItem(COOKIE_NOTICE_KEY) === null;
    } catch {
      return true;
    }
  }
}
