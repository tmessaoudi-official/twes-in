// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
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
import { InvoicesFacade } from './invoices-facade';
import { InvoicesHome } from './invoices-home';
import type { InvoicesError, InvoiceSummary } from './invoices-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      invoices: {
        errors: { network: 'Le serveur est injoignable.' },
        home: {
          outstanding: 'À encaisser',
          not_yet_due: 'Pas encore dû',
          overdue: 'En retard',
          overdue_detail: '{{count}} factures · la plus ancienne {{days}} j',
          overdue_none: 'Aucune facture en retard',
          see: 'Voir',
          collected: 'Encaissé en {{month}}',
          vat: 'TVA collectée · {{month}}',
          to_chase: 'À relancer',
          to_chase_detail: '{{count}} factures · {{amount}} {{currency}}',
          to_chase_none: 'Rien à relancer',
          all_unpaid: 'Toutes les impayées',
          late: '{{days}} j de retard',
          due_today: 'Échéance aujourd’hui',
          due_in: 'Échéance dans {{days}} j',
          collections: 'Encaissements',
          six_months: '6 derniers mois',
          aging: {
            not_due: 'Pas encore dû',
            days_1_15: '1–15 j',
            days_16_30: '16–30 j',
            days_31_45: '31–45 j',
            days_over_45: '> 45 j',
          },
        },
      },
    });
  }
}

const summary: InvoiceSummary = {
  currency: 'TND',
  currencyScale: 3,
  today: '2026-09-21',
  outstanding: '3531.050',
  notYetDue: '1500.000',
  overdue: '2031.050',
  overdueCount: 3,
  oldestOverdueDays: 82,
  aging: [
    { bucket: 'not_due', amount: '1500.000', count: 2 },
    { bucket: 'days_1_15', amount: '850.050', count: 1 },
    { bucket: 'days_16_30', amount: '0.000', count: 0 },
    { bucket: 'days_31_45', amount: '300.000', count: 1 },
    { bucket: 'days_over_45', amount: '881.000', count: 1 },
  ],
  toChase: [
    {
      invoiceId: 'i3',
      number: 'FAC-O3',
      customerName: 'Transports Sahel',
      dueDate: '2026-07-01',
      amountDue: '881.000',
      daysLate: 82,
    },
    {
      invoiceId: 'is',
      number: 'FAC-S',
      customerName: 'Sousse Print',
      dueDate: '2026-09-24',
      amountDue: '300.000',
      daysLate: -3,
    },
  ],
  toChaseCount: 4,
  toChaseAmount: '2331.050',
  collected: [
    { month: '2026-04', amount: '0.000' },
    { month: '2026-05', amount: '0.000' },
    { month: '2026-06', amount: '0.000' },
    { month: '2026-07', amount: '190.000' },
    { month: '2026-08', amount: '400.000' },
    { month: '2026-09', amount: '800.000' },
  ],
  vat: [{ code: 'TVA19', rate: '19.000', amount: '171.000' }],
  vatTotal: '171.000',
};

describe('InvoicesHome', () => {
  const current = signal<InvoiceSummary | null>(summary);
  const error = signal<InvoicesError | null>(null);
  const facade = {
    summary: current.asReadonly(),
    error: error.asReadonly(),
    loadSummary: vi.fn(),
  };
  const company = signal({ id: 'c1', countryCode: 'TN', timezone: 'Africa/Tunis' });
  const auth = { me: () => ({ user: { id: 'u1' }, company: company() }) };
  let fixture: ComponentFixture<InvoicesHome>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);
  const text = (testId: string): string =>
    (q(testId)?.textContent ?? '').replace(/\s+/g, ' ').trim();

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  beforeEach(async () => {
    current.set(summary);
    error.set(null);
    company.set({ id: 'c1', countryCode: 'TN', timezone: 'Africa/Tunis' });
    facade.loadSummary.mockReset().mockResolvedValue(undefined);
    TestBed.configureTestingModule({
      imports: [InvoicesHome],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: InvoicesFacade, useValue: facade },
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
    fixture = TestBed.createComponent(InvoicesHome);
    await settle();
  });

  it('reads the summary of the working company, and again when the company changes', async () => {
    expect(facade.loadSummary).toHaveBeenCalledWith('c1');
    company.set({ id: 'c2', countryCode: 'TN', timezone: 'Africa/Tunis' });
    await settle();
    expect(facade.loadSummary).toHaveBeenLastCalledWith('c2');
  });

  it('shows what is to collect, what is late, what came in and the VAT collected, as the API has them', () => {
    expect(text('home-outstanding')).toContain('3 531,050');
    expect(text('home-outstanding')).toContain('TND');
    expect(text('home-overdue')).toContain('2 031,050');
    expect(text('home-overdue')).toContain('3 factures · la plus ancienne 82 j');
    expect(text('home-collected')).toContain('Encaissé en septembre');
    expect(text('home-collected')).toContain('800,000');
    expect(text('home-vat')).toContain('171,000');
    expect(text('home-vat')).toContain('TVA19');
  });

  it('lists the invoices to chase, each opening its invoice, with how late or how soon', () => {
    expect(text('home-to-chase')).toContain('4 factures · 2 331,050 TND');
    const first = q('home-chase-i3');
    expect(first?.getAttribute('href')).toBe('/invoices/i3');
    expect(first?.textContent).toContain('82 j de retard');
    expect(q('home-chase-is')?.textContent).toContain('Échéance dans 3 j');
  });

  it('draws the six months of payments, this month marked, and the aging without empty buckets', () => {
    const bars = fixture.nativeElement.querySelectorAll('[data-testid^="home-month-"]');
    expect(bars.length).toBe(6);
    expect(q('home-month-2026-09')?.getAttribute('data-current')).toBe('true');
    expect(q('home-aging-days_16_30')).toBeNull();
    expect(q('home-aging-days_over_45')?.textContent).toContain('881,000');
  });

  it('says when nothing is late or to chase, and why the figures are missing', async () => {
    current.set({
      ...summary,
      overdue: '0.000',
      overdueCount: 0,
      oldestOverdueDays: null,
      toChase: [],
      toChaseCount: 0,
      toChaseAmount: '0.000',
    });
    await settle();
    expect(text('home-overdue')).toContain('Aucune facture en retard');
    expect(text('home-to-chase')).toContain('Rien à relancer');

    current.set(null);
    error.set('network');
    await settle();
    expect(text('home-invoices-error')).toBe('Le serveur est injoignable.');
    expect(q('home-outstanding')).toBeNull();
  });
});
