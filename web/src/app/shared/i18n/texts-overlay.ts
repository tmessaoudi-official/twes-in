// SPDX-License-Identifier: AGPL-3.0-or-later

import { DestroyRef, effect, inject, Injectable } from '@angular/core';
import { TranslateLoader, TranslateService } from '@ngx-translate/core';
import { firstValueFrom } from 'rxjs';
import { LiveChanges } from '../realtime/live-changes';
import { Session } from '../session/session';
import { OverriddenLoader } from './overridden-loader';
import { TextOverrides } from './text-overrides';
import { TextsParts } from './texts-part';

/** The kind of live change a text worded otherwise is told by (the API's `OverrideText::ENTITY_TYPE`). */
export const TEXT_CHANGES = 'text';

/**
 * Keeps the screens in the wording in force: a company entered or left, or a text worded otherwise in another tab or by
 * another person, lays the wording again over the language's own file and over every page's part, each replaced at
 * once. Started with the application.
 */
@Injectable({ providedIn: 'root' })
export class TextsOverlay {
  private readonly root = inject(TranslateService);
  private readonly loader = inject(TranslateLoader);
  private readonly overrides = inject(TextOverrides);
  private readonly parts = inject(TextsParts);

  constructor() {
    const session = inject(Session);
    let shown: string | null | undefined;
    effect(() => {
      const company = session.me()?.company?.id ?? null;
      if (shown !== undefined && company !== shown) void this.refresh();
      shown = company;
    });
    inject(LiveChanges).on([TEXT_CHANGES], () => void this.refresh(), inject(DestroyRef));
  }

  async refresh(): Promise<void> {
    this.overrides.forget();
    const language = this.root.getCurrentLang();
    if (language === null) return;
    const texts =
      this.loader instanceof OverriddenLoader
        ? await this.loader.laid(language)
        : await firstValueFrom(this.loader.getTranslation(language));
    this.root.setTranslation(language, texts, false);
    await this.parts.refresh(language);
    this.parts.share(language);
  }
}
