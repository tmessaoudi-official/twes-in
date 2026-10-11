// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { MAT_DIALOG_DATA, MatDialogRef } from '@angular/material/dialog';
import { provideTranslateService } from '@ngx-translate/core';
import { BuildInfo } from '../shared/build/build-info';
import { provideQuietFeedback } from '../shared/testing/feedback';
import { glossaryFor } from './glossary';
import { HelpPanel, type HelpPanelData } from './help-panel';
import { TOURS } from './tours';

describe('HelpPanel', () => {
  function render(data: HelpPanelData) {
    const close = vi.fn();
    TestBed.configureTestingModule({
      providers: [
        ...provideQuietFeedback(),
        provideTranslateService({ lang: 'fr' }),
        {
          provide: BuildInfo,
          useValue: {
            web: signal(null),
            api: signal(null),
            webMode: null,
            line: signal(''),
            start: vi.fn(),
          },
        },
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

  it('offers each channel the operators filled in as a link that opens it, and only those', () => {
    const { q } = render({
      tours: TOURS,
      glossary: glossaryFor('TN'),
      support: { phone: '+216 71 000 000', whatsapp: '+216 20 123 456', email: null },
    });

    expect(q('help-contact-phone')?.getAttribute('href')).toBe('tel:+21671000000');
    expect(q('help-contact-phone')?.textContent).toContain('+216 71 000 000');
    // WhatsApp's link takes the international number in digits alone.
    expect(q('help-contact-whatsapp')?.getAttribute('href')).toBe('https://wa.me/21620123456');
    expect(q('help-contact-email')).toBeNull();
  });

  it('writes the address as a mail link', () => {
    const { q } = render({
      tours: TOURS,
      glossary: glossaryFor('TN'),
      support: { phone: null, whatsapp: null, email: 'help@twes.test' },
    });

    expect(q('help-contact-email')?.getAttribute('href')).toBe('mailto:help@twes.test');
  });

  it('leaves the contact out when the operators gave none, rather than an empty heading', () => {
    expect(render({ tours: TOURS, glossary: glossaryFor('TN') }).q('help-contact')).toBeNull();
    TestBed.resetTestingModule();
    const empty = { phone: null, whatsapp: null, email: null };
    expect(
      render({ tours: TOURS, glossary: glossaryFor('TN'), support: empty }).q('help-contact'),
    ).toBeNull();
  });

  it('shows the build, which is what support asks for', () => {
    const { q } = render({ tours: TOURS, glossary: glossaryFor('TN') });

    expect(q('help-build')?.querySelector('[data-testid="build-line"]')).not.toBeNull();
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
