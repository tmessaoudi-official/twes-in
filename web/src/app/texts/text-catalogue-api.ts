// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type {
  TextCataloguePage,
  TextOverride,
  TextOverrideChange,
  TextOverrideRefusal,
} from '../api/types.gen';
import { isRecordChanged } from '../shared/errors/record-changed';
import { refusedFor } from '../shared/errors/said-already';
import {
  type SavedText,
  type TextLevel,
  type TextPage,
  type TextQuery,
  TEXT_REFUSALS,
  TextRefused,
  type TextRow,
} from './texts-types';

/** The HTTP edge of « Textes »: the only code here that knows where a level's texts are read and written. */
@Injectable({ providedIn: 'root' })
export class TextCatalogueApi {
  private readonly http = inject(HttpClient);

  async page(level: TextLevel, language: string, query: TextQuery): Promise<TextPage> {
    let params = new HttpParams().set('page', query.page).set('itemsPerPage', query.itemsPerPage);
    if (query.words.trim() !== '') params = params.set('q', query.words.trim());
    if (query.place !== null) params = params.set('domain', query.place);
    if (query.changed) params = params.set('changed', '1');
    const found = await firstValueFrom(
      this.http.get<TextCataloguePage>(`${base(level, language)}/catalogue`, { params }),
    );
    return { rows: found.items.map((item): TextRow => ({ ...item })), total: found.total };
  }

  /** Words the text otherwise at this level, over the version read; a refusal is a `TextRefused`. */
  async write(
    level: TextLevel,
    language: string,
    text: Pick<TextRow, 'domain' | 'key'>,
    value: string,
    version: number,
  ): Promise<SavedText> {
    const body: TextOverrideChange = { value, version };
    try {
      const saved = await firstValueFrom(
        this.http.put<TextOverride>(address(level, language, text), body),
      );
      return { value: saved.value, version: saved.version };
    } catch (error) {
      throw refused(error);
    }
  }

  /** Takes this level's wording away: the text reads as the level under it, or as shipped. */
  async reset(
    level: TextLevel,
    language: string,
    text: Pick<TextRow, 'domain' | 'key'>,
  ): Promise<void> {
    try {
      await firstValueFrom(this.http.delete<null>(address(level, language, text)));
    } catch (error) {
      throw refused(error);
    }
  }
}

function base(level: TextLevel, language: string): string {
  return level.kind === 'installation'
    ? `/api/platform/texts/${language}`
    : `/api/companies/${level.companyId}/texts/${language}`;
}

/** A domain and a key are written as the API's routes require them, so neither is escaped. */
function address(
  level: TextLevel,
  language: string,
  text: Pick<TextRow, 'domain' | 'key'>,
): string {
  return `${base(level, language)}/${text.domain}/${text.key}`;
}

/** The API's refusal as the screen says it, or the error itself when it is none. */
function refused(error: unknown): unknown {
  if (!(error instanceof HttpErrorResponse)) return error;
  if (error.status === 409 && isRecordChanged(error.error)) {
    return refusedFor(error, new TextRefused('changed'));
  }
  const code = (error.error as Partial<TextOverrideRefusal> | null)?.error;
  const known = TEXT_REFUSALS.find((each) => each === code);
  return error.status === 422 && known !== undefined
    ? refusedFor(error, new TextRefused(known))
    : error;
}
