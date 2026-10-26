// SPDX-License-Identifier: AGPL-3.0-or-later

import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { MAT_DIALOG_DATA, MatDialogRef } from '@angular/material/dialog';
import { provideTranslateService } from '@ngx-translate/core';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { FormAnswer } from '../feedback/request-state';
import type { ReceiverChoice } from './receiver';
import { ReceiverDialog, type ReceiverDialogData } from './receiver-dialog';

describe('ReceiverDialog', () => {
  let fixture: ComponentFixture<ReceiverDialog>;
  const close = vi.fn();
  let save: ReturnType<
    typeof vi.fn<(choice: ReceiverChoice | null) => Promise<FormAnswer<unknown>>>
  >;

  const q = (id: string): HTMLElement | null =>
    document.body.querySelector(`[data-testid="${id}"]`);
  const name = (): HTMLInputElement => q('receiver-dialog-name') as HTMLInputElement;
  const settle = async (): Promise<void> => {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  };
  const type = async (text: string): Promise<void> => {
    name().value = text;
    name().dispatchEvent(new Event('input'));
    await settle();
  };

  const open = async (data: Partial<ReceiverDialogData> = {}): Promise<void> => {
    TestBed.configureTestingModule({
      providers: [
        provideTranslateService(),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: MatDialogRef, useValue: { close } },
        {
          provide: MAT_DIALOG_DATA,
          useValue: {
            title: 'delivery_notes.actions.correct_receiver',
            current: { name: 'Karim Jaziri', contactId: null },
            required: false,
            contacts: Promise.resolve([{ contactId: 'k1', name: 'Ahmed Ben Salah' }]),
            save,
            ...data,
          } satisfies ReceiverDialogData,
        },
      ],
    });
    fixture = TestBed.createComponent(ReceiverDialog);
    await settle();
  };

  beforeEach(() => {
    close.mockReset();
    save = vi.fn(async () => ({ kept: {} }));
  });

  it('opens on whoever the document names and records the name typed in its place', async () => {
    await open();
    expect(name().value).toBe('Karim Jaziri');
    await type('Leila Ben Ali');
    q('receiver-dialog-save')!.click();
    await settle();

    expect(save).toHaveBeenCalledWith({
      contactId: null,
      name: 'Leila Ben Ali',
      addToContacts: false,
    });
    expect(close).toHaveBeenCalledWith(true);
  });

  it('names nobody once emptied, unless the document must name someone', async () => {
    await open();
    await type('');
    q('receiver-dialog-save')!.click();
    await settle();
    expect(save).toHaveBeenCalledWith(null);

    TestBed.resetTestingModule();
    save.mockClear();
    await open({ required: true });
    await type('');
    q('receiver-dialog-save')!.click();
    await settle();
    expect(save).not.toHaveBeenCalled();
    expect(q('receiver-dialog-error')?.textContent).toContain('receivers.required');
  });

  it('stays open on a refusal and says it under the name', async () => {
    save.mockResolvedValue({
      refused: [{ field: 'receivedBy', reason: 'receiver_off', params: {} }],
    });
    await open();
    await type('Leila Ben Ali');
    q('receiver-dialog-save')!.click();
    await settle();

    expect(close).not.toHaveBeenCalled();
    // Said under the name (its words are the texts' to give), never above the form.
    expect(q('receiver-dialog-error')?.textContent).toContain('import.rejections.');
    expect(q('receiver-dialog-refused')).toBeNull();
  });

  it('offers no adding to a list that takes none, and says what a pick is', async () => {
    await open({ offersAdding: false, pickedHint: 'receivers.a_member', current: null });
    await type('Someone new');
    expect(q('receiver-dialog-add')).toBeNull();
  });
});
