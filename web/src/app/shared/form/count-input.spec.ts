// SPDX-License-Identifier: AGPL-3.0-or-later

import { Component } from '@angular/core';
import { type ComponentFixture, TestBed } from '@angular/core/testing';
import { FormControl, ReactiveFormsModule, Validators } from '@angular/forms';
import { CountInput, typedCount } from './count-input';

@Component({
  imports: [ReactiveFormsModule, CountInput],
  template: `<input appCount type="text" [formControl]="count" data-testid="count" />`,
})
class Host {
  readonly count = new FormControl<number | null>(3, { validators: [Validators.max(10)] });
}

/**
 * Row 242: a whole number is typed in a text field with the numeric keyboard, never `type="number"`, which hands the
 * form an empty value for a French « 1,5 » and for anything else it cannot read, so the person's typing is lost
 * unsaid. What is typed is read as a number; one that is not whole, or no number at all, is said.
 */
describe('CountInput', () => {
  let fixture: ComponentFixture<Host>;
  let host: Host;
  const field = (): HTMLInputElement =>
    fixture.nativeElement.querySelector('[data-testid="count"]');

  function type(value: string): void {
    field().value = value;
    field().dispatchEvent(new Event('input'));
    fixture.detectChanges();
  }

  beforeEach(() => {
    fixture = TestBed.createComponent(Host);
    host = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('shows the number the form holds, in a text field with the numeric keyboard', () => {
    expect(field().value).toBe('3');
    expect(field().type).toBe('text');
    expect(field().getAttribute('inputmode')).toBe('numeric');
  });

  it('hands the form the whole number typed, its invisible ends left out', () => {
    type(' 12​');
    expect(host.count.value).toBe(12);
    expect(host.count.hasError('max')).toBe(true);
    type('-2');
    expect(host.count.value).toBe(-2);
    expect(host.count.valid).toBe(true);
  });

  it('refuses a number that is not whole, written with a comma or a point', () => {
    for (const typed of ['1,5', '1.5']) {
      type(typed);
      expect(host.count.value).toBe(1.5);
      expect(host.count.hasError('wholeNumber')).toBe(true);
    }
  });

  it('refuses what is no number at all, rather than holding nothing', () => {
    type('douze');
    expect(host.count.hasError('wholeNumber')).toBe(true);
  });

  it('holds nothing for an emptied field, which required alone refuses', () => {
    type('  ');
    expect(host.count.value).toBeNull();
    expect(host.count.valid).toBe(true);
  });

  it('reads a count as typed the way the platform page does', () => {
    expect(typedCount('')).toBeNaN();
    expect(typedCount('1,5')).toBe(1.5);
    expect(typedCount('x')).toBe(Number.POSITIVE_INFINITY);
  });
});
