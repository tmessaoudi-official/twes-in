// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { FormatFacade } from '../i18n/format-facade';
import { Session, type SessionState } from '../session/session';
import { Feedback } from '../feedback/feedback';
import { provideQuietFeedback, type RecordedFeedback } from '../testing/feedback';
import { tabId } from '../realtime/tab-interceptor';
import { PairingApi, PairingRefused } from './pairing-api';
import { HEARTBEAT_MS, PhonePairing } from './phone-pairing';
import { type ScanOutcome, ScanBus } from './scan-bus';
import { ScanOffers } from './scan-offers';

const SCAN = '0199aaaa-0000-4000-8000-000000000001';

describe('PhonePairing', () => {
  let api: {
    open: ReturnType<typeof vi.fn>;
    renew: ReturnType<typeof vi.fn>;
    end: ReturnType<typeof vi.fn>;
    echo: ReturnType<typeof vi.fn>;
  };
  let pairing: PhonePairing;
  let bus: ScanBus;
  let handled: string[];
  let outcome: ScanOutcome;
  const me = signal<SessionState | null>({
    user: { id: 'u-1' },
    company: { id: 'c-1', countryCode: 'TN', currency: 'TND' },
  });

  const publication = (event: string, extra: Record<string, unknown> = {}, tab = tabId()) => ({
    type: 'pairing',
    event,
    pairing: 'p-1',
    tab,
    ...extra,
  });

  beforeEach(async () => {
    vi.useFakeTimers();
    api = {
      open: vi.fn(async () => ({
        id: 'p-1',
        link: 'a'.repeat(64),
        address: null as string | null,
      })),
      renew: vi.fn(async () => undefined),
      end: vi.fn(async () => undefined),
      echo: vi.fn(async () => undefined),
    };
    TestBed.configureTestingModule({
      providers: [
        provideQuietFeedback(),
        { provide: PairingApi, useValue: api },
        { provide: Session, useValue: { me } },
        { provide: FormatFacade, useValue: { amount: (value: string) => `~${value}` } },
      ],
    });
    pairing = TestBed.inject(PhonePairing);
    bus = TestBed.inject(ScanBus);
    handled = [];
    outcome = { kind: 'done', key: 'scan.added', params: { name: 'Nutella' } };
    TestBed.runInInjectionContext(() =>
      bus.handle(async (scan) => {
        handled.push(`${scan.source}:${scan.code}`);
        return outcome;
      }),
    );
    await pairing.open();
  });

  afterEach(() => {
    pairing.end();
    vi.useRealTimers();
  });

  it('opens a link a phone reaches from this address, and says it is there every little while', async () => {
    expect(api.open).toHaveBeenCalledWith('c-1');
    expect(pairing.state()).toEqual({
      id: 'p-1',
      url: `${location.origin}/pair#${'a'.repeat(64)}`,
      phone: 'waiting',
    });

    await vi.advanceTimersByTimeAsync(HEARTBEAT_MS);
    await vi.advanceTimersByTimeAsync(HEARTBEAT_MS);

    expect(api.renew).toHaveBeenCalledTimes(2);
    expect(api.renew).toHaveBeenCalledWith('c-1', 'p-1');
  });

  it('builds the link on the address the API names, which a phone reaches when this tab is on localhost', async () => {
    api.open.mockResolvedValueOnce({
      id: 'p-2',
      link: 'b'.repeat(64),
      address: 'https://192.168.1.20:8443',
    });

    await pairing.open();

    expect(pairing.state()?.url).toBe(`https://192.168.1.20:8443/pair#${'b'.repeat(64)}`);
  });

  it('hears the phone arrive, and only for its own tab and pairing', () => {
    expect(pairing.receive(publication('claimed', {}, 'another-tab'))).toBe(true);
    expect(pairing.state()?.phone).toBe('waiting');
    expect(pairing.receive({ ...publication('claimed'), pairing: 'p-2' })).toBe(true);
    expect(pairing.state()?.phone).toBe('waiting');
    expect(pairing.receive({ type: 'changed', kind: 'product' })).toBe(false);

    pairing.receive(publication('claimed'));

    expect(pairing.state()?.phone).toBe('connected');
  });

  it('hands a phone scan to the screen once, and echoes what it made of it', async () => {
    outcome = {
      kind: 'done',
      key: 'scan.added',
      params: { name: 'Nutella', quantity: 2, ignored: { deep: true } },
      product: { name: 'Nutella', unitPrice: '12.500' },
    };

    pairing.receive(publication('scan', { scan: SCAN, code: '3017620422003' }));
    pairing.receive(publication('scan', { scan: SCAN, code: '3017620422003' }));
    await vi.waitFor(() => expect(api.echo).toHaveBeenCalled());

    expect(handled).toEqual(['phone:3017620422003']);
    const [companyId, id, echo] = api.echo.mock.calls[0];
    expect([companyId, id]).toEqual(['c-1', 'p-1']);
    expect(echo).toEqual({
      id: expect.any(String),
      scan: SCAN,
      outcome: 'done',
      message: 'scan.added',
      params: { name: 'Nutella', quantity: 2 },
      product: { name: 'Nutella', price: '~12.500 TND' },
      choices: [],
    });
  });

  it('answers a scan no screen claims with what its card offers, and a tap on the phone runs the choice', async () => {
    outcome = { kind: 'unclaimed' };
    const choose = vi.fn();

    pairing.receive(publication('scan', { scan: SCAN, code: '999' }));
    await vi.advanceTimersByTimeAsync(0);
    TestBed.inject(ScanOffers).offer({
      code: '999',
      message: 'scan.phone.unknown',
      params: {},
      product: null,
      choices: [{ id: 'create', label: 'products.scan.actions.create' }],
      choose,
    });
    await vi.waitFor(() => expect(api.echo).toHaveBeenCalled());
    const echo = api.echo.mock.calls[0][2];
    expect(echo.choices).toEqual([{ id: 'create', label: 'products.scan.actions.create' }]);
    expect(echo.message).toBe('scan.phone.unknown');

    // A tap answering an echo this tab no longer stands by is dropped: it would act on another scan's card.
    pairing.receive(publication('choice', { echo: 'an-older-echo', choice: 'create' }));
    expect(choose).not.toHaveBeenCalled();
    pairing.receive(publication('choice', { echo: echo.id, choice: 'create' }));

    expect(choose.mock.calls).toEqual([['create']]);
  });

  it('lets the phone go when the API says the pairing is over', async () => {
    api.renew.mockRejectedValue(new PairingRefused('ended'));

    await vi.advanceTimersByTimeAsync(HEARTBEAT_MS);

    expect(pairing.state()).toBeNull();
    const said = (TestBed.inject(Feedback) as RecordedFeedback).said;
    expect(said.filter((each) => each.kind === 'failure').map((each) => each.key)).toEqual([
      'scan.phone.ended',
    ]);
  });

  it('ends the pairing and stops saying it is there', async () => {
    pairing.end();

    expect(api.end).toHaveBeenCalledWith('c-1', 'p-1');
    expect(pairing.state()).toBeNull();
    await vi.advanceTimersByTimeAsync(HEARTBEAT_MS * 2);
    expect(api.renew).not.toHaveBeenCalled();
  });
});
