// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpErrorResponse } from '@angular/common/http';
import { stepUpAsked } from './step-up-api';

const refused = (status: number, error: unknown) => new HttpErrorResponse({ status, error });

// docs/SPEC.md § 7, the step-up list: the API holds a change back until the person proves who they are again, in the
// one of two shapes its edge answers with.
describe('stepUpAsked', () => {
  it('reads the token API Platform leads a refusal’s detail with', () => {
    expect(
      stepUpAsked(refused(403, { detail: 'step_up_required: confirm the password again.' })),
    ).toBe(true);
  });

  it('reads the code a plain controller or the platform’s wall answers', () => {
    expect(stepUpAsked(refused(403, { error: 'step_up_required' }))).toBe(true);
  });

  it('reads nothing else as asking for the proof', () => {
    expect(stepUpAsked(refused(403, { error: 'owner_only' }))).toBe(false);
    expect(stepUpAsked(refused(403, { detail: 'Access Denied.' }))).toBe(false);
    expect(stepUpAsked(refused(422, { error: 'step_up_required' }))).toBe(false);
    expect(stepUpAsked(refused(0, null))).toBe(false);
    expect(stepUpAsked(new Error('step_up_required'))).toBe(false);
  });
});
