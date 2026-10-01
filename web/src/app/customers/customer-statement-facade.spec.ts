// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { CustomerStatementFacade } from './customer-statement-facade';
import { CustomersApi, CustomersRefused } from './customers-api';
import type { CustomerStatement } from './customers-types';

const statement = (closingBalance: string): CustomerStatement => ({
  customerId: 'k1',
  customerName: 'Carthage Conseil',
  customerNumber: 'CLI-0001',
  currency: 'TND',
  currencyScale: 3,
  from: '2026-01-01',
  to: '2026-10-01',
  openingBalance: '0.000',
  totalDebit: closingBalance,
  totalCredit: '0.000',
  closingBalance,
  creditLimit: '0.000',
  overCreditLimit: false,
  lines: [],
});

describe('CustomerStatementFacade', () => {
  const api = { statement: vi.fn() };
  let facade: CustomerStatementFacade;

  beforeEach(() => {
    api.statement.mockReset();
    TestBed.configureTestingModule({ providers: [{ provide: CustomersApi, useValue: api }] });
    facade = TestBed.inject(CustomerStatementFacade);
  });

  it('keeps the account it read, asking for the period it is given', async () => {
    api.statement.mockResolvedValue(statement('100.000'));

    await facade.load('c1', 'k1', { from: '2026-02-01' });

    expect(api.statement).toHaveBeenCalledWith('c1', 'k1', { from: '2026-02-01' });
    expect(facade.statement()?.closingBalance).toBe('100.000');
    expect(facade.error()).toBeNull();
    expect(facade.busy()).toBe(false);
  });

  it('shows the code of a refusal and no account', async () => {
    api.statement.mockResolvedValueOnce(statement('100.000'));
    await facade.load('c1', 'k1', {});
    api.statement.mockRejectedValue(new CustomersRefused('invalid'));

    await facade.load('c1', 'k1', { from: '2026-05-02', to: '2026-05-01' });

    expect(facade.error()).toBe('invalid');
    expect(facade.statement()).toBeNull();
  });

  it('shows only the answer of the latest question, whichever comes back last', async () => {
    let answerFirst: (value: CustomerStatement) => void = () => undefined;
    api.statement
      .mockReturnValueOnce(new Promise<CustomerStatement>((resolve) => (answerFirst = resolve)))
      .mockResolvedValueOnce(statement('200.000'));

    const first = facade.load('c1', 'k1', { from: '2026-01-01' });
    await facade.load('c1', 'k1', { from: '2026-06-01' });
    answerFirst(statement('100.000'));
    await first;

    expect(facade.statement()?.closingBalance).toBe('200.000');
    expect(facade.busy()).toBe(false);
  });
});
