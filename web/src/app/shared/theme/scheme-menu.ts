// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, inject } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatMenuModule } from '@angular/material/menu';
import { TranslatePipe } from '@ngx-translate/core';
import { Label } from '../a11y/label';
import { type SchemePreference, ThemeFacade } from './theme-facade';

const SCHEMES: readonly { value: SchemePreference; icon: string }[] = [
  { value: 'auto', icon: 'contrast' },
  { value: 'light', icon: 'light_mode' },
  { value: 'dark', icon: 'dark_mode' },
];

/**
 * Automatique · Clair · Sombre, on every screen: an icon showing the current choice, opening the three with a check
 * on the chosen one (docs/SPEC.md § 7, 2026-09-16 review).
 */
@Component({
  selector: 'app-scheme-menu',
  imports: [MatButtonModule, MatIconModule, MatMenuModule, TranslatePipe, Label],
  template: `
    <button
      mat-icon-button
      type="button"
      [matMenuTriggerFor]="menu"
      [appLabel]="
        'appearance.scheme_menu'
          | translate: { current: ('appearance.schemes.' + theme.preference() | translate) }
      "
      data-testid="scheme-menu"
    >
      <mat-icon aria-hidden="true">{{ icon() }}</mat-icon>
    </button>
    <mat-menu #menu="matMenu" xPosition="before">
      @for (scheme of schemes; track scheme.value) {
        <button
          mat-menu-item
          type="button"
          role="menuitemradio"
          [attr.aria-checked]="theme.preference() === scheme.value"
          (click)="theme.setScheme(scheme.value)"
          [attr.data-testid]="'scheme-' + scheme.value"
        >
          <mat-icon aria-hidden="true">{{ scheme.icon }}</mat-icon>
          <span class="flex items-center gap-6">
            <span class="flex-1">{{ 'appearance.schemes.' + scheme.value | translate }}</span>
            <span
              aria-hidden="true"
              class="material-symbols-outlined text-primary"
              [class.invisible]="theme.preference() !== scheme.value"
              >check</span
            >
          </span>
        </button>
      }
    </mat-menu>
  `,
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SchemeMenu {
  protected readonly theme = inject(ThemeFacade);
  protected readonly schemes = SCHEMES;
  protected readonly icon = computed(
    () => SCHEMES.find((scheme) => scheme.value === this.theme.preference())?.icon ?? 'contrast',
  );
}
