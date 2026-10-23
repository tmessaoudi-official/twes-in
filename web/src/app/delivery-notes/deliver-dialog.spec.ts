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
import { DeliverDialog } from './deliver-dialog';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      form: {
        errors: {
          required: 'Ce champ est obligatoire.',
          date: 'Saisissez un jour valide.',
          time: 'Saisissez une heure valide.',
        },
      },
    });
  }
}

// A refusal said only by a disabled button: « Livrer » greyed out on a wrong day or time said nothing of what to change.
describe('DeliverDialog', () => {
  const ref = { close: vi.fn() };

  async function open() {
    ref.close.mockReset();
    TestBed.configureTestingModule({
      imports: [DeliverDialog],
      providers: [
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: MAT_DIALOG_DATA, useValue: 'Africa/Tunis' },
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
    const fixture = TestBed.createComponent(DeliverDialog);
    await fixture.whenStable();
    const el = fixture.nativeElement as HTMLElement;
    const q = <T extends HTMLElement>(id: string) => el.querySelector<T>(`[data-testid="${id}"]`);
    const type = async (id: string, text: string) => {
      const input = q<HTMLInputElement>(id)!;
      input.value = text;
      input.dispatchEvent(new Event('input'));
      await fixture.whenStable();
    };
    const deliver = async () => {
      q<HTMLButtonElement>('delivery-note-deliver')!.click();
      await fixture.whenStable();
    };
    return { q, type, deliver };
  }

  it('says a time that is not one under its field, stays open and puts the cursor there', async () => {
    const { q, type, deliver } = await open();

    await type('delivery-note-delivered-time', '25h99');
    expect(q<HTMLButtonElement>('delivery-note-deliver')!.disabled).toBe(false);
    await deliver();

    expect(ref.close).not.toHaveBeenCalled();
    expect(q('delivery-note-delivered-time-error')?.textContent).toContain(
      'Saisissez une heure valide.',
    );
    expect(document.activeElement).toBe(q('delivery-note-delivered-time'));
  });

  it('says a day that is not one, and asks for the time when only the day is left', async () => {
    const { q, type, deliver } = await open();

    await type('delivery-note-delivered-on', '31/02/2026');
    await type('delivery-note-delivered-time', '');
    await deliver();

    expect(ref.close).not.toHaveBeenCalled();
    expect(q('delivery-note-delivered-on-error')?.textContent).toContain(
      'Saisissez un jour valide.',
    );
    expect(q('delivery-note-delivered-time-error')?.textContent).toContain(
      'Ce champ est obligatoire.',
    );
    expect(document.activeElement).toBe(q('delivery-note-delivered-on'));
  });

  it('asks again for nothing once both are emptied, and closes on now', async () => {
    const { q, type, deliver } = await open();

    await type('delivery-note-delivered-on', '');
    await type('delivery-note-delivered-time', '');
    await deliver();

    expect(q('delivery-note-delivered-time-error')).toBeNull();
    expect(ref.close).toHaveBeenCalledWith('');
  });

  it('closes on the moment written, the time as a person writes it', async () => {
    const { type, deliver } = await open();

    await type('delivery-note-delivered-on', '01/10/2026');
    await type('delivery-note-delivered-time', '9h05');
    await deliver();

    expect(ref.close).toHaveBeenCalledWith('2026-10-01T09:05');
  });

  it('closes on now when left as it opened', async () => {
    const { deliver } = await open();

    await deliver();

    expect(ref.close).toHaveBeenCalledWith('');
  });
});
