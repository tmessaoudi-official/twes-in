// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { beforeEach, describe, expect, it } from 'vitest';
import {
  PageMemoryStorage,
  type SettingDefinition,
  SettingsFacade,
} from '../settings/settings-facade';
import { PRESENTATION } from '../settings/settings-registry';
import { CUSTOMER_VIEW_STORAGE, CustomerView } from './customer-view';

describe('CustomerView', () => {
  let storage: PageMemoryStorage;
  const cost = signal(true);
  const supplierCodes = signal(true);
  const settings = {
    value: <T>(setting: SettingDefinition<T>) =>
      setting.key === PRESENTATION.customerViewCost.key ? cost : supplierCodes,
  };

  function view(): CustomerView {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      providers: [
        { provide: SettingsFacade, useValue: settings },
        { provide: CUSTOMER_VIEW_STORAGE, useValue: storage },
      ],
    });
    return TestBed.inject(CustomerView);
  }

  beforeEach(() => {
    storage = new PageMemoryStorage();
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
});
