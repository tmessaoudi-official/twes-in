// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { generate } from 'lean-qr';
import { QrCode } from './qr-code';

describe('QrCode', () => {
  function render(value: string): HTMLElement {
    const fixture = TestBed.createComponent(QrCode);
    fixture.componentRef.setInput('value', value);
    fixture.componentRef.setInput('label', 'Scan me');
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  it('draws exactly the modules the encoder sets, inside a four-module quiet zone', () => {
    const value =
      'otpauth://totp/twes-in:someone%40twes.local?secret=JBSWY3DPEHPK3PXP&issuer=twes-in';
    const code = generate(value);
    const expected: string[] = [];
    for (let y = 0; y < code.size; y += 1) {
      for (let x = 0; x < code.size; x += 1) {
        if (code.get(x, y)) {
          expected.push(`${x},${y}`);
        }
      }
    }

    const svg = render(value).querySelector('svg')!;
    const drawn = [...svg.querySelectorAll('rect[data-module]')].map(
      (rect) => `${Number(rect.getAttribute('x')) - 4},${Number(rect.getAttribute('y')) - 4}`,
    );

    expect(drawn).toEqual(expected);
    expect(svg.getAttribute('viewBox')).toBe(`0 0 ${code.size + 8} ${code.size + 8}`);
    expect(svg.getAttribute('role')).toBe('img');
    expect(svg.getAttribute('aria-label')).toBe('Scan me');
  });

  it('draws a different code for a different value', () => {
    const first = render('otpauth://totp/a?secret=AAAA').querySelectorAll(
      'rect[data-module]',
    ).length;
    const second = render('otpauth://totp/b?secret=BBBBBBBBBBBBBBBB').querySelectorAll(
      'rect[data-module]',
    ).length;
    expect(first).not.toBe(second);
  });

  it('never uses a style attribute, which the Content Security Policy refuses', () => {
    expect(render('otpauth://totp/x?secret=ABC').querySelectorAll('[style]')).toHaveLength(0);
  });
});
