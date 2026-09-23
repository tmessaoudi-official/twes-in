// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { type ScanOffer, ScanOffers } from './scan-offers';

function offer(code: string, choose = vi.fn()): ScanOffer {
  return {
    code,
    message: 'scan.phone.found',
    params: { name: 'Nutella' },
    product: { name: 'Nutella', unitPrice: '12.5' },
    choices: [{ id: 'invoice', label: 'products.scan.actions.invoice' }],
    choose,
  };
}

describe('ScanOffers', () => {
  let offers: ScanOffers;

  beforeEach(() => {
    vi.useFakeTimers();
    offers = TestBed.inject(ScanOffers);
  });

  afterEach(() => vi.useRealTimers());

  it('hands the next offer for a code to whoever waits for it', async () => {
    const waiting = offers.next('3017620422003', 4000);

    offers.offer(offer('999'));
    const made = offer('3017620422003');
    offers.offer(made);

    await expect(waiting).resolves.toBe(made);
  });

  it('hands an offer already made for that code, while it still stands', async () => {
    const made = offer('3017620422003');
    offers.offer(made);

    await expect(offers.next('3017620422003', 4000)).resolves.toBe(made);
  });

  it('answers nothing once the wait is over, or once the offer was withdrawn', async () => {
    const waiting = offers.next('3017620422003', 4000);
    vi.advanceTimersByTime(4000);
    await expect(waiting).resolves.toBeNull();

    const withdraw = offers.offer(offer('3017620422003'));
    withdraw();
    const late = offers.next('3017620422003', 4000);
    vi.advanceTimersByTime(4000);
    await expect(late).resolves.toBeNull();
  });
});
