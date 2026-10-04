// SPDX-License-Identifier: AGPL-3.0-or-later

import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import { Session } from '../shared/session/session';
import { AuthFacade } from '../auth/auth-facade';
import { ReceiptPlacement } from './receipt-placement';
import type { Part } from './split-receipt';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      inventory: {
        placement: {
          short: 'Il reste {{left}} à placer',
          done: 'Tout est placé',
          over: 'Il y a {{left}} de trop',
          invalid: 'Saisissez la quantité reçue',
          unplaced: 'Placez tout avant d’enregistrer',
        },
      },
    });
  }
}

const places = [
  { value: 'l1', label: '000' },
  { value: 'l2', label: '000 › R1' },
  { value: 'l3', label: '000 › R2' },
];

describe('ReceiptPlacement', () => {
  let fixture: ComponentFixture<ReceiptPlacement>;
  const changes: (readonly Part[])[] = [];

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  async function show(received: string, parts: readonly Part[], refused = false): Promise<void> {
    fixture.componentRef.setInput('received', received);
    fixture.componentRef.setInput('parts', parts);
    fixture.componentRef.setInput('refused', refused);
    await settle();
  }

  beforeEach(async () => {
    changes.length = 0;
    TestBed.configureTestingModule({
      imports: [ReceiptPlacement],
      providers: [
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: AuthFacade, useValue: { me: () => null } },
        { provide: Session, useExisting: AuthFacade },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
    fixture = TestBed.createComponent(ReceiptPlacement);
    fixture.componentRef.setInput('places', places);
    fixture.componentRef.setInput('defaultPlace', 'l1');
    fixture.componentInstance.partsChange.subscribe((parts) => changes.push(parts));
  });

  it('says what is left to place, in whole thousandths, until it reaches nothing', async () => {
    await show('10', [
      { locationId: 'l1', quantity: '0.1' },
      { locationId: 'l2', quantity: '0.2' },
    ]);
    expect(q('placement-status')!.textContent).toContain('Il reste 9.7 à placer');

    await show('0.3', [
      { locationId: 'l1', quantity: '0.1' },
      { locationId: 'l2', quantity: '0.2' },
    ]);
    expect(q('placement-status')!.textContent).toContain('Tout est placé');
  });

  it('says by how much it is over, and asks for the quantity received when there is none', async () => {
    await show('5', [{ locationId: 'l1', quantity: '6' }]);
    expect(q('placement-status')!.textContent).toContain('Il y a 1 de trop');

    await show('', [{ locationId: 'l1', quantity: '6' }]);
    expect(q('placement-status')!.textContent).toContain('Saisissez la quantité reçue');
  });

  it('says why a save was refused only once it was', async () => {
    await show('5', [{ locationId: 'l1', quantity: '2' }]);
    expect(q('placement-refused')).toBeNull();

    await show('5', [{ locationId: 'l1', quantity: '2' }], true);
    expect(q('placement-refused')!.textContent).toContain('Placez tout avant d’enregistrer');
  });

  it('hands back the rows with the quantity typed on one of them', async () => {
    await show('10', [
      { locationId: 'l1', quantity: '' },
      { locationId: 'l2', quantity: '' },
    ]);
    const input = q('placement-0-quantity') as HTMLInputElement;
    input.value = '4,5';
    input.dispatchEvent(new Event('input'));

    expect(changes.at(-1)).toEqual([
      { locationId: 'l1', quantity: '4.5' },
      { locationId: 'l2', quantity: '' },
    ]);
  });

  it('adds a row on the first place no row names, and offers no more once every place is named', async () => {
    await show('10', [{ locationId: 'l1', quantity: '4' }]);
    q('placement-add')!.click();
    expect(changes.at(-1)).toEqual([
      { locationId: 'l1', quantity: '4' },
      { locationId: 'l2', quantity: '' },
    ]);

    await show('10', [
      { locationId: 'l1', quantity: '4' },
      { locationId: 'l2', quantity: '' },
      { locationId: 'l3', quantity: '' },
    ]);
    expect((q('placement-add') as HTMLButtonElement).disabled).toBe(true);
  });

  it('removes a row, and leaves the last one with nothing to remove', async () => {
    await show('10', [
      { locationId: 'l1', quantity: '4' },
      { locationId: 'l2', quantity: '6' },
    ]);
    q('placement-1-remove')!.click();
    expect(changes.at(-1)).toEqual([{ locationId: 'l1', quantity: '4' }]);

    await show('10', [{ locationId: 'l1', quantity: '4' }]);
    expect(q('placement-0-remove')).toBeNull();
  });

  it('puts what is left on the default place, on its row or on a row of its own', async () => {
    await show('10', [{ locationId: 'l2', quantity: '4' }]);
    q('placement-rest')!.click();
    expect(changes.at(-1)).toEqual([
      { locationId: 'l2', quantity: '4' },
      { locationId: 'l1', quantity: '6' },
    ]);

    await show('10', [
      { locationId: 'l1', quantity: '1.5' },
      { locationId: 'l2', quantity: '4' },
    ]);
    q('placement-rest')!.click();
    expect(changes.at(-1)).toEqual([
      { locationId: 'l1', quantity: '6' },
      { locationId: 'l2', quantity: '4' },
    ]);
  });

  it('offers the rest only while some is left', async () => {
    await show('10', [{ locationId: 'l1', quantity: '10' }]);
    expect(q('placement-rest')).toBeNull();
  });
});
