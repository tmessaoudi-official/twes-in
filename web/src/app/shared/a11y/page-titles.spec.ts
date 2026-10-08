// SPDX-License-Identifier: AGPL-3.0-or-later

import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Title } from '@angular/platform-browser';
import { provideRouter, Router, TitleStrategy } from '@angular/router';
import { provideTranslateService, TranslateService } from '@ngx-translate/core';
import { Brand } from '../brand/brand';
import { PageTitles } from './page-titles';

@Component({ template: '' })
class Blank {}

class Installation extends Brand {
  readonly name = signal('Atelier');
  readonly tagline = signal('');
}

describe('PageTitles', () => {
  let translate: TranslateService;
  let installation: Installation;

  beforeEach(() => {
    installation = new Installation();
    TestBed.configureTestingModule({
      providers: [
        provideRouter([
          { path: 'customers', title: 'nav.customers', component: Blank },
          { path: 'untitled', component: Blank },
        ]),
        { provide: TitleStrategy, useClass: PageTitles },
        provideTranslateService({ lang: 'fr' }),
        { provide: Brand, useValue: installation },
      ],
    });
    translate = TestBed.inject(TranslateService);
    translate.setTranslation('fr', { nav: { customers: 'Clients' } });
    translate.setTranslation('en', { nav: { customers: 'Customers' } });
    translate.use('fr');
  });

  function title(): string {
    TestBed.tick();
    return TestBed.inject(Title).getTitle();
  }

  it('names the page in the interface language, then the installation', async () => {
    await TestBed.inject(Router).navigateByUrl('/customers');
    expect(title()).toBe('Clients · Atelier');

    translate.use('en');
    expect(title()).toBe('Customers · Atelier');
  });

  it('follows the name the installation gives itself', async () => {
    await TestBed.inject(Router).navigateByUrl('/customers');
    installation.name.set('Quincaillerie');

    expect(title()).toBe('Clients · Quincaillerie');
  });

  it('names the installation alone on a page that names nothing', async () => {
    await TestBed.inject(Router).navigateByUrl('/customers');
    await TestBed.inject(Router).navigateByUrl('/untitled');

    expect(title()).toBe('Atelier');
  });
});
