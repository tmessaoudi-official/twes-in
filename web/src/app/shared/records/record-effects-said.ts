// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable } from '@angular/core';
import { TranslateService } from '@ngx-translate/core';
import type { SaidEffect } from '../effects/effects';
import { Feedback } from '../feedback/feedback';
import { TextsParts } from '../i18n/texts-part';
import { LINKS_CLEARED, LINKS_NOT_RESTORED } from './record-effects';
import type { TableCount } from './record-removals-api';
import { tableCounts } from './record-tables';
import { RECORDS_TEXTS } from './records-texts';

/**
 * Says the links a delete emptied, and those its restore left, once the delete's own toast has closed (docs/SPEC.md
 * § 7: never silent): one sentence per kind, each table counted in words. Past what a header carries the API folds a
 * kind to its count, said as other records.
 */
@Injectable({ providedIn: 'root' })
export class RecordEffectsSaid {
  private readonly feedback = inject(Feedback);
  private readonly texts = inject(TextsParts);
  private readonly translate = inject(TranslateService);

  async said(effects: readonly SaidEffect[]): Promise<void> {
    await this.texts.open(RECORDS_TEXTS);
    for (const kind of [LINKS_CLEARED, LINKS_NOT_RESTORED]) {
      const own = effects.filter((effect) => effect.kind === kind);
      if (own.length === 0) continue;
      await this.feedback.idle();
      this.feedback.notice(kind, { links: this.counted(own.map(countOf)) });
    }
  }

  /** « 2 sous-catégories et 1 fournisseur », a table the screens do not name counted as other records. */
  private counted(uses: readonly TableCount[]): string {
    const language =
      this.translate.getCurrentLang() ?? this.translate.getFallbackLang() ?? undefined;
    return new Intl.ListFormat(language, { type: 'conjunction' }).format(
      tableCounts(uses).map(({ key, count }) => this.translate.instant(key, { count }) as string),
    );
  }
}

function countOf(effect: SaidEffect): TableCount {
  const { table, count } = effect.params;
  return typeof table === 'string' && typeof count === 'number'
    ? { table, count }
    : { table: 'other', count: effect.count ?? 1 };
}
