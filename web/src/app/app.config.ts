// SPDX-License-Identifier: AGPL-3.0-or-later
import { provideHttpClient } from '@angular/common/http';
import { ApplicationConfig, provideBrowserGlobalErrorListeners } from '@angular/core';
import { provideRouter } from '@angular/router';
import { provideTranslateService } from '@ngx-translate/core';
import { provideTranslateHttpLoader } from '@ngx-translate/http-loader';
import { routes } from './app.routes';

export const appConfig: ApplicationConfig = {
  providers: [
    provideBrowserGlobalErrorListeners(),
    provideRouter(routes),
    provideHttpClient(),
    // French first (Tunisia, France); English second. Files live in public/i18n/<lang>.json.
    provideTranslateService({
      lang: 'fr',
      fallbackLang: 'fr',
      loader: provideTranslateHttpLoader({ prefix: '/i18n/', suffix: '.json' }),
    }),
  ],
};
