// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatMenuModule } from '@angular/material/menu';
import { TranslatePipe } from '@ngx-translate/core';
import { Label } from '../a11y/label';
import { LANGUAGE_NAMES, LanguageFacade, SUPPORTED_LANGUAGES } from './language-facade';

/**
 * The interface language: the current one's code on the button, and each language named in itself in the menu, with
 * its `lang` so a screen reader pronounces it rightly. No flags (docs/SPEC.md § 7, 2026-09-16 review).
 */
@Component({
  selector: 'app-language-menu',
  imports: [MatButtonModule, MatIconModule, MatMenuModule, TranslatePipe, Label],
  template: `
    <button
      mat-button
      type="button"
      class="twes-language-button"
      [matMenuTriggerFor]="menu"
      [appLabel]="'appearance.language_menu' | translate: { current: names[language.current()] }"
      data-testid="language-menu"
    >
      <mat-icon aria-hidden="true">translate</mat-icon>
      <span aria-hidden="true" class="font-semibold uppercase">{{ language.current() }}</span>
    </button>
    <mat-menu #menu="matMenu" xPosition="before">
      @for (lang of languages; track lang) {
        <button
          mat-menu-item
          type="button"
          role="menuitemradio"
          [attr.aria-checked]="language.current() === lang"
          (click)="language.use(lang)"
          [attr.data-testid]="'language-' + lang"
        >
          <span class="flex min-w-40 items-center gap-3">
            <span
              aria-hidden="true"
              class="w-7 rounded border border-outline-variant text-center text-xs font-semibold uppercase text-on-surface-variant"
              >{{ lang }}</span
            >
            <span class="flex-1" [attr.lang]="lang">{{ names[lang] }}</span>
            <span
              aria-hidden="true"
              class="material-symbols-outlined text-primary"
              [class.invisible]="language.current() !== lang"
              >check</span
            >
          </span>
        </button>
      }
    </mat-menu>
  `,
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class LanguageMenu {
  protected readonly language = inject(LanguageFacade);
  protected readonly languages = SUPPORTED_LANGUAGES;
  protected readonly names = LANGUAGE_NAMES;
}
