// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import { LiveChanges } from '../shared/realtime/live-changes';
import { DataErasureApi, ErasureRefused, type Erasure } from './data-erasure-api';

/**
 * The company's erasure that may still be undone: what the banner « Annuler l'effacement » offers on every page, and
 * what « Effacer » waits for. Only an owner reads it; the API refuses anybody else.
 */
@Injectable({ providedIn: 'root' })
export class PendingErasure {
  private readonly api = inject(DataErasureApi);
  private readonly live = inject(LiveChanges);
  private readonly erasureSignal = signal<Erasure | null>(null);
  readonly erasure = this.erasureSignal.asReadonly();

  /**
   * Reads it again. A banner that could not be read keeps what it showed: the network coming back, or the next
   * change, reads it again.
   */
  async load(companyId: string): Promise<void> {
    try {
      this.erasureSignal.set(await this.api.pending(companyId));
    } catch (error) {
      if (!(error instanceof ErasureRefused) || error.code !== 'network') {
        this.erasureSignal.set(null);
      }
    }
  }

  /** What another read of it answered, such as the page's counts. */
  shown(erasure: Erasure | null): void {
    this.erasureSignal.set(erasure);
  }

  /** An erasure this tab just made: shown at once, and the screens of this tab told what went. */
  erased(erasure: Erasure): void {
    this.erasureSignal.set(erasure);
    this.live.changedHere(erasure.kinds, 'data.erased');
  }

  /** @throws ErasureRefused when it can no longer be undone, or something made since stands in its way */
  async undo(companyId: string): Promise<Erasure | null> {
    const erasure = this.erasureSignal();
    if (erasure === null) return null;
    try {
      const undone = await this.api.undo(companyId, erasure.id);
      this.erasureSignal.set(null);
      this.live.changedHere(undone.kinds, 'data.erasure_undone');
      return undone;
    } catch (error) {
      if (error instanceof ErasureRefused && error.code === 'erasure_final') {
        this.erasureSignal.set(null);
      }
      throw error;
    }
  }
}
