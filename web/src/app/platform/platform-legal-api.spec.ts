// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { PlatformLegalApi } from './platform-legal-api';

describe('PlatformLegalApi', () => {
  let api: PlatformLegalApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(PlatformLegalApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('reads the identity from the legal.* platform settings, by placeholder, in their order, the empty ones included', async () => {
    const identity = api.identity();
    http.expectOne('/api/platform/settings').flush([
      { key: 'signup.enabled', value: true },
      { key: 'legal.publisher.name', value: 'twes SAS' },
      { key: 'legal.host.name', value: '' },
    ]);
    const read = await identity;
    expect(read).toEqual({ 'publisher.name': 'twes SAS', 'host.name': '' });
    expect(Object.keys(read)).toEqual(['publisher.name', 'host.name']);
  });

  it('saves one fact as its platform setting', async () => {
    const saved = api.setIdentity('host.name', 'OVH SAS');
    const request = http.expectOne('/api/platform/settings/legal.host.name');
    expect(request.request.method).toBe('PUT');
    expect(request.request.body).toEqual({ value: 'OVH SAS' });
    request.flush({ key: 'legal.host.name', value: 'OVH SAS' });
    await saved;
  });

  it('writes a new version and validates the latest at the page and language path', async () => {
    const written = api.write('mentions', 'ar', 'نص');
    const write = http.expectOne('/api/platform/legal-texts/mentions/ar/versions');
    expect(write.request.method).toBe('POST');
    expect(write.request.body).toEqual({ body: 'نص' });
    write.flush({
      id: 'v1',
      page: 'mentions',
      language: 'ar',
      body: 'نص',
      publishedOn: '2026-09-27',
      validated: false,
      validatedAt: null,
      validatedBy: null,
      createdAt: '2026-09-27T10:00:00+00:00',
      createdBy: 'op@twes.local',
    });
    expect((await written).createdBy).toBe('op@twes.local');

    const validated = api.validate('mentions', 'ar');
    http.expectOne('/api/platform/legal-texts/mentions/ar/validate').flush({
      id: 'v1',
      page: 'mentions',
      language: 'ar',
      body: 'نص',
      publishedOn: '2026-09-27',
      validated: true,
      validatedAt: '2026-09-27T11:00:00+00:00',
      validatedBy: 'op@twes.local',
      createdAt: '2026-09-27T10:00:00+00:00',
      createdBy: 'op@twes.local',
    });
    expect((await validated).validated).toBe(true);
  });
});
