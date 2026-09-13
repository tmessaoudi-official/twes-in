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
import { FiscalFacade } from './fiscal-facade';
import { FiscalTaxesPage } from './fiscal-taxes-page';
import type { CustomerTaxRegimeRow, TaxComponentRow } from './fiscal-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      fiscal: {
        edit: 'Modifier',
        save: 'Enregistrer',
        cancel: 'Annuler',
        yes: 'Oui',
        no: 'Non',
        families: { vat: 'TVA', stamp: 'Droit de timbre' },
        taxes: {
          title: 'Taxes',
          add: 'Ajouter une taxe',
          name: 'Nom',
          amount: 'Montant',
          rate: 'Taux',
        },
        regimes: { title: 'Régimes', label: 'Régime', excluded: 'Taxes non facturées' },
        errors: { code_taken: 'Code déjà utilisé' },
      },
    });
  }
}

const vat: TaxComponentRow = {
  id: 't1',
  code: 'TVA19',
  name: 'TVA 19 %',
  kind: 'percentage_line',
  family: 'vat',
  rate: '19.000',
  amount: null,
  threshold: null,
  entersVatBase: false,
  isDefault: true,
  isActive: true,
  exemptionMention: null,
  sortOrder: 10,
};

const stamp: TaxComponentRow = {
  ...vat,
  id: 's1',
  code: 'TIMBRE',
  name: 'Droit de timbre',
  kind: 'fixed_document',
  family: 'stamp',
  rate: null,
  amount: '1.000',
  sortOrder: 50,
};

const exportRegime: CustomerTaxRegimeRow = {
  code: 'export',
  label: 'Export',
  excludedFamilies: ['vat'],
  hasMention: true,
  sortOrder: 40,
};

describe('FiscalTaxesPage', () => {
  const taxes = signal<readonly TaxComponentRow[]>([]);
  const error = signal<string | null>(null);
  const fiscal = {
    taxes: taxes.asReadonly(),
    regimes: signal<readonly CustomerTaxRegimeRow[]>([exportRegime]).asReadonly(),
    busy: signal(false).asReadonly(),
    error: error.asReadonly(),
    loadTaxes: vi.fn(),
    createTax: vi.fn(),
    reviseTax: vi.fn(),
    clearError: vi.fn(),
  };
  const auth = {
    me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }),
    hasPermission: vi.fn(),
  };
  let fixture: ComponentFixture<FiscalTaxesPage>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  async function settle(target: ComponentFixture<FiscalTaxesPage> = fixture): Promise<void> {
    target.detectChanges();
    await target.whenStable();
    target.detectChanges();
  }

  beforeEach(async () => {
    taxes.set([vat, stamp]);
    error.set(null);
    fiscal.loadTaxes.mockReset().mockResolvedValue(undefined);
    fiscal.createTax.mockReset().mockResolvedValue(true);
    fiscal.reviseTax.mockReset().mockResolvedValue(true);
    auth.hasPermission.mockReset().mockReturnValue(true);

    TestBed.configureTestingModule({
      imports: [FiscalTaxesPage],
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
        { provide: FiscalFacade, useValue: fiscal },
        { provide: AuthFacade, useValue: auth },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
    fixture = TestBed.createComponent(FiscalTaxesPage);
    await settle();
  });

  it('loads the taxes of the company being worked in', () => {
    expect(fiscal.loadTaxes).toHaveBeenCalledWith('c1');
  });

  it('shows each tax with its translated family and its rate or amount', () => {
    expect(q('tax-TVA19')?.textContent).toContain('TVA');
    expect(q('tax-TVA19')?.textContent).toContain('19.000 %');
    expect(q('tax-TIMBRE')?.textContent).toContain('Droit de timbre');
    expect(q('tax-TIMBRE')?.textContent).toContain('1.000');
  });

  it('lists the customer regimes with the taxes they do not charge', () => {
    expect(q('regime-export')?.textContent).toContain('Export');
    expect(q('regime-export')?.textContent).toContain('TVA');
  });

  it('revises a stamp with an amount and no rate, under its own code and family', async () => {
    q('tax-edit-TIMBRE')!.click();
    await settle();

    expect(q('field-amount')).not.toBeNull();
    expect(q('field-rate')).toBeNull();
    expect(q('field-code')).toBeNull();

    const name = q('field-name') as HTMLInputElement;
    name.value = 'Timbre fiscal';
    name.dispatchEvent(new Event('input'));
    q('tax-save')!.click();
    await settle();

    expect(fiscal.reviseTax).toHaveBeenCalledWith(
      'c1',
      's1',
      expect.objectContaining({
        code: 'TIMBRE',
        family: 'stamp',
        name: 'Timbre fiscal',
        amount: '1.000',
        rate: null,
      }),
    );
    expect(q('tax-form')).toBeNull();
    expect(q('tax-saved')).not.toBeNull();
  });

  it('keeps the form open when the API refused', async () => {
    fiscal.createTax.mockResolvedValue(false);
    q('tax-add')!.click();
    await settle();
    for (const [id, value] of [
      ['field-code', 'TVA19'],
      ['field-name', 'Encore'],
      ['field-rate', '19'],
    ]) {
      const input = q(id) as HTMLInputElement;
      input.value = value;
      input.dispatchEvent(new Event('input'));
    }
    q('tax-save')!.click();
    await settle();

    expect(fiscal.createTax).toHaveBeenCalledWith(
      'c1',
      expect.objectContaining({ code: 'TVA19', family: 'vat', rate: '19' }),
    );
    expect(q('tax-form')).not.toBeNull();
  });

  it('hides adding and editing from someone who may only read', async () => {
    auth.hasPermission.mockReturnValue(false);
    const reader = TestBed.createComponent(FiscalTaxesPage);
    await settle(reader);

    expect(reader.nativeElement.querySelector('[data-testid="tax-add"]')).toBeNull();
    expect(reader.nativeElement.querySelector('[data-testid="tax-edit-TVA19"]')).toBeNull();
    expect(reader.nativeElement.querySelector('[data-testid="tax-TVA19"]')).not.toBeNull();
  });
});
