// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { MatIconModule } from '@angular/material/icon';
import { TranslatePipe } from '@ngx-translate/core';
import type { ProductCheckReason } from './products-types';

/**
 * « À vérifier » on a product's page: what it lacks, as the API says it to this reader, and the mark a person put on
 * it with its note. Each reason leaves once what it names is filled in; the mark is set and cleared in the form below.
 */
@Component({
  selector: 'app-product-check',
  imports: [MatIconModule, TranslatePipe],
  templateUrl: './product-check.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ProductCheck {
  readonly reasons = input.required<readonly ProductCheckReason[]>();
  readonly marked = input(false);
  readonly note = input<string | null>(null);
}
