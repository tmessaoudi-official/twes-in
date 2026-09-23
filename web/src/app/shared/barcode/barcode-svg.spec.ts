// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { describe, expect, it } from 'vitest';
import { BarcodeSvg } from './barcode-svg';
import { linearBarcode } from './linear-barcode';

describe('BarcodeSvg', () => {
  function render(value: string): HTMLElement {
    const fixture = TestBed.createComponent(BarcodeSvg);
    fixture.componentRef.setInput('value', value);
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  it('draws one bar per run of dark modules, where the encoder puts it, inside a ten-module quiet zone', () => {
    const code = linearBarcode('4006381333931')!;
    const expected: string[] = [];
    for (const match of code.modules.matchAll(/1+/g))
      expected.push(`${match.index}:${match[0].length}`);

    const svg = render('4006381333931').querySelector('svg')!;
    const bars = [...svg.querySelectorAll('rect[data-bar]')].map(
      (rect) => `${Number(rect.getAttribute('x')) - 10}:${rect.getAttribute('width')}`,
    );
    expect(bars).toEqual(expected);
    expect(svg.getAttribute('viewBox')?.split(' ')[2]).toBe(String(95 + 20));
    expect(svg.getAttribute('role')).toBe('img');
    expect(svg.getAttribute('aria-label')).toBe('4006381333931');
  });

  it('draws nothing for a value no symbology here can carry', () => {
    expect(render('Café').querySelector('svg')).toBeNull();
  });
});
