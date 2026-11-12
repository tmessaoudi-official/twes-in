// SPDX-License-Identifier: AGPL-3.0-or-later

import { readPart, unreadOf } from './read-part';

class PartRefused extends Error {
  constructor(readonly code: string | { unknown: string }) {
    super('refused');
  }
}

const refusedCode = (error: unknown) => (error instanceof PartRefused ? error.code : null);

describe('readPart', () => {
  it('hands the part over when it was read', async () => {
    const read = await readPart(Promise.resolve(['a']), 'custom_fields', [], refusedCode);

    expect(read).toEqual({ value: ['a'], unread: null });
  });

  it('keeps the screen when the part is refused: the part is empty and named with its code', async () => {
    const read = await readPart(
      Promise.reject(new PartRefused('not_found')),
      'custom_fields',
      [],
      refusedCode,
    );

    expect(read).toEqual({ value: [], unread: { unread: 'custom_fields', code: 'not_found' } });
  });

  it('names a code the reader has no words for as the part too', async () => {
    const read = await readPart(
      Promise.reject(new PartRefused({ unknown: 'quota_spent' })),
      'customer_groups',
      [],
      refusedCode,
    );

    expect(read.unread).toEqual({ unread: 'customer_groups', code: 'quota_spent' });
  });

  it('fails the whole read on the network, as the record itself would', async () => {
    const lost = new PartRefused('network');

    await expect(readPart(Promise.reject(lost), 'custom_fields', [], refusedCode)).rejects.toBe(
      lost,
    );
  });

  it('fails the whole read on anything that is not the part refused', async () => {
    const broken = new TypeError('x is undefined');

    await expect(readPart(Promise.reject(broken), 'custom_fields', [], refusedCode)).rejects.toBe(
      broken,
    );
  });

  it('lists the parts not read, in the order the screen reads them', () => {
    const fields = { value: [], unread: { unread: 'custom_fields' as const, code: 'a' } };
    const groups = { value: [], unread: null };
    const locations = { value: [], unread: { unread: 'stock_locations' as const, code: 'b' } };

    expect(unreadOf(fields, groups, locations)).toEqual([fields.unread, locations.unread]);
  });
});
