// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { provideTranslateService } from '@ngx-translate/core';
import { beforeEach, describe, expect, it } from 'vitest';
import { type ReceiverContact, receiverChoice, receiverForm } from './receiver';
import { ReceiverField } from './receiver-field';

const CONTACTS: readonly ReceiverContact[] = [
  { contactId: 'k1', name: 'Ahmed Ben Salah' },
  { contactId: 'k2', name: 'Leila Trabelsi' },
];

@Component({
  imports: [ReceiverField],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <app-receiver-field
      [form]="form()"
      [contacts]="contacts"
      [offersAdding]="offersAdding()"
      [pickedHint]="pickedHint()"
    />
  `,
})
class Host {
  readonly form = signal(receiverForm(null));
  readonly contacts = CONTACTS;
  readonly offersAdding = signal(true);
  readonly pickedHint = signal('receivers.a_contact');
}

describe('ReceiverField', () => {
  let fixture: ComponentFixture<Host>;

  const q = (id: string): HTMLElement | null =>
    document.body.querySelector(`[data-testid="${id}"]`);
  const name = (): HTMLInputElement => q('receiver-name') as HTMLInputElement;
  const settle = async (): Promise<void> => {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  };
  const type = async (text: string): Promise<void> => {
    name().focus();
    name().value = text;
    name().dispatchEvent(new Event('input'));
    await settle();
  };

  beforeEach(async () => {
    TestBed.configureTestingModule({
      providers: [
        provideTranslateService(),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
      ],
    });
    fixture = TestBed.createComponent(Host);
    await settle();
  });

  it('offers the contacts whose name holds what is typed, whatever its case', async () => {
    await type('leila');
    const offered = [...document.body.querySelectorAll('[data-testid="receiver-contact"]')];
    expect(offered.map((option) => option.textContent?.trim())).toEqual(['Leila Trabelsi']);
  });

  it('copies a contact picked by its id, says it is one, and offers no adding', async () => {
    await type('ahm');
    (document.body.querySelector('[data-testid="receiver-contact"]') as HTMLElement).click();
    await settle();

    expect(receiverChoice(fixture.componentInstance.form())).toEqual({
      contactId: 'k1',
      name: null,
      addToContacts: false,
    });
    expect(q('receiver-is-contact')?.textContent).toContain('receivers.a_contact');
    expect(q('receiver-add')).toBeNull();
  });

  it('makes a picked contact a name typed once its name is typed over, which may then be added', async () => {
    await type('ahm');
    (document.body.querySelector('[data-testid="receiver-contact"]') as HTMLElement).click();
    await settle();
    await type('Ahmed B.');

    expect(fixture.componentInstance.form().controls.contactId.value).toBeNull();
    expect(q('receiver-add')).not.toBeNull();
    (q('receiver-add')!.querySelector('input') as HTMLInputElement).click();
    await settle();
    expect(receiverChoice(fixture.componentInstance.form())).toEqual({
      contactId: null,
      name: 'Ahmed B.',
      addToContacts: true,
    });
  });

  it('offers no adding where the list cannot take a name, and says what a pick is', async () => {
    fixture.componentInstance.offersAdding.set(false);
    fixture.componentInstance.pickedHint.set('receivers.a_member');
    await type('Someone new');
    expect(q('receiver-add')).toBeNull();

    await type('ahm');
    (document.body.querySelector('[data-testid="receiver-contact"]') as HTMLElement).click();
    await settle();
    expect(q('receiver-is-contact')?.textContent).toContain('receivers.a_member');
  });

  it('says a refusal under the name, and lets it go once the name is changed', async () => {
    const control = fixture.componentInstance.form().controls.name;
    control.setErrors({ refused: { key: 'import.rejections.receiver_off', params: {} } });
    control.markAsTouched();
    await settle();
    expect(q('receiver-error')?.textContent).toContain('import.rejections.receiver_off');

    await type('');
    expect(control.hasError('refused')).toBe(false);
  });

  it('asks for a name where one is required', async () => {
    fixture.componentInstance.form.set(receiverForm(null, true));
    await settle();
    fixture.componentInstance.form().markAllAsTouched();
    await settle();
    expect(fixture.componentInstance.form().invalid).toBe(true);
    expect(q('receiver-error')?.textContent).toContain('receivers.required');
  });
});
