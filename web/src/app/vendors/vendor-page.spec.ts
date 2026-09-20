// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { provideRouter, Router } from '@angular/router';
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
import { VendorPage } from './vendor-page';
import { VendorsFacade } from './vendors-facade';
import type { VendorOptions, VendorRow, VendorsError } from './vendors-types';
import { provideQuietFeedback, successToasts } from '../shared/testing/feedback';
import { announceSaved } from '../shared/testing/live';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      vendors: {
        saved: 'Le fournisseur a été enregistré.',
        errors: { number_taken: 'Un autre fournisseur porte déjà ce numéro.' },
      },
    });
  }
}

const options: VendorOptions = {
  countryCode: 'TN',
  identifiers: [
    {
      key: 'matricule_fiscal',
      label: 'Matricule fiscal',
      pattern: '^[0-9]{7}[A-Z]/[A-Z]/[A-Z]/[0-9]{3}$',
    },
  ],
};
const sotumag: VendorRow = {
  id: 'v1',
  number: 'FRN-0001',
  name: 'Sotumag',
  legalName: null,
  identifiers: {},
  email: null,
  phone: null,
  website: null,
  address: { line1: null, line2: null, postalCode: null, city: 'Ben Arous', countryCode: 'TN' },
  iban: null,
  bic: null,
  paymentTermsDays: 30,
  notes: null,
  isActive: true,
};

describe('VendorPage', () => {
  const error = signal<VendorsError | null>(null);
  const vendor = signal<VendorRow | null>(null);
  const facade = {
    options: signal<VendorOptions | null>(options).asReadonly(),
    vendor: vendor.asReadonly(),
    busy: signal(false).asReadonly(),
    error: error.asReadonly(),
    loadVendor: vi.fn(),
    createVendor: vi.fn(),
    reviseVendor: vi.fn(),
  };
  const auth = {
    me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }),
    hasPermission: vi.fn(),
  };
  let fixture: ComponentFixture<VendorPage>;

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

  async function open(vendorId: string | undefined): Promise<void> {
    fixture = TestBed.createComponent(VendorPage);
    if (vendorId !== undefined) {
      fixture.componentRef.setInput('vendorId', vendorId);
    }
    await settle();
  }

  beforeEach(() => {
    error.set(null);
    vendor.set(null);
    facade.loadVendor.mockReset().mockResolvedValue(undefined);
    facade.createVendor.mockReset().mockResolvedValue({ ...sotumag, id: 'v9' });
    facade.reviseVendor.mockReset().mockResolvedValue(sotumag);
    auth.hasPermission.mockReset().mockReturnValue(true);
    TestBed.configureTestingModule({
      imports: [VendorPage],
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
        { provide: VendorsFacade, useValue: facade },
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
  });

  // docs/SPEC.md § 7, 2026-09-19 21:55: a page names nothing it has not loaded.
  it('titles a vendor still loading as nothing, never as a new one', async () => {
    await open('v1');

    const title = q('vendor-title');
    expect(title?.textContent?.trim()).toBe('');
    expect(title?.getAttribute('aria-hidden')).toBe('true');
  });

  it('titles the page for a new vendor as new', async () => {
    await open(undefined);

    expect(q('vendor-title')?.textContent).toContain('vendors.new_title');
    expect(q('vendor-title')?.getAttribute('aria-hidden')).toBeNull();
  });

  it('creates a vendor without registration numbers, then opens it by its identifier', async () => {
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    await open(undefined);
    expect(facade.loadVendor).toHaveBeenCalledWith('c1', null);

    type('field-number', 'FRN-0009');
    type('field-name', 'Sotumag');
    type('field-iban', 'TN59 1000 6035 1835 9847 8831');
    type('field-paymentTermsDays', '30');
    await settle();
    q('record-save')!.click();
    await settle();

    expect(facade.createVendor).toHaveBeenCalledWith(
      'c1',
      expect.objectContaining({
        number: 'FRN-0009',
        identifiers: {},
        iban: 'TN59 1000 6035 1835 9847 8831',
        paymentTermsDays: 30,
        address: expect.objectContaining({ countryCode: 'TN' }),
      }),
    );
    await vi.waitFor(() =>
      expect(navigate).toHaveBeenCalledWith(['/vendors', 'v9'], { replaceUrl: true }),
    );
    expect(successToasts()).toContain('vendors.saved');
  });

  it('does not send a registration number or payment terms of the wrong shape', async () => {
    await open(undefined);
    type('field-number', 'FRN-0009');
    type('field-name', 'Sotumag');
    type('field-identifier__matricule_fiscal', '1234567');
    await settle();
    q('record-save')!.click();
    await settle();
    expect(facade.createVendor).not.toHaveBeenCalled();

    type('field-identifier__matricule_fiscal', '');
    type('field-paymentTermsDays', 'soon');
    await settle();
    q('record-save')!.click();
    await settle();
    expect(facade.createVendor).not.toHaveBeenCalled();
  });

  it('revises an existing vendor and says so, or says why not', async () => {
    vendor.set(sotumag);
    await open('v1');
    expect(facade.loadVendor).toHaveBeenCalledWith('c1', 'v1');
    expect(q('vendor-title')?.textContent).toContain('FRN-0001 · Sotumag');
    expect((q('field-paymentTermsDays') as HTMLInputElement).value).toBe('30');

    type('field-email', 'compta@sotumag.tn');
    await settle();
    q('record-save')!.click();
    await settle();
    expect(facade.reviseVendor).toHaveBeenCalledWith(
      'c1',
      'v1',
      expect.objectContaining({ email: 'compta@sotumag.tn' }),
    );
    expect(successToasts()).toContain('vendors.saved');

    facade.reviseVendor.mockResolvedValue(null);
    error.set('number_taken');
    q('record-save')!.click();
    await settle();
    expect(successToasts()).toEqual(['vendors.saved']);
    expect(q('vendor-error')?.textContent).toContain('Un autre fournisseur porte déjà ce numéro.');
  });

  it('takes what another person saved into the open vendor, and keeps what is typed', async () => {
    vendor.set(sotumag);
    await open('v1');
    type('field-name', 'Sotumag & fils');
    facade.loadVendor.mockImplementation(async () => {
      vendor.set({ ...sotumag, name: 'Sotumag SA', email: 'compta@sotumag.tn' });
    });

    await announceSaved('vendor', 'v1');
    await settle();

    expect(facade.loadVendor).toHaveBeenLastCalledWith('c1', 'v1');
    expect((q('field-email') as HTMLInputElement).value).toBe('compta@sotumag.tn');
    expect((q('field-name') as HTMLInputElement).value).toBe('Sotumag & fils');
    expect(q('record-changed')).not.toBeNull();
    expect(q('field-conflict-name')).not.toBeNull();
  });

  it('shows a reader the vendor without a way to save it', async () => {
    auth.hasPermission.mockReturnValue(false);
    vendor.set(sotumag);
    await open('v1');

    expect(q('vendor-read-only')).not.toBeNull();
    expect(q('record-save')).toBeNull();
  });
});
