// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Pipe, type PipeTransform } from '@angular/core';
import { FormatFacade } from './format-facade';
import type { SignedUnit } from './format';

// The pipes are impure so a change of language or company re-formats what is on screen: a pure pipe keeps its
// last result while its arguments stay the same.

/** `{{ note.total | amount: scale }}`: an amount as the working locale writes it; no value shows nothing. */
@Pipe({ name: 'amount', pure: false })
export class AmountPipe implements PipeTransform {
  private readonly format = inject(FormatFacade);

  transform(value: string | null | undefined, scale: number | null | undefined): string {
    return value === null || value === undefined ? '' : this.format.amount(value, scale ?? null);
  }
}

/** `{{ note.issueDate | day }}`: a calendar day as the working locale writes it; no value shows nothing. */
@Pipe({ name: 'day', pure: false })
export class DayPipe implements PipeTransform {
  private readonly format = inject(FormatFacade);

  transform(value: string | null | undefined): string {
    return value === null || value === undefined ? '' : this.format.day(value);
  }
}

/**
 * `{{ entry.createdAt | moment }}`: a moment's day and time in the viewer's time zone, or in the one given
 * (`| moment: company.timezone`, what a document's own clock reads); no value shows nothing.
 */
@Pipe({ name: 'moment', pure: false })
export class MomentPipe implements PipeTransform {
  private readonly format = inject(FormatFacade);

  transform(value: string | null | undefined, timeZone?: string | null): string {
    return value === null || value === undefined
      ? ''
      : this.format.moment(value, timeZone ?? undefined);
  }
}

/**
 * `{{ rect.width | measure }}`: a measure as the screen writes a decimal (« 1,2 » in French), to the millimetre so a
 * size worked out while dragging never shows a float's tail; no value shows nothing.
 */
@Pipe({ name: 'measure', pure: false })
export class MeasurePipe implements PipeTransform {
  private readonly format = inject(FormatFacade);

  transform(value: number | string | null | undefined): string {
    if (value === null || value === undefined || value === '') return '';
    const number = Number(value);
    if (!Number.isFinite(number)) return String(value);

    // float: a measure on screen, rounded to the millimetre and never summed or stored from here.
    return this.format.decimal(String(Number(number.toFixed(3))));
  }
}

/**
 * `month.change | signed: 1`, drawn in a `<bdi dir="ltr">`: a change with its sign always written, « +6,2 % » or
 * « −3,1 % » (`| signed: 3 : 'figure'` for a plain figure); no value shows nothing. The template draws it in a
 * left-to-right `<bdi>`, so in Arabic the sign stays at its start and the percent at its end (value-direction.sh holds
 * every use to it).
 */
@Pipe({ name: 'signed', pure: false })
export class SignedPipe implements PipeTransform {
  private readonly format = inject(FormatFacade);

  transform(
    value: string | number | null | undefined,
    scale: number,
    unit: SignedUnit = 'percent',
  ): string {
    if (value === null || value === undefined) return '';
    if (typeof value === 'number') {
      if (!Number.isFinite(value)) return '';
      // float: a ratio worked out on screen, rounded here to what is shown and never summed or stored.
      return this.format.signed(value.toFixed(scale), scale, unit);
    }
    return this.format.signed(value, scale, unit);
  }
}
