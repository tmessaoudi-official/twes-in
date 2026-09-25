// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import { WatchApi } from './watch-api';
import type { WatchError, WatchList } from './watch-types';

/** « À surveiller » as signals: the page and the home's count read the same list. */
@Injectable({ providedIn: 'root' })
export class WatchFacade {
  private readonly api = inject(WatchApi);
  private readonly listSignal = signal<WatchList | null>(null);
  private readonly errorSignal = signal<WatchError | null>(null);

  readonly list = this.listSignal.asReadonly();
  readonly error = this.errorSignal.asReadonly();

  /** Reads the list again; what was shown stays until the answer replaces it, so a reload does not flicker. */
  async load(companyId: string): Promise<void> {
    try {
      this.listSignal.set(await this.api.list(companyId));
      this.errorSignal.set(null);
    } catch {
      this.errorSignal.set('unavailable');
    }
  }
}
