// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable } from '@angular/core';
import { TranslateService } from '@ngx-translate/core';
import { AuthFacade } from '../../auth/auth-facade';
import { EFFECT_TEXTS, type SaidEffect } from '../../shared/effects/effects';
import { Feedback } from '../../shared/feedback/feedback';
import { FormatFacade } from '../../shared/i18n/format-facade';
import { TextsParts } from '../../shared/i18n/texts-part';
import {
  AT_REORDER_POINT,
  COUNT_DIFFERENCE,
  DOCUMENT_NOT_MOVED,
  LATE_COST_ENTERED,
  MERGE_CARRIED,
} from './stock-effects';

type Params = Record<string, string | number>;

/**
 * Says what a request did to the stock beyond its subject (docs/SPEC.md § 7: never silent), one toast per kind of the
 * answer, each once the toast before it has closed, so a consequence never replaces one not yet read. Past what a
 * header carries the API folds a kind to its count, which is said as how many.
 */
@Injectable({ providedIn: 'root' })
export class StockEffectsSaid {
  private readonly auth = inject(AuthFacade);
  private readonly feedback = inject(Feedback);
  private readonly format = inject(FormatFacade);
  private readonly texts = inject(TextsParts);
  private readonly translate = inject(TranslateService);

  async said(effects: readonly SaidEffect[]): Promise<void> {
    await this.texts.open(EFFECT_TEXTS);
    for (const kind of new Set(effects.map((effect) => effect.kind))) {
      await this.feedback.idle();
      const own = effects.filter((effect) => effect.kind === kind);
      const { key, params } = this.message(kind, own);
      this.feedback.success(key, params);
    }
  }

  private message(kind: string, effects: readonly SaidEffect[]): { key: string; params: Params } {
    const folded = effects.some((effect) => effect.id === null || effect.count !== undefined);
    const count = effects.reduce((sum, effect) => sum + (effect.count ?? 1), 0);
    const [first] = effects;
    switch (kind) {
      case LATE_COST_ENTERED:
        return folded || count > 1 || first === undefined
          ? { key: 'effects.late_cost.many', params: { count } }
          : this.lateCost(first);
      case COUNT_DIFFERENCE:
        return folded
          ? { key: 'effects.count.many', params: { count } }
          : {
              key: 'effects.count.differences',
              params: { differences: this.differences(effects) },
            };
      case AT_REORDER_POINT:
        return folded || count > 1 || first === undefined
          ? { key: 'effects.reorder.many', params: { count } }
          : { key: 'effects.reorder.one', params: { establishment: text(first, 'establishment') } };
      case MERGE_CARRIED:
        return folded || count > 1 || first === undefined
          ? { key: 'effects.merge.carried_many', params: {} }
          : this.carried(first);
      case DOCUMENT_NOT_MOVED:
        return folded || count > 1 || first === undefined
          ? { key: 'effects.not_moved.many', params: { count } }
          : {
              key:
                text(first, 'outcome') === 'failed'
                  ? 'effects.not_moved.failed'
                  : 'effects.not_moved.left_out',
              params: { number: text(first, 'number') },
            };
      default:
        throw new Error(`StockEffectsSaid says no ${kind}`);
    }
  }

  /** The cost of sales corrected, the product's cost moved, or both; a cost the product had none of is « now ». */
  private lateCost(effect: SaidEffect): { key: string; params: Params } {
    const correction = decimal(effect, 'costOfSales');
    const before = decimal(effect, 'costBefore');
    const after = decimal(effect, 'cost');
    const moved = after !== null && after !== before;
    const amount = (value: string) => this.format.amount(value, null);
    const cost: Params =
      !moved || after === null
        ? {}
        : before === null
          ? { after: amount(after) }
          : { before: amount(before), after: amount(after) };
    const set = moved && before === null ? '_set' : '';
    if (correction !== null) {
      return moved
        ? { key: `effects.late_cost.both${set}`, params: { amount: amount(correction), ...cost } }
        : { key: 'effects.late_cost.cost_of_sales', params: { amount: amount(correction) } };
    }
    return { key: `effects.late_cost.cost${set}`, params: cost };
  }

  /** « +2 à A1 et −0,25 à Z1 »: each place's difference signed, at the decimals it was counted to. */
  private differences(effects: readonly SaidEffect[]): string {
    const parts = effects.map((effect) => {
      const difference = decimal(effect, 'difference') ?? '0';
      return this.translate.instant('effects.count.at', {
        difference: this.format.signed(difference, decimals(difference), 'figure'),
        location: text(effect, 'location'),
      }) as string;
    });
    return new Intl.ListFormat(this.format.locale(), { type: 'conjunction' }).format(parts);
  }

  /** The units carried, and their worth to whoever reads costs: the API carries goods only for one who does. */
  private carried(effect: SaidEffect): { key: string; params: Params } {
    const quantity = decimal(effect, 'quantity') ?? '0';
    const units = this.format.amount(quantity, decimals(quantity));
    const value = decimal(effect, 'value');
    return value !== null && this.auth.hasPermission('product.cost.read')
      ? {
          key: 'effects.merge.carried',
          params: { quantity: units, value: this.format.amount(value, null) },
        }
      : { key: 'effects.merge.carried_units', params: { quantity: units } };
  }
}

function text(effect: SaidEffect, name: string): string {
  const value = effect.params[name];
  return typeof value === 'string' ? value : '';
}

function decimal(effect: SaidEffect, name: string): string | null {
  const value = effect.params[name];
  return typeof value === 'string' ? value : null;
}

/** The decimals a quantity carries once its trailing zeros are left: « 2.000 » reads 2, « 0.250 » 0,25. */
function decimals(value: string): number {
  return (value.split('.')[1] ?? '').replace(/0+$/, '').length;
}
