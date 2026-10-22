// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  signal,
  untracked,
} from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatIconModule } from '@angular/material/icon';
import { TranslatePipe } from '@ngx-translate/core';
import { Brand } from '../shared/brand/brand';
import { TextsParts } from '../shared/i18n/texts-part';
import { AppInstall } from '../shared/install/app-install';
import { INSTALL_TEXTS } from '../shared/install/install-texts';
import { WINDOW_CLASS } from '../shared/ui/window-class';
import { INSTALL_STORAGE, installPutAside, putInstallAside } from './install-later';

/**
 * The offer to install the application, once, on a phone's home (docs/SPEC.md § 7, the install offer): only where the
 * browser can install it and it is not installed yet, gone for good on this phone once put aside. The account menu
 * and Ctrl K offer it on every width.
 */
@Component({
  selector: 'app-install-home',
  imports: [MatButtonModule, MatCardModule, MatIconModule, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (shown()) {
      <mat-card appearance="outlined" data-testid="home-install">
        <mat-card-content>
          <div class="flex flex-col gap-3">
            <div class="flex items-center gap-3">
              <mat-icon aria-hidden="true">install_mobile</mat-icon>
              <h2 class="text-section">
                {{ 'install.card.title' | translate: { name: brand.name() } }}
              </h2>
            </div>
            <p class="text-on-surface-variant">{{ 'install.card.text' | translate }}</p>
            <div class="flex flex-wrap justify-end gap-2">
              <button mat-button type="button" (click)="later()" data-testid="install-later">
                {{ 'install.card.later' | translate }}
              </button>
              <button
                mat-flat-button
                type="button"
                (click)="install.install()"
                data-testid="install-now"
              >
                {{ 'install.card.install' | translate }}
              </button>
            </div>
          </div>
        </mat-card-content>
      </mat-card>
    }
  `,
})
export class InstallHome {
  protected readonly install = inject(AppInstall);
  protected readonly brand = inject(Brand);
  private readonly windowClass = inject(WINDOW_CLASS);
  private readonly storage = inject(INSTALL_STORAGE);
  private readonly texts = inject(TextsParts);
  private readonly putAside = signal(installPutAside(this.storage));
  /** Whether the card would show, its words aside: the browser may hand its offer over after the home is drawn. */
  private readonly offered = computed(
    () => !this.putAside() && this.windowClass() === 'compact' && this.install.offer() !== null,
  );
  /** Its words, opened by the card itself once it would show (the home's route does not open them), and only then. */
  private readonly worded = signal(false);
  private opening = false;
  protected readonly shown = computed(() => this.worded() && this.offered());

  constructor() {
    effect(() => {
      if (!this.offered() || this.opening) return;
      this.opening = true;
      untracked(() => void this.openWords());
    });
  }

  private async openWords(): Promise<void> {
    await this.texts.open(INSTALL_TEXTS);
    this.worded.set(true);
  }

  protected later(): void {
    putInstallAside(this.storage);
    this.putAside.set(true);
  }
}
