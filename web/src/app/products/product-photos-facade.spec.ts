// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { ProductPhotosApi, ProductPhotosRefused } from './product-photos-api';
import { ProductPhotos } from './product-photos-facade';
import type { ProductPhotoRow } from './product-photos-types';

const photo = (id: string, main = false): ProductPhotoRow => ({
  id,
  name: `${id}.jpg`,
  mime: 'image/jpeg',
  size: 1,
  width: 1,
  height: 1,
  main,
  createdAt: '',
});

describe('ProductPhotos', () => {
  const api = {
    list: vi.fn(),
    add: vi.fn(),
    order: vi.fn(),
    markMain: vi.fn(),
    remove: vi.fn(),
    restore: vi.fn(),
    url: vi.fn(),
  };
  let gallery: ProductPhotos;

  beforeEach(() => {
    for (const call of Object.values(api)) call.mockReset();
    api.list.mockResolvedValue([photo('a', true), photo('b'), photo('c')]);
    TestBed.configureTestingModule({
      providers: [ProductPhotos, { provide: ProductPhotosApi, useValue: api }],
    });
    gallery = TestBed.inject(ProductPhotos);
  });

  it('holds the gallery read for one product, and says which', async () => {
    await gallery.load('c1', 'p1');

    expect(gallery.photos().map((each) => each.id)).toEqual(['a', 'b', 'c']);
    expect(gallery.main()?.id).toBe('a');
    expect(gallery.loadedFor()).toBe('p1');
  });

  it('moves a photo one step by sending the whole order, and nothing past an end', async () => {
    await gallery.load('c1', 'p1');
    api.order.mockResolvedValue(undefined);

    expect(await gallery.move('c1', 'p1', 'b', 1)).toBe(true);
    expect(api.order).toHaveBeenCalledWith('c1', 'p1', ['a', 'c', 'b']);

    api.order.mockClear();
    expect(await gallery.move('c1', 'p1', 'a', -1)).toBe(false);
    expect(api.order).not.toHaveBeenCalled();
  });

  it('shows a drag’s order at once and the API’s once it answers', async () => {
    await gallery.load('c1', 'p1');
    let answer!: () => void;
    api.order.mockReturnValue(new Promise<void>((resolve) => (answer = resolve)));
    api.list.mockResolvedValue([photo('c'), photo('a', true), photo('b')]);

    const done = gallery.order('c1', 'p1', ['c', 'a', 'b']);
    expect(gallery.photos().map((each) => each.id)).toEqual(['c', 'a', 'b']);
    answer();
    await done;

    expect(gallery.photos().map((each) => each.id)).toEqual(['c', 'a', 'b']);
  });

  it('keeps the API’s refusal and reads the gallery again after it', async () => {
    await gallery.load('c1', 'p1');
    api.add.mockRejectedValue(
      new ProductPhotosRefused({ code: 'too_many_photos', params: { max: 6 } }),
    );
    api.list.mockClear();

    expect(await gallery.add('c1', 'p1', new File(['x'], 'x.jpg'))).toBe(false);

    expect(gallery.refusal()).toEqual({ code: 'too_many_photos', params: { max: 6 } });
    expect(api.list).toHaveBeenCalledWith('c1', 'p1');
  });

  it('removes and puts back through the API, reading the gallery after each', async () => {
    api.remove.mockResolvedValue(undefined);
    api.restore.mockResolvedValue(undefined);

    expect(await gallery.remove('c1', 'p1', 'b')).toBe(true);
    expect(await gallery.restore('c1', 'p1', 'b')).toBe(true);

    expect(api.remove).toHaveBeenCalledWith('c1', 'p1', 'b');
    expect(api.restore).toHaveBeenCalledWith('c1', 'p1', 'b');
    expect(api.list).toHaveBeenCalledTimes(2);
    expect(gallery.refusal()).toBeNull();
  });
});
