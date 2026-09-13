// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { MatPaginatorIntl } from '@angular/material/paginator';
import { TranslateService } from '@ngx-translate/core';

/**
 * Material's paginator labels, from `list.paginator` in the translation files instead of its built-in English.
 * Relabels, and tells every paginator to redraw, whenever the language changes.
 */
@Injectable()
export class TranslatedPaginatorIntl extends MatPaginatorIntl {
  private readonly translate = inject(TranslateService);

  constructor() {
    super();
    this.translate
      .stream('list.paginator')
      .pipe(takeUntilDestroyed())
      .subscribe(() => this.relabel());
  }

  override getRangeLabel = (page: number, pageSize: number, length: number): string => {
    const start = page * pageSize;
    if (length === 0 || pageSize <= 0 || start >= length) {
      return this.translate.instant('list.paginator.empty', { total: length });
    }
    return this.translate.instant('list.paginator.range', {
      start: start + 1,
      end: Math.min(start + pageSize, length),
      total: length,
    });
  };

  private relabel(): void {
    const label = (key: string): string => this.translate.instant(`list.paginator.${key}`);
    this.itemsPerPageLabel = label('items_per_page');
    this.nextPageLabel = label('next');
    this.previousPageLabel = label('previous');
    this.firstPageLabel = label('first');
    this.lastPageLabel = label('last');
    this.changes.next();
  }
}
