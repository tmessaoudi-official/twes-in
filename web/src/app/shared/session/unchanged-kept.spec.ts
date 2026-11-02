// SPDX-License-Identifier: AGPL-3.0-or-later

import { unchangedKept } from './unchanged-kept';

describe('unchangedKept', () => {
  const previous = {
    user: { id: '1', roles: ['owner'] },
    company: { id: 'c1', name: 'Demo' },
    modules: ['a'],
  };

  it('hands the previous value back when the next is equal to it', () => {
    expect(unchangedKept(previous, structuredClone(previous))).toBe(previous);
  });

  it('keeps each unchanged part and takes the changed ones', () => {
    const next = { ...structuredClone(previous), modules: ['a', 'b'] };
    const kept = unchangedKept(previous, next);

    expect(kept).not.toBe(previous);
    expect(kept).toEqual(next);
    expect(kept.company).toBe(previous.company);
    expect(kept.user).toBe(previous.user);
    expect(kept.modules).toEqual(['a', 'b']);
  });

  it('takes a part whose field changed, was added or was removed', () => {
    const renamed = unchangedKept(previous, {
      ...structuredClone(previous),
      company: { id: 'c1', name: 'X' },
    });
    expect(renamed.company).not.toBe(previous.company);
    expect(renamed.user).toBe(previous.user);

    const added = unchangedKept(previous.company, {
      id: 'c1',
      name: 'Demo',
      logo: null,
    } as typeof previous.company);
    expect(added).not.toBe(previous.company);
    expect(added).toEqual({ id: 'c1', name: 'Demo', logo: null });

    const removed = unchangedKept(previous.company, { id: 'c1' } as typeof previous.company);
    expect(removed).toEqual({ id: 'c1' });
  });

  it('takes a shorter or longer list, and null in place of an object', () => {
    expect(unchangedKept(['a', 'b'], ['a'])).toEqual(['a']);
    expect(unchangedKept<{ id: string } | null>({ id: 'c1' }, null)).toBeNull();
    expect(unchangedKept<{ id: string } | null>(null, { id: 'c1' })).toEqual({ id: 'c1' });
  });
});
