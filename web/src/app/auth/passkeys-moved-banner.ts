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
import { MatIconModule } from '@angular/material/icon';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { TextsParts } from '../shared/i18n/texts-part';
import { AuthFacade } from './auth-facade';

/** The banner's own words, opened by the banner once it would show, so no other page carries them. */
export const PASSKEYS_MOVED_TEXTS = 'passkeys-moved';

/**
 * While the account holds a passkey made for another address than the installation's passkeys are bound to now (its
 * operator moved them to the Tailscale name, or back), every page says so: a state of the account, which a mail told
 * once, so a status line rather than a toast that leaves, with the way to add one again.
 */
@Component({
  selector: 'app-passkeys-moved-banner',
  imports: [MatIconModule, RouterLink, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (shown()) {
      <p
        role="status"
        class="m-0 mb-4 flex flex-wrap items-center gap-2 rounded-lg border border-outline-variant bg-surface-container-high px-4 py-2 text-on-surface"
        data-testid="passkeys-moved-banner"
      >
        <mat-icon aria-hidden="true">passkey</mat-icon>
        <span>{{ 'passkeys_moved.banner' | translate }}</span>
        <a
          class="ms-auto whitespace-nowrap"
          routerLink="/two-factor"
          data-testid="passkeys-moved-open"
          >{{ 'passkeys_moved.open' | translate }}</a
        >
      </p>
    }
  `,
})
export class PasskeysMovedBanner {
  private readonly auth = inject(AuthFacade);
  private readonly texts = inject(TextsParts);
  private readonly elsewhere = computed(() => (this.auth.me()?.mfa.passkeysElsewhere ?? 0) > 0);
  private readonly worded = signal(false);
  private opening = false;
  protected readonly shown = computed(() => this.worded() && this.elsewhere());

  constructor() {
    effect(() => {
      if (!this.elsewhere() || this.opening) return;
      this.opening = true;
      untracked(() => void this.openWords());
    });
  }

  private async openWords(): Promise<void> {
    await this.texts.open(PASSKEYS_MOVED_TEXTS);
    this.worded.set(true);
  }
}
