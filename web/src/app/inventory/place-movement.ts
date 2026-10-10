// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
  linkedSignal,
  output,
  signal,
  untracked,
} from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { Feedback } from '../shared/feedback/feedback';
import { DescriptorForm } from '../shared/form/descriptor-form';
import { buildFormGroup, type DescriptorFormGroup } from '../shared/form/form-builder';
import type { FormValues } from '../shared/form/form-types';
import type { PickOption } from '../shared/form/pick-field';
import { InventoryFacade } from './inventory-facade';
import { movementForm, movementInput, movementValues } from './inventory-forms';
import type { StockLevelRow, StockMovementInput, StockProductOption } from './inventory-types';

/** What a line of « Ce qu'il y a ici » was asked to do: move it, count it where it is, or receive more of it there. */
export interface MovementAsked {
  readonly operation: 'move' | 'count' | 'receive';
  readonly line: StockLevelRow;
}

/**
 * A quantity as its unit counts it, read from the API's decimal string without a float: « 173.000 » of a unit counted
 * whole is « 173 ». Nothing is offered from a line holding none, or less than none: moving it is not a proposal.
 */
export function quantityOf(quantity: string, decimals: number): string {
  if (!/^\d+(\.\d+)?$/.test(quantity) || /^0*(\.0*)?$/.test(quantity)) return '';
  const [whole = '0', fraction = ''] = quantity.split('.');
  return decimals === 0 ? whole : `${whole}.${fraction.padEnd(decimals, '0').slice(0, decimals)}`;
}

/**
 * A movement of one article recorded from the stock map's panel, with the Stock page's own form: the line says what,
 * from where, and for a move how much; the plan says where to (docs/SPEC.md § 7, 2026-10-09 10:31 and 23:19). A move
 * done here is undone by a counter-movement, offered on its toast; nothing is erased.
 */
@Component({
  selector: 'app-place-movement',
  imports: [MatButtonModule, MatIconModule, TranslatePipe, DescriptorForm],
  templateUrl: './place-movement.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class PlaceMovement {
  private readonly facade = inject(InventoryFacade);
  private readonly auth = inject(AuthFacade);
  private readonly feedback = inject(Feedback);

  readonly companyId = input.required<string>();
  readonly asked = input.required<MovementAsked>();
  /** The place touched on the plan as where the goods go; the form's own box names it too. */
  readonly destination = input<string | null>(null);
  /** Where the goods are going as the form now says, for the plan to outline. */
  readonly heading = output<string | null>();
  /** Saved or let go: the panel goes back to what the place holds. */
  readonly closed = output<void>();

  protected readonly busy = this.facade.busy;
  protected readonly error = this.facade.error;

  /** The line's product as the picker answers it, by id: a tracked one's form asks its lot. */
  private readonly product = signal<StockProductOption | null>(null);
  private readonly known = new Map<string, StockProductOption>();
  protected readonly productShown = computed(() => {
    const product = this.product();
    return product === null
      ? null
      : { id: product.id, code: product.reference, name: product.name };
  });
  protected readonly searchProducts = async (words: string): Promise<readonly PickOption[]> => {
    const found = await this.facade.pickProducts(this.companyId(), { words });
    for (const product of found) this.known.set(product.id, product);
    return found.map((product) => ({
      id: product.id,
      code: product.reference,
      name: product.name,
    }));
  };

  protected readonly descriptor = computed(() => {
    const product = this.product();
    const operation = this.asked().operation;
    return product === null
      ? null
      : movementForm(
          operation,
          this.facade.locations(),
          product.tracking,
          operation === 'receive' && this.auth.hasPermission('product.cost.read'),
        );
  });

  /** Built once per article asked; locations arriving later rebuild it over what was typed. */
  protected readonly form = linkedSignal<
    { asked: MovementAsked; descriptor: ReturnType<typeof movementForm> | null },
    DescriptorFormGroup | null
  >({
    source: () => ({ asked: this.asked(), descriptor: this.descriptor() }),
    computation: ({ asked, descriptor }, previous) => {
      if (descriptor === null) return null;
      const typed =
        previous?.value && previous.source.asked === asked ? previous.value.getRawValue() : null;
      return buildFormGroup(descriptor, typed ?? untracked(() => this.startingValues(asked)));
    },
  });

  constructor() {
    effect(() => {
      const companyId = this.companyId();
      const productId = this.asked().line.productId;
      untracked(() => void this.readProduct(companyId, productId));
    });
    // A place touched on the plan is the destination, as if chosen in the box.
    effect(() => {
      const destination = this.destination();
      const control = this.form()?.get('toLocationId');
      if (destination !== null && control !== null && control !== undefined) {
        untracked(() => {
          control.setValue(destination);
          control.markAsDirty();
        });
      }
    });
    effect((onCleanup) => {
      const control = this.form()?.get('toLocationId');
      if (control === null || control === undefined) return;
      const subscription = control.valueChanges.subscribe((value) =>
        this.heading.emit(typeof value === 'string' && value !== '' ? value : null),
      );
      onCleanup(() => subscription.unsubscribe());
    });
  }

  private async readProduct(companyId: string, productId: string): Promise<void> {
    this.facade.clearError();
    const [product] = await this.facade.pickProducts(companyId, { ids: [productId] });
    if (product === undefined || this.asked().line.productId !== productId) return;
    this.known.set(product.id, product);
    this.product.set(product);
  }

  private startingValues({ operation, line }: MovementAsked): FormValues {
    return {
      ...movementValues(this.facade.locations()),
      productId: line.productId,
      locationId: line.locationId,
      toLocationId: this.destination() ?? '',
      lotCode: line.lotCode ?? '',
      quantity: operation === 'move' ? quantityOf(line.quantity, line.unitDecimals) : '',
    };
  }

  protected back(): void {
    this.facade.clearError();
    this.closed.emit();
  }

  protected async save(values: FormValues): Promise<void> {
    const companyId = this.companyId();
    const { operation, line } = this.asked();
    if (this.busy()) return;
    const movement = movementInput(operation, values);
    if (!(await this.facade.record(companyId, movement))) return;
    await this.facade.reloadContents(companyId);
    if (operation === 'move') {
      this.feedback.success(
        'inventory.plan.move.moved',
        {
          quantity: movement.quantity,
          product: line.productName,
          from: this.codeOf(movement.locationId),
          to: this.codeOf(movement.toLocationId ?? ''),
        },
        { key: 'inventory.plan.move.undo', run: () => void this.putBack(companyId, movement) },
      );
    } else {
      this.feedback.success('inventory.stock.recorded');
    }
    this.closed.emit();
  }

  /** The counter-movement: the same goods, the same lot, back where they were. */
  private async putBack(companyId: string, moved: StockMovementInput): Promise<void> {
    const back: StockMovementInput = {
      ...moved,
      locationId: moved.toLocationId ?? '',
      toLocationId: moved.locationId,
    };
    if (await this.facade.record(companyId, back)) {
      await this.facade.reloadContents(companyId);
      this.feedback.success('inventory.plan.move.undone', {
        product: this.asked().line.productName,
        from: this.codeOf(moved.locationId),
      });
    } else {
      this.feedback.failure('inventory.plan.move.undo_failed', {
        product: this.asked().line.productName,
      });
    }
  }

  private codeOf(locationId: string): string {
    return this.facade.locations().find((location) => location.id === locationId)?.code ?? '';
  }
}
