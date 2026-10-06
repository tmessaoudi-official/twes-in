// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DOCUMENT,
  inject,
  type OnInit,
} from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { QrCode } from '../shared/qr/qr-code';
import { InventoryFacade } from './inventory-facade';
import { locationLabels } from './inventory-forms';

/**
 * How large a label prints its code: on one line across the label, so a code is never split at its hyphen, smaller as
 * it grows; one too long for any size on one line may break anywhere rather than run off the label.
 */
export function codeSize(code: string): string {
  if (code.length <= 8) return 'text-4xl whitespace-nowrap';
  if (code.length <= 12) return 'text-3xl whitespace-nowrap';
  if (code.length <= 16) return 'text-2xl whitespace-nowrap';
  return 'text-xl break-all';
}

/**
 * A sheet of location labels to print and stick on the shelves (docs/SPEC.md § 7, 2026-09-23 slice 8). Each carries
 * the location's code in large type, its path, and a QR code of its own address in this app: a phone's camera opens
 * it straight into count mode at that location, and count mode recognises it from a scanner as well. A label is
 * paper, so it is black on white whatever the screen's scheme, as the QR code itself is.
 */
@Component({
  selector: 'app-location-labels-page',
  imports: [MatButtonModule, MatIconModule, QrCode, RouterLink, TranslatePipe],
  templateUrl: './location-labels-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class LocationLabelsPage implements OnInit {
  private readonly facade = inject(InventoryFacade);
  private readonly auth = inject(AuthFacade);
  private readonly document = inject(DOCUMENT);
  protected readonly codeSize = codeSize;

  protected readonly labels = computed(() => {
    const paths = locationLabels(this.facade.locations());
    return this.facade.locations().map((location) => ({
      id: location.id,
      code: location.code,
      path: paths.get(location.id) ?? '',
    }));
  });

  async ngOnInit(): Promise<void> {
    const companyId = this.auth.me()?.company?.id;
    if (companyId) await this.facade.loadStockContext(companyId);
  }

  /** The address a label's QR code carries: count mode at that location, on this app's own origin. */
  address(id: string): string {
    return `${this.document.location.origin}/stock/locations/${id}`;
  }

  protected print(): void {
    this.document.defaultView?.print();
  }
}
