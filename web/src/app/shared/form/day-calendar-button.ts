// SPDX-License-Identifier: AGPL-3.0-or-later

import { Overlay, type OverlayRef } from '@angular/cdk/overlay';
import { TemplatePortal } from '@angular/cdk/portal';
import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  inject,
  Injectable,
  input,
  OnDestroy,
  signal,
  TemplateRef,
  viewChild,
  ViewContainerRef,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { MatButtonModule } from '@angular/material/button';
import {
  DateAdapter,
  MAT_DATE_FORMATS,
  MAT_DATE_LOCALE,
  MAT_NATIVE_DATE_FORMATS,
} from '@angular/material/core';
import { MatDatepickerIntl, MatCalendar } from '@angular/material/datepicker';
import { MatIconModule } from '@angular/material/icon';
import { TranslateService, TranslatePipe } from '@ngx-translate/core';
import { Label } from '../a11y/label';
import { todayIn } from '../i18n/format';
import { FormatFacade } from '../i18n/format-facade';
import { DayAdapter, dateOfDay, dayOfDate } from './day-adapter';
import { DayInput } from './day-input';

/** Material's calendar labels, from `form.calendar` in the translation files instead of its built-in English. */
@Injectable()
class TranslatedDatepickerIntl extends MatDatepickerIntl {
  private readonly translate = inject(TranslateService);

  constructor() {
    super();
    this.translate
      .stream('form.calendar')
      .pipe(takeUntilDestroyed())
      .subscribe(() => this.relabel());
  }

  private relabel(): void {
    const label = (key: string): string => this.translate.instant(`form.calendar.${key}`);
    this.calendarLabel = label('calendar');
    this.openCalendarLabel = label('open');
    this.prevMonthLabel = label('previous_month');
    this.nextMonthLabel = label('next_month');
    this.prevYearLabel = label('previous_year');
    this.nextYearLabel = label('next_year');
    this.prevMultiYearLabel = label('previous_years');
    this.nextMultiYearLabel = label('next_years');
    this.switchToMonthViewLabel = label('choose_date');
    this.switchToMultiYearViewLabel = label('choose_month_and_year');
    this.changes.next();
  }
}

/**
 * The calendar beside a day field: a button that opens Material's calendar over the page and hands the day chosen to
 * the field, which shows it in the company's format. The calendar is the standalone `mat-calendar`, with the locale's
 * own first day of the week.
 */
@Component({
  selector: 'app-day-calendar-button',
  imports: [MatButtonModule, MatIconModule, MatCalendar, Label, TranslatePipe],
  providers: [
    { provide: DateAdapter, useClass: DayAdapter },
    { provide: MAT_DATE_FORMATS, useValue: MAT_NATIVE_DATE_FORMATS },
    { provide: MAT_DATE_LOCALE, useFactory: () => inject(FormatFacade).locale() },
    { provide: MatDatepickerIntl, useClass: TranslatedDatepickerIntl },
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <button
      mat-icon-button
      type="button"
      #opener
      [appLabel]="'form.calendar.open' | translate"
      (click)="toggle()"
      data-testid="day-calendar-open"
    >
      <mat-icon aria-hidden="true">calendar_month</mat-icon>
    </button>
    <ng-template #panel>
      <div
        class="w-72 rounded-card border border-outline bg-surface shadow-lg"
        data-testid="day-calendar"
      >
        <mat-calendar
          [selected]="selected()"
          [startAt]="startAt()"
          (selectedChange)="pick($event)"
        />
      </div>
    </ng-template>
  `,
})
export class DayCalendarButton implements OnDestroy {
  readonly field = input.required<DayInput>();

  private readonly overlay = inject(Overlay);
  private readonly container = inject(ViewContainerRef);
  private readonly opener = viewChild.required('opener', { read: ElementRef<HTMLElement> });
  private readonly panel = viewChild.required<TemplateRef<unknown>>('panel');
  private ref: OverlayRef | null = null;
  protected readonly selected = signal<Date | null>(null);
  protected readonly startAt = signal<Date>(new Date());

  protected toggle(): void {
    if (this.ref) {
      this.close();
      return;
    }
    const held = dateOfDay(this.field().day());
    this.selected.set(held);
    // An empty field opens on today without marking it as the value.
    this.startAt.set(held ?? dateOfDay(todayIn(null)) ?? new Date());
    const origin = this.opener().nativeElement;
    this.ref = this.overlay.create({
      positionStrategy: this.overlay
        .position()
        .flexibleConnectedTo(origin)
        .withPositions([
          { originX: 'end', originY: 'bottom', overlayX: 'end', overlayY: 'top', offsetY: 4 },
          { originX: 'end', originY: 'top', overlayX: 'end', overlayY: 'bottom', offsetY: -4 },
        ])
        .withPush(true),
      scrollStrategy: this.overlay.scrollStrategies.reposition(),
      panelClass: 'twes-day-calendar-panel',
    });
    this.ref.attach(new TemplatePortal(this.panel(), this.container));
    this.ref.outsidePointerEvents().subscribe((event) => {
      if (!origin.contains(event.target as Node)) this.close();
    });
    this.ref.keydownEvents().subscribe((event) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        this.close();
        origin.focus();
      }
    });
  }

  protected pick(date: Date | null): void {
    if (date) this.field().choose(dayOfDate(date));
    this.close();
    this.field().focus();
  }

  ngOnDestroy(): void {
    this.close();
  }

  private close(): void {
    this.ref?.dispose();
    this.ref = null;
  }
}
