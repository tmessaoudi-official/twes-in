// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  inject,
  type OnInit,
  signal,
} from '@angular/core';
import {
  FormArray,
  FormControl,
  FormGroup,
  FormsModule,
  ReactiveFormsModule,
} from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSlideToggleModule } from '@angular/material/slide-toggle';
import { NgTemplateOutlet } from '@angular/common';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import type { DescriptorFormGroup } from '../shared/form/form-builder';
import type { FieldValue } from '../shared/form/form-types';
import type { FieldConflict } from '../shared/form/form-merge';
import { liveRecord } from '../shared/form/live-record';
import { PartConflict } from '../shared/form/part-conflict';
import { RecordChanged } from '../shared/form/record-changed';
import { Select, type SelectOption } from '../shared/form/select';
import { DecimalInput } from '../shared/form/decimal-input';
import { DayCalendarButton } from '../shared/form/day-calendar-button';
import { DayInput } from '../shared/form/day-input';
import { PickField, type PickOption } from '../shared/form/pick-field';
import { FormatFacade } from '../shared/i18n/format-facade';
import { LiveChanges } from '../shared/realtime/live-changes';
import { Feedback } from '../shared/feedback/feedback';
import { DataList, DataListCell } from '../shared/list/data-list';
import type { ListDescriptor } from '../shared/list/list-types';
import { StatusBadge } from '../shared/ui/status-badge';
import { PRICE_LISTS_LIST } from './price-lists-forms';
import { rowFigures, type RowFigures } from './price-lists-figures';
import { PriceListsFacade } from './price-lists-facade';
import {
  scopeOf,
  type PriceListInput,
  type PriceListItem,
  type PriceListRow,
  type PriceListScope,
} from './price-lists-types';

const SCOPES: readonly PriceListScope[] = ['everyone', 'group', 'customer'];

type ItemGroup = FormGroup<{
  productId: FormControl<string>;
  productReference: FormControl<string>;
  productName: FormControl<string>;
  minQuantity: FormControl<string>;
  unitPriceNet: FormControl<string>;
}>;

function itemGroup(item: PriceListItem | null): ItemGroup {
  return new FormGroup({
    productId: new FormControl(item?.productId ?? '', { nonNullable: true }),
    productReference: new FormControl(item?.productReference ?? '', { nonNullable: true }),
    productName: new FormControl(item?.productName ?? '', { nonNullable: true }),
    minQuantity: new FormControl(item?.minQuantity ?? '1', { nonNullable: true }),
    unitPriceNet: new FormControl(item?.unitPriceNet ?? '', { nonNullable: true }),
  });
}

/** The prices as one comparable text: equal texts are the same prices, in the same order. */
function pricesKey(
  items: readonly Pick<PriceListItem, 'productId' | 'minQuantity' | 'unitPriceNet'>[],
): string {
  return JSON.stringify(items.map((item) => [item.productId, item.minQuantity, item.unitPriceNet]));
}

/**
 * Price lists: each for everyone, for the customers of a group or for one customer, between two days or without end,
 * holding the net price of a product from a quantity up. An edit sends the whole list, its prices included, which
 * become exactly the rows shown. Another person's save of the open list merges into it: each field with a control on
 * its own, whom it is for and its prices each as one part.
 */
