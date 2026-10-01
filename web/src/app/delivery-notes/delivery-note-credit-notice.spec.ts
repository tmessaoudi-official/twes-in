// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { FormatFacade } from '../shared/i18n/format-facade';
import { DeliveryNoteCreditNotice } from './delivery-note-credit-notice';
import { DeliveryNotesFacade } from './delivery-notes-facade';
import type { DeliveryNoteCredit } from './delivery-notes-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      delivery_notes: { credit_over: 'Dépassé : {{owed}} + {{total}} sur {{limit}} {{currency}}' },
    });
  }
}

const position = (over: boolean): DeliveryNoteCredit => ({
  limit: '1200.000',
  owed: '1000.000',
  noteTotal: '300.000',
  afterDelivery: '1300.000',
  over,
});

describe('DeliveryNoteCreditNotice', () => {
  const credit = vi.fn();

  async function render(inputs: Record<string, unknown> = {}) {
    await TestBed.configureTestingModule({
      imports: [DeliveryNoteCreditNotice],
      providers: [
        provideTranslateService({
          fallbackLang: 'fr',
          loader: provideTranslateLoader(StaticLoader),
        }),
        { provide: FormatFacade, useValue: { amount: (v: string) => v, locale: () => 'fr' } },
        { provide: DeliveryNotesFacade, useValue: { credit } },
      ],
    }).compileComponents();
    const fixture = TestBed.createComponent(DeliveryNoteCreditNotice);
    const all = {
      companyId: 'c1',
      noteId: 'n1',
      status: 'draft',
      total: '300.000',
      currency: 'TND',
      scale: 3,
      ...inputs,
    };
    for (const [name, value] of Object.entries(all)) fixture.componentRef.setInput(name, value);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
    return { fixture, el: fixture.nativeElement as HTMLElement };
  }
  const notice = (el: HTMLElement) => el.querySelector('[data-testid="delivery-note-credit-over"]');

  beforeEach(() => credit.mockReset());

  it('warns, as an alert naming what is owed, the note and the limit, when the delivery passes the limit', async () => {
    credit.mockResolvedValue(position(true));
    const { el } = await render();

    expect(credit).toHaveBeenCalledWith('c1', 'n1');
    expect(notice(el)?.textContent?.trim()).toBe('Dépassé : 1000.000 + 300.000 sur 1200.000 TND');
    expect(notice(el)?.getAttribute('role')).toBe('alert');
  });

  it('says nothing when the delivery stays under the limit, or when the position could not be read', async () => {
    credit.mockResolvedValue(position(false));
    expect(notice((await render()).el)).toBeNull();

    TestBed.resetTestingModule();
    credit.mockResolvedValue(null);
    expect(notice((await render()).el)).toBeNull();
  });

  it('reads again when the note’s total changes, since that changes the answer', async () => {
    credit.mockResolvedValue(position(false));
    const { fixture, el } = await render();
    expect(credit).toHaveBeenCalledTimes(1);

    credit.mockResolvedValue(position(true));
    fixture.componentRef.setInput('total', '900.000');
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();

    expect(credit).toHaveBeenCalledTimes(2);
    expect(notice(el)).not.toBeNull();
  });
});
