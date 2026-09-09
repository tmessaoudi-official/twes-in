// SPDX-License-Identifier: AGPL-3.0-or-later
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { App } from './app';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      app: { name: 'twes-in', tagline: 'Facturation' },
      health: {
        api: 'API',
        ok: 'opérationnelle',
        degraded: 'dégradée',
        unreachable: 'injoignable',
        checking: 'vérification…',
      },
    });
  }
}

describe('App', () => {
  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [App],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
      ],
    }).compileComponents();
  });

  afterEach(() => TestBed.inject(HttpTestingController).verify());

  async function render() {
    const fixture = TestBed.createComponent(App);
    await fixture.whenStable();
    return {
      fixture,
      el: fixture.nativeElement as HTMLElement,
      http: TestBed.inject(HttpTestingController),
    };
  }

  it('renders the product name as the page heading', async () => {
    const { el, http } = await render();
    http.expectOne('/api/health').flush({ status: 'ok', database: 'ok' });
    expect(el.querySelector('h1')?.textContent).toContain('twes-in');
  });

  it('reports the API as operational when /api/health answers ok', async () => {
    const { fixture, el, http } = await render();
    http.expectOne('/api/health').flush({ status: 'ok', database: 'ok' });
    await fixture.whenStable();
    expect(el.querySelector('[data-testid="api-status"]')?.textContent).toContain('opérationnelle');
  });

  it('reports the API as degraded when /api/health answers 503 with a degraded body', async () => {
    const { fixture, el, http } = await render();
    http
      .expectOne('/api/health')
      .flush(
        { status: 'degraded', database: 'unreachable' },
        { status: 503, statusText: 'Service Unavailable' },
      );
    await fixture.whenStable();
    expect(el.querySelector('[data-testid="api-status"]')?.textContent).toContain('dégradée');
  });

  it('reports the API as unreachable when the request fails outright', async () => {
    const { fixture, el, http } = await render();
    http.expectOne('/api/health').error(new ProgressEvent('error'));
    await fixture.whenStable();
    expect(el.querySelector('[data-testid="api-status"]')?.textContent).toContain('injoignable');
  });
});
