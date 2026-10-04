// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { FormControl, ReactiveFormsModule } from '@angular/forms';
import { beforeEach, describe, expect, it } from 'vitest';
import { formatDay, type DateFormat } from '../i18n/format';
import { FormatFacade } from '../i18n/format-facade';
import { DayInput } from './day-input';

@Component({
  imports: [ReactiveFormsModule, DayInput],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<input appDay [formControl]="control" data-testid="day" />`,
})
class Host {
  readonly control = new FormControl<string>('2026-09-05', { nonNullable: true });
}

describe('DayInput', () => {
  const style = signal<DateFormat>('auto');
  let fixture: ComponentFixture<Host>;
  let input: HTMLInputElement;

  const type = (text: string): void => {
    input.value = text;
    input.dispatchEvent(new Event('input'));
  };
  const leave = (): void => {
    input.dispatchEvent(new Event('blur'));
  };

  beforeEach(async () => {
    style.set('auto');
    TestBed.configureTestingModule({
      providers: [
        {
          provide: FormatFacade,
          useValue: {
            locale: () => 'fr-TN',
            dateFormat: () => style(),
            day: (value: string) => formatDay(value, 'fr-TN', style()),
          },
        },
      ],
    });
    fixture = TestBed.createComponent(Host);
    fixture.detectChanges();
    await fixture.whenStable();
    input = fixture.nativeElement.querySelector('input');
  });

  it('shows the day as the company writes it while the control keeps the ISO day', () => {
    expect(input.value).toBe('05/09/2026');
    expect(fixture.componentInstance.control.value).toBe('2026-09-05');
  });

  it('takes a typed day in the company order and keeps its ISO form, shown in full once left', () => {
    type('7-10-2026');
    expect(fixture.componentInstance.control.value).toBe('2026-10-07');
    expect(fixture.componentInstance.control.errors).toBeNull();
    leave();
    expect(input.value).toBe('07/10/2026');
  });

  it('flags what is not a day instead of dropping it, and keeps the text for the person to correct', () => {
    type('31/02/2026');
    leave();
    expect(fixture.componentInstance.control.errors).toEqual({ date: true });
    expect(input.value).toBe('31/02/2026');
  });

  it('is empty, and valid, when cleared', () => {
    type('');
    expect(fixture.componentInstance.control.value).toBe('');
    expect(fixture.componentInstance.control.errors).toBeNull();
  });

  it('follows a chosen date format', async () => {
    style.set('ymd');
    fixture.componentInstance.control.setValue('2026-09-05');
    await fixture.whenStable();
    expect(input.value).toBe('2026-09-05');
  });

  it('can be disabled with its control', () => {
    fixture.componentInstance.control.disable();
    expect(input.disabled).toBe(true);
  });
});
