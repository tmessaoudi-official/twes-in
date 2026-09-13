// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { NotificationsApi } from './notifications-api';

describe('NotificationsApi', () => {
  let api: NotificationsApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(NotificationsApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('reads the centre', async () => {
    const listed = api.list();
    http.expectOne({ method: 'GET', url: '/api/me/notifications' }).flush({
      items: [
        {
          id: 'n1',
          type: 'membership.added',
          payload: { company: 'Acme' },
          companyId: null,
          createdAt: '2026-09-13T10:00:00+00:00',
          readAt: null,
        },
      ],
      unread: 1,
    });

    await expect(listed).resolves.toEqual({
      items: [
        {
          id: 'n1',
          type: 'membership.added',
          payload: { company: 'Acme' },
          companyId: null,
          createdAt: '2026-09-13T10:00:00+00:00',
          readAt: null,
        },
      ],
      unread: 1,
    });
  });

  it('marks one read at its own address', async () => {
    const marked = api.markRead('0198f0c4-8a3e-7b2c-9d1e-2f3a4b5c6d7e');
    http
      .expectOne({
        method: 'POST',
        url: '/api/me/notifications/0198f0c4-8a3e-7b2c-9d1e-2f3a4b5c6d7e/read',
      })
      .flush(null, { status: 204, statusText: 'No Content' });

    await expect(marked).resolves.toBeUndefined();
  });

  it('marks all read', async () => {
    const marked = api.markAllRead();
    http
      .expectOne({ method: 'POST', url: '/api/me/notifications/read-all' })
      .flush(null, { status: 204, statusText: 'No Content' });

    await expect(marked).resolves.toBeUndefined();
  });

  it('returns only the token string of a realtime token', async () => {
    const token = api.realtimeToken();
    http
      .expectOne({ method: 'GET', url: '/api/me/realtime-token' })
      .flush({ token: 'a.b.c', expiresAt: '2026-09-13T10:15:00+00:00' });

    await expect(token).resolves.toBe('a.b.c');
  });

  it('lets a refusal reach the caller', async () => {
    const listed = api.list();
    http
      .expectOne('/api/me/notifications')
      .flush({ error: 'authentication_required' }, { status: 401, statusText: 'Unauthorized' });

    await expect(listed).rejects.toBeTruthy();
  });
});
