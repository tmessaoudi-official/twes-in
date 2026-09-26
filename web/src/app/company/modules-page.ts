// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  inject,
  OnInit,
  signal,
} from '@angular/core';
import { LiveChanges } from '../shared/realtime/live-changes';
import { MatSlideToggleChange, MatSlideToggleModule } from '@angular/material/slide-toggle';
import { MatButtonModule } from '@angular/material/button';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';
import { ThemeFacade } from '../shared/theme/theme-facade';
import { AuthFacade } from '../auth/auth-facade';
import { Feedback } from '../shared/feedback/feedback';
import { ModulesFacade } from './modules-facade';
import type { ModuleRow } from './modules-types';

/**
 * The modules the company has on, one switch each. A module switched off leaves the navigation and its pages, and
 * keeps its data; a switch the API refuses names the modules it is waiting on and goes back to where it was.
 */
@Component({
  selector: 'app-modules-page',
  imports: [MatButtonModule, MatSlideToggleModule, TranslatePipe],
  templateUrl: './modules-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ModulesPage implements OnInit {
  private readonly live = inject(LiveChanges);
  private readonly destroyRef = inject(DestroyRef);
  private readonly facade = inject(ModulesFacade);
  private readonly auth = inject(AuthFacade);
  private readonly feedback = inject(Feedback);
  private readonly translate = inject(TranslateService);

  protected readonly modules = this.facade.modules;
  /** What the company can switch, and what is only planned (docs/SPEC.md § 7, 2026-09-26 10:08). */
  protected readonly available = computed(() =>
    this.modules().filter((row) => row.planned === undefined),
  );
  protected readonly planned = computed(() =>
    this.modules().filter((row) => row.planned !== undefined),
  );
  protected readonly showComing = inject(ThemeFacade).showComing;
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
      this.live.reloadOn(['module'], () => this.facade.load(companyId), this.destroyRef);
      await this.facade.load(companyId);
    }
  }

  protected labelOf(key: string): string {
    return this.modules().find((row) => row.key === key)?.labelKey ?? `modules.${key}`;
  }

  /** « Me prévenir », or stop asking: the toast names the module, and says nothing when the API refused. */
  protected async notify(row: ModuleRow): Promise<void> {
    const companyId = this.company()?.id;
    if (!companyId || this.busy()) return;
    const interested = row.interested !== true;
    if (await this.facade.setInterest(companyId, row.key, interested)) {
      this.feedback.success(
        interested ? 'company.modules.notify_saved' : 'company.modules.unnotify_saved',
        { label: this.translate.instant(row.labelKey) as string },
      );
    }
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
