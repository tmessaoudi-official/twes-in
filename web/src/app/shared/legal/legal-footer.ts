// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { Brand } from '../brand/brand';
import { LEGAL_PAGES, LEGAL_ROUTE } from './legal-pages';

/**
 * The copyright and legal-links line (docs/SPEC.md § 7, 2026-09-26 08:52, row 147): slim, under the content of every
 * page, signed out too, scrolling with it. The brand is the installation's own, through the `Brand` port. The licence
 * leads to the source page, which a network service under the AGPL owes its users (§ 13).
 */
@Component({
  selector: 'app-legal-footer',
  imports: [RouterLink, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <nav
      class="twes-legal-line"
      [attr.aria-label]="'legal.navigation' | translate"
      data-testid="legal-footer"
    >
      <span data-testid="legal-copyright">© {{ year }} {{ brand.name() }}</span>
      <!-- The dots are drawn, not said: a screen reader reads each link by its own name. -->
      <span aria-hidden="true">·</span>
      <a [routerLink]="sourcePage" data-testid="legal-licence">AGPL-3.0</a>
      @for (slug of pages; track slug) {
        <span aria-hidden="true">·</span>
        <a [routerLink]="[route, slug]" [attr.data-testid]="'legal-link-' + slug">{{
          'legal.pages.' + slug | translate
        }}</a>
      }
    </nav>
  `,
})
export class LegalFooter {
  protected readonly brand = inject(Brand);
  protected readonly year = new Date().getFullYear();
  protected readonly pages = LEGAL_PAGES;
  protected readonly route = LEGAL_ROUTE;
  protected readonly sourcePage = `${LEGAL_ROUTE}/source`;
}
