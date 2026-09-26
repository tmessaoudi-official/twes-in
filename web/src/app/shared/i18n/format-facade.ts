// SPDX-License-Identifier: AGPL-3.0-or-later

import { computed, inject, Injectable } from '@angular/core';
import { SettingsFacade } from '../settings/settings-facade';
import { PRESENTATION } from '../settings/settings-registry';
import { Session } from '../session/session';
import { decimalShown, formatAmount, formatDay, formatLocale, formatMoment } from './format';
import { LanguageFacade } from './language-facade';

/**
 * How figures read on screen: amounts and days in the interface language for the working company's country, so a
 * Tunisian company reads "2 975,000" and "05/09/2026". A decimal field shows the same separator, ungrouped, while its
 * control and the API keep the point (`DecimalInput`, docs/SPEC.md § 7, 2026-09-19). A date or number format the person
 * chose wins over the locale's (docs/SPEC.md § 7, 2026-09-25 12:45, row 130); a day written out in full keeps the language's.
 */
@Injectable({ providedIn: 'root' })
export class FormatFacade {
  private readonly session = inject(Session);
  private readonly language = inject(LanguageFacade);
  private readonly settings = inject(SettingsFacade);
  readonly dateFormat = this.settings.value(PRESENTATION.dateFormat);
  readonly numberFormat = this.settings.value(PRESENTATION.numberFormat);

  readonly locale = computed(() =>
    formatLocale(this.language.current(), this.session.me()?.company?.countryCode),
  );

  amount(value: string, scale: number | null): string {
    return formatAmount(value, scale, this.locale(), this.numberFormat());
  }

  day(value: string): string {
    return formatDay(value, this.locale(), this.dateFormat());
  }

  moment(value: string, timeZone?: string): string {
    return formatMoment(value, this.locale(), timeZone, this.dateFormat());
  }

  /** A decimal field's text: the chosen or the locale's decimal separator, never grouped. */
  decimal(value: string): string {
    return decimalShown(value, this.locale(), this.numberFormat());
  }
}
