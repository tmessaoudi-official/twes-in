// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient, withInterceptors, withNoXsrfProtection } from '@angular/common/http';
import {
  ApplicationConfig,
  inject,
  provideAppInitializer,
  provideBrowserGlobalErrorListeners,
} from '@angular/core';
import { MatIconRegistry } from '@angular/material/icon';
import { MatPaginatorIntl } from '@angular/material/paginator';
import { provideRouter, withComponentInputBinding } from '@angular/router';
import { provideTranslateService } from '@ngx-translate/core';
import { provideTranslateHttpLoader } from '@ngx-translate/http-loader';
import { routes } from './app.routes';
import { csrfInterceptor } from './auth/csrf-interceptor';
import { ApiSettings } from './shared/settings/api-settings';
import { BrowserStorageSettings } from './shared/settings/browser-storage-settings';
import { SettingsFacade } from './shared/settings/settings-facade';
import { TranslatedPaginatorIntl } from './shared/list/translated-paginator-intl';
import { ThemeFacade } from './shared/theme/theme-facade';

export const appConfig: ApplicationConfig = {
  providers: [
    provideBrowserGlobalErrorListeners(),
    // Route parameters arrive as component inputs; the invitation token is bound this way.
    provideRouter(routes, withComponentInputBinding()),
    // The API uses Symfony stateless CSRF (header only), not the cookie Angular built-in XSRF support echoes.
    provideHttpClient(withInterceptors([csrfInterceptor]), withNoXsrfProtection()),
    // French first (Tunisia, France); English second. Files live in public/i18n/<lang>.json.
    provideTranslateService({
      lang: 'fr',
      fallbackLang: 'fr',
      loader: provideTranslateHttpLoader({ prefix: '/i18n/', suffix: '.json' }),
    }),
    // Presentation preferences go through the API's presentation chain; the browser keeps them only for the pages
    // shown before anyone signs in.
    { provide: SettingsFacade, useClass: ApiSettings },
    BrowserStorageSettings,
    // Every paginator's labels follow the chosen language.
    { provide: MatPaginatorIntl, useClass: TranslatedPaginatorIntl },
    // Icons are Material Symbols ligatures (the material-symbols package), and the theme is applied before the
    // first page renders, so nothing paints in the compiled fallback colours for longer than a frame.
    provideAppInitializer(() => {
      inject(MatIconRegistry).setDefaultFontSetClass('material-symbols-outlined');
      inject(ThemeFacade);
    }),
  ],
};
