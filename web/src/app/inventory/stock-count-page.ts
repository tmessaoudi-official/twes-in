// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  effect,
  ElementRef,
  inject,
  input,
  type OnInit,
  signal,
  untracked,
  viewChildren,
} from '@angular/core';
import { FormsModule, NgModel } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { ProductScans } from '../products/product-scans';
import { Label } from '../shared/a11y/label';
import { Feedback } from '../shared/feedback/feedback';
import { DecimalInput } from '../shared/form/decimal-input';
import { Select } from '../shared/form/select';
import { UnsavedChanges } from '../shared/form/unsaved-changes';
import { type Scan, ScanBus, type ScanOutcome } from '../shared/scan/scan-bus';
import { PageTabs } from '../shared/ui/page-tabs';
import { LiveChanges } from '../shared/realtime/live-changes';
import { CountEffectNote } from './count-effect-note';
import type { CountContext } from './inventory-types';
import {
  type CountLine,
  countEffect,
  countGroups,
  countInputs,
  countLine,
  locationScanned,
  readyToRecord,
} from './count-sheet';
import { InventoryFacade } from './inventory-facade';
import { locationLabels, QUANTITY_PATTERN } from './inventory-forms';
import { INVENTORY_TABS } from './inventory-nav';

/**
 * Count mode (docs/SPEC.md § 7, 2026-09-23 slice 8): walking the shelves with a scanner. The location counted is the
 * default one, or the one whose label's address opened the page, until a location's label — its address, or its
 * code — is scanned; every product scanned there is
 * tallied at it, the same product and lot counting on as a till does, and each line can be corrected by hand. A line
 * keeps the location it was scanned at, and the sheet is shown by location. Recording writes one count per line at its
 * own location, which sets what is on hand there to what was found; a line the API refused stays on the sheet with the
 * reason shown, and the others are gone. Nothing is recorded of what was not scanned.
 */
@Component({
  selector: 'app-stock-count-page',
  imports: [
    FormsModule,
    MatButtonModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    Select,
    DecimalInput,
    Label,
    PageTabs,
    TranslatePipe,
    CountEffectNote,
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
  private readonly live = inject(LiveChanges);
  private readonly destroyRef = inject(DestroyRef);
  protected readonly tabs = INVENTORY_TABS;

  /** The location a label's address named (`/stock/locations/:location`), counted from the start. */
  readonly location = input<string>();

  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly mayWrite = computed(() => this.auth.hasPermission('stock.write'));
  protected readonly busy = this.facade.busy;
  protected readonly error = this.facade.error;
  protected readonly locations = computed(() => [...locationLabels(this.facade.locations())]);
  protected readonly locationOptions = computed(() =>
    this.locations().map(([value, label]) => ({ value, label })),
  );
  /** The location being counted; empty until the locations are read, then the first default one. */
  protected readonly locationId = signal('');
  protected readonly lines = signal<readonly CountLine[]>([]);
  /** The sheet by location, each named as the location picker names it. */
  protected readonly groups = computed(() => {
    const names = new Map(this.locations());
    return countGroups(this.lines()).map((group) => ({
      ...group,
      name: names.get(group.locationId) ?? '',
    }));
  });
  /** A code typed by hand, for a label the scanner cannot read. */
  protected readonly code = signal('');
  protected readonly quantityPattern = QUANTITY_PATTERN;
  /** Each line's lot and count, which an attempt to record shows wrong under themselves. */
  private readonly lineFields = viewChildren('lineField', { read: NgModel });
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef).nativeElement;

  /** What the API says of each product on the sheet before it is counted, by product id. */
  private readonly contexts = signal<ReadonlyMap<string, CountContext>>(new Map());
  /** What recording each line would change, said on the line: null until its product is read. */
  protected readonly effects = computed(() => {
    const contexts = this.contexts();
    const locations = this.facade.locations();
    return this.lines().map((line) => {
      const context = contexts.get(line.productId);
      return context === undefined ? null : countEffect(context, locations, line);
    });
  });
  protected readonly placeNames = computed<ReadonlyMap<string, string>>(
    () => new Map(this.locations()),
  );

  constructor() {
    // What is counted and not yet recorded is unsaved work: leaving the page would lose it.
    inject(UnsavedChanges).declare(computed(() => this.lines().length));
    this.bus.handle((scan) => this.scanned(scan));
    effect(() => {
      const locations = this.facade.locations();
      untracked(() => {
        if (this.locationId() !== '') return;
        const named = locations.find((location) => location.id === this.location());
        const first = named ?? locations.find((location) => location.isDefault) ?? locations[0];
        if (first) this.locationId.set(first.id);
      });
    });
    // A product scanned for the first time is read once; what is already known is read again only on a change.
    effect(() => {
      const known = this.contexts();
      const missing = [...new Set(this.lines().map((line) => line.productId))].filter(
        (id) => !known.has(id),
      );
      if (missing.length > 0) untracked(() => void this.readContexts(missing));
    });
  }

  async ngOnInit(): Promise<void> {
    const companyId = this.company()?.id;
    if (!companyId) return;
    // A count recorded here, in another tab or by someone else changes what each line expects.
    this.live.reloadOn(
      ['stock', 'product', 'stock_location'],
      () => this.readContexts([...this.contexts().keys()]),
      this.destroyRef,
    );
    await this.facade.loadStockContext(companyId);
  }

  private readonly asked = new Set<string>();

  private async readContexts(productIds: readonly string[]): Promise<void> {
    const companyId = this.company()?.id;
    // Each product is asked once until its answer is in, so a second scan of it asks nothing more.
    const asking = productIds.filter((id) => !this.asked.has(id) || this.contexts().has(id));
    if (!companyId || asking.length === 0) return;
    for (const id of asking) this.asked.add(id);
    const read = await this.facade.countContexts(companyId, asking);
    this.contexts.update((contexts) => {
      const next = new Map(contexts);
      for (const context of read) next.set(context.productId, context);
      return next;
    });
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

  /** Records each line as a count at the location it was found; what the API refused stays, the rest is done. */
  protected async record(): Promise<void> {
    const companyId = this.company()?.id;
    const lines = this.lines();
    if (!companyId || lines.length === 0 || this.busy()) return;
    if (!lines.every(readyToRecord)) {
      // Said under each field it concerns, the cursor in the first, as well as for the whole sheet.
      for (const field of this.lineFields()) field.control.markAsTouched();
      this.host.querySelector<HTMLElement>('input.ng-invalid')?.focus();
      this.feedback.failure('inventory.count.incomplete');
      return;
    }
    const inputs = countInputs(lines);
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

    const located = locationScanned(scan.code, this.facade.locations(), this.locationId());
    if (located?.kind === 'ambiguous') {
      return {
        kind: 'refused',
        key: 'inventory.scan.location_ambiguous',
        params: { code: scan.code.trim() },
      };
    }
    if (located !== null) {
      const before = this.locationId();
      this.locationId.set(located.id);
      const name = this.locations().find(([id]) => id === located.id)?.[1] ?? scan.code;
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
      this.locationId(),
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
