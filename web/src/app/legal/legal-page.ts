// SPDX-License-Identifier: AGPL-3.0-or-later

import { Location } from '@angular/common';
import { ChangeDetectionStrategy, Component, computed, inject, input } from '@angular/core';
import { MatIconModule } from '@angular/material/icon';
import { Router, RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { SignedOutLayout } from '../auth/signed-out-layout';
import { isLegalPage } from '../shared/legal/legal-pages';
import { LegalText } from '../shared/legal/legal-text';

/**
 * One legal page at `/legal/<slug>`, open to anyone, signed in or not, outside the shell like the other public pages:
 * the address a legal text is shared by. Inside the app the same text opens over the screen instead (`LegalLink`).
 * « Retour » goes back to the screen the page was opened from, or home when it was opened directly; home sends a
 * signed-out visitor to sign in.
 */
@Component({
  selector: 'app-legal-page',
  imports: [LegalText, MatIconModule, RouterLink, SignedOutLayout, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <app-signed-out-layout plain>
      <!-- A reading column centred under the brand: the text wrapped at its own width on the left of a wider box. -->
      <article class="twes-legal-page mx-auto flex w-full max-w-[44rem] flex-col gap-4">
        <p>
          <a
            href="/"
            (click)="back($event)"
            class="inline-flex items-center gap-1"
            data-testid="legal-back"
          >
            <mat-icon aria-hidden="true">arrow_back</mat-icon>
            <span>{{ 'legal.back' | translate }}</span>
          </a>
        </p>
        @if (known()) {
          <h1 class="text-2xl font-semibold" data-testid="legal-title">
            {{ 'legal.pages.' + slug() | translate }}
          </h1>
          <app-legal-text [slug]="slug()" />
        } @else {
          <p data-testid="legal-unknown">
            {{ 'legal.unknown' | translate }}
            <a routerLink="/">{{ 'legal.home' | translate }}</a>
          </p>
        }
      </article>
    </app-signed-out-layout>
  `,
})
export class LegalPage {
  /** From the route, through `withComponentInputBinding`. */
  readonly slug = input.required<string>();
  protected readonly known = computed(() => isLegalPage(this.slug()));
  private readonly router = inject(Router);
  private readonly location = inject(Location);

  protected back(event: MouseEvent): void {
    event.preventDefault();
    // The navigation that opened this page names the one before it: none when the page was the first one loaded.
    if (this.router.lastSuccessfulNavigation()?.previousNavigation) {
      this.location.back();
    } else {
      void this.router.navigateByUrl('/');
    }
  }
}
