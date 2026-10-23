// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { SILENT } from '../feedback/activity-interceptor';
import { RECORD_CHANGED } from '../errors/record-changed';
import { TextsParts } from '../i18n/texts-part';
import { refusalsOf, SettingsApi, SettingsRefused } from './settings-api';

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
  let opened: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    opened = vi.fn(() => Promise.resolve());
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: TextsParts, useValue: { open: opened } },
      ],
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
        patternReason: null,
      },
    ]);
    expect(opened).not.toHaveBeenCalled();
  });

  it('reads what a parties setting holds across the company, the parties untold to who may not read customers', async () => {
    const told = api.valuesInUse('c1', 'document.language');
    http
      .expectOne('/api/companies/c1/settings/document.language/in-use')
      .flush({ key: 'document.language', company: 'fr', parties: ['ar', 'en'] });
    await expect(told).resolves.toEqual({ company: 'fr', parties: ['ar', 'en'] });

    const untold = api.valuesInUse('c1', 'document.language');
    http
      .expectOne('/api/companies/c1/settings/document.language/in-use')
      .flush({ key: 'document.language', company: 'fr', parties: null });
    await expect(untold).resolves.toEqual({ company: 'fr', parties: null });
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

  it('places each setting a save refused on its field, with the words it is said in opened first', async () => {
    const saved = api.changeAll('c1', 'company', [
      { key: 'late_fees.tiers', value: '5 ; x', version: 0 },
      { key: 'reminders.stages', value: '7, 15, 30, 45, 60, 90', version: 0 },
    ]);
    http.expectOne('/api/companies/c1/settings').flush(
      {
        violations: [
          { propertyPath: 'late_fees.tiers', payload: { reason: 'invalid_late_fee_tiers' } },
          {
            propertyPath: 'reminders.stages[5]',
            payload: { reason: 'at_most', params: { max: 5 } },
          },
        ],
      },
      { status: 422, statusText: 'Unprocessable' },
    );

    const refused = await saved.then(
      () => null,
      (error: unknown) => error,
    );
    expect(refused).toBeInstanceOf(SettingsRefused);
    expect(refusalsOf(refused)).toEqual([
      { field: 'late_fees__tiers', reason: 'invalid_late_fee_tiers', params: {} },
      { field: 'reminders__stages', reason: 'at_most', params: { max: 5 } },
    ]);
    expect(opened).toHaveBeenCalledWith('settings-refusals');
  });

  it('opens the words a field with a pattern is refused in when it reads its chain', async () => {
    const rows = api.chain('c1', 'parties');
    http
      .expectOne('/api/companies/c1/settings?chain=parties')
      .flush([{ ...density, pattern: '/^x$/', patternReason: 'invalid_reminder_stages' }]);

    await expect(rows).resolves.toMatchObject([{ patternReason: 'invalid_reminder_stages' }]);
    expect(opened).toHaveBeenCalledWith('settings-refusals');
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
