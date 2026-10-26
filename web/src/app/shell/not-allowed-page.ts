// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, effect, inject, input } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { Router, RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { gatedPage, RouteGates } from './permission-guard';

/**
 * Where a page the person may not open sends them (hunt P-2): it says which page, whether their role or the company's
 * subscription keeps it closed, and offers the way home, once, instead of a list that fails to load and still offers
 * « Exporter ». It follows the session as every screen does: once a company switch or a new role opens the page, the
 * person is taken on to it.
 */
@Component({
  selector: 'app-not-allowed-page',
  imports: [MatButtonModule, RouterLink, TranslatePipe],
  templateUrl: './not-allowed-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class NotAllowedPage {
  private readonly auth = inject(AuthFacade);
  private readonly gates = inject(RouteGates);
  private readonly router = inject(Router);

  /** The refused page's route, as its declaration names it (`?page=`). */
  readonly page = input<string>();
  /** Where the person was going (`?from=`), an address inside the application. */
  readonly from = input<string>();

  protected readonly declaration = computed(() => gatedPage(this.page() ?? ''));
  protected readonly company = computed(() => this.auth.me()?.company?.name ?? '');
  protected readonly refusal = computed(() => {
    const declaration = this.declaration();
    return declaration === null ? 'role' : this.gates.refusal(declaration);
  });

  constructor() {
    effect(() => {
      const declaration = this.declaration();
      if (declaration === null || this.auth.me() === null || this.refusal() !== null) return;
      // Only an address of this application: a link from elsewhere never makes this page open another site.
      const from = this.from();
      void this.router.navigateByUrl(
        from !== undefined && from.startsWith('/') && !from.startsWith('//')
          ? from
          : declaration.route,
        { replaceUrl: true },
      );
    });
  }
}
