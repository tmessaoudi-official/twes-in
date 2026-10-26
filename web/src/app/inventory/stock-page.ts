// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  effect,
  inject,
  linkedSignal,
  OnInit,
  signal,
  untracked,
  viewChild,
} from '@angular/core';
import { LiveChanges } from '../shared/realtime/live-changes';
import { toSignal } from '@angular/core/rxjs-interop';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';
import { debounceTime, map, startWith } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { RefusalLine } from '../shared/documents/refusal-line';
import { DescriptorForm } from '../shared/form/descriptor-form';
import { RefusalFigures } from '../shared/form/refusal-figures';
import { unsavedChanges } from '../shared/form/dirty-count';
import { UnsavedChanges } from '../shared/form/unsaved-changes';
import type { PickOption } from '../shared/form/pick-field';
import { buildFormGroup, type DescriptorFormGroup } from '../shared/form/form-builder';
import type { FormDescriptor, FormValues } from '../shared/form/form-types';
import { todayIn } from '../shared/i18n/format';
import { AmountPipe, DayPipe } from '../shared/i18n/format-pipes';
import { DataList, DataListCell } from '../shared/list/data-list';
import type { ExportFormat } from '../shared/list/export-address';
import { ListExport } from '../shared/list/list-export';
import { PageTabs } from '../shared/ui/page-tabs';
import { StatusBadge } from '../shared/ui/status-badge';
import { InventoryFacade } from './inventory-facade';
import type { PickAsked } from '../shared/form/pick-api';
import type { ListDescriptor, ListPickSource, ListQuery } from '../shared/list/list-types';
import {
  countInput,
  locationLabels,
  movementForm,
  movementInput,
  movementValues,
  receiptInput,
  stockList,
  type StockListRow,
  stockListRows,
  stockSearch,
} from './inventory-forms';
import { INVENTORY_TABS } from './inventory-nav';
import { countEffect, locationScanned } from './count-sheet';
import { CountEffectNote } from './count-effect-note';
import { ReceiptPlacement } from './receipt-placement';
import { addPlace, type Part, placement, toCountParts } from './split-receipt';
import type {
  CountContext,
  ReceiptCostView,
  StockOperation,
  StockProductOption,
  StockVendorOption,
  StockSearch,
} from './inventory-types';
import { isStockOperation, movementAddress } from './inventory-types';
import { Feedback } from '../shared/feedback/feedback';
import { ProductScans } from '../products/product-scans';
import { type Scan, ScanBus, type ScanOutcome } from '../shared/scan/scan-bus';
import { addCount } from '../shared/scan/scan-lines';

