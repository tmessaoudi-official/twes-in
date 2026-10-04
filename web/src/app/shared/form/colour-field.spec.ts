// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { FormControl, ReactiveFormsModule } from '@angular/forms';
import { provideTranslateService } from '@ngx-translate/core';
import { beforeEach, describe, expect, it } from 'vitest';
import { ColourField } from './colour-field';

@Component({
  imports: [ReactiveFormsModule, ColourField],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<app-colour-field [formControl]="control" testId="accent" />`,
})
class Host {
  readonly control = new FormControl<string>('#1F6FEB', { nonNullable: true });
}

describe('ColourField', () => {
  let fixture: ComponentFixture<Host>;
  const q = (id: string): HTMLElement =>
    fixture.nativeElement.querySelector(`[data-testid="${id}"]`);
  const settle = async (): Promise<void> => {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  };

  beforeEach(async () => {
    TestBed.configureTestingModule({ providers: [provideTranslateService()] });
    fixture = TestBed.createComponent(Host);
    await settle();
  });

  it('shows the colour held as the chosen swatch and as its written value, in lower case', () => {
    expect(q('accent-swatch-blue').getAttribute('aria-checked')).toBe('true');
    expect(q('accent-swatch-red').getAttribute('aria-checked')).toBe('false');
    expect((q('accent') as HTMLInputElement).value).toBe('#1f6feb');
  });

  it('takes a swatch as the value, and marks it as the chosen one', async () => {
    q('accent-swatch-red').click();
    await settle();
    expect(fixture.componentInstance.control.value).toBe('#b3261e');
    expect(q('accent-swatch-red').getAttribute('aria-checked')).toBe('true');
    expect(q('accent-swatch-blue').getAttribute('aria-checked')).toBe('false');
  });

  it('takes a written colour, and flags text that is not one without dropping it', async () => {
    const input = q('accent') as HTMLInputElement;
    input.value = '#ABCDEF';
    input.dispatchEvent(new Event('input'));
    await settle();
    expect(fixture.componentInstance.control.value).toBe('#abcdef');
    expect(fixture.componentInstance.control.errors).toBeNull();

    input.value = '#abc';
    input.dispatchEvent(new Event('input'));
    await settle();
    expect(fixture.componentInstance.control.errors).toEqual({ colour: true });
    expect(input.value).toBe('#abc');
  });
});
