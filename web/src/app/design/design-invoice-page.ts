// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { TranslatePipe } from '@ngx-translate/core';
import { DESIGN_CUSTOMER_NAMES, DESIGN_INVOICE } from './design-fixtures';

/**
 * A static invoice editor for the design checkpoint: the layout of header, lines, totals and actions over one
 * fixture invoice. Its fields accept typing but nothing recomputes, and the page says so; invoice arithmetic is
 * test-driven where invoices are built for real (G3 and G7).
 */
@Component({
  selector: 'app-design-invoice-page',
  imports: [
    MatButtonModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatSelectModule,
    TranslatePipe,
  ],
  templateUrl: './design-invoice-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class DesignInvoicePage {
  protected readonly invoice = DESIGN_INVOICE;
  protected readonly customers = DESIGN_CUSTOMER_NAMES;
  protected readonly taxes = ['TVA 19 %', 'TVA 13 %', 'TVA 7 %', 'Exonéré'];
}
