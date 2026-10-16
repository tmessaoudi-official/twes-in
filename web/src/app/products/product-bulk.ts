// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, DOCUMENT, inject, input } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { TranslatePipe } from '@ngx-translate/core';
import { AddToDocuments } from '../shared/documents/add-to-documents';
import { PRODUCTS_ADD_TO } from './add-to-kinds';

/**
 * What the products list offers on the rows a person selected, in its selection bar: « Ajouter à… » puts them on a
 * new or open draft, whose editor shows them on its lines unsaved; « Étiquettes » opens a sheet of their labels in a
 * tab of its own, as a product's own sheet opens. Nothing is printed until the person prints that sheet, which first
 * names each product that gets no label and why.
 */
@Component({
  selector: 'app-product-bulk',
  imports: [MatButtonModule, MatIconModule, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  providers: PRODUCTS_ADD_TO,
  host: { class: 'flex flex-wrap gap-2' },
  template: `
    @if (mayAddTo) {
      <button
        mat-stroked-button
        type="button"
        (click)="addTo.open(ids())"
        data-testid="products-bulk-add-to"
      >
        <mat-icon aria-hidden="true">add_box</mat-icon>
        {{ 'documents.add_to.action' | translate }}
      </button>
    }
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
  protected readonly addTo = inject(AddToDocuments);
  protected readonly mayAddTo = this.addTo.kinds().length > 0;

  protected labels(): void {
    const query = this.ids()
      .map((id) => `id=${encodeURIComponent(id)}`)
      .join('&');
    this.document.defaultView?.open(`/print/product-labels?${query}`, '_blank');
  }
}
