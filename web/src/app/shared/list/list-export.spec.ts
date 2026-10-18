// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideTranslateService } from '@ngx-translate/core';
import { demoRefusedInterceptor } from '../demo/demo-refused-interceptor';
import { Feedback } from '../feedback/feedback';
import { FileSaver } from '../files/save-file';
import { StepUp } from '../step-up/step-up';
import { provideQuietFeedback, type RecordedFeedback } from '../testing/feedback';
import { ListExport } from './list-export';

describe('ListExport', () => {
  let request: ReturnType<typeof vi.fn<(intro?: string) => Promise<boolean>>>;
  let saved: string[];

  beforeEach(() => {
    request = vi.fn<(intro?: string) => Promise<boolean>>();
    saved = [];
  });

  function create(
    address: ((format: 'csv' | 'xlsx') => string) | null,
    prepare: ((address: string) => Promise<string | null>) | null = null,
  ) {
    TestBed.configureTestingModule({
      providers: [
        provideTranslateService({ lang: 'fr' }),
        // The app's own refusal of the demo, which says it once, in words, before the export hears of it.
        provideHttpClient(withInterceptors([demoRefusedInterceptor])),
        provideHttpClientTesting(),
        provideQuietFeedback(),
        { provide: StepUp, useValue: { request } },
        { provide: FileSaver, useValue: { save: (_file: Blob, name: string) => saved.push(name) } },
      ],
    });
    const fixture = TestBed.createComponent(ListExport);
    fixture.componentRef.setInput('address', address);
    fixture.componentRef.setInput('testId', 'things-export');
    if (prepare !== null) fixture.componentRef.setInput('prepare', prepare);
    fixture.detectChanges();
    return fixture;
  }

  /** Opens « Exporter ▾ » and finds one format's entry, which the menu draws over the page. */
  function button(fixture: ReturnType<typeof create>, format: 'csv' | 'xlsx'): HTMLButtonElement {
    open(fixture);
    return document.body.querySelector(`[data-testid="things-export-${format}"]`)!;
  }

  function open(fixture: ReturnType<typeof create>): void {
    (fixture.nativeElement.querySelector('[data-testid="things-export"]') as HTMLElement).click();
    fixture.detectChanges();
  }

  afterEach(() => {
    document.body.querySelectorAll('.cdk-overlay-container').forEach((overlay) => overlay.remove());
  });

  const refused = () =>
    new Blob([JSON.stringify({ error: 'step_up_required' })], { type: 'application/json' });

  /** Lets the component read a refused answer's body, which a Blob hands over on a later turn. */
  async function settle(): Promise<void> {
    await new Promise((resolve) => setTimeout(resolve));
  }

  it('shows nothing until the list has a search to hand over', () => {
    const fixture = create(null);

    expect(fixture.nativeElement.querySelector('button')).toBeNull();
  });

  it('offers the file in both formats from one « Exporter » menu, each a button, since a file may first ask who is at the screen', () => {
    const fixture = create((format) => `/exports/things.${format}`);
    const trigger = fixture.nativeElement.querySelector(
      '[data-testid="things-export"]',
    ) as HTMLButtonElement;

    expect(trigger.textContent).toContain('export.open');
    expect(trigger.getAttribute('aria-haspopup')).toBe('menu');
    expect(fixture.nativeElement.querySelectorAll('button').length).toBe(1);
    open(fixture);
    const buttons = [
      ...document.body.querySelectorAll('[data-testid^="things-export-"]'),
    ] as HTMLButtonElement[];

    expect(buttons.map((each) => each.getAttribute('data-testid'))).toEqual([
      'things-export-csv',
      'things-export-xlsx',
    ]);
    expect(buttons.every((each) => each.type === 'button')).toBe(true);
    expect(buttons.map((each) => each.getAttribute('data-address'))).toEqual([
      '/exports/things.csv',
      '/exports/things.xlsx',
    ]);
  });

  it('saves the file at once while a recent proof still holds, under the name the address gives', async () => {
    const fixture = create((format) => `/api/companies/c/exports/things.${format}?q=sonia`);

    button(fixture, 'xlsx').click();
    TestBed.inject(HttpTestingController)
      .expectOne('/api/companies/c/exports/things.xlsx?q=sonia')
      .flush(new Blob(['the file']));
    await settle();

    expect(request).not.toHaveBeenCalled();
    expect(saved).toEqual(['things.xlsx']);
  });

  it('saves the file under the name a screen gives when its address ends with no name of its own', async () => {
    const fixture = create(
      (format) => `/api/companies/c/reports/takings/export.${format}?from=2026-09-01`,
    );
    fixture.componentRef.setInput(
      'fileName',
      (address: string) => `takings.${address.split('?')[0].split('.').at(-1)}`,
    );
    fixture.detectChanges();

    button(fixture, 'csv').click();
    TestBed.inject(HttpTestingController)
      .expectOne('/api/companies/c/reports/takings/export.csv?from=2026-09-01')
      .flush(new Blob(['the file']));
    await settle();

    expect(saved).toEqual(['takings.csv']);
  });

  it('asks who is at the screen when the API wants it, then fetches the file again', async () => {
    request.mockResolvedValue(true);
    const fixture = create((format) => `/exports/things.${format}`);
    const http = TestBed.inject(HttpTestingController);

    button(fixture, 'csv').click();
    http
      .expectOne('/exports/things.csv')
      .flush(refused(), { status: 403, statusText: 'Forbidden' });
    await settle();
    expect(request).toHaveBeenCalledExactlyOnceWith('step_up.intro_export');
    http.expectOne('/exports/things.csv').flush(new Blob(['a,b']));
    await settle();

    expect(saved).toEqual(['things.csv']);
    expect((TestBed.inject(Feedback) as RecordedFeedback).said).toEqual([]);
  });

  it('saves nothing and says nothing when the person gives up the proof', async () => {
    request.mockResolvedValue(false);
    const fixture = create((format) => `/exports/things.${format}`);
    const http = TestBed.inject(HttpTestingController);

    button(fixture, 'csv').click();
    http
      .expectOne('/exports/things.csv')
      .flush(refused(), { status: 403, statusText: 'Forbidden' });
    await settle();

    http.expectNone('/exports/things.csv');
    expect(saved).toEqual([]);
    expect((TestBed.inject(Feedback) as RecordedFeedback).said).toEqual([]);
  });

  it('says the file could not be prepared when the API fails, and never asks for a proof then', async () => {
    const fixture = create((format) => `/exports/things.${format}`);

    button(fixture, 'csv').click();
    TestBed.inject(HttpTestingController)
      .expectOne('/exports/things.csv')
      .flush(new Blob(['']), { status: 503, statusText: 'Unavailable' });
    await settle();

    expect(request).not.toHaveBeenCalled();
    expect(saved).toEqual([]);
    expect((TestBed.inject(Feedback) as RecordedFeedback).said.map((toast) => toast.key)).toEqual([
      'export.failed',
    ]);
  });

  it("leaves the demo's refusal of an accountant's file to the one toast that says it", async () => {
    const fixture = create((format) => `/api/exports/sales-journal.${format}`);

    button(fixture, 'csv').click();
    TestBed.inject(HttpTestingController)
      .expectOne('/api/exports/sales-journal.csv')
      .flush(new Blob([JSON.stringify({ error: 'demo_refused' })]), {
        status: 403,
        statusText: 'Forbidden',
      });
    await vi.waitFor(() =>
      expect((TestBed.inject(Feedback) as RecordedFeedback).said).not.toEqual([]),
    );
    await settle();

    expect(request).not.toHaveBeenCalled();
    expect(saved).toEqual([]);
    expect((TestBed.inject(Feedback) as RecordedFeedback).said.map((toast) => toast.key)).toEqual([
      'auth.errors.demo_refused',
    ]);
  });

  // A selection is checked before its file is fetched, and only what passed goes in.
  it('fetches the address its prepare step hands back, and says which file it saved', async () => {
    const prepare = vi.fn((address: string) => Promise.resolve(`${address}&id[]=b`));
    const fixture = create((format) => `/exports/things.${format}?id[]=a`, prepare);
    const savedAddresses: string[] = [];
    fixture.componentInstance.saved.subscribe((address) => savedAddresses.push(address));

    button(fixture, 'csv').click();
    await settle();
    TestBed.inject(HttpTestingController)
      .expectOne('/exports/things.csv?id[]=a&id[]=b')
      .flush(new Blob(['a,b']));
    await settle();

    expect(prepare).toHaveBeenCalledExactlyOnceWith('/exports/things.csv?id[]=a');
    expect(saved).toEqual(['things.csv']);
    expect(savedAddresses).toEqual(['/exports/things.csv?id[]=a&id[]=b']);
  });

  it('fetches nothing when its prepare step calls the file off', async () => {
    const fixture = create(
      (format) => `/exports/things.${format}`,
      () => Promise.resolve(null),
    );

    button(fixture, 'csv').click();
    await settle();

    TestBed.inject(HttpTestingController).expectNone('/exports/things.csv');
    expect(saved).toEqual([]);
    expect((TestBed.inject(Feedback) as RecordedFeedback).said).toEqual([]);
  });

  // A disabled button loses the focus in a browser: a keyboard user would be left on the page's body after every file.
  it('keeps its button in reach while the file is fetched, marked busy, and fetches it once however often it is pressed', async () => {
    const fixture = create((format) => `/exports/things.${format}`);
    const trigger = fixture.nativeElement.querySelector(
      '[data-testid="things-export"]',
    ) as HTMLButtonElement;
    const http = TestBed.inject(HttpTestingController);

    button(fixture, 'csv').click();
    await settle();
    fixture.detectChanges();
    expect(trigger.disabled).toBe(false);
    expect(trigger.getAttribute('aria-busy')).toBe('true');
    button(fixture, 'csv').click();
    await settle();

    http.expectOne('/exports/things.csv').flush(new Blob(['a,b']));
    await settle();
    fixture.detectChanges();
    expect(saved).toEqual(['things.csv']);
    expect(trigger.getAttribute('aria-busy')).toBeNull();
  });

  // A screen offers other files through the same menu, the same proof and the same saving: « PDF ▾ » on a selection.
  it('offers the formats, words and icon a screen gives it, and saves the file under the name its address ends with', async () => {
    TestBed.configureTestingModule({
      providers: [
        provideTranslateService({ lang: 'fr' }),
        provideHttpClient(),
        provideHttpClientTesting(),
        provideQuietFeedback(),
        { provide: StepUp, useValue: { request } },
        { provide: FileSaver, useValue: { save: (_file: Blob, name: string) => saved.push(name) } },
      ],
    });
    const fixture = TestBed.createComponent(ListExport<'pdf' | 'zip'>);
    fixture.componentRef.setInput(
      'address',
      (format: 'pdf' | 'zip') => `/exports/things.${format}?id[]=a`,
    );
    fixture.componentRef.setInput('formats', ['pdf', 'zip']);
    fixture.componentRef.setInput('label', 'things.pdf');
    fixture.componentRef.setInput('entries', 'things.pdf_as');
    fixture.componentRef.setInput('icon', 'picture_as_pdf');
    fixture.componentRef.setInput('testId', 'things-pdf');
    fixture.detectChanges();
    const trigger = fixture.nativeElement.querySelector(
      '[data-testid="things-pdf"]',
    ) as HTMLButtonElement;

    expect(trigger.textContent).toContain('things.pdf');
    expect(trigger.textContent).not.toContain('export.open');
    expect(trigger.textContent).toContain('picture_as_pdf');
    trigger.click();
    fixture.detectChanges();
    const entries = [
      ...document.body.querySelectorAll('[data-testid^="things-pdf-"]'),
    ] as HTMLButtonElement[];
    expect(
      entries.map((each) => [each.getAttribute('data-testid'), each.textContent?.trim()]),
    ).toEqual([
      ['things-pdf-pdf', 'things.pdf_as.pdf'],
      ['things-pdf-zip', 'things.pdf_as.zip'],
    ]);

    entries[1].click();
    await settle();
    TestBed.inject(HttpTestingController)
      .expectOne('/exports/things.zip?id[]=a')
      .flush(new Blob(['PK']));
    await settle();

    expect(saved).toEqual(['things.zip']);
  });
});
