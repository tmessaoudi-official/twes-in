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
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { QrCode } from '../shared/qr/qr-code';
import { InventoryFacade } from './inventory-facade';
import { locationLabels } from './inventory-forms';

/**
 * A sheet of location labels to print and stick on the shelves (docs/SPEC.md § 7, 2026-09-23 slice 8). Each carries
 * the location's code in large type, its path, and a QR code of its own address in this app: a phone's camera opens
 * it straight into count mode at that location, and count mode recognises it from a scanner as well. A label is
 * paper, so it is black on white whatever the screen's scheme, as the QR code itself is.
 */
@Component({
  selector: 'app-location-labels-page',
  imports: [MatButtonModule, MatIconModule, QrCode, TranslatePipe],
  templateUrl: './location-labels-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class LocationLabelsPage implements OnInit {
  private readonly facade = inject(InventoryFacade);
  private readonly auth = inject(AuthFacade);
  private readonly document = inject(DOCUMENT);

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
