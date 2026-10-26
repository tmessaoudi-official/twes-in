// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { MAT_DIALOG_DATA, MatDialogRef } from '@angular/material/dialog';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { formatDay } from '../shared/i18n/format';
import { FormatFacade } from '../shared/i18n/format-facade';
import { QuoteAnswerDialog, type QuoteAnswerDialogData } from './quote-answer-dialog';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      form: { errors: { date: 'Saisissez un jour valide.' } },
      import: {
        rejections: {
          on_or_after: 'Date refusée : le {{day}} au plus tôt.',
          lines_required: 'Ajoutez au moins une ligne.',
        },
      },
    });
  }
}

// A refusal said only by a disabled button: the answer's confirmation greyed out on a wrong day said nothing.
describe('QuoteAnswerDialog', () => {
  const ref = { close: vi.fn() };
  const answer = vi.fn<QuoteAnswerDialogData['answer']>();

  async function open(data: Omit<QuoteAnswerDialogData, 'answer'>) {
    ref.close.mockReset();
    answer.mockReset().mockResolvedValue({ kept: true });
    TestBed.configureTestingModule({
      imports: [QuoteAnswerDialog],
      providers: [
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: MAT_DIALOG_DATA, useValue: { ...data, answer } },
        { provide: MatDialogRef, useValue: ref },
        {
          provide: FormatFacade,
          useValue: {
            locale: () => 'fr-TN',
            dateFormat: () => 'auto',
            day: (value: string) => formatDay(value, 'fr-TN', 'auto'),
          },
        },
      ],
    });
    const fixture = TestBed.createComponent(QuoteAnswerDialog);
    await fixture.whenStable();
    const el = fixture.nativeElement as HTMLElement;
    const q = <T extends HTMLElement>(id: string) => el.querySelector<T>(`[data-testid="${id}"]`);
    const typeDay = async (text: string) => {
      const input = q<HTMLInputElement>('quote-answered-on')!;
      input.value = text;
      input.dispatchEvent(new Event('input'));
      await fixture.whenStable();
    };
    const confirm = async () => {
      q<HTMLButtonElement>('quote-answer-confirm')!.click();
      await fixture.whenStable();
    };
    return { q, typeDay, confirm };
  }

  it('says a day that is not one under its field, stays open and puts the cursor there', async () => {
    const { q, typeDay, confirm } = await open({ kind: 'refuse' });

    await typeDay('31/02/2026');
    expect(q<HTMLButtonElement>('quote-answer-confirm')!.disabled).toBe(false);
    await confirm();

    expect(ref.close).not.toHaveBeenCalled();
    expect(answer).not.toHaveBeenCalled();
    expect(q('quote-answered-on-error')?.textContent).toContain('Saisissez un jour valide.');
    expect(document.activeElement).toBe(q('quote-answered-on'));
  });

  it('answers on the day written, and closes once the answer is kept', async () => {
    const { typeDay, confirm } = await open({ kind: 'accept' });

    await typeDay('01/10/2026');
    await confirm();

    expect(answer).toHaveBeenCalledWith({
      answeredOn: '2026-10-01',
      refusalReason: '',
      signed: null,
    });
    expect(ref.close).toHaveBeenCalledWith(true);
  });

  it('answers on the company’s today when no day is written', async () => {
    const { q, confirm } = await open({ kind: 'accept' });

    await confirm();

    expect(q('quote-answered-on-error')).toBeNull();
    expect(answer).toHaveBeenCalledWith({ answeredOn: '', refusalReason: '', signed: null });
  });

  it('stays open on a refused day, saying why under it in its figures, and what no field takes below', async () => {
    const { q, typeDay, confirm } = await open({ kind: 'refuse' });
    answer.mockResolvedValueOnce({
      refused: [
        { field: 'answeredOn', reason: 'on_or_after', params: { day: '2026-10-05' } },
        { field: 'quote', reason: 'lines_required', params: {} },
      ],
    });

    await typeDay('01/10/2026');
    await confirm();

    expect(ref.close).not.toHaveBeenCalled();
    expect(q('quote-answered-on-error')?.textContent).toContain(
      `Date refusée : le ${formatDay('2026-10-05', 'fr-TN', 'auto')} au plus tôt.`,
    );
    expect(q('quote-answer-refused')?.textContent?.trim()).toBe('Ajoutez au moins une ligne.');

    await typeDay('06/10/2026');
    expect(q('quote-answered-on-error')).toBeNull();
  });
});
