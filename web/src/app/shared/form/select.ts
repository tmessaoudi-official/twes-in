// SPDX-License-Identifier: AGPL-3.0-or-later

import { Overlay, type OverlayRef } from '@angular/cdk/overlay';
import { TemplatePortal } from '@angular/cdk/portal';
import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  ElementRef,
  TemplateRef,
  ViewContainerRef,
  computed,
  forwardRef,
  inject,
  input,
  signal,
  viewChild,
} from '@angular/core';
import { NG_VALUE_ACCESSOR, type ControlValueAccessor } from '@angular/forms';
import { MatIconModule } from '@angular/material/icon';
import { toSignal } from '@angular/core/rxjs-interop';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';
import { WINDOW_CLASS } from '../ui/window-class';

/** One thing a Select offers: the value a control holds, and the words a person reads for it. */
export interface SelectOption {
  value: string;
  label: string;
  /** Names the option's row for a test that must pick it. */
  testId?: string;
}

/** Past this many options the panel gets a search box: fewer are quicker to read than to filter. */
export const SELECT_SEARCH_FROM = 8;
/** Past this many chosen values a multiple Select shows « +N » instead of one more chip. */
export const SELECT_CHIP_LIMIT = 3;

let nextId = 0;

/** What a person types and what an option reads as, compared without case or accents. */
function fold(text: string): string {
  return text.normalize('NFD').replace(/\p{M}/gu, '').toLowerCase();
}

/**
 * The one dropdown (docs/SPEC.md § 7, 2026-10-03 21:45, row 187): a button that opens a styled listbox, single or
 * multiple, in the place of every native and Material select. A search box appears past seven options; a multiple one
 * shows what is chosen as chips (then « +N ») and offers select-all and clear-all; the keyboard and a screen reader
 * get the combobox pattern; on a phone the panel is a sheet at the bottom of the window.
 *
 * It holds a value, never a record: a string, or an array of strings when `multiple`. Labels arrive already
 * translated, because the caller is the one who knows whether they are keys or a company's own words.
 */
