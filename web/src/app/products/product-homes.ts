// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  input,
  type OnInit,
  signal,
} from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { locationLabels } from '../inventory/inventory-forms';
import { Label } from '../shared/a11y/label';
import { Feedback } from '../shared/feedback/feedback';
import { Select } from '../shared/form/select';
import { StatusBadge } from '../shared/ui/status-badge';
import { ProductHomes } from './product-homes-facade';
import type { ProductHomeRow } from './products-types';

/**
 * Where a product normally lives: several places per establishment, in an order, the first the main one a receipt
 * proposes (docs/SPEC.md rows 101 and 188).
 *
 * A home is a PROPOSAL: it is what a receipt offers so a person putting goods away answers that question once
 * instead of on every receipt, and nothing here refuses a movement to anywhere else. So removing one is an ordinary
 * button and not a confirmed destruction — nothing is lost, and the same shelf can be added again in one step.
 */
@Component({
  selector: 'app-product-homes',
  imports: [FormsModule, MatButtonModule, MatIconModule, Select, StatusBadge, TranslatePipe, Label],
  templateUrl: './product-homes.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ProductHomesSection implements OnInit {
  private readonly homes = inject(ProductHomes);
  private readonly auth = inject(AuthFacade);
  private readonly feedback = inject(Feedback);

  readonly productId = input.required<string>();
  readonly readOnly = input(false);

  protected readonly groups = this.homes.groups;
  protected readonly busy = this.homes.busy;
  protected readonly error = this.homes.error;
  protected readonly chosen = signal('');

  /** Every location of the company, read as a person reads a shelf: its path, then its name. */
  protected readonly choices = computed(() =>
    [...locationLabels(this.homes.locations())].map(([value, label]) => ({ value, label })),
  );

  private readonly companyId = computed(() => this.auth.me()?.company?.id ?? null);

  async ngOnInit(): Promise<void> {
    const companyId = this.companyId();
    if (companyId !== null) await this.homes.load(companyId, this.productId());
  }

  protected async add(): Promise<void> {
    const companyId = this.companyId();
    const locationId = this.chosen();
    if (companyId === null || locationId === '') return;
    if (await this.homes.add(companyId, this.productId(), locationId)) {
      this.chosen.set('');
      this.feedback.success('products.homes.saved');
    }
  }

  protected async move(home: ProductHomeRow, step: -1 | 1): Promise<void> {
    const companyId = this.companyId();
    if (companyId === null) return;
    if (await this.homes.move(companyId, this.productId(), home, step)) {
      this.feedback.success('products.homes.reordered');
    }
  }

  protected async remove(home: ProductHomeRow): Promise<void> {
    const companyId = this.companyId();
    if (companyId === null) return;
    if (await this.homes.remove(companyId, this.productId(), home)) {
      this.feedback.success('products.homes.cleared');
    }
  }
}
