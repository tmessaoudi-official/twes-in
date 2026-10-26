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
import { NOBODY } from '../shared/receivers/receiver';
import { DeliverDialog, type DeliverDialogData } from './deliver-dialog';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      receivers: {
        dropped: '« Reçu par » est désactivé pour ce document : {{name}} ne sera pas gardé.',
      },
      form: {
        errors: {
          required: 'Ce champ est obligatoire.',
          date: 'Saisissez un jour valide.',
          time: 'Saisissez une heure valide.',
        },
      },
      import: {
        rejections: {
          after_today: 'Date refusée : aujourd’hui au plus tard.',
          lines_required: 'Ajoutez au moins une ligne.',
        },
      },
    });
  }
}

// A refusal said only by a disabled button: « Livrer » greyed out on a wrong day or time said nothing of what to change.
describe('DeliverDialog', () => {
  const ref = { close: vi.fn() };
  const delivered = vi.fn<DeliverDialogData['deliver']>();

  async function open(receiver?: DeliverDialogData['receiver'], dropped?: string) {
    ref.close.mockReset();
    delivered.mockReset().mockResolvedValue({ kept: true });
    TestBed.configureTestingModule({
      imports: [DeliverDialog],
      providers: [
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        {
          provide: MAT_DIALOG_DATA,
          useValue: { zone: 'Africa/Tunis', deliver: delivered, receiver, dropped },
        },
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
    expect(delivered).toHaveBeenCalledWith('', null);
    expect(ref.close).toHaveBeenCalledWith(true);
  });

  it('delivers at the moment written, the time as a person writes it', async () => {
    const { type, deliver } = await open();

    await type('delivery-note-delivered-on', '01/10/2026');
    await type('delivery-note-delivered-time', '9h05');
    await deliver();

    expect(delivered).toHaveBeenCalledWith('2026-10-01T09:05', null);
  });

  it('delivers now when left as it opened', async () => {
    const { deliver } = await open();

    await deliver();

    expect(delivered).toHaveBeenCalledWith('', null);
  });

  // What « Reçu par » shows when « Marquer livré » is pressed is what the note records: emptied, it names nobody,
  // rather than leaving whoever the draft named on the note unsaid.
  const named = {
    current: { name: 'Karim Jaziri', contactId: null },
    required: false,
    contacts: Promise.resolve([]),
  };

  it('opens « Reçu par » on whoever the note names and records that name', async () => {
    const { q, deliver } = await open(named);

    expect(q<HTMLInputElement>('deliver-receiver-name')!.value).toBe('Karim Jaziri');
    await deliver();

    expect(delivered).toHaveBeenCalledWith('', {
      contactId: null,
      name: 'Karim Jaziri',
      addToContacts: false,
    });
  });

  it('records nobody once « Reçu par » is emptied, never the name it opened on', async () => {
    const { type, deliver } = await open(named);

    await type('deliver-receiver-name', '');
    await deliver();

    expect(delivered).toHaveBeenCalledWith('', NOBODY);
    expect(ref.close).toHaveBeenCalledWith(true);
  });

  it('says the name the note carries is not kept where « Reçu par » was switched off since, and delivers', async () => {
    // The dialog showed no field and refused with « videz ce champ »: the note could not be delivered from it.
    const { q, deliver } = await open(undefined, 'Sami Ben Ali');

    expect(q('deliver-receiver-name')).toBeNull();
    expect(q('deliver-receiver-dropped')?.textContent).toContain('Sami Ben Ali ne sera pas gardé');
    await deliver();

    expect(delivered).toHaveBeenCalledWith('', null);
    expect(ref.close).toHaveBeenCalledWith(true);
  });

  it('says nothing of a name when the note names nobody', async () => {
    const { q } = await open(undefined);

    expect(q('deliver-receiver-dropped')).toBeNull();
  });

  it('stays open on a refused day, saying why under it, and what no field takes below', async () => {
    const { q, type, deliver } = await open();
    delivered.mockResolvedValueOnce({
      refused: [
        { field: 'delivered', reason: 'after_today', params: {} },
        { field: 'lines', reason: 'lines_required', params: {} },
      ],
    });

    await type('delivery-note-delivered-on', '01/10/2027');
    await deliver();

    expect(ref.close).not.toHaveBeenCalled();
    expect(q('delivery-note-delivered-on-error')?.textContent).toContain(
      'Date refusée : aujourd’hui au plus tard.',
    );
    expect(q('deliver-refused')?.textContent?.trim()).toBe('Ajoutez au moins une ligne.');
  });

  it('closes on a failure no field names, for the page to say', async () => {
    const { deliver } = await open();
    delivered.mockResolvedValueOnce(null);

    await deliver();

    expect(ref.close).toHaveBeenCalledWith(false);
  });
});
