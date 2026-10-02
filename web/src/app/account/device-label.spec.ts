// SPDX-License-Identifier: AGPL-3.0-or-later

import { describe, expect, it } from 'vitest';
import { deviceLabel } from './device-label';

describe('deviceLabel', () => {
  it.each([
    [
      'Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0',
      { browser: 'Firefox', system: 'Linux' },
    ],
    [
      'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36 Edg/129.0',
      { browser: 'Edge', system: 'Windows' },
    ],
    [
      'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/129.0 Safari/537.36',
      { browser: 'Chrome', system: 'Windows' },
    ],
    [
      'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile/15E148 Safari/604.1',
      { browser: 'Safari', system: 'iOS' },
    ],
    [
      'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/129.0 Mobile Safari/537.36',
      { browser: 'Chrome', system: 'Android' },
    ],
    [
      'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Version/18.0 Safari/605.1.15',
      { browser: 'Safari', system: 'macOS' },
    ],
  ])('reads %s', (userAgent, expected) => {
    expect(deviceLabel(userAgent)).toEqual(expected);
  });

  it('says nothing of a User-Agent it does not know, or of none', () => {
    expect(deviceLabel('curl/8.0')).toEqual({ browser: null, system: null });
    expect(deviceLabel('')).toEqual({ browser: null, system: null });
  });
});
