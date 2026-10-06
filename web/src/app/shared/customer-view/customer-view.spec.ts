// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { Session, type SessionState } from '../session/session';
import { StepUp } from '../step-up/step-up';
import { CustomerScreenHold } from './customer-screen-hold';
import { CustomerView } from './customer-view';

describe('CustomerView', () => {
  const me = signal<SessionState | null>(null);
  const stepUp = { request: vi.fn() };
  const hold = { hold: vi.fn(), release: vi.fn(), reread: vi.fn() };

  const signedIn = (customerScreenCompanyId: string | null): SessionState => ({
    user: { id: 'u1' },
    company: { id: 'c1', countryCode: 'TN', currency: 'TND' },
    customerScreenCompanyId,
  });

  function view(): CustomerView {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      providers: [
        { provide: Session, useValue: { me } },
        { provide: StepUp, useValue: stepUp },
        { provide: CustomerScreenHold, useValue: hold },
      ],
    });
    return TestBed.inject(CustomerView);
  }

  beforeEach(() => {
    me.set(signedIn(null));
    stepUp.request.mockReset().mockResolvedValue(true);
    hold.hold.mockReset().mockResolvedValue(true);
    hold.release.mockReset().mockResolvedValue(true);
  });

  it('is on exactly while the sign-in is held on the screen, whichever tab held it', () => {
    const customer = view();

    expect(customer.active()).toBe(false);

    me.set(signedIn('c1'));

    expect(customer.active()).toBe(true);
    expect(view().active()).toBe(true);
  });

  it('holds the sign-in on the working company when the screen opens', async () => {
    expect(await view().on()).toBe(true);

    expect(hold.hold).toHaveBeenCalledWith('c1');
  });

  it('says so when the API would not hold it, and holds nothing twice', async () => {
    hold.hold.mockResolvedValueOnce(false);
    expect(await view().on()).toBe(false);

    me.set(signedIn('c1'));
    hold.hold.mockClear();
    expect(await view().on()).toBe(true);
    expect(hold.hold).not.toHaveBeenCalled();
  });

  it('holds nothing without a working company', async () => {
    me.set({ user: { id: 'u1' }, company: null });

    expect(await view().on()).toBe(false);
    expect(hold.hold).not.toHaveBeenCalled();
  });

  it('is left only once the person proved who they are and the API let go', async () => {
    me.set(signedIn('c1'));
    const customer = view();

    stepUp.request.mockResolvedValueOnce(false);
    expect(await customer.leave()).toBe(false);
    expect(hold.release).not.toHaveBeenCalled();

    hold.release.mockResolvedValueOnce(false);
    expect(await customer.leave()).toBe(false);

    expect(await customer.leave()).toBe(true);
    expect(stepUp.request).toHaveBeenCalledTimes(3);
    expect(hold.release).toHaveBeenCalledTimes(2);
  });

  it('asks nothing to leave what is not on', async () => {
    expect(await view().leave()).toBe(true);
    expect(stepUp.request).not.toHaveBeenCalled();
  });
});
