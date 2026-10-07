// SPDX-License-Identifier: AGPL-3.0-or-later

import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { type PageTab, PageTabs } from './page-tabs';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({ tabs: { label: 'Clients', list: 'Clients', groups: 'Groupes de clients' } });
  }
}

@Component({ selector: 'app-page-tabs-blank', template: '' })
class Blank {}

@Component({
  imports: [PageTabs],
  template: `<app-page-tabs [tabs]="tabs" label="tabs.label"
    ><p data-testid="page">La page</p></app-page-tabs
  >`,
})
class Host {
  readonly tabs: readonly PageTab[] = [
    { labelKey: 'tabs.list', route: '/customers', testId: 'customers-tab' },
    { labelKey: 'tabs.groups', route: '/customers/groups', testId: 'customer-groups-link' },
  ];
}

@Component({
  imports: [PageTabs],
  template: `<app-page-tabs [tabs]="tabs" label="tabs.label" selected="/customers"
    ><p>La page</p></app-page-tabs
  >`,
})
class NamedHost {
  readonly tabs = new Host().tabs;
}

describe('PageTabs', () => {
  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [Host],
      providers: [
        provideRouter([
          { path: 'customers', component: Blank },
          { path: 'customers/groups', component: Blank },
          { path: 'customers/elsewhere/:id', component: Blank },
        ]),
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
      ],
    }).compileComponents();
  });

  it("links a feature's screens as named tabs around the page, the open one selected", async () => {
    const fixture = TestBed.createComponent(Host);
    await TestBed.inject(Router).navigateByUrl('/customers/groups');
    fixture.detectChanges();
    await fixture.whenStable();
    // RouterLinkActive settles its state in a microtask after the links first render; a second pass shows it.
    fixture.detectChanges();
    await fixture.whenStable();
    const el = fixture.nativeElement as HTMLElement;
    const tab = (id: string) => el.querySelector<HTMLElement>(`[data-testid="${id}"]`);

    expect(el.querySelector('nav')?.getAttribute('aria-label')).toBe('Clients');
    expect(tab('customers-tab')?.getAttribute('href')).toBe('/customers');
    expect(tab('customers-tab')?.textContent).toContain('Clients');
    expect(tab('customer-groups-link')?.getAttribute('href')).toBe('/customers/groups');
    // The list tab is not selected on its child screen: each tab matches its own address exactly.
    expect(tab('customers-tab')?.getAttribute('aria-selected')).toBe('false');
    expect(tab('customer-groups-link')?.getAttribute('aria-selected')).toBe('true');
    expect(tab('page')?.closest('[role="tabpanel"]')).not.toBeNull();
  });

  it('selects the tab a page names, when the page opens at an address of its own', async () => {
    // Audit 2026-10-06 V-23 (a): count mode opened from a location's QR code selected no tab.
    const fixture = TestBed.createComponent(NamedHost);
    await TestBed.inject(Router).navigateByUrl('/customers/elsewhere/7');
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
    const el = fixture.nativeElement as HTMLElement;
    const selected = (id: string) =>
      el.querySelector(`[data-testid="${id}"]`)?.getAttribute('aria-selected');

    expect(selected('customers-tab')).toBe('true');
    expect(selected('customer-groups-link')).toBe('false');
  });
});
