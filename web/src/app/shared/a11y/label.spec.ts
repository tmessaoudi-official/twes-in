// SPDX-License-Identifier: AGPL-3.0-or-later

import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { MatTooltip } from '@angular/material/tooltip';
import { Label } from './label';

@Component({
  imports: [Label],
  template: `<button type="button" [appLabel]="name()" matTooltipPosition="right">x</button>`,
})
class Host {
  readonly name = signal('Fermer');
}

describe('Label', () => {
  it('names the control and says the same in its tooltip, following every change', async () => {
    const fixture = TestBed.createComponent(Host);
    await fixture.whenStable();
    const button = fixture.debugElement.query(By.css('button'));
    const tooltip = button.injector.get(MatTooltip);

    expect(button.nativeElement.getAttribute('aria-label')).toBe('Fermer');
    expect(tooltip.message).toBe('Fermer');
    expect(tooltip.position).toBe('right');

    fixture.componentInstance.name.set('Close');
    await fixture.whenStable();

    expect(button.nativeElement.getAttribute('aria-label')).toBe('Close');
    expect(tooltip.message).toBe('Close');
  });
});
