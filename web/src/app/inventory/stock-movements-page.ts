// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  effect,
  inject,
  input,
  signal,
  untracked,
} from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatDialog } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { Router, RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { firstValueFrom } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { ProductScans } from '../products/product-scans';
import { Feedback } from '../shared/feedback/feedback';
import { buildFormGroup } from '../shared/form/form-builder';
import { LiveChanges } from '../shared/realtime/live-changes';
import { type Scan, ScanBus, type ScanOutcome } from '../shared/scan/scan-bus';
import { AmountPipe, MomentPipe } from '../shared/i18n/format-pipes';
import { DataList, DataListCell } from '../shared/list/data-list';
import type { ExportFormat } from '../shared/list/export-address';
import { ListExport } from '../shared/list/list-export';
import type { PickAsked } from '../shared/form/pick-api';
import type {
  ListDescriptor,
  ListPickSource,
  ListQuery,
  RowAction,
} from '../shared/list/list-types';
import { PageTabs } from '../shared/ui/page-tabs';
import { InventoryFacade } from './inventory-facade';
import {
  locationLabels,
  MOVEMENTS_LIST,
  movementListRows,
  movementSearch,
  receiptCostForm,
  receiptCostInput,
  receiptCostValues,
  type StockMovementListRow,
} from './inventory-forms';
import { INVENTORY_TABS } from './inventory-nav';
import type { StockMovementSearch } from './inventory-types';
import { ReceiptCostDialog } from './receipt-cost-dialog';

/**
 * How stock moved: one product's movements when the address names it, one lot's or serial number's when it names that
 * (the recall search, row 63 slice 10: typed, or read from a scanned GS1 label), otherwise the company's, a page at a
 * time.
 */
@Component({
  selector: 'app-stock-movements-page',
  imports: [
    PageTabs,
    MatButtonModule,
    MatFormFieldModule,
    MatInputModule,
    RouterLink,
    TranslatePipe,
    AmountPipe,
    MomentPipe,
    DataList,
    DataListCell,
    ListExport,
  ],
  templateUrl: './stock-movements-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class StockMovementsPage {
  /** The `productId` query parameter, bound by the router. */
  readonly productId = input<string | undefined>();
  /** The `lot` query parameter, bound by the router: a lot or serial code, matched whole. */
  readonly lot = input<string | undefined>();
  /**
   * The `costOf` query parameter: a receipt left « à compléter » whose cost « À surveiller » sent a cost reader here to
   * enter, with `productId` naming what it received.
   */
  readonly costOf = input<string | undefined>();

  protected readonly tabs = INVENTORY_TABS;
  private readonly facade = inject(InventoryFacade);
  private readonly auth = inject(AuthFacade);
  private readonly live = inject(LiveChanges);
  private readonly destroyRef = inject(DestroyRef);
  private readonly router = inject(Router);
  private readonly productScans = inject(ProductScans);
  private readonly dialog = inject(MatDialog);
  private readonly feedback = inject(Feedback);

  /** A receipt left « à compléter » takes its cost here, from whoever may read costs (audit 2026-10-06, C challenge 9). */
  private readonly enterCostAction: RowAction<StockMovementListRow> = {
    id: 'enter-cost',
    label: 'inventory.receipt_cost.enter_of',
    labelParams: (row) => ({ name: row.productLabel }),
    icon: 'request_quote',
    run: (row) => void this.enterCost(row.id, row.productId, row.productLabel),
    shown: (row) => row.costToComplete && this.auth.hasPermission('product.cost.read'),
  };
  protected readonly list: ListDescriptor<StockMovementListRow> = {
    ...MOVEMENTS_LIST,
    actions: [...(MOVEMENTS_LIST.actions ?? []), this.enterCostAction],
  };
  protected readonly rows = computed(() =>
    movementListRows(this.facade.movements(), this.facade.locations()),
  );
  protected readonly total = this.facade.movementsTotal;
  protected readonly error = this.facade.error;
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly rowTestId = (row: StockMovementListRow): string => `stock-movement-${row.id}`;

  /** What the list last asked the API for; the page is not read until the list has said what it wants. */
  private query: ListQuery | null = null;
  private readonly searched = signal<StockMovementSearch | null>(null);
  /** What the list shows now, as a file: null until the list has asked for its first page. */
  protected readonly exporter = computed(() => {
    const companyId = this.company()?.id;
    const search = this.searched();
    return companyId && search !== null
      ? (format: ExportFormat) => this.facade.exportMovementsUrl(companyId, search, format)
      : null;
  });

  constructor() {
    // The page stays when only the query changes, as when leaving one product for all of them, so the product it
    // narrows to is read again here rather than only when the list first speaks.
    effect(() => {
      this.productId();
      this.lot();
      const companyId = untracked(this.company)?.id;
      const query = untracked(() => this.query);
      if (companyId && query !== null) this.load(companyId, query);
    });
    inject(ScanBus).handle((scan) => this.scanned(scan));
    // Sent here by « À surveiller » to enter one receipt's cost: asked once, then the address forgets it.
    effect(() => {
      const movementId = this.costOf();
      const productId = this.productId();
      if (movementId && productId && this.company()) {
        untracked(() => void this.enterCost(movementId, productId, ''));
      }
    });
    void this.start();
  }

  /**
   * Asks a cost reader for a receipt's cost, with the choice the company leaves to a receipt when it leaves one, and
   * enters it. The movements are read again, so the row stops saying its cost is to complete.
   */
  private async enterCost(movementId: string, productId: string, product: string): Promise<void> {
    const companyId = this.company()?.id;
    if (!companyId) return;
    const mode = (await this.facade.receiptCost(companyId, productId, '1', '0'))?.mode ?? null;
    const descriptor = receiptCostForm(mode);
    const group = buildFormGroup(descriptor, receiptCostValues());
    const values = await firstValueFrom(
      this.dialog
        .open(ReceiptCostDialog, {
          data: { descriptor, group, product },
          autoFocus: 'first-tabbable',
        })
        .afterClosed(),
    );
    if (this.costOf()) {
      void this.router.navigate([], {
        queryParams: { costOf: null },
        queryParamsHandling: 'merge',
      });
    }
    if (!values) return;
    const { unitCost, applyCost } = receiptCostInput(values);
    if (await this.facade.enterReceiptCost(companyId, movementId, unitCost, applyCost)) {
      this.feedback.effect('inventory.receipt_cost.entered', {}, 'definitif');
    }
  }

  /** Opens the movements of the lot or serial number typed into the search. */
  protected findLot(code: string): void {
    const lot = code.trim();
    if (lot !== '') void this.router.navigate(['/stock/movements'], { queryParams: { lot } });
  }

  protected onQuery(query: ListQuery): void {
    this.query = query;
    const companyId = this.company()?.id;
    if (companyId) this.load(companyId, query);
  }

  private load(companyId: string, query: ListQuery): void {
    const search = this.search(query);
    this.searched.set(search);
    void this.facade.loadMovements(companyId, search);
  }

  /**
   * The address may name a product (from its page) or a lot; the list names everything else. A product the address names
   * is one more of the products asked for, so a pick made on the list adds to it rather than replacing it.
   */
  private search(query: ListQuery): StockMovementSearch {
    const listed = movementSearch(query);
    const named = this.productId();
    return {
      ...listed,
      productIds:
        named === undefined || listed.productIds.includes(named)
          ? listed.productIds
          : [named, ...listed.productIds],
      lot: this.lot() ?? null,
    };
  }

  /**
   * Where the « Filtres » panel finds a product or a location to narrow by, and names the ones an address holds. Products
   * are searched by the API, never read whole; the locations are already here, each named by its path, since picking
   * one lists what moved under it too.
   */
  protected readonly pickSources: Readonly<Record<string, ListPickSource>> = {
    product: {
      search: (words) => this.pickProducts({ words }),
      byIds: (ids) => this.pickProducts({ ids }),
    },
    location: {
      search: async (words) => {
        const wanted = words.trim().toLocaleLowerCase();
        return this.locationChoices().filter((each) =>
          `${each.code} ${each.name}`.toLocaleLowerCase().includes(wanted),
        );
      },
      byIds: async (ids) => this.locationChoices().filter((each) => ids.includes(each.id)),
    },
  };
  private async pickProducts(asked: PickAsked) {
    const companyId = this.company()?.id;
    if (!companyId) return [];
    const found = await this.facade.pickProducts(companyId, asked);
    return found.map((product) => ({
      id: product.id,
      code: product.reference,
      name: product.name,
    }));
  }
  private locationChoices() {
    const labels = locationLabels(this.facade.locations());
    return [...labels].map(([id, label]) => ({ id, code: '', name: label }));
  }

  /**
   * A label carrying a lot or serial number opens its movements; any other code is the card's, as on every screen
   * that has nothing to do with it.
   */
  private async scanned(scan: Scan): Promise<ScanOutcome> {
    if (!this.company() || !this.auth.hasPermission('product.read')) return { kind: 'unclaimed' };
    const named = await this.productScans.named(scan.code);
    const lot = named?.lot ?? named?.serial ?? null;
    if (named === null || lot === null) return { kind: 'unclaimed' };
    await this.router.navigate(['/stock/movements'], { queryParams: { lot } });
    return { kind: 'done', key: 'inventory.movements.scanned_lot', params: { code: lot } };
  }

  private async start(): Promise<void> {
    const companyId = this.company()?.id;
    if (!companyId) return;
    this.live.reloadOn(
      ['stock', 'delivery_note', 'invoice'],
      () => this.facade.reloadMovements(companyId),
      this.destroyRef,
    );
    await this.facade.loadLocations(companyId);
  }
}
