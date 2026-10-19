// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, inject } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { FormsModule } from '@angular/forms';
import { TranslateService } from '@ngx-translate/core';
import { Select, type SelectCompact } from '../form/select';
import { LANGUAGE_NAMES, LanguageFacade, SUPPORTED_LANGUAGES } from './language-facade';
import { languageChoices } from './language-options';

/**
 * The interface language in a bar: the one language dropdown, drawn as a small button with the current language's
 * code. Each language is named in itself, in its own `lang` so a screen reader pronounces it rightly, with its name in
 * the screen's language under it. No flags. The button's name ends with the code it shows, which is what a speech
 * user reads off it (WCAG 2.5.3).
 */
@Component({
  selector: 'app-language-menu',
  imports: [FormsModule, Select],
  template: `
    <app-select
      [compact]="compact()"
      [languages]="true"
      [options]="options"
      [ngModel]="language.current()"
      (ngModelChange)="choose($event)"
      testId="language-menu"
    />
  `,
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class LanguageMenu {
  protected readonly language = inject(LanguageFacade);
  private readonly translate = inject(TranslateService);
  /** The button's name is a screen text: it is read again once the texts of a new language arrive. */
  private readonly texts = toSignal(this.translate.onLangChange, { initialValue: null });

  protected readonly options = languageChoices(SUPPORTED_LANGUAGES, 'language');
  protected readonly compact = computed<SelectCompact>(() => {
    this.texts();
    const current = this.language.current();
    return {
      icon: 'translate',
      text: current,
      name: this.translate.instant('appearance.language_menu', {
        current: LANGUAGE_NAMES[current],
        code: current.toUpperCase(),
      }) as string,
    };
  });

  protected async choose(tag: string | null): Promise<void> {
    if (tag !== null && tag !== this.language.current()) {
      await this.language.use(tag);
    }
  }
}
