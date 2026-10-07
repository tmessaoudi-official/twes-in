// SPDX-License-Identifier: AGPL-3.0-or-later

import { type Provider, signal } from '@angular/core';
import { BuildInfo } from '../build/build-info';

/**
 * For a spec rendering a page that carries the legal line (every page) but tests not the builds it ends on: the line
 * shows one build that never changes, and nothing asks the server which one is out.
 */
export function provideQuietBuild(): Provider[] {
  return [
    {
      provide: BuildInfo,
      useValue: {
        web: signal({ version: '2026.10.07.1', commit: 'aaaa1111' }),
        api: signal(null),
        newWeb: signal(false),
        webMode: null,
        line: signal('Web 2026.10.07.1 (aaaa1111)'),
        start: () => undefined,
      },
    },
  ];
}
