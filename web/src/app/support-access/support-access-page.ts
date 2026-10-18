// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  inject,
  signal,
} from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatRadioModule } from '@angular/material/radio';
import { MatIconModule } from '@angular/material/icon';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { unlessDemoRefused } from '../shared/demo/demo-refused-interceptor';
import { Feedback } from '../shared/feedback/feedback';
import { FormatFacade } from '../shared/i18n/format-facade';
import { LiveChanges } from '../shared/realtime/live-changes';
import { StepUp } from '../shared/step-up/step-up';
import {
  SupportAccessApi,
  SupportAccessRefused,
  type SupportAccessState,
} from './support-access-api';

/** The window offered first: long enough for a question asked in the morning to be answered the next day. */
export const DEFAULT_SUPPORT_HOURS = 24;

/**
 * « Accès du support »: the owner lets the platform's support read the company for an hour, a day or three, read-only,
 * never its exports nor what goods cost, and closes it whenever they like. Every visit is in the activity log.
 */
@Component({
  selector: 'app-support-access-page',
  imports: [MatButtonModule, MatIconModule, MatRadioModule, RouterLink, TranslatePipe],
  templateUrl: './support-access-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SupportAccessPage {
  private readonly api = inject(SupportAccessApi);
  private readonly auth = inject(AuthFacade);
  private readonly feedback = inject(Feedback);
  private readonly format = inject(FormatFacade);
  private readonly stepUp = inject(StepUp);

  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly state = signal<SupportAccessState | null>(null);
  /** True when the API says only the owner may come here. */
  protected readonly ownerOnly = signal(false);
  protected readonly hours = signal(DEFAULT_SUPPORT_HOURS);
  protected readonly busy = signal(false);
  protected readonly until = computed(() => {
    const endsAt = this.state()?.endsAt ?? null;
    return endsAt === null ? null : this.format.moment(endsAt, this.company()?.timezone);
  });

  constructor() {
    // Another owner may open or close it meanwhile, and a window ends by itself.
    inject(LiveChanges).reloadOn(['support_grant'], () => this.reload(), inject(DestroyRef));
    void this.reload();
  }

  protected async grant(): Promise<void> {
    const companyId = this.company()?.id;
    if (!companyId || this.busy()) return;
    this.busy.set(true);
    try {
      // Opening the company to support waits for the owner to prove who is at the screen (docs/SPEC.md § 7).
      const hours = this.hours();
      this.state.set(
        await this.stepUp.retrying(
          () => this.api.grant(companyId, hours),
          (error) => error instanceof SupportAccessRefused && error.code === 'step_up_required',
          'step_up.intro_change',
        ),
      );
      this.feedback.effect('support_access.granted', { until: this.until() }, 'corrigeable');
      await this.auth.refresh();
    } catch (error) {
      this.refused(error);
    } finally {
      this.busy.set(false);
    }
  }

  protected async end(): Promise<void> {
    const companyId = this.company()?.id;
    if (!companyId || this.busy()) return;
    this.busy.set(true);
    try {
      await this.api.end(companyId);
      this.feedback.effect('support_access.ended', {}, 'corrigeable');
      await this.reload();
      await this.auth.refresh();
    } catch (error) {
      this.refused(error);
    } finally {
      this.busy.set(false);
    }
  }

  private async reload(): Promise<void> {
    const companyId = this.company()?.id;
    if (!companyId) return;
    try {
      this.state.set(await this.api.read(companyId));
      this.ownerOnly.set(false);
    } catch (error) {
      if (error instanceof SupportAccessRefused && error.code === 'owner_only') {
        this.ownerOnly.set(true);
        return;
      }
      this.refused(error);
    }
  }

  private refused(error: unknown): void {
    const code = unlessDemoRefused(error instanceof SupportAccessRefused ? error.code : 'network');
    if (code !== null) this.feedback.failure(`support_access.refused.${code}`);
  }
}
