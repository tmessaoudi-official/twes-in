// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Pipe, type PipeTransform } from '@angular/core';
import { TranslateService } from '@ngx-translate/core';
import { fieldKey } from './activity-list';

/**
 * What a journal entry changed, in the screen's words: « Date de livraison, Paiement » rather than the names the API
 * records (« deliveryDate, paymentId »). Impure, as the translate pipe is, so a language change reads again.
 */
@Pipe({ name: 'activityFields', pure: false })
export class ActivityFieldsPipe implements PipeTransform {
  private readonly translate = inject(TranslateService);

  transform(fields: readonly string[], kind: string): string {
    const has = (key: string): boolean => {
      const said: unknown = this.translate.instant(key);
      return typeof said === 'string' && said !== key;
    };
    return fields
      .map((field) => {
        const key = fieldKey(kind, field, has);
        return key === field ? field : (this.translate.instant(key) as string);
      })
      .join(', ');
  }
}