@Component({
  selector: 'app-price-lists-page',
  imports: [
    FormsModule,
    ReactiveFormsModule,
    Select,
    MatButtonModule,
    MatCardModule,
    MatFormFieldModule,
    MatInputModule,
    MatSlideToggleModule,
    TranslatePipe,
    DataList,
    DataListCell,
    DecimalInput,
    DayInput,
    DayCalendarButton,
    PickField,
    StatusBadge,
    RecordChanged,
    PartConflict,
    NgTemplateOutlet,
  ],
  templateUrl: './price-lists-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class PriceListsPage implements OnInit {
  private readonly facade = inject(PriceListsFacade);
  private readonly auth = inject(AuthFacade);
  private readonly feedback = inject(Feedback);
  private readonly format = inject(FormatFacade);
  private readonly live = inject(LiveChanges);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly scopes = SCOPES;
  protected readonly scopeOptions: SelectOption[] = SCOPES.map((known) => ({
    value: known,
    label: `price_lists.scopes.${known}`,
    testId: `price-list-scope-option-${known}`,
  }));
  protected readonly lists = this.facade.lists;
  protected readonly groups = this.facade.groups;
  /** « — » is no group: the list then applies to whoever the scope names without one. */
  protected readonly groupOptions = computed<SelectOption[]>(() => [
    { value: '', label: '—', testId: 'price-list-group-option-none' },
    ...this.groups().map((group) => ({
      value: group.id,
      label: group.name,
      testId: `price-list-group-option-${group.id}`,
    })),
  ]);
  protected readonly busy = this.facade.busy;
  protected readonly error = this.facade.error;
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly mayWrite = computed(() => this.auth.hasPermission('product.write'));
  protected readonly rowTestId = (row: PriceListRow): string => `price-list-${row.name}`;
  protected readonly scopeOf = scopeOf;

  /** The list being edited; `'new'` for one not saved yet. */
  protected readonly editing = signal<PriceListRow | 'new' | null>(null);
  protected readonly scope = signal<PriceListScope>('everyone');
  protected readonly customer = signal<PickOption | null>(null);
  /** The list's own fields, typed as the page reads them. */
  private readonly fields = {
    name: new FormControl('', { nonNullable: true }),
    customerGroupId: new FormControl('', { nonNullable: true }),
    validFrom: new FormControl('', { nonNullable: true }),
    validTo: new FormControl('', { nonNullable: true }),
    isActive: new FormControl(true, { nonNullable: true }),
  };
  /** The same fields as a form the merge reads, each a control holding a value. */
  protected readonly form: DescriptorFormGroup = new FormGroup<
    Record<string, FormControl<FieldValue>>
  >(this.fields);
  /** The prices, a group of their own beside the form: they merge as one part, never field by field. */
  protected readonly prices = new FormGroup({ items: new FormArray<ItemGroup>([]) });
  /** The open list as it is saved, its prices included, read again when someone saves it. */
  private readonly saved = signal<PriceListRow | null>(null);

  /**
   * The saved version the open list stands on, and what another person's save changed in it. The group is left out of
   * the fields merged one by one: it is part of whom the list is for, and taken alone it could land behind another
   * scope, where nobody sees it and a save drops it.
   */
  protected readonly sync = liveRecord({
    kind: 'price_list',
    id: () => this.openId(),
    form: () => (this.openId() === null ? null : this.form),
    reload: () => this.readOpen(),
    saved: () => {
      const row = this.saved();
      return row === null
        ? null
        : {
            name: row.name,
            validFrom: row.validFrom ?? '',
            validTo: row.validTo ?? '',
            isActive: row.isActive,
          };
    },
    version: () => this.saved()?.version ?? null,
    parts: [
      {
        field: 'scope',
        saved: () => {
          const row = this.saved();
          return row === null
            ? null
            : `${scopeOf(row)}|${row.customerGroupId ?? ''}|${row.customerId ?? ''}`;
        },
        shown: () => {
          const scope = this.scope();
          const group = scope === 'group' ? this.fields.customerGroupId.value : '';
          return `${scope}|${group}|${scope === 'customer' ? (this.customer()?.id ?? '') : ''}`;
        },
        take: () => {
          const row = this.saved();
          if (row === null) return;
          this.showScope(row);
          void this.nameCustomer(row);
        },
      },
      {
        field: 'items',
        saved: () => {
          const items = this.saved()?.items;
          return items == null ? null : pricesKey(items);
        },
        shown: () => pricesKey(this.prices.controls.items.getRawValue()),
        take: () => {
          const row = this.saved();
          if (row !== null) this.showPrices(row);
        },
      },
    ],
  });
  protected readonly isScopeConflict = (conflict: FieldConflict): boolean =>
    conflict.field === 'scope';
  protected readonly isPricesConflict = (conflict: FieldConflict): boolean =>
    conflict.field === 'items';

  protected readonly list = computed<ListDescriptor<PriceListRow>>(() => ({
    ...PRICE_LISTS_LIST,
    actions: [
      {
        id: 'edit',
        label: 'price_lists.edit',
        icon: 'edit',
        run: (row) => void this.open(row),
        disabled: () => this.busy(),
        shown: () => this.mayWrite(),
      },
      {
        id: 'delete',
        label: 'price_lists.delete_named',
        labelParams: (row) => ({ name: row.name }),
        icon: 'delete',
        destructive: true,
        run: (row) => void this.remove(row),
        disabled: () => this.busy(),
        shown: () => this.mayWrite(),
        confirm: (row) => ({
          kind: 'definitif',
          title: 'price_lists.delete_title',
          message: 'price_lists.delete_message',
          messageParams: { name: row.name },
          confirmLabel: 'price_lists.delete_confirm',
          keepLabel: 'price_lists.keep',
        }),
      },
    ],
  }));

  async ngOnInit(): Promise<void> {
    const companyId = this.company()?.id;
    if (!companyId) return;
    this.live.reloadOn(['price_list'], () => this.facade.load(companyId), this.destroyRef);
    await this.facade.load(companyId);
  }

  protected groupName(row: PriceListRow): string {
    return this.groups().find((group) => group.id === row.customerGroupId)?.name ?? '';
  }

  /** A new list, or one opened with the prices it holds, which the collection does not carry. */
  protected async open(target: PriceListRow | 'new'): Promise<void> {
    this.facade.clearError();
    const companyId = this.company()?.id;
    let row: PriceListRow | null = null;
    if (target !== 'new') {
      row = companyId ? await this.facade.open(companyId, target.id) : null;
      if (row === null) return;
    }
    this.form.reset({
      name: row?.name ?? '',
      customerGroupId: row?.customerGroupId ?? '',
      validFrom: row?.validFrom ?? '',
      validTo: row?.validTo ?? '',
      isActive: row?.isActive ?? true,
    });
    this.prices.controls.items.clear();
    if (row !== null) this.showPrices(row);
    this.scope.set('everyone');
    this.customer.set(null);
    if (row !== null) this.showScope(row);
    if (row?.customerId) await this.nameCustomer(row);
    // Before the list counts as open: the form it is tracked from stands on this version.
    this.saved.set(row);
    this.editing.set(target === 'new' ? 'new' : row);
  }

  /** The id of the saved list open in the editor; null while none is, or a new one is written. */
  private openId(): string | null {
    const editing = this.editing();
    return editing === null || editing === 'new' ? null : editing.id;
  }

  /** Reads the open list again, its prices included, which the collection does not carry. */
  private async readOpen(): Promise<void> {
    const companyId = this.company()?.id;
    const id = this.openId();
    if (!companyId || id === null) return;
    const row = await this.facade.open(companyId, id);
    if (row !== null && this.openId() === id) this.saved.set(row);
  }

  /** Whom the list is for, as saved: the scope, its group and its customer, named by `nameCustomer`. */
  private showScope(row: PriceListRow): void {
    this.scope.set(scopeOf(row));
    this.fields.customerGroupId.setValue(row.customerGroupId ?? '');
    this.customer.set(row.customerId ? { id: row.customerId, code: '', name: '' } : null);
  }

  /** The saved list's customer named as the picker names one, unless another was picked meanwhile. */
  private async nameCustomer(row: PriceListRow): Promise<void> {
    const companyId = this.company()?.id;
    if (!row.customerId || !companyId) return;
    const [found] = await this.facade.pickCustomerIds(companyId, [row.customerId]);
    if (found !== undefined && this.customer()?.id === row.customerId) this.customer.set(found);
  }

  /** The list's prices as saved, each row with its product's figures. */
  private showPrices(row: PriceListRow): void {
    const items = this.prices.controls.items;
    items.clear();
    for (const item of row.items ?? []) items.push(itemGroup(item));
    const companyId = this.company()?.id;
    if (companyId && row.items) {
      void this.facade.loadFigures(
        companyId,
        row.items.map((item) => item.productId),
      );
    }
  }

  /** Drops what was typed and shows the open list as it is saved. */
  protected reloadSaved(): void {
    this.sync.reloadSaved(this.form);
  }

  /** The choice made on what both sides changed: the saved version, or what is typed here. */
  protected resolve(choice: { field: string; choice: 'theirs' | 'mine' }): void {
    this.sync.resolve(this.form, choice);
  }

  /** The field both sides changed, when the field shown is one of them. */
  protected conflictOf(field: string): FieldConflict | null {
    return this.sync.conflicts().find((conflict) => conflict.field === field) ?? null;
  }

  /** What the saved version holds for a field both sides changed, as the field shows it. */
  protected theirsShown(conflict: FieldConflict): string {
    if (typeof conflict.theirs === 'boolean') {
      return conflict.theirs ? 'price_lists.active' : 'price_lists.inactive';
    }
    const value = String(conflict.theirs ?? '');
    if (value === '') return '—';
    return conflict.field === 'name' ? value : this.format.day(value);
  }

  protected cancel(): void {
    this.editing.set(null);
    this.saved.set(null);
    this.facade.clearError();
  }

  protected chooseScope(value: string): void {
    this.scope.set(SCOPES.find((known) => known === value) ?? 'everyone');
  }

  protected addRow(): void {
    this.prices.controls.items.push(itemGroup(null));
  }

  protected removeRow(index: number): void {
    this.prices.controls.items.removeAt(index);
  }

  protected readonly searchProducts = async (words: string): Promise<readonly PickOption[]> =>
    this.facade.pickProducts(this.company()?.id ?? '', words);

  protected readonly searchCustomers = async (words: string): Promise<readonly PickOption[]> =>
    this.facade.pickCustomers(this.company()?.id ?? '', words);

  protected productOf(row: ItemGroup): PickOption | null {
    const { productId, productReference, productName } = row.getRawValue();
    return productId === '' ? null : { id: productId, code: productReference, name: productName };
  }

  /**
   * Picking a product starts its price at the product's own, to be edited: a new price is made from the old one, never
   * typed from memory. A price already typed on the row is kept.
   */
  protected chooseProduct(row: ItemGroup, option: PickOption | null): void {
    row.patchValue({
      productId: option?.id ?? '',
      productReference: option?.code ?? '',
      productName: option?.name ?? '',
    });
    const shelf = option === null ? undefined : this.facade.figures().get(option.id);
    if (shelf !== undefined && row.controls.unitPriceNet.value.trim() === '') {
      row.controls.unitPriceNet.setValue(shelf.unitPriceNet);
    }
    row.markAsDirty();
  }

  /** What the row's price means beside its product's: the shelf price, the change, the margin, a sale at a loss. */
  protected figuresOf(row: ItemGroup): RowFigures | null {
    const { productId, unitPriceNet } = row.getRawValue();
    return rowFigures(unitPriceNet, this.facade.figures().get(productId) ?? null);
  }

  /** The sentence under a row's price: the translation key and its words, with the cost only for who may read it. */
  protected lineOf(figures: RowFigures): { key: string; params: Record<string, string> } {
    const percent = (value: number | null): string =>
      value === null ? '—' : `${this.format.decimal((Math.round(value * 10) / 10).toFixed(1))} %`;
    const params = {
      shelf: this.format.amount(figures.shelf, null),
      change: percent(figures.changePercent),
      cost: figures.cost === null ? '' : this.format.amount(figures.cost, null),
      margin: percent(figures.marginPercent),
    };
    return {
      key: figures.cost === null ? 'price_lists.prices.figures' : 'price_lists.prices.figures_cost',
      params,
    };
  }

  protected async save(): Promise<void> {
    const companyId = this.company()?.id;
    const editing = this.editing();
    if (!companyId || editing === null || this.busy()) return;
    if (this.form.invalid || this.prices.invalid) {
      this.form.markAllAsTouched();
      this.prices.markAllAsTouched();
      return;
    }
    const value = {
      name: this.fields.name.value,
      customerGroupId: this.fields.customerGroupId.value,
      validFrom: this.fields.validFrom.value,
      validTo: this.fields.validTo.value,
      isActive: this.fields.isActive.value,
    };
    const scope = this.scope();
    const input: PriceListInput = {
      name: value.name.trim(),
      customerGroupId:
        scope === 'group' && value.customerGroupId !== '' ? value.customerGroupId : null,
      customerId: scope === 'customer' ? (this.customer()?.id ?? null) : null,
      validFrom: value.validFrom === '' ? null : value.validFrom,
      validTo: value.validTo === '' ? null : value.validTo,
      isActive: value.isActive,
      items: this.prices.controls.items.getRawValue().map((item) => ({ ...item })),
    };
    if (editing === 'new') {
      if (await this.facade.create(companyId, input)) this.closeSaved();
      return;
    }
    const outcome = await this.facade.revise(
      companyId,
      editing.id,
      input,
      this.sync.version() ?? editing.version,
    );
    if (outcome === 'saved') this.closeSaved();
    else if (outcome === 'changed') await this.sync.savedElsewhere();
  }

  /** The list was saved: the editor closes and says so. */
  private closeSaved(): void {
    this.editing.set(null);
    this.saved.set(null);
    this.feedback.success('price_lists.saved');
  }

  protected async remove(row: PriceListRow): Promise<void> {
    const companyId = this.company()?.id;
    if (!companyId || this.busy()) return;
    if (await this.facade.remove(companyId, row.id)) this.feedback.success('price_lists.deleted');
  }
}
