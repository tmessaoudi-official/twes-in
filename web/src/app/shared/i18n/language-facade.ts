// SPDX-License-Identifier: AGPL-3.0-or-later

import { DOCUMENT, inject, Injectable, signal } from '@angular/core';
import { TranslateService } from '@ngx-translate/core';
import { firstValueFrom } from 'rxjs';

/** French first (Tunisia, France), English second; Arabic and right-to-left come after the POC. */
export const SUPPORTED_LANGUAGES = ['fr', 'en'] as const;
export type Language = (typeof SUPPORTED_LANGUAGES)[number];

export class UnsupportedLanguage extends Error {
  constructor(readonly value: string) {
    super(`The language "${value}" is not one twes-in ships (${SUPPORTED_LANGUAGES.join(', ')}).`);
    this.name = 'UnsupportedLanguage';
  }
}

export function isSupportedLanguage(value: string): value is Language {
  return (SUPPORTED_LANGUAGES as readonly string[]).includes(value);
}

/** The interface language. Switching loads the translations first, then moves the document's `lang` with them. */
@Injectable({ providedIn: 'root' })
export class LanguageFacade {
  private readonly translate = inject(TranslateService);
  private readonly document = inject(DOCUMENT);
  private readonly currentSignal = signal<Language>(this.initial());

  readonly current = this.currentSignal.asReadonly();

  async use(language: string): Promise<void> {
    if (!isSupportedLanguage(language)) {
      throw new UnsupportedLanguage(language);
    }
    await firstValueFrom(this.translate.use(language));
    this.currentSignal.set(language);
    this.document.documentElement.lang = language;
  }

  private initial(): Language {
    const configured = this.translate.getCurrentLang() ?? '';
    return isSupportedLanguage(configured) ? configured : SUPPORTED_LANGUAGES[0];
  }
}
