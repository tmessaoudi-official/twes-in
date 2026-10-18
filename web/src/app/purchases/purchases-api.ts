// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpErrorResponse, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type {
  ApiCompaniesCompanyIdpurchaseOrdersGetCollectionResponse,
  GoodsReceiptGoodsReceiptWriteValidationGoodsReceiptWrite as GoodsReceiptWrite,
  GoodsReceiptPurchaseOrderResourcePurchaseOrderReadValidationGoodsReceiptWrite as GoodsReceiptAnswer,
  PurchaseOrderJsonldPurchaseOrderRead,
  PurchaseOrderProductPickPurchaseOrderProductPickRead,
  PurchaseOrderPurchaseOrderRead,
  PurchaseOrderPurchaseOrderWriteRecordReviseValidationPurchaseOrderWriteRecordReviseWritable as PurchaseOrderRevise,
  PurchaseOrderPurchaseOrderWriteValidationPurchaseOrderWriteWritable as PurchaseOrderWrite,
  PurchaseOrderReceivingPurchaseOrderReceivingRead,
  PurchaseOrderStatusCountsPurchaseOrderStatusCountsRead,
  PurchaseOrderVendorPickPurchaseOrderVendorPickRead,
} from '../api/types.gen';
import { isRecordChanged } from '../shared/errors/record-changed';
import { type PickAsked, pickParams } from '../shared/form/pick-api';
import { apiRangeKey } from '../shared/list/list-filters';
import type { ListPage } from '../shared/list/list-types';
import { refusedField } from '../invoices/invoices-api';
import { trackingOf } from '../products/products-types';
import {
  PURCHASE_ORDER_STATUSES,
  type ProductOption,
  type PurchaseOrderInput,
  type PurchaseOrderRow,
  type PurchaseOrderSearch,
  type PurchaseOrderStatusCounts,
  type PurchasesError,
  type ReceiptInput,
  type Receiving,
  type VendorOption,
} from './purchases-types';

/** Thrown when the API refuses; carries the code the UI translates and, for a 422, the field it named. */
export class PurchasesRefused extends Error {
  constructor(
    readonly code: PurchasesError,
    /** `vendorId`, `lines`, `lines[2].lotCode`…: what the API refused, when it named it. */
    readonly field: string | null = null,
  ) {
    super(code);
  }
}

/** The HTTP edge of the orders to vendors: the only code here that knows endpoints and generated types. */
@Injectable({ providedIn: 'root' })
export class PurchasesApi {
  private readonly http = inject(HttpClient);

  /** One page of the company's orders, searched, narrowed and sorted by the API. */
  async orders(
    companyId: string,
    search: PurchaseOrderSearch,
  ): Promise<ListPage<PurchaseOrderRow>> {
    return this.guard(async () => {
      const page = await firstValueFrom(
        this.http.get<ApiCompaniesCompanyIdpurchaseOrdersGetCollectionResponse>(
          orderPath(companyId),
          {
            headers: { Accept: 'application/ld+json' },
            params: toSearchParams(search),
          },
        ),
      );
      if (page.totalItems === undefined)
        throw new Error('A page of purchase orders came without its total.');
      return { rows: page.member.map(toOrder), total: page.totalItems };
    });
  }

  /** What each status chip would list under the list's words and vendors. */
  async statusCounts(
    companyId: string,
    search: PurchaseOrderSearch,
  ): Promise<PurchaseOrderStatusCounts> {
    return this.guard(async () => {
      const counts = await firstValueFrom(
        this.http.get<PurchaseOrderStatusCountsPurchaseOrderStatusCountsRead>(
          `${companyPath(companyId)}/purchase-order-status-counts`,
          { params: toCountParams(search) },
        ),
      );
      if (counts.all === undefined || counts.statuses === undefined)
        throw new Error('Status counts came without their figures.');
      return { all: counts.all, statuses: { ...counts.statuses } };
    });
  }

  /**
   * The few vendors or products a person means, or — given ids — exactly the records an order already names, whether
   * or not they are still offered.
   */
  async pickVendors(companyId: string, asked: PickAsked): Promise<VendorOption[]> {
    return this.guard(async () => {
      const rows = await firstValueFrom(
        this.http.get<PurchaseOrderVendorPickPurchaseOrderVendorPickRead[]>(
          `${companyPath(companyId)}/purchase-order-options/vendors`,
          { params: pickParams(asked) },
        ),
      );
      return rows.map((vendor) => ({
        id: vendor.id ?? '',
        number: vendor.number,
        name: vendor.name,
        active: vendor.active,
      }));
    });
  }

