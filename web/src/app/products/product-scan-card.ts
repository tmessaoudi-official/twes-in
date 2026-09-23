// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
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
import { FormatFacade } from '../shared/i18n/format-facade';
import { ProductsApi, ProductsRefused } from './products-api';
import type { ProductScan, ProductsError } from './products-types';

export interface ProductScanCardData {
  /** What the scanner read, as it came. */
  readonly code: string;
}

interface ScanAction {
  readonly id: 'sheet' | 'codes' | 'movements' | 'search';
  /** The one key that runs it; Enter always runs the first. */
  readonly key: string;
  readonly icon: string;
  readonly url: string;
}

/**
 * What a scan on a page with no field focused opens (docs/SPEC.md § 7, 2026-09-23 01:10): the product the code names,
 * what the code counts and what a GS1 scan carried, and one key to each place a person holding it goes next — its
 * sheet, its codes, its movements. A code nobody holds offers the catalogue searched for it.
 */
@Component({
  selector: 'app-product-scan-card',
  imports: [MatButtonModule, MatIconModule, TranslatePipe],
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
  protected readonly format = inject(FormatFacade);

  protected readonly code = this.data.code;
  /** Undefined while the API is asked, null when no product holds the code. */
  protected readonly scan = signal<ProductScan | null | undefined>(undefined);
  protected readonly failed = signal<ProductsError | null>(null);

  protected readonly actions = computed((): readonly ScanAction[] => {
    const scan = this.scan();
    if (scan === undefined) return [];
    if (scan === null) {
      return [
        {
          id: 'search',
          key: 'r',
          icon: 'search',
          url: `/products?q=${encodeURIComponent(this.code)}`,
        },
      ];
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
    if (!isBareKeystroke(event)) return;
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
    this.dialog.close();
    void this.router.navigateByUrl(action.url);
  }
}
