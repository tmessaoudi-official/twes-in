// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type { ProductPhotoProductPhotoRead } from '../api/types.gen';
import {
  PHOTO_REFUSALS,
  type PhotoSize,
  type ProductPhotoRefusal,
  type ProductPhotoRow,
} from './product-photos-types';

/** Thrown when the API refuses a photo or a change to the gallery; carries what the screen says. */
export class ProductPhotosRefused extends Error {
  constructor(readonly refusal: ProductPhotoRefusal) {
    super(refusal.code);
  }
}

/** The HTTP edge of a product's photos: the only code here that knows their endpoints and generated types. */
@Injectable({ providedIn: 'root' })
export class ProductPhotosApi {
  private readonly http = inject(HttpClient);

  async list(companyId: string, productId: string): Promise<ProductPhotoRow[]> {
    return this.guard(async () =>
      (
        await firstValueFrom(
          this.http.get<ProductPhotoProductPhotoRead[]>(photosPath(companyId, productId)),
        )
      ).map(toPhoto),
    );
  }

  /** A multipart part named `file`; 422 with a code for a photo not kept, 413 when the proxy finds it too large. */
  async add(companyId: string, productId: string, file: File): Promise<ProductPhotoRow> {
    const body = new FormData();
    body.append('file', file, file.name);
    return this.guard(async () =>
      toPhoto(
        await firstValueFrom(
          this.http.post<ProductPhotoProductPhotoRead>(photosPath(companyId, productId), body),
        ),
      ),
    );
  }

  /** Every photo of the gallery once, first to last; 409 when the gallery changed since it was read. */
  async order(companyId: string, productId: string, photoIds: readonly string[]): Promise<void> {
    await this.guard(async () =>
      firstValueFrom(
        this.http.post(`${photosPath(companyId, productId)}/order`, { photoIds: [...photoIds] }),
      ),
    );
  }

  async markMain(companyId: string, productId: string, photoId: string): Promise<void> {
    await this.guard(async () =>
      firstValueFrom(this.http.post(`${photoPath(companyId, productId, photoId)}/main`, null)),
    );
  }

  async remove(companyId: string, productId: string, photoId: string): Promise<void> {
    await this.guard(async () =>
      firstValueFrom(this.http.delete(photoPath(companyId, productId, photoId))),
    );
  }

  async restore(companyId: string, productId: string, photoId: string): Promise<void> {
    await this.guard(async () =>
      firstValueFrom(this.http.post(`${photoPath(companyId, productId, photoId)}/restore`, null)),
    );
  }

  /** Where an `<img>` reads a photo's picture: a same-origin address the session cookie reaches. */
  url(companyId: string, productId: string, photoId: string, size: PhotoSize): string {
    return `${photoPath(companyId, productId, photoId)}/content?size=${size}`;
  }

  private async guard<T>(call: () => Promise<T>): Promise<T> {
    try {
      return await call();
    } catch (error) {
      throw new ProductPhotosRefused(refusalOf(error));
    }
  }
}

/** The API's own code when it gave one; otherwise what its status says. */
export function refusalOf(error: unknown): ProductPhotoRefusal {
  if (!(error instanceof HttpErrorResponse) || error.status === 0) {
    return { code: 'network', params: {} };
  }
  const body: unknown = error.error;
  if (typeof body === 'object' && body !== null && 'code' in body) {
    const code = PHOTO_REFUSALS.find((known) => known === body.code);
    if (code !== undefined) {
      const params = paramsOf(body);
      const maxBytes = params['maxBytes'];
      // Said in megabytes, as a person reads a file's size.
      if (maxBytes !== undefined) params['megabytes'] = Math.round(maxBytes / 1048576);
      return { code, params };
    }
  }
  switch (error.status) {
    case 404:
      return { code: 'not_found', params: {} };
    case 409:
      return { code: 'changed', params: {} };
    case 413:
      return { code: 'too_large', params: {} };
    default:
      return { code: 'not_a_picture', params: {} };
  }
}

function paramsOf(body: object): Record<string, number> {
  const params = 'params' in body ? body.params : null;
  if (typeof params !== 'object' || params === null) return {};
  return Object.fromEntries(
    Object.entries(params).filter(
      (entry): entry is [string, number] => typeof entry[1] === 'number',
    ),
  );
}

const photosPath = (companyId: string, productId: string): string =>
  `/api/companies/${encodeURIComponent(companyId)}/products/${encodeURIComponent(productId)}/photos`;

const photoPath = (companyId: string, productId: string, photoId: string): string =>
  `${photosPath(companyId, productId)}/${encodeURIComponent(photoId)}`;

function toPhoto(raw: ProductPhotoProductPhotoRead): ProductPhotoRow {
  return {
    id: raw.id ?? '',
    name: raw.name ?? '',
    mime: raw.mime ?? '',
    size: raw.size ?? 0,
    width: raw.width ?? 0,
    height: raw.height ?? 0,
    main: raw.main ?? false,
    createdAt: raw.createdAt ?? '',
  };
}
