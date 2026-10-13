// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, Injector } from '@angular/core';
import { toObservable } from '@angular/core/rxjs-interop';
import type { CanActivateFn, Route } from '@angular/router';
import {
  provideChildTranslateService,
  provideTranslateCompiler,
  TranslateService,
} from '@ngx-translate/core';
import { provideTranslateHttpLoader } from '@ngx-translate/http-loader';
import { filter, firstValueFrom } from 'rxjs';
import { PluralCompiler } from './plural-compiler';

/**
 * The texts parts opened so far. A language switch loads each of them in the new language before the language
 * changes, so an opened page never shows its words in the old language beside the rest in the new one.
 */
@Injectable({ providedIn: 'root' })
export class TextsParts {
  private readonly parts = new Set<TranslateService>();
  private readonly root = inject(TranslateService);

  add(part: TranslateService): void {
    this.parts.add(part);
  }

  /**
   * Lends every opened part's texts to the root service, which a toast or a dialog translates through: both are drawn
   * from the root injector, never the page's, so a page's own words would otherwise reach them as keys. Only once the
   * root holds the language, or the root would take the part for the whole language and never load its own file.
   */
  share(language: string): void {
    if (this.root.getTranslations(language) === undefined) return;
    for (const part of this.parts) {
      const texts = part.getTranslations(language);
      if (texts !== undefined) this.root.setCompiledTranslation(language, texts, true);
    }
  }

  /** Nothing when every opened part already has the language, so a switch waits no longer than it did without them. */
  load(language: string): Promise<unknown> | undefined {
    const missing = [...this.parts].filter((part) => !part.getLangs().includes(language));
    return missing.length === 0
      ? undefined
      : Promise.all(missing.map((part) => firstValueFrom(part.reloadLang(language))));
  }
}

/** The route waits for its texts in the current language, so the page never shows a key. */
const textsLoaded: CanActivateFn = () => {
  const part = inject(TranslateService);
  const parts = inject(TextsParts);
  parts.add(part);
  return firstValueFrom(
    toObservable(part.isLoading, { injector: inject(Injector) }).pipe(
      filter((loading) => !loading),
    ),
  ).then(() => {
    const language = part.getCurrentLang();
    if (language !== null) parts.share(language);
    return true;
  });
};

/**
 * The texts only one page reads, in `/i18n/<name>/<language>.json` beside the language's own file, loaded when the
 * page is first opened rather than with the file every page waits for (the screen texts budget). ngx-translate's child
 * service: the page's keys first, every other key from the language's file, the language always the root's.
 */
export function textsPart(name: string): Pick<Route, 'providers' | 'canActivate'> {
  return {
    providers: [
      provideChildTranslateService({
        loader: provideTranslateHttpLoader({ prefix: `/i18n/${name}/`, suffix: '.json' }),
        compiler: provideTranslateCompiler(PluralCompiler),
      }),
    ],
    canActivate: [textsLoaded],
  };
}
