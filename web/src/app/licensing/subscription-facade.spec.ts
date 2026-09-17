// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { AuthFacade } from '../auth/auth-facade';
import { Session } from '../shared/session/session';
import { SubscriptionApi, SubscriptionRefused } from './subscription-api';
import { SubscriptionFacade } from './subscription-facade';
import type { PaymentRow, SubscriptionView } from './subscription-types';

const declared: PaymentRow = {
  id: 'p1',
  amount: '600.000',
  currency: 'TND',
  method: 'cash',
  paidOn: '2026-09-16',
  reference: null,
  note: null,
  status: 'declared',
  declaredAt: '2026-09-16T10:00:00+00:00',
  decidedAt: null,
  decisionNote: null,
};

const unpaid: SubscriptionView = {
  companyId: 'c1',
  stage: 'unpaid',
  access: 'read_only',
  coveredUntil: '2026-08-18T23:59:59+01:00',
  graceEndsAt: '2026-08-25T23:59:59+01:00',
  daysLeft: null,
  trialEndsOn: null,
  paidThrough: '2026-08-18',
  periodCount: 1,
  periodUnit: 'month',
  price: '600.000',
  currency: 'TND',
  openPayment: null,
  payments: [],
  canDeclare: true,
};
const held: SubscriptionView = {
  ...unpaid,
  stage: 'held',
  access: 'full',
  daysLeft: 7,
  openPayment: declared,
  payments: [declared],
  canDeclare: false,
};

describe('SubscriptionFacade', () => {
  const api = {
    ofCompany: vi.fn(),
    declare: vi.fn(),
    waiting: vi.fn(),
    confirm: vi.fn(),
    reject: vi.fn(),
  };
  const auth = { load: vi.fn() };
  let facade: SubscriptionFacade;

  beforeEach(() => {
    Object.values(api).forEach((fn) => fn.mockReset());
    auth.load.mockReset();
    TestBed.configureTestingModule({
      providers: [
        { provide: SubscriptionApi, useValue: api },
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
      ],
    });
    facade = TestBed.inject(SubscriptionFacade);
  });

  it('reads the standing of the company it was given', async () => {
    api.ofCompany.mockResolvedValue(unpaid);

    await facade.load('c1');

    expect(api.ofCompany).toHaveBeenCalledWith('c1');
    expect(facade.subscription()).toEqual(unpaid);
    expect(facade.managed()).toBe(true);
    expect(facade.busy()).toBe(false);
  });

  it('tells a company licensing does not manage from one not read yet', async () => {
    api.ofCompany.mockResolvedValue(null);

    await facade.load('c1');

    expect(facade.subscription()).toBeNull();
    expect(facade.managed()).toBe(false);
    expect(facade.error()).toBeNull();
  });

  it('reads the session again after declaring, because a declaration changes what the company may do', async () => {
    api.declare.mockResolvedValue(declared);
    api.ofCompany.mockResolvedValue(held);

    expect(await facade.declare('c1', { ...declared, currency: 'TND' })).toBe(true);

    expect(facade.subscription()?.stage).toBe('held');
    expect(auth.load).toHaveBeenCalled();
  });

  it('keeps the refusal of a declaration and leaves the session alone', async () => {
    api.declare.mockRejectedValue(new SubscriptionRefused('already_declared'));

    expect(await facade.declare('c1', { ...declared, currency: 'TND' })).toBe(false);

    expect(facade.error()).toBe('already_declared');
    expect(auth.load).not.toHaveBeenCalled();
  });

  it("reads the operator's queue again after each decision", async () => {
    api.waiting.mockResolvedValue([]);
    api.confirm.mockResolvedValue({ ...declared, status: 'confirmed' });

    expect(await facade.confirm('p1', 2, null)).toBe(true);

    expect(api.confirm).toHaveBeenCalledWith('p1', 2, null);
    expect(api.waiting).toHaveBeenCalled();
    expect(facade.waiting()).toEqual([]);
  });

  it('turns anything that is not a refusal into network', async () => {
    api.waiting.mockRejectedValue(new Error('boom'));

    await facade.loadWaiting();

    expect(facade.error()).toBe('network');
  });
});
