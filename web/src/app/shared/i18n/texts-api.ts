// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpBackend, HttpClient } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import type { TranslationObject } from '@ngx-translate/core';
import { firstValueFrom } from 'rxjs';
import type { ScreenOverrides } from './text-overrides';

/** The screen texts files and their wording: the only code here that knows where they are served. */
@Injectable({ providedIn: 'root' })
export class TextsApi {
  private readonly http = inject(HttpClient);
  // Past the interceptors: reading the wording never signs anyone out nor shows the activity bar, and a failure leaves
  // the shipped words, which every screen can show.
  private readonly quiet = new HttpClient(inject(HttpBackend));

  /** A screen texts file, `<prefix><language>.json`: the language's own under `/i18n/`, a page's under `/i18n/<name>/`. */
  file(prefix: string, language: string): Promise<TranslationObject> {
    return firstValueFrom(this.http.get<TranslationObject>(`${prefix}${language}.json`));
  }

  /** A part's texts in a language, from `/i18n/<name>/<language>.json`. */
  part(name: string, language: string): Promise<TranslationObject> {
    return this.file(`/i18n/${name}/`, language);
  }

  /** The screens' texts the installation words otherwise, with the company's over them when one is named. */
  overrides(company: string | null, language: string): Promise<ScreenOverrides> {
    const url =
      company === null ? `/api/texts/${language}` : `/api/companies/${company}/texts/${language}`;
    return firstValueFrom(this.quiet.get<ScreenOverrides>(url));
  }
}
