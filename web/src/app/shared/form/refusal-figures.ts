// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, Injector } from '@angular/core';
import { FormatFacade } from '../i18n/format-facade';
import { Session } from '../session/session';

/**
 * The params a refusal sends as figures, by name, and how the reader reads each: the API writes them as it stores
 * them, a decimal string and a day, never in anyone's format. Every reason naming a figure names it so.
 */
const FIGURES: Readonly<Record<string, 'amount' | 'day' | 'decimal'>> = {
  amount: 'amount',
  day: 'day',
  quantity: 'decimal',
  price: 'decimal',
  wanted: 'decimal',
  on_hand: 'decimal',
};

/**
 * A refusal's figures in the reader's formats, as every other figure on the screen: an amount at the company's
 * currency scale (CLDR's, as the API's `IntlCurrencyScales`, so a stored « 12.340 » reads 12,34 in euros), a day as the
 * person reads days, a quantity or a price with its own decimals. A figure it cannot read stays as it came.
 */
@Injectable({ providedIn: 'root' })
export class RefusalFigures {
  // The formats are read only for a refusal naming a figure: most name none, and a form saying them needs no session.
  private readonly injector = inject(Injector);

  written<P extends Readonly<Record<string, unknown>>>(params: P): P {
    const written: Record<string, unknown> = { ...params };
    for (const [name, value] of Object.entries(params)) {
      const kind = FIGURES[name];
      if (kind === undefined || typeof value !== 'string') continue;
      const format = this.injector.get(FormatFacade);
      written[name] =
        kind === 'day'
          ? format.day(value)
          : format.amount(value, kind === 'amount' ? this.currencyScale() : null);
    }

    return written as P;
  }

  private currencyScale(): number | null {
    const currency = this.injector.get(Session).me()?.company?.currency;
    return currency === undefined
      ? null
      : // A currency's decimals are CLDR's for the currency, whatever the language, so none is named.
        (new Intl.NumberFormat(undefined, { style: 'currency', currency }).resolvedOptions()
          .maximumFractionDigits ?? null);
  }
}
