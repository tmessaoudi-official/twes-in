// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import type { SettingRow, SettingsError } from '../shared/settings/settings-types';
import { ArticleDefaults } from './article-defaults';
import { ArticleSettings } from './article-settings-facade';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({ products: { defaults: { errors: { invalid: 'Valeur refusée.' } } } });
  }
}

// As a product of a category sees it: the category says hours, the product says nothing yet.
const unit: SettingRow = {
  key: 'article.default_unit',
  chain: 'articles',
  type: 'text',
  labelKey: 'settings.article.default_unit',
  module: 'core',
  defaultValue: 'C62',
  value: 'HUR',
  source: 'product_category',
  levels: [{ level: 'product_category', value: 'HUR' }],
  overridableLevels: ['company', 'product_category', 'product', 'document_line'],
  writableLevels: ['product'],
  choices: [],
  min: null,
  max: null,
  maxLength: null,
  pattern: '/^[A-Z0-9]{2,3}$/',
};

describe('ArticleDefaults', () => {
  const rows = signal<readonly SettingRow[]>([unit]);
  const error = signal<SettingsError | null>(null);
  const facade = {
    rows: rows.asReadonly(),
    busy: signal(false).asReadonly(),
    error: error.asReadonly(),
    load: vi.fn(),
    save: vi.fn(),
    reset: vi.fn(),
    clearError: vi.fn(),
  };
  const auth = { me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }) };
  let fixture: ComponentFixture<ArticleDefaults>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  async function open(
    subject: { productId: string } | { productCategoryId: string },
  ): Promise<void> {
    fixture = TestBed.createComponent(ArticleDefaults);
    fixture.componentRef.setInput('subject', subject);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  beforeEach(() => {
    rows.set([unit]);
    error.set(null);
    facade.load.mockReset().mockResolvedValue(undefined);
    facade.save.mockReset().mockResolvedValue(true);
    facade.reset.mockReset().mockResolvedValue(true);
    TestBed.configureTestingModule({
      imports: [ArticleDefaults],
      providers: [
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: ArticleSettings, useValue: facade },
        { provide: AuthFacade, useValue: auth },
      ],
    });
  });

  it("reads the product's articles chain and starts at what its category says", async () => {
    await open({ productId: 'p1' });

    expect(facade.load).toHaveBeenCalledWith('c1', { productId: 'p1' });
    expect((q('field-article__default_unit') as HTMLInputElement).value).toBe('HUR');
  });

  it("stores only what was changed, at the product's level", async () => {
    await open({ productId: 'p1' });
    const input = q('field-article__default_unit') as HTMLInputElement;
    input.value = 'KGM';
    input.dispatchEvent(new Event('input'));
    q('article-defaults-save')!.click();
    await fixture.whenStable();

    expect(facade.save).toHaveBeenCalledWith('c1', { productId: 'p1' }, [
      { key: 'article.default_unit', value: 'KGM' },
    ]);
  });

  it("stores a category's own values at the category's level", async () => {
    rows.set([
      { ...unit, source: null, value: 'C62', levels: [], writableLevels: ['product_category'] },
    ]);
    await open({ productCategoryId: 'k1' });
    const input = q('field-article__default_unit') as HTMLInputElement;
    input.value = 'HUR';
    input.dispatchEvent(new Event('input'));
    q('article-defaults-save')!.click();
    await fixture.whenStable();

    expect(facade.save).toHaveBeenCalledWith('c1', { productCategoryId: 'k1' }, [
      { key: 'article.default_unit', value: 'HUR' },
    ]);
  });

  it('offers to reset a value the product holds, and says why a change was refused', async () => {
    rows.set([{ ...unit, levels: [...unit.levels, { level: 'product', value: 'KGM' }] }]);
    error.set('invalid');
    await open({ productId: 'p1' });

    q('article-defaults-reset-article.default_unit')!.click();
    expect(facade.reset).toHaveBeenCalledWith('c1', { productId: 'p1' }, 'article.default_unit');
    expect(q('article-defaults-error')?.textContent).toContain('Valeur refusée');
  });

  it('shows a reader the values without a way to change them', async () => {
    rows.set([
      { ...unit, writableLevels: [], levels: [...unit.levels, { level: 'product', value: 'KGM' }] },
    ]);
    await open({ productId: 'p1' });

    expect((q('field-article__default_unit') as HTMLInputElement).disabled).toBe(true);
    expect(q('article-defaults-save')).toBeNull();
    expect(q('article-defaults-reset-article.default_unit')).toBeNull();
  });
});
