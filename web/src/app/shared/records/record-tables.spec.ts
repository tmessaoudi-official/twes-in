// SPDX-License-Identifier: AGPL-3.0-or-later

import { tableCounts } from './record-tables';

describe('tableCounts', () => {
  it('counts a table the screens do not word among the other records, once, and leaves out what counts nothing', () => {
    expect(
      tableCounts([
        { table: 'contact', count: 2 },
        { table: 'audit_trail', count: 1 },
        { table: 'nobody_knows', count: 3 },
        { table: 'setting', count: 0 },
      ]),
    ).toEqual([
      { key: 'records.tables.contact', count: 2 },
      { key: 'records.tables.other', count: 4 },
    ]);
  });
});
