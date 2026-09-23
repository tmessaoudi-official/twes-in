// SPDX-License-Identifier: AGPL-3.0-or-later

import { describe, expect, it } from 'vitest';
import { CODE128_WIDTHS, linearBarcode } from './linear-barcode';

/** The widths of each run of equal modules, bar first: what a scanner actually measures. */
function runs(modules: string): number[] {
  return (modules.match(/1+|0+/g) ?? []).map((run) => run.length);
}

describe('linearBarcode', () => {
  it('draws a valid GTIN-13 as EAN-13: 95 modules, guards at both ends and in the middle', () => {
    const code = linearBarcode('4006381333931');
    expect(code?.symbology).toBe('ean-13');
    expect(code?.modules).toHaveLength(95);
    expect(code?.modules.slice(0, 3)).toBe('101');
    expect(code?.modules.slice(45, 50)).toBe('01010');
    expect(code?.modules.slice(92)).toBe('101');
    // The first digit (4) is carried by the parity of the next six: odd, even, odd, odd, even, even for 4.
    // Digit 0 in set A is 0001101, in set B 0100111; the second digit here is 0, in set A.
    expect(code?.modules.slice(3, 10)).toBe('0001101');
    // The right half is set C, the complement of A: the last digit, 1, is 1100110.
    expect(code?.modules.slice(85, 92)).toBe('1100110');
  });

  it('draws a UPC-A as the EAN-13 it is, and a GTIN-8 as EAN-8', () => {
    expect(linearBarcode('036000291452')).toEqual(linearBarcode('0036000291452'));
    const eight = linearBarcode('96385074');
    expect(eight?.symbology).toBe('ean-8');
    expect(eight?.modules).toHaveLength(67);
  });

  it('refuses a wrong check digit as an EAN and draws the text as Code 128 instead', () => {
    const code = linearBarcode('4006381333932');
    expect(code?.symbology).toBe('code-128');
  });

  it('draws text as Code 128 set B: start, one symbol per character, the checksum and stop', () => {
    const code = linearBarcode('PJJ123C')!;
    expect(code.symbology).toBe('code-128');
    // 11 modules a symbol: start + 7 characters + checksum, then a 13-module stop.
    expect(code.modules).toHaveLength(11 * 9 + 13);
    const widths = runs(code.modules);
    expect(widths.slice(0, 6)).toEqual([2, 1, 1, 2, 1, 4]); // start B
    expect(widths.slice(-7)).toEqual([2, 3, 3, 1, 1, 1, 2]); // stop
    // Checksum: 104 + Σ position × value, mod 103. P=48 J=42 J=42 1=17 2=18 3=19 C=35.
    const sum = 104 + 48 * 1 + 42 * 2 + 42 * 3 + 17 * 4 + 18 * 5 + 19 * 6 + 35 * 7;
    expect(widths.slice(6 * 8, 6 * 9)).toEqual(CODE128_WIDTHS[sum % 103].split('').map(Number));
  });

  it('draws an even run of digits in Code 128 set C, two digits a symbol', () => {
    const code = linearBarcode('12345678')!;
    expect(code.symbology).toBe('code-128');
    expect(code.modules).toHaveLength(11 * 6 + 13);
    expect(runs(code.modules).slice(0, 6)).toEqual([2, 1, 1, 2, 3, 2]); // start C
  });

  it('keeps every Code 128 symbol at eleven modules, three bars and three spaces, all different', () => {
    expect(CODE128_WIDTHS).toHaveLength(107);
    for (const widths of CODE128_WIDTHS.slice(0, 106)) {
      expect(widths).toMatch(/^[1-4]{6}$/);
      expect([...widths].reduce((sum, width) => sum + Number(width), 0)).toBe(11);
    }
    expect(new Set(CODE128_WIDTHS).size).toBe(107);
    expect(CODE128_WIDTHS[106]).toBe('2331112');
  });

  it('draws the modules an independent Code 128 encoder draws for the same text', () => {
    // Computed apart from this code, from the ISO/IEC 15417 tables, and read back by zxing in the e2e.
    expect(linearBarcode('REF-MUEJ50GU')?.modules).toBe(
      '11010010000110001011101000110100010001100010100110111001011101100011011101110100011010001011011100011011100100100111011001101000100011011101110111100010101100011101011',
    );
  });

  it('draws nothing it cannot encode: an empty value, or a character outside printable ASCII', () => {
    expect(linearBarcode('')).toBeNull();
    expect(linearBarcode('Café')).toBeNull();
  });
});
