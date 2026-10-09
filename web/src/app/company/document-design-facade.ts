// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import { DocumentDesignApi, PreviewRefused } from './document-design-api';
import type { DocumentDesign, PreviewState } from './document-design-types';

/**
 * The preview of a design while it is chosen: the latest picture asked for, and what became of it. The picture of a
 * design asked for earlier never replaces one asked for later, however the answers arrive.
 */
@Injectable({ providedIn: 'root' })
export class DocumentDesignFacade {
  private readonly api = inject(DocumentDesignApi);
  private readonly pictureSignal = signal<string | null>(null);
  private readonly stateSignal = signal<PreviewState>('loading');
  private readonly logoRatioSignal = signal<number | null>(null);
  private asked = 0;

  /** The last picture shown, kept while the next one is on its way. */
  readonly picture = this.pictureSignal.asReadonly();
  readonly state = this.stateSignal.asReadonly();
  /** The logo's width over its height, which a locked size follows; null without a logo. */
  readonly logoRatio = this.logoRatioSignal.asReadonly();

  async loadLogoRatio(companyId: string): Promise<void> {
    this.logoRatioSignal.set(await this.api.logoRatio(companyId));
  }

  /** No picture, and why: what the page says when it has no design to ask for. Drops any answer still on its way. */
  without(why: Exclude<PreviewState, 'loading' | 'ready'>): void {
    this.asked++;
    this.pictureSignal.set(null);
    this.stateSignal.set(why);
  }

  async preview(companyId: string, design: DocumentDesign): Promise<void> {
    const ask = ++this.asked;
    this.stateSignal.set('loading');
    try {
      const picture = await this.api.preview(companyId, design);
      if (ask !== this.asked) return;
      this.pictureSignal.set(picture);
      this.stateSignal.set('ready');
    } catch (error) {
      if (ask !== this.asked) return;
      this.pictureSignal.set(null);
      this.stateSignal.set(error instanceof PreviewRefused ? error.state : 'failed');
    }
  }
}
