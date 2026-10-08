// SPDX-License-Identifier: AGPL-3.0-or-later

import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter, Router, RouterOutlet } from '@angular/router';
import { RouteFocus } from './route-focus';

@Component({
  selector: 'app-customers',
  template: '<h1>Clients</h1><button type="button">Page 2</button>',
})
class Customers {}

@Component({ selector: 'app-invoices', template: '<h1>Factures</h1>' })
class Invoices {}

@Component({ selector: 'app-headless', template: '<p>Rien à lire</p>' })
class Headless {}

@Component({
  imports: [RouterOutlet, RouteFocus],
  template: '<main appRouteFocus tabindex="-1"><router-outlet /></main>',
})
class Shell {}

describe('RouteFocus', () => {
  async function at(url: string): Promise<void> {
    await TestBed.inject(Router).navigateByUrl(url);
    await fixture.whenStable();
  }

  let fixture: ReturnType<typeof TestBed.createComponent<Shell>>;

  beforeEach(async () => {
    TestBed.configureTestingModule({
      providers: [
        provideRouter([
          { path: 'customers', component: Customers },
          { path: 'invoices', component: Invoices },
          { path: 'headless', component: Headless },
        ]),
      ],
    });
    fixture = TestBed.createComponent(Shell);
    await at('/customers');
  });

  function focused(): string {
    return (document.activeElement?.textContent ?? '').trim();
  }

  it('leaves focus where it is on the first page, which the browser opened', () => {
    expect(document.activeElement).toBe(document.body);
  });

  it("moves focus to the new page's heading, so a screen reader starts there", async () => {
    await at('/invoices');

    expect(document.activeElement?.tagName).toBe('H1');
    expect(focused()).toBe('Factures');
    expect(document.activeElement?.getAttribute('tabindex')).toBe('-1');
  });

  it('moves focus to the main region on a page with no heading', async () => {
    await at('/headless');

    expect(document.activeElement?.tagName).toBe('MAIN');
  });

  it('keeps focus where it is when only the query changes, as a list moving to its next page does', async () => {
    const button = fixture.nativeElement.querySelector('button') as HTMLButtonElement;
    button.focus();

    await at('/customers?page=2');

    expect(document.activeElement).toBe(button);
  });
});
