// SPDX-License-Identifier: AGPL-3.0-or-later

import { SESSION_KINDS, withSessionKinds } from './session-kinds';

describe('withSessionKinds', () => {
  it('adds every change of the session to what a list reads, each kind once', () => {
    const kinds = withSessionKinds(['invoice', 'module']);

    expect(kinds).toEqual(expect.arrayContaining(['invoice', ...SESSION_KINDS]));
    expect(new Set(kinds).size).toBe(kinds.length);
  });
});
