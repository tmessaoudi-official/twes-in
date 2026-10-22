// SPDX-License-Identifier: AGPL-3.0-or-later

import { computed, DestroyRef, DOCUMENT, inject, Injectable, signal } from '@angular/core';
import { MatDialog } from '@angular/material/dialog';
import { Brand } from '../brand/brand';
import { Feedback } from '../feedback/feedback';
import { TextsParts } from '../i18n/texts-part';
import { INSTALL_TEXTS } from './install-texts';

/** What Chrome and Edge hand the page when it may be installed; TypeScript's DOM library does not declare it. */
interface BeforeInstallPromptEvent extends Event {
  prompt(): Promise<void>;
}

/**
 * How this browser installs the application: its own dialog (Chrome, Edge, on a computer or Android), or two steps
 * in Safari's own menus on an iPhone or an iPad, which offers no dialog a page may open.
 */
export type InstallOffer = 'prompt' | 'guide';

/**
 * Offering to install the application on a computer or a phone (docs/SPEC.md § 7, the install offer). Listens from the
 * start of the page, since the browser says once, early, that it may be installed; offers nothing where it is installed
 * already or where the browser cannot install it, so no menu entry ever answers nothing. Eager, in `app.config.ts`.
 */
@Injectable({ providedIn: 'root' })
export class AppInstall {
  private readonly window = inject(DOCUMENT).defaultView;
  private readonly brand = inject(Brand);
  private readonly feedback = inject(Feedback);
  private readonly dialog = inject(MatDialog);
  private readonly texts = inject(TextsParts);
  /** The browser's offer, kept to open its dialog when the person asks; used once, as the browser allows. */
  private readonly deferred = signal<BeforeInstallPromptEvent | null>(null);
  private readonly installed = signal(this.runsInstalled());

  readonly offer = computed((): InstallOffer | null => {
    if (this.installed()) return null;
    if (this.deferred() !== null) return 'prompt';
    return this.safariOnIos() ? 'guide' : null;
  });

  constructor() {
    const offered = (event: Event): void => {
      // Kept rather than shown: the browser's own bar on a phone would offer it before the person reads the page.
      event.preventDefault();
      this.deferred.set(event as BeforeInstallPromptEvent);
    };
    // Said whichever way it was installed, from here or from the browser's own menu.
    const installed = (): void => {
      this.deferred.set(null);
      this.installed.set(true);
      this.feedback.success('shell.install.installed', { name: this.brand.name() });
    };
    this.window?.addEventListener('beforeinstallprompt', offered);
    this.window?.addEventListener('appinstalled', installed);
    inject(DestroyRef).onDestroy(() => {
      this.window?.removeEventListener('beforeinstallprompt', offered);
      this.window?.removeEventListener('appinstalled', installed);
    });
  }

  /** Opens the browser's dialog, called straight from the click or key that asked, as the browser requires. */
  install(): void {
    const offered = this.deferred();
    if (offered !== null) {
      this.deferred.set(null);
      offered.prompt().catch(() => {
        this.deferred.set(offered);
        this.feedback.failure('shell.install.failed');
      });
      return;
    }
    if (this.offer() === 'guide') void this.guide();
  }

  private async guide(): Promise<void> {
    await this.texts.open(INSTALL_TEXTS);
    const { InstallGuide } = await import('./install-guide');
    this.dialog.open(InstallGuide, { autoFocus: 'dialog' });
  }

  private runsInstalled(): boolean {
    const standalone = this.window?.matchMedia?.('(display-mode: standalone)').matches ?? false;
    return standalone || this.iosNavigator()?.standalone === true;
  }

  /** Safari on an iPhone or an iPad: the one browser that tells a page whether it runs from the home screen. */
  private safariOnIos(): boolean {
    return this.iosNavigator()?.standalone === false;
  }

  private iosNavigator(): { standalone?: boolean } | undefined {
    return this.window?.navigator as { standalone?: boolean } | undefined;
  }
}
