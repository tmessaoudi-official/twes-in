// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  type OnInit,
  signal,
  untracked,
} from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { ProductScans } from '../products/product-scans';
import { Label } from '../shared/a11y/label';
import { Feedback } from '../shared/feedback/feedback';
import { DecimalInput } from '../shared/form/decimal-input';
import { type Scan, ScanBus, type ScanOutcome } from '../shared/scan/scan-bus';
import { PageTabs } from '../shared/ui/page-tabs';
import {
  type CountLine,
  countInputs,
  countLine,
  locationScanned,
  readyToRecord,
} from './count-sheet';
import { InventoryFacade } from './inventory-facade';
import { locationLabels } from './inventory-forms';
import { INVENTORY_TABS } from './inventory-nav';

/**
 * Count mode (docs/SPEC.md § 7, 2026-09-23 slice 8): walking the shelves with a scanner. The location counted is the
 * default one until a location's label — its address, or its code — is scanned; every product scanned there is
 * tallied, the same product and lot counting on as a till does, and each line can be corrected by hand. Recording
 * writes one count per line, which sets what is on hand at that location to what was found; a line the API refused
 * stays on the sheet with the reason shown, and the others are gone. Nothing is recorded of what was not scanned.
 */
@Component({
  selector: 'app-stock-count-page',
  imports: [
    FormsModule,
    MatButtonModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatSelectModule,
    DecimalInput,
    Label,
    PageTabs,
    TranslatePipe,
  ],
  templateUrl: './stock-count-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class StockCountPage implements OnInit {
  private readonly facade = inject(InventoryFacade);
  private readonly auth = inject(AuthFacade);
  private readonly feedback = inject(Feedback);
  private readonly productScans = inject(ProductScans);
  private readonly bus = inject(ScanBus);
  protected readonly tabs = INVENTORY_TABS;

  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly mayWrite = computed(() => this.auth.hasPermission('stock.write'));
  protected readonly busy = this.facade.busy;
  protected readonly error = this.facade.error;
  protected readonly locations = computed(() => [...locationLabels(this.facade.locations())]);
  /** The location being counted; empty until the locations are read, then the first default one. */
  protected readonly locationId = signal('');
  protected readonly lines = signal<readonly CountLine[]>([]);
  /** A code typed by hand, for a label the scanner cannot read. */
  protected readonly code = signal('');

  constructor() {
    this.bus.handle((scan) => this.scanned(scan));
    effect(() => {
      const locations = this.facade.locations();
      untracked(() => {
        if (this.locationId() !== '') return;
        const first = locations.find((location) => location.isDefault) ?? locations[0];
        if (first) this.locationId.set(first.id);
      });
    });
  }

  async ngOnInit(): Promise<void> {
    const companyId = this.company()?.id;
    if (companyId) await this.facade.loadStockContext(companyId);
  }

  protected submit(event: Event): void {
    event.preventDefault();
    const code = this.code().trim();
    if (code === '') return;
    this.code.set('');
    void this.bus.receive(code, 'wedge');
  }

  protected setCounted(index: number, counted: string): void {
    this.lines.update((lines) =>
      lines.map((line, at) => (at === index ? { ...line, counted: counted ?? '' } : line)),
    );
  }

  protected setLot(index: number, lotCode: string): void {
    this.lines.update((lines) =>
      lines.map((line, at) => (at === index ? { ...line, lotCode } : line)),
    );
  }

  protected remove(index: number): void {
    this.lines.update((lines) => lines.filter((_, at) => at !== index));
  }

  /** Records each line as a count at the location; what the API refused stays, the rest is done. */
  protected async record(): Promise<void> {
    const companyId = this.company()?.id;
    const locationId = this.locationId();
    const lines = this.lines();
    if (!companyId || locationId === '' || lines.length === 0 || this.busy()) return;
    if (!lines.every(readyToRecord)) {
      this.feedback.failure('inventory.count.incomplete');
      return;
    }
    const inputs = countInputs(lines, locationId);
    const kept: CountLine[] = [];
    for (const [index, input] of inputs.entries()) {
      if (!(await this.facade.record(companyId, input))) kept.push(lines[index]);
    }
    this.lines.set(kept);
    const recorded = lines.length - kept.length;
    if (recorded > 0) this.feedback.success('inventory.count.recorded', { count: recorded });
  }

  private async scanned(scan: Scan): Promise<ScanOutcome> {
    const companyId = this.company()?.id;
    if (!companyId || !this.auth.hasPermission('stock.write')) return { kind: 'unclaimed' };

    const located = locationScanned(scan.code, this.facade.locations());
    if (located !== null) {
      const before = this.locationId();
      this.locationId.set(located);
      const name = this.locations().find(([id]) => id === located)?.[1] ?? scan.code;
      return {
        kind: 'done',
        key: 'inventory.count.at_location',
        params: { name },
        undo: () => this.locationId.set(before),
      };
    }

    if (!this.auth.hasPermission('product.read')) return { kind: 'unclaimed' };
    const named = await this.productScans.named(scan.code);
    if (named === null) return { kind: 'unclaimed' };
    if (!named.isActive) {
      return { kind: 'refused', key: 'scan.retired', params: { name: named.name } };
    }
    const [product] = await this.facade.pickProducts(companyId, { ids: [named.productId] });
    if (product === undefined) return { kind: 'unclaimed' };

    const counted = countLine(
      this.lines(),
      product,
      named.lot ?? named.serial ?? '',
      Math.max(named.quantity, 1) * scan.times,
    );
    this.lines.set(counted.lines);
    return {
      kind: 'done',
      key: 'inventory.count.tallied',
      params: { name: product.name, quantity: counted.lines[counted.index].counted },
      product: { name: named.name, unitPrice: named.unitPriceGross },
      undo: () => this.lines.set(counted.before),
    };
  }
}
