// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  OnInit,
  signal,
} from '@angular/core';
import { MatSlideToggleChange, MatSlideToggleModule } from '@angular/material/slide-toggle';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { ModulesFacade } from './modules-facade';
import type { ModuleRow } from './modules-types';

/**
 * The modules the company has on, one switch each. A module switched off leaves the navigation and its pages, and
 * keeps its data; a switch the API refuses names the modules it is waiting on and goes back to where it was.
 */
@Component({
  selector: 'app-modules-page',
  imports: [MatSlideToggleModule, TranslatePipe],
  templateUrl: './modules-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ModulesPage implements OnInit {
  private readonly facade = inject(ModulesFacade);
  private readonly auth = inject(AuthFacade);

  protected readonly modules = this.facade.modules;
  protected readonly busy = this.facade.busy;
  protected readonly error = this.facade.error;
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly mayManage = computed(() => this.auth.hasPermission('company.settings'));

  private readonly attempt = signal<{ key: string; enabled: boolean } | null>(null);

  /** What a refused switch waits on: the modules to switch on first, or the enabled modules still needing it. */
  protected readonly blockers = computed<readonly ModuleRow[]>(() => {
    const attempt = this.attempt();
    const all = this.modules();
    if (attempt === null) return [];
    switch (this.error()) {
      case 'still_needed':
        return all.filter((row) => row.enabled && row.dependencies.includes(attempt.key));
      case 'needs_modules': {
        const needed = all.find((row) => row.key === attempt.key)?.dependencies ?? [];
        return all.filter((row) => !row.enabled && needed.includes(row.key));
      }
      default:
        return [];
    }
  });

  async ngOnInit(): Promise<void> {
    const companyId = this.company()?.id;
    if (companyId) {
      await this.facade.load(companyId);
    }
  }

  protected labelOf(key: string): string {
    return this.modules().find((row) => row.key === key)?.labelKey ?? `modules.${key}`;
  }

  protected async toggle(row: ModuleRow, change: MatSlideToggleChange): Promise<void> {
    const companyId = this.company()?.id;
    if (!companyId || this.busy()) {
      change.source.checked = row.enabled;
      return;
    }
    this.attempt.set({ key: row.key, enabled: change.checked });
    if (!(await this.facade.switch(companyId, row.key, change.checked))) {
      change.source.checked = row.enabled;
    }
  }
}
