// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { MemberDevicesApi, MemberDevicesRefused } from './member-devices-api';

describe('MemberDevicesApi', () => {
  let http: HttpTestingController;
  let api: MemberDevicesApi;
  const sessions = '/api/companies/c1/members/u2/sessions';

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    http = TestBed.inject(HttpTestingController);
    api = TestBed.inject(MemberDevicesApi);
  });

  afterEach(() => http.verify());

  async function refusal(answer: Promise<unknown>): Promise<unknown> {
    try {
      await answer;
    } catch (error) {
      return error instanceof MemberDevicesRefused ? error.code : error;
    }
    throw new Error('not refused');
  }

  it('lists a member’s devices in the company', async () => {
    const answer = api.list('c1', 'u2');
    http.expectOne({ method: 'GET', url: sessions }).flush([]);
    expect(await answer).toEqual([]);
  });

  it('signs one out by its id, and all of them, saying how many', async () => {
    const one = api.end('c1', 'u2', 's1');
    http.expectOne({ method: 'DELETE', url: `${sessions}/s1` }).flush(null);
    await one;

    const all = api.endAll('c1', 'u2');
    http.expectOne({ method: 'DELETE', url: sessions }).flush({ ended: 2 });
    expect(await all).toBe(2);
  });

  it.each([
    'owner_only',
    'own_sessions',
    'member_not_found',
    'session_not_found',
    'step_up_required',
  ])('names the refusal %s', async (code) => {
    const answer = refusal(api.end('c1', 'u2', 's1'));
    http
      .expectOne(`${sessions}/s1`)
      .flush({ error: code }, { status: code.endsWith('not_found') ? 404 : 403, statusText: 'No' });
    expect(await answer).toBe(code);
  });

  it('says a lost connection as the network, and a code it has no words for as that code', async () => {
    const lost = refusal(api.list('c1', 'u2'));
    http.expectOne(sessions).flush(null, { status: 503, statusText: 'Unavailable' });
    expect(await lost).toBe('network');

    const later = refusal(api.list('c1', 'u2'));
    http
      .expectOne(sessions)
      .flush({ error: 'added_later' }, { status: 409, statusText: 'Conflict' });
    expect(await later).toEqual({ unknown: 'added_later' });
  });
});
