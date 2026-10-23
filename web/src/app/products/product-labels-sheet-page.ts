// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DOCUMENT,
  effect,
  inject,
  input,
  signal,
  untracked,
  viewChild,
} from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { FormatFacade } from '../shared/i18n/format-facade';
import { Session } from '../shared/session/session';
import { LabelCopies } from './label-copies';
import { labelCode, ProductLabel } from './product-label';
import { ProductsApi, ProductsRefused } from './products-api';
import type { LabelCode, LabelledProduct, ProductsError } from './products-types';

/** A product selected that gets no label, and why: no longer found, or holding no code of its own to print. */
export interface LeftOutProduct {
  id: string;
  /** What names it: its reference and name, or nothing when it was not found. */
  name: string | null;
  reason: 'not_found' | 'no_code';
}

/**
 * A sheet of labels for the products selected on the list: one label per product, with the code its own sheet carries
 * by default (the unit code, else an internal one, else a pack's), as many copies of each as asked. A product that gets
 * none is named above the labels with why, before anything is printed. The browser prints; the controls stay off the
 * paper. Which code each label carries is chosen on a product's own sheet.
 */
@Component({
  selector: 'app-product-labels-sheet-page',
  imports: [MatButtonModule, MatIconModule, LabelCopies, ProductLabel, RouterLink, TranslatePipe],
  templateUrl: './product-labels-sheet-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ProductLabelsSheetPage {
  private readonly api = inject(ProductsApi);
  private readonly session = inject(Session);
  private readonly format = inject(FormatFacade);
  private readonly document = inject(DOCUMENT);

  /** From the query, `?id=…&id=…`: the products selected, in the order they were ticked. */
  readonly id = input<string | readonly string[]>();

  /** What the API answered; null while it is being read. */
  protected readonly products = signal<readonly LabelledProduct[] | null>(null);
  protected readonly error = signal<ProductsError | null>(null);
  protected readonly copies = signal(1);
  private readonly copiesField = viewChild(LabelCopies);

  private readonly asked = computed((): readonly string[] => {
    const id = this.id();
    return [...new Set(id === undefined ? [] : typeof id === 'string' ? [id] : id)];
  });

  /** One label per product that has a code to print, in the order selected. */
  protected readonly printed = computed(() => {
    const currency = this.session.me()?.company?.currency ?? '';
    return (this.products() ?? []).flatMap((product) => {
      const code = labelCode<LabelCode>(product.codes);
      return code === null
        ? []
        : [
            {
              product,
              code,
              price: `${this.format.amount(code.priceGross, null)} ${currency}`.trim(),
            },
          ];
    });
  });

  protected readonly leftOut = computed((): readonly LeftOutProduct[] => {
    const products = this.products();
    if (products === null) return [];
    const found = new Map(products.map((product) => [product.productId, product]));
    return this.asked().flatMap((id): LeftOutProduct[] => {
      const product = found.get(id);
      if (product === undefined) return [{ id, name: null, reason: 'not_found' }];
      return labelCode(product.codes) === null
        ? [{ id, name: `${product.reference} · ${product.name}`, reason: 'no_code' }]
        : [];
    });
  });

  protected readonly labels = computed(() => Array.from({ length: this.copies() }, (_, i) => i));

  constructor() {
    effect(() => {
      const asked = this.asked();
      untracked(() => void this.load(asked));
    });
  }

  protected print(): void {
    if (this.copiesField()?.reveal()) return;
    this.document.defaultView?.print();
  }

  private async load(asked: readonly string[]): Promise<void> {
    const company = this.session.me()?.company;
    if (!company) return;
    this.products.set(null);
    this.error.set(null);
    if (asked.length === 0) {
      this.products.set([]);
      return;
    }
    try {
      this.products.set(await this.api.labels(company.id, asked));
    } catch (error) {
      this.error.set(error instanceof ProductsRefused ? error.code : 'network');
    }
  }
}
