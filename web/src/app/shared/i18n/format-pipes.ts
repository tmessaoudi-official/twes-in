// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Pipe, type PipeTransform } from '@angular/core';
import { FormatFacade } from './format-facade';

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

/** `{{ entry.createdAt | moment }}`: a moment's day and time in the viewer's time zone; no value shows nothing. */
@Pipe({ name: 'moment', pure: false })
export class MomentPipe implements PipeTransform {
  private readonly format = inject(FormatFacade);

  transform(value: string | null | undefined): string {
    return value === null || value === undefined ? '' : this.format.moment(value);
  }
}
