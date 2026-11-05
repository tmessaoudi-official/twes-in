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
import { announceSaved } from '../shared/testing/live';
import type { InvoicesError, InvoiceSummary } from './invoices-types';
import { provideQuietFeedback } from '../shared/testing/feedback';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      feedback: {
        retry: 'Réessayer',
        panel_failed: 'Le panneau {{panel}} n’a pas pu être lu.',
        retry_panel: 'Réessayer : {{panel}}',
      },
      nav: { invoices: 'Factures' },
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
          margin: 'Marge · {{month}}',
          margin_basis: 'sur {{amount}} {{currency}} de ventes avec un coût',
          margin_blank: 'La marge apparaît dès qu’une ligne émise a un coût.',
          invoiced: 'Facturé · {{month}}',
          invoiced_note: 'hors taxes, avoirs déduits',
          withheld: 'Retenue à la source · {{month}}',
          withheld_note: 'retenue par vos clients, déjà déduite de ce qu’ils vous doivent',
          versus: {
            up: '+{{amount}} sur les mêmes jours du mois dernier',
            down: '−{{amount}} sur les mêmes jours du mois dernier',
            same: 'Comme les mêmes jours du mois dernier',
          },
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
  vat: [
    { code: 'TVA19', rate: '19.000', name: 'TVA 19 %', amount: '164.000' },
    { code: 'TVA7', rate: '7.000', name: null, amount: '7.000' },
  ],
  vatTotal: '171.000',
  invoicedMonth: '1881.000',
  invoicedLastMonth: '1850.050',
  collectedMonth: '800.000',
  collectedLastMonth: '1200.000',
  margin: '441.000',
  marginLastMonth: '50.050',
  marginBasis: '1081.000',
  costsVisible: true,
  withheldMonth: '10.000',
  withheldLastMonth: '4.000',
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
        ...provideQuietFeedback(),
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

  it.each(['invoice', 'customer', 'role', 'membership', 'module'])(
    'reads the summary again when %s changes elsewhere, as a payment a colleague records or a role that loses the costs and with them the margin',
    async (kind) => {
      // The owner chased a customer a colleague had just recorded paid (hunt C1).
      facade.loadSummary.mockClear().mockResolvedValue(undefined);

      await announceSaved(kind, 'x1');

      expect(facade.loadSummary).toHaveBeenCalledWith('c1');
    },
  );

  it('shows what is to collect, what is late, what came in and the VAT collected, as the API has them', () => {
    expect(text('home-outstanding')).toContain('3 531,050');
    expect(text('home-outstanding')).toContain('TND');
    expect(text('home-overdue')).toContain('2 031,050');
    expect(text('home-overdue')).toContain('3 factures · la plus ancienne 82 j');
    expect(text('home-collected')).toContain('Encaissé en septembre');
    expect(text('home-collected')).toContain('800,000');
    expect(text('home-vat')).toContain('171,000');
    // Each tax by the company's name for it; one it no longer has at that rate by the code and rate it was charged at.
    expect(text('home-vat')).toContain('TVA 19 % 164,000');
    expect(text('home-vat')).not.toContain('TVA19');
    expect(text('home-vat')).toContain('TVA7 7 % 7,000');
  });

  it('puts the margin first, against the same days of last month, with the sales it speaks of', () => {
    expect(text('home-margin')).toContain('Marge · septembre');
    expect(text('home-margin')).toContain('441,000');
    expect(text('home-margin')).toContain('+390,950 sur les mêmes jours du mois dernier');
    expect(text('home-margin')).toContain('sur 1 081,000 TND de ventes avec un coût');
    expect(text('home-invoiced')).toContain('1 881,000');
    expect(text('home-invoiced')).toContain('+30,950 sur les mêmes jours du mois dernier');
    expect(text('home-collected')).toContain('−400,000 sur les mêmes jours du mois dernier');
  });

  it('shows the withholding suffered against the same days of last month, and draws no card for a company that has none', async () => {
    expect(text('home-withheld')).toContain('Retenue à la source · septembre');
    expect(text('home-withheld')).toContain('10,000');
    expect(text('home-withheld')).toContain('+6,000 sur les mêmes jours du mois dernier');

    current.set({ ...summary, withheldMonth: '0.000', withheldLastMonth: '0.000' });
    await settle();
    expect(q('home-withheld')).toBeNull();

    current.set({ ...summary, withheldMonth: '0.000', withheldLastMonth: '4.000' });
    await settle();
    expect(text('home-withheld')).toContain('−4,000 sur les mêmes jours du mois dernier');
  });

  it('leaves the margin blank with a note until a line has a cost, and draws no card for a reader without costs', async () => {
    current.set({ ...summary, margin: null, marginLastMonth: null, marginBasis: null });
    await settle();
    expect(text('home-margin')).toContain('La marge apparaît dès qu’une ligne émise a un coût.');
    expect(text('home-margin')).not.toContain('sur les mêmes jours');
    // A figure with nothing behind it is left out (2026-09-25): the sentence says it, never a dash standing for a figure.
    expect(text('home-margin')).not.toContain('—');
    expect(q('home-margin')?.querySelector('[data-part="figure"]')).toBeNull();

    current.set({
      ...summary,
      margin: null,
      marginLastMonth: null,
      marginBasis: null,
      costsVisible: false,
    });
    await settle();
    expect(q('home-margin')).toBeNull();
    expect(q('home-invoiced')).not.toBeNull();
  });

  it('draws its figures as the home’s tiles, the margin first and larger, no change coloured with the accent', () => {
    for (const id of [
      'home-margin',
      'home-invoiced',
      'home-collected',
      'home-outstanding',
      'home-overdue',
      'home-vat',
    ]) {
      expect(q(id)?.tagName, id).toBe('APP-KPI-TILE');
    }
    expect(q('home-margin')?.querySelector('[data-part="tile"]')?.getAttribute('data-hero')).toBe(
      'true',
    );
    const deltas = [
      ...fixture.nativeElement.querySelectorAll('[data-part="delta"]'),
    ] as HTMLElement[];
    expect(deltas.length).toBeGreaterThanOrEqual(3);
    for (const delta of deltas) expect(delta.outerHTML).not.toMatch(/accent/);
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
    // Said as every home panel says it, naming which (S5.18); the activity bar says the server is unreachable.
    expect(text('home-invoices-error')).toContain('Le panneau Factures n’a pas pu être lu.');
    expect(q('home-invoices-retry')?.getAttribute('aria-label')).toBe('Réessayer : Factures');
    expect(q('home-outstanding')).toBeNull();
  });

  it('offers to read the summary again where it says it could not be read', async () => {
    current.set(null);
    error.set('network');
    await settle();
    facade.loadSummary.mockClear().mockResolvedValue(undefined);

    q('home-invoices-retry')?.click();
    await settle();

    expect(text('home-invoices-retry')).toBe('Réessayer');
    expect(facade.loadSummary).toHaveBeenCalledWith('c1');
  });

  it('opens the late invoices alone from what is late', () => {
    const see = q('home-overdue')?.querySelector('[data-part="see"]');
    expect(see?.getAttribute('href')).toBe('/invoices?status=overdue');
  });
});
