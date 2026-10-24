// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, type Provider } from '@angular/core';
import { TranslateLoader, type TranslationObject } from '@ngx-translate/core';
import { from, type Observable } from 'rxjs';
import { laid, TextOverrides } from './text-overrides';
import { TextsApi } from './texts-api';

/**
 * A screen texts file as the release ships it, with the texts the installation, then the company, word otherwise laid
 * over it before any screen reads it: the shipped file and the API's wording are fetched side by side.
 */
export class OverriddenLoader implements TranslateLoader {
  constructor(
    private readonly api: TextsApi,
    private readonly overrides: TextOverrides,
    private readonly prefix: string,
    private readonly file: string,
  ) {}

  getTranslation(language: string): Observable<TranslationObject> {
    return from(this.laid(language));
  }

  async laid(language: string): Promise<TranslationObject> {
    const [texts, overrides] = await Promise.all([
      this.api.file(this.prefix, language),
      this.overrides.of(language),
    ]);
    return laid(texts, overrides[this.file]);
  }
}

/** The loader of the files under `prefix` (`/i18n/`, `/i18n/<part>/`), worded as `file` (`screen`, `screen:<part>`). */
export function provideOverriddenLoader(prefix: string, file: string): Provider {
  return {
    provide: TranslateLoader,
    useFactory: () => new OverriddenLoader(inject(TextsApi), inject(TextOverrides), prefix, file),
  };
}
