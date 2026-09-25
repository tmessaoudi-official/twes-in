// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  inject,
  type OnInit,
  signal,
} from '@angular/core';
import { MatCardModule } from '@angular/material/card';
import { MatIconModule } from '@angular/material/icon';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { LiveChanges } from '../shared/realtime/live-changes';
import { FirstStepsApi } from './first-steps-api';
import { FIRST_STEP_LINKS, FIRST_STEPS_KINDS, type FirstSteps } from './first-steps-types';

/**
 * « Premiers pas » on the home (docs/SPEC.md § 7, 2026-09-25 22:17, row 139; design direction § 4.2): the steps the
 * member may still do, each leading to where it is done, ticked by the API from what the company has. Gone once
 * nothing remains, and never drawn for a member who may do none of them.
 */
@Component({
  selector: 'app-first-steps-home',
  imports: [MatCardModule, MatIconModule, RouterLink, TranslatePipe],
  templateUrl: './first-steps-home.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class FirstStepsHome implements OnInit {
  private readonly api = inject(FirstStepsApi);
  private readonly auth = inject(AuthFacade);
  private readonly live = inject(LiveChanges);
  private readonly destroyRef = inject(DestroyRef);

  private readonly current = signal<FirstSteps | null>(null);
  protected readonly links = FIRST_STEP_LINKS;
  /** Shown while something remains; a read that failed shows nothing rather than a stale list. */
  protected readonly shown = computed(() => {
    const steps = this.current();
    return steps !== null && steps.remaining > 0 ? steps : null;
  });
  protected readonly doneCount = computed(
    () => this.shown()?.steps.filter((step) => step.done).length ?? 0,
  );

  async ngOnInit(): Promise<void> {
    const companyId = this.auth.me()?.company?.id;
    if (!companyId) return;
    this.live.reloadOn(FIRST_STEPS_KINDS, () => this.load(companyId), this.destroyRef);
    await this.load(companyId);
  }

  /** The translation key of a step, whose dotted key nests in the translations. */
  protected labelOf(key: string): string {
    return `first_steps.steps.${key}`;
  }

  private async load(companyId: string): Promise<void> {
    try {
      this.current.set(await this.api.read(companyId));
    } catch {
      this.current.set(null);
    }
  }
}
