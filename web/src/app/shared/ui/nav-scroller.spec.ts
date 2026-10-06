// SPDX-License-Identifier: AGPL-3.0-or-later

import { Component, signal } from '@angular/core';
import { type ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter, Router, RouterLink, RouterLinkActive } from '@angular/router';
import { NavScroller } from './nav-scroller';

@Component({ selector: 'app-nav-scroller-blank', template: '' })
class Blank {}

@Component({
  imports: [NavScroller, RouterLink, RouterLinkActive],
  template: `
    <nav appNavScroller data-testid="scroller">
      @for (place of places(); track place) {
        <a
          [routerLink]="'/' + place"
          routerLinkActive
          #active="routerLinkActive"
          [attr.aria-current]="active.isActive ? 'page' : null"
          [attr.data-testid]="place"
          >{{ place }}</a
        >
      }
    </nav>
  `,
})
class Host {
  readonly places = signal(['first', 'middle', 'last']);
}

// docs/SPEC.md § 7, 2026-09-26 12:05 (row 152): a fade marks any edge of a menu with more behind it, and the entry of
// the page on view is kept in view.
describe('NavScroller', () => {
  let fixture: ComponentFixture<Host>;
  const revealed: Element[] = [];
  let original: typeof Element.prototype.scrollIntoView;

  const scroller = () =>
    fixture.nativeElement.querySelector('[data-testid="scroller"]') as HTMLElement;

  /** jsdom lays nothing out: the scroll box is described instead. */
  function box(top: number, visible: number, total: number): void {
    const element = scroller();
    Object.defineProperty(element, 'scrollTop', { configurable: true, value: top });
    Object.defineProperty(element, 'clientHeight', { configurable: true, value: visible });
    Object.defineProperty(element, 'scrollHeight', { configurable: true, value: total });
    element.dispatchEvent(new Event('scroll'));
    fixture.detectChanges();
  }

  beforeEach(async () => {
    original = Element.prototype.scrollIntoView;
    Element.prototype.scrollIntoView = function (this: Element) {
      revealed.push(this);
    };
    revealed.length = 0;
    await TestBed.configureTestingModule({
      imports: [Host],
      providers: [
        provideRouter([
          { path: 'first', component: Blank },
          { path: 'middle', component: Blank },
          { path: 'last', component: Blank },
        ]),
      ],
    }).compileComponents();
    fixture = TestBed.createComponent(Host);
    fixture.detectChanges();
    await fixture.whenStable();
  });

  afterEach(() => {
    Element.prototype.scrollIntoView = original;
  });

  it('marks each edge with more behind it, and only those', () => {
    box(0, 400, 400);
    expect(scroller().hasAttribute('data-more-above')).toBe(false);
    expect(scroller().hasAttribute('data-more-below')).toBe(false);

    box(0, 400, 700);
    expect(scroller().hasAttribute('data-more-above')).toBe(false);
    expect(scroller().hasAttribute('data-more-below')).toBe(true);

    box(150, 400, 700);
    expect(scroller().hasAttribute('data-more-above')).toBe(true);
    expect(scroller().hasAttribute('data-more-below')).toBe(true);

    box(300, 400, 700);
    expect(scroller().hasAttribute('data-more-above')).toBe(true);
    expect(scroller().hasAttribute('data-more-below')).toBe(false);
  });

  it('brings the entry of the page on view into view after each navigation', async () => {
    await TestBed.inject(Router).navigateByUrl('/last');
    fixture.detectChanges();
    await fixture.whenStable();
    expect(revealed.at(-1)?.getAttribute('data-testid')).toBe('last');

    await TestBed.inject(Router).navigateByUrl('/middle');
    fixture.detectChanges();
    await fixture.whenStable();
    expect(revealed.at(-1)?.getAttribute('data-testid')).toBe('middle');
  });

  it('brings the entry into view once it appears, though the menu drew it after the navigation', async () => {
    // Audit 2026-10-06 V-9: the module entries arrive with the signed-in state, after the first navigation ended.
    fixture.componentInstance.places.set([]);
    fixture.detectChanges();
    await TestBed.inject(Router).navigateByUrl('/last');
    fixture.detectChanges();
    await fixture.whenStable();
    revealed.length = 0;

    fixture.componentInstance.places.set(['first', 'middle', 'last']);
    fixture.detectChanges();
    await fixture.whenStable();
    // RouterLinkActive marks a new link active a microtask later, then asks for the render that draws it so.
    fixture.detectChanges();
    expect(revealed.map((entry) => entry.getAttribute('data-testid'))).toEqual(['last']);

    // Another render with the same entry leaves the menu where the person scrolled it.
    fixture.componentInstance.places.set(['first', 'middle', 'last', 'after']);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
    expect(revealed).toHaveLength(1);
  });

  it('brings the same entry back when the menu’s layout moves while nobody scrolled, and not once somebody did', async () => {
    await TestBed.inject(Router).navigateByUrl('/last');
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
    box(0, 400, 700);
    revealed.length = 0;

    box(0, 400, 760);
    expect(revealed.map((entry) => entry.getAttribute('data-testid'))).toEqual(['last']);

    // A box made shorter below the menu hides it too.
    box(0, 380, 760);
    expect(revealed).toHaveLength(2);

    box(120, 400, 820);
    expect(revealed).toHaveLength(2);
  });
});
