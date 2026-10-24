// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, Injector } from '@angular/core';
import type { TranslationObject } from '@ngx-translate/core';
import { Session } from '../session/session';
import { TextsApi } from './texts-api';

/** By screen file (`screen`, or `screen:<part>` for a page's own texts), each text worded otherwise by its dotted key. */
export type ScreenOverrides = Readonly<Record<string, Readonly<Record<string, string>>>>;

/** The language's own file among them; a page's part is `screen:<its name>`. */
export const MAIN_TEXTS = 'screen';

export function partTexts(name: string): string {
  return `${MAIN_TEXTS}:${name}`;
}

/**
 * The screens' texts the installation words otherwise, and the company's own over them once a company is entered, as
 * the API answers them: read once a language, read again once forgotten (another company, a text changed).
 */
@Injectable({ providedIn: 'root' })
export class TextOverrides {
  private readonly api = inject(TextsApi);
  // Asked when a language loads, which the translate service does as it starts, before the session can be built.
  private readonly injector = inject(Injector);
  private readonly read = new Map<string, Promise<ScreenOverrides>>();

  of(language: string): Promise<ScreenOverrides> {
    const company = this.injector.get(Session).me()?.company?.id ?? null;
    const key = `${company ?? ''}|${language}`;
    let overrides = this.read.get(key);
    if (overrides === undefined) {
      // A failure leaves the shipped words, which every screen can show, and is asked again the next time.
      overrides = this.api.overrides(company, language).catch(() => {
        this.read.delete(key);
        return {};
      });
      this.read.set(key, overrides);
    }
    return overrides;
  }

  forget(): void {
    this.read.clear();
  }
}

/**
 * A file's texts with the ones worded otherwise in their place, the file itself untouched. Only a text the file ships
 * is replaced: a wording of a text a release removed, or of a group of texts, is left out.
 */
export function laid(
  texts: TranslationObject,
  overrides: Readonly<Record<string, string>> | undefined,
): TranslationObject {
  if (overrides === undefined) return texts;
  const copy = structuredClone(texts);
  for (const [key, value] of Object.entries(overrides)) {
    const steps = key.split('.');
    const last = steps.pop();
    let node: unknown = copy;
    for (const step of steps) node = isGroup(node) ? node[step] : undefined;
    if (last !== undefined && isGroup(node) && typeof node[last] === 'string') node[last] = value;
  }
  return copy;
}

function isGroup(node: unknown): node is Record<string, unknown> {
  return typeof node === 'object' && node !== null && !Array.isArray(node);
}
