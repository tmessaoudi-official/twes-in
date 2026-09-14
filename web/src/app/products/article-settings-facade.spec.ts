// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { SettingsApi, SettingsRefused } from '../shared/settings/settings-api';
import type { SettingRow } from '../shared/settings/settings-types';
import { ArticleSettings } from './article-settings-facade';

const unit = { key: 'article.default_unit', chain: 'articles' } as SettingRow;

describe('ArticleSettings', () => {
  let api: {
    chain: ReturnType<typeof vi.fn>;
    change: ReturnType<typeof vi.fn>;
    reset: ReturnType<typeof vi.fn>;
  };
  let facade: ArticleSettings;

  beforeEach(() => {
    api = {
      chain: vi.fn().mockResolvedValue([unit]),
      change: vi.fn().mockResolvedValue(undefined),
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

    expect(await facade.save('c1', category, [{ key: unit.key, value: 'HUR' }])).toBe(true);

    expect(api.change).toHaveBeenCalledWith(
      'c1',
      unit.key,
      'product_category',
      'HUR',
      undefined,
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

  it('names what the API refused', async () => {
    api.change.mockRejectedValue(new SettingsRefused('invalid'));

    expect(await facade.save('c1', { productId: 'p1' }, [{ key: unit.key, value: 'x' }])).toBe(
      false,
    );
    expect(facade.error()).toBe('invalid');
  });
});
