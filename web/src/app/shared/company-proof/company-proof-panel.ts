// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject, type OnInit } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { TranslatePipe } from '@ngx-translate/core';
import { CompanyEntry } from './company-entry';

/**
 * What the shell shows in place of a company's pages until this sign-in has proved itself for it: the question is
 * asked as the panel opens, and asked again from its button by a person who turned it down, or who may instead switch
 * to another company or sign out from the shell around it.
 */
@Component({
  selector: 'app-company-proof-panel',
  imports: [MatButtonModule, MatIconModule, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (entry.asked(); as asked) {
      <section
        class="mx-auto flex max-w-xl flex-col items-start gap-4 py-8"
        data-testid="company-proof-panel"
      >
        <h1 class="m-0 text-2xl">
          {{ 'company_proof.title' | translate: { company: asked.companyName } }}
        </h1>
        <p class="m-0">{{ 'company_proof.panel' | translate }}</p>
        <button mat-flat-button type="button" (click)="prove()" data-testid="company-proof-prove">
          <mat-icon aria-hidden="true">lock_open</mat-icon>
          {{ 'company_proof.prove' | translate }}
        </button>
      </section>
    }
  `,
})
export class CompanyProofPanel implements OnInit {
  protected readonly entry = inject(CompanyEntry);

  ngOnInit(): void {
    // Entering a company asks at once; turned down earlier in this sign-in, it waits for the button.
    void this.entry.prove();
  }

  protected prove(): void {
    void this.entry.prove(true);
  }
}
