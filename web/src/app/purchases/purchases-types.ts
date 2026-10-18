// SPDX-License-Identifier: AGPL-3.0-or-later

import type { StatusTone } from '../shared/theme/accent-theme';
import { type LifecycleStage, tonesOf } from '../shared/theme/lifecycle-tones';
import type { ProductTracking } from '../products/products-types';

/** Why the API refused, as the orders screens translate it. */
export type PurchasesError =
  | 'network'
  | 'not_found'
  /** The order is no longer in the state the step needs: sent, received or closed since it was read. */
  | 'conflict'
  /** Saved over a version someone else saved since: nothing was saved, and what they saved is to be merged. */
  | 'record_changed'
  | 'invalid'
  /** The vendor was retired since the order was started. */
  | 'vendor_unavailable';

export type PurchaseOrderStatus =
  'draft' | 'sent' | 'partly_received' | 'received' | 'closed' | 'cancelled';

/** The sorts the API answers; the total is worked out per row and is not a column it can order by. */
export type PurchaseOrderSortKey = 'number' | 'vendor' | 'issueDate' | 'expectedOn' | 'status';

export const PURCHASE_ORDER_STATUSES: readonly PurchaseOrderStatus[] = [
  'draft',
  'sent',
  'partly_received',
  'received',
  'closed',
  'cancelled',
];

/**
 * Where each status stands (design direction § 1.1): a draft is not started, a sent order is under way, one partly
 * received waits for the rest, a received or closed one is done and a cancelled one withdrawn.
 */
export const PURCHASE_ORDER_STATUS_STAGES: Readonly<Record<PurchaseOrderStatus, LifecycleStage>> = {
  draft: 'not-started',
  sent: 'under-way',
  partly_received: 'needs-action',
  received: 'done',
  closed: 'done',
  cancelled: 'withdrawn',
};

export const PURCHASE_ORDER_STATUS_TONES: Readonly<Record<PurchaseOrderStatus, StatusTone>> =
  tonesOf(PURCHASE_ORDER_STATUS_STAGES);

/** Whether goods may still be received against an order in this state. */
export function receivable(status: PurchaseOrderStatus): boolean {
  return status === 'sent' || status === 'partly_received';
}

export interface PurchaseOrderLine {
  /** What a receipt names the line by. */
  id: string;
  productId: string;
  /** The product's reference as the line was written. */
  productReference: string;
  /** How the product's stock is told apart today, so a receipt knows whether to name a lot. */
  productTracking: ProductTracking | null;
  description: string | null;
  /** A decimal string with three decimals. */
  quantity: string;
  unitId: string;
  /** The unit's code, C62 for a piece, and its name as the company has it. */
  unitCode: string;
  unitName: string;
  /** What one unit should cost, four decimals; null when unknown or withheld from whoever may not read costs. */
  unitPriceNet: string | null;
  /** The line's net at the currency's scale; null when its price is. */
  net: string | null;
  /** The vendor's own code for the product; null when it has none, or withheld from whoever may not read costs. */
  vendorCode: string | null;
  received: string;
  outstanding: string;
}

export interface PurchaseOrderLineInput {
  productId: string;
  description: string | null;
  quantity: string;
  /** Left null by whoever may not read costs, which keeps the price a cost reader set. */
  unitPriceNet: string | null;
}

export interface ReceiptLine {
  orderLineId: string;
  locationId: string;
  quantity: string;
  lotCode: string | null;
  lotExpiresOn: string | null;
  /** Null when none was given, or withheld from whoever may not read costs. */
  unitCost: string | null;
}

export interface Receipt {
  id: string;
  receivedOn: string;
  supplierReference: string | null;
  lines: ReceiptLine[];
}

export interface PurchaseOrderRow {
  id: string;
  /** The version read, which a revision sends back; one saved since is refused as `record_changed`. */
  version: number;
  /** Null until the order is sent. */
  number: string | null;
  status: PurchaseOrderStatus;
  vendorId: string;
  /** Who the order goes to: the vendor's name as a draft last read it, or the day the order was sent. */
  vendorName: string;
  /** The vendor's number as the order recorded it when sent; null for a draft. */
  recordedVendorNumber: string | null;
  establishmentId: string | null;
  issueDate: string | null;
  expectedOn: string | null;
  remarksPrinted: string | null;
  notesInternal: string | null;
  lines: PurchaseOrderLine[];
  /** At the currency's scale; null when a line's price is unknown or withheld. */
  totalNet: string | null;
  /** Oldest first. */
  receipts: Receipt[];
}

export interface PurchaseOrderInput {
  vendorId: string;
  /** Null: the company's default establishment. */
  establishmentId: string | null;
  expectedOn: string | null;
  remarksPrinted: string | null;
  notesInternal: string | null;
  lines: PurchaseOrderLineInput[];
}

export interface PurchaseOrderSearch {
  /** Numbered from 1. */
  page: number;
  itemsPerPage: number;
  /** Words found in the number or the vendor's name; empty finds all. */
  q: string;
  /** Statuses the order may hold, OR'd; none lists every one. */
  status: readonly PurchaseOrderStatus[];
  vendorIds: readonly string[];
  /** The ends of the intervals, by `issueDate.from`, `expectedOn.to`: each already valid. */
  intervals: Readonly<Record<string, string>>;
  order: { key: PurchaseOrderSortKey; direction: 'asc' | 'desc' } | null;
}

export interface PurchaseOrderStatusCounts {
  all: number;
  statuses: Record<PurchaseOrderStatus, number>;
}

/** One vendor as the order's picker answers it. */
export interface VendorOption {
  id: string;
  number: string;
  name: string;
  /** Whether a new order may still name it. */
  active: boolean;
}

/** One product as the line's picker answers it: what a line starts from. */
export interface ProductOption {
  id: string;
  reference: string;
  name: string;
  unitId: string;
  /** That unit as the company names it, which a line not yet saved shows beside its quantity. */
  unitName: string;
  tracking: ProductTracking;
  /** Whether it is goods: a product an order names may since have become a service, which no new line takes. */
  goods: boolean;
  active: boolean;
  /** What it costs today, the line's price unless the person types another; null for whoever may not read costs. */
  costPrice: string | null;
}

/** A place a receipt may put goods, naming the one it sits under so its path can be written out. */
export interface ReceivingPlace {
  id: string;
  parentId: string | null;
  code: string;
  name: string;
  isDefault: boolean;
}

/** Where a receipt of an order is offered to put its goods, and whether its cost's effect is the person's to say. */
export interface Receiving {
  places: ReceivingPlace[];
  /** Each line's place unless the person says otherwise, by order line id. */
  homes: Readonly<Record<string, string>>;
  asksCostBasis: boolean;
}

/** What a receipt's cost does to the products' cost, where the person says it: the average left, or this cost. */
export type CostBasis = 'average' | 'last';

export interface ReceiptInput {
  receivedOn: string;
  supplierReference: string | null;
  apply: CostBasis | null;
  lines: ReceiptLine[];
}