/** What is on hand of each product whose stock is kept, per location, with goods received and counts recorded here. */
@Component({
  selector: 'app-stock-page',
  imports: [
    PageTabs,
    MatButtonModule,
    MatCardModule,
    RouterLink,
    TranslatePipe,
    AmountPipe,
    DayPipe,
    DataList,
    DataListCell,
    ListExport,
    DescriptorForm,
    StatusBadge,
    ReceiptPlacement,
    CountEffectNote,
  ],
  templateUrl: './stock-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class StockPage implements OnInit {
  private readonly live = inject(LiveChanges);
  private readonly destroyRef = inject(DestroyRef);
  protected readonly tabs = INVENTORY_TABS;
  private readonly facade = inject(InventoryFacade);
  private readonly auth = inject(AuthFacade);
  private readonly feedback = inject(Feedback);
  private readonly productScans = inject(ProductScans);

  /** Where a line's quantity came from: an address, so it is a real link rather than a button that navigates. */
  protected readonly list = computed<ListDescriptor<StockListRow>>(() => ({
    ...stockList(this.facade.options()?.establishments ?? []),
    actions: [
      {
        id: 'movements',
        label: 'inventory.stock.movements_of',
        labelParams: (row) => ({ name: row.productName }),
        icon: 'swap_vert',
        link: () => ['/stock/movements'],
        linkQuery: (row) => ({ productId: row.productId }),
      },
      // What is done to a product at a place, offered on the row that shows it there; with a movement open its form
      // is where that is done, so these wait until it is closed rather than replace what is typed in it.
      ...(
        [
          ['count', 'inventory.stock.count_here', 'checklist'],
          ['move', 'inventory.stock.move_from_here', 'swap_horiz'],
          ['loss', 'inventory.stock.loss_here', 'output'],
        ] as const
      ).map(([operation, label, icon]) => ({
        id: `${operation}_here`,
        label,
        labelParams: (row: StockListRow) => ({ name: row.productName }),
        icon,
        link: () => movementAddress(operation, '').path,
        linkQuery: (row: StockListRow) =>
          movementAddress(operation, row.productId, row.locationId).query,
        // Occasional beside the row's own movements and a lot's release, so they wait in its « ⋮ ».
        rare: true,
        shown: () => this.mayWrite() && this.operation() === null,
      })),
      {
        id: 'release',
        label: 'inventory.stock.release',
        icon: 'lock_open',
        // Final and occasional, so in the « ⋮ » with the movements above: as a button it widened every row's actions by
        // a slot, and the stock list's columns gave way on a laptop and on the widest page.
        rare: true,
        run: (row) => void this.release(row),
        disabled: () => this.busy(),
        shown: (row) => this.mayWrite() && row.expired,
        confirm: (row) => ({
          kind: 'definitif',
          title: 'inventory.stock.release_title',
          message: 'inventory.stock.release_message',
          messageParams: { lot: row.lotCode ?? '', product: row.productName },
          confirmLabel: 'inventory.stock.release_confirm',
          keepLabel: 'inventory.stock.release_keep',
        }),
      },
    ],
  }));
  protected readonly rows = computed(() =>
    stockListRows(this.facade.levels(), this.facade.locations(), this.today()),
  );
  protected readonly today = computed(() => todayIn(this.company()?.timezone));
  protected readonly total = this.facade.total;
  protected readonly busy = this.facade.busy;
  protected readonly error = this.facade.error;
  /** The line above the form: what no field took, or the error's own words. */
  protected readonly refusalLine = new RefusalLine(
    this.facade.refusals,
    'inventory.errors.',
    inject(TranslateService),
    inject(RefusalFigures),
  );
  private readonly editor = viewChild(DescriptorForm);
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly mayWrite = computed(() => this.auth.hasPermission('stock.write'));
  protected readonly rowTestId = (row: StockListRow): string =>
    `stock-${row.productReference}-${row.locationCode}`;

  protected readonly operation = signal<StockOperation | null>(null);
  /**
   * The movement the address asks for, from wherever its product or place is shown (a product's page, a row of this
   * list): `?operation=count&product=<id>&location=<id>` opens that form already naming them, which a person still
   * changes before saving. `product` and `location` are also the list's own picks, so the list beside the form shows
   * that product's stock there. Nothing is recorded by opening it.
   */
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly asked = toSignal(
    this.route.queryParamMap.pipe(
      map((params) => ({
        operation: params.get('operation'),
        product: params.get('product'),
        location: params.get('location'),
      })),
    ),
    { initialValue: { operation: null, product: null, location: null } },
  );
  /** The address last opened, so a reload of the locations or a live change never opens it again over what is typed. */
  private askedOpened: string | null = null;
  /**
   * The movement form, for the product chosen: a tracked one also asks its lot (docs/SPEC.md § 7, 2026-09-23 slice 7).
   * Choosing it rebuilds the form over what was typed, as a location arriving does.
   */
  protected readonly descriptor = computed(() => {
    const operation = this.operation();
    const tracking = this.product()?.tracking ?? 'none';
    return operation === null
      ? null
      : movementForm(
          operation,
          this.facade.locations(),
          tracking,
          this.auth.hasPermission('product.cost.read'),
          this.costMode(),
          this.split(),
          this.auth.hasModule('vendors'),
        );
  });

  /**
   * One delivery shared over several places (docs/SPEC.md row 188): the single place gives way to rows, and nothing is
   * saved until every unit is placed. A count is taken over several places the same way, each row what was found
   * there, with no total to place (audit 2026-10-06, H-b3). A serial number is one piece and has one place.
   */
  protected readonly split = signal(false);
  protected readonly parts = signal<readonly Part[]>([]);
  /** The quantity the form holds, which the rows are counted against. */
  protected readonly received = signal('');
  /** A save was refused for what was left unplaced; the next change to the rows takes the line away. */
  protected readonly unplaced = signal(false);
  /** The decimals the chosen product's unit keeps, which the rows are read to. */
  protected readonly decimals = computed(() => this.product()?.unitDecimals ?? 3);
  protected readonly splitOffered = computed(
    () =>
      (this.operation() === 'receive' || this.operation() === 'count') &&
      this.product()?.tracking !== 'serial',
  );
  protected readonly places = computed(() =>
    [...locationLabels(this.facade.locations())].map(([value, label]) => ({ value, label })),
  );
  /** Where the rest goes: the default place of the first row's establishment, else any default place. */
  protected readonly defaultPlace = computed(() => {
    const locations = this.facade.locations();
    const first = locations.find((location) => location.id === this.parts()[0]?.locationId);
    const own = locations.find(
      (location) => location.isDefault && location.establishmentId === first?.establishmentId,
    );
    return (own ?? locations.find((location) => location.isDefault))?.id ?? null;
  });

  /**
   * What a receipt of the chosen product would do to its cost, asked while the receipt is being typed: the figures a
   * person decides on, and whether the company leaves the deciding to them. Nothing without the cost permission.
   */
  private readonly costView = signal<ReceiptCostView | null>(null);
  /** What was asked for an earlier form is never shown on another: a receipt, and a person who may read costs. */
  protected readonly receiptCost = computed(() =>
    this.operation() === 'receive' && this.auth.hasPermission('product.cost.read')
      ? this.costView()
      : null,
  );
  /** Only the mode shapes the form, so only a change of it rebuilds the form over what was typed. */
  private readonly costMode = computed(() => this.receiptCost()?.mode ?? null);
  private costRequest = 0;

  /**
   * Which product the form names, as the picker answered it — the catalogue is never held here. The form owns the
   * id; this is what the box READS, which the form cannot know, and what it must read again after a rebuild.
   */
  private readonly product = signal<StockProductOption | null>(null);
  protected readonly productShown = computed(() => {
    const product = this.product();
    return product === null
      ? null
      : { id: product.id, code: product.reference, name: product.name };
  });
  /** Every product the picker has answered, so what is chosen can be found again from the id in the form. */
  private readonly known = new Map<string, StockProductOption>();
  protected readonly searchProducts = async (words: string): Promise<readonly PickOption[]> => {
    const companyId = this.company()?.id;
    if (!companyId) return [];
    const found = await this.facade.pickProducts(companyId, { words });
    for (const product of found) this.known.set(product.id, product);
    return found.map((product) => ({
      id: product.id,
      code: product.reference,
      name: product.name,
    }));
  };

  /** Which vendor the form names, as the picker answered it; the form carries the id, this is what the box reads. */
  private readonly vendor = signal<StockVendorOption | null>(null);
  protected readonly vendorShown = computed(() => {
    const vendor = this.vendor();
    return vendor === null ? null : { id: vendor.id, code: vendor.number, name: vendor.name };
  });
  /** Every vendor the picker has answered, so what the form names can be read back from its id. */
  private readonly knownVendors = new Map<string, StockVendorOption>();
  protected readonly searchVendors = async (words: string): Promise<readonly PickOption[]> => {
    const companyId = this.company()?.id;
    if (!companyId) return [];
    const found = await this.facade.pickVendors(companyId, { words });
    for (const vendor of found) this.knownVendors.set(vendor.id, vendor);
    return found.map((vendor) => ({ id: vendor.id, code: vendor.number, name: vendor.name }));
  };

  /**
   * The form of the movement being recorded. Options or locations arriving while it is open rebuild it over what was
   * typed; switching between a receipt and a count starts afresh.
   */
  protected readonly form = linkedSignal<
    { operation: StockOperation | null; descriptor: FormDescriptor | null },
    DescriptorFormGroup | null
  >({
    source: () => ({ operation: this.operation(), descriptor: this.descriptor() }),
    computation: ({ operation, descriptor }, previous) => {
      if (operation === null || descriptor === null) return null;
      const typed =
        previous?.value && previous.source.operation === operation
          ? previous.value.getRawValue()
          : null;
      const group = buildFormGroup(
        descriptor,
        typed ?? untracked(() => movementValues(this.facade.locations())),
      );
      // A serial number is one piece: the quantity is fixed at 1 as soon as such a product is the one named.
      if (untracked(() => this.product()?.tracking) === 'serial') {
        group.get('quantity')?.setValue('1');
      }
      return group;
    },
  });

  /**
   * What the open movement held when it opened, or once the last one was recorded and the next began from the product
   * and place it kept: what was picked or typed since is unsaved. Options arriving rebuild the form over what was
   * typed, so the measure is taken when the movement opens, never again on a rebuild.
   */
  private readonly started = linkedSignal<StockOperation | null, FormValues | null>({
    source: this.operation,
    computation: (operation) =>
      operation === null ? null : untracked(() => this.form()?.getRawValue() ?? null),
  });
  /** A place's share typed in a delivery shared over several places, unsaved like the form's own fields. */
  private readonly partsTyped = computed(() =>
    this.split() ? this.parts().filter((part) => part.quantity.trim() !== '').length : 0,
  );

  /** A person looked at the goods and let the lot leave: it is taken by delivery notes again. */
  protected async release(row: StockListRow): Promise<void> {
    const companyId = this.company()?.id;
    if (!companyId || row.lotId === null) return;
    if (await this.facade.releaseLot(companyId, row.lotId)) {
      this.feedback.effect('inventory.stock.released', { lot: row.lotCode ?? '' }, 'definitif');
    }
  }

  constructor() {
    unsavedChanges(this.form, this.started);
    inject(UnsavedChanges).declare(this.partsTyped);
    inject(ScanBus).handle((scan) => this.scanned(scan));
    // The address opens a movement once the places are read, which its form offers.
    effect(() => {
      const { operation: asked, product: productId, location: locationId } = this.asked();
      const companyId = this.company()?.id;
      const ready = this.facade.locations().length > 0;
      if (!isStockOperation(asked)) {
        this.askedOpened = null;
        return;
      }
      const key = `${asked}|${productId ?? ''}|${locationId ?? ''}`;
      if (!companyId || !ready || !this.mayWrite() || key === this.askedOpened) return;
      this.askedOpened = key;
      untracked(() => void this.openAsked(companyId, asked, productId, locationId));
    });
    // The picker shows what the form names: the form carries the id, and only this page knows how that id reads.
    effect((onCleanup) => {
      const form = this.form();
      if (form === null) return;
      const subscription = form.get('productId')?.valueChanges.subscribe((productId) => {
        const product = typeof productId === 'string' ? (this.known.get(productId) ?? null) : null;
        this.product.set(product);
        this.proposeHome(form, product);
        if (product?.tracking === 'serial') this.stopSplit();
      });
      onCleanup(() => subscription?.unsubscribe());
    });
    // The vendor's box shows what the form names, as the product's does.
    effect((onCleanup) => {
      const form = this.form();
      if (form === null) return;
      const subscription = form.get('vendorId')?.valueChanges.subscribe((vendorId) => {
        this.vendor.set(
          typeof vendorId === 'string' ? (this.knownVendors.get(vendorId) ?? null) : null,
        );
      });
      onCleanup(() => subscription?.unsubscribe());
    });
    // The rows are counted against the quantity as it is typed.
    effect((onCleanup) => {
      const form = this.form();
      if (form === null) return;
      const subscription = form.valueChanges.pipe(startWith(null)).subscribe(() => {
        const quantity = form.get('quantity')?.value;
        this.received.set(typeof quantity === 'string' ? quantity.trim() : '');
        const text = (id: string): string => {
          const value = form.get(id)?.value;
          return typeof value === 'string' ? value : '';
        };
        this.countAt.set({ locationId: text('locationId'), lotCode: text('lotCode') });
      });
      onCleanup(() => subscription.unsubscribe());
    });
    // A count says what it changes before it is recorded: the product chosen is read when it is chosen.
    effect(() => {
      const productId = this.operation() === 'count' ? (this.product()?.id ?? null) : null;
      untracked(() => void this.readCountContext(productId));
    });
    // The figures follow what is typed, a moment after the last key; only the latest answer is shown.
    effect((onCleanup) => {
      const form = this.form();
      if (
        form === null ||
        this.operation() !== 'receive' ||
        !this.auth.hasPermission('product.cost.read')
      ) {
        return;
      }
      const subscription = form.valueChanges
        .pipe(startWith(null), debounceTime(250))
        .subscribe(() => void this.askCost(form));
      onCleanup(() => subscription.unsubscribe());
    });
  }

  /** Where the open count is taken and of which lot, as the form holds them. */
  private readonly countAt = signal({ locationId: '', lotCode: '' });
  /** What the API says of the product being counted; null while none is chosen or it cannot be read. */
  private readonly countContext = signal<CountContext | null>(null);
  private countRequest = 0;
  protected readonly placeNames = computed<ReadonlyMap<string, string>>(() =>
    locationLabels(this.facade.locations()),
  );
  /** What recording the count on one place would change, said under the form. */
  protected readonly countNote = computed(() => {
    const context = this.countContext();
    const product = this.product();
    if (this.operation() !== 'count' || this.split() || context === null || product === null) {
      return null;
    }
    return countEffect(context, this.facade.locations(), {
      ...this.countAt(),
      counted: this.received(),
      tracking: product.tracking,
    });
  });
  /** The same for each place of a count taken over several, each with its own figures. */
  protected readonly partNotes = computed(() => {
    const context = this.countContext();
    const product = this.product();
    if (this.operation() !== 'count' || !this.split() || context === null || product === null) {
      return [];
    }
    return this.parts().map((part) => ({
      locationId: part.locationId,
      effect: countEffect(context, this.facade.locations(), {
        locationId: part.locationId,
        lotCode: this.countAt().lotCode,
        counted: part.quantity,
        tracking: product.tracking,
      }),
    }));
  });

  private async readCountContext(productId: string | null): Promise<void> {
    const companyId = this.company()?.id;
    const request = ++this.countRequest;
    if (!companyId || productId === null) {
      this.countContext.set(null);
      return;
    }
    const [context] = await this.facade.countContexts(companyId, [productId]);
    if (request === this.countRequest) this.countContext.set(context ?? null);
  }

  private async askCost(form: DescriptorFormGroup): Promise<void> {
    const companyId = this.company()?.id;
    const values = form.getRawValue();
    const text = (value: unknown): string => (typeof value === 'string' ? value.trim() : '');
    const productId = text(values['productId']);
    if (!companyId || productId === '') {
      this.costView.set(null);
      return;
    }
    const request = ++this.costRequest;
    const cost = await this.facade.receiptCost(
      companyId,
      productId,
      text(values['quantity']),
      text(values['unitCost']),
    );
    if (request === this.costRequest) this.costView.set(cost);
  }

  /**
   * Where the chosen product normally lives, offered as the location (docs/SPEC.md row 101), so a person putting
   * goods away answers that question once rather than on every receipt.
   *
   * It is a proposal and behaves like one. It fills the box only while nobody has chosen a location themselves — a
   * touched control is left alone, because a screen that overwrites what a person typed teaches them to distrust it —
   * and a product without a single home, or one whose home this company no longer has, leaves whatever is there.
   *
   * A move is left out on purpose: its location is where the goods are now, which a home does not say, and proposing
   * the home as a destination would be refused whenever the goods are already there.
   */
  private proposeHome(form: DescriptorFormGroup, product: StockProductOption | null): void {
    if (this.operation() === 'move') return;
    const home = product?.homeLocationId ?? null;
    const control = form.get('locationId');
    if (home === null || control === null || control.dirty) return;
    if (!this.facade.locations().some((location) => location.id === home)) return;
    control.setValue(home);
    control.markAsPristine();
  }

  /**
   * A scan on an open movement fills it (docs/SPEC.md § 7, 2026-09-23 slice 7): the product, and from a GS1 label its
   * lot or serial number and the day it is used by, and the pieces the code enters — counting on, as a till does,
   * while the same lot is scanned again. With no movement open the card takes the scan. The product is asked by id,
   * exactly: a search answers a window of the catalogue and could miss it. Whether stock is kept of it is then the
   * API's to say when the movement is saved.
   */
  private async scanned(scan: Scan): Promise<ScanOutcome> {
    const companyId = this.company()?.id;
    const form = this.form();
    if (!companyId || form === null) return { kind: 'unclaimed' };
    const located = locationScanned(
      scan.code,
      this.facade.locations(),
      String(form.get('locationId')?.value ?? ''),
    );
    if (located?.kind === 'ambiguous') {
      return {
        kind: 'refused',
        key: 'inventory.scan.location_ambiguous',
        params: { code: scan.code.trim() },
      };
    }
    if (located !== null) return this.located(form, located.id, scan.code);
    if (!this.auth.hasPermission('product.read')) return { kind: 'unclaimed' };
    const named = await this.productScans.named(scan.code);
    if (named === null) return { kind: 'unclaimed' };
    if (!named.isActive) {
      return { kind: 'refused', key: 'scan.retired', params: { name: named.name } };
    }
    const [product] = await this.facade.pickProducts(companyId, { ids: [named.productId] });
    if (product === undefined) return { kind: 'unclaimed' };

    const before = form.getRawValue();
    const chosenBefore = this.product();
    const lotCode = named.lot ?? named.serial ?? '';
    const pieces = Math.max(named.quantity, 1) * scan.times;
    const same = before['productId'] === product.id && (before['lotCode'] ?? '') === lotCode;
    // One serial number is one piece: the same one scanned again is not one more.
    if (same && product.tracking === 'serial' && lotCode !== '') {
      return { kind: 'refused', key: 'scan.serial_present', params: { name: product.name } };
    }
    this.known.set(product.id, product);
    this.product.set(product);
    // Read again: a tracked product's form is another form, rebuilt over what was typed.
    const filled = this.form() ?? form;
    filled.patchValue({
      productId: product.id,
      lotCode,
      lotExpiresOn: named.useBy ?? (same ? (before['lotExpiresOn'] ?? '') : ''),
      quantity: same ? addCount(String(before['quantity'] ?? ''), pieces) : String(pieces),
    });
    this.proposeHome(filled, product);
    return {
      kind: 'done',
      key: 'inventory.scan.filled',
      params: { name: product.name },
      product: { name: named.name, unitPrice: named.unitPriceGross },
      undo: () => {
        this.product.set(chosenBefore);
        this.form()?.patchValue(before);
      },
    };
  }

  /**
   * A printed location label names where the goods go on a move, and where they are everywhere else, as count mode
   * reads one (audit 2026-10-06, A-11). Marked as the person's choice, so a product's home proposed after it never
   * replaces it.
   */
  private located(form: DescriptorFormGroup, locationId: string, code: string): ScanOutcome {
    const field = this.operation() === 'move' ? 'toLocationId' : 'locationId';
    const control = form.get(field);
    if (control === null) return { kind: 'unclaimed' };
    const before = control.value;
    control.setValue(locationId);
    control.markAsDirty();
    return {
      kind: 'done',
      key: 'inventory.scan.location_set',
      params: { name: locationLabels(this.facade.locations()).get(locationId) ?? code },
      undo: () => this.form()?.get(field)?.setValue(before),
    };
  }

  /** Where the list's product and location filters search: the API for products, the loaded locations for places. */
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
    return [...locationLabels(this.facade.locations())].map(([id, label]) => ({
      id,
      code: '',
      name: label,
    }));
  }

  /** What the list last asked the API for; the page is not read until the list has said what it wants. */
  private search: StockSearch | null = null;
  private readonly searched = signal<StockSearch | null>(null);
  /** What the list shows now, as a file: null until the list has asked for its first page. */
  protected readonly exporter = computed(() => {
    const companyId = this.company()?.id;
    const search = this.searched();
    return companyId && search !== null
      ? (format: ExportFormat) => this.facade.exportLevelsUrl(companyId, search, format)
      : null;
  });

  async ngOnInit(): Promise<void> {
    const companyId = this.company()?.id;
    if (companyId) {
      this.live.reloadOn(
        ['stock', 'delivery_note', 'invoice', 'product', 'stock_location'],
        () => this.reload(companyId),
        this.destroyRef,
      );
      await this.facade.loadStockContext(companyId);
    }
  }

  protected onQuery(query: ListQuery): void {
    const companyId = this.company()?.id;
    if (!companyId) return;
    this.search = stockSearch(query);
    this.searched.set(this.search);
    void this.facade.loadStock(companyId, this.search);
  }

  private async reload(companyId: string): Promise<void> {
    await Promise.all([
      this.facade.loadStockContext(companyId),
      this.search === null ? Promise.resolve() : this.facade.loadStock(companyId, this.search),
      this.readCountContext(this.operation() === 'count' ? (this.product()?.id ?? null) : null),
    ]);
  }

  protected open(operation: StockOperation): void {
    this.facade.clearError();
    this.product.set(null);
    this.split.set(false);
    this.parts.set([]);
    this.unplaced.set(false);
    this.operation.set(operation);
  }

  /** Shares the delivery over rows, the first on the place the form named; or goes back to the one place. */
  protected toggleSplit(): void {
    if (!this.split()) {
      const named = this.form()?.get('locationId')?.value;
      const first = typeof named === 'string' && named !== '' ? named : (this.defaultPlace() ?? '');
      this.parts.set(
        addPlace(
          [{ locationId: first, quantity: '' }],
          this.places().map((place) => ({ id: place.value })),
        ),
      );
      this.unplaced.set(false);
      this.split.set(true);
    } else {
      this.stopSplit();
    }
  }

  private stopSplit(): void {
    if (!this.split()) return;
    const first = this.parts()[0]?.locationId ?? '';
    this.split.set(false);
    this.parts.set([]);
    this.unplaced.set(false);
    // The form is rebuilt with its place again, over what was typed; the place is the first row's.
    this.form()?.patchValue({ locationId: first });
  }

  protected setParts(parts: readonly Part[]): void {
    this.parts.set(parts);
    this.unplaced.set(false);
  }

  protected cancel(): void {
    this.operation.set(null);
    this.facade.clearError();
    // Closed, the movement the address asked for is no longer asked: a reload does not open it again.
    if (this.asked().operation !== null) {
      void this.router.navigate([], {
        relativeTo: this.route,
        queryParams: { operation: null },
        queryParamsHandling: 'merge',
        replaceUrl: true,
      });
    }
  }

  /**
   * Opens the movement the address names, on the product and the place it names: the place first and marked as the
   * person's, so the product's home proposed after it does not replace it. What it names is the form's starting
   * point, not something typed: leaving it untouched asks nothing.
   */
  private async openAsked(
    companyId: string,
    operation: StockOperation,
    productId: string | null,
    locationId: string | null,
  ): Promise<void> {
    if (this.operation() !== operation) this.open(operation);
    const form = this.form();
    if (form === null) return;
    if (locationId !== null && this.facade.locations().some((place) => place.id === locationId)) {
      const control = form.get('locationId');
      control?.setValue(locationId);
      control?.markAsDirty();
    }
    if (productId !== null) {
      const [product] = await this.facade.pickProducts(companyId, { ids: [productId] });
      if (product === undefined || this.operation() !== operation) return;
      this.known.set(product.id, product);
      (this.form() ?? form).get('productId')?.setValue(product.id);
    }
    this.started.set(this.form()?.getRawValue() ?? null);
  }

  protected async save(values: FormValues): Promise<void> {
    const companyId = this.company()?.id;
    const operation = this.operation();
    if (!companyId || operation === null || this.busy()) return;
    if (operation === 'receive' && this.split()) {
      await this.saveSplit(companyId, values);
      return;
    }
    if (operation === 'count' && this.split()) {
      await this.saveCount(companyId, values);
      return;
    }
    if (await this.facade.record(companyId, movementInput(operation, values))) {
      // The form stays open for the next one — a serial number after a serial number, a lot after a lot — on the same
      // product and where it goes; what names this movement alone is emptied. « Annuler » is how it is closed.
      const form = this.form();
      if (form !== null) this.startNext(form);
      this.feedback.success('inventory.stock.recorded');
    } else {
      this.sayRefused();
    }
  }

  /** Each field the API refused said on that field; the line above the form says what none of them took. */
  private sayRefused(): void {
    const refusals = this.facade.refusals();
    const unplaced = this.editor()?.showRefused(refusals) ?? refusals;
    this.refusalLine.placed(refusals, unplaced);
  }

  /** Nothing goes to the API until every unit has a place: the delivery is stored whole, so it is asked whole. */
  private async saveSplit(companyId: string, values: FormValues): Promise<void> {
    const stand = placement(
      this.received(),
      this.parts().map((part) => part.quantity),
      this.decimals(),
    );
    if (stand.state !== 'done') {
      this.unplaced.set(true);
      return;
    }
    if (
      await this.facade.receiveSplit(companyId, receiptInput(values, this.parts(), this.decimals()))
    ) {
      const form = this.form();
      if (form !== null) this.startNext(form);
      this.parts.update((parts) => parts.map((part) => ({ ...part, quantity: '' })));
      this.feedback.success('inventory.stock.recorded');
    } else {
      this.sayRefused();
    }
  }

  /** Every place is counted before anything is sent: a place left blank is not a place where nothing was found. */
  private async saveCount(companyId: string, values: FormValues): Promise<void> {
    const parts = toCountParts(this.parts(), this.decimals());
    if (parts === null) {
      this.unplaced.set(true);
      return;
    }
    if (await this.facade.countSplit(companyId, countInput(values, parts))) {
      const form = this.form();
      if (form !== null) this.startNext(form);
      this.parts.update((rows) => rows.map((part) => ({ ...part, quantity: '' })));
      this.feedback.success('inventory.stock.recorded');
    } else {
      this.sayRefused();
    }
  }

  private startNext(form: DescriptorFormGroup): void {
    const kept = ['productId', 'locationId', 'toLocationId'];
    const serial = this.product()?.tracking === 'serial';
    for (const [id, control] of Object.entries(form.controls)) {
      if (!kept.includes(id)) control.reset(id === 'quantity' && serial ? '1' : '');
    }
    this.started.set(form.getRawValue());
  }
}
