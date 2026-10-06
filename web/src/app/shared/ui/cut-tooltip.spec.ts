// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { MatTooltip } from '@angular/material/tooltip';
import { By } from '@angular/platform-browser';
import { CutTooltip } from './cut-tooltip';

@Component({
  imports: [CutTooltip],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<span appCutTooltip="Export comptable">Export comptable</span>`,
})
class Host {}

describe('CutTooltip', () => {
  function render(shown: number, whole: number) {
    TestBed.configureTestingModule({
      imports: [Host],
      providers: [{ provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } }],
    });
    const fixture = TestBed.createComponent(Host);
    fixture.detectChanges();
    const span = fixture.debugElement.query(By.directive(CutTooltip));
    // jsdom lays nothing out, so the widths a browser would measure are given here.
    Object.defineProperty(span.nativeElement, 'clientWidth', { value: shown });
    Object.defineProperty(span.nativeElement, 'scrollWidth', { value: whole });
    span.nativeElement.dispatchEvent(new MouseEvent('mouseenter'));
    return span.injector.get(MatTooltip);
  }

  it('says the whole text of a label its ellipsis cut', () => {
    const tooltip = render(80, 140);

    expect(tooltip.message).toBe('Export comptable');
    expect(tooltip.disabled).toBe(false);
  });

  it('stays quiet over a label shown whole', () => {
    const tooltip = render(140, 140);

    expect(tooltip.disabled).toBe(true);
  });
});
