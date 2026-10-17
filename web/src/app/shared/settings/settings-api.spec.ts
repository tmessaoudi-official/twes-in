// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { SILENT } from '../feedback/activity-interceptor';
import { RECORD_CHANGED } from '../errors/record-changed';
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
  versions: { company: 3 },
  overridableLevels: ['company', 'role', 'user'],
  writableLevels: ['user'],
  choices: ['comfortable', 'compact'],
  min: null,
  max: null,
  maxLength: null,
  pattern: null,
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
        versions: { company: 3 },
        overridableLevels: ['company', 'role', 'user'],
        writableLevels: ['user'],
        choices: ['comfortable', 'compact'],
        min: null,
        max: null,
        maxLength: null,
        pattern: null,
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

  it('writes a click-as-you-go preference quietly, and a settings form save where the activity shows', async () => {
    const saved = api.change('c1', 'presentation.density', 'company', 'compact');
    const form = http.expectOne('/api/companies/c1/settings/presentation.density');
    expect(form.request.context.get(SILENT)).toBe(false);
    form.flush(density);
    await saved;

    const clicked = api.change(
      'c1',
      'presentation.density',
      'user',
      'compact',
      undefined,
      undefined,
      true,
    );
    const preference = http.expectOne('/api/companies/c1/settings/presentation.density');
    expect(preference.request.context.get(SILENT)).toBe(true);
    preference.flush(density);
    await clicked;

    const forgotten = api.reset('c1', 'presentation.density', 'user', undefined, undefined, true);
    const reset = http.expectOne('/api/companies/c1/settings/presentation.density?level=user');
    expect(reset.request.context.get(SILENT)).toBe(true);
    reset.flush(null, { status: 204, statusText: 'No Content' });
    await forgotten;
  });

  it('saves every change of a page at one level in one request, each with the version read', async () => {
    const saved = api.changeAll(
      'c1',
      'customer',
      [
        { key: 'document.payment_terms_days', value: 45, version: 2 },
        { key: 'customer.tags', value: ['a', 'b'], version: 0 },
      ],
      { customerId: 'k1' },
    );
    const request = http.expectOne('/api/companies/c1/settings');

    expect(request.request.method).toBe('PUT');
    expect(request.request.body).toEqual({
      level: 'customer',
      customerId: 'k1',
      changes: [
        { key: 'document.payment_terms_days', value: 45, version: 2 },
        { key: 'customer.tags', value: ['a', 'b'], version: 0 },
      ],
    });
    request.flush(null, { status: 204, statusText: 'No Content' });
    await expect(saved).resolves.toBeUndefined();
  });

  it('tells a save made over a version saved since from another conflict', async () => {
    const since = api.changeAll('c1', 'company', [{ key: 'k', value: 1, version: 1 }]);
    http
      .expectOne('/api/companies/c1/settings')
      .flush({ type: RECORD_CHANGED, status: 409 }, { status: 409, statusText: 'Conflict' });
    await expect(since).rejects.toEqual(new SettingsRefused('record_changed'));

    const other = api.changeAll('c1', 'company', [{ key: 'k', value: 1, version: 1 }]);
    http
      .expectOne('/api/companies/c1/settings')
      .flush({ type: '/errors/409' }, { status: 409, statusText: 'Conflict' });
    await expect(other).rejects.toEqual(new SettingsRefused('network'));
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
