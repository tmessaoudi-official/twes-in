// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideTranslateService } from '@ngx-translate/core';
import { LegalIdentityRefused, PlatformLegalApi } from './platform-legal-api';
import { TextsParts } from '../shared/i18n/texts-part';
import { RECORD_CHANGED } from '../shared/errors/record-changed';

describe('PlatformLegalApi', () => {
  let api: PlatformLegalApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting(), provideTranslateService()],
    });
    api = TestBed.inject(PlatformLegalApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('reads the identity from the legal.* platform settings, by placeholder, in their order, the empty ones included', async () => {
    const identity = api.identity();
    http.expectOne('/api/platform/settings').flush([
      { key: 'signup.enabled', value: true, versions: { platform: 9 } },
      { key: 'legal.publisher.name', value: 'twes SAS', versions: { platform: 2 } },
      { key: 'legal.host.name', value: '', versions: [] },
      { key: 'platform.country', value: 'FR', choices: ['FR', 'TN'], versions: [] },
    ]);
    const read = await identity;
    expect(read).toEqual({
      values: { 'publisher.name': 'twes SAS', 'host.name': '' },
      versions: { 'publisher.name': 2, 'host.name': 0 },
      // The platform's own country, which its phone fields start on.
      country: 'FR',
    });
    expect(Object.keys(read.values)).toEqual(['publisher.name', 'host.name']);
  });

  it('saves every fact changed as its platform setting, together, each with the version read', async () => {
    const saved = api.saveIdentity([
      { placeholder: 'host.name', value: 'OVH SAS', version: 0 },
      { placeholder: 'publisher.name', value: 'twes', version: 2 },
    ]);
    const request = http.expectOne('/api/platform/settings');
    expect(request.request.method).toBe('PUT');
    expect(request.request.body).toEqual({
      changes: [
        { key: 'legal.host.name', value: 'OVH SAS', version: 0 },
        { key: 'legal.publisher.name', value: 'twes', version: 2 },
      ],
    });
    request.flush(null, { status: 204, statusText: 'No Content' });
    expect(await saved).toBe('saved');

    const since = api.saveIdentity([{ placeholder: 'host.name', value: 'OVH', version: 1 }]);
    http
      .expectOne('/api/platform/settings')
      .flush({ type: RECORD_CHANGED, status: 409 }, { status: 409, statusText: 'Conflict' });
    expect(await since).toBe('saved_since');
  });

  it('names each field the API refused by its placeholder, and opens the settings’ refusal texts first', async () => {
    const open = vi.spyOn(TestBed.inject(TextsParts), 'open').mockResolvedValue(undefined);
    const saved = api.saveIdentity([
      { placeholder: 'publisher.email', value: 'contact@twes', version: 1 },
    ]);
    http.expectOne('/api/platform/settings').flush(
      {
        violations: [
          { propertyPath: 'legal.publisher.email', payload: { reason: 'invalid_email' } },
        ],
      },
      { status: 422, statusText: 'Unprocessable Content' },
    );

    const refused = await saved.catch((error: unknown) => error);
    expect(refused).toBeInstanceOf(LegalIdentityRefused);
    expect((refused as LegalIdentityRefused).refusals).toEqual([
      { field: 'publisher.email', reason: 'invalid_email', params: {} },
    ]);
    expect(open).toHaveBeenCalledWith('settings-refusals');
    // A phone's refusals are said in the form refusals' words, where every phone field reads them.
    expect(open).toHaveBeenCalledWith('form-refusals');
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
