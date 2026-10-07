// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { SILENT } from '../feedback/activity-interceptor';
import { BuildApi } from './build-api';

describe('BuildApi', () => {
  let api: BuildApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(BuildApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('asks the server itself each time, and quietly: a poll is nothing the person did', async () => {
    const web = api.web();
    const request = http.expectOne('/version.json');
    expect(request.request.headers.get('Cache-Control')).toBe('no-cache');
    expect(request.request.context.get(SILENT)).toBe(true);
    request.flush({ version: '2026.10.07.3', commit: 'aaaa1111' });
    expect(await web).toEqual({ version: '2026.10.07.3', commit: 'aaaa1111' });

    const health = api.api();
    const asked = http.expectOne('/api/health');
    expect(asked.request.headers.get('Cache-Control')).toBe('no-cache');
    expect(asked.request.context.get(SILENT)).toBe(true);
    asked.flush({
      status: 'ok',
      database: 'ok',
      build: { version: '2026.10.07.5', commit: 'bbbb2222', mode: 'prod' },
      deployment: null,
    });
    expect(await health).toEqual({
      version: '2026.10.07.5',
      commit: 'bbbb2222',
      mode: 'prod',
      deployment: null,
    });
  });

  it('reads a web with no build file as unversioned, an empty version as none, and silence as nothing known', async () => {
    const missing = api.web();
    http.expectOne('/version.json').flush('', { status: 404, statusText: 'Not Found' });
    expect(await missing).toEqual({ version: null, commit: null });

    const empty = api.web();
    http.expectOne('/version.json').flush({ version: '', commit: '' });
    expect(await empty).toEqual({ version: null, commit: null });

    const down = api.web();
    http.expectOne('/version.json').error(new ProgressEvent('error'));
    expect(await down).toBeNull();
  });

  it('reads the build of a degraded API, which still says it', async () => {
    const health = api.api();
    http.expectOne('/api/health').flush(
      {
        status: 'degraded',
        database: 'unreachable',
        build: { version: '2026.10.07.5', commit: 'bbbb2222', mode: 'dev' },
        deployment: 'staging',
      },
      { status: 503, statusText: 'Service Unavailable' },
    );
    expect(await health).toEqual({
      version: '2026.10.07.5',
      commit: 'bbbb2222',
      mode: 'dev',
      deployment: 'staging',
    });

    const unreachable = api.api();
    http.expectOne('/api/health').error(new ProgressEvent('error'));
    expect(await unreachable).toBeNull();
  });
});
