// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { FormatFacade } from '../shared/i18n/format-facade';
import { CustomerAccountFacade } from './customer-account-facade';
import { CustomerAccountView } from './customer-account';
import type { CustomerAccount } from './customers-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      customers: {
        account: {
          title: 'Compte du client',
          balance: 'Dû',
          overdue: 'En retard',
          overdue_detail: '{{count}} late, oldest {{days}} days',
          on_account: 'À son crédit',
          owed: 'Solde net',
          limit: 'Plafond {{limit}} {{currency}}',
          over_limit: 'Plafond dépassé {{limit}} {{currency}}',
          errors: { not_found: 'Introuvable.' },
        },
      },
    });
  }
}

const settled: CustomerAccount = {
  currency: 'TND',
  currencyScale: 3,
  day: '2026-10-07',
  balance: '0.000',
  overdue: '0.000',
  overdueCount: 0,
  oldestOverdueDays: null,
  onAccount: '0.000',
  owed: '0.000',
  creditLimit: '0.000',
  overCreditLimit: false,
};

describe('CustomerAccountView', () => {
  const account = signal<CustomerAccount | null>(settled);
  const error = signal<string | null>(null);
  const load = vi.fn();

  async function render(): Promise<HTMLElement> {
    await TestBed.configureTestingModule({
      imports: [CustomerAccountView],
      providers: [
        provideTranslateService({
          fallbackLang: 'fr',
          loader: provideTranslateLoader(StaticLoader),
        }),
        {
          provide: FormatFacade,
          useValue: { amount: (v: string) => v, locale: () => 'fr' },
        },
        { provide: CustomerAccountFacade, useValue: { account, error, load } },
      ],
    }).compileComponents();
    const fixture = TestBed.createComponent(CustomerAccountView);
    fixture.componentRef.setInput('companyId', 'c1');
    fixture.componentRef.setInput('customerId', 'k1');
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  const text = (root: HTMLElement, testId: string): string =>
    root.querySelector(`[data-testid="${testId}"]`)?.textContent?.replace(/\s+/g, ' ').trim() ?? '';

  beforeEach(() => {
    account.set(settled);
    error.set(null);
    load.mockReset();
  });

  it('reads the account of this company and customer', async () => {
    await render();
    expect(load).toHaveBeenCalledWith('c1', 'k1');
  });

  it('says nothing of lateness, money on account or a limit the customer does not have', async () => {
    const root = await render();
    expect(text(root, 'account-balance')).toContain('TND');
    expect(root.querySelector('[data-testid="account-overdue-detail"]')).toBeNull();
    expect(root.querySelector('[data-testid="account-on-account"]')).toBeNull();
    expect(root.querySelector('[data-testid="account-limit"]')).toBeNull();
  });

  it('says how much is late and since when, what is on account, and that the limit is passed', async () => {
    account.set({
      ...settled,
      balance: '120.000',
      overdue: '70.000',
      overdueCount: 2,
      oldestOverdueDays: 10,
      onAccount: '15.000',
      owed: '105.000',
      creditLimit: '100.000',
      overCreditLimit: true,
    });
    const root = await render();

    expect(text(root, 'account-overdue-detail')).toBe('2 late, oldest 10 days');
    expect(root.querySelector('[data-testid="account-on-account"]')).not.toBeNull();
    const limit = root.querySelector('[data-testid="account-limit"]');
    expect(limit?.getAttribute('role')).toBe('alert');
    expect(limit?.textContent).toContain('Plafond dépassé');
  });

  it('states the limit quietly while it is not passed', async () => {
    account.set({ ...settled, creditLimit: '100.000', owed: '40.000' });
    const root = await render();
    const limit = root.querySelector('[data-testid="account-limit"]');
    expect(limit?.getAttribute('role')).toBeNull();
    expect(limit?.textContent).toContain('Plafond');
    expect(limit?.textContent).not.toContain('dépassé');
  });

  it('says why the account is not there', async () => {
    account.set(null);
    error.set('not_found');
    const root = await render();
    expect(text(root, 'account-error')).toBe('Introuvable.');
  });
});
