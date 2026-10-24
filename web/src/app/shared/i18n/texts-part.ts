// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, Injector } from '@angular/core';
import { toObservable } from '@angular/core/rxjs-interop';
import type { CanActivateFn, Route } from '@angular/router';
import {
  provideChildTranslateService,
  provideTranslateCompiler,
  TranslateService,
  type TranslationObject,
} from '@ngx-translate/core';
import { filter, firstValueFrom } from 'rxjs';
import { provideOverriddenLoader } from './overridden-loader';
import { PluralCompiler } from './plural-compiler';
import { laid, partTexts, TextOverrides } from './text-overrides';
import { TextsApi } from './texts-api';

/**
 * The texts parts opened so far. A language switch loads each of them in the new language before the language
 * changes, so an opened page never shows its words in the old language beside the rest in the new one.
 */
@Injectable({ providedIn: 'root' })
export class TextsParts {
  private readonly parts = new Set<TranslateService>();
  /** Each page's part by the name of its files, which its wording is read by. */
  private readonly names = new Map<TranslateService, string>();
  private readonly root = inject(TranslateService);
  private readonly api = inject(TextsApi);
  private readonly overrides = inject(TextOverrides);
  private readonly injector = inject(Injector);
  /** The parts opened into the root, by name, each language's texts as fetched. */
  private readonly opened = new Map<string, Map<string, Promise<TranslationObject>>>();

  add(part: TranslateService, name: string): void {
    this.parts.add(part);
    this.names.set(part, name);
  }

  /**
   * Opens texts several screens read, and the dialogs over any of them, into the root service: a schedule's words, read
   * on two lists and in a dialog an invoice opens wherever it is shown. Fetched once per language; a language switch
   * fetches them in the new one with the other parts.
   */
  async open(name: string): Promise<void> {
    await firstValueFrom(
      toObservable(this.root.isLoading, { injector: this.injector }).pipe(
        filter((loading) => !loading),
      ),
    );
    const language = this.root.getCurrentLang() ?? this.root.getFallbackLang();
    if (language === null) return;
    if (!this.opened.has(name)) this.opened.set(name, new Map());
    await this.fetch(name, language);
    this.share(language);
  }

  private fetch(name: string, language: string): Promise<TranslationObject> {
    const languages = this.opened.get(name) ?? new Map<string, Promise<TranslationObject>>();
    this.opened.set(name, languages);
    let texts = languages.get(language);
    if (texts === undefined) {
      texts = this.laidPart(name, language);
      // A failed fetch is not kept, so the next screen that needs the texts asks again.
      texts.catch(() => languages.delete(language));
      languages.set(language, texts);
    }
    return texts;
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
    for (const [name, languages] of this.opened) {
      if (!languages.has(language)) continue;
      void this.fetch(name, language).then((texts) =>
        this.root.setTranslation(language, texts, true),
      );
    }
  }

  /**
   * Lays the texts worded otherwise again over every part, in a language they hold: another company entered, or a text
   * changed. Each part's texts are replaced at once, never emptied first, so no page shows a key meanwhile.
   */
  async refresh(language: string): Promise<void> {
    const opened = [...this.opened]
      .filter(([, languages]) => languages.delete(language))
      .map(([name]) => name);
    await Promise.all([
      ...[...this.names].map(async ([part, name]) => {
        if (!part.getLangs().includes(language)) return;
        part.setTranslation(language, await this.laidPart(name, language), false);
      }),
      ...opened.map((name) => this.fetch(name, language)),
    ]);
  }

  private async laidPart(name: string, language: string): Promise<TranslationObject> {
    const [texts, overrides] = await Promise.all([
      this.api.part(name, language),
      this.overrides.of(language),
    ]);
    return laid(texts, overrides[partTexts(name)]);
  }

  /** Nothing when every opened part already has the language, so a switch waits no longer than it did without them. */
  load(language: string): Promise<unknown> | undefined {
    const missing = [...this.parts].filter((part) => !part.getLangs().includes(language));
    const unopened = [...this.opened].filter(([, languages]) => !languages.has(language));
    return missing.length === 0 && unopened.length === 0
      ? undefined
      : Promise.all([
          ...missing.map((part) => firstValueFrom(part.reloadLang(language))),
          ...unopened.map(([name]) => this.fetch(name, language)),
        ]);
  }
}

/** The route waits for its texts in the current language, so the page never shows a key. */
function textsLoaded(name: string): CanActivateFn {
  return () => {
    const part = inject(TranslateService);
    const parts = inject(TextsParts);
    parts.add(part, name);
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
}

/** The route waits for texts several screens read, opened into the root (`TextsParts.open`). */
export function textsOpened(name: string): CanActivateFn {
  return () =>
    inject(TextsParts)
      .open(name)
      .then(() => true);
}

/**
 * The texts only one page reads, in `/i18n/<name>/<language>.json` beside the language's own file, loaded when the
 * page is first opened rather than with the file every page waits for (the screen texts budget). ngx-translate's child
 * service: the page's keys first, every other key from the language's file, the language always the root's. The route's
 * own guards come with it, ahead of the texts', so a route reads `...textsPart(name, guard)`: one call, as the routes
 * sit on the first page and its budget.
 */
export function textsPart(
  name: string,
  ...guards: CanActivateFn[]
): Pick<Route, 'providers' | 'canActivate'> {
  return {
    providers: [
      provideChildTranslateService({
        loader: provideOverriddenLoader(`/i18n/${name}/`, partTexts(name)),
        compiler: provideTranslateCompiler(PluralCompiler),
      }),
    ],
    canActivate: [...guards, textsLoaded(name)],
  };
}
