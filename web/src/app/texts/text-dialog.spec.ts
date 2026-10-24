// SPDX-License-Identifier: AGPL-3.0-or-later

import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { MAT_DIALOG_DATA, MatDialogRef } from '@angular/material/dialog';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { TextDialog, type TextDialogData } from './text-dialog';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      texts: {
        dialog: { marks: 'Gardez : {{marks}}' },
        refusals: { empty: 'Écrivez un texte.', placeholders: 'Gardez les repères.' },
      },
    });
  }
}

describe('TextDialog', () => {
  let fixture: ComponentFixture<TextDialog>;
  const close = vi.fn();
  const save = vi.fn();
  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  async function open(data: Partial<TextDialogData> = {}): Promise<void> {
    close.mockReset();
    save.mockReset();
    TestBed.configureTestingModule({
      imports: [TextDialog],
      providers: [
        provideTranslateService({
          lang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: MatDialogRef, useValue: { close } },
        {
          provide: MAT_DIALOG_DATA,
          useValue: {
            language: 'fr',
            original: 'Facture %number%',
            installation: 'Facture de vente %number%',
            value: 'Facture de vente %number%',
            marks: ['%number%'],
            save,
            ...data,
          } satisfies TextDialogData,
        },
      ],
    });
    fixture = TestBed.createComponent(TextDialog);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  function type(value: string): void {
    const field = q('text-dialog-value') as HTMLTextAreaElement;
    field.value = value;
    field.dispatchEvent(new Event('input'));
    fixture.detectChanges();
  }

  async function press(testId: string): Promise<void> {
    q(testId)?.click();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  it('shows the original beside the field, the installation’s wording and the marks to keep', async () => {
    await open();

    expect(q('text-dialog-original')?.textContent?.trim()).toBe('Facture %number%');
    expect(q('text-dialog-installation')?.textContent?.trim()).toBe('Facture de vente %number%');
    expect((q('text-dialog-value') as HTMLTextAreaElement).value).toBe('Facture de vente %number%');
    expect(q('text-dialog-marks')?.textContent?.trim()).toBe('Gardez : %number%');
  });

  it('answers with what was kept', async () => {
    await open({ installation: null });
    expect(q('text-dialog-installation')).toBeNull();
    save.mockResolvedValue({ kept: true, answer: { value: 'Note %number%', version: 2 } });
    type('Note %number%');

    await press('text-dialog-save');

    expect(save).toHaveBeenCalledWith('Note %number%');
    expect(close).toHaveBeenCalledWith({ value: 'Note %number%', version: 2 });
  });

  it('says a refusal under the field and keeps what was typed, until it is typed again', async () => {
    await open();
    save.mockResolvedValue({ kept: false, refusal: 'placeholders' });
    type('Facture');

    await press('text-dialog-save');

    expect(close).not.toHaveBeenCalled();
    expect(q('text-dialog-refusal')?.textContent?.trim()).toBe('Gardez les repères.');
    expect((q('text-dialog-value') as HTMLTextAreaElement).value).toBe('Facture');
    type('Facture %number%');
    expect(q('text-dialog-refusal')).toBeNull();
  });

  it('refuses a text of spaces alone without asking the API', async () => {
    await open();
    type('   ');

    await press('text-dialog-save');

    expect(save).not.toHaveBeenCalled();
    expect(q('text-dialog-refusal')?.textContent?.trim()).toBe('Écrivez un texte.');
  });

  it('stays open and says nothing more of a refusal already said elsewhere', async () => {
    await open();
    save.mockResolvedValue({ kept: false, refusal: null });

    await press('text-dialog-save');

    expect(close).not.toHaveBeenCalled();
    expect(q('text-dialog-refusal')).toBeNull();
  });
});
