// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component } from '@angular/core';
import { MatTabsModule } from '@angular/material/tabs';
import { RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';

/**
 * The design checkpoint (G2b): fixture screens built only from the shared list and form layers, reachable in a
 * development build only (the route's canMatch and the nav entry's devOnly flag). Nothing here talks to the API.
 */
@Component({
  selector: 'app-design-page',
  imports: [MatTabsModule, RouterLink, RouterLinkActive, RouterOutlet, TranslatePipe],
  templateUrl: './design-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class DesignPage {
  protected readonly tabs = [
    { key: 'customers', path: 'customers', label: 'design.customers.title' },
    { key: 'customer-form', path: 'customers/new', label: 'design.customer_form.title' },
    { key: 'invoice', path: 'invoice', label: 'design.invoice.title' },
  ] as const;
}
