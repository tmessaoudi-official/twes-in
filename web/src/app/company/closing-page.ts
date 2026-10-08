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
import { FormControl, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatDialog } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { runAction } from '../shared/actions/run-action';
import { Feedback } from '../shared/feedback/feedback';
import { todayIn } from '../shared/i18n/format';
import { DayPipe } from '../shared/i18n/format-pipes';
import { FormatFacade } from '../shared/i18n/format-facade';
import { LiveChanges } from '../shared/realtime/live-changes';
import { ConfirmDialog } from '../shared/ui/confirm-dialog';
import { ClosingApi, type ClosingError, ClosingRefused, type CompanyClosing } from './closing-api';

/**
 * « Clôture des comptes »: the last day of the closed period, and closing through a later one. Once closed, no
 * document is dated on or before that day, and a closed period never opens again, so the question before closing says
 * it is final. Today is never closed: the day still being worked stays open.
 */
@Component({
  selector: 'app-closing-page',
  imports: [
    ReactiveFormsModule,
    MatButtonModule,
    MatFormFieldModule,
    MatInputModule,
    TranslatePipe,
    DayPipe,
  ],
  templateUrl: './closing-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ClosingPage implements OnInit {
  private readonly api = inject(ClosingApi);
  private readonly auth = inject(AuthFacade);
  private readonly live = inject(LiveChanges);
  private readonly destroyRef = inject(DestroyRef);
  private readonly dialog = inject(MatDialog);
  private readonly feedback = inject(Feedback);
  private readonly format = inject(FormatFacade);

  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly closing = signal<CompanyClosing | null>(null);
  protected readonly error = signal<ClosingError | null>(null);
  protected readonly busy = signal(false);
  /** The last day that may be closed: yesterday, on the company's own calendar. */
  protected readonly latest = computed(() => dayBefore(todayIn(this.company()?.timezone)));
  protected readonly form = new FormGroup({
    day: new FormControl('', { nonNullable: true, validators: Validators.required }),
  });

  async ngOnInit(): Promise<void> {
    const companyId = this.company()?.id;
    if (companyId) {
      this.live.reloadOn(['company'], () => this.load(companyId), this.destroyRef);
      await this.load(companyId);
    }
  }

  protected close(): void {
    const companyId = this.company()?.id;
    const day = this.form.controls.day.value;
    if (!companyId || day === '' || this.busy()) {
      this.form.markAllAsTouched();
      return;
    }
    runAction(
      {
        run: () => void this.closeThrough(companyId, day),
        confirm: {
          kind: 'definitif',
          title: 'company.closing.ask.title',
          message: 'company.closing.ask.message',
          messageParams: { day: this.format.day(day) },
          confirmLabel: 'company.closing.ask.confirm',
          keepLabel: 'company.closing.ask.keep',
        },
      },
      (confirm) =>
        this.dialog.open(ConfirmDialog, { data: confirm, autoFocus: 'dialog' }).afterClosed(),
    );
  }

  private async closeThrough(companyId: string, day: string): Promise<void> {
    this.busy.set(true);
    this.error.set(null);
    try {
      this.closing.set(await this.api.closeThrough(companyId, day));
      this.form.reset();
      this.feedback.effect('company.closing.closed', {}, 'definitif');
    } catch (error) {
      this.error.set(error instanceof ClosingRefused ? error.code : 'network');
    } finally {
      this.busy.set(false);
    }
  }

  private async load(companyId: string): Promise<void> {
    try {
      this.closing.set(await this.api.read(companyId));
      this.error.set(null);
    } catch (error) {
      this.error.set(error instanceof ClosingRefused ? error.code : 'network');
    }
  }
}

/** The day before an ISO day, by the calendar alone. */
function dayBefore(day: string): string {
  const date = new Date(`${day}T00:00:00Z`);
  date.setUTCDate(date.getUTCDate() - 1);
  return date.toISOString().slice(0, 10);
}
