// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { provideTranslateService } from '@ngx-translate/core';
import { ListExport } from './list-export';

describe('ListExport', () => {
  function create(address: ((format: 'csv' | 'xlsx') => string) | null) {
    TestBed.configureTestingModule({ providers: [provideTranslateService({ lang: 'fr' })] });
    const fixture = TestBed.createComponent(ListExport);
    fixture.componentRef.setInput('address', address);
    fixture.componentRef.setInput('testId', 'things-export');
    fixture.detectChanges();
    return fixture;
  }

  it('shows nothing until the list has a search to hand over', () => {
    const fixture = create(null);

    expect(fixture.nativeElement.querySelector('a')).toBeNull();
  });

  it('offers the file in both formats, from the address the list names', () => {
    const fixture = create((format) => `/exports/things.${format}`);
    const links = [...fixture.nativeElement.querySelectorAll('a')] as HTMLAnchorElement[];

    expect(links.map((link) => link.getAttribute('href'))).toEqual([
      '/exports/things.csv',
      '/exports/things.xlsx',
    ]);
    expect(links.map((link) => link.getAttribute('data-testid'))).toEqual([
      'things-export-csv',
      'things-export-xlsx',
    ]);
  });

  it('keeps its two buttons apart, which a bare inline host does not (the buttons touched)', () => {
    const fixture = create((format) => `/exports/things.${format}`);

    const host = fixture.nativeElement as HTMLElement;
    expect(host.classList.contains('inline-flex')).toBe(true);
    expect(host.classList.contains('gap-2')).toBe(true);
  });
});
