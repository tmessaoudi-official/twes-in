// SPDX-License-Identifier: AGPL-3.0-or-later

import { Component } from '@angular/core';
import { type ComponentFixture, TestBed } from '@angular/core/testing';
import { MatDialog } from '@angular/material/dialog';
import { provideRouter, Router } from '@angular/router';
import { LegalDialog } from './legal-dialog';
import { LegalLink } from './legal-link';

@Component({
  imports: [LegalLink],
  template: `<a appLegalLink="cookies" data-testid="link">Cookies</a>`,
})
class Host {}

// A legal text opened from the notice or the footer opens over the screen, which stays exactly as it was.
describe('LegalLink', () => {
  let fixture: ComponentFixture<Host>;
  const open = vi.fn();

  beforeEach(async () => {
    open.mockReset();
    await TestBed.configureTestingModule({
      imports: [Host],
      providers: [provideRouter([]), { provide: MatDialog, useValue: { open } }],
    }).compileComponents();
    fixture = TestBed.createComponent(Host);
    fixture.detectChanges();
  });

  const link = () =>
    (fixture.nativeElement as HTMLElement).querySelector<HTMLAnchorElement>(
      '[data-testid="link"]',
    )!;

  it('keeps the page address, so it can be opened in a tab or shared', () => {
    expect(link().getAttribute('href')).toBe('/legal/cookies');
    expect(link().getAttribute('aria-haspopup')).toBe('dialog');
  });

  it('opens the text in a panel over the screen instead of leaving it', () => {
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigateByUrl');
    const click = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 });

    link().dispatchEvent(click);

    expect(click.defaultPrevented).toBe(true);
    expect(navigate).not.toHaveBeenCalled();
    expect(open).toHaveBeenCalledWith(
      LegalDialog,
      expect.objectContaining({ data: { slug: 'cookies' } }),
    );
  });

  it('leaves a click asking for a new tab or window to the browser', () => {
    for (const init of [{ ctrlKey: true }, { metaKey: true }, { shiftKey: true }, { button: 1 }]) {
      const click = new MouseEvent('click', {
        bubbles: true,
        cancelable: true,
        button: 0,
        ...init,
      });
      link().dispatchEvent(click);
      expect(click.defaultPrevented, JSON.stringify(init)).toBe(false);
    }
    expect(open).not.toHaveBeenCalled();
  });
});
