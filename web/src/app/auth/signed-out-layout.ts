// SPDX-License-Identifier: AGPL-3.0-or-later

import { booleanAttribute, ChangeDetectionStrategy, Component, inject, input } from '@angular/core';
import { MatIconModule } from '@angular/material/icon';
import { TranslatePipe } from '@ngx-translate/core';
import { Brand } from '../shared/brand/brand';
import { ActivityBar } from '../shared/feedback/activity-bar';
import { BrandWordmark } from '../shared/brand/brand-wordmark';
import { LanguageMenu } from '../shared/i18n/language-menu';
import { LegalFooter } from '../shared/legal/legal-footer';
import { SchemeMenu } from '../shared/theme/scheme-menu';

/**
 * Every page shown before the shell: the installation's wordmark and tagline over the page's own card, a quiet
 * scene of business documents behind it on wide screens, and a footer line the page may project
 * (`twesAuthFooter`), with the language and colour scheme in the top corner. The scene is decoration only, hidden from assistive technology and from phones.
 */
@Component({
  selector: 'app-signed-out-layout',
  imports: [
    ActivityBar,
    BrandWordmark,
    LanguageMenu,
    LegalFooter,
    MatIconModule,
    SchemeMenu,
    TranslatePipe,
  ],
  templateUrl: './signed-out-layout.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SignedOutLayout {
  /** A page with more to show than one form (setting up a second factor) takes a wider card. */
  readonly wide = input(false, { transform: booleanAttribute });

  /**
   * A page that brings its own surfaces (the locked company's subscription, with its cards and its form) takes the
   * width plainly instead: inside the card its form still lays out in two columns — `sm:` is the viewport's
   * breakpoint, not the card's — and the inputs collapse to nothing (2026-09-17).
   */
  readonly plain = input(false, { transform: booleanAttribute });
  protected readonly brand = inject(Brand);
}
