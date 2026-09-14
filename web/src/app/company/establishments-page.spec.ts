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
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import type { CompanyError, EstablishmentRow } from './company-types';
import { EstablishmentsFacade } from './establishments-facade';
import { EstablishmentsPage } from './establishments-page';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      company: {
        establishments: {
          title: 'Établissements',
          yes: 'Oui',
          no: 'Non',
          errors: { code_taken: 'Un autre établissement porte déjà ce code.' },
        },
      },
    });
  }
}

const head: EstablishmentRow = {
  id: 'e1',
  code: '000',
  name: 'Acme',
  addressLine1: null,
  addressLine2: null,
  postalCode: null,
  city: 'Tunis',
  phone: null,
  email: null,
  isDefault: true,
  codePattern: '^[0-9]{3}$',
  codeLocked: false,
};

describe('EstablishmentsPage', () => {
  const error = signal<CompanyError | null>(null);
  const establishments = signal<readonly EstablishmentRow[]>([head]);
  const facade = {
    establishments: establishments.asReadonly(),
    busy: signal(false).asReadonly(),
    error: error.asReadonly(),
    loadEstablishments: vi.fn(),
    createEstablishment: vi.fn(),
    reviseEstablishment: vi.fn(),
    clearError: vi.fn(),
  };
  const auth = {
    me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }),
    hasPermission: vi.fn(),
  };
  let fixture: ComponentFixture<EstablishmentsPage>;

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
    establishments.set([head]);
    facade.loadEstablishments.mockReset().mockResolvedValue(undefined);
    facade.createEstablishment.mockReset().mockResolvedValue(true);
    facade.reviseEstablishment.mockReset().mockResolvedValue(true);
    auth.hasPermission.mockReset().mockReturnValue(true);

    TestBed.configureTestingModule({
      imports: [EstablishmentsPage],
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
        { provide: EstablishmentsFacade, useValue: facade },
        { provide: AuthFacade, useValue: auth },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
    fixture = TestBed.createComponent(EstablishmentsPage);
    await settle();
  });

  it('loads and shows the establishments of the company being worked in', () => {
    expect(facade.loadEstablishments).toHaveBeenCalledWith('c1');
    expect(q('establishment-000')?.textContent).toContain('Tunis');
    expect(q('establishment-000')?.textContent).toContain('Oui');
  });

  it('adds an establishment with a code of the shape its preset gives', async () => {
    q('establishment-add')!.click();
    await settle();
    type('field-code', '001');
    type('field-name', 'Agence de Sfax');
    q('establishment-save')!.click();
    await settle();

    expect(facade.createEstablishment).toHaveBeenCalledWith(
      'c1',
      expect.objectContaining({ code: '001', name: 'Agence de Sfax', isDefault: false }),
    );
  });

  it('does not send a code the preset would refuse', async () => {
    q('establishment-add')!.click();
    await settle();
    type('field-code', '12');
    type('field-name', 'Agence');
    q('establishment-save')!.click();
    await settle();

    expect(facade.createEstablishment).not.toHaveBeenCalled();
  });

  it('revises an establishment by its identifier', async () => {
    q('establishment-edit-000')!.click();
    await settle();
    type('field-name', 'Siège social');
    q('establishment-save')!.click();
    await settle();

    expect(facade.reviseEstablishment).toHaveBeenCalledWith(
      'c1',
      'e1',
      expect.objectContaining({ code: '000', name: 'Siège social', isDefault: true }),
    );
  });

  it('revises an establishment whose code numbered documents carry, keeping the code', async () => {
    establishments.set([{ ...head, codeLocked: true }]);
    await settle();
    q('establishment-edit-000')!.click();
    await settle();

    expect((q('field-code') as HTMLInputElement).disabled).toBe(true);

    type('field-name', 'Siège social');
    q('establishment-save')!.click();
    await settle();

    expect(facade.reviseEstablishment).toHaveBeenCalledWith(
      'c1',
      'e1',
      expect.objectContaining({ code: '000', name: 'Siège social' }),
    );
  });

  it('says why the API refused', async () => {
    error.set('code_taken');
    await settle();

    expect(q('establishments-error')?.textContent).toContain('porte déjà ce code');
  });

  it('offers no change to a reader', async () => {
    auth.hasPermission.mockReturnValue(false);
    fixture = TestBed.createComponent(EstablishmentsPage);
    await settle();

    expect(q('establishment-add')).toBeNull();
    expect(q('establishment-edit-000')).toBeNull();
  });
});
