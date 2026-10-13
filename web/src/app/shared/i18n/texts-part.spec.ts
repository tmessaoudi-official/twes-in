// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import {
  provideTranslateCompiler,
  provideTranslateService,
  TranslatePipe,
  TranslateService,
} from '@ngx-translate/core';
import { provideTranslateHttpLoader } from '@ngx-translate/http-loader';
import { Session } from '../session/session';
import { BrowserStorageSettings } from '../settings/browser-storage-settings';
import { PageMemoryStorage, SETTINGS_STORAGE, SettingsFacade } from '../settings/settings-facade';
import { LanguageFacade } from './language-facade';
import { PluralCompiler } from './plural-compiler';
import { textsPart } from './texts-part';

@Component({
  imports: [TranslatePipe],
  template: `<p>
    {{ 'part.remove' | translate }} · {{ 'shared.yes' | translate }} ·
    {{ 'part.items' | translate: { count: 2 } }}
  </p>`,
})
class PartPage {}

const ROOT = {
  fr: { shared: { yes: 'Oui' } },
  en: { shared: { yes: 'Yes' } },
};
const PART = {
  fr: {
    part: {
      remove: 'Supprimer',
      items: '{{count}} {count, plural, one {élément} other {éléments}}',
    },
  },
  en: { part: { remove: 'Delete', items: '{{count}} {count, plural, one {item} other {items}}' } },
};

/**
 * A page whose texts only it reads brings them itself, in a file of their own beside the language's, so the file
 * every page waits for stays small (docs/SPEC.md § 7, the screen texts budget). Its words are there before it shows,
 * and a language switch loads them before the page changes language, so it never shows a key or two languages at once.
 */
describe('textsPart', () => {
  let http: HttpTestingController;
  let harness: RouterTestingHarness;

  /** Lets the router and the loaders run up to their next request. */
  function settled(): Promise<void> {
    return new Promise((resolve) => setTimeout(resolve));
  }

  function text(): string {
    return harness.routeNativeElement?.textContent?.trim() ?? '';
  }

  beforeEach(async () => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateHttpLoader({ prefix: '/i18n/', suffix: '.json' }),
          compiler: provideTranslateCompiler(PluralCompiler),
        }),
        provideRouter([{ path: 'part', ...textsPart('part'), component: PartPage }]),
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
        { provide: Session, useValue: { me: () => null } },
      ],
    });
    http = TestBed.inject(HttpTestingController);
    TestBed.inject(TranslateService);
    harness = await RouterTestingHarness.create();
    http.expectOne('/i18n/fr.json').flush(ROOT.fr);
  });

  afterEach(() => http.verify());

  it('loads the page’s own texts before showing it, beside the language’s', async () => {
    const navigation = TestBed.inject(Router).navigateByUrl('/part');
    await settled();
    harness.detectChanges();
    expect(text()).not.toContain('part.');
    http.expectOne('/i18n/part/fr.json').flush(PART.fr);
    await navigation;
    harness.detectChanges();

    expect(text()).toBe('Supprimer · Oui · 2 éléments');
  });

  it('has the page’s texts in the new language before the language changes', async () => {
    const navigation = TestBed.inject(Router).navigateByUrl('/part');
    await settled();
    http.expectOne('/i18n/part/fr.json').flush(PART.fr);
    await navigation;
    harness.detectChanges();

    // Whichever file the network answers first, the language's own is answered first here: the page must still
    // show one language at every moment.
    const switching = TestBed.inject(LanguageFacade).use('en');
    const shown: string[] = [];
    for (const url of ['/i18n/en.json', '/i18n/part/en.json', '/i18n/en.json']) {
      await settled();
      for (const request of http.match(url)) {
        request.flush(url === '/i18n/en.json' ? ROOT.en : PART.en);
        await settled();
        harness.detectChanges();
        shown.push(text());
      }
    }
    await switching;
    harness.detectChanges();

    expect(
      shown.filter(
        (seen) => !['Supprimer · Oui · 2 éléments', 'Delete · Yes · 2 items'].includes(seen),
      ),
    ).toEqual([]);
    expect(text()).toBe('Delete · Yes · 2 items');
  });

  it('does not load the page’s texts while the page is not opened', () => {
    http.expectNone('/i18n/part/fr.json');
  });
});
