// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { WatchApi } from './watch-api';
import { WatchFacade } from './watch-facade';
import { SessionData } from '../shared/session/session-data';
import { WatchSubjectGone } from './watch-types';

const NOW = Date.parse('2026-11-06T10:00:00Z');
const DAY = 24 * 60 * 60 * 1000;
/** The seconds the API says, for a span in milliseconds. */
const after = (ms: number) => ms / 1000;

describe('WatchFacade', () => {
  const api = { summary: vi.fn(), rows: vi.fn() };
  let facade: WatchFacade;

  beforeEach(() => {
    api.summary.mockReset();
    api.rows.mockReset();
    TestBed.configureTestingModule({ providers: [{ provide: WatchApi, useValue: api }] });
    facade = TestBed.inject(WatchFacade);
  });

  it('keeps the summary shown when a later read fails, and says it is unavailable', async () => {
    api.summary.mockResolvedValueOnce({ count: 2, subjects: [], readAgainIn: after(DAY) });
    await facade.load('k1');
    expect(facade.summary()?.count).toBe(2);
    expect(facade.error()).toBeNull();

    api.summary.mockRejectedValueOnce(new Error('down'));
    await facade.load('k1');
    expect(facade.summary()?.count).toBe(2);
    expect(facade.error()).toBe('unavailable');
  });

  it('holds the page of a subject once read', async () => {
    api.rows.mockResolvedValueOnce({ rows: [], total: 7 });
    await facade.loadRows('k1', 'a', 0, 25);

    expect(api.rows).toHaveBeenCalledWith('k1', 'a', 0, 25);
    expect(facade.rows()).toEqual({ status: 'ready', page: { rows: [], total: 7 } });
  });

  it('tells a subject that has gone from a read that failed', async () => {
    api.rows.mockRejectedValueOnce(new WatchSubjectGone());
    await facade.loadRows('k1', 'a', 0, 25);
    expect(facade.rows().status).toBe('gone');

    api.rows.mockRejectedValueOnce(new Error('down'));
    await facade.loadRows('k1', 'a', 0, 25);
    expect(facade.rows().status).toBe('unavailable');
  });

  describe('read again with time alone', () => {
    beforeEach(() => {
      vi.useFakeTimers();
      vi.setSystemTime(NOW);
    });
    afterEach(() => vi.useRealTimers());

    it('reads the summary again when it says, as a draft comes due with nothing written', async () => {
      api.summary.mockResolvedValue({ count: 0, subjects: [], readAgainIn: after(90_000) });
      await facade.load('k1');
      expect(api.summary).toHaveBeenCalledTimes(1);

      await vi.advanceTimersByTimeAsync(89_000);
      expect(api.summary).toHaveBeenCalledTimes(1);
      await vi.advanceTimersByTimeAsync(2_000);
      expect(api.summary.mock.calls).toEqual([['k1'], ['k1']]);
    });

    it('waits a day at most, and one timer whoever read the summary', async () => {
      api.summary.mockResolvedValue({ count: 0, subjects: [], readAgainIn: after(3 * DAY) });
      await facade.load('k1');
      await facade.load('k1');

      await vi.advanceTimersByTimeAsync(DAY + 1_000);
      expect(api.summary).toHaveBeenCalledTimes(3);
    });

    it('reads at once a tab shown again past the moment, which a hidden tab’s timer may have missed', async () => {
      api.summary.mockResolvedValue({ count: 0, subjects: [], readAgainIn: after(60_000) });
      await facade.load('k1');
      document.dispatchEvent(new Event('visibilitychange'));
      expect(api.summary).toHaveBeenCalledTimes(1);

      vi.setSystemTime(NOW + 120_000);
      document.dispatchEvent(new Event('visibilitychange'));
      expect(api.summary).toHaveBeenCalledTimes(2);
    });

    it('drops an answer for a company the session left while it was asked, and reads it no more', async () => {
      const session = TestBed.inject(SessionData);
      session.heard({ user: { id: 'u1' }, company: { id: 'k1' } });
      let answer: (summary: unknown) => void = () => undefined;
      api.summary.mockReturnValueOnce(new Promise((resolve) => (answer = resolve)));
      const asked = facade.load('k1');

      session.heard({ user: { id: 'u1' }, company: { id: 'k2' } });
      answer({ count: 7, subjects: [], readAgainIn: 60 });
      await asked;
      await vi.advanceTimersByTimeAsync(61_000);

      expect(api.summary).toHaveBeenCalledTimes(1);
      expect(facade.summary()).toBeNull();
    });

    it('stops reading a company the session has left', async () => {
      const session = TestBed.inject(SessionData);
      session.heard({ user: { id: 'u1' }, company: { id: 'k1' } });
      api.summary.mockResolvedValue({ count: 0, subjects: [], readAgainIn: after(60_000) });
      await facade.load('k1');

      session.heard({ user: { id: 'u1' }, company: { id: 'k2' } });
      await vi.advanceTimersByTimeAsync(DAY);
      vi.setSystemTime(NOW + 2 * DAY);
      document.dispatchEvent(new Event('visibilitychange'));
      expect(api.summary).toHaveBeenCalledTimes(1);
    });
  });
});
