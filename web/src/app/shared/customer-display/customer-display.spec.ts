// SPDX-License-Identifier: AGPL-3.0-or-later

import { DestroyRef, Injector, runInInjectionContext, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { beforeEach, describe, expect, it } from 'vitest';
import { Session } from '../session/session';
import { FakeDisplayChannels } from '../testing/display-channels';
import { CUSTOMER_DISPLAY_CHANNEL, CustomerDisplay } from './customer-display';

describe('CustomerDisplay', () => {
  let channels: FakeDisplayChannels;
  const company = signal<{ id: string; currency: string } | null>({ id: 'c1', currency: 'TND' });

  beforeEach(() => {
    channels = new FakeDisplayChannels();
    company.set({ id: 'c1', currency: 'TND' });
    TestBed.configureTestingModule({
      providers: [
        { provide: CUSTOMER_DISPLAY_CHANNEL, useValue: channels.opener },
        { provide: Session, useValue: { me: () => ({ company: company() }) } },
      ],
    });
  });

  /** A display in its own injector, as a second window has, over the same channels. */
  function aDisplay() {
    const injector = Injector.create({
      providers: [CustomerDisplay],
      parent: TestBed.inject(Injector),
    });
    const destroyed: (() => void)[] = [];
    const destroyRef = {
      onDestroy: (fn: () => void) => destroyed.push(fn),
    } as unknown as DestroyRef;
    const shown = runInInjectionContext(injector, () =>
      injector.get(CustomerDisplay).watch(destroyRef),
    );
    return { shown, close: () => destroyed.forEach((fn) => fn()) };
  }

  it('shows a display what was scanned, priced taxes included, on a channel of the company only', () => {
    const display = aDisplay();
    const sender = TestBed.inject(CustomerDisplay);

    sender.show({ name: 'Vis 6x40', quantity: '3', unitPrice: '1.190' });

    expect(display.shown()).toEqual({
      item: { name: 'Vis 6x40', quantity: '3', unitPrice: '1.190' },
      total: null,
      currency: 'TND',
    });
    expect(new Set(channels.names)).toEqual(new Set(['twes.customer-display.c1']));
  });

  it('adds the saved total to what it shows, and a new scan takes the total away until the next save', () => {
    const display = aDisplay();
    const sender = TestBed.inject(CustomerDisplay);

    sender.show({ name: 'Vis', quantity: '1', unitPrice: '1.190' });
    sender.total('11.900');
    expect(display.shown()?.total).toBe('11.900');

    sender.show({ name: 'Écrou', quantity: '2', unitPrice: '0.500' });
    expect(display.shown()?.item?.name).toBe('Écrou');
    expect(display.shown()?.total).toBeNull();
  });

  it('never shows a total the tab did not feed a scan for: browsing documents says nothing to the customer', () => {
    const display = aDisplay();
    const sender = TestBed.inject(CustomerDisplay);

    sender.total('999.000');

    expect(display.shown()).toBeNull();
  });

  it('empties the display when the sale leaves the screen, and then stays quiet', () => {
    const display = aDisplay();
    const sender = TestBed.inject(CustomerDisplay);
    sender.show({ name: 'Vis', quantity: '1', unitPrice: '1.190' });

    sender.clear();
    expect(display.shown()).toEqual({ item: null, total: null, currency: 'TND' });

    sender.total('5.000');
    expect(display.shown()?.total).toBeNull();
  });

  it('empties the display when the counter tab is closed or reloaded, where no screen is destroyed', () => {
    const display = aDisplay();
    TestBed.inject(CustomerDisplay).show({ name: 'Vis', quantity: '1', unitPrice: '1.190' });

    window.dispatchEvent(new Event('pagehide'));

    expect(display.shown()).toEqual({ item: null, total: null, currency: 'TND' });
  });

  it('answers a display opened in the middle of a sale with what it shows now', () => {
    const sender = TestBed.inject(CustomerDisplay);
    sender.show({ name: 'Vis', quantity: '4', unitPrice: '1.190' });

    const late = aDisplay();

    expect(late.shown()?.item?.quantity).toBe('4');
  });

  it('closes the display side of the channel when the display goes', () => {
    const display = aDisplay();
    const before = channels.count;
    display.close();
    expect(channels.count).toBe(before - 1);
  });

  it('opens nothing without a company, and nothing where the browser has no channel', () => {
    company.set(null);
    TestBed.inject(CustomerDisplay).show({ name: 'Vis', quantity: '1', unitPrice: '1' });
    expect(channels.names).toEqual([]);

    TestBed.resetTestingModule();
    company.set({ id: 'c1', currency: 'TND' });
    TestBed.configureTestingModule({
      providers: [
        { provide: CUSTOMER_DISPLAY_CHANNEL, useValue: () => null },
        { provide: Session, useValue: { me: () => ({ company: company() }) } },
      ],
    });
    expect(() =>
      TestBed.inject(CustomerDisplay).show({ name: 'Vis', quantity: '1', unitPrice: '1' }),
    ).not.toThrow();
  });
});
