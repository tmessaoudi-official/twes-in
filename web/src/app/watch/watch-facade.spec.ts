// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { WatchApi } from './watch-api';
import { WatchFacade } from './watch-facade';
import { WatchSubjectGone } from './watch-types';

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
    api.summary.mockResolvedValueOnce({ count: 2, subjects: [] });
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
});
