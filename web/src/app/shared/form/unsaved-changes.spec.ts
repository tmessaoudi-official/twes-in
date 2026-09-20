// SPDX-License-Identifier: AGPL-3.0-or-later

import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { MatDialog } from '@angular/material/dialog';
import type { Route } from '@angular/router';
import { of } from 'rxjs';
import { guardUnsaved, UnsavedChanges, unsavedGuard } from './unsaved-changes';

@Component({ template: '' })
class Page {
  readonly changes = signal(0);
  constructor() {
    TestBed.inject(UnsavedChanges).declare(this.changes);
  }
}

describe('UnsavedChanges', () => {
  let answer: boolean | undefined;
  let asked: number;

  function service(): UnsavedChanges {
    return TestBed.inject(UnsavedChanges);
  }

  async function leaving(): Promise<boolean> {
    return new Promise((resolve) => service().confirmLeave().subscribe(resolve));
  }

  beforeEach(() => {
    answer = true;
    asked = 0;
    TestBed.configureTestingModule({
      imports: [Page],
      providers: [
        {
          provide: MatDialog,
          useValue: {
            open: () => {
              asked += 1;
              return { afterClosed: () => of(answer) };
            },
          },
        },
      ],
    });
  });

  it('lets a page nobody changed go without asking', async () => {
    const page = TestBed.createComponent(Page);
    page.detectChanges();

    expect(await leaving()).toBe(true);
    expect(asked).toBe(0);
  });

  it('asks when something is unsaved, and keeps the person there on a no', async () => {
    const page = TestBed.createComponent(Page);
    page.componentInstance.changes.set(3);
    page.detectChanges();
    answer = false;

    expect(await leaving()).toBe(false);
    expect(asked).toBe(1);
  });

  it('treats a dismissed question as staying, never as leaving', async () => {
    // Escape answers `undefined`: reading anything but an explicit yes as "go" would throw the work away on the
    // key a person presses to get rid of a dialog they did not read.
    const page = TestBed.createComponent(Page);
    page.componentInstance.changes.set(1);
    page.detectChanges();
    answer = undefined;

    expect(await leaving()).toBe(false);
  });

  it('lets a page through once when it says it saved, and asks again the time after', () => {
    // Narrow on purpose: the statement is consumed by the very navigation that follows the save, so it cannot
    // leave a later, genuine leave unguarded.
    const page = TestBed.createComponent(Page);
    page.componentInstance.changes.set(4);
    page.detectChanges();
    answer = false;

    service().savedAndLeaving();
    let first: boolean | undefined;
    service()
      .confirmLeave()
      .subscribe((allowed) => (first = allowed));
    expect([first, asked]).toEqual([true, 0]);

    let second: boolean | undefined;
    service()
      .confirmLeave()
      .subscribe((allowed) => (second = allowed));
    expect([second, asked]).toEqual([false, 1]);
  });

  it('stops asking once the page has been saved', async () => {
    const page = TestBed.createComponent(Page);
    page.componentInstance.changes.set(2);
    page.detectChanges();
    page.componentInstance.changes.set(0);

    expect(await leaving()).toBe(true);
    expect(asked).toBe(0);
  });

  it('forgets a page that is gone, so the next one is not asked about its changes', async () => {
    const page = TestBed.createComponent(Page);
    page.componentInstance.changes.set(5);
    page.detectChanges();
    expect(service().count()).toBe(5);

    page.destroy();

    expect(service().count()).toBe(0);
    expect(await leaving()).toBe(true);
  });

  it('counts every form on the page, not just the first', () => {
    const one = TestBed.createComponent(Page);
    const two = TestBed.createComponent(Page);
    one.componentInstance.changes.set(2);
    two.componentInstance.changes.set(3);
    one.detectChanges();
    two.detectChanges();

    expect(service().count()).toBe(5);
  });
});

describe('guardUnsaved', () => {
  it('guards every route given, and every child of one, so none can be forgotten', () => {
    const routes: Route[] = [
      { path: 'customers' },
      { path: '', children: [{ path: 'products' }, { path: 'invoices/:id' }] },
    ];

    const guarded = guardUnsaved(routes);

    expect(guarded[0].canDeactivate).toEqual([unsavedGuard]);
    expect(guarded[1].children?.map((child) => child.canDeactivate)).toEqual([
      [unsavedGuard],
      [unsavedGuard],
    ]);
  });

  it("keeps a route's own guard and adds this one after it", () => {
    const own = () => true;

    const [guarded] = guardUnsaved([{ path: 'x', canDeactivate: [own] }]);

    expect(guarded.canDeactivate).toEqual([own, unsavedGuard]);
  });

  it('leaves the route it was given untouched, since routes are read after this runs', () => {
    const routes: Route[] = [{ path: 'customers' }];

    guardUnsaved(routes);

    expect(routes[0].canDeactivate).toBeUndefined();
  });
});
