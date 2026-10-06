// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideTranslateService } from '@ngx-translate/core';
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

  function create(address: ((format: 'csv' | 'xlsx') => string) | null) {
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
    const fixture = TestBed.createComponent(ListExport);
    fixture.componentRef.setInput('address', address);
    fixture.componentRef.setInput('testId', 'things-export');
    fixture.detectChanges();
    return fixture;
  }

  function button(fixture: ReturnType<typeof create>, format: 'csv' | 'xlsx'): HTMLButtonElement {
    return fixture.nativeElement.querySelector(`[data-testid="things-export-${format}"]`);
  }

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

  it('offers the file in both formats as buttons, since a file may first ask who is at the screen', () => {
    const fixture = create((format) => `/exports/things.${format}`);
    const buttons = [...fixture.nativeElement.querySelectorAll('button')] as HTMLButtonElement[];

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

  it('keeps its two buttons apart, which a bare inline host does not (the buttons touched)', () => {
    const fixture = create((format) => `/exports/things.${format}`);

    const host = fixture.nativeElement as HTMLElement;
    expect(host.classList.contains('inline-flex')).toBe(true);
    expect(host.classList.contains('gap-2')).toBe(true);
  });
});
