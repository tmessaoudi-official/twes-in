// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable } from '@angular/core';
import { TranslateService } from '@ngx-translate/core';
import { typedText } from '../form/typed-text';
import { TextsParts } from '../i18n/texts-part';
import { Session } from '../session/session';
import {
  type ProductSelector,
  type UnofferedCounts,
  UnofferedProductsApi,
} from './unoffered-products-api';

/** The words every product selector says when it offers nothing, opened into the root by `UnofferedProducts`. */
export const UNOFFERED_TEXTS = 'unoffered';

/** Why a picker offers nothing for these words, and the way to the records that would answer them. */
export interface NoneFound {
  /** Each reason, said in the screen's language. */
  readonly sentences: readonly string[];
  /** The products the first reason names, for a person who may read them; null otherwise. */
  readonly link: {
    readonly label: string;
    readonly path: string;
    readonly query: Readonly<Record<string, string>>;
  } | null;
}

/** What a picker asks once its answer came back empty for words typed: why, or null to say only that none was found. */
export type ExplainNone = (words: string) => Promise<NoneFound | null>;

/**
 * Says why a product selector found nothing (docs/SPEC.md § 7, S5.40): the products switched off, the services where
 * goods alone are offered, the goods whose stock is not kept, each with its count, rather than « aucun produit » while
 * the catalogue holds what was typed. The way to the products shows only to a person who may read them.
 */
@Injectable({ providedIn: 'root' })
export class UnofferedProducts {
  private readonly api = inject(UnofferedProductsApi);
  private readonly parts = inject(TextsParts);
  private readonly translate = inject(TranslateService);
  private readonly session = inject(Session);

  /** The explainer a selector read under `selector`'s options hands its picker, for the company the page acts for. */
  explainer(companyId: () => string | null | undefined, selector: ProductSelector): ExplainNone {
    return this.reading((words) => {
      const id = companyId();
      return id ? this.api.unoffered(id, selector, words) : Promise.resolve(null);
    });
  }

  /**
   * The explainer of a picker reading the active products of the catalogue itself, under the product permission (a
   * price list, a code being given to a product): what it counts switched off among the words is all it hides.
   */
  deactivatedExplainer(count: (words: string) => Promise<number | null>): ExplainNone {
    return this.reading(async (words) => {
      const deactivated = await count(words);
      return deactivated === null ? null : { deactivated, services: 0, notStocked: 0 };
    });
  }

  private reading(read: (words: string) => Promise<UnofferedCounts | null>): ExplainNone {
    return async (words) => {
      if (typedText(words) === '') return null;
      let counts: UnofferedCounts | null;
      try {
        counts = await read(words);
      } catch {
        // The request's failure is said by the activity bar as every read's is; the picker then says only that none
        // was found, which is what it said before it could tell why.
        return null;
      }
      return counts === null ? null : this.said(counts, words);
    };
  }

  /** What these counts say for these words; null when the words find nothing hidden either. */
  async said(counts: UnofferedCounts, words: string): Promise<NoneFound | null> {
    const reasons = (
      [
        ['deactivated', counts.deactivated],
        ['services', counts.services],
        ['not_stocked', counts.notStocked],
      ] as const
    ).filter(([, count]) => count > 0);
    if (reasons.length === 0) return null;
    await this.parts.open(UNOFFERED_TEXTS);
    return {
      sentences: reasons.map(([key, count]) =>
        this.translate.instant(`unoffered.${key}`, { count }),
      ) as string[],
      link: this.linkFor(counts, typedText(words)),
    };
  }

  private linkFor(counts: UnofferedCounts, words: string): NoneFound['link'] {
    if (!this.session.hasPermission('product.read') || !this.session.hasModule('products'))
      return null;
    if (counts.deactivated > 0) {
      return {
        label: this.translate.instant('unoffered.see_deactivated') as string,
        path: '/products',
        query: { q: words, status: 'inactive' },
      };
    }
    if (counts.notStocked > 0) {
      return {
        label: this.translate.instant('unoffered.see_products') as string,
        path: '/products',
        query: { q: words },
      };
    }
    return null;
  }
}
