// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, DOCUMENT, inject, input } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { TranslatePipe } from '@ngx-translate/core';

/**
 * What the products list offers on the rows a person selected, in its selection bar: « Étiquettes » opens a sheet of
 * their labels in a tab of its own, as a product's own sheet opens. Nothing is printed until the person prints that
 * sheet, which first names each product that gets no label and why.
 */
@Component({
  selector: 'app-product-bulk',
  imports: [MatButtonModule, MatIconModule, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { class: 'flex flex-wrap gap-2' },
  template: `
    <button mat-stroked-button type="button" (click)="labels()" data-testid="products-bulk-labels">
      <mat-icon aria-hidden="true">label</mat-icon>
      {{ 'products.bulk.labels' | translate }}
    </button>
  `,
})
export class ProductBulk {
  /** The selected products' ids, in the order they were ticked, a list page at most. */
  readonly ids = input.required<readonly string[]>();

  private readonly document = inject(DOCUMENT);

  protected labels(): void {
    const query = this.ids()
      .map((id) => `id=${encodeURIComponent(id)}`)
      .join('&');
    this.document.defaultView?.open(`/print/product-labels?${query}`, '_blank');
  }
}
