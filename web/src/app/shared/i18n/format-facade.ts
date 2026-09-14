// SPDX-License-Identifier: AGPL-3.0-or-later

import { computed, inject, Injectable } from '@angular/core';
import { AuthFacade } from '../../auth/auth-facade';
import { formatAmount, formatDay, formatLocale, formatMoment } from './format';
import { LanguageFacade } from './language-facade';

/**
 * How figures read on screen: amounts and days in the interface language for the working company's country, so a
 * Tunisian company reads "2 975,000" and "05/09/2026". Inputs and the API keep their own format (`atScale`).
 */
@Injectable({ providedIn: 'root' })
export class FormatFacade {
  private readonly auth = inject(AuthFacade);
  private readonly language = inject(LanguageFacade);

  readonly locale = computed(() =>
    formatLocale(this.language.current(), this.auth.me()?.company?.countryCode),
  );

  amount(value: string, scale: number | null): string {
    return formatAmount(value, scale, this.locale());
  }

  day(value: string): string {
    return formatDay(value, this.locale());
  }

  moment(value: string, timeZone?: string): string {
    return formatMoment(value, this.locale(), timeZone);
  }
}
