// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { LiveChanges } from '../shared/realtime/live-changes';
import { ReportsFacade } from './reports-facade';
import { ReportsPage } from './reports-page';
import type { ReportEntry } from './reports-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      reports: {
        none: 'Aucun rapport.',
        errors: { catalogue: 'Illisible.' },
        items: {
          takings: { title: 'Encaissements', description: 'Chaque jour.' },
          'dead-stock': { title: 'Stock dormant', description: 'Ce qui dort.' },
        },
      },
    });
  }
}

const entry = (key: string): ReportEntry => ({
  key,
  byEstablishment: true,
  periodic: true,
  columns: [],
});

describe('ReportsPage', () => {
  const catalogue = signal<readonly ReportEntry[] | null>(null);
  const failed = signal(false);
  const facade = {
    catalogue: catalogue.asReadonly(),
    catalogueFailed: failed.asReadonly(),
    loadCatalogue: vi.fn(),
  };
  const live = { reloadOn: vi.fn() };
  let fixture: ComponentFixture<ReportsPage>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  async function open(): Promise<void> {
    fixture = TestBed.createComponent(ReportsPage);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  beforeEach(() => {
    catalogue.set([entry('takings'), entry('dead-stock')]);
    failed.set(false);
    facade.loadCatalogue.mockReset().mockResolvedValue(undefined);
    live.reloadOn.mockReset();
    TestBed.configureTestingModule({
      imports: [ReportsPage],
      providers: [
        provideRouter([]),
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: ReportsFacade, useValue: facade },
        { provide: LiveChanges, useValue: live },
        { provide: AuthFacade, useValue: { me: () => ({ company: { id: 'k1' } }) } },
      ],
    });
  });

  it('offers each report the catalogue lists, in its order, each opening its own page', async () => {
    await open();

    expect(facade.loadCatalogue).toHaveBeenCalledWith('k1');
    const links = [
      ...fixture.nativeElement.querySelectorAll('[data-testid="reports-list"] a'),
    ] as HTMLAnchorElement[];
    expect(links.map((link) => link.getAttribute('href'))).toEqual([
      '/reports/takings',
      '/reports/dead-stock',
    ]);
    expect(q('report-takings')?.textContent).toContain('Encaissements');
    expect(q('report-takings')?.textContent).toContain('Chaque jour.');
  });

  it('reads the catalogue again when a module or a role changes', async () => {
    await open();

    const [kinds, reload] = live.reloadOn.mock.calls[0]!;
    expect(kinds).toEqual(['module', 'role']);
    await reload();
    expect(facade.loadCatalogue).toHaveBeenCalledTimes(2);
  });

  it('says when the role may read no report, and when the catalogue cannot be read', async () => {
    catalogue.set([]);
    failed.set(true);
    await open();

    expect(q('reports-none')?.textContent).toContain('Aucun rapport.');
    expect(q('reports-list')).toBeNull();
    expect(q('reports-failed')?.textContent).toContain('Illisible.');
  });
});
