// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { ApiStatus, HealthApi } from './health-api';

export type HealthStatus = ApiStatus | 'checking';

/** The G0 status line lives on: the one page an unauthenticated visitor sees says whether the API is up. */
@Injectable({ providedIn: 'root' })
export class HealthFacade {
  readonly status = toSignal<HealthStatus, 'checking'>(inject(HealthApi).status(), {
    initialValue: 'checking',
  });
}
