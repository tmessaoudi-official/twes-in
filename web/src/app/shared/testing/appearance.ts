// SPDX-License-Identifier: AGPL-3.0-or-later

import { type Provider, signal } from '@angular/core';
import { LanguageFacade } from '../i18n/language-facade';
import { ThemeFacade } from '../theme/theme-facade';

/**
 * For a spec rendering a page that carries the language and scheme menus (every signed-out page) but tests neither:
 * both facades stand still in French and Automatique, so the page needs no settings port.
 */
export function provideStillAppearance(): Provider[] {
  return [
    { provide: ThemeFacade, useValue: { preference: signal('auto'), setScheme: () => undefined } },
    {
      provide: LanguageFacade,
      useValue: { current: signal('fr'), use: async () => undefined },
    },
  ];
}
