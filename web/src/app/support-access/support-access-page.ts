// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  ElementRef,
  inject,
  signal,
  viewChild,
} from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatRadioModule } from '@angular/material/radio';
import { MatIconModule } from '@angular/material/icon';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { saidAlready } from '../shared/errors/said-already';
import { RefusedField } from '../shared/form/refused-field';
import { typedText } from '../shared/form/typed-text';
import { UnsavedChanges } from '../shared/form/unsaved-changes';
import { Feedback } from '../shared/feedback/feedback';
import { FormatFacade } from '../shared/i18n/format-facade';
import { LiveChanges } from '../shared/realtime/live-changes';
import { StepUp } from '../shared/step-up/step-up';
import { deadlineClock } from '../shared/time/deadline-clock';
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
  imports: [
    MatButtonModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatRadioModule,
    RefusedField,
    RouterLink,
    TranslatePipe,
  ],
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
  /** What is wrong, as the owner types it for support to read on opening the company. */
  protected readonly reason = signal('');
  protected readonly busy = signal(false);
  /** A reason typed and not yet sent is work a person would lose by leaving. */
  private readonly reasonTyped = computed(() => (typedText(this.reason()) === '' ? 0 : 1));
  /** The reason's length as the API counts it: in characters, without what sits at its ends. */
  protected readonly reasonLength = computed(() => [...typedText(this.reason())].length);
  /**
   * Longer than the API keeps: said under the field, and nothing is sent, so the owner is never asked to prove who
   * they are for a reason refused afterwards; what was typed or pasted is kept whole to be shortened.
   */
  protected readonly reasonTooLong = computed(() => {
    const max = this.state()?.reasonMax;
    return max !== undefined && this.reasonLength() > max;
  });
  private readonly reasonField = viewChild<ElementRef<HTMLTextAreaElement>>('reasonField');
  /** Moves on when the window ends, which nothing announces: the page then says it is closed. */
  private readonly now = deadlineClock(() => [this.state()?.endsAt ?? null]);
  protected readonly until = computed(() => {
    const endsAt = this.state()?.endsAt ?? null;
    if (endsAt === null || Date.parse(endsAt) <= this.now()) return null;
    return this.format.moment(endsAt, this.company()?.timezone);
  });

  constructor() {
    inject(UnsavedChanges).declare(this.reasonTyped);
    // Another owner may open or close it meanwhile; a window ending by itself is told by `now`, since nothing says it.
    inject(LiveChanges).reloadOn(['support_grant'], () => this.reload(), inject(DestroyRef));
    void this.reload();
  }

  protected async grant(): Promise<void> {
    const companyId = this.company()?.id;
    if (!companyId || this.busy()) return;
    if (this.reasonTooLong()) {
      this.reasonField()?.nativeElement.focus();
      return;
    }
    this.busy.set(true);
    try {
      // Opening the company to support waits for the owner to prove who is at the screen (docs/SPEC.md § 7).
      const hours = this.hours();
      const typed = typedText(this.reason());
      const reason = typed === '' ? null : typed;
      this.state.set(
        await this.stepUp.retrying(
          () => this.api.grant(companyId, hours, reason),
          (error) => error instanceof SupportAccessRefused && error.code === 'step_up_required',
          'step_up.intro_change',
        ),
      );
      this.reason.set('');
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

  protected typed(event: Event): void {
    this.reason.set((event.target as HTMLTextAreaElement).value);
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
    if (saidAlready(error)) return;
    const code = error instanceof SupportAccessRefused ? error.code : 'network';
    this.feedback.failure(`support_access.refused.${code}`);
  }
}
