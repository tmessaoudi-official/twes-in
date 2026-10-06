// SPDX-License-Identifier: AGPL-3.0-or-later

/** A price under the shelf's that the company runs for everyone, with what it asks and when. */
export interface ScreenPromotion {
  /** Final, tax-included. */
  readonly price: string;
  /** The least a customer buys for it, as the price list writes it. */
  readonly minQuantity: string;
  readonly startsOn: string | null;
  readonly endsOn: string | null;
}

/** An establishment the customer screen may stand at: it says that establishment's own stock. */
export interface ScreenPlace {
  readonly id: string;
  readonly code: string;
  readonly name: string;
  readonly isDefault: boolean;
}

/** What the customer screen shows of a product, and not a field more. */
export interface ScreenProduct {
  readonly id: string;
  readonly name: string;
  readonly reference: string;
  /** Our own barcode; never a supplier's code. */
  readonly barcode: string | null;
  /** Final, tax-included. */
  readonly finalPrice: string;
  /** In stock or not; null when the company does not say, or does not keep stock of it. */
  readonly inStock: boolean | null;
  readonly promotions: readonly ScreenPromotion[];
}
