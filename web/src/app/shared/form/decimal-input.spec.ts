// SPDX-License-Identifier: AGPL-3.0-or-later

import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { FormControl, ReactiveFormsModule } from '@angular/forms';
import { decimalShown, type NumberStyle } from '../i18n/format';
import { FormatFacade } from '../i18n/format-facade';
import { DecimalInput } from './decimal-input';

@Component({
  imports: [ReactiveFormsModule, DecimalInput],
  template: `<input appDecimal [formControl]="control" />`,
})
class Host {
  readonly control = new FormControl('890.000', { nonNullable: true });
}

/**
 * docs/SPEC.md § 7, 2026-09-19 21:55: a decimal field shows the screen's decimal separator and takes a comma or a
 * point, while the control keeps the API's own string, so validators, saving and merging never see a comma.
 */
describe('DecimalInput', () => {
  const locale = signal('fr-TN');
  const numberFormat = signal<NumberStyle>('auto');
  const decimal = (value: string) => decimalShown(value, locale(), numberFormat());

  function render(): { host: Host; input: HTMLInputElement } {
    TestBed.configureTestingModule({
      providers: [{ provide: FormatFacade, useValue: { locale, decimal } }],
    });
    const fixture = TestBed.createComponent(Host);
    fixture.detectChanges();
    const input = (fixture.nativeElement as HTMLElement).querySelector('input') as HTMLInputElement;
    return { host: fixture.componentInstance, input };
  }

  function type(input: HTMLInputElement, text: string): void {
    input.value = text;
    input.dispatchEvent(new Event('input'));
  }

  beforeEach(() => {
    locale.set('fr-TN');
    numberFormat.set('auto');
  });

  it('shows the separator of a number format the person chose, and follows a change of it', () => {
    numberFormat.set('comma-dot');
    const { host, input } = render();
    expect(input.value).toBe('890.000');

    numberFormat.set('dot-comma');
    TestBed.tick();

    expect(input.value).toBe('890,000');
    type(input, '12.5');
    expect(host.control.value).toBe('12.5');
  });

  it('shows the control’s value with the locale’s decimal separator', () => {
    const { host, input } = render();

    expect(input.value).toBe('890,000');

    host.control.setValue('12.5');
    expect(input.value).toBe('12,5');
  });

  it('keeps the API’s point in the control whether a comma or a point is typed', () => {
    const { host, input } = render();

    type(input, '890,5');
    expect(host.control.value).toBe('890.5');
    expect(input.value).toBe('890,5');

    type(input, '12.25');
    expect(host.control.value).toBe('12.25');
  });

  it('leaves what is not a decimal in the control, for its pattern to refuse', () => {
    const { host, input } = render();

    type(input, '12,5,3');

    expect(host.control.value).toBe('12,5,3');
  });

  it('shows what was typed the locale’s way once the field is left, and marks it touched', () => {
    const { host, input } = render();

    type(input, '12.25');
    input.dispatchEvent(new Event('blur'));

    expect(input.value).toBe('12,25');
    expect(host.control.touched).toBe(true);
  });

  it('writes a point under an English interface, and follows a change of language', () => {
    const { host, input } = render();

    locale.set('en-TN');
    TestBed.tick();

    expect(input.value).toBe('890.000');
    type(input, '7,5');
    expect(host.control.value).toBe('7.5');
  });

  it('follows the control being disabled', () => {
    const { host, input } = render();

    host.control.disable();

    expect(input.disabled).toBe(true);
  });
});
