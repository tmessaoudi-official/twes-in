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

  it('is not a change: a notification, or anything malformed', () => {
    for (const data of [
      { type: 'membership.added', payload: {} },
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
});
