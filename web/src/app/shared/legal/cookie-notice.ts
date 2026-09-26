// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { SETTINGS_STORAGE } from '../settings/settings-facade';
import { LEGAL_ROUTE } from './legal-pages';

/** Where this browser remembers that the notice was closed. */
export const COOKIE_NOTICE_KEY = 'twes.cookie-notice';

/**
 * The cookie notice (docs/SPEC.md § 7, 2026-09-26 08:52, row 149): informational, since only the session cookie and
 * the person's own display choices are stored, which need no consent. Shown until closed, then never again in this
 * browser. It sits in the page's flow at its top, as GOV.UK places its own, so it covers nothing and is the first thing
 * a keyboard reaches.
 */
@Component({
  selector: 'app-cookie-notice',
  imports: [MatButtonModule, RouterLink, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (open()) {
      <section
        class="border-b border-outline-variant bg-surface-container-high text-on-surface"
        [attr.aria-label]="'legal.notice.title' | translate"
        data-testid="cookie-notice"
      >
        <div
          class="mx-auto flex w-full max-w-6xl flex-wrap items-center gap-x-4 gap-y-2 px-4 py-2 text-sm sm:px-6 lg:px-8"
        >
          <p class="m-0 min-w-0 flex-1 basis-72">{{ 'legal.notice.text' | translate }}</p>
          <a [routerLink]="cookiesPage" data-testid="cookie-notice-more">{{
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
  protected readonly cookiesPage = `${LEGAL_ROUTE}/cookies`;
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
