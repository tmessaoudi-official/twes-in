// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  afterNextRender,
  ChangeDetectionStrategy,
  Component,
  computed,
  ElementRef,
  inject,
  Injector,
  type OnInit,
  signal,
} from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogRef } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import { Router } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { isBareKeystroke } from '../shared/actions/shortcuts';
import { PickField, type PickOption } from '../shared/form/pick-field';
import { FormatFacade } from '../shared/i18n/format-facade';
import { ProductsApi, ProductsRefused } from './products-api';
import type { ProductScan, ProductsError } from './products-types';

export interface ProductScanCardData {
  /** What the scanner read, as it came. */
  readonly code: string;
}

interface ScanAction {
  readonly id:
    'sheet' | 'codes' | 'movements' | 'invoice' | 'delivery_note' | 'create' | 'attach' | 'search';
  /** The one key that runs it; Enter always runs the first. */
  readonly key: string;
  readonly icon: string;
  /** Where it goes; none for what happens in the card itself. */
  readonly url: string | null;
}

/**
 * What a scan on a page with no field focused opens (docs/SPEC.md § 7, 2026-09-23 01:10): the product the code names,
 * what the code counts and what a GS1 scan carried, and one key to each place a person holding it goes next — its
 * sheet, its codes, its movements, or a new invoice or delivery note that starts with it (docs/SPEC.md § 7, 2026-09-23
 * 09:45, slice 2). A code nobody holds offers first to create the product it names, then to add the code to a product
 * that exists, then the catalogue searched for it.
 */
@Component({
  selector: 'app-product-scan-card',
  imports: [MatButtonModule, MatIconModule, TranslatePipe, PickField],
  templateUrl: './product-scan-card.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { '(keydown)': 'onKeydown($event)' },
})
export class ProductScanCard implements OnInit {
  private readonly data = inject<ProductScanCardData>(MAT_DIALOG_DATA);
  private readonly dialog = inject(MatDialogRef<ProductScanCard>);
  private readonly api = inject(ProductsApi);
  private readonly auth = inject(AuthFacade);
  private readonly router = inject(Router);
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);
  private readonly injector = inject(Injector);
  protected readonly format = inject(FormatFacade);

  protected readonly code = this.data.code;
  /** Undefined while the API is asked, null when no product holds the code. */
  protected readonly scan = signal<ProductScan | null | undefined>(undefined);
  protected readonly failed = signal<ProductsError | null>(null);
  /** Whether the picker of the product to add the code to is open. */
  protected readonly attaching = signal(false);

  protected readonly actions = computed((): readonly ScanAction[] => {
    const scan = this.scan();
    if (scan === undefined) return [];
    const code = encodeURIComponent(this.code);
    if (scan === null) {
      const actions: ScanAction[] = [];
      if (this.auth.hasPermission('product.write')) {
        actions.push(
          { id: 'create', key: 'n', icon: 'add', url: `/products/new?barcode=${code}` },
          { id: 'attach', key: 'a', icon: 'add_link', url: null },
        );
      }
      actions.push({ id: 'search', key: 'r', icon: 'search', url: `/products?q=${code}` });
      return actions;
    }
    const id = encodeURIComponent(scan.productId);
    const actions: ScanAction[] = [
      { id: 'sheet', key: 'f', icon: 'description', url: `/products/${id}` },
      { id: 'codes', key: 'c', icon: 'barcode', url: `/products/${id}?tab=codes` },
    ];
    if (this.auth.hasModule('inventory') && this.auth.hasPermission('stock.read')) {
      actions.push({
        id: 'movements',
        key: 'm',
        icon: 'swap_vert',
        url: `/stock/movements?productId=${id}`,
      });
    }
    // A new document starts with the scan itself, so a pack's count and the till's rule apply as on any draft.
    if (
      scan.isActive &&
      this.auth.hasModule('invoices') &&
      this.auth.hasPermission('invoice.write')
    ) {
      actions.push({
        id: 'invoice',
        key: 'i',
        icon: 'receipt_long',
        url: `/invoices/new?scan=${code}`,
      });
    }
    if (
      scan.isActive &&
      this.auth.hasModule('delivery_notes') &&
      this.auth.hasPermission('delivery_note.write')
    ) {
      actions.push({
        id: 'delivery_note',
        key: 'l',
        icon: 'local_shipping',
        url: `/delivery-notes/new?scan=${code}`,
      });
    }
    return actions;
  });

  async ngOnInit(): Promise<void> {
    const companyId = this.auth.me()?.company?.id;
    if (companyId === undefined) return;
    try {
      this.scan.set(await this.api.scan(companyId, this.code));
    } catch (error) {
      this.failed.set(error instanceof ProductsRefused ? error.code : 'network');
    }
  }

  protected onKeydown(event: KeyboardEvent): void {
    // What is typed into the picker is the search, not a key of the card. Not `isTypingTarget`: the card is itself
    // in an overlay, which that check counts as typing.
    const field =
      event.target instanceof Element &&
      event.target.closest('input, textarea, select, [contenteditable]') !== null;
    if (!isBareKeystroke(event) || field) return;
    const actions = this.actions();
    const action =
      event.key === 'Enter'
        ? actions[0]
        : actions.find((candidate) => candidate.key === event.key.toLowerCase());
    if (action === undefined) return;
    event.preventDefault();
    this.run(action);
  }

  protected run(action: ScanAction): void {
    if (action.url === null) {
      this.attaching.set(true);
      afterNextRender(
        () =>
          this.host.nativeElement
            .querySelector<HTMLInputElement>('[data-testid="product-scan-attach"]')
            ?.focus(),
        { injector: this.injector },
      );
      return;
    }
    this.dialog.close();
    void this.router.navigateByUrl(action.url);
  }

  /** The products the code may be added to: those still sold, found by their words. */
  protected readonly searchProducts = async (words: string): Promise<readonly PickOption[]> => {
    const companyId = this.auth.me()?.company?.id;
    if (companyId === undefined) return [];
    const page = await this.api.products(companyId, {
      page: 1,
      itemsPerPage: 10,
      q: words,
      kind: null,
      isActive: true,
      order: null,
    });
    return page.rows.map((row) => ({ id: row.id, code: row.reference, name: row.name }));
  };

  /** The code goes onto the chosen product's codes, where the person sets its role and saves it. */
  protected attachTo(option: PickOption | null): void {
    if (option === null) return;
    this.dialog.close();
    void this.router.navigateByUrl(
      `/products/${encodeURIComponent(option.id)}?tab=codes&add=${encodeURIComponent(this.code)}`,
    );
  }
}
