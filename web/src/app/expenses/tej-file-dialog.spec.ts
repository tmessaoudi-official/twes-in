// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { MAT_DIALOG_DATA, MatDialogRef } from '@angular/material/dialog';
import { provideRouter } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { FileSaver } from '../shared/files/save-file';
import { Session } from '../shared/session/session';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import { ExpensesFacade } from './expenses-facade';
import type { TejFileAnswer } from './expenses-types';
import { TejFileDialog, type TejFileDialogData } from './tej-file-dialog';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      expenses: {
        tej_file: {
          refused: { incomplete_expenses: '{{count}} paiement(s) incomplet(s)' },
          problems: {
            vendor_missing: 'Aucun fournisseur',
            operation_code_missing: 'Opération TEJ non indiquée',
          },
        },
      },
    });
  }
}

// docs/SPEC.md § 7, 2026-09-26: the month's TEJ file, downloaded, or why the month cannot be declared yet.
describe('TejFileDialog', () => {
  const facade = { tejFile: vi.fn(), busy: signal(false).asReadonly() };
  const saver = { save: vi.fn() };
  const ref = { close: vi.fn() };
  const data: TejFileDialogData = {
    companyId: 'c1',
    months: [
      { value: '2026-08', label: 'août 2026' },
      { value: '2026-07', label: 'juillet 2026' },
    ],
  };

  function open() {
    const fixture = TestBed.createComponent(TejFileDialog);
    fixture.detectChanges();
    return fixture;
  }
  const q = (root: HTMLElement, testId: string): HTMLElement | null =>
    root.querySelector(`[data-testid="${testId}"]`);

  beforeEach(() => {
    facade.tejFile.mockReset();
    saver.save.mockReset();
    ref.close.mockReset();
    TestBed.configureTestingModule({
      imports: [TejFileDialog],
      providers: [
        provideRouter([]),
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: MAT_DIALOG_DATA, useValue: data },
        { provide: MatDialogRef, useValue: ref },
        { provide: ExpensesFacade, useValue: facade },
        { provide: FileSaver, useValue: saver },
        {
          provide: Session,
          useValue: {
            me: () => ({ user: { id: 'u1' }, company: { id: 'c1', countryCode: 'TN' } }),
          },
        },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
  });

  it('downloads the last month that is over, under the name the API gives, and closes', async () => {
    const file = new Blob(['<DeclarationsRS/>']);
    facade.tejFile.mockResolvedValue({
      kind: 'file',
      file,
      filename: '1234567A-2026-08-0.xml',
    } satisfies TejFileAnswer);
    const fixture = open();
    q(fixture.nativeElement, 'tej-download')!.click();
    await fixture.whenStable();

    expect(facade.tejFile).toHaveBeenCalledWith('c1', '2026-08');
    expect(saver.save).toHaveBeenCalledWith(file, '1234567A-2026-08-0.xml');
    expect(ref.close).toHaveBeenCalledWith(true);
  });

  it('asks for the month chosen', async () => {
    facade.tejFile.mockResolvedValue({
      kind: 'refused',
      code: 'nothing_to_declare',
      params: {},
      expenses: [],
    });
    const fixture = open();
    q(fixture.nativeElement, 'tej-month')!.click();
    fixture.detectChanges();
    const july = Array.from(document.body.querySelectorAll<HTMLElement>('mat-option')).find(
      (option) => option.textContent?.trim() === 'juillet 2026',
    );
    july!.click();
    fixture.detectChanges();
    q(fixture.nativeElement, 'tej-download')!.click();
    await fixture.whenStable();
    expect(facade.tejFile).toHaveBeenCalledWith('c1', '2026-07');
  });

  it('says why the month cannot be declared, each payment linked with what it lacks', async () => {
    facade.tejFile.mockResolvedValue({
      kind: 'refused',
      code: 'incomplete_expenses',
      params: { count: 1 },
      expenses: [
        {
          id: 'e1',
          paidOn: '2026-08-12',
          description: 'Honoraires',
          reference: 'F-1',
          vendorName: null,
          problems: ['vendor_missing', 'operation_code_missing'],
        },
      ],
    } satisfies TejFileAnswer);
    const fixture = open();
    q(fixture.nativeElement, 'tej-download')!.click();
    await fixture.whenStable();
    fixture.detectChanges();

    const root = fixture.nativeElement as HTMLElement;
    expect(saver.save).not.toHaveBeenCalled();
    expect(ref.close).not.toHaveBeenCalled();
    expect(q(root, 'tej-refusal')?.textContent).toContain('1 paiement(s) incomplet(s)');
    const payment = q(root, 'tej-refused-e1');
    expect(payment?.querySelector('a')?.getAttribute('href')).toBe('/expenses/e1');
    expect(payment?.textContent).toContain('Honoraires');
    expect(payment?.textContent).toContain('Aucun fournisseur');
    expect(payment?.textContent).toContain('Opération TEJ non indiquée');
  });
});