@Component({
  selector: 'app-select',
  imports: [MatIconModule, TranslatePipe],
  providers: [{ provide: NG_VALUE_ACCESSOR, useExisting: forwardRef(() => Select), multi: true }],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (labelInside() && label() !== '') {
      <span
        [id]="labelId"
        class="pointer-events-none absolute -top-2 left-3 z-10 bg-surface px-1 text-xs leading-4 text-on-surface-variant"
        >{{ label() }}</span
      >
    }
    <button
      #trigger
      type="button"
      role="combobox"
      class="flex min-h-10 w-full items-center gap-1 rounded-control border border-outline bg-surface py-1.5 pr-2 pl-4 text-left text-base text-on-surface hover:border-on-surface focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent disabled:opacity-60"
      aria-haspopup="listbox"
      [id]="inputId() || null"
      [attr.aria-expanded]="open()"
      [attr.aria-controls]="open() ? listId : null"
      [attr.aria-labelledby]="ownLabel() ? ownLabel() + ' ' + valueId : null"
      [attr.aria-required]="required() || null"
      [attr.data-testid]="testId() || null"
      [disabled]="disabled()"
      (click)="toggle()"
      (keydown)="triggerKey($event)"
    >
      <span [id]="valueId" class="flex min-w-0 flex-1 flex-wrap items-center gap-1.5">
        @if (chosen().length === 0) {
          <span class="text-on-surface-variant">{{ 'select.placeholder' | translate }}</span>
        } @else if (multiple()) {
          @for (option of chipped(); track option.value) {
            <span
              data-chip
              class="rounded-full bg-secondary-container px-2.5 py-0.5 text-sm text-on-secondary-container"
              >{{ option.label }}</span
            >
          }
          @if (hiddenCount() > 0) {
            <span class="text-sm text-on-surface-variant">{{
              'select.more' | translate: { count: hiddenCount() }
            }}</span>
          }
        } @else {
          <span class="truncate">{{ chosen()[0].label }}</span>
        }
      </span>
      <mat-icon aria-hidden="true" class="shrink-0 text-on-surface-variant">expand_more</mat-icon>
    </button>

    <ng-template #panel>
      <div
        class="flex max-h-[60vh] flex-col overflow-hidden rounded-card border border-outline-variant bg-surface-container shadow-lg"
        tabindex="-1"
        (keydown)="panelKey($event)"
      >
        @if (searchable()) {
          <div class="flex items-center gap-2 border-b border-outline-variant px-3">
            <mat-icon aria-hidden="true" class="shrink-0 text-on-surface-variant">search</mat-icon>
            <input
              #searchBox
              type="text"
              class="min-h-12 w-full bg-transparent outline-none"
              autocomplete="off"
              [attr.aria-label]="'select.search' | translate"
              [attr.aria-controls]="listId"
              [attr.aria-activedescendant]="activeId()"
              [attr.data-testid]="testId() + '-search'"
              [value]="query()"
              (input)="typed($event)"
            />
          </div>
        }
        @if (multiple()) {
          <div class="flex justify-between gap-2 border-b border-outline-variant px-2 py-1">
            <button
              type="button"
              class="rounded px-3 py-2 text-sm text-accent-text hover:bg-accent-soft"
              [attr.data-testid]="testId() + '-select-all'"
              (click)="selectAll()"
            >
              {{ 'select.select_all' | translate }}
            </button>
            <button
              type="button"
              class="rounded px-3 py-2 text-sm text-accent-text hover:bg-accent-soft"
              [attr.data-testid]="testId() + '-clear-all'"
              (click)="clearAll()"
            >
              {{ 'select.clear_all' | translate }}
            </button>
          </div>
        }
        <ul
          #listbox
          role="listbox"
          tabindex="-1"
          class="overflow-y-auto py-1 outline-none"
          [id]="listId"
          [attr.aria-multiselectable]="multiple() ? 'true' : null"
          [attr.aria-labelledby]="labelledBy() || null"
          [attr.aria-activedescendant]="activeId()"
        >
          @for (option of shown(); track option.value; let index = $index) {
            <li
              role="option"
              class="flex min-h-12 cursor-pointer items-center gap-3 px-4 py-2 hover:bg-surface-container-high"
              [class.bg-surface-container-highest]="index === active()"
              [id]="optionId(index)"
              [attr.data-testid]="option.testId ?? null"
              [attr.aria-selected]="isChosen(option.value)"
              tabindex="-1"
              (click)="pick(option)"
              (keydown.enter)="pick(option)"
            >
              <mat-icon
                aria-hidden="true"
                class="shrink-0 text-accent-text"
                [class.invisible]="!isChosen(option.value)"
                >check</mat-icon
              >
              <span data-option-label class="min-w-0 flex-1">{{ option.label }}</span>
            </li>
          } @empty {
            <li class="px-4 py-3 text-on-surface-variant" role="presentation">
              {{ 'select.none_found' | translate }}
            </li>
          }
        </ul>
      </div>
    </ng-template>
  `,
  host: { class: 'relative block min-w-0' },
})
export class Select implements ControlValueAccessor {
  readonly options = input.required<readonly SelectOption[]>();
  readonly multiple = input(false);
  readonly labelledBy = input('');
  /** The label drawn on the control's top edge, as a Material outlined field does, for a row of such fields. */
  readonly label = input('');
  readonly labelInside = input(false);
  readonly inputId = input('');
  readonly testId = input('');
  readonly required = input(false);
  /** The labels are translation keys (a company's own words pass through a key lookup unchanged). */
  readonly translateLabels = input(false);

  protected readonly open = signal(false);
  protected readonly disabled = signal(false);
  protected readonly query = signal('');
  protected readonly active = signal(-1);
  private readonly value = signal<readonly string[]>([]);

  private readonly uid = nextId++;
  protected readonly listId = `app-select-${this.uid}-list`;
  protected readonly valueId = `app-select-${this.uid}-value`;
  protected readonly labelId = `app-select-${this.uid}-label`;
  protected readonly ownLabel = computed(() =>
    this.labelInside() && this.label() !== '' ? this.labelId : this.labelledBy(),
  );

  private readonly trigger = viewChild.required<ElementRef<HTMLButtonElement>>('trigger');
  private readonly panel = viewChild.required<TemplateRef<unknown>>('panel');

  private readonly overlay = inject(Overlay);
  private readonly container = inject(ViewContainerRef);
  private readonly windowClass = inject(WINDOW_CLASS);
  private ref: OverlayRef | null = null;

  private readonly translate = inject(TranslateService);
  /** Re-reads the labels when the translations arrive or the language changes. */
  private readonly language = toSignal(this.translate.onLangChange, { initialValue: null });

  /** The options with their words as a person reads them. */
  private readonly items = computed<readonly SelectOption[]>(() => {
    this.language();
    return this.translateLabels()
      ? this.options().map((option) => ({
          ...option,
          label: this.translate.instant(option.label) as string,
        }))
      : this.options();
  });

  protected readonly searchable = computed(() => this.items().length >= SELECT_SEARCH_FROM);

  /** The options the search lets through, in the order they were given. */
  protected readonly shown = computed(() => {
    const words = fold(this.query().trim());
    return words === ''
      ? this.items()
      : this.items().filter((option) => fold(option.label).includes(words));
  });

  /** What the control holds, as options, in the order the options were given. */
  protected readonly chosen = computed(() => {
    const held = new Set(this.value());
    return this.items().filter((option) => held.has(option.value));
  });
  protected readonly chipped = computed(() => this.chosen().slice(0, SELECT_CHIP_LIMIT));
  protected readonly hiddenCount = computed(() =>
    Math.max(0, this.chosen().length - SELECT_CHIP_LIMIT),
  );

  protected readonly activeId = computed(() =>
    this.open() && this.active() >= 0 && this.active() < this.shown().length
      ? this.optionId(this.active())
      : null,
  );

  private onChange: (value: string | string[] | null) => void = () => undefined;
  private onTouched: () => void = () => undefined;

  constructor() {
    inject(DestroyRef).onDestroy(() => this.ref?.dispose());
  }

  writeValue(value: unknown): void {
    this.value.set(
      Array.isArray(value)
        ? value.filter((v): v is string => typeof v === 'string')
        : typeof value === 'string' && value !== ''
          ? [value]
          : [],
    );
  }

  registerOnChange(fn: (value: string | string[] | null) => void): void {
    this.onChange = fn;
  }

  registerOnTouched(fn: () => void): void {
    this.onTouched = fn;
  }

  setDisabledState(disabled: boolean): void {
    this.disabled.set(disabled);
    if (disabled) {
      this.close(false);
    }
  }

  protected optionId(index: number): string {
    return `app-select-${this.uid}-option-${index}`;
  }

  protected isChosen(value: string): boolean {
    return this.value().includes(value);
  }

  protected toggle(): void {
    if (this.open()) {
      this.close(true);
    } else {
      this.openPanel();
    }
  }

  protected triggerKey(event: KeyboardEvent): void {
    if (!this.open() && (event.key === 'ArrowDown' || event.key === 'ArrowUp')) {
      event.preventDefault();
      this.openPanel();
    }
  }

  protected panelKey(event: KeyboardEvent): void {
    const last = this.shown().length - 1;
    switch (event.key) {
      case 'ArrowDown':
        event.preventDefault();
        this.active.set(Math.min(last, this.active() + 1));
        break;
      case 'ArrowUp':
        event.preventDefault();
        this.active.set(Math.max(0, this.active() - 1));
        break;
      case 'Home':
        event.preventDefault();
        this.active.set(last < 0 ? -1 : 0);
        break;
      case 'End':
        event.preventDefault();
        this.active.set(last);
        break;
      case 'Enter': {
        const option = this.shown()[this.active()];
        if (option) {
          event.preventDefault();
          this.pick(option);
        }
        break;
      }
      case 'Escape':
        event.preventDefault();
        event.stopPropagation();
        this.close(true);
        break;
      case 'Tab':
        this.close(false);
        break;
    }
  }

  protected typed(event: Event): void {
    this.query.set((event.target as HTMLInputElement).value);
    this.active.set(this.shown().length > 0 ? 0 : -1);
  }

  protected pick(option: SelectOption): void {
    if (this.multiple()) {
      const held = this.value();
      this.commit(
        held.includes(option.value)
          ? held.filter((v) => v !== option.value)
          : this.inOptionOrder([...held, option.value]),
      );
    } else {
      this.commit([option.value]);
      this.close(true);
    }
  }

  protected selectAll(): void {
    this.commit(this.inOptionOrder([...this.value(), ...this.shown().map((o) => o.value)]));
  }

  protected clearAll(): void {
    this.commit([]);
  }

  private inOptionOrder(values: readonly string[]): string[] {
    const wanted = new Set(values);
    return this.items()
      .map((o) => o.value)
      .filter((v) => wanted.has(v));
  }

  private commit(values: readonly string[]): void {
    this.value.set(values);
    this.onChange(this.multiple() ? [...values] : (values[0] ?? null));
  }

  private openPanel(): void {
    if (this.disabled() || this.open()) {
      return;
    }
    this.query.set('');
    const index = this.shown().findIndex((option) => this.isChosen(option.value));
    this.active.set(index);

    const sheet = this.windowClass() === 'compact';
    const origin = this.trigger().nativeElement;
    this.ref = this.overlay.create({
      positionStrategy: sheet
        ? this.overlay.position().global().bottom('0').centerHorizontally()
        : this.overlay
            .position()
            .flexibleConnectedTo(origin)
            .withPositions([
              {
                originX: 'start',
                originY: 'bottom',
                overlayX: 'start',
                overlayY: 'top',
                offsetY: 4,
              },
              {
                originX: 'start',
                originY: 'top',
                overlayX: 'start',
                overlayY: 'bottom',
                offsetY: -4,
              },
            ])
            .withPush(false),
      scrollStrategy: this.overlay.scrollStrategies.reposition(),
      hasBackdrop: sheet,
      backdropClass: 'cdk-overlay-dark-backdrop',
      width: sheet ? '100%' : origin.getBoundingClientRect().width,
      minWidth: sheet ? undefined : 200,
      panelClass: sheet ? ['twes-select-sheet'] : ['twes-select-panel'],
    });
    this.ref.attach(new TemplatePortal(this.panel(), this.container));
    this.ref.outsidePointerEvents().subscribe((event) => {
      if (!origin.contains(event.target as Node)) {
        this.close(false);
      }
    });
    this.ref.backdropClick().subscribe(() => this.close(true));
    this.open.set(true);

    queueMicrotask(() => {
      const root = this.ref?.overlayElement;
      const field = root?.querySelector<HTMLElement>('input[type="text"], [role="listbox"]');
      field?.focus({ preventScroll: true });
    });
  }

  private close(refocus: boolean): void {
    if (!this.open()) {
      return;
    }
    this.ref?.dispose();
    this.ref = null;
    this.open.set(false);
    this.onTouched();
    if (refocus) {
      this.trigger().nativeElement.focus();
    }
  }
}
