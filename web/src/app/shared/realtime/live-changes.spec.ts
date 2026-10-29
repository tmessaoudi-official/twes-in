// SPDX-License-Identifier: AGPL-3.0-or-later

import { DestroyRef, Injector, runInInjectionContext } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { RequestActivity } from '../feedback/request-activity';
import { LIVE_BATCH_MS, type LiveChange, LiveChanges, readLiveChange } from './live-changes';
import { tabId } from './tab-interceptor';

const customer = (over: Record<string, unknown> = {}): Record<string, unknown> => ({
  type: 'changed',
  kind: 'customer',
  id: 'c1',
  action: 'customer.revised',
  actor: { id: 'u1', name: 'Nadia' },
  origin: 'another-tab',
  ...over,
});

describe('readLiveChange', () => {
  it('reads what another tab changed', () => {
    expect(readLiveChange(customer(), 'this-tab')).toEqual<LiveChange>({
      kind: 'customer',
      id: 'c1',
      action: 'customer.revised',
      actor: { id: 'u1', name: 'Nadia' },
    });
  });

  it('ignores the echo of a change this tab made, which it already shows', () => {
    expect(readLiveChange(customer({ origin: 'this-tab' }), 'this-tab')).toBeNull();
  });

  it('keeps a change from nowhere in particular and without an actor', () => {
    expect(readLiveChange(customer({ origin: null, actor: null, id: null }), 'this-tab')).toEqual({
      kind: 'customer',
      id: null,
      action: 'customer.revised',
      actor: null,
    });
  });

  it('reads a change of many records at once, which names none of them but how many', () => {
    expect(
      readLiveChange(
        customer({ id: null, action: 'customer.created', bulk: true, count: 40 }),
        'this-tab',
      ),
    ).toEqual<LiveChange>({
      kind: 'customer',
      id: null,
      action: 'customer.created',
      actor: { id: 'u1', name: 'Nadia' },
      bulk: true,
      count: 40,
    });
  });

  it('is not a change: a notification, or anything malformed', () => {
    for (const data of [
      { type: 'invitation.received', payload: {} },
      customer({ kind: 3 }),
      customer({ action: undefined }),
      customer({ actor: { id: 7 } }),
      null,
      'changed',
    ]) {
      expect(readLiveChange(data, 'this-tab')).toBeNull();
    }
  });
});

