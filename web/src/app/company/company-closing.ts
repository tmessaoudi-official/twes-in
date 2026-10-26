// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { CompanyClosingActions } from './company-closing-actions';

/**
 * « Fermer l'entreprise » at the foot of the company's profile, for its owner alone, as the API allows: where
 * « Supprimer mon compte » sends the only owner of a company. Anyone else is told who may.
 */
@Component({
  selector: 'app-company-closing',
  imports: [MatButtonModule, TranslatePipe],
  template: `
    <section class="flex max-w-xl flex-col gap-3" data-testid="company-closing">
      <h2 class="text-lg font-semibold">{{ 'company.closing.title' | translate }}</h2>
      <p class="m-0 text-sm text-on-surface-variant">{{ 'company.closing.intro' | translate }}</p>
      @if (owner()) {
        <div>
          <button
            mat-stroked-button
            type="button"
            class="twes-danger"
            [disabled]="busy()"
            (click)="close()"
            data-testid="company-close"
          >
            {{ 'company.closing.close' | translate }}
          </button>
        </div>
      } @else {
        <p class="m-0 text-sm" data-testid="company-close-owner-only">
          {{ 'company.closing.owner_only' | translate }}
        </p>
      }
    </section>
  `,
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class CompanyClosing {
  private readonly auth = inject(AuthFacade);
  private readonly actions = inject(CompanyClosingActions);

  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly owner = computed(() => this.company()?.role === 'owner');
  protected readonly busy = signal(false);

  protected async close(): Promise<void> {
    const company = this.company();
    if (company === null || this.busy()) return;
    this.busy.set(true);
    try {
      await this.actions.close({ id: company.id, name: company.name });
    } finally {
      this.busy.set(false);
    }
  }
}
