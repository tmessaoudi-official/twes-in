// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatDialog } from '@angular/material/dialog';
import { Router } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { firstValueFrom } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { CompanyFacade } from '../company/company-facade';
import type { ActionConfirm } from '../shared/actions/screen-action';
import { saidAlready } from '../shared/errors/said-already';
import { Feedback } from '../shared/feedback/feedback';
import { FormatFacade } from '../shared/i18n/format-facade';
import { StepUp } from '../shared/step-up/step-up';
import { ConfirmDialog } from '../shared/ui/confirm-dialog';
import {
  AccountDeletionApi,
  AccountDeletionRefused,
  type OwnedAlone,
} from './account-deletion-api';

const STEP_UP = 'account.deletion.step_up';

/**
 * « Supprimer mon compte »: the account closes at once and is deleted for good after the days the platform sets;
 * signing in before then takes it back, so the question calls it « Définitif à terme », asks a word typed, and the
 * toast gives the day. The only owner of a company is kept until it has another owner or is closed, and is led to
 * either, company by company. An operator is told at once: the API refuses their account whatever is confirmed.
 */
@Component({
  selector: 'app-account-deletion',
  imports: [MatButtonModule, TranslatePipe],
  templateUrl: './account-deletion.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AccountDeletion {
  private readonly api = inject(AccountDeletionApi);
  private readonly stepUp = inject(StepUp);
  private readonly dialog = inject(MatDialog);
  private readonly auth = inject(AuthFacade);
  private readonly companies = inject(CompanyFacade);
  private readonly router = inject(Router);
  private readonly feedback = inject(Feedback);
  private readonly format = inject(FormatFacade);

  protected readonly busy = signal(false);
  /** The companies the account owns alone, once the API has named them. */
  protected readonly ownedAlone = signal<readonly OwnedAlone[]>([]);
  protected readonly operator = signal(this.auth.isPlatformOperator());

  protected async delete(): Promise<void> {
    if (this.busy()) return;
    this.busy.set(true);
    try {
      if (!(await this.confirmed()) || !(await this.stepUp.request(STEP_UP))) return;
      await this.attempt();
    } finally {
      this.busy.set(false);
    }
  }

  /** Opens the company as the working one, then the page where it is handed over or closed. */
  protected async open(company: OwnedAlone, page: '/members' | '/company/profile'): Promise<void> {
    if (await this.companies.switchTo(company.id)) await this.router.navigateByUrl(page);
  }

  private async attempt(again = true): Promise<void> {
    try {
      const finalAt = await this.api.delete();
      this.auth.sessionEnded();
      await this.router.navigateByUrl('/login');
      this.feedback.effect(
        'account.deletion.done',
        { day: this.format.day(finalAt) },
        'definitif_a_terme',
      );
    } catch (error) {
      if (saidAlready(error)) return;
      const code = error instanceof AccountDeletionRefused ? error.code : 'refused';
      // The proof is older than the API keeps it: asked again where the person is, then the same request once more.
      if (code === 'step_up_required' && again) {
        if (await this.stepUp.request(STEP_UP)) await this.attempt(false);
      } else if (code === 'sole_owner' && error instanceof AccountDeletionRefused) {
        this.ownedAlone.set(error.companies);
      } else if (code === 'platform_operator') {
        this.operator.set(true);
      } else {
        this.feedback.failure('account.deletion.failed');
      }
    }
  }

  private confirmed(): Promise<boolean | undefined> {
    const confirm: ActionConfirm = {
      kind: 'definitif_a_terme',
      title: 'account.deletion.confirm_title',
      message: 'account.deletion.confirm_message',
      confirmLabel: 'account.deletion.confirm',
      keepLabel: 'account.deletion.keep',
      typed: { word: 'account.deletion.typed_word' },
    };
    return firstValueFrom(
      this.dialog
        .open<ConfirmDialog, ActionConfirm, boolean>(ConfirmDialog, {
          data: confirm,
          autoFocus: '[data-testid="confirm-typed"]',
        })
        .afterClosed(),
    );
  }
}
