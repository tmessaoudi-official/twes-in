// SPDX-License-Identifier: AGPL-3.0-or-later

import { Injectable, signal } from '@angular/core';

/** A product whose page is open, as the scan card names it. */
export interface ProductOnViewRef {
  readonly id: string;
  readonly reference: string;
}

/**
 * The product whose page is on view, for the shell's scan card: a code nobody holds is offered to that product first
 * (docs/SPEC.md § 7, 2026-09-25 10:13). The page sets it while it shows a saved product and clears it when it goes.
 */
@Injectable({ providedIn: 'root' })
export class ProductOnView {
  private readonly shown = signal<ProductOnViewRef | null>(null);
  readonly product = this.shown.asReadonly();

  show(product: ProductOnViewRef | null): void {
    this.shown.set(product);
  }

  /** Clears it only while it is still this product: a page that goes after the next one opened leaves the next alone. */
  leave(id: string): void {
    if (this.shown()?.id === id) this.shown.set(null);
  }
}
