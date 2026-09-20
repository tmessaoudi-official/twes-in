// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { provideRouter } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { Feedback } from '../shared/feedback/feedback';
import { Session } from '../shared/session/session';
import { provideQuietFeedback, type RecordedFeedback } from '../shared/testing/feedback';
import { ImportFacade } from './import-facade';
import { ImportPage } from './import-page';
import type { ImportGuide, ImportRefusal, ImportReport } from './import-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      import: {
        title: 'Importer des {{subject}}',
        subjects: { opening_stock: 'stocks de départ' },
        identity: 'Une ligne est retrouvée par : {{columns}}. Au plus {{rows}} lignes par fichier.',
        preview: 'Prévisualiser',
        store: 'Importer',
        rejections: { duplicate_in_file: 'Déjà présent à la ligne {{line}} du fichier.' },
        refusals: { unknown_columns: 'Colonnes inconnues : {{columns}}.' },
      },
    });
  }
}

const GUIDE: ImportGuide = {
  subject: 'opening-stock',
  identity: ['reference', 'location_code'],
  maxRows: 2000,
  columns: [
    {
      key: 'reference',
      required: true,
      headingKey: 'import.opening_stock.reference',
      label: null,
      example: 'VIS-6X40',
      noteKey: null,
    },
    {
      key: 'quantity',
      required: true,
      headingKey: null,
      label: 'Quantité comptée',
      example: '120.000',
      noteKey: null,
    },
  ],
};

const CLEAN: ImportReport = { committed: false, created: [2, 3], updated: [], rejected: [] };
const REJECTED: ImportReport = {
  committed: false,
  created: [2],
  updated: [],
  rejected: [
    { line: 3, column: 'reference', code: 'duplicate_in_file', params: { line: 2 }, message: 'x' },
  ],
};

