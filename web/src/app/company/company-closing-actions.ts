// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable } from '@angular/core';
import { MatDialog } from '@angular/material/dialog';
import { Router } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import type { ActionConfirm } from '../shared/actions/screen-action';
import { saidAlready } from '../shared/errors/said-already';
import { Feedback } from '../shared/feedback/feedback';
import { FormatFacade } from '../shared/i18n/format-facade';
import { StepUp } from '../shared/step-up/step-up';
import { ConfirmDialog } from '../shared/ui/confirm-dialog';
import { askedForProof, CompanyClosingApi, CompanyClosingRefused } from './company-closing-api';

/** The company acted on: its id and its name as the question and the toast say it. */
export interface ClosedCompany {
  readonly id: string;
  readonly name: string;
}

/**
 * « Fermer l'entreprise » and « Rouvrir l'entreprise », the same from the profile, the toast and the page a closed
 * company's members land on. Closing is final once the days to reopen are over, so it is asked as « Définitif à terme »
 * with the company's name typed; reopening is the undo, asked nothing. Each proves who is at the screen only when the
 * API asks, and says the dates from its answer and the session's.
 */
@Injectable({ providedIn: 'root' })
export class CompanyClosingActions {
  private readonly api = inject(CompanyClosingApi);
  private readonly auth = inject(AuthFacade);
  private readonly dialog = inject(MatDialog);
  private readonly stepUp = inject(StepUp);
  private readonly feedback = inject(Feedback);
  private readonly format = inject(FormatFacade);
  private readonly router = inject(Router);

  /** True once closed; the person then waits on the page a closed company's members see, with « Rouvrir » there. */
  async close(company: ClosedCompany): Promise<boolean> {
    if (!(await this.confirmed(company))) return false;
    try {
      const terms = await this.stepUp.retrying(
        () => this.api.closeDown(company.id),
        askedForProof,
        'company.closing.step_up',
      );
      await this.auth.refresh();
      await this.router.navigateByUrl('/awaiting-approval');
      this.feedback.effect(
        'company.closing.done',
        {
          company: company.name,
          reopen: this.format.moment(terms.reopenUntil),
          retain: this.format.day(terms.retainUntil),
        },
        'definitif_a_terme',
        { key: 'company.closing.reopen', run: () => void this.reopen(company) },
      );
      return true;
    } catch (error) {
      this.refused(error, 'company.closing.failed');
      return false;
    }
  }

  /** True once open again: the session follows, and the page outside the shell leaves by itself. */
  async reopen(company: ClosedCompany): Promise<boolean> {
    try {
      await this.stepUp.retrying(
        () => this.api.reopen(company.id),
        askedForProof,
        'company.closing.step_up_reopen',
      );
      await this.auth.refresh();
      this.feedback.effect('company.closing.reopened', { company: company.name }, 'corrigeable');
      return true;
    } catch (error) {
      this.refused(error, 'company.closing.reopen_failed');
      return false;
    }
  }

  private refused(error: unknown, otherwise: string): void {
    if (saidAlready(error)) return;
    const code = error instanceof CompanyClosingRefused ? error.code : 'refused';
    if (code === 'owner_only' || code === 'reopen_period_over') {
      this.feedback.failure(`company.closing.${code}`);
    } else if (code !== 'step_up_required') {
      // A proof refused is said by its own dialog: the person gave it up.
      this.feedback.failure(otherwise);
    }
  }

  private async confirmed(company: ClosedCompany): Promise<boolean> {
    const confirm: ActionConfirm = {
      kind: 'definitif_a_terme',
      title: 'company.closing.confirm_title',
      message: 'company.closing.confirm_message',
      messageParams: { company: company.name },
      confirmLabel: 'company.closing.confirm',
      keepLabel: 'company.closing.keep',
      typed: { name: company.name },
    };
    return (
      (await firstValueFrom(
        this.dialog
          .open<ConfirmDialog, ActionConfirm, boolean>(ConfirmDialog, {
            data: confirm,
            autoFocus: '[data-testid="confirm-typed"]',
          })
          .afterClosed(),
      )) === true
    );
  }
}
