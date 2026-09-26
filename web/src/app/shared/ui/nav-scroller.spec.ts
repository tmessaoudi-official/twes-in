// SPDX-License-Identifier: AGPL-3.0-or-later

import { Component } from '@angular/core';
import { type ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter, Router, RouterLink, RouterLinkActive } from '@angular/router';
import { NavScroller } from './nav-scroller';

@Component({ template: '' })
class Blank {}

@Component({
  imports: [NavScroller, RouterLink, RouterLinkActive],
  template: `
    <nav appNavScroller data-testid="scroller">
      @for (place of places; track place) {
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
  readonly places = ['first', 'middle', 'last'];
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
});
