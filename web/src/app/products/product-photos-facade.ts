// SPDX-License-Identifier: AGPL-3.0-or-later

import { computed, inject, Injectable, signal } from '@angular/core';
import { moved } from './product-home-order';
import { ProductPhotosApi, ProductPhotosRefused } from './product-photos-api';
import type { PhotoSize, ProductPhotoRefusal, ProductPhotoRow } from './product-photos-types';

/**
 * One product's photos: its gallery in order, one main. Its own facade, as each tab of the product has one: a gallery
 * that fails to load or refuses a photo says so about itself, not about the whole product.
 */
@Injectable()
export class ProductPhotos {
  private readonly api = inject(ProductPhotosApi);
  private readonly photosSignal = signal<readonly ProductPhotoRow[]>([]);
  private readonly busySignal = signal(false);
  private readonly refusalSignal = signal<ProductPhotoRefusal | null>(null);
  private readonly loadedForSignal = signal<string | null>(null);

  readonly photos = this.photosSignal.asReadonly();
  readonly busy = this.busySignal.asReadonly();
  /** Why the last change was refused; null once another succeeds. */
  readonly refusal = this.refusalSignal.asReadonly();
  readonly main = computed(() => this.photosSignal().find((photo) => photo.main) ?? null);
  /** The product whose gallery is held: what is held answers for that product only. */
  readonly loadedFor = this.loadedForSignal.asReadonly();

  async load(companyId: string, productId: string): Promise<void> {
    await this.run(async () => {
      this.photosSignal.set(await this.api.list(companyId, productId));
      this.loadedForSignal.set(productId);
    });
  }

  /** A photo sent, last in the gallery; main when it is the first. */
  async add(companyId: string, productId: string, file: File): Promise<boolean> {
    return this.change(companyId, productId, () => this.api.add(companyId, productId, file));
  }

  async markMain(companyId: string, productId: string, photoId: string): Promise<boolean> {
    return this.change(companyId, productId, () =>
      this.api.markMain(companyId, productId, photoId),
    );
  }

  /** A photo one step towards the start (−1) or the end (1); which one is main does not change. */
  async move(
    companyId: string,
    productId: string,
    photoId: string,
    step: -1 | 1,
  ): Promise<boolean> {
    const ids = this.photosSignal().map((photo) => photo.id);
    const next = moved(ids, photoId, step);
    return next.every((id, index) => id === ids[index])
      ? false
      : this.order(companyId, productId, next);
  }

  /** The gallery in this order, every photo named once: what a drag drops. */
  async order(companyId: string, productId: string, ids: readonly string[]): Promise<boolean> {
    // Shown at once in the order asked, as the drag left it; the API's answer replaces it.
    const byId = new Map(this.photosSignal().map((photo) => [photo.id, photo]));
    const arranged = ids.flatMap((id) => byId.get(id) ?? []);
    if (arranged.length === byId.size) this.photosSignal.set(arranged);
    return this.change(companyId, productId, () => this.api.order(companyId, productId, ids));
  }

  async remove(companyId: string, productId: string, photoId: string): Promise<boolean> {
    return this.change(companyId, productId, () => this.api.remove(companyId, productId, photoId));
  }

  /** A removed photo back where it was: what « Annuler » on the toast does. */
  async restore(companyId: string, productId: string, photoId: string): Promise<boolean> {
    return this.change(companyId, productId, () => this.api.restore(companyId, productId, photoId));
  }

  url(companyId: string, productId: string, photoId: string, size: PhotoSize): string {
    return this.api.url(companyId, productId, photoId, size);
  }

  private async change(
    companyId: string,
    productId: string,
    call: () => Promise<unknown>,
  ): Promise<boolean> {
    return this.run(async () => {
      try {
        await call();
      } finally {
        // What the gallery holds now, whoever else changed it meanwhile.
        this.photosSignal.set(await this.api.list(companyId, productId));
      }
    });
  }

  private async run(work: () => Promise<void>): Promise<boolean> {
    this.busySignal.set(true);
    this.refusalSignal.set(null);
    try {
      await work();
      return true;
    } catch (error) {
      this.refusalSignal.set(
        error instanceof ProductPhotosRefused ? error.refusal : { code: 'network', params: {} },
      );
      return false;
    } finally {
      this.busySignal.set(false);
    }
  }
}