  async pickProducts(companyId: string, asked: PickAsked): Promise<ProductOption[]> {
    return this.guard(async () => {
      const rows = await firstValueFrom(
        this.http.get<PurchaseOrderProductPickPurchaseOrderProductPickRead[]>(
          `${companyPath(companyId)}/purchase-order-options/products`,
          { params: pickParams(asked) },
        ),
      );
      return rows.map((product) => ({
        id: product.id ?? '',
        reference: product.reference,
        name: product.name,
        unitId: product.unitId,
        unitName: product.unitName,
        tracking: trackingOf(product.tracking),
        goods: product.goods,
        active: product.active,
        costPrice: product.costPrice,
      }));
    });
  }

  async order(companyId: string, id: string): Promise<PurchaseOrderRow> {
    return this.guard(async () =>
      toOrder(
        await firstValueFrom(
          this.http.get<PurchaseOrderPurchaseOrderRead>(orderPath(companyId, id)),
        ),
      ),
    );
  }

  /** Where an order's PDF is downloaded from, with the session the browser already has. */
  pdfUrl(companyId: string, id: string): string {
    return `${orderPath(companyId, id)}/pdf`;
  }

  /** A draft; 422 naming what the API refused. */
  async create(companyId: string, input: PurchaseOrderInput): Promise<PurchaseOrderRow> {
    const body: PurchaseOrderWrite = toBody(input);
    return this.guard(async () =>
      toOrder(
        await firstValueFrom(
          this.http.post<PurchaseOrderPurchaseOrderRead>(orderPath(companyId), body),
        ),
      ),
    );
  }

  /**
   * 409 once the order is no longer a draft; `version` is the one the form was opened at, and a later one saved
   * elsewhere answers `record_changed`.
   */
  async revise(
    companyId: string,
    id: string,
    input: PurchaseOrderInput,
    version: number | null,
  ): Promise<PurchaseOrderRow> {
    const body: PurchaseOrderRevise = { ...toBody(input), version };
    return this.guard(async () =>
      toOrder(
        await firstValueFrom(
          this.http.put<PurchaseOrderPurchaseOrderRead>(orderPath(companyId, id), body),
        ),
      ),
    );
  }

  /** Numbers a draft and records the vendor as it reads now; 409 when it is not a draft, 422 without lines. */
  async send(companyId: string, id: string): Promise<PurchaseOrderRow> {
    return this.step(companyId, id, 'send');
  }

  /** Ends an order short: what came stays, nothing more is expected. */
  async close(companyId: string, id: string): Promise<PurchaseOrderRow> {
    return this.step(companyId, id, 'close');
  }

  /** Withdraws an order nothing came against yet. */
  async cancel(companyId: string, id: string): Promise<PurchaseOrderRow> {
    return this.step(companyId, id, 'cancel');
  }

  /** Where the order's goods are offered to go, read with the right to receive alone. */
  async receiving(companyId: string, id: string): Promise<Receiving> {
    return this.guard(async () => {
      const raw = await firstValueFrom(
        this.http.get<PurchaseOrderReceivingPurchaseOrderReceivingRead>(
          `${orderPath(companyId, id)}/receiving`,
        ),
      );
      if (raw.places === undefined || raw.homes === undefined)
        throw new Error('The receiving answer came without its places.');
      return {
        places: raw.places.map((place) => ({ ...place })),
        homes: Object.fromEntries(raw.homes.map((home) => [home.orderLineId, home.locationId])),
        asksCostBasis: raw.asksCostBasis === true,
      };
    });
  }

  /**
   * Goods that came against the order, put on the shelves when the answer, the order as it now stands, comes back; a
   * line the stock refuses refuses the whole receipt, and the 422 names it.
   */
  async receive(companyId: string, id: string, input: ReceiptInput): Promise<PurchaseOrderRow> {
    const body: GoodsReceiptWrite = {
      receivedOn: input.receivedOn,
      supplierReference: input.supplierReference,
      apply: input.apply,
      lines: input.lines.map((line) => ({ ...line })),
    };
    return this.guard(async () =>
      toOrder(
        await firstValueFrom(
          this.http.post<GoodsReceiptAnswer>(`${orderPath(companyId, id)}/receipts`, body),
        ),
      ),
    );
  }

  private async step(
    companyId: string,
    id: string,
    action: 'send' | 'close' | 'cancel',
  ): Promise<PurchaseOrderRow> {
    return this.guard(async () =>
      toOrder(
        await firstValueFrom(
          this.http.post<PurchaseOrderPurchaseOrderRead>(
            `${orderPath(companyId, id)}/${action}`,
            null,
          ),
        ),
      ),
    );
  }

