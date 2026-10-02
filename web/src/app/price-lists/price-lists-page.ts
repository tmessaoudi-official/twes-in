// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  type OnInit,
  signal,
} from '@angular/core';
import { FormArray, FormControl, FormGroup, ReactiveFormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSlideToggleModule } from '@angular/material/slide-toggle';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { DecimalInput } from '../shared/form/decimal-input';
import { PickField, type PickOption } from '../shared/form/pick-field';
import { FormatFacade } from '../shared/i18n/format-facade';
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

/**
 * Price lists: each for everyone, for the customers of a group or for one customer, between two days or without end,
 * holding the net price of a product from a quantity up. An edit sends the whole list, its prices included, which
 * become exactly the rows shown.
 */
@Component({
  selector: 'app-price-lists-page',
  imports: [
    ReactiveFormsModule,
    MatButtonModule,
    MatCardModule,
    MatFormFieldModule,
    MatInputModule,
    MatSlideToggleModule,
    TranslatePipe,
    DataList,
    DataListCell,
    DecimalInput,
    PickField,
    StatusBadge,
  ],
  templateUrl: './price-lists-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class PriceListsPage implements OnInit {
  private readonly facade = inject(PriceListsFacade);
  private readonly auth = inject(AuthFacade);
  private readonly feedback = inject(Feedback);
  private readonly format = inject(FormatFacade);

  protected readonly scopes = SCOPES;
  protected readonly lists = this.facade.lists;
  protected readonly groups = this.facade.groups;
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
  protected readonly form = new FormGroup({
    name: new FormControl('', { nonNullable: true }),
    customerGroupId: new FormControl('', { nonNullable: true }),
    validFrom: new FormControl('', { nonNullable: true }),
    validTo: new FormControl('', { nonNullable: true }),
    isActive: new FormControl(true, { nonNullable: true }),
    items: new FormArray<ItemGroup>([]),
  });

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
    if (companyId) await this.facade.load(companyId);
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
    this.form.controls.items.clear();
    for (const item of row?.items ?? []) this.form.controls.items.push(itemGroup(item));
    if (companyId && row?.items) {
      void this.facade.loadFigures(
        companyId,
        row.items.map((item) => item.productId),
      );
    }
    this.scope.set(row === null ? 'everyone' : scopeOf(row));
    this.customer.set(null);
    if (row?.customerId && companyId) {
      const [found] = await this.facade.pickCustomerIds(companyId, [row.customerId]);
      this.customer.set(found ?? { id: row.customerId, code: '', name: '' });
    }
    this.editing.set(target === 'new' ? 'new' : row);
  }

  protected cancel(): void {
    this.editing.set(null);
    this.facade.clearError();
  }

  protected chooseScope(event: Event): void {
    const value = (event.target as HTMLSelectElement).value;
    this.scope.set(SCOPES.find((known) => known === value) ?? 'everyone');
  }

  protected addRow(): void {
    this.form.controls.items.push(itemGroup(null));
  }

  protected removeRow(index: number): void {
    this.form.controls.items.removeAt(index);
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
    const value = this.form.getRawValue();
    const scope = this.scope();
    const input: PriceListInput = {
      name: value.name.trim(),
      customerGroupId:
        scope === 'group' && value.customerGroupId !== '' ? value.customerGroupId : null,
      customerId: scope === 'customer' ? (this.customer()?.id ?? null) : null,
      validFrom: value.validFrom === '' ? null : value.validFrom,
      validTo: value.validTo === '' ? null : value.validTo,
      isActive: value.isActive,
      items: value.items.map((item) => ({ ...item })),
    };
    const accepted =
      editing === 'new'
        ? await this.facade.create(companyId, input)
        : await this.facade.revise(companyId, editing.id, input);
    if (accepted) {
      this.editing.set(null);
      this.feedback.success('price_lists.saved');
    }
  }

  protected async remove(row: PriceListRow): Promise<void> {
    const companyId = this.company()?.id;
    if (!companyId || this.busy()) return;
    if (await this.facade.remove(companyId, row.id)) this.feedback.success('price_lists.deleted');
  }
}
