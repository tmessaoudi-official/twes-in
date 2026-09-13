// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { SettingsApi, SettingsRefused } from './settings-api';

const density = {
  key: 'presentation.density',
  chain: 'presentation',
  type: 'enum',
  labelKey: 'settings.presentation.density',
  module: 'core',
  default: 'comfortable',
  value: 'compact',
  source: 'company',
  levels: [{ level: 'company', value: 'compact' }],
  overridableLevels: ['company', 'role', 'user'],
  writableLevels: ['user'],
  choices: ['comfortable', 'compact'],
  min: null,
  max: null,
  maxLength: null,
};

describe('SettingsApi', () => {
  let api: SettingsApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(SettingsApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('reads one chain of a company', async () => {
    const rows = api.chain('c1', 'presentation');
    const request = http.expectOne('/api/companies/c1/settings?chain=presentation');

    expect(request.request.method).toBe('GET');
    request.flush([density]);
    await expect(rows).resolves.toEqual([
      {
        key: 'presentation.density',
        chain: 'presentation',
        type: 'enum',
        labelKey: 'settings.presentation.density',
        module: 'core',
        defaultValue: 'comfortable',
        value: 'compact',
        source: 'company',
        levels: [{ level: 'company', value: 'compact' }],
        overridableLevels: ['company', 'role', 'user'],
        writableLevels: ['user'],
        choices: ['comfortable', 'compact'],
        min: null,
        max: null,
        maxLength: null,
      },
    ]);
  });

  it('stores a value at one level', async () => {
    const changed = api.change('c1', 'presentation.density', 'user', 'compact');
    const request = http.expectOne('/api/companies/c1/settings/presentation.density');

    expect(request.request.method).toBe('PUT');
    expect(request.request.body).toEqual({ level: 'user', value: 'compact' });
    request.flush({ ...density, source: 'user' });
    await expect(changed).resolves.toMatchObject({ source: 'user' });
  });

  it('stores a role default for the role it names', async () => {
    const changed = api.change('c1', 'presentation.scheme', 'role', 'dark', 'r1');
    const request = http.expectOne('/api/companies/c1/settings/presentation.scheme');

    expect(request.request.body).toEqual({ level: 'role', value: 'dark', roleId: 'r1' });
    request.flush({ ...density, key: 'presentation.scheme', source: 'role' });
    await changed;
  });

  it('forgets the value stored at one level', async () => {
    const reset = api.reset('c1', 'presentation.density', 'user');
    const request = http.expectOne('/api/companies/c1/settings/presentation.density?level=user');

    expect(request.request.method).toBe('DELETE');
    request.flush(null, { status: 204, statusText: 'No Content' });
    await expect(reset).resolves.toBeUndefined();
  });

  it('names what the API refused', async () => {
    const invalid = api.change('c1', 'presentation.density', 'user', 'cosy');
    http
      .expectOne('/api/companies/c1/settings/presentation.density')
      .flush({}, { status: 422, statusText: 'Unprocessable' });
    await expect(invalid).rejects.toEqual(new SettingsRefused('invalid'));

    const missing = api.chain('c2', 'presentation');
    http
      .expectOne('/api/companies/c2/settings?chain=presentation')
      .flush({}, { status: 404, statusText: 'Not Found' });
    await expect(missing).rejects.toEqual(new SettingsRefused('not_found'));

    const offline = api.reset('c1', 'presentation.density', 'user');
    http
      .expectOne('/api/companies/c1/settings/presentation.density?level=user')
      .error(new ProgressEvent('error'));
    await expect(offline).rejects.toEqual(new SettingsRefused('network'));
  });
});
