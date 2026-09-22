// SPDX-License-Identifier: AGPL-3.0-or-later

import { addScanned, barcodeKey, rowProblem, sameCodes, withRole } from './barcode-rows';
import type { ProductBarcode } from './products-types';

const unit = (code: string): ProductBarcode => ({
  role: 'unit',
  code,
  quantity: 1,
  supplierId: null,
});

describe('barcode rows', () => {
  it('keys the spellings of one GTIN alike and anything else as printed', () => {
    // Published examples, never this code's own output: a UPC-A, its EAN-13 spelling and its GTIN-14 spelling.
    expect(barcodeKey('036000291452')).toBe('00036000291452');
    expect(barcodeKey('0036000291452')).toBe('00036000291452');
    expect(barcodeKey(' 00036000291452 ')).toBe('00036000291452');
    // A misread check digit is not a GTIN, so it is its own key and finds nothing.
    expect(barcodeKey('036000291453')).toBe('036000291453');
    expect(barcodeKey('ABC-1')).toBe('ABC-1');
  });

  it('adds a scanned code as the unit code first, then as an internal one, and refuses one already listed', () => {
    const first = addScanned([], ' 3017620422003 ');
    expect(first).toEqual({ rows: [unit('3017620422003')], duplicate: null });

    const second = addScanned(first.rows, 'X-1');
    expect(second.rows[1]).toEqual({
      role: 'internal',
      code: 'X-1',
      quantity: 1,
      supplierId: null,
    });

    // The EAN-13 of a code already there, spelled with its leading zero, is the same code.
    const again = addScanned(second.rows, '03017620422003');
    expect(again).toEqual({ rows: second.rows, duplicate: 0 });
    expect(addScanned(second.rows, '   ')).toEqual({ rows: second.rows, duplicate: null });
  });

  it('gives each role the quantity and supplier it allows', () => {
    const row: ProductBarcode = { role: 'supplier', code: 'S-1', quantity: 6, supplierId: 'v1' };

    expect(withRole(row, 'unit')).toEqual({
      role: 'unit',
      code: 'S-1',
      quantity: 1,
      supplierId: null,
    });
    expect(withRole(unit('P-1'), 'pack')).toEqual({
      role: 'pack',
      code: 'P-1',
      quantity: 2,
      supplierId: null,
    });
    expect(withRole(row, 'pack').quantity).toBe(6);
    expect(withRole(row, 'internal').supplierId).toBeNull();
    expect(withRole(row, 'supplier').supplierId).toBe('v1');
  });

  it('names what is wrong with a row before the API is asked', () => {
    expect(rowProblem(unit('3017620422003'))).toBeNull();
    expect(rowProblem(unit(''))).toBe('code_required');
    expect(rowProblem(unit('30176 20422003'))).toBe('code_shape');
    expect(rowProblem(unit('3017620422004'))).toBe('check_digit');
    expect(rowProblem({ ...unit('10012345678903'), role: 'pack', quantity: 12 })).toBe(
      'check_digit',
    );
    expect(rowProblem({ ...unit('P-1'), role: 'pack', quantity: 1 })).toBe('pack_many');
    expect(rowProblem({ ...unit('P-1'), role: 'supplier' })).toBe('supplier_required');
    expect(rowProblem({ ...unit('P-1'), quantity: 0, role: 'internal' })).toBe('quantity_range');
  });

  it('compares two lists as sets, order aside', () => {
    const pack: ProductBarcode = {
      role: 'pack',
      code: '10012345678902',
      quantity: 12,
      supplierId: null,
    };

    expect(sameCodes([unit('A'), pack], [pack, unit('A')])).toBe(true);
    expect(sameCodes([unit('A')], [unit('A'), pack])).toBe(false);
    expect(sameCodes([unit('A')], [{ ...unit('A'), role: 'internal' }])).toBe(false);
  });
});
