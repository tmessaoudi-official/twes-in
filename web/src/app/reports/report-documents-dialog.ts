// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject, type OnInit, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AmountPipe, DayPipe } from '../shared/i18n/format-pipes';
import { documentLink } from './report-view';
import { ReportsFacade } from './reports-facade';
import type { ReportDocument, ReportDocuments, ReportQuery } from './reports-types';

export interface ReportDocumentsData {
  readonly companyId: string;
  readonly key: string;
  readonly query: ReportQuery;
  readonly row: string;
  /** The row's name as the report shows it, for the title. */
  readonly label: string;
}

/**
 * The documents behind one row, for a row no list of the app can narrow to exactly: the payments of one day, the
 * invoices that sold one product. Each with what it brings to the row's figure, and its own page a click away.
 */
@Component({
  selector: 'app-report-documents-dialog',
  imports: [AmountPipe, DayPipe, MatButtonModule, MatDialogModule, RouterLink, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title data-testid="report-documents-title">
      {{ 'reports.documents.title' | translate: { row: data.label } }}
    </h2>
    <mat-dialog-content>
      <!-- Material's own display beats a layout class on mat-dialog-content: the layout goes on a div inside it. -->
      <div class="flex flex-col gap-3">
        @if (failed()) {
          <p role="alert" class="text-error" data-testid="report-documents-failed">
            {{ 'reports.documents.failed' | translate }}
          </p>
        }
        @if (read(); as answer) {
          @if (answer.documents.length === 0) {
            <p data-testid="report-documents-none">{{ 'reports.documents.none' | translate }}</p>
          } @else {
            <table
              class="twes-stack-table w-full border-collapse text-sm"
              data-testid="report-documents"
            >
              <thead>
                <tr>
                  <th scope="col" class="p-2 text-start">
                    {{ 'reports.documents.number' | translate }}
                  </th>
                  <th scope="col" class="p-2 text-start">
                    {{ 'reports.documents.party' | translate }}
                  </th>
                  <th scope="col" class="p-2 text-start">
                    {{ 'reports.documents.day' | translate }}
                  </th>
                  <th scope="col" class="p-2 text-end">
                    {{ 'reports.documents.amount' | translate }}
                  </th>
                </tr>
              </thead>
              <tbody>
                @for (document of answer.documents; track $index) {
                  <tr class="border-t border-outline-variant" data-testid="report-document">
                    <!-- A document by its number, or by its kind while it has none, as a draft expense. -->
                    @let named =
                      document.number ?? ('reports.documents.kinds.' + document.kind | translate);
                    <th scope="row" class="p-2 text-start font-medium">
                      @if (link(document); as opens) {
                        <a [routerLink]="opens" (click)="ref.close()"
                          ><bdi>{{ named }}</bdi></a
                        >
                      } @else {
                        {{ named }}
                      }
                    </th>
                    <td class="p-2" [attr.data-label]="'reports.documents.party' | translate">
                      <bdi>{{ document.party }}</bdi>
                    </td>
                    <td class="p-2" [attr.data-label]="'reports.documents.day' | translate">
                      {{ document.day | day }}
                    </td>
                    <td
                      class="p-2 text-end tabular-nums"
                      [attr.data-label]="'reports.documents.amount' | translate"
                    >
                      {{ document.amount | amount: null }}
                    </td>
                  </tr>
                }
              </tbody>
            </table>
            @if (answer.more) {
              <p class="text-sm text-on-surface-variant" data-testid="report-documents-more">
                {{ 'reports.documents.more' | translate }}
              </p>
            }
          }
        }
      </div>
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-button type="button" (click)="ref.close()" data-testid="report-documents-close">
        {{ 'reports.documents.close' | translate }}
      </button>
    </mat-dialog-actions>
  `,
})
export class ReportDocumentsDialog implements OnInit {
  protected readonly ref = inject<MatDialogRef<ReportDocumentsDialog>>(MatDialogRef);
  protected readonly data = inject<ReportDocumentsData>(MAT_DIALOG_DATA);
  private readonly facade = inject(ReportsFacade);

  protected readonly read = signal<ReportDocuments | null>(null);
  protected readonly failed = signal(false);

  async ngOnInit(): Promise<void> {
    try {
      this.read.set(
        await this.facade.documents(
          this.data.companyId,
          this.data.key,
          this.data.query,
          this.data.row,
        ),
      );
    } catch {
      this.failed.set(true);
    }
  }

  protected link(document: ReportDocument): string[] | null {
    return documentLink(document);
  }
}
