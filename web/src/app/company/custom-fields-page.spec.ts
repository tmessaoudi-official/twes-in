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
import type { CustomFieldsError } from '../shared/custom-fields/custom-fields-api';
import type { CustomFieldDefinition } from '../shared/custom-fields/custom-fields-types';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import { CustomFieldsFacade } from './custom-fields-facade';
import { CustomFieldsPage } from './custom-fields-page';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      company: {
        custom_fields: {
          title: 'Champs personnalisés',
          statuses: { active: 'Actif', retired: 'Retiré' },
          errors: { key_taken: 'Un autre champ porte déjà cette clé.' },
        },
      },
    });
  }
}

const sector: CustomFieldDefinition = {
  id: 'f1',
  entity: 'customer',
  key: 'sector',
  label: 'Secteur',
  type: 'choice',
  required: true,
  choices: ['retail', 'wholesale'],
  sortOrder: 0,
  isActive: true,
};

describe('CustomFieldsPage', () => {
  const error = signal<CustomFieldsError | null>(null);
  const facade = {
    fields: signal<readonly CustomFieldDefinition[]>([sector]).asReadonly(),
    busy: signal(false).asReadonly(),
    error: error.asReadonly(),
    load: vi.fn(),
    create: vi.fn(),
    revise: vi.fn(),
    clearError: vi.fn(),
  };
  const auth = {
    me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }),
    hasPermission: vi.fn(),
  };
  let fixture: ComponentFixture<CustomFieldsPage>;

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

  beforeEach(async () => {
    error.set(null);
    facade.load.mockReset().mockResolvedValue(undefined);
    facade.create.mockReset().mockResolvedValue(true);
    facade.revise.mockReset().mockResolvedValue(true);
    auth.hasPermission.mockReset().mockReturnValue(true);

    TestBed.configureTestingModule({
      imports: [CustomFieldsPage],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: CustomFieldsFacade, useValue: facade },
        { provide: AuthFacade, useValue: auth },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
    fixture = TestBed.createComponent(CustomFieldsPage);
    await settle();
  });

  it('switches to the fields of products and declares one for them', async () => {
    expect(q('custom-fields-entity-customer')?.getAttribute('aria-pressed')).toBe('true');
    q('custom-fields-entity-product')!.click();
    await settle();
    expect(facade.load).toHaveBeenLastCalledWith('c1', 'product');
    expect(q('custom-fields-entity-product')?.getAttribute('aria-pressed')).toBe('true');

    q('custom-field-add')!.click();
    await settle();
    type('field-key', 'warranty');
    type('field-label', 'Garantie');
    q('custom-field-save')!.click();
    await vi.waitFor(() =>
      expect(facade.create).toHaveBeenCalledWith(
        'c1',
        expect.objectContaining({ entity: 'product', key: 'warranty' }),
      ),
    );
  });

  it("loads and shows the company's custom fields", () => {
    expect(facade.load).toHaveBeenCalledWith('c1', 'customer');
    expect(q('custom-field-sector')?.textContent).toContain('Secteur');
    expect(q('custom-field-sector')?.textContent).toContain('Actif');
  });

  it('declares a field with its key and label', async () => {
    q('custom-field-add')!.click();
    await settle();
    type('field-key', 'account_manager');
    type('field-label', 'Chargé de compte');
    q('custom-field-save')!.click();
    await vi.waitFor(() =>
      expect(facade.create).toHaveBeenCalledWith(
        'c1',
        expect.objectContaining({
          key: 'account_manager',
          label: 'Chargé de compte',
          type: 'text',
          choices: [],
        }),
      ),
    );
  });

  it('does not send a key the API would refuse', async () => {
    q('custom-field-add')!.click();
    await settle();
    type('field-key', 'Account Manager');
    type('field-label', 'Chargé');
    q('custom-field-save')!.click();
    await settle();

    expect(facade.create).not.toHaveBeenCalled();
  });

  it('revises a field by its identifier, keeping its key and type', async () => {
    q('custom-field-edit-sector')!.click();
    await settle();
    type('field-label', 'Activité');
    q('custom-field-save')!.click();
    await vi.waitFor(() =>
      expect(facade.revise).toHaveBeenCalledWith(
        'c1',
        'f1',
        expect.objectContaining({ key: 'sector', type: 'choice', label: 'Activité' }),
      ),
    );
  });

  it('says why the API refused', async () => {
    error.set('key_taken');
    await settle();

    expect(q('custom-fields-error')?.textContent).toContain('porte déjà cette clé');
  });

  it('offers no change to someone who may not change settings', async () => {
    auth.hasPermission.mockReturnValue(false);
    fixture = TestBed.createComponent(CustomFieldsPage);
    await settle();

    expect(q('custom-field-add')).toBeNull();
    expect(q('custom-field-edit-sector')).toBeNull();
  });
});