describe('ImportPage', () => {
  const guide = signal<ImportGuide | null>(GUIDE);
  const report = signal<ImportReport | null>(null);
  const refusal = signal<ImportRefusal | null>(null);
  const error = signal<string | null>(null);
  const facade = {
    guide: guide.asReadonly(),
    report: report.asReadonly(),
    refusal: refusal.asReadonly(),
    busy: signal(false).asReadonly(),
    error: error.asReadonly(),
    load: vi.fn(),
    refresh: vi.fn(async () => undefined),
    run: vi.fn(),
    forget: vi.fn(),
  };
  const auth = {
    me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }),
    hasPermission: vi.fn(),
  };
  let fixture: ComponentFixture<ImportPage>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  /**
   * Clicks and waits for the handler's OWN promise: `whenStable()` covers pending HTTP, not the microtasks a
   * click handler awaits, so without this the assertions read the page as it was before the answer came back.
   */
  async function click(testId: string): Promise<void> {
    (q(testId) as HTMLButtonElement).click();
    await new Promise((resume) => setTimeout(resume, 0));
    await settle();
  }

  /** A file the input hands over: the page reads `files.item(0)`, which jsdom will not let us set on a real input. */
  function chooseFile(name = 'stocks.csv'): void {
    const file = new File(['reference,quantity\n'], name, { type: 'text/csv' });
    const input = q('import-file') as HTMLInputElement;
    Object.defineProperty(input, 'files', { value: { item: () => file, length: 1 } });
    input.dispatchEvent(new Event('change'));
  }

  beforeEach(async () => {
    guide.set(GUIDE);
    report.set(null);
    refusal.set(null);
    error.set(null);
    facade.load.mockReset().mockResolvedValue(undefined);
    facade.run.mockReset().mockResolvedValue(undefined);
    facade.forget.mockReset();
    auth.hasPermission.mockReset().mockReturnValue(true);
    TestBed.configureTestingModule({
      imports: [ImportPage],
      providers: [
        ...provideQuietFeedback(),
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: ImportFacade, useValue: facade },
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
      ],
    });
    fixture = TestBed.createComponent(ImportPage);
    fixture.componentRef.setInput('subject', 'opening-stock');
    await settle();
  });

  it('asks the API what this company’s file holds, and says what a row is found again by', async () => {
    expect(facade.load).toHaveBeenCalledWith('c1', 'opening-stock');
    expect(q('import-title')?.textContent).toContain('stocks de départ');
    expect(q('import-identity')?.textContent).toContain('reference, location_code');
    expect(q('import-identity')?.textContent).toContain('2000');
  });

  it('describes every column the guide names, whichever way its heading comes', () => {
    const rows = fixture.nativeElement.querySelectorAll('[data-testid="import-columns"] tbody tr');
    expect(rows.length).toBe(2);
    expect(rows[0].textContent).toContain('reference');
    expect(rows[0].textContent).toContain('VIS-6X40');
    expect(rows[1].textContent).toContain('Quantité comptée');
  });

  it('offers the empty file in both formats, at the API address', () => {
    expect(q('import-template-csv')?.getAttribute('href')).toBe(
      '/api/companies/c1/import-templates/opening-stock.csv',
    );
    expect(q('import-template-xlsx')?.getAttribute('href')).toBe(
      '/api/companies/c1/import-templates/opening-stock.xlsx',
    );
  });

  it('will not import until a preview has come back with nothing rejected', async () => {
    expect((q('import-preview') as HTMLButtonElement).disabled).toBe(true);

    chooseFile();
    await settle();
    expect((q('import-preview') as HTMLButtonElement).disabled).toBe(false);
    expect((q('import-store') as HTMLButtonElement).disabled).toBe(true);

    facade.run.mockImplementation(async () => report.set(CLEAN));
    await click('import-preview');

    expect(facade.run).toHaveBeenCalledWith(
      'c1',
      'opening-stock',
      expect.anything(),
      'create',
      true,
    );
    expect((q('import-store') as HTMLButtonElement).disabled).toBe(false);
  });

  it('keeps the import shut when the preview rejected a row, and says why in the person’s words', async () => {
    chooseFile();
    await settle();
    facade.run.mockImplementation(async () => report.set(REJECTED));
    await click('import-preview');

    expect((q('import-store') as HTMLButtonElement).disabled).toBe(true);
    expect(q('import-nothing-stored')).not.toBeNull();
    expect(q('import-rejections')?.textContent).toContain('Déjà présent à la ligne 2 du fichier.');
  });

  /** A code this screen does not translate yet must still read as a sentence, not as the code itself. */
  it('falls back to the API’s own words for a reason it does not know', async () => {
    chooseFile();
    await settle();
    facade.run.mockImplementation(async () =>
      report.set({
        committed: false,
        created: [],
        updated: [],
        rejected: [
          {
            line: 2,
            column: 'quantity',
            code: 'a_reason_from_tomorrow',
            params: {},
            message: 'A quantity is a decimal number.',
          },
        ],
      }),
    );
    await click('import-preview');

    expect(q('import-rejections')?.textContent).toContain('A quantity is a decimal number.');
    expect(q('import-rejections')?.textContent).not.toContain('a_reason_from_tomorrow');
  });

  it('imports once previewed, and says what was stored', async () => {
    chooseFile();
    await settle();
    facade.run.mockImplementation(async () => report.set(CLEAN));
    await click('import-preview');

    facade.run.mockImplementation(async () => {
      report.set({ committed: true, created: [2, 3], updated: [], rejected: [] });
      TestBed.inject(Feedback).success('import.stored', { created: 2, updated: 0 });
    });
    await click('import-store');

    expect(facade.run).toHaveBeenLastCalledWith(
      'c1',
      'opening-stock',
      expect.anything(),
      'create',
      false,
    );
    expect((TestBed.inject(Feedback) as RecordedFeedback).said).toContainEqual(
      expect.objectContaining({ key: 'import.stored' }),
    );
  });

  it('shows a file refused whole beside the form, naming what the reason names', async () => {
    refusal.set({ reason: 'unknown_columns', columns: ['nom', 'prenom'], limit: null });
    await settle();

    expect(q('import-refusal')?.textContent).toContain('nom, prenom');
  });

  it('says so plainly when the subject cannot be imported here', async () => {
    guide.set(null);
    error.set('not_found');
    await settle();

    expect(q('import-error')).not.toBeNull();
    expect(q('import-columns')).toBeNull();
  });
});
