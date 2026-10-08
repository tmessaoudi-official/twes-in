// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, Injector } from '@angular/core';
import { TranslateService } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import type { StockLevelRow } from '../inventory/inventory-types';
import { FormatFacade } from '../shared/i18n/format-facade';
import type { ScanDetails } from '../shared/scan/scan-details';
import { ProductScans } from './product-scans';

/** Places named on the phone: more would not fit a line each on a phone's screen. */
const PLACES_MAX = 3;

/**
 * What a phone is told about a product it scanned, beyond its name and price (docs/SPEC.md § 7, 2026-09-23 09:45,
 * slice 4): what a pack holds, the use-by date a GS1 code carried, what is in stock and where, and the nearest use-by
 * date of that stock, marked when it is past. A customer at the till could read all of it off the screen; the cost
 * and the suppliers stay on the computer.
 */
@Injectable({ providedIn: 'root' })
export class ProductScanDetails implements ScanDetails {
  private readonly scans = inject(ProductScans);
  private readonly injector = inject(Injector);
  private readonly auth = inject(AuthFacade);
  private readonly format = inject(FormatFacade);
  private readonly translate = inject(TranslateService);

  async of(code: string): Promise<readonly string[]> {
    const companyId = this.auth.me()?.company?.id;
    const scan = await this.scans.named(code);
    if (companyId === undefined || scan === null) return [];
    const lines: string[] = [];
    if (scan.quantity > 1) lines.push(this.say('pack', { count: scan.quantity }));
    if (scan.useBy !== null) lines.push(this.say('use_by', { date: this.format.day(scan.useBy) }));
    lines.push(...(await this.stockLines(companyId, scan.productId, scan.reference)));
    return lines;
  }

  private async stockLines(
    companyId: string,
    productId: string,
    reference: string,
  ): Promise<string[]> {
    // The stock's client is read on the first scan, not with the first page: this card is in the shell's bundle, and
    // the whole client there would grow the first page with every change to the stock screens.
    const { InventoryApi } = await import('../inventory/inventory-api');
    const page = await this.injector.get(InventoryApi).levels(companyId, {
      page: 1,
      itemsPerPage: 100,
      q: reference,
      locationIds: [],
      establishmentIds: [],
      productIds: [productId],
      negative: null,
      expired: null,
      intervals: {},
      order: null,
    });
    const held = page.rows.filter((row) => row.productId === productId && Number(row.quantity) > 0);
    if (held.length === 0) return [this.say('none')];
    const lines = [this.say('stock', { count: this.counted(held, sum(held)) })];
    const places = new Map<string, StockLevelRow[]>();
    for (const row of held)
      places.set(row.locationName, [...(places.get(row.locationName) ?? []), row]);
    for (const [place, rows] of [...places].slice(0, PLACES_MAX)) {
      lines.push(this.say('at', { place, count: this.counted(rows, sum(rows)) }));
    }
    const nearest = held
      .map((row) => row.lotExpiresOn)
      .filter((day): day is string => day !== null)
      .sort()[0];
    if (nearest !== undefined) {
      const past = nearest < new Date().toISOString().slice(0, 10);
      lines.push(this.say(past ? 'expired' : 'nearest', { date: this.format.day(nearest) }));
    }
    return lines;
  }

  /** A quantity in the unit's own decimals, written as the screen writes figures. */
  private counted(rows: readonly StockLevelRow[], quantity: number): string {
    return this.format.decimal(quantity.toFixed(rows[0]?.unitDecimals ?? 0));
  }

  private say(key: string, params?: Record<string, string | number>): string {
    return this.translate.instant(`scan.phone.details.${key}`, params);
  }
}

function sum(rows: readonly StockLevelRow[]): number {
  return rows.reduce((total, row) => total + Number(row.quantity), 0);
}
