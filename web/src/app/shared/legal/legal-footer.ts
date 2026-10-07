// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { TranslatePipe } from '@ngx-translate/core';
import { Brand } from '../brand/brand';
import { BuildLine } from '../build/build-line';
import { LegalLink } from './legal-link';
import { LEGAL_PAGES } from './legal-pages';

/**
 * The copyright and legal-links line: slim and centred, under the content of every page, signed out too, scrolling
 * with it. The brand is the installation's own, through the `Brand` port. The licence leads to the source page, which
 * a network service under the AGPL owes its users (§ 13). Each link opens its text over the screen (`LegalLink`). The
 * line ends on the builds that answer (`BuildLine`).
 */
@Component({
  selector: 'app-legal-footer',
  imports: [BuildLine, LegalLink, TranslatePipe],
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
      <a appLegalLink="source" data-testid="legal-licence">AGPL-3.0</a>
      @for (slug of pages; track slug) {
        <span aria-hidden="true">·</span>
        <a [appLegalLink]="slug" [attr.data-testid]="'legal-link-' + slug">{{
          'legal.pages.' + slug | translate
        }}</a>
      }
      <span aria-hidden="true">·</span>
      <app-build-line />
    </nav>
  `,
})
export class LegalFooter {
  protected readonly brand = inject(Brand);
  protected readonly year = new Date().getFullYear();
  protected readonly pages = LEGAL_PAGES;
}