describe('LiveChanges', () => {
  let live: LiveChanges;
  const quietly = vi.fn(async (work: () => Promise<unknown>) => {
    await work();
  });

  beforeEach(() => {
    vi.useFakeTimers();
    quietly.mockClear();
    TestBed.configureTestingModule({
      providers: [{ provide: RequestActivity, useValue: { quietly } }],
    });
    live = TestBed.inject(LiveChanges);
  });

  afterEach(() => vi.useRealTimers());

  function listen(kinds: readonly string[], handler: (changes: readonly LiveChange[]) => void) {
    const injector = Injector.create({ providers: [], parent: TestBed.inject(Injector) });
    let destroy!: () => void;
    const destroyRef: DestroyRef = {
      onDestroy: (callback: () => void) => {
        destroy = callback;
        return () => undefined;
      },
      destroyed: false,
    } as unknown as DestroyRef;
    runInInjectionContext(injector, () => live.on(kinds, handler, destroyRef));
    return () => destroy();
  }

  it('gathers a burst of changes of the kinds asked for into one call', () => {
    const handler = vi.fn();
    listen(['customer', 'customer_group'], handler);

    live.receive(customer({ id: 'c1' }));
    live.receive(customer({ kind: 'invoice', id: 'i1' }));
    live.receive(customer({ kind: 'customer_group', id: 'g1' }));
    expect(handler).not.toHaveBeenCalled();
    vi.advanceTimersByTime(LIVE_BATCH_MS);

    expect(handler).toHaveBeenCalledOnce();
    expect(handler.mock.calls[0][0].map((change: LiveChange) => change.id)).toEqual(['c1', 'g1']);
  });

  it('tells this tab’s screens what its own request changed, as the API’s answer names it', () => {
    const handler = vi.fn();
    listen(['customer', 'stock'], handler);

    live.heardHere(
      JSON.stringify([
        { kind: 'customer', id: 'c1', action: 'customer.revised' },
        { kind: 'stock', id: null, action: 'stock.received', bulk: true, count: 12 },
        { kind: 'invoice', id: 'i1', action: 'invoice.issued' },
      ]),
    );
    vi.advanceTimersByTime(LIVE_BATCH_MS);

    expect(handler.mock.calls[0][0]).toEqual<LiveChange[]>([
      { kind: 'customer', id: 'c1', action: 'customer.revised', actor: null, own: true },
      {
        kind: 'stock',
        id: null,
        action: 'stock.received',
        actor: null,
        own: true,
        bulk: true,
        count: 12,
      },
    ]);
  });

  it('reads what it can of an answer and nothing of one it cannot read', () => {
    const handler = vi.fn();
    listen(['customer'], handler);

    for (const header of [null, '', 'not json', '{"kind":"customer"}', '[{"kind":3}]', '[null]']) {
      live.heardHere(header);
    }
    live.heardHere(
      '[{"kind":"customer","id":7,"action":"customer.revised"},{"kind":"customer","id":"c2","action":"customer.revised"}]',
    );
    vi.advanceTimersByTime(LIVE_BATCH_MS);

    expect(handler).toHaveBeenCalledOnce();
    expect(handler.mock.calls[0][0].map((change: LiveChange) => change.id)).toEqual(['c2']);
  });

  it('says nothing about this tab’s own changes', () => {
    const handler = vi.fn();
    listen(['customer'], handler);

    live.receive(customer({ origin: tabId() }));
    vi.advanceTimersByTime(LIVE_BATCH_MS);

    expect(handler).not.toHaveBeenCalled();
  });

  it('stops calling once the screen that listened is gone', () => {
    const handler = vi.fn();
    const destroy = listen(['customer'], handler);

    live.receive(customer());
    destroy();
    vi.advanceTimersByTime(LIVE_BATCH_MS);
    live.receive(customer());
    vi.advanceTimersByTime(LIVE_BATCH_MS);

    expect(handler).not.toHaveBeenCalled();
  });

  it('reloads quietly, without the activity bar, since nobody on this screen asked for it', async () => {
    const reload = vi.fn().mockResolvedValue(undefined);
    const injector = TestBed.inject(Injector);
    const destroyRef = { onDestroy: () => () => undefined } as unknown as DestroyRef;
    runInInjectionContext(injector, () => live.reloadOn(['vendor'], reload, destroyRef));

    live.receive(customer({ kind: 'vendor' }));
    await vi.advanceTimersByTimeAsync(LIVE_BATCH_MS);

    expect(quietly).toHaveBeenCalledOnce();
    expect(reload).toHaveBeenCalledOnce();
  });

  it('reads once at a time: what changes during a slow read is read once after it, never beside it', async () => {
    const answers: (() => void)[] = [];
    const reload = vi.fn(() => new Promise<void>((resolve) => answers.push(resolve)));
    const injector = TestBed.inject(Injector);
    const destroyRef = { onDestroy: () => () => undefined } as unknown as DestroyRef;
    runInInjectionContext(injector, () => live.reloadOn(['vendor'], reload, destroyRef));

    live.receive(customer({ kind: 'vendor' }));
    await vi.advanceTimersByTimeAsync(LIVE_BATCH_MS);
    for (let burst = 0; burst < 3; burst++) {
      live.receive(customer({ kind: 'vendor' }));
      await vi.advanceTimersByTimeAsync(LIVE_BATCH_MS);
    }
    expect(reload).toHaveBeenCalledTimes(1);

    answers[0]();
    await vi.advanceTimersByTimeAsync(0);
    expect(reload).toHaveBeenCalledTimes(2);

    answers[1]();
    await vi.advanceTimersByTimeAsync(0);
    expect(reload).toHaveBeenCalledTimes(2);
  });

  it('reads again after a read that failed, when something changed meanwhile', async () => {
    const answers: ((failed: boolean) => void)[] = [];
    const reload = vi.fn(
      () =>
        new Promise<void>((resolve, reject) =>
          answers.push((failed) => (failed ? reject(new Error('down')) : resolve())),
        ),
    );
    const injector = TestBed.inject(Injector);
    const destroyRef = { onDestroy: () => () => undefined } as unknown as DestroyRef;
    runInInjectionContext(injector, () => live.reloadOn(['vendor'], reload, destroyRef));

    live.receive(customer({ kind: 'vendor' }));
    await vi.advanceTimersByTimeAsync(LIVE_BATCH_MS);
    live.receive(customer({ kind: 'vendor' }));
    await vi.advanceTimersByTimeAsync(LIVE_BATCH_MS);
    answers[0](true);
    await vi.advanceTimersByTimeAsync(0);

    expect(reload).toHaveBeenCalledTimes(2);
  });
});
