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
import { VendorsFacade } from './vendors-facade';
import { VendorsPage } from './vendors-page';
import type { VendorRow } from './vendors-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      vendors: {
        title: 'Fournisseurs',
        add: 'Nouveau fournisseur',
        statuses: { active: 'Actif', inactive: 'Inactif' },
      },
    });
  }
}

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
  isActive: false,
};

describe('VendorsPage', () => {
  const facade = {
    vendors: signal<readonly VendorRow[]>([sotumag]).asReadonly(),
    busy: signal(false).asReadonly(),
    error: signal(null).asReadonly(),
    loadList: vi.fn(),
  };
  const auth = {
    me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }),
    hasPermission: vi.fn(),
  };
  let fixture: ComponentFixture<VendorsPage>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  beforeEach(async () => {
    facade.loadList.mockReset().mockResolvedValue(undefined);
    auth.hasPermission.mockReset().mockReturnValue(true);
    TestBed.configureTestingModule({
      imports: [VendorsPage],
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
        { provide: VendorsFacade, useValue: facade },
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
    fixture = TestBed.createComponent(VendorsPage);
    await settle();
  });

  it("lists the company's vendors with their city, terms and status in words", () => {
    expect(facade.loadList).toHaveBeenCalledWith('c1');
    const row = q('vendor-FRN-0001')?.textContent ?? '';
    expect(row).toContain('Sotumag');
    expect(row).toContain('Ben Arous');
    expect(row).toContain('30');
    expect(row).toContain('Inactif');
    expect(q('vendor-FRN-0001')?.querySelector('app-status-badge')?.getAttribute('data-tone')).toBe(
      'neutral',
    );
    expect(q('vendor-open-FRN-0001')?.getAttribute('href')).toBe('/vendors/v1');
  });

  it('offers a new vendor to a writer only', async () => {
    expect(q('vendor-add')?.getAttribute('href')).toBe('/vendors/new');

    auth.hasPermission.mockReturnValue(false);
    fixture = TestBed.createComponent(VendorsPage);
    await settle();

    expect(q('vendor-add')).toBeNull();
    expect(q('vendor-open-FRN-0001')).not.toBeNull();
  });
});
