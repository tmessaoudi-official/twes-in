// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { FormatFacade } from '../shared/i18n/format-facade';
import { CustomerStatementFacade } from './customer-statement-facade';
import { CustomerStatementView } from './customer-statement';
import type { CustomerStatement } from './customers-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      customers: {
        statement: {
          title: 'Relevé de compte',
          none: 'Aucun mouvement sur la période.',
          opening: 'Solde d’ouverture',
          closing: 'Solde de clôture',
          totals: 'Totaux',
          kinds: { invoice: 'Facture', credit_note: 'Avoir', payment: 'Paiement' },
          limit: 'Plafond {{limit}} {{currency}}',
          over_limit: 'Plafond dépassé {{limit}} {{currency}}',
          errors: { invalid: 'Période refusée.' },
        },
      },
    });
  }
}

const account: CustomerStatement = {
  customerId: 'k1',
  customerName: 'Carthage Conseil',
  customerNumber: 'CLI-0001',
  currency: 'TND',
  currencyScale: 3,
  from: '2026-01-01',
  to: '2026-10-01',
  openingBalance: '70.000',
  totalDebit: '120.000',
  totalCredit: '40.000',
  closingBalance: '150.000',
  creditLimit: '0.000',
  overCreditLimit: false,
  lines: [
    {
      day: '2026-03-05',
      kind: 'invoice',
      number: 'FA-0002',
      documentId: 'i2',
      reference: null,
      debit: '120.000',
      credit: '0.000',
      balance: '190.000',
    },
    {
      day: '2026-04-01',
      kind: 'payment',
      number: 'FA-0002',
      documentId: 'i2',
      reference: 'VIR-77',
      debit: '0.000',
      credit: '40.000',
      balance: '150.000',
    },
  ],
};

describe('CustomerStatementView', () => {
  const statement = signal<CustomerStatement | null>(account);
  const error = signal<string | null>(null);
  const load = vi.fn();

  async function render() {
    await TestBed.configureTestingModule({
      imports: [CustomerStatementView],
      providers: [
        provideRouter([]),
        provideTranslateService({
          fallbackLang: 'fr',
          loader: provideTranslateLoader(StaticLoader),
        }),
        {
          provide: FormatFacade,
          useValue: { amount: (v: string) => v, day: (v: string) => v, locale: () => 'fr' },
        },
        {
          provide: CustomerStatementFacade,
          useValue: {
            statement,
            error,
            busy: signal(false),
            load,
            pdfUrl: (c: string, k: string, p: { from: string; to: string }) =>
              `/api/companies/${c}/customers/${k}/statement/pdf?from=${p.from}&to=${p.to}`,
          },
        },
      ],
    }).compileComponents();
    const fixture = TestBed.createComponent(CustomerStatementView);
    fixture.componentRef.setInput('companyId', 'c1');
    fixture.componentRef.setInput('customerId', 'k1');
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
    return { fixture, el: fixture.nativeElement as HTMLElement };
  }
  /** The cells of a row, or the text of any other element, each trimmed and joined by a space. */
  const text = (el: HTMLElement, id: string) => {
    const node = el.querySelector(`[data-testid="${id}"]`);
    const cells = Array.from(node?.querySelectorAll('td, th') ?? []);
    const parts = cells.length > 0 ? cells.map((cell) => cell.textContent) : [node?.textContent];
    return parts
      .map((part) => part?.replace(/\s+/g, ' ').trim())
      .filter((part) => part)
      .join(' ');
  };

  beforeEach(() => {
    load.mockReset();
    statement.set(account);
    error.set(null);
  });

  it('asks for the company year to date until a day is chosen, then for the days chosen', async () => {
    const { fixture, el } = await render();
    expect(load).toHaveBeenLastCalledWith('c1', 'k1', { from: '', to: '' });

    const from = el.querySelector<HTMLInputElement>('[data-testid="statement-from"]')!;
    expect(from.value).toBe('2026-01-01');
    from.value = '2026-02-01';
    from.dispatchEvent(new Event('change'));
    fixture.detectChanges();
    await fixture.whenStable();

    expect(load).toHaveBeenLastCalledWith('c1', 'k1', { from: '2026-02-01', to: '' });
  });

  it('says nothing of a limit when there is none, states it when there is one and warns when it is passed', async () => {
    const { fixture, el } = await render();
    expect(el.querySelector('[data-testid="statement-limit"]')).toBeNull();

    statement.set({ ...account, creditLimit: '1000.000' });
    fixture.detectChanges();
    const note = () => el.querySelector('[data-testid="statement-limit"]')!;
    expect(note().textContent?.trim()).toBe('Plafond 1000.000 TND');
    expect(note().getAttribute('role')).toBeNull();

    statement.set({ ...account, creditLimit: '100.000', overCreditLimit: true });
    fixture.detectChanges();
    expect(note().textContent?.trim()).toBe('Plafond dépassé 100.000 TND');
    expect(note().getAttribute('role')).toBe('alert');
  });

  it('links to the PDF of the period chosen', async () => {
    const { fixture, el } = await render();
    const link = () => el.querySelector<HTMLAnchorElement>('[data-testid="statement-pdf"]')!;
    expect(link().getAttribute('href')).toBe(
      '/api/companies/c1/customers/k1/statement/pdf?from=&to=',
    );

    const to = el.querySelector<HTMLInputElement>('[data-testid="statement-to"]')!;
    to.value = '2026-06-30';
    to.dispatchEvent(new Event('change'));
    fixture.detectChanges();
    await fixture.whenStable();

    expect(link().getAttribute('href')).toBe(
      '/api/companies/c1/customers/k1/statement/pdf?from=&to=2026-06-30',
    );
  });

  it('lays out the opening balance, each line with what was owed after it, the totals and the closing balance', async () => {
    const { el } = await render();

    expect(text(el, 'statement-opening')).toBe('Solde d’ouverture 70.000');
    expect(text(el, 'statement-line-0')).toBe('2026-03-05 Facture FA-0002 120.000 190.000');
    expect(text(el, 'statement-line-1')).toBe(
      '2026-04-01 Paiement FA-0002 · VIR-77 40.000 150.000',
    );
    expect(text(el, 'statement-totals')).toBe('Totaux 120.000 40.000');
    expect(text(el, 'statement-closing')).toBe('Solde de clôture 150.000 TND');
    expect(el.querySelector('[data-testid="statement-document-0"]')?.getAttribute('href')).toBe(
      '/invoices/i2',
    );
  });

  it('says so when nothing happened in the period', async () => {
    statement.set({ ...account, lines: [], totalDebit: '0.000', totalCredit: '0.000' });
    const { el } = await render();

    expect(text(el, 'statement-empty')).toBe('Aucun mouvement sur la période.');
  });

  it('shows the refusal and no table', async () => {
    statement.set(null);
    error.set('invalid');
    const { el } = await render();

    expect(text(el, 'statement-error')).toBe('Période refusée.');
    expect(el.querySelector('[data-testid="statement-table"]')).toBeNull();
  });
});
