// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { FormControl, ReactiveFormsModule } from '@angular/forms';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { provideTranslateService } from '@ngx-translate/core';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { formatDay } from '../i18n/format';
import { FormatFacade } from '../i18n/format-facade';
import { DayCalendarButton } from './day-calendar-button';
import { DayInput } from './day-input';

@Component({
  imports: [ReactiveFormsModule, DayInput, DayCalendarButton],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <input appDay #day="appDay" [formControl]="control" data-testid="day" />
    <app-day-calendar-button [field]="day" />
  `,
})
class Host {
  readonly control = new FormControl<string>('2026-09-05', { nonNullable: true });
}

describe('DayCalendarButton', () => {
  let fixture: ComponentFixture<Host>;

  const settle = async (): Promise<void> => {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  };
  const calendar = (): HTMLElement | null =>
    document.body.querySelector('[data-testid="day-calendar"]');

  beforeEach(async () => {
    TestBed.configureTestingModule({
      providers: [
        provideTranslateService(),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        {
          provide: FormatFacade,
          useValue: {
            locale: () => 'fr-TN',
            dateFormat: () => 'auto',
            day: (value: string) => formatDay(value, 'fr-TN', 'auto'),
          },
        },
      ],
    });
    fixture = TestBed.createComponent(Host);
    await settle();
  });

  afterEach(() => {
    document.body.querySelectorAll('.cdk-overlay-container').forEach((overlay) => overlay.remove());
  });

  it('opens on the day the field holds, weeks starting on Monday, and hands a chosen day to the field', async () => {
    fixture.nativeElement.querySelector('[data-testid="day-calendar-open"]').click();
    await settle();

    expect(calendar()).not.toBeNull();
    const header = Array.from(document.body.querySelectorAll('.mat-calendar-table-header th')).map(
      (th) => th.textContent?.trim().toLowerCase(),
    );
    expect(header[0]).toMatch(/^l/);
    const cell = Array.from(
      document.body.querySelectorAll<HTMLElement>('.mat-calendar-body-cell'),
    ).find((c) => c.textContent?.trim() === '17')!;
    cell.click();
    await settle();

    expect(fixture.componentInstance.control.value).toBe('2026-09-17');
    expect(fixture.nativeElement.querySelector('input').value).toBe('17/09/2026');
    expect(calendar()).toBeNull();
  });

  it('closes on Escape without changing the day', async () => {
    fixture.nativeElement.querySelector('[data-testid="day-calendar-open"]').click();
    await settle();
    document.body
      .querySelector('.twes-day-calendar-panel')!
      .dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    await settle();

    expect(calendar()).toBeNull();
    expect(fixture.componentInstance.control.value).toBe('2026-09-05');
  });
});
