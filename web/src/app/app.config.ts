// SPDX-License-Identifier: AGPL-3.0-or-later

import { PluralCompiler } from './shared/i18n/plural-compiler';
import { SCAN_DETAILS } from './shared/scan/scan-details';
import { tabInterceptor } from './shared/realtime/tab-interceptor';
import { submissionInterceptor } from './shared/submissions/submission-interceptor';
import { liveChangesInterceptor } from './shared/realtime/live-changes-interceptor';
import { effectsInterceptor } from './shared/effects/effects-interceptor';
import { EFFECT_SAYERS } from './shared/effects/effects';
import { PriceEffects } from './products/price-effects';
import { languageInterceptor } from './shared/i18n/language-interceptor';
import { provideHttpClient, withInterceptors, withNoXsrfProtection } from '@angular/common/http';
import {
  ApplicationConfig,
  ErrorHandler,
  inject,
  Injector,
  provideAppInitializer,
  provideBrowserGlobalErrorListeners,
} from '@angular/core';
import { MAT_FORM_FIELD_DEFAULT_OPTIONS } from '@angular/material/form-field';
import { MatIconRegistry } from '@angular/material/icon';
import { provideRouter, TitleStrategy, withComponentInputBinding } from '@angular/router';
import { provideTranslateCompiler, provideTranslateService } from '@ngx-translate/core';
import { provideOverriddenLoader } from './shared/i18n/overridden-loader';
import { MAIN_TEXTS } from './shared/i18n/text-overrides';
import { TextsOverlay } from './shared/i18n/texts-overlay';
import { routes } from './app.routes';
import { PageTitles } from './shared/a11y/page-titles';
import { AuthFacade } from './auth/auth-facade';
import { Session } from './shared/session/session';
import { StepUpProof } from './shared/step-up/step-up-proof';
import { CustomerScreenHold } from './shared/customer-view/customer-screen-hold';
import { CompanyEntryProof } from './shared/company-proof/company-entry-proof';
import { companyProofInterceptor } from './shared/company-proof/company-proof-interceptor';
import { customerScreenInterceptor } from './shared/customer-view/customer-screen-interceptor';
import { supportReadOnlyInterceptor } from './shared/support/support-read-only-interceptor';
import { demoRefusedInterceptor } from './shared/demo/demo-refused-interceptor';
import { csrfInterceptor } from './auth/csrf-interceptor';
import { PasskeyClient } from './auth/passkey-client';
import { ApiSettings } from './shared/settings/api-settings';
import { BrowserStorageSettings } from './shared/settings/browser-storage-settings';
import { SettingsFacade } from './shared/settings/settings-facade';
import { activityInterceptor } from './shared/feedback/activity-interceptor';
import { Feedback } from './shared/feedback/feedback';
import { MaterialFeedback } from './shared/feedback/material-feedback';
import { BarcodeReader } from './shared/scan/barcode-reader';
import { BrowserCamera, Camera } from './shared/scan/camera';
import { ZxingBarcodeReader } from './shared/scan/zxing-barcode-reader';
import { trackNavigation } from './shared/feedback/navigation-activity';
import { ReportingErrorHandler } from './shared/errors/reporting-error-handler';
import { DocumentDirection } from './shared/i18n/document-direction';
import { AppInstall } from './shared/install/app-install';
import { LanguageFacade } from './shared/i18n/language-facade';
import { DEFAULT_LANGUAGE } from './shared/i18n/languages';
import { ThemeFacade } from './shared/theme/theme-facade';

export const appConfig: ApplicationConfig = {
  providers: [
    provideBrowserGlobalErrorListeners(),
    // Every uncaught error is also reported to the API, which carries it on to the error tracker.
    { provide: ErrorHandler, useClass: ReportingErrorHandler },
    // Route parameters arrive as component inputs; the invitation token is bound this way.
    provideRouter(routes, withComponentInputBinding()),
    // Each route's `title` is a translation key: the tab names the page in the interface language.
    { provide: TitleStrategy, useClass: PageTitles },
    // The API uses Symfony stateless CSRF (header only), not the cookie Angular built-in XSRF support echoes.
    provideHttpClient(
      withInterceptors([
        csrfInterceptor,
        tabInterceptor,
        submissionInterceptor,
        liveChangesInterceptor,
        effectsInterceptor,
        languageInterceptor,
        activityInterceptor,
        // After the activity bar's: a request sent again once its company is proved is one request to it.
        companyProofInterceptor,
        customerScreenInterceptor,
        supportReadOnlyInterceptor,
        demoRefusedInterceptor,
      ]),
      withNoXsrfProtection(),
    ),
    // The catalogue's default language first, and what a text missing in another language falls back to. Files live
    // in public/i18n/<lang>.json, the texts the installation and the company word otherwise laid over them.
    provideTranslateService({
      lang: DEFAULT_LANGUAGE,
      fallbackLang: DEFAULT_LANGUAGE,
      loader: provideOverriddenLoader('/i18n/', MAIN_TEXTS),
      // A count is said in the form its language gives it (`{count, plural, one {…} other {…}}`).
      compiler: provideTranslateCompiler(PluralCompiler),
    }),
    // Presentation preferences go through the API's presentation chain; the browser keeps them only for the pages
    // shown before anyone signs in.
    // Shared code reads the session through its port; the auth feature answers it.
    { provide: Session, useExisting: AuthFacade },
    // What a phone hears of a product it scanned reads the stock, which loads with the first scan, off the first page.
    {
      provide: SCAN_DETAILS,
      useFactory: (injector = inject(Injector)) => ({
        of: async (code: string) =>
          injector
            .get((await import('./products/product-scan-details')).ProductScanDetails)
            .of(code),
      }),
    },
    // What a request did beyond its subject is said by the feature it concerns, whichever page sent the request.
    { provide: EFFECT_SAYERS, useExisting: PriceEffects, multi: true },
    // Proving who is at the screen again is the auth feature's too.
    { provide: StepUpProof, useExisting: AuthFacade },
    // So is holding the sign-in on the customer screen, which the API keeps in the session.
    { provide: CustomerScreenHold, useExisting: AuthFacade },
    { provide: CompanyEntryProof, useExisting: AuthFacade },
    { provide: SettingsFacade, useClass: ApiSettings },
    // Outcomes are said in toasts; what the application waits for, in the activity bar (docs/SPEC.md § 8 row 48).
    { provide: Feedback, useExisting: MaterialFeedback },
    // The camera scans through two ports: this device's cameras, and the decoder that reads a frame (slice 3).
    { provide: Camera, useClass: BrowserCamera },
    { provide: BarcodeReader, useClass: ZxingBarcodeReader },
    BrowserStorageSettings,
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
      // The remembered language is applied before any page, a signed-out one included, and the components follow the
      // direction it gives the document.
      inject(DocumentDirection);
      inject(LanguageFacade);
      // The texts the installation and the company word otherwise, laid again as a company is entered or a text changes.
      inject(TextsOverlay);
      // The browser says once, early, that the application may be installed: heard before any page.
      inject(AppInstall);
      // Where the installation's passkeys work, read once before the sign-in page: a page at another address (a shop's
      // network one) offers none, rather than a ceremony the browser refuses. Not awaited: the browser decides meanwhile.
      void inject(PasskeyClient).read();
      trackNavigation();
    }),
  ],
};
