// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { AccountDeletionApi, AccountDeletionRefused } from './account-deletion-api';

describe('AccountDeletionApi', () => {
  let http: HttpTestingController;
  let api: AccountDeletionApi;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    http = TestBed.inject(HttpTestingController);
    api = TestBed.inject(AccountDeletionApi);
  });

  afterEach(() => http.verify());

  it('asks for the deletion and answers when it becomes final', async () => {
    const answer = api.delete();
    http
      .expectOne({ method: 'DELETE', url: '/api/auth/account' })
      .flush({ finalAt: '2026-10-20T15:00:00+00:00' });
    expect(await answer).toBe('2026-10-20T15:00:00+00:00');
  });

  it('names the companies a sole owner must hand over or close first', async () => {
    const answer = api.delete();
    http
      .expectOne('/api/auth/account')
      .flush(
        { error: 'sole_owner', companies: [{ id: 'c1', name: 'Atelier' }] },
        { status: 409, statusText: 'Conflict' },
      );
    const refusal = await answer.catch((error: unknown) => error);
    expect(refusal).toBeInstanceOf(AccountDeletionRefused);
    expect((refusal as AccountDeletionRefused).code).toBe('sole_owner');
    expect((refusal as AccountDeletionRefused).companies).toEqual([{ id: 'c1', name: 'Atelier' }]);
  });

  it.each([
    [403, 'step_up_required'],
    [409, 'platform_operator'],
  ])('reads a %s %s as its code', async (status, code) => {
    const answer = api.delete();
    http.expectOne('/api/auth/account').flush({ error: code }, { status, statusText: 'No' });
    const refusal = (await answer.catch((error: unknown) => error)) as AccountDeletionRefused;
    expect(refusal.code).toBe(code);
    expect(refusal.companies).toEqual([]);
  });

  it('says « refused » for an answer it does not know, and « network » when nothing came back', async () => {
    const strange = api.delete();
    http
      .expectOne('/api/auth/account')
      .flush({ error: 'whatever' }, { status: 500, statusText: 'No' });
    expect(((await strange.catch((e: unknown) => e)) as AccountDeletionRefused).code).toBe(
      'refused',
    );

    const silent = api.delete();
    http.expectOne('/api/auth/account').error(new ProgressEvent('error'), { status: 0 });
    expect(((await silent.catch((e: unknown) => e)) as AccountDeletionRefused).code).toBe(
      'network',
    );
  });
});
