// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { MatDialog } from '@angular/material/dialog';
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
import { CHANGES_SHOWN, ImportPage } from './import-page';
import type { ImportGuide, ImportRefusal, ImportReport } from './import-types';
import { WINDOW_CLASS, type WindowClass } from '../shared/ui/window-class';
import { FormatFacade } from '../shared/i18n/format-facade';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      import: {
        title: 'Importer des {{subject}}',
        subjects: { opening_stock: 'stocks de départ' },
        opening_stock: { reference: 'Référence' },
        identity: 'Une ligne est retrouvée par : {{columns}}. Au plus {{rows}} lignes par fichier.',
        preview: 'Prévisualiser',
        store: 'Importer',
        rejected: '{{count}} refusés',
        unchanged: '{{count}} inchangés',
        line: 'Ligne',
        column: 'Colonne',
        changes_title: 'Ce qui change',
        changes_by_column: '{{column}} : {{count}}',
        changes_shown: 'Les {{shown}} premiers changements sur {{count}}.',
        before: 'Avant',
        after: 'Après',
        rejections: {
          duplicate_in_file: 'Déjà présent à la ligne {{line}} du fichier.',
          gross_unreachable:
            'Aucun prix HT n’arrive à {{gross}} TTC ; les plus proches sont {{below}} et {{above}}.',
        },
        notes: {
          reference_given: 'Référence donnée : {{reference}}.',
          stock_change: 'Stock à {{location}} : {{before}} → {{after}}.',
        },
        stock: { recount: 'Recompter', recount_note: 'Compte à nouveau.' },
        already_imported: 'Ce fichier a déjà été importé le {{date}}.',
        refusals: {
          unknown_columns: 'Colonnes inconnues : {{columns}}.',
          invalid_pairing: 'À associer de nouveau : {{columns}}.',
        },
        pairing: {
          title: 'Vos en-têtes',
          heading: 'En-tête du fichier',
          column: 'Colonne',
          ignored: 'Ne pas importer',
          from_last: 'Repris de votre dernier import.',
          forget: 'Oublier l’association de {{heading}}',
        },
        encoding: {
          label: 'Encodage du fichier',
          auto: 'Automatique',
          'utf-8': 'UTF-8',
          'windows-1252': 'Windows-1252 (français)',
          'windows-1256': 'Windows-1256 (arabe)',
          'utf-16': 'UTF-16 (Texte Unicode d’Excel)',
          read_as:
            'Fichier lu en {{encoding}}. Des lettres illisibles ? Choisissez l’encodage du fichier.',
        },
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
  switches: [
    { key: 'recount', labelKey: 'import.stock.recount', noteKey: 'import.stock.recount_note' },
  ],
  lastPairing: [],
};

