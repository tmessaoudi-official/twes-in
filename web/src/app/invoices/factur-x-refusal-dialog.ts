// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule } from '@angular/material/dialog';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';
import { FormatFacade } from '../shared/i18n/format-facade';
import type { FacturXAnswer, FacturXGap } from './invoices-types';

export type FacturXRefusal = Extract<FacturXAnswer, { kind: 'refused' }>;

/**
 * Why an issued document has no Factur-X: each datum EN 16931 asks for and the document lacks, named so that it can be
 * filled in (the company's profile, the customer, a line's tax). The file is never written in part, since a validator
 * would refuse it.
 */
@Component({
  selector: 'app-factur-x-refusal-dialog',
  imports: [MatButtonModule, MatDialogModule, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title>{{ 'invoices.factur_x.refused_title' | translate }}</h2>
    <mat-dialog-content>
      <div role="alert" class="flex flex-col gap-2" data-testid="factur-x-refusal">
        <p>{{ 'invoices.factur_x.refused.' + refusal.code | translate: refusal.params }}</p>
        @if (refusal.gaps.length > 0) {
          <ul class="flex list-disc flex-col gap-1 ps-5">
            @for (gap of refusal.gaps; track $index) {
              <li class="text-sm" [attr.data-testid]="'factur-x-gap-' + gap.code">
                {{ 'invoices.factur_x.gaps.' + gap.code | translate: said(gap) }}
              </li>
            }
          </ul>
        }
      </div>
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-flat-button type="button" mat-dialog-close data-testid="factur-x-close">
        {{ 'invoices.factur_x.close' | translate }}
      </button>
    </mat-dialog-actions>
  `,
})
export class FacturXRefusalDialog {
  protected readonly refusal = inject<FacturXRefusal>(MAT_DIALOG_DATA);
  private readonly translate = inject(TranslateService);
  private readonly format = inject(FormatFacade);

  /** A gap's parameters as a sentence takes them: the parts of an address named, a list of tax codes joined. */
  protected said(gap: FacturXGap): Record<string, string | number> {
    const said: Record<string, string | number> = {};
    for (const [name, value] of Object.entries(gap.params)) {
      if (name === 'rate' && typeof value === 'string') {
        // A rate arrives as the API keeps it (20.000), and is said as a person writes it (20, or 5,5).
        said[name] = this.format.decimal(value.includes('.') ? value.replace(/\.?0+$/, '') : value);
      } else if (typeof value === 'string' || typeof value === 'number') {
        said[name] = value;
      } else if (name === 'missing') {
        said[name] = value
          .map((part) => this.translate.instant(`invoices.factur_x.parts.${part}`))
          .join(', ');
      } else {
        said[name] = value.join(', ');
      }
    }
    return said;
  }
}
