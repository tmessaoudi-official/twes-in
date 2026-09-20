// SPDX-License-Identifier: AGPL-3.0-or-later

import { Component, signal } from '@angular/core';
import { type ComponentFixture, TestBed } from '@angular/core/testing';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { Session } from '../session/session';
import { BrowserStorageSettings } from '../settings/browser-storage-settings';
import { PageMemoryStorage, SETTINGS_STORAGE, SettingsFacade } from '../settings/settings-facade';
import type { FormDescriptor, FormValues } from './form-types';
import { RecordView } from './record-view';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      d: {
        parties: 'Parties',
        terms: 'Terms',
        customer: 'Customer',
        reference: 'Their reference',
        supply: 'Supplied on',
        discount: 'Discount',
        notes: 'Notes',
        printed: 'Printed on the document',
        family: 'Family',
        vat: 'VAT',
        stamp: 'Stamp',
        rate: 'Rate',
      },
      form: {
        nothing_filled: 'This document fills none of these fields in.',
        yes: 'Yes',
        no: 'No',
      },
    });
  }
}

const descriptor: FormDescriptor = {
  id: 'invoice',
  sections: [
    {
      id: 'parties',
      title: 'd.parties',
      fields: [
        { id: 'customerId', label: 'd.customer', kind: 'pick' },
        { id: 'customerReference', label: 'd.reference', kind: 'text' },
        { id: 'supplyDate', label: 'd.supply', kind: 'date' },
      ],
    },
    {
      id: 'terms',
      title: 'd.terms',
      fields: [
        { id: 'discountAmount', label: 'd.discount', kind: 'decimal' },
        { id: 'notesPrinted', label: 'd.notes', kind: 'textarea', span: 2 },
        { id: 'printed', label: 'd.printed', kind: 'checkbox' },
        {
          id: 'family',
          label: 'd.family',
          kind: 'select',
          options: [
            { value: 'vat', label: 'd.vat' },
            { value: 'stamp', label: 'd.stamp' },
          ],
        },
        {
          id: 'rate',
          label: 'd.rate',
          kind: 'decimal',
          visibleWhen: { field: 'family', oneOf: ['vat'] },
        },
      ],
    },
  ],
};

@Component({
  imports: [RecordView],
  template: `<app-record-view
    [descriptor]="descriptor"
    [values]="values()"
    [picked]="{ customerId: 'Carthage SARL' }"
    testId="invoice-view"
  />`,
})
class Host {
  readonly descriptor = descriptor;
  readonly values = signal<FormValues>({});
}

describe('RecordView', () => {
  let fixture: ComponentFixture<Host>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);
  const text = (testId: string): string => (q(testId)?.textContent ?? '').trim();

  async function show(values: FormValues): Promise<void> {
    fixture.componentInstance.values.set(values);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  beforeEach(async () => {
    TestBed.configureTestingModule({
      imports: [Host],
      providers: [
        provideTranslateService({
          lang: 'en',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
        {
          provide: Session,
          useValue: { me: () => ({ user: { id: 'u1' }, company: { id: 'c1' } }) },
        },
      ],
    });
    fixture = TestBed.createComponent(Host);
    await show({});
  });

  it('shows what the document says, each value under its own label', async () => {
    await show({ customerReference: 'BC-77', supplyDate: '2026-09-14' });

    expect(text('view-label-customerReference')).toBe('Their reference');
    expect(text('view-customerReference')).toBe('BC-77');
    expect(text('view-supplyDate')).toBe('14/09/2026');
  });

  it('leaves out what the document does not say, and the section that then holds nothing', async () => {
    // The measured defect: a locked invoice kept a row of empty boxes for every field nobody filled in.
    await show({ customerReference: 'BC-77' });

    expect(q('view-supplyDate')).toBeNull();
    expect(q('view-label-supplyDate')).toBeNull();
    expect(q('view-discountAmount')).toBeNull();
    expect(fixture.nativeElement.textContent).not.toContain('Terms');
    expect(fixture.nativeElement.textContent).toContain('Parties');
  });

  it('reads a chosen record by its name, which is the only thing the screen knows', async () => {
    await show({ customerId: 'k1' });
    expect(text('view-customerId')).toBe('Carthage SARL');
  });

  it('reads a choice by its label and a tick as a word, never by the value stored', async () => {
    await show({ family: 'stamp', printed: true });
    expect(text('view-family')).toBe('Stamp');
    expect(text('view-printed')).toBe('Yes');
  });

  it('says No for a tick that is off, since an unticked box is an answer and not a blank', async () => {
    await show({ printed: false });
    expect(text('view-printed')).toBe('No');
  });

  it('leaves out a field the document’s own values put out of play', async () => {
    // `rate` belongs to a VAT component; on a stamp it is not empty, it does not apply.
    await show({ family: 'stamp', rate: '19.000' });
    expect(q('view-rate')).toBeNull();

    await show({ family: 'vat', rate: '19.000' });
    expect(text('view-rate')).toBe('19,000');
  });

  it('says so when the document filled none of these fields in', () => {
    expect(text('invoice-view-empty')).toBe('This document fills none of these fields in.');
  });
});
