// SPDX-License-Identifier: AGPL-3.0-or-later

import { Directionality } from '@angular/cdk/bidi';
import { TestBed } from '@angular/core/testing';
import { DocumentDirection } from './document-direction';

/** A MutationObserver reports after the current task's microtasks. */
const observed = () => new Promise<void>((resolve) => setTimeout(resolve));

describe('DocumentDirection', () => {
  beforeEach(() => {
    TestBed.resetTestingModule();
    document.documentElement.dir = '';
    document.body.dir = '';
  });

  afterEach(() => {
    TestBed.resetTestingModule();
    document.documentElement.dir = '';
  });

  it("starts from the document's direction", () => {
    document.documentElement.dir = 'rtl';

    TestBed.inject(DocumentDirection);

    expect(TestBed.inject(Directionality).value).toBe('rtl');
  });

  it("moves the components' direction with the document's, and says so once per change", async () => {
    TestBed.inject(DocumentDirection);
    const direction = TestBed.inject(Directionality);
    const said: string[] = [];
    direction.change.subscribe((value) => said.push(value));

    document.documentElement.dir = 'rtl';
    await observed();
    expect(direction.value).toBe('rtl');

    document.documentElement.dir = 'rtl';
    document.documentElement.lang = 'xx';
    await observed();

    document.documentElement.dir = 'ltr';
    await observed();
    expect(direction.value).toBe('ltr');
    expect(said).toEqual(['rtl', 'ltr']);
  });

  it('reads anything but rtl as left to right', async () => {
    TestBed.inject(DocumentDirection);
    document.documentElement.dir = 'rtl';
    await observed();

    document.documentElement.dir = 'auto';
    await observed();

    expect(TestBed.inject(Directionality).value).toBe('ltr');
  });
});
