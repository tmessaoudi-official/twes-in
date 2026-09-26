// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { SignedOutLayout } from '../auth/signed-out-layout';
import { isLegalPage } from '../shared/legal/legal-pages';

/**
 * One legal page at `/legal/<slug>` (docs/SPEC.md § 7, 2026-09-26 08:52, rows 147 and 148), open to anyone, signed in
 * or not, outside the shell like the other public pages. Row 147 gives each page its place and its title; row 148
 * gives it its text, edited by the platform operator, dated and per language. Until then the page says so.
 */
@Component({
  selector: 'app-legal-page',
  imports: [RouterLink, SignedOutLayout, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <app-signed-out-layout plain>
      <article class="twes-legal-page flex flex-col gap-4">
        @if (known()) {
          <h1 class="text-2xl font-semibold" data-testid="legal-title">
            {{ 'legal.pages.' + slug() | translate }}
          </h1>
          <p>
            <span class="twes-soon" data-testid="legal-draft">{{ 'legal.draft' | translate }}</span>
          </p>
          <p data-testid="legal-drafting">{{ 'legal.drafting' | translate }}</p>
        } @else {
          <p data-testid="legal-unknown">
            {{ 'legal.unknown' | translate }}
            <a routerLink="/">{{ 'legal.home' | translate }}</a>
          </p>
        }
      </article>
    </app-signed-out-layout>
  `,
})
export class LegalPage {
  /** From the route, through `withComponentInputBinding`. */
  readonly slug = input.required<string>();
  protected readonly known = computed(() => isLegalPage(this.slug()));
}
