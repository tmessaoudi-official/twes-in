// SPDX-License-Identifier: AGPL-3.0-or-later

import { formatMonth } from '../shared/i18n/format';
import type { AgingAmount, AgingBucket, MonthCollected } from './invoices-types';

/** A bar of the payments chart: its height is a share of the best month, in percent. */
export interface CollectedBar {
  readonly month: string;
  readonly label: string;
  readonly amount: string;
  readonly height: number;
  /** The last month drawn, which is the company's current one. */
  readonly current: boolean;
}

/** A segment of the aging bar: its width is its share of everything still due, in percent. */
export interface AgingBar {
  readonly bucket: AgingBucket;
  readonly amount: string;
  readonly count: number;
  readonly share: number;
}

/** How an invoice to chase stands against its due day, as a translation key and a day count. */
export function chaseDue(daysLate: number): { key: string; days: number } {
  if (daysLate > 0) return { key: 'invoices.home.late', days: daysLate };
  if (daysLate === 0) return { key: 'invoices.home.due_today', days: 0 };
  return { key: 'invoices.home.due_in', days: -daysLate };
}

/**
 * The payments chart. Heights and widths are drawing proportions, never a figure a person reads: they go through
 * `Number`, while every amount shown stays the API's own string.
 */
export function collectedBars(
  collected: readonly MonthCollected[],
  locale: string,
): readonly CollectedBar[] {
  const best = Math.max(0, ...collected.map((month) => Number(month.amount)));
  return collected.map((month, index) => ({
    month: month.month,
    label: formatMonth(`${month.month}-01`, locale, 'short'),
    amount: month.amount,
    height: best > 0 ? Math.round((Number(month.amount) / best) * 100) : 0,
    current: index === collected.length - 1,
  }));
}

/** The aging bar's segments, the empty buckets left out. */
export function agingBars(aging: readonly AgingAmount[]): readonly AgingBar[] {
  const total = aging.reduce((sum, bucket) => sum + Number(bucket.amount), 0);
  if (total <= 0) return [];
  return aging
    .filter((bucket) => Number(bucket.amount) > 0)
    .map((bucket) => ({
      ...bucket,
      share: Math.round((Number(bucket.amount) / total) * 1000) / 10,
    }));
}

/** Up to two initials of a name, for the avatar beside it. */
export function initials(name: string): string {
  const letters = name
    .trim()
    .split(/\s+/)
    .filter((word) => word !== '')
    .slice(0, 2)
    .map((word) => word.charAt(0).toLocaleUpperCase());
  return letters.length === 0 ? '?' : letters.join('');
}

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
