// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpErrorResponse } from '@angular/common/http';
import { refusalKey, UNKNOWN_REFUSAL } from './unknown-refusal';
import { isUnreachable, unknownRefusal, unnamedRefusal } from './unknown-refusal-api';

const answer = (status: number, body: unknown) =>
  new HttpErrorResponse({ status, statusText: String(status), error: body });

describe('an answer a reader does not know', () => {
  it('is named by its {error} code, else its first refusal, else its status', () => {
    expect(unknownRefusal(answer(422, { error: 'brand_new' }))).toEqual({ unknown: 'brand_new' });
    expect(
      unknownRefusal(
        answer(422, {
          violations: [{ propertyPath: 'x', message: 'm', payload: { reason: 'too_long' } }],
        }),
      ),
    ).toEqual({ unknown: 'too_long' });
    expect(unknownRefusal(answer(500, null))).toEqual({ unknown: '500' });
    expect(unknownRefusal(answer(403, { error: '' }))).toEqual({ unknown: '403' });
  });

  it('is a lost connection only when nothing answered or a gateway answered for a restarting API', () => {
    expect(isUnreachable(new Error('offline'))).toBe(true);
    for (const status of [0, 502, 503, 504]) expect(isUnreachable(answer(status, null))).toBe(true);
    for (const status of [400, 403, 409, 422, 500]) {
      expect(isUnreachable(answer(status, null))).toBe(false);
    }
  });

  it("is said in the one sentence naming its code; a known code in the screen's own words", () => {
    expect(refusalKey('support_access.refused', { unknown: 'brand_new' })).toEqual({
      key: UNKNOWN_REFUSAL,
      params: { code: 'brand_new' },
    });
    expect(refusalKey('support_access.refused', 'owner_only')).toEqual({
      key: 'support_access.refused.owner_only',
      params: {},
    });
  });
});

describe('a status a reader does not name', () => {
  it("is the reader's own word when what was sent was refused (400, 422), whatever the answer names", () => {
    expect(unnamedRefusal(answer(400, null))).toBe('invalid');
    expect(unnamedRefusal(answer(422, { error: 'brand_new' }))).toBe('invalid');
    expect(unnamedRefusal(answer(422, null), 'refused')).toBe('refused');
  });

  it('is any other answer named by its code, else its status, never the word', () => {
    expect(unnamedRefusal(answer(403, { error: 'brand_new' }), 'refused')).toEqual({
      unknown: 'brand_new',
    });
    expect(unnamedRefusal(answer(500, null))).toEqual({ unknown: '500' });
    expect(unnamedRefusal(answer(409, null))).toEqual({ unknown: '409' });
  });
});
