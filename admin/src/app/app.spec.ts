/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { TestBed } from '@angular/core/testing';

import { App } from './app';
import { BRANDING } from './branding';

describe('App', () => {
  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [App],
    }).compileComponents();
  });

  it('should create the app', () => {
    const fixture = TestBed.createComponent(App);

    expect(fixture.componentInstance).toBeTruthy();
  });

  /**
   * The product name comes from configuration, not from a literal in the template.
   *
   * This is licensing invariant 9 asserted rather than trusted: overriding the token must change what
   * renders. A hardcoded name would pass a "renders the name" test and fail this one, which is the whole
   * point — deployment starts with no public domain, and a later public one must be a config change.
   */
  it('renders the product name from the BRANDING token rather than a hardcoded string', async () => {
    TestBed.resetTestingModule();
    await TestBed.configureTestingModule({
      imports: [App],
      providers: [
        {
          provide: BRANDING,
          useValue: { productName: 'A Different Name', supportEmail: 'x@example.invalid' },
        },
      ],
    }).compileComponents();

    const fixture = TestBed.createComponent(App);
    await fixture.whenStable();

    const heading = (fixture.nativeElement as HTMLElement).querySelector('h1');

    expect(heading?.textContent).toContain('A Different Name');
  });
});
