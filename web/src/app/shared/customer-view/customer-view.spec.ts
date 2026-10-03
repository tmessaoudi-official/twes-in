// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { PageMemoryStorage } from '../settings/settings-facade';
import { StepUp } from '../step-up/step-up';
import { CUSTOMER_VIEW_STORAGE, CustomerView } from './customer-view';

describe('CustomerView', () => {
  let storage: PageMemoryStorage;
  const stepUp = { request: vi.fn() };

  function view(): CustomerView {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      providers: [
        { provide: CUSTOMER_VIEW_STORAGE, useValue: storage },
        { provide: StepUp, useValue: stepUp },
      ],
    });
    return TestBed.inject(CustomerView);
  }

  beforeEach(() => {
    storage = new PageMemoryStorage();
    stepUp.request.mockReset().mockResolvedValue(true);
  });

  it('is off until the customer screen is opened', () => {
    const customer = view();

    expect(customer.active()).toBe(false);

    customer.on();

    expect(customer.active()).toBe(true);
  });

  it('stays on through a reload of the same tab, so a reload does not unlock it', () => {
    view().on();

    expect(view().active()).toBe(true);
  });

  it('is left only once the person proved who they are, and stays on when they give up', async () => {
    const customer = view();
    customer.on();

    stepUp.request.mockResolvedValueOnce(false);
    expect(await customer.leave()).toBe(false);
    expect(customer.active()).toBe(true);
    expect(view().active()).toBe(true);

    stepUp.request.mockResolvedValueOnce(true);
    expect(await customer.leave()).toBe(true);
    expect(customer.active()).toBe(false);
    expect(view().active()).toBe(false);
    expect(stepUp.request).toHaveBeenCalledTimes(2);
  });

  it('asks nothing to leave what is not on', async () => {
    const customer = view();

    expect(await customer.leave()).toBe(true);
    expect(stepUp.request).not.toHaveBeenCalled();
  });
});
