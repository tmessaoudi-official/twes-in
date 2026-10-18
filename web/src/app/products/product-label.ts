// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { TranslatePipe } from '@ngx-translate/core';
import { BarcodeSvg } from '../shared/barcode/barcode-svg';
import type { BarcodeRole } from './products-types';

/** Which of a product's codes a label carries unless one is asked for: never a supplier's, which is theirs to print. */
const PRINTED_FIRST: readonly BarcodeRole[] = ['unit', 'internal', 'pack'];
/** How many copies of each label a sheet prints at most. */
export const MOST_LABEL_COPIES = 100;

/** The code a product's label carries by default, or null when it holds none of its own to print. */
export function labelCode<Code extends { role: BarcodeRole }>(codes: readonly Code[]): Code | null {
  for (const role of PRINTED_FIRST) {
    const found = codes.find((code) => code.role === role);
    if (found) return found;
  }
  return null;
}

/**
 * One printed product label, the same on a product's own sheet and on a sheet of several: the product's name and
 * reference, what a customer pays for what the code enters, how many pieces a pack code stands for, and the code as a
 * barcode with the code written under it. Black on white whatever the scheme, as paper is.
 */
@Component({
  selector: 'app-product-label',
  imports: [BarcodeSvg, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: {
    class: 'flex h-full flex-col gap-1 rounded-md border border-black bg-white p-3 text-black',
    'data-testid': 'product-label',
  },
  template: `
    <span class="text-lg leading-tight font-semibold"
      ><bdi>{{ name() }}</bdi></span
    >
    <span class="text-sm"
      ><bdi>{{ reference() }}</bdi></span
    >
    @if (price(); as price) {
      <span class="text-2xl font-bold"
        ><bdi>{{ price }}</bdi></span
      >
    }
    @if (quantity() > 1) {
      <span class="text-sm">{{
        'products.labels.pack_of' | translate: { quantity: quantity() }
      }}</span>
    }
    <app-barcode-svg class="block h-16" [value]="code()" />
    <span class="text-center font-mono text-sm tracking-widest"
      ><bdi>{{ code() }}</bdi></span
    >
  `,
})
export class ProductLabel {
  readonly name = input.required<string>();
  readonly reference = input.required<string>();
  readonly code = input.required<string>();
  /** How many pieces the code enters: 1 for a unit code. */
  readonly quantity = input(1);
  /** What a customer pays for that, written with its currency; none when the shelf has no price for it. */
  readonly price = input<string | null>(null);
}
