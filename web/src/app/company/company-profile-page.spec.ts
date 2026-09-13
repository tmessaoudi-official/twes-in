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
import { CompanyProfileFacade } from './company-profile-facade';
import { CompanyProfilePage } from './company-profile-page';
import type { CompanyError, CompanyProfile } from './company-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({ company: { profile: { title: 'Profil', saved: 'Enregistré' } } });
  }
}

const profile: CompanyProfile = {
  name: 'Demo',
  countryCode: 'TN',
  writable: true,
  legalName: null,
  legalForm: null,
  identifiers: { matricule_fiscal: '1234567A/B/M/000' },
  addressLine1: null,
  addressLine2: null,
  postalCode: null,
  city: null,
  email: null,
  phone: null,
  website: null,
  iban: null,
  bic: null,
  vatRegime: 'standard',
  invoiceFooterText: null,
  latePenaltyText: null,
  identifierFields: [
    {
      key: 'matricule_fiscal',
      label: 'Matricule fiscal',
      pattern: '^[0-9]{7}[A-Z]/[A-Z]/[A-Z]/[0-9]{3}$',
      required: true,
    },
  ],
  vatRegimes: [{ code: 'standard', label: 'Régime normal' }],
};

describe('CompanyProfilePage', () => {
  const facade = {
    profile: signal<CompanyProfile | null>(profile).asReadonly(),
    busy: signal(false).asReadonly(),
    error: signal<CompanyError | null>(null).asReadonly(),
    load: vi.fn(),
    save: vi.fn(),
  };
  const auth = {
    me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Demo' } }),
    hasPermission: vi.fn(),
  };
  let fixture: ComponentFixture<CompanyProfilePage>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  async function open(): Promise<void> {
    TestBed.configureTestingModule({
      imports: [CompanyProfilePage],
      providers: [
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: CompanyProfileFacade, useValue: facade },
        { provide: AuthFacade, useValue: auth },
      ],
    });
    fixture = TestBed.createComponent(CompanyProfilePage);
    await settle();
  }

  beforeEach(() => {
    facade.load.mockReset().mockResolvedValue(undefined);
    facade.save.mockReset().mockResolvedValue(true);
    auth.hasPermission.mockReset().mockReturnValue(true);
  });

  it("loads the working company's profile and asks for its preset's identifiers", async () => {
    await open();

    expect(facade.load).toHaveBeenCalledWith('c1');
    expect(auth.hasPermission).toHaveBeenCalledWith('company.settings');
    expect((q('field-identifier__matricule_fiscal') as HTMLInputElement).value).toBe(
      '1234567A/B/M/000',
    );
  });

  it('saves what the form holds', async () => {
    await open();
    const legalName = q('field-legalName') as HTMLInputElement;
    legalName.value = 'Demo SARL';
    legalName.dispatchEvent(new Event('input'));
    q('profile-save')!.click();
    await settle();

    expect(facade.save).toHaveBeenCalledWith(
      'c1',
      expect.objectContaining({
        legalName: 'Demo SARL',
        identifiers: { matricule_fiscal: '1234567A/B/M/000' },
        vatRegime: 'standard',
      }),
    );
    expect(q('profile-saved')?.textContent).toContain('Enregistré');
  });

  it('does not send an identifier without the shape the preset expects', async () => {
    await open();
    const identifier = q('field-identifier__matricule_fiscal') as HTMLInputElement;
    identifier.value = '1234567';
    identifier.dispatchEvent(new Event('input'));
    q('profile-save')!.click();
    await settle();

    expect(facade.save).not.toHaveBeenCalled();
  });

  it('neither loads nor offers the form without the settings permission', async () => {
    auth.hasPermission.mockReturnValue(false);
    await open();

    expect(facade.load).not.toHaveBeenCalled();
    expect(q('profile-forbidden')).not.toBeNull();
    expect(q('profile-form')).toBeNull();
  });
});
