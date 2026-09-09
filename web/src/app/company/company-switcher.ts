// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject, OnInit } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatMenuModule } from '@angular/material/menu';
import { TranslatePipe } from '@ngx-translate/core';
import { CompanyFacade } from './company-facade';

/**
 * Where the user is working, and the way to somewhere else. Renders nothing at all when there is only one
 * company: a menu with a single entry is furniture, not a choice.
 */
@Component({
  selector: 'app-company-switcher',
  imports: [MatButtonModule, MatMenuModule, TranslatePipe],
  templateUrl: './company-switcher.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class CompanySwitcher implements OnInit {
  private readonly companyFacade = inject(CompanyFacade);

  protected readonly companies = this.companyFacade.companies;
  protected readonly current = this.companyFacade.current;
  protected readonly canSwitch = this.companyFacade.canSwitch;
  protected readonly switching = this.companyFacade.switching;

  async ngOnInit(): Promise<void> {
    await this.companyFacade.load();
  }

  protected async choose(companyId: string): Promise<void> {
    await this.companyFacade.switchTo(companyId);
  }
}
