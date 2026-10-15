// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import type { TranslationObject } from '@ngx-translate/core';
import { firstValueFrom } from 'rxjs';

/** The screen texts files beside the language's own: the only code here that knows where they are served. */
@Injectable({ providedIn: 'root' })
export class TextsApi {
  private readonly http = inject(HttpClient);

  /** A part's texts in a language, from `/i18n/<name>/<language>.json`. */
  part(name: string, language: string): Promise<TranslationObject> {
    return firstValueFrom(this.http.get<TranslationObject>(`/i18n/${name}/${language}.json`));
  }
}
