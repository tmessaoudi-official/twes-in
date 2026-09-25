// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type { FirstStepsFirstStepsRead } from '../api/types.gen';
import type { FirstSteps } from './first-steps-types';

/** The one importer of the generated types for « Premiers pas ». */
@Injectable({ providedIn: 'root' })
export class FirstStepsApi {
  private readonly http = inject(HttpClient);

  async read(companyId: string): Promise<FirstSteps> {
    const raw = await firstValueFrom(
      this.http.get<FirstStepsFirstStepsRead>(
        `/api/companies/${encodeURIComponent(companyId)}/first-steps`,
      ),
    );
    return {
      remaining: raw.remaining ?? 0,
      steps: (raw.steps ?? []).map((step) => ({ key: step.key, done: step.done })),
    };
  }
}
