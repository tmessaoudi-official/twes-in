// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { MatPaginatorIntl } from '@angular/material/paginator';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
  TranslateService,
} from '@ngx-translate/core';
import { firstValueFrom, of } from 'rxjs';
import { TranslatedPaginatorIntl } from './translated-paginator-intl';

const LABELS = {
  fr: {
    list: {
      paginator: {
        items_per_page: 'Éléments par page :',
        next: 'Page suivante',
        previous: 'Page précédente',
        first: 'Première page',
        last: 'Dernière page',
        range: '{{start}} – {{end}} sur {{total}}',
        empty: '0 sur {{total}}',
      },
    },
  },
  en: {
    list: {
      paginator: {
        items_per_page: 'Items per page:',
        next: 'Next page',
        previous: 'Previous page',
        first: 'First page',
        last: 'Last page',
        range: '{{start}} – {{end}} of {{total}}',
        empty: '0 of {{total}}',
      },
    },
  },
} as const;

class TwoLanguageLoader implements TranslateLoader {
  getTranslation(lang: string) {
    return of(lang === 'en' ? LABELS.en : LABELS.fr);
  }
}

describe('TranslatedPaginatorIntl', () => {
  let intl: MatPaginatorIntl;
  let translate: TranslateService;

  beforeEach(async () => {
    TestBed.configureTestingModule({
      providers: [
        provideTranslateService({ loader: provideTranslateLoader(() => new TwoLanguageLoader()) }),
        { provide: MatPaginatorIntl, useClass: TranslatedPaginatorIntl },
      ],
    });
    translate = TestBed.inject(TranslateService);
    await firstValueFrom(translate.use('fr'));
    intl = TestBed.inject(MatPaginatorIntl);
  });

  it('labels the paginator in the current language', () => {
    expect(intl.itemsPerPageLabel).toBe('Éléments par page :');
    expect(intl.nextPageLabel).toBe('Page suivante');
    expect(intl.previousPageLabel).toBe('Page précédente');
    expect(intl.firstPageLabel).toBe('Première page');
    expect(intl.lastPageLabel).toBe('Dernière page');
    expect(intl.getRangeLabel(1, 10, 24)).toBe('11 – 20 sur 24');
  });

  it('follows a language switch and tells the paginators to redraw', async () => {
    let redraws = 0;
    intl.changes.subscribe(() => redraws++);

    await firstValueFrom(translate.use('en'));

    expect(intl.itemsPerPageLabel).toBe('Items per page:');
    expect(intl.getRangeLabel(2, 10, 24)).toBe('21 – 24 of 24');
    expect(redraws).toBeGreaterThan(0);
  });

  it('never counts past the end or below zero', () => {
    expect(intl.getRangeLabel(0, 10, 0)).toBe('0 sur 0');
    expect(intl.getRangeLabel(5, 10, 24)).toBe('0 sur 24');
  });
});
