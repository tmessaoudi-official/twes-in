// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * One-dimensional barcodes for printed labels (docs/SPEC.md § 7, 2026-09-23 slice 8), built here from the published
 * symbologies (ISO/IEC 15420 for EAN/UPC, ISO/IEC 15417 for Code 128) rather than taken from a library: a few tables
 * and a checksum each. A module string is the symbol from its first bar to its last, "1" a dark module, "0" a light
 * one; the quiet zone around it is the drawing's to add.
 */
export interface LinearBarcode {
  symbology: 'ean-13' | 'ean-8' | 'code-128';
  modules: string;
}

/**
 * The widths of each Code 128 symbol, by value, bar first: three bars and three spaces over eleven modules, and the
 * stop's extra bar at 106.
 */
// prettier-ignore
export const CODE128_WIDTHS: readonly string[] = [
  '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
  '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
  '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
  '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
  '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
  '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
  '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
  '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
  '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
  '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
  '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
];

const START_B = 104;
const START_C = 105;
const STOP = 106;

/** EAN's left-hand odd-parity set (A, or "L"); set C is its complement and set B its complement reversed. */
const EAN_A = [
  '0001101',
  '0011001',
  '0010011',
  '0111101',
  '0100011',
  '0110001',
  '0101111',
  '0111011',
  '0110111',
  '0001011',
];
/** Which of the left half's six digits take set B, by an EAN-13's first digit, which is carried by nothing else. */
const EAN13_PARITY = [
  'AAAAAA',
  'AABABB',
  'AABBAB',
  'AABBBA',
  'ABAABB',
  'ABBAAB',
  'ABBBAA',
  'ABABAB',
  'ABABBA',
  'ABBABA',
];

/** The barcode a label prints for a code: EAN when it is a valid GTIN, Code 128 otherwise; null when neither fits. */
export function linearBarcode(value: string): LinearBarcode | null {
  if (/^\d{12,13}$/.test(value) && gtinValid(value)) {
    return { symbology: 'ean-13', modules: ean13(value.padStart(13, '0')) };
  }
  if (/^\d{8}$/.test(value) && gtinValid(value)) {
    return { symbology: 'ean-8', modules: ean8(value) };
  }
  const modules = code128(value);
  return modules === null ? null : { symbology: 'code-128', modules };
}

function gtinValid(digits: string): boolean {
  const body = digits.slice(0, -1);
  let sum = 0;
  for (let i = 0; i < body.length; i += 1) {
    sum += Number(body[body.length - 1 - i]) * (i % 2 === 0 ? 3 : 1);
  }
  return (10 - (sum % 10)) % 10 === Number(digits[digits.length - 1]);
}

function eanSet(digit: string, set: 'A' | 'B' | 'C'): string {
  const a = EAN_A[Number(digit)];
  const c = [...a].map((module) => (module === '1' ? '0' : '1')).join('');
  return set === 'A' ? a : set === 'C' ? c : [...c].reverse().join('');
}

function ean13(digits: string): string {
  const parity = EAN13_PARITY[Number(digits[0])];
  const left = [...digits.slice(1, 7)].map((digit, i) => eanSet(digit, parity[i] as 'A' | 'B'));
  const right = [...digits.slice(7)].map((digit) => eanSet(digit, 'C'));
  return `101${left.join('')}01010${right.join('')}101`;
}

function ean8(digits: string): string {
  const left = [...digits.slice(0, 4)].map((digit) => eanSet(digit, 'A'));
  const right = [...digits.slice(4)].map((digit) => eanSet(digit, 'C'));
  return `101${left.join('')}01010${right.join('')}101`;
}

/** Set C for an even run of digits, two a symbol; set B for printable ASCII; null for anything else. */
function code128(text: string): string | null {
  if (text === '') return null;
  let values: number[];
  if (/^(\d\d)+$/.test(text)) {
    values = [START_C, ...(text.match(/\d\d/g) ?? []).map(Number)];
  } else if (/^[\x20-\x7e]+$/.test(text)) {
    values = [START_B, ...[...text].map((character) => character.charCodeAt(0) - 32)];
  } else {
    return null;
  }
  const checksum =
    values.reduce((sum, value, position) => sum + value * Math.max(position, 1), 0) % 103;
  return [...values, checksum, STOP]
    .map((value) => widthsToModules(CODE128_WIDTHS[value]))
    .join('');
}

function widthsToModules(widths: string): string {
  return [...widths].map((width, i) => (i % 2 === 0 ? '1' : '0').repeat(Number(width))).join('');
}
