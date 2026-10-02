// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
  PageMemoryStorage,
  type SettingDefinition,
  SettingsFacade,
} from '../settings/settings-facade';
import { PRESENTATION } from '../settings/settings-registry';
import { StepUp } from '../step-up/step-up';
import { CUSTOMER_VIEW_STORAGE, CustomerView } from './customer-view';

describe('CustomerView', () => {
  let storage: PageMemoryStorage;
  const cost = signal(true);
  const supplierCodes = signal(true);
  const settings = {
    value: <T>(setting: SettingDefinition<T>) =>
      setting.key === PRESENTATION.customerViewCost.key ? cost : supplierCodes,
  };

  const stepUp = { request: vi.fn() };

  function view(): CustomerView {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      providers: [
        { provide: SettingsFacade, useValue: settings },
        { provide: CUSTOMER_VIEW_STORAGE, useValue: storage },
        { provide: StepUp, useValue: stepUp },
      ],
    });
    return TestBed.inject(CustomerView);
  }

  beforeEach(() => {
    storage = new PageMemoryStorage();
    stepUp.request.mockReset().mockResolvedValue(true);
    cost.set(true);
    supplierCodes.set(true);
  });

  it('is off until someone turns it on, and hides nothing while off', () => {
    const customer = view();

    expect(customer.active()).toBe(false);
    expect(customer.hides('cost')).toBe(false);
    expect(customer.hides('supplier-codes')).toBe(false);
  });

  it('hides what the company chose once on, and only that', () => {
    const customer = view();
    supplierCodes.set(false);

    customer.toggle();

    expect(customer.active()).toBe(true);
    expect(customer.hides('cost')).toBe(true);
    expect(customer.hides('supplier-codes')).toBe(false);
  });

  it('stays on through a reload of the same tab, and off again once turned off', () => {
    view().on();
    expect(view().active()).toBe(true);

    view().off();
    expect(view().active()).toBe(false);
  });

  it('is left only once the person proved who they are, and stays on when they give up', async () => {
    const customer = view();
    customer.on();

    stepUp.request.mockResolvedValueOnce(false);
    expect(await customer.leave()).toBe(false);
    expect(customer.active()).toBe(true);

    stepUp.request.mockResolvedValueOnce(true);
    expect(await customer.leave()).toBe(true);
    expect(customer.active()).toBe(false);
    expect(stepUp.request).toHaveBeenCalledTimes(2);
  });

  it('asks nothing to leave what is not on, and the top bar toggle asks to leave like the banner does', async () => {
    const customer = view();
    expect(await customer.leave()).toBe(true);
    expect(stepUp.request).not.toHaveBeenCalled();

    customer.toggle();
    expect(customer.active()).toBe(true);
    stepUp.request.mockResolvedValueOnce(false);
    customer.toggle();
    await Promise.resolve();
    expect(stepUp.request).toHaveBeenCalledTimes(1);
    expect(customer.active()).toBe(true);
  });
});
