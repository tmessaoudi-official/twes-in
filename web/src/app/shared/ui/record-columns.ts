// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, ViewEncapsulation } from '@angular/core';

/**
 * A record page's two tracks (design proposal § 5): the main one, its sections, and the summary, its figures. The page
 * box decides, in rem: wide enough, the summary stands at the inline end beside the main track and stays in view
 * while it scrolls; narrower (a phone, a rail, the page at 125 %), one column, the summary first. Arabic places it at
 * the left from the same grid.
 *
 * The summary is the element marked `recordSummary`, always there, its figures inside; everything else is the main
 * track. Unscoped, as its rules read what the page projects into it; every class it styles is its own.
 */
@Component({
  selector: 'app-record-columns',
  changeDetection: ChangeDetectionStrategy.OnPush,
  encapsulation: ViewEncapsulation.None,
  styleUrl: './record-columns.scss',
  template: `
    <div class="twes-record-columns">
      <div class="twes-record-summary"><ng-content select="[recordSummary]" /></div>
      <div class="twes-record-main"><ng-content /></div>
    </div>
  `,
})
export class RecordColumns {}
