// SPDX-License-Identifier: AGPL-3.0-or-later

import { computed, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { Feedback } from '../shared/feedback/feedback';
import { LiveChanges } from '../shared/realtime/live-changes';
import { Session } from '../shared/session/session';
import { provideQuietFeedback, RecordedFeedback, successToasts } from '../shared/testing/feedback';
import { ProductPhotos } from './product-photos-facade';
import { ProductPhotosSection } from './product-photos';
import type { ProductPhotoRefusal, ProductPhotoRow } from './product-photos-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      products: {
        photos: {
          main: 'Principale',
          errors: { too_large: 'Au plus {{megabytes}} Mo.', too_many_photos: 'Au plus {{max}}.' },
        },
      },
    });
  }
}

const photo = (id: string, main = false): ProductPhotoRow => ({
  id,
  name: `${id}.jpg`,
  mime: 'image/jpeg',
  size: 1000,
  width: 1200,
  height: 800,
  main,
  createdAt: '2026-10-09T01:00:00+02:00',
});

describe('ProductPhotosSection', () => {
  const photos = signal<readonly ProductPhotoRow[]>([]);
  const refusal = signal<ProductPhotoRefusal | null>(null);
  const facade = {
    photos: photos.asReadonly(),
    main: computed(() => photos().find((each) => each.main) ?? null),
    busy: signal(false).asReadonly(),
    refusal: refusal.asReadonly(),
    load: vi.fn(),
    add: vi.fn(),
    markMain: vi.fn(),
    move: vi.fn(),
    order: vi.fn(),
    remove: vi.fn(),
    restore: vi.fn(),
    url: (companyId: string, productId: string, photoId: string, size: string) =>
      `/api/companies/${companyId}/products/${productId}/photos/${photoId}/content?size=${size}`,
  };
  const live = { reloadOn: vi.fn() };
  const auth = { me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }) };
  let fixture: ComponentFixture<ProductPhotosSection>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  async function open(inputs: { readOnly?: boolean; maxPhotos?: number } = {}): Promise<void> {
    fixture = TestBed.createComponent(ProductPhotosSection);
    fixture.componentRef.setInput('productId', 'p1');
    fixture.componentRef.setInput('readOnly', inputs.readOnly ?? false);
    fixture.componentRef.setInput('maxPhotos', inputs.maxPhotos ?? 6);
    fixture.componentRef.setInput('maxBytes', 5242880);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  beforeEach(() => {
    photos.set([]);
    refusal.set(null);
    for (const call of [facade.load, facade.add, facade.markMain, facade.move, facade.order]) {
      call.mockReset().mockResolvedValue(true);
    }
    facade.remove.mockReset().mockResolvedValue(true);
    facade.restore.mockReset().mockResolvedValue(true);
    live.reloadOn.mockReset();
    TestBed.configureTestingModule({
      imports: [ProductPhotosSection],
      providers: [
        ...provideQuietFeedback(),
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: ProductPhotos, useValue: facade },
        { provide: LiveChanges, useValue: live },
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
      ],
    });
  });

  it('reads the gallery and reads it again when the product changes elsewhere', async () => {
    await open();

    expect(facade.load).toHaveBeenCalledWith('c1', 'p1');
    expect(live.reloadOn).toHaveBeenCalledWith(
      ['product'],
      expect.any(Function),
      expect.anything(),
    );
  });

  it('shows each photo in its order from its large copy, the main one marked', async () => {
    photos.set([photo('a'), photo('b', true)]);
    await open();

    const images = [...fixture.nativeElement.querySelectorAll('img')] as HTMLImageElement[];
    expect(images.map((image) => image.getAttribute('src'))).toEqual([
      '/api/companies/c1/products/p1/photos/a/content?size=large',
      '/api/companies/c1/products/p1/photos/b/content?size=large',
    ]);
    expect(q('product-photo-main-b')?.textContent).toContain('Principale');
    expect(q('product-photo-main-a')).toBeNull();
    expect(q('product-photo-make-main-a')).not.toBeNull();
    expect(q('product-photo-make-main-b')).toBeNull();
  });

  it('moves a photo with buttons, the first unable to go earlier and the last later', async () => {
    photos.set([photo('a', true), photo('b')]);
    await open();

    expect((q('product-photo-earlier-a') as HTMLButtonElement).disabled).toBe(true);
    expect((q('product-photo-later-b') as HTMLButtonElement).disabled).toBe(true);
    (q('product-photo-later-a') as HTMLButtonElement).click();
    await fixture.whenStable();

    expect(facade.move).toHaveBeenCalledWith('c1', 'p1', 'a', 1);
    expect(successToasts()).toEqual(['products.photos.reordered']);
  });

  it('marks another photo main', async () => {
    photos.set([photo('a', true), photo('b')]);
    await open();

    (q('product-photo-make-main-b') as HTMLButtonElement).click();
    await fixture.whenStable();

    expect(facade.markMain).toHaveBeenCalledWith('c1', 'p1', 'b');
    expect(successToasts()).toEqual(['products.photos.main_set']);
  });

  it('removes a photo at once and puts it back from the toast', async () => {
    photos.set([photo('a', true)]);
    await open();

    (q('product-photo-remove-a') as HTMLButtonElement).click();
    await fixture.whenStable();

    expect(facade.remove).toHaveBeenCalledWith('c1', 'p1', 'a');
    const said = (TestBed.inject(Feedback) as RecordedFeedback).said;
    expect(said[0]?.key).toBe('products.photos.removed');
    expect(said[0]?.action?.key).toBe('products.photos.undo');
    said[0]?.action?.run();
    await fixture.whenStable();
    expect(facade.restore).toHaveBeenCalledWith('c1', 'p1', 'a');
    expect(successToasts()).toEqual(['products.photos.removed', 'products.photos.restored']);
  });

  it('sends a photo picked from the device', async () => {
    await open();
    const file = new File(['x'], 'perceuse.jpg', { type: 'image/jpeg' });
    const input = q('product-photo-add') as HTMLInputElement;
    Object.defineProperty(input, 'files', { value: [file] });

    input.dispatchEvent(new Event('change'));
    await fixture.whenStable();

    expect(facade.add).toHaveBeenCalledWith('c1', 'p1', file);
    expect(successToasts()).toEqual(['products.photos.added']);
  });

  it('offers no more room once the gallery is full', async () => {
    photos.set([photo('a', true), photo('b')]);
    await open({ maxPhotos: 2 });

    expect(q('product-photos-full')).not.toBeNull();
    expect(q('product-photo-add')).toBeNull();
  });

  it('says a refusal with its numbers, from the screen’s own limits where the API named none', async () => {
    refusal.set({ code: 'too_large', params: {} });
    await open();

    expect(q('product-photos-error')?.textContent?.trim()).toBe('Au plus 5 Mo.');
  });

  it('shows the photos and changes nothing for a reader', async () => {
    photos.set([photo('a', true), photo('b')]);
    await open({ readOnly: true });

    expect(fixture.nativeElement.querySelectorAll('img').length).toBe(2);
    for (const testId of [
      'product-photo-add',
      'product-photo-remove-a',
      'product-photo-later-a',
      'product-photo-make-main-b',
      'product-photo-drag',
    ]) {
      expect(q(testId)).toBeNull();
    }
  });
});
