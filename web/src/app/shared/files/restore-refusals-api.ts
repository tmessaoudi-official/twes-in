// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpErrorResponse } from '@angular/common/http';
import type { Injector } from '@angular/core';
import type { FieldRefusal } from '../form/field-refusals';
import { formRefusalsIn } from '../form/form-refusals-api';

/**
 * What a file put back where it was is refused for, read alike by every record that keeps files. A 422 is the record
 * full: its reason's words are an upload's (« before adding another »), so it is set aside and the restore's own code
 * says that this file cannot come back. A 409 names the move the record refuses (an expense recorded since), read as
 * every save's refusal is. `scripts/gates/restore-refusals.sh` holds each reader to it.
 */
export async function restoreRefusalsIn(
  injector: Injector,
  error: unknown,
): Promise<readonly FieldRefusal[]> {
  if (error instanceof HttpErrorResponse && error.status === 422) return [];
  return formRefusalsIn(injector, error);
}
