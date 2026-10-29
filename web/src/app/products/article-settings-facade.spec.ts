// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { SettingsApi, SettingsRefused } from '../shared/settings/settings-api';
import type { SettingRow } from '../shared/settings/settings-types';
import { ArticleSettings } from './article-settings-facade';

const unit = { key: 'article.default_unit', chain: 'articles' } as SettingRow;

describe('ArticleSettings', () => {
  let api: {
    chain: ReturnType<typeof vi.fn>;
    changeAll: ReturnType<typeof vi.fn>;
    reset: ReturnType<typeof vi.fn>;
  };
  let facade: ArticleSettings;

  beforeEach(() => {
    api = {
      chain: vi.fn().mockResolvedValue([unit]),
      changeAll: vi.fn().mockResolvedValue(undefined),
      reset: vi.fn().mockResolvedValue(undefined),
    };
    TestBed.configureTestingModule({ providers: [{ provide: SettingsApi, useValue: api }] });
    facade = TestBed.inject(ArticleSettings);
  });

  it('reads the articles chain as the product sees it', async () => {
    await facade.load('c1', { productId: 'p1' });

    expect(api.chain).toHaveBeenCalledWith('c1', 'articles', { productId: 'p1' });
    expect(facade.rows()).toEqual([unit]);
    expect(facade.error()).toBeNull();
  });

  it("stores each change at the category's level, naming the category, then reads again", async () => {
    const category = { productCategoryId: 'k1' };

    expect(await facade.save('c1', category, [{ key: unit.key, value: 'HUR', version: 1 }])).toBe(
      true,
    );

    expect(api.changeAll).toHaveBeenCalledWith(
      'c1',
      'product_category',
      [{ key: unit.key, value: 'HUR', version: 1 }],
      category,
    );
    expect(api.chain).toHaveBeenCalledWith('c1', 'articles', category);
  });

  it("forgets the product's own value", async () => {
    expect(await facade.reset('c1', { productId: 'p1' }, unit.key)).toBe(true);

    expect(api.reset).toHaveBeenCalledWith('c1', unit.key, 'product', undefined, {
      productId: 'p1',
    });
  });

  it('stores and forgets a role’s own value at the role level, naming the role', async () => {
    const role = { roleId: 'r1' };
    const change = { key: 'stock.sale_without_stock', value: 'refuse', version: 0 };

    expect(await facade.save('c1', role, [change])).toBe(true);
    expect(await facade.reset('c1', role, change.key)).toBe(true);

    expect(api.changeAll).toHaveBeenCalledWith('c1', 'role', [change], role);
    expect(api.reset).toHaveBeenCalledWith('c1', change.key, 'role', undefined, role);
    expect(api.chain).toHaveBeenCalledWith('c1', 'articles', role);
  });

  it('names what the API refused', async () => {
    api.changeAll.mockRejectedValue(new SettingsRefused('invalid'));

    expect(
      await facade.save('c1', { productId: 'p1' }, [{ key: unit.key, value: 'x', version: 1 }]),
    ).toBe(false);
    expect(facade.error()).toBe('invalid');
  });
});
