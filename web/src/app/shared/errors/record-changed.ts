// SPDX-License-Identifier: AGPL-3.0-or-later

/** The problem type the API answers a save of a version someone else saved since (`RecordChangedProblem`). */
export const RECORD_CHANGED = '/problems/record-changed';

/**
 * Whether a 409's body says the record was saved since it was opened. The same request answers 409 for its own
 * reasons too (a number another record has), so the status alone does not say it: the problem's type does.
 */
export function isRecordChanged(body: unknown): boolean {
  return (
    typeof body === 'object' && body !== null && 'type' in body && body.type === RECORD_CHANGED
  );
}
