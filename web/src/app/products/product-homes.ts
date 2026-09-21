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
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatSelectModule } from '@angular/material/select';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { locationLabels } from '../inventory/inventory-forms';
import { Label } from '../shared/a11y/label';
import { Feedback } from '../shared/feedback/feedback';
import { ProductHomes } from './product-homes-facade';

/**
 * Where a product normally lives, one home per establishment (docs/SPEC.md row 101).
 *
 * A home is a PROPOSAL: it is what a receipt offers so a person putting goods away answers that question once
 * instead of on every receipt, and nothing here refuses a movement to anywhere else. So clearing one is an ordinary
 * button and not a confirmed destruction — nothing is lost, and the same shelf can be chosen again in one step.
 */
@Component({
  selector: 'app-product-homes',
  imports: [
    FormsModule,
    MatButtonModule,
    MatFormFieldModule,
    MatIconModule,
    MatSelectModule,
    TranslatePipe,
    Label,
  ],
  templateUrl: './product-homes.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ProductHomesSection implements OnInit {
  private readonly homes = inject(ProductHomes);
  private readonly auth = inject(AuthFacade);
  private readonly feedback = inject(Feedback);

  readonly productId = input.required<string>();
  readonly readOnly = input(false);

  protected readonly rows = this.homes.homes;
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

  protected async set(): Promise<void> {
    const companyId = this.companyId();
    const locationId = this.chosen();
    if (companyId === null || locationId === '') return;
    if (await this.homes.set(companyId, this.productId(), locationId)) {
      this.chosen.set('');
      this.feedback.success('products.homes.saved');
    }
  }

  protected async clear(establishmentId: string): Promise<void> {
    const companyId = this.companyId();
    if (companyId === null) return;
    if (await this.homes.clear(companyId, this.productId(), establishmentId)) {
      this.feedback.success('products.homes.cleared');
    }
  }
}
