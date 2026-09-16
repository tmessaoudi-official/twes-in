// SPDX-License-Identifier: AGPL-3.0-or-later

import { booleanAttribute, ChangeDetectionStrategy, Component, inject, input } from '@angular/core';
import { MatIconModule } from '@angular/material/icon';
import { TranslatePipe } from '@ngx-translate/core';
import { Brand } from '../shared/brand/brand';
import { BrandWordmark } from '../shared/brand/brand-wordmark';

/**
 * Every page shown before the shell: the installation's wordmark and tagline over the page's own card, a quiet
 * scene of business documents behind it on wide screens, and a footer line the page may project
 * (`twesAuthFooter`). The scene is decoration only, hidden from assistive technology and from phones.
 */
@Component({
  selector: 'app-signed-out-layout',
  imports: [BrandWordmark, MatIconModule, TranslatePipe],
  templateUrl: './signed-out-layout.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SignedOutLayout {
  /** A page with more to show than one form (setting up a second factor) takes a wider card. */
  readonly wide = input(false, { transform: booleanAttribute });
  protected readonly brand = inject(Brand);
}
