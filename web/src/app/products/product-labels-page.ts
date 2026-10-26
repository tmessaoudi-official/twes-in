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
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatIconModule } from '@angular/material/icon';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { FormatFacade } from '../shared/i18n/format-facade';
import { Session } from '../shared/session/session';
import { LabelCopies } from './label-copies';
import { labelCode, ProductLabel } from './product-label';
import { ProductsApi } from './products-api';
import type { LabelCode, LabelledProduct } from './products-types';

/**
 * Printed product labels: the product's name and reference, what a customer
 * pays for what the code enters, taxes included (the price check's count), and the code as a barcode — EAN when it
 * is a GTIN, Code 128 otherwise — with the code written under it. The unit code unless another is asked for, as
 * many copies as asked; every code of the product can be ticked, each printing at its own price and with the pieces it
 * stands for. Read as a sheet of labels reads it, so loose goods with no code of their own print their reference,
 * which a scan finds. The browser prints; the controls stay off the paper.
 */
@Component({
  selector: 'app-product-labels-page',
  imports: [
    MatButtonModule,
    MatCheckboxModule,
    MatIconModule,
    LabelCopies,
    ProductLabel,
    RouterLink,
    TranslatePipe,
  ],
  templateUrl: './product-labels-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ProductLabelsPage {
  private readonly api = inject(ProductsApi);
  private readonly session = inject(Session);
  private readonly format = inject(FormatFacade);
  private readonly document = inject(DOCUMENT);

  /** From the route, `/print/product-labels/:productId`. */
  readonly productId = input.required<string>();
  /** From the query, `?code=`: one of the product's codes to print instead of its unit code. */
  readonly code = input<string>();

  protected readonly product = signal<LabelledProduct | null>(null);
  /** What a customer pays for what each code enters, by code, with the company's currency. */
  protected readonly prices = computed((): Readonly<Record<string, string>> => {
    const currency = this.session.me()?.company?.currency ?? '';
    return Object.fromEntries(
      this.codes().map((code) => [
        code.code,
        `${this.format.amount(code.priceGross, null)} ${currency}`.trim(),
      ]),
    );
  });
  /** The codes the person ticked; until they tick one, the default code alone. */
  private readonly ticked = signal<readonly string[] | null>(null);
  protected readonly copies = signal(1);
  private readonly copiesField = viewChild(LabelCopies);

  protected readonly codes = computed((): readonly LabelCode[] => this.product()?.codes ?? []);
  protected readonly printed = computed((): LabelCode | null => {
    const asked = this.codes().find((code) => code.code === this.code());
    return asked ?? labelCode(this.codes());
  });
  /** What is printed: the ticked codes in the order the product holds them, else the default one. */
  protected readonly chosen = computed((): readonly LabelCode[] => {
    const ticked = this.ticked();
    if (ticked === null) {
      const first = this.printed();
      return first === null ? [] : [first];
    }
    return this.codes().filter((barcode) => ticked.includes(barcode.code));
  });
  protected readonly labels = computed(() => Array.from({ length: this.copies() }, (_, i) => i));

  protected isChosen(barcode: LabelCode): boolean {
    return this.chosen().some((held) => held.code === barcode.code);
  }

  protected tick(barcode: LabelCode, on: boolean): void {
    const now = this.chosen().map((held) => held.code);
    this.ticked.set(
      on
        ? [...now.filter((code) => code !== barcode.code), barcode.code]
        : now.filter((code) => code !== barcode.code),
    );
  }

  constructor() {
    effect(() => {
      const productId = this.productId();
      untracked(() => void this.load(productId));
    });
  }

  protected print(): void {
    if (this.copiesField()?.reveal()) return;
    this.document.defaultView?.print();
  }

  private async load(productId: string): Promise<void> {
    const company = this.session.me()?.company;
    if (!company) return;
    const [product] = await this.api.labels(company.id, [productId]);
    this.product.set(product ?? null);
  }
}
