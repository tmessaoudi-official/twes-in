// SPDX-License-Identifier: AGPL-3.0-or-later

import { Pipe, type PipeTransform } from '@angular/core';

/**
 * A text with its first letter raised and the rest left alone: a role or a status translated lowercase to sit inside a
 * sentence, shown on its own. Angular's `titlecase` raises every word, which turns « caissier / vendeur » into two names.
 */
@Pipe({ name: 'capitalize' })
export class CapitalizePipe implements PipeTransform {
  transform(text: string | null | undefined): string {
    if (!text) return '';
    const [first = '', ...rest] = [...text];
    return first.toLocaleUpperCase() + rest.join('');
  }
}
