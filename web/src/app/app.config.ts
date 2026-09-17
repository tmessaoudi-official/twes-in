// SPDX-License-Identifier: AGPL-3.0-or-later

import { tabInterceptor } from './shared/realtime/tab-interceptor';
import { provideHttpClient, withInterceptors, withNoXsrfProtection } from '@angular/common/http';
import {
  ApplicationConfig,
  inject,
  provideAppInitializer,
  provideBrowserGlobalErrorListeners,
} from '@angular/core';
import { MAT_FORM_FIELD_DEFAULT_OPTIONS } from '@angular/material/form-field';
import { MatIconRegistry } from '@angular/material/icon';
import { MatPaginatorIntl } from '@angular/material/paginator';
import { provideRouter, withComponentInputBinding } from '@angular/router';
import { provideTranslateService } from '@ngx-translate/core';
import { provideTranslateHttpLoader } from '@ngx-translate/http-loader';
import { routes } from './app.routes';
import { AuthFacade } from './auth/auth-facade';
import { Session } from './shared/session/session';
import { csrfInterceptor } from './auth/csrf-interceptor';
import { ApiSettings } from './shared/settings/api-settings';
import { BrowserStorageSettings } from './shared/settings/browser-storage-settings';
import { SettingsFacade } from './shared/settings/settings-facade';
import { TranslatedPaginatorIntl } from './shared/list/translated-paginator-intl';
import { activityInterceptor } from './shared/feedback/activity-interceptor';
import { Feedback } from './shared/feedback/feedback';
import { MaterialFeedback } from './shared/feedback/material-feedback';
import { trackNavigation } from './shared/feedback/navigation-activity';
import { LanguageFacade } from './shared/i18n/language-facade';
import { ThemeFacade } from './shared/theme/theme-facade';

export const appConfig: ApplicationConfig = {
  providers: [
    provideBrowserGlobalErrorListeners(),
    // Route parameters arrive as component inputs; the invitation token is bound this way.
    provideRouter(routes, withComponentInputBinding()),
    // The API uses Symfony stateless CSRF (header only), not the cookie Angular built-in XSRF support echoes.
    provideHttpClient(
      withInterceptors([csrfInterceptor, tabInterceptor, activityInterceptor]),
      withNoXsrfProtection(),
    ),
    // French first (Tunisia, France); English second. Files live in public/i18n/<lang>.json.
    provideTranslateService({
      lang: 'fr',
      fallbackLang: 'fr',
      loader: provideTranslateHttpLoader({ prefix: '/i18n/', suffix: '.json' }),
    }),
    // Presentation preferences go through the API's presentation chain; the browser keeps them only for the pages
    // shown before anyone signs in.
    // Shared code reads the session through its port; the auth feature answers it.
    { provide: Session, useExisting: AuthFacade },
    { provide: SettingsFacade, useClass: ApiSettings },
    // Outcomes are said in toasts; what the application waits for, in the activity bar (docs/SPEC.md § 8 row 48).
    { provide: Feedback, useExisting: MaterialFeedback },
    BrowserStorageSettings,
    // Every paginator's labels follow the chosen language.
    { provide: MatPaginatorIntl, useClass: TranslatedPaginatorIntl },
    // The approved component sheet draws every field as an outlined box (styles.scss sets its size and corners),
    // and a hint that wraps pushes the next field down instead of running into it.
    {
      provide: MAT_FORM_FIELD_DEFAULT_OPTIONS,
      useValue: { appearance: 'outline', subscriptSizing: 'dynamic' },
    },
    // Icons are Material Symbols ligatures (the material-symbols package), and the theme is applied before the
    // first page renders, so nothing paints in the compiled fallback colours for longer than a frame.
    provideAppInitializer(() => {
      inject(MatIconRegistry).setDefaultFontSetClass('material-symbols-outlined');
      inject(ThemeFacade);
      // The remembered language is applied before any page, a signed-out one included.
      inject(LanguageFacade);
      trackNavigation();
    }),
  ],
};
