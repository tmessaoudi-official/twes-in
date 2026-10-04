// SPDX-License-Identifier: AGPL-3.0-or-later

/** An amount against another in exact decimals, never through `Number`: which way it moved and by how much. */
export function versus(
  current: string,
  previous: string,
): { kind: 'up' | 'down' | 'same'; amount: string } {
  const scale = Math.max(fractionDigits(current), fractionDigits(previous));
  const gap = scaled(current, scale) - scaled(previous, scale);
  const size = (gap < 0n ? -gap : gap).toString().padStart(scale + 1, '0');
  const amount = scale === 0 ? size : `${size.slice(0, -scale)}.${size.slice(-scale)}`;
  return { kind: gap > 0n ? 'up' : gap < 0n ? 'down' : 'same', amount };
}

function fractionDigits(value: string): number {
  return value.split('.')[1]?.length ?? 0;
}

function scaled(value: string, scale: number): bigint {
  const negative = value.startsWith('-');
  const [whole, fraction = ''] = value.replace('-', '').split('.');
  const units = BigInt(whole + fraction.padEnd(scale, '0'));
  return negative ? -units : units;
}
