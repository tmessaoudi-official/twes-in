// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { provideRouter } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { Session } from '../shared/session/session';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import { ExpenseCategoriesPage } from './expense-categories-page';
import { ExpensesFacade } from './expenses-facade';
import type { ExpenseCategoryRow, ExpensesError } from './expenses-types';
import { provideQuietFeedback, successToasts } from '../shared/testing/feedback';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      expenses: {
        errors: { name_taken: 'Une autre catégorie sous le même parent porte déjà ce nom.' },
      },
    });
  }
}

const vehicles: ExpenseCategoryRow = {
  id: 'k1',
  name: 'Véhicules',
  parentId: null,
  isActive: true,
};
const fuel: ExpenseCategoryRow = { id: 'k2', name: 'Carburant', parentId: 'k1', isActive: false };

describe('ExpenseCategoriesPage', () => {
  const error = signal<ExpensesError | null>(null);
  const categories = signal<readonly ExpenseCategoryRow[]>([vehicles, fuel]);
  const facade = {
    categories: categories.asReadonly(),
    busy: signal(false).asReadonly(),
    error: error.asReadonly(),
    loadCategories: vi.fn(),
    createCategory: vi.fn(),
    reviseCategory: vi.fn(),
    clearError: vi.fn(),
  };
  const auth = {
    me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }),
    hasPermission: vi.fn(),
  };
  let fixture: ComponentFixture<ExpenseCategoriesPage>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  function type(testId: string, value: string): void {
    const input = q(testId) as HTMLInputElement;
    input.value = value;
    input.dispatchEvent(new Event('input'));
  }

  async function create(): Promise<void> {
    fixture = TestBed.createComponent(ExpenseCategoriesPage);
    await settle();
  }

  beforeEach(() => {
    error.set(null);
    categories.set([vehicles, fuel]);
    facade.loadCategories.mockReset().mockResolvedValue(undefined);
    facade.createCategory.mockReset().mockResolvedValue(true);
    facade.reviseCategory.mockReset().mockResolvedValue(true);
    auth.hasPermission.mockReset().mockReturnValue(true);
    TestBed.configureTestingModule({
      imports: [ExpenseCategoriesPage],
      providers: [
        ...provideQuietFeedback(),
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: ExpensesFacade, useValue: facade },
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
  });

  it('lists the categories by their path, each active or not', async () => {
    await create();
    expect(facade.loadCategories).toHaveBeenCalledWith('c1');
    const row = q('expense-category-Carburant')?.textContent ?? '';
    expect(row).toContain('Véhicules › Carburant');
    expect(row).toContain('expenses.categories.statuses.inactive');
    expect(q('expenses-tab')?.getAttribute('href')).toBe('/expenses');
  });

  it('adds an active category at the top, keeping what was typed while the list is read again', async () => {
    await create();
    q('expense-category-add')!.click();
    await settle();
    type('field-name', 'Péages');

    categories.set([vehicles, fuel, { id: 'k3', name: 'Bureau', parentId: null, isActive: true }]);
    await settle();
    q('expense-category-save')!.click();
    await settle();

    expect(facade.createCategory).toHaveBeenCalledWith('c1', {
      name: 'Péages',
      parentId: null,
      isActive: true,
    });
    expect(successToasts()).toContain('expenses.categories.saved');
    expect(q('expense-category-form')).toBeNull();
  });

  it('reactivates a category under its parent by its identifier', async () => {
    await create();
    q('expense-category-edit-Carburant')!.click();
    await settle();
    (q('field-isActive')!.querySelector('input') as HTMLInputElement).click();
    q('expense-category-save')!.click();
    await settle();

    expect(facade.reviseCategory).toHaveBeenCalledWith('c1', 'k2', {
      name: 'Carburant',
      parentId: 'k1',
      isActive: true,
    });
  });

  it('keeps the form open and says why when the name is taken', async () => {
    facade.createCategory.mockResolvedValue(false);
    await create();
    q('expense-category-add')!.click();
    await settle();
    type('field-name', 'Carburant');
    error.set('name_taken');
    q('expense-category-save')!.click();
    await settle();

    expect(q('expense-category-form')).not.toBeNull();
    expect(q('expense-categories-error')?.textContent).toContain('porte déjà ce nom');
  });

  it('offers no change to a reader', async () => {
    auth.hasPermission.mockReturnValue(false);
    await create();
    expect(q('expense-category-add')).toBeNull();
    expect(q('expense-category-edit-Carburant')).toBeNull();
  });
});