  private async guard<T>(call: () => Promise<T>): Promise<T> {
    try {
      return await call();
    } catch (error) {
      throw new PurchasesRefused(
        codeOf(error),
        error instanceof HttpErrorResponse ? refusedField(error) : null,
      );
    }
  }
}

function codeOf(error: unknown): PurchasesError {
  if (!(error instanceof HttpErrorResponse) || error.status === 0) {
    return 'network';
  }
  switch (error.status) {
    case 404:
      return 'not_found';
    case 409:
      return isRecordChanged(error.error) ? 'record_changed' : 'conflict';
    default:
      // A vendor retired since the order was started, named by the field the API refused.
      return refusedField(error) === 'vendorId' ? 'vendor_unavailable' : 'invalid';
  }
}

const companyPath = (companyId: string): string =>
  `/api/companies/${encodeURIComponent(companyId)}`;

const orderPath = (companyId: string, id?: string): string =>
  `${companyPath(companyId)}/purchase-orders${id === undefined ? '' : `/${encodeURIComponent(id)}`}`;

/** Only what the search asks for: an absent parameter is the API's own default, never an empty one. */
function toSearchParams(search: PurchaseOrderSearch): HttpParams {
  let params = new HttpParams().set('page', search.page).set('itemsPerPage', search.itemsPerPage);
  if (search.q.trim() !== '') params = params.set('q', search.q.trim());
  for (const status of search.status) params = params.append('status[]', status);
  params = narrowing(params, search);
  if (search.order !== null)
    params = params.set(`order[${search.order.key}]`, search.order.direction);
  return params;
}

/** What narrows a list whatever the status: the vendors and the ends of the intervals, as the API names them. */
function narrowing(params: HttpParams, search: PurchaseOrderSearch): HttpParams {
  let next = params;
  for (const id of search.vendorIds) next = next.append('vendorId[]', id);
  for (const [key, value] of Object.entries(search.intervals)) {
    next = next.set(apiRangeKey(key), value);
  }
  return next;
}

/** The chips narrow by status themselves, and a count has no page or order. */
function toCountParams(search: PurchaseOrderSearch): HttpParams {
  let params = new HttpParams();
  if (search.q.trim() !== '') params = params.set('q', search.q.trim());
  return narrowing(params, search);
}

function toOrder(
  raw: PurchaseOrderPurchaseOrderRead | PurchaseOrderJsonldPurchaseOrderRead | GoodsReceiptAnswer,
): PurchaseOrderRow {
  return {
    id: raw.id ?? '',
    version: raw.version ?? 0,
    number: raw.number ?? null,
    status: PURCHASE_ORDER_STATUSES.find((status) => status === raw.status) ?? 'draft',
    vendorId: raw.vendorId ?? '',
    vendorName: raw.vendorName ?? '',
    recordedVendorNumber: raw.vendorSnapshot?.number ?? null,
    establishmentId: raw.establishmentId ?? null,
    issueDate: raw.issueDate ?? null,
    expectedOn: raw.expectedOn ?? null,
    remarksPrinted: raw.remarksPrinted ?? null,
    notesInternal: raw.notesInternal ?? null,
    lines: (raw.lines ?? []).map((line) => ({
      id: line.id ?? '',
      productId: line.productId,
      productReference: line.productReference ?? '',
      productTracking: line.productTracking == null ? null : trackingOf(line.productTracking),
      description: line.description ?? null,
      quantity: line.quantity,
      unitId: line.unitId ?? '',
      unitCode: line.unitCode ?? '',
      unitName: line.unitName ?? '',
      unitPriceNet: line.unitPriceNet ?? null,
      net: line.net ?? null,
      vendorCode: line.vendorCode ?? null,
      received: line.received ?? '0',
      outstanding: line.outstanding ?? line.quantity,
    })),
    totalNet: raw.totalNet ?? null,
    receipts: (raw.receipts ?? []).map((receipt) => ({
      id: receipt.id,
      receivedOn: receipt.receivedOn,
      supplierReference: receipt.supplierReference,
      lines: receipt.lines.map((line) => ({ ...line })),
    })),
  };
}

function toBody(input: PurchaseOrderInput): PurchaseOrderWrite {
  return {
    vendorId: input.vendorId,
    establishmentId: input.establishmentId,
    expectedOn: input.expectedOn,
    remarksPrinted: input.remarksPrinted,
    notesInternal: input.notesInternal,
    lines: input.lines.map((line) => ({ ...line })),
  };
}
