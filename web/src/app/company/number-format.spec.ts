// SPDX-License-Identifier: AGPL-3.0-or-later

import { renderNumber } from './number-format';

describe('renderNumber', () => {
  const september = new Date(2026, 8, 13);

  it('writes the year and a padded sequence', () => {
    expect(renderNumber('FAC-{YYYY}-{SEQ:5}', 42, september, '000')).toBe('FAC-2026-00042');
  });

  it('writes the short year, the month and an unpadded sequence', () => {
    expect(renderNumber('BL{YY}{MM}/{SEQ}', 7, new Date(2026, 0, 31), '000')).toBe('BL2601/7');
  });

  it("writes the issuing establishment's code", () => {
    expect(renderNumber('FAC-{EST}-{SEQ:3}', 4, september, '001')).toBe('FAC-001-004');
  });

  it('never cuts a sequence wider than its padding', () => {
    expect(renderNumber('AV-{SEQ:5}', 123456, september, '000')).toBe('AV-123456');
  });

  it.each([
    ['no sequence', 'FAC-{YYYY}'],
    ['two sequences', '{SEQ}-{SEQ:3}'],
    ['an unknown token', 'FAC-{DD}-{SEQ}'],
    ['a character a number cannot carry', 'FAC#{SEQ}'],
    ['no padding', '{SEQ:0}'],
    ['too much padding', '{SEQ:13}'],
    ['an unclosed brace', 'FAC-{SEQ'],
    ['blank', '   '],
    ['too long', `${'A'.repeat(60)}-{SEQ}`],
  ])('refuses a format with %s', (_, format) => {
    expect(renderNumber(format, 1, september, '000')).toBeNull();
  });

  it('refuses a sequence below one', () => {
    expect(renderNumber('FAC-{SEQ}', 0, september, '000')).toBeNull();
  });
});
