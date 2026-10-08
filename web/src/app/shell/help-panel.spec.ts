// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { MAT_DIALOG_DATA, MatDialogRef } from '@angular/material/dialog';
import { provideTranslateService } from '@ngx-translate/core';
import { glossaryFor } from './glossary';
import { HelpPanel, type HelpPanelData } from './help-panel';
import { TOURS } from './tours';

describe('HelpPanel', () => {
  function render(data: HelpPanelData) {
    const close = vi.fn();
    TestBed.configureTestingModule({
      providers: [
        provideTranslateService({ lang: 'fr' }),
        { provide: MAT_DIALOG_DATA, useValue: data },
        { provide: MatDialogRef, useValue: { close } },
      ],
    });
    const fixture = TestBed.createComponent(HelpPanel);
    fixture.detectChanges();
    const q = (testId: string) =>
      (fixture.nativeElement as HTMLElement).querySelector<HTMLElement>(
        `[data-testid="${testId}"]`,
      );
    return { close, q, fixture };
  }

  it('closes with the guide chosen, for the shell to start over the page', () => {
    const { close, q } = render({ tours: TOURS, glossary: glossaryFor('TN') });

    q(`help-guide-${TOURS[0].key}`)!.click();

    expect(close).toHaveBeenCalledWith({ tour: TOURS[0] });
  });

  it('closes asking for the keyboard shortcuts', () => {
    const { close, q } = render({ tours: TOURS, glossary: glossaryFor('TN') });

    q('help-shortcuts')!.click();

    expect(close).toHaveBeenCalledWith('shortcuts');
  });

  it('says there is no guide here rather than drawing an empty list', () => {
    const { q } = render({ tours: [], glossary: glossaryFor('TN') });

    expect(q('help-no-guide')).not.toBeNull();
  });

  it('lists the words of the company’s own country only', () => {
    const { q } = render({ tours: TOURS, glossary: glossaryFor('TN') });

    expect(q('glossary-invoice')).not.toBeNull();
    expect(q('glossary-stamp_duty')).not.toBeNull();
    expect(q('glossary-withholding')).not.toBeNull();
    expect(q('glossary-vat_franchise')).toBeNull();
    expect(q('glossary-reverse_charge')).toBeNull();
  });
});

describe('glossaryFor', () => {
  it('gives a French company the French words and the documents, never the Tunisian ones', () => {
    expect(glossaryFor('FR').map((entry) => entry.key)).toEqual([
      'invoice',
      'credit_note',
      'quote',
      'delivery_note',
      'vat_franchise',
      'reverse_charge',
    ]);
  });

  it('gives a person outside a company the documents alone', () => {
    expect(glossaryFor(undefined).every((entry) => entry.country === undefined)).toBe(true);
  });
});
