// SPDX-License-Identifier: AGPL-3.0-or-later

import { DOCUMENT, effect, inject, Injectable, signal } from '@angular/core';
import { TranslateService } from '@ngx-translate/core';
import { firstValueFrom } from 'rxjs';
import { SettingsFacade } from '../settings/settings-facade';
import { type Language, PRESENTATION, SUPPORTED_LANGUAGES } from '../settings/settings-registry';

export { type Language, SUPPORTED_LANGUAGES } from '../settings/settings-registry';

/**
 * Each language named in itself, as a person looks for their own: never a flag, which is a country, and never
 * translated into the current language (docs/SPEC.md § 7, 2026-09-16 review).
 */
export const LANGUAGE_NAMES: Readonly<Record<Language, string>> = {
  fr: 'Français',
  en: 'English',
};

export class UnsupportedLanguage extends Error {
  constructor(readonly value: string) {
    super(`The language "${value}" is not one twes-in ships (${SUPPORTED_LANGUAGES.join(', ')}).`);
    this.name = 'UnsupportedLanguage';
  }
}

export function isSupportedLanguage(value: string): value is Language {
  return (SUPPORTED_LANGUAGES as readonly string[]).includes(value);
}

/**
 * The interface language, remembered as a presentation setting: the browser keeps it before sign-in, the
 * presentation chain after. Switching loads the translations first, then moves the document's `lang` with them.
 */
@Injectable({ providedIn: 'root' })
export class LanguageFacade {
  private readonly translate = inject(TranslateService);
  private readonly document = inject(DOCUMENT);
  private readonly settings = inject(SettingsFacade);
  private readonly chosen = this.settings.value(PRESENTATION.language);
  private readonly currentSignal = signal<Language>(this.initial());
  /** The language being loaded, so a choice is not loaded a second time when its setting echoes back. */
  private target: Language = this.currentSignal();

  readonly current = this.currentSignal.asReadonly();

  constructor() {
    // A language the settings answer later (the account's own, once signed in) is applied as it arrives.
    effect(() => {
      const language = this.chosen();
      if (this.target !== language) {
        void this.apply(language);
      }
    });
  }

  async use(language: string): Promise<void> {
    if (!isSupportedLanguage(language)) {
      throw new UnsupportedLanguage(language);
    }
    this.settings.set(PRESENTATION.language, language);
    await this.apply(language);
  }

  private async apply(language: Language): Promise<void> {
    this.target = language;
    await firstValueFrom(this.translate.use(language));
    this.currentSignal.set(language);
    this.document.documentElement.lang = language;
  }

  private initial(): Language {
    const configured = this.translate.getCurrentLang() ?? '';
    return isSupportedLanguage(configured) ? configured : SUPPORTED_LANGUAGES[0];
  }
}
