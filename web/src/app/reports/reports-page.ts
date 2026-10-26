// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  inject,
  type OnInit,
} from '@angular/core';
import { MatCardModule } from '@angular/material/card';
import { MatIconModule } from '@angular/material/icon';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { LiveChanges } from '../shared/realtime/live-changes';
import { withSessionKinds } from '../shared/session/session-kinds';
import { ReportsFacade } from './reports-facade';

/**
 * « Rapports »: the reports this member may read, in the order the API offers them,
 * each opening on its own page. A module switched off, or a role changed, takes its reports with it on the next read.
 */
@Component({
  selector: 'app-reports-page',
  imports: [MatCardModule, MatIconModule, RouterLink, TranslatePipe],
  templateUrl: './reports-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ReportsPage implements OnInit {
  private readonly facade = inject(ReportsFacade);
  private readonly auth = inject(AuthFacade);
  private readonly live = inject(LiveChanges);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly catalogue = this.facade.catalogue;
  protected readonly failed = this.facade.catalogueFailed;
  protected readonly empty = computed(() => this.catalogue()?.length === 0);

  async ngOnInit(): Promise<void> {
    const companyId = this.auth.me()?.company?.id;
    if (!companyId) return;
    this.live.reloadOn(
      withSessionKinds(['module', 'role']),
      () => this.facade.loadCatalogue(companyId),
      this.destroyRef,
    );
    await this.facade.loadCatalogue(companyId);
  }
}
