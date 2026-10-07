// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { BuildApi } from './build-api';
import { BUILD_CHECK_INTERVAL, BuildInfo, WEB_MODE } from './build-info';
import type { ApiBuild, PartBuild } from './build-types';

const web = (version: string, commit = 'aaaa1111'): PartBuild => ({ version, commit });
const api = (version: string, changes: Partial<ApiBuild> = {}): ApiBuild => ({
  version,
  commit: 'bbbb2222',
  mode: 'prod',
  deployment: 'prod',
  ...changes,
});

// docs/SPEC.md § 7, 2026-10-07 14:28 and 14:31: a new web build shows a banner, a new API only the footer.
describe('BuildInfo', () => {
  const apis = { web: vi.fn(), api: vi.fn() };
  let info: BuildInfo;

  async function settle(): Promise<void> {
    for (let i = 0; i < 5; i++) await Promise.resolve();
  }

  function create(mode: 'dev' | null = null): void {
    TestBed.configureTestingModule({
      providers: [
        { provide: BuildApi, useValue: apis },
        { provide: BUILD_CHECK_INTERVAL, useValue: 1000 },
        { provide: WEB_MODE, useValue: mode },
      ],
    });
    info = TestBed.inject(BuildInfo);
  }

  beforeEach(() => {
    vi.useFakeTimers();
    apis.web.mockReset().mockResolvedValue(web('2026.10.07.3'));
    apis.api.mockReset().mockResolvedValue(api('2026.10.07.5'));
  });

  afterEach(() => vi.useRealTimers());

  it('takes the first web build it reads as the one this page runs, and the API as it is', async () => {
    create();
    info.start();
    await settle();

    expect(info.web()).toEqual(web('2026.10.07.3'));
    expect(info.api()).toEqual(api('2026.10.07.5'));
    expect(info.newWeb()).toBe(false);
  });

  it('says a new web build is out once the server holds another, and never takes it as its own', async () => {
    create();
    info.start();
    await settle();

    apis.web.mockResolvedValue(web('2026.10.07.3'));
    await vi.advanceTimersByTimeAsync(1000);
    expect(info.newWeb()).toBe(false);

    apis.web.mockResolvedValue(web('2026.10.07.4', 'cccc3333'));
    await vi.advanceTimersByTimeAsync(1000);
    expect(info.newWeb()).toBe(true);
    expect(info.web()).toEqual(web('2026.10.07.3'));
  });

  it('takes a rebuilt commit of the same day as new, the version alone being the same', async () => {
    create();
    info.start();
    await settle();

    apis.web.mockResolvedValue(web('2026.10.07.3', 'dddd4444'));
    await vi.advanceTimersByTimeAsync(1000);
    expect(info.newWeb()).toBe(true);
  });

  it('follows a new API quietly, with no banner', async () => {
    create();
    info.start();
    await settle();

    apis.api.mockResolvedValue(api('2026.10.07.6'));
    await vi.advanceTimersByTimeAsync(1000);
    expect(info.api()?.version).toBe('2026.10.07.6');
    expect(info.newWeb()).toBe(false);
  });

  it('keeps what it knew when the server does not answer, and is never told a new build by silence', async () => {
    create();
    info.start();
    await settle();

    apis.web.mockResolvedValue(null);
    apis.api.mockResolvedValue(null);
    await vi.advanceTimersByTimeAsync(1000);
    expect(info.web()).toEqual(web('2026.10.07.3'));
    expect(info.api()?.version).toBe('2026.10.07.5');
    expect(info.newWeb()).toBe(false);
  });

  it('asks again when the page comes back into view or its window into focus, and starts only once', async () => {
    create();
    info.start();
    info.start();
    await settle();
    expect(apis.web).toHaveBeenCalledTimes(1);

    document.dispatchEvent(new Event('visibilitychange'));
    await settle();
    expect(apis.web).toHaveBeenCalledTimes(2);

    window.dispatchEvent(new Event('focus'));
    await settle();
    expect(apis.web).toHaveBeenCalledTimes(3);
  });

  it('writes the line support is given: each part, its hash and its mode, and the deployment, prod left unsaid', async () => {
    create('dev');
    apis.api.mockResolvedValue(api('2026.10.07.5', { mode: 'dev', deployment: 'staging' }));
    info.start();
    await settle();

    expect(info.webMode).toBe('dev');
    expect(info.line()).toBe(
      'Web 2026.10.07.3 (aaaa1111) dev · API 2026.10.07.5 (bbbb2222) dev · staging',
    );

    apis.api.mockResolvedValue(api('2026.10.07.5'));
    await vi.advanceTimersByTimeAsync(1000);
    expect(info.line()).toBe('Web 2026.10.07.3 (aaaa1111) dev · API 2026.10.07.5 (bbbb2222)');
  });
});