const CLEAN: ImportReport = {
  committed: false,
  created: [2, 3],
  updated: [],
  rejected: [],
  notes: [],
  alreadyImportedAt: null,
  encoding: null,
  changes: [],
  unchanged: [],
};
/** An upsert's preview: line 2 changes two columns, line 3 one, line 4 nothing. */
const CHANGED: ImportReport = {
  committed: false,
  created: [],
  updated: [2, 3],
  rejected: [],
  notes: [],
  alreadyImportedAt: null,
  encoding: null,
  changes: [
    { line: 2, column: 'name', before: 'Carthage', after: 'Carthage Conseil' },
    { line: 2, column: 'email', before: '', after: 'contact@carthage.tn' },
    { line: 3, column: 'name', before: 'Sousse Bois', after: 'Sousse Bois et Fils' },
  ],
  unchanged: [4],
};
const REJECTED: ImportReport = {
  committed: false,
  created: [2],
  updated: [],
  rejected: [
    { line: 3, column: 'reference', code: 'duplicate_in_file', params: { line: 2 }, message: 'x' },
  ],
  notes: [],
  alreadyImportedAt: null,
  encoding: null,
  changes: [],
  unchanged: [],
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
  const dialog = { open: vi.fn() };
  let fixture: ComponentFixture<ImportPage>;
  const width = signal<WindowClass>('expanded');

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
    Object.defineProperty(input, 'files', {
      value: { item: () => file, length: 1 },
      configurable: true,
    });
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
    width.set('expanded');
    dialog.open.mockReset().mockReturnValue({ afterClosed: () => of(true) });
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
        { provide: WINDOW_CLASS, useValue: width },
        { provide: MatDialog, useValue: dialog },
        // The screen's decimal separator and a moment as words, as the working locale would write them.
        {
          provide: FormatFacade,
          useValue: {
            decimal: (value: string) => value.replace('.', ','),
            moment: (value: string) => `9 oct. 2026 (${value})`,
          },
        },
      ],
    });
    fixture = TestBed.createComponent(ImportPage);
    fixture.componentRef.setInput('subject', 'opening-stock');
    await settle();
  });

  it('asks the API what this company’s file holds, and says what a row is found again by', async () => {
    expect(facade.load).toHaveBeenCalledWith('c1', 'opening-stock');
    expect(q('import-title')?.textContent).toContain('stocks de départ');
    // A column is named as its heading reads, its key beside it as the file writes it.
    expect(q('import-identity')?.textContent).toContain('Référence (reference), location_code');
    expect(q('import-identity')?.textContent).toContain('2000');
  });

  it('asks for the file before it describes the columns, which are there to consult', () => {
    const file = q('import-file');
    const columns = q('import-columns');
    expect(file).not.toBeNull();
    expect(columns).not.toBeNull();
    expect(file!.compareDocumentPosition(columns!) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
  });

  it('describes each column as an entry of a list on a phone, never a table cut at the edge', async () => {
    width.set('compact');
    await settle();

    expect(q('import-columns')?.tagName).toBe('UL');
    const entries = fixture.nativeElement.querySelectorAll('[data-testid="import-columns"] > li');
    expect(entries.length).toBe(2);
    expect(entries[0].textContent).toContain('reference');
    expect(entries[0].textContent).toContain('Référence');
    expect(entries[0].textContent).toContain('VIS-6X40');
    expect(entries[1].textContent).toContain('Quantité comptée');
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
      [],
      null,
      [],
    );
    expect((q('import-store') as HTMLButtonElement).disabled).toBe(false);
  });

  // docs/SPEC.md § 7, 2026-10-09 10:40 (6): « Recompter » is the file's own choice, sent with the run it was ticked for.
  it('sends the switches ticked, and a tick asks for a new preview', async () => {
    chooseFile();
    await settle();
    facade.run.mockImplementation(async () => report.set(CLEAN));
    await click('import-preview');
    expect((q('import-store') as HTMLButtonElement).disabled).toBe(false);
    expect(q('import-switches')?.textContent).toContain('Compte à nouveau.');

    (q('import-switch-recount')!.querySelector('input') as HTMLInputElement).click();
    await settle();

    expect((q('import-store') as HTMLButtonElement).disabled).toBe(true);
    await click('import-preview');
    expect(facade.run).toHaveBeenLastCalledWith(
      'c1',
      'opening-stock',
      expect.anything(),
      'create',
      true,
      ['recount'],
      null,
      [],
    );
  });

  it('offers the encoding for a CSV only, found on the file until one is chosen', async () => {
    expect(q('import-encoding')).toBeNull();
    chooseFile('stocks.xlsx');
    await settle();
    expect(q('import-encoding')).toBeNull();

    chooseFile('stocks.csv');
    await settle();
    expect(q('import-encoding')?.textContent).toContain('Windows-1256 (arabe)');
    await click('import-preview');
    expect(facade.run).toHaveBeenLastCalledWith(
      'c1',
      'opening-stock',
      expect.anything(),
      'create',
      true,
      [],
      null,
      [],
    );
  });

  it('sends the encoding chosen, which asks for a new preview, and a new file starts from none', async () => {
    chooseFile();
    await settle();
    facade.run.mockImplementation(async () => report.set(CLEAN));
    await click('import-preview');
    expect((q('import-store') as HTMLButtonElement).disabled).toBe(false);

    (q('import-encoding-windows-1256')!.querySelector('input') as HTMLInputElement).click();
    await settle();

    expect((q('import-store') as HTMLButtonElement).disabled).toBe(true);
    await click('import-preview');
    expect(facade.run).toHaveBeenLastCalledWith(
      'c1',
      'opening-stock',
      expect.anything(),
      'create',
      true,
      [],
      'windows-1256',
      [],
    );

    chooseFile('other.csv');
    await settle();
    await click('import-preview');
    expect(facade.run).toHaveBeenLastCalledWith(
      'c1',
      'opening-stock',
      expect.anything(),
      'create',
      true,
      [],
      null,
      [],
    );
  });

  it('says first what a CSV was read as, and what to do when its letters do not read', async () => {
    chooseFile();
    await settle();
    facade.run.mockImplementation(async () => report.set({ ...CLEAN, encoding: 'windows-1252' }));
    await click('import-preview');

    const said = q('import-read-as');
    expect(said?.textContent).toContain(
      'Fichier lu en Windows-1252 (français). Des lettres illisibles',
    );
    expect(said?.compareDocumentPosition(q('import-created')!)).toBe(
      Node.DOCUMENT_POSITION_FOLLOWING,
    );
  });

  it('says before confirming that this very file was already imported', async () => {
    chooseFile();
    await settle();
    facade.run.mockImplementation(async () =>
      report.set({ ...CLEAN, alreadyImportedAt: '2026-10-08T15:00:00+00:00' }),
    );
    await click('import-preview');

    expect(q('import-already-imported')?.textContent).toContain(
      'Ce fichier a déjà été importé le 9 oct. 2026 (2026-10-08T15:00:00+00:00).',
    );
  });

  it('writes the stock before and after a row with the screen’s decimal separator', async () => {
    chooseFile();
    await settle();
    facade.run.mockImplementation(async () =>
      report.set({
        ...CLEAN,
        notes: [
          {
            line: 2,
            column: 'stock_count',
            code: 'stock_change',
            params: { location: 'Z1', before: '0.000', after: '7.500' },
          },
        ],
      }),
    );
    await click('import-preview');

    expect(q('import-notes')?.textContent).toContain('Stock à Z1 : 0,000 → 7,500.');
    expect(q('import-already-imported')).toBeNull();
  });

  // docs/SPEC.md § 7, 2026-09-17 (3): what a row was noted for, in the person's words, without stopping the file.
  it('lists what the rows imported were noted for, in the person’s words', async () => {
    chooseFile();
    await settle();
    facade.run.mockImplementation(async () =>
      report.set({
        ...CLEAN,
        notes: [
          {
            line: 2,
            column: 'reference',
            code: 'reference_given',
            params: { reference: 'ART-00042' },
          },
          { line: 3, column: 'name', code: 'from_tomorrow', params: {} },
        ],
      }),
    );
    await click('import-preview');

    const rows = Array.from(q('import-notes')!.querySelectorAll('tbody tr')).map((row) =>
      Array.from(row.children)
        .map((cell) => cell.textContent?.trim())
        .join(' '),
    );
    expect(rows).toEqual(['2 reference Référence donnée : ART-00042.', '3 name from_tomorrow']);
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

  it('writes the prices a refusal names with the screen’s decimal separator', async () => {
    chooseFile();
    await settle();
    facade.run.mockImplementation(async () =>
      report.set({
        ...REJECTED,
        rejected: [
          {
            line: 2,
            column: 'unit_price_gross',
            code: 'gross_unreachable',
            params: { gross: '9.999', below: '9.998', above: '10.000' },
            message: 'x',
          },
        ],
      }),
    );
    await click('import-preview');

    expect(q('import-rejections')?.textContent).toContain(
      'Aucun prix HT n’arrive à 9,999 TTC ; les plus proches sont 9,998 et 10,000.',
    );
  });

  /** A row says every fault it holds, so the count beside « créés » counts rows, as it does, not faults. */
  it('lists each fault of a row and counts the rows refused', async () => {
    chooseFile();
    await settle();
    facade.run.mockImplementation(async () =>
      report.set({
        ...REJECTED,
        rejected: [
          { line: 3, column: 'email', code: 'invalid_value', params: {}, message: 'x' },
          { line: 3, column: 'website', code: 'invalid_value', params: {}, message: 'x' },
          { line: 3, column: null, code: 'invalid_value', params: {}, message: 'x' },
          { line: 4, column: 'email', code: 'invalid_value', params: {}, message: 'x' },
        ],
      }),
    );
    await click('import-preview');

    expect(q('import-rejected-count')?.textContent?.trim()).toBe('2 refusés');
    expect(q('import-rejections')?.querySelectorAll('tbody tr').length).toBe(4);
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
        notes: [],
        alreadyImportedAt: null,
        encoding: null,
        changes: [],
        unchanged: [],
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
      report.set({
        committed: true,
        created: [2, 3],
        updated: [],
        rejected: [],
        notes: [],
        alreadyImportedAt: null,
        encoding: null,
        changes: [],
        unchanged: [],
      });
      TestBed.inject(Feedback).success('import.stored', { created: 2, updated: 0 });
    });
    await click('import-store');

    expect(facade.run).toHaveBeenLastCalledWith(
      'c1',
      'opening-stock',
      expect.anything(),
      'create',
      false,
      [],
      null,
      [],
    );
    expect((TestBed.inject(Feedback) as RecordedFeedback).said).toContainEqual(
      expect.objectContaining({ key: 'import.stored' }),
    );
  });

  /** Upsert, then a preview that answers `answer`: a change of mode forgets any preview before it. */
  async function previewUpsert(answer: ImportReport): Promise<void> {
    chooseFile();
    await settle();
    (
      fixture.nativeElement.querySelector(
        '[data-testid="import-mode"] mat-radio-button[value="upsert"] input',
      ) as HTMLInputElement
    ).click();
    await settle();
    facade.run.mockImplementation(async () => report.set(answer));
    await click('import-preview');
  }

  it('says what an upsert changes in each record, column by column, and counts what it leaves alone', async () => {
    await previewUpsert(CHANGED);

    expect(q('import-unchanged')?.textContent).toContain('1 inchangés');
    // Each column with the lines it changes, in the order the changes come.
    const columns = [
      ...fixture.nativeElement.querySelectorAll('[data-testid="import-changed-columns"] > li'),
    ].map((item: Element) => item.textContent!.trim());
    expect(columns).toEqual(['name : 2', 'email : 1']);
    const rows = [
      ...fixture.nativeElement.querySelectorAll('[data-testid="import-changes"] tbody tr'),
    ].map((row: Element) =>
      [...row.querySelectorAll('th, td')].map((cell) => cell.textContent!.trim()),
    );
    expect(rows).toEqual([
      ['2', 'name', 'Carthage', 'Carthage Conseil'],
      ['2', 'email', '', 'contact@carthage.tn'],
      ['3', 'name', 'Sousse Bois', 'Sousse Bois et Fils'],
    ]);
    expect(q('import-changes-shown')).toBeNull();
  });

  it('lists the first changes only, and says how many there are in all', async () => {
    const many = Array.from({ length: CHANGES_SHOWN + 5 }, (_, at) => ({
      line: at + 2,
      column: 'name',
      before: `old ${at}`,
      after: `new ${at}`,
    }));
    await previewUpsert({ ...CHANGED, updated: many.map((change) => change.line), changes: many });

    expect(
      fixture.nativeElement.querySelectorAll('[data-testid="import-changes"] tbody tr').length,
    ).toBe(CHANGES_SHOWN);
    expect(q('import-changes-shown')?.textContent).toContain(
      `Les ${CHANGES_SHOWN} premiers changements sur ${CHANGES_SHOWN + 5}.`,
    );
    expect(q('import-changed-columns')?.textContent).toContain(`name : ${CHANGES_SHOWN + 5}`);
  });

  it('asks before an upsert changes records, naming how many and which columns, and imports nothing on « non »', async () => {
    await previewUpsert(CHANGED);
    dialog.open.mockReturnValue({ afterClosed: () => of(false) });
    facade.run.mockClear();

    await click('import-store');

    expect(dialog.open.mock.calls.map(([, config]) => config.data)).toEqual([
      {
        kind: 'corrigeable',
        title: 'import.confirm_changes.title',
        message: 'import.confirm_changes.message',
        messageParams: { records: '2', fields: 'name (2), email (1)' },
        confirmLabel: 'import.confirm_changes.store',
        keepLabel: 'import.confirm_changes.keep',
      },
    ]);
    expect(facade.run).not.toHaveBeenCalled();

    dialog.open.mockReturnValue({ afterClosed: () => of(true) });
    await click('import-store');

    expect(facade.run).toHaveBeenLastCalledWith(
      'c1',
      'opening-stock',
      expect.anything(),
      'upsert',
      false,
      [],
      null,
      [],
    );
  });

  it('imports without asking when the upsert changes nothing already there', async () => {
    await previewUpsert({ ...CLEAN, unchanged: [4] });
    facade.run.mockClear();

    await click('import-store');

    expect(dialog.open).not.toHaveBeenCalled();
    expect(facade.run).toHaveBeenCalledTimes(1);
    expect(q('import-changes')).toBeNull();
  });

  it('shows a file refused whole beside the form, naming what the reason names', async () => {
    refusal.set({
      reason: 'unknown_columns',
      columns: ['nom', 'prenom'],
      limit: null,
      encoding: null,
    });
    await settle();

    expect(q('import-refusal')?.textContent).toContain('nom, prenom');
  });

  describe('the file’s own headings', () => {
    /** The pairing offered by the last import: a heading read as a column, and one set aside. */
    const KEPT = [
      { heading: 'Réf', column: 'reference' },
      { heading: 'Remarque', column: '' },
    ];

    /** A Select as a person uses it: opened, then the option taken. Its panel hangs off the body, not the page. */
    async function choose(testId: string, value: string): Promise<void> {
      q(testId)!.click();
      await settle();
      const option = Array.from(
        document.body.querySelectorAll<HTMLElement>('[role="option"]'),
      ).find((each) => each.getAttribute('data-testid') === `${testId}-option-${value}`);
      expect(option, `${testId} offers ${value}`).toBeDefined();
      option!.click();
      await settle();
    }

    const rows = (): HTMLElement[] =>
      Array.from(fixture.nativeElement.querySelectorAll('[data-testid="import-pairing"] li'));
    /** What a row's Select shows as chosen. */
    const shown = (index: number): string =>
      (q(`import-pairing-${index}`)?.textContent ?? '').replace(/expand_more/, '').trim();
    const lastPairing = (): unknown => facade.run.mock.lastCall?.[7];

    beforeEach(() => {
      // As the real facade does: a new answer is asked for, the last one is gone.
      facade.forget.mockImplementation(() => {
        refusal.set(null);
        report.set(null);
      });
    });

    it('lists the pairing the last import kept before any preview, a heading set aside saying so', async () => {
      guide.set({ ...GUIDE, lastPairing: KEPT });
      await settle();

      expect(q('import-pairing-from-last')?.textContent).toContain(
        'Repris de votre dernier import.',
      );
      expect(rows().map((row) => row.querySelector('[data-heading]')?.textContent?.trim())).toEqual(
        ['Réf', 'Remarque'],
      );
      expect(shown(0)).toBe('Référence (reference)');
      expect(shown(1)).toBe('Ne pas importer');

      chooseFile();
      await settle();
      await click('import-preview');
      expect(lastPairing()).toEqual(KEPT);
    });

    it('shows no pairing at all while there is none to show', () => {
      expect(q('import-pairing')).toBeNull();
    });

    it('asks where each unknown heading goes, keeps asking while one is chosen, and sends the choice', async () => {
      chooseFile();
      await settle();
      facade.run.mockImplementation(async () =>
        refusal.set({
          reason: 'unknown_columns',
          columns: ['Qté', 'Note'],
          limit: null,
          encoding: null,
        }),
      );
      await click('import-preview');
      expect(rows().length).toBe(2);
      expect(shown(0)).not.toContain('Quantité');

      await choose('import-pairing-0', 'quantity');

      // The refusal is gone with the choice, the headings it named are not: the other one is still to pair.
      expect(refusal()).toBeNull();
      expect(rows().length).toBe(2);
      expect(shown(0)).toBe('Quantité comptée (quantity)');
      expect((q('import-store') as HTMLButtonElement).disabled).toBe(true);

      await choose('import-pairing-1', 'ignored');
      facade.run.mockImplementation(async () => report.set(CLEAN));
      await click('import-preview');
      expect(lastPairing()).toEqual([
        { heading: 'Qté', column: 'quantity' },
        { heading: 'Note', column: '' },
      ]);
      expect((q('import-store') as HTMLButtonElement).disabled).toBe(false);
    });

    it('forgets a pairing, so the heading is read as the file writes it again', async () => {
      guide.set({ ...GUIDE, lastPairing: KEPT });
      await settle();

      await click('import-pairing-forget-1');

      expect(rows().length).toBe(1);
      chooseFile();
      await settle();
      await click('import-preview');
      expect(lastPairing()).toEqual([{ heading: 'Réf', column: 'reference' }]);
    });

    it('asks again about a heading whose kept pairing names a column no longer there', async () => {
      guide.set({ ...GUIDE, lastPairing: [{ heading: 'Réf', column: 'reference_old' }] });
      await settle();
      chooseFile();
      await settle();
      facade.run.mockImplementation(async () =>
        refusal.set({ reason: 'invalid_pairing', columns: ['Réf'], limit: null, encoding: null }),
      );
      await click('import-preview');

      expect(q('import-refusal')?.textContent).toContain('À associer de nouveau : Réf.');
      expect(rows().length).toBe(1);
      expect(shown(0)).not.toContain('reference_old');

      facade.run.mockImplementation(async () => report.set(CLEAN));
      await click('import-preview');
      expect(lastPairing()).toEqual([]);
    });

    it('keeps the pairing for a new file, and forgets the headings the file before asked about', async () => {
      guide.set({ ...GUIDE, lastPairing: [KEPT[0]] });
      await settle();
      chooseFile();
      await settle();
      facade.run.mockImplementation(async () =>
        refusal.set({ reason: 'unknown_columns', columns: ['Qté'], limit: null, encoding: null }),
      );
      await click('import-preview');
      expect(rows().length).toBe(2);

      chooseFile('autre.csv');
      await settle();

      expect(rows().length).toBe(1);
      expect(shown(0)).toBe('Référence (reference)');
    });

    it('names each forget button after its heading', async () => {
      guide.set({ ...GUIDE, lastPairing: KEPT });
      await settle();

      expect(q('import-pairing-forget-0')?.getAttribute('aria-label')).toBe(
        'Oublier l’association de Réf',
      );
    });
  });

  it('says so plainly when the subject cannot be imported here', async () => {
    guide.set(null);
    error.set('not_found');
    await settle();

    expect(q('import-error')).not.toBeNull();
    expect(q('import-columns')).toBeNull();
  });
});
