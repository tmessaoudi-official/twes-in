// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type { DocumentDesign, PreviewState } from './document-design-types';

/** Why the API gave no picture, as the preview names it. */
export class PreviewRefused extends Error {
  constructor(readonly state: Exclude<PreviewState, 'loading' | 'ready'>) {
    super(state);
  }
}

/** The HTTP edge of the document design page: its one call, the preview, which answers a picture rather than JSON. */
@Injectable({ providedIn: 'root' })
export class DocumentDesignApi {
  private readonly http = inject(HttpClient);

  /**
   * The company's latest invoice in a design, its first page as a `data:` address: the page's policy shows pictures
   * from its own origin or as data, and a picture fetched here can say why it failed, which an `<img>` cannot.
   */
  async preview(companyId: string, design: DocumentDesign): Promise<string> {
    try {
      const picture = await firstValueFrom(
        this.http.get(`/api/companies/${encodeURIComponent(companyId)}/invoice-design-preview`, {
          params: {
            layout: design.layout,
            accent: design.accent,
            ...(design.logoWidth === null ? {} : { logoWidth: design.logoWidth }),
            ...(design.logoHeight === null ? {} : { logoHeight: design.logoHeight }),
            logoProportions: design.logoKeepsProportions ? 'keep' : 'free',
          },
          responseType: 'blob',
        }),
      );
      return await dataAddressOf(picture);
    } catch (error) {
      throw new PreviewRefused(stateOf(error));
    }
  }

  /**
   * The company logo's own proportions, its width over its height, which a locked size keeps; null when the company has
   * no logo yet, or the picture cannot be read here.
   */
  async logoRatio(companyId: string): Promise<number | null> {
    try {
      const picture = await firstValueFrom(
        this.http.get(`/api/companies/${encodeURIComponent(companyId)}/logo`, {
          responseType: 'blob',
        }),
      );
      if (typeof createImageBitmap !== 'function') return null;
      const bitmap = await createImageBitmap(picture);
      const ratio = bitmap.height > 0 ? bitmap.width / bitmap.height : null;
      bitmap.close();
      return ratio;
    } catch {
      return null;
    }
  }
}

function stateOf(error: unknown): Exclude<PreviewState, 'loading' | 'ready'> {
  if (!(error instanceof HttpErrorResponse)) return 'failed';
  if (error.status === 0) return 'network';
  // The company is the one the page is open on: its 404 is that it has no invoice yet (`nothing_to_preview`).
  if (error.status === 404) return 'nothing';
  if (error.status === 403) return 'forbidden';
  return 'failed';
}

function dataAddressOf(picture: Blob): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result));
    reader.onerror = () => reject(reader.error ?? new Error('The picture could not be read.'));
    reader.readAsDataURL(picture);
  });
}
