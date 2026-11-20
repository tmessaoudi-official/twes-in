// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, Injector } from '@angular/core';
import type { EffectSayer, SaidEffect } from '../effects/effects';

/** The links a delete emptied, where the company has its deletes empty those (`RemoveRecords::LINKS_CLEARED`). */
export const LINKS_CLEARED = 'records.links_cleared';
/** The links a restore did not write back, set or gone since (`RestoreRecords::LINKS_NOT_RESTORED`). */
export const LINKS_NOT_RESTORED = 'records.links_not_restored';

/**
 * Says what a delete or a restore did to the records naming it, whichever screen sent it. Provided with the first page,
 * so it loads its words only when an answer first says one.
 */
@Injectable({ providedIn: 'root' })
export class RecordEffects implements EffectSayer {
  readonly kinds = [LINKS_CLEARED, LINKS_NOT_RESTORED] as const;
  private readonly injector = inject(Injector);

  async say(effects: readonly SaidEffect[]): Promise<void> {
    const { RecordEffectsSaid } = await import('./record-effects-said');
    await this.injector.get(RecordEffectsSaid).said(effects);
  }
}
