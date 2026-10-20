// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  inject,
  OnInit,
  signal,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import {
  FormControl,
  FormGroup,
  ReactiveFormsModule,
  type ValidationErrors,
  Validators,
} from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatRadioModule } from '@angular/material/radio';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { debounceTime } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { CompanySettings } from '../settings/company-settings-facade';
import { type SettingVersions, versioned, versionsAt } from '../shared/settings/setting-forms';
import { Label } from '../shared/a11y/label';
import { Feedback } from '../shared/feedback/feedback';
import type { ScreenAction } from '../shared/actions/screen-action';
import { ScreenActions } from '../shared/actions/screen-actions';
import { ColourField } from '../shared/form/colour-field';
import { RecordBar } from '../shared/form/record-bar';
import { UnsavedChanges } from '../shared/form/unsaved-changes';
import { LiveChanges } from '../shared/realtime/live-changes';
import { DocumentDesignFacade } from './document-design-facade';
import { DEFAULT_DESIGN, designChanges, logoRoom, savedDesign } from './document-design-forms';
import {
  DOCUMENT_LAYOUTS,
  type DocumentDesign,
  type DocumentLayout,
  WATERMARK_COPIES,
  WATERMARK_INTENSITIES,
  WATERMARK_SPREADS,
  type WatermarkCopies,
  type WatermarkIntensity,
  type WatermarkSpread,
} from './document-design-types';

/** How long the choice rests before its preview is asked for: a picture per keystroke would be wasted. */
export const PREVIEW_DELAY_MS = 300;

/**
 * How the company's documents print: a built-in layout, the accent, the room the logo may take and the watermark, tried on the
 * company's latest invoice before they are saved. Saving writes the company settings; an invoice issued after keeps
 * them. The logo itself is the profile's, which this page leads to.
 */
@Component({
  selector: 'app-document-design-page',
  imports: [
    ReactiveFormsModule,
    MatButtonModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatRadioModule,
    RouterLink,
    TranslatePipe,
    ColourField,
    Label,
    RecordBar,
  ],
  templateUrl: './document-design-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class DocumentDesignPage implements OnInit {
  private readonly settings = inject(CompanySettings);
  private readonly design = inject(DocumentDesignFacade);
  private readonly auth = inject(AuthFacade);
  private readonly feedback = inject(Feedback);
  private readonly live = inject(LiveChanges);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly layouts = DOCUMENT_LAYOUTS;
  protected readonly intensities = WATERMARK_INTENSITIES;
  protected readonly copies = WATERMARK_COPIES;
  protected readonly spreads = WATERMARK_SPREADS;
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly mayManage = computed(() => this.auth.hasPermission('company.settings'));
  protected readonly busy = this.settings.busy;
  protected readonly error = this.settings.error;
  protected readonly picture = this.design.picture;
  protected readonly previewState = this.design.state;

  /** The bounds the API declares for the logo's room, which the fields check and the hint says. */
  protected readonly room = computed(() => logoRoom(this.settings.rows()));

  protected readonly form = new FormGroup({
    layout: new FormControl<DocumentLayout>(DEFAULT_DESIGN.layout, { nonNullable: true }),
    accent: new FormControl<string>(DEFAULT_DESIGN.accent, {
      nonNullable: true,
      validators: [Validators.required],
    }),
    logoWidth: new FormControl<number | null>(DEFAULT_DESIGN.logoWidth, {
      validators: [Validators.required, (control) => this.within(control.value, 'width')],
    }),
    logoHeight: new FormControl<number | null>(DEFAULT_DESIGN.logoHeight, {
      validators: [Validators.required, (control) => this.within(control.value, 'height')],
    }),
    logoKeepsProportions: new FormControl<boolean>(DEFAULT_DESIGN.logoKeepsProportions, {
      nonNullable: true,
    }),
    watermarkIntensity: new FormControl<WatermarkIntensity>(DEFAULT_DESIGN.watermarkIntensity, {
      nonNullable: true,
    }),
    watermarkCopies: new FormControl<WatermarkCopies>(DEFAULT_DESIGN.watermarkCopies, {
      nonNullable: true,
    }),
    watermarkSpread: new FormControl<WatermarkSpread>(DEFAULT_DESIGN.watermarkSpread, {
      nonNullable: true,
    }),
  });
  /** The logo's width over its height, which a locked size keeps; null while unread or without a logo. */
  protected readonly logoRatio = this.design.logoRatio;
  /** Whether the logo keeps its proportions, which the lock button shows and the warning follows. */
  protected readonly locked = signal(DEFAULT_DESIGN.logoKeepsProportions);
  /** Whether the watermark prints more than once a page, the only time its spread has anything to say. */
  protected readonly manyCopies = signal(DEFAULT_DESIGN.watermarkCopies !== '1');
  /** The design as the API holds it, which the form starts from and returns to. */
  private readonly saved = signal<DocumentDesign>(DEFAULT_DESIGN);
  /** What saving would change, which the save and cancel buttons follow. */
  protected readonly changes = signal(0);
  /** Saving and going back from the bar beside the title, the same as on every record page. */
  protected readonly recordActions = computed<ScreenAction[]>(() => {
    const busy = this.busy();
    const changes = this.changes();
    return [
      {
        id: 'save',
        label: 'form.save',
        icon: 'save',
        primary: true,
        shortcut: 's',
        disabled: busy || changes === 0,
        run: () => this.saveFromBar(),
      },
      {
        id: 'revert',
        label: 'form.revert',
        disabled: busy,
        run: () => this.cancel(),
        shown: changes > 0,
      },
    ];
  });
  /**
   * The version of each setting the form was last set from, which a save sends back: a design saved elsewhere while
   * something is chosen here moves the saved design, not this, so the save is refused instead of writing over it.
   */
  private versions: SettingVersions = {};

  constructor() {
    inject(UnsavedChanges).declare(this.changes);
    inject(ScreenActions).declare(this.recordActions);
    this.form.valueChanges
      .pipe(debounceTime(PREVIEW_DELAY_MS), takeUntilDestroyed())
      .subscribe(() => void this.showPreview());
    this.form.valueChanges
      .pipe(takeUntilDestroyed())
      .subscribe(() => this.changes.set(designChanges(this.saved(), this.chosen()).length));
    // Locked, the size typed sets the other from the logo's proportions, before the form's own change is told.
    const { logoWidth, logoHeight, logoKeepsProportions } = this.form.controls;
    logoWidth.valueChanges.pipe(takeUntilDestroyed()).subscribe((width) => {
      const ratio = this.followedRatio();
      if (ratio !== null && this.within(width, 'width') === null && width !== null) {
        logoHeight.setValue(this.clamped(Math.round(width / ratio), 'height'), {
          emitEvent: false,
        });
      }
    });
    logoHeight.valueChanges.pipe(takeUntilDestroyed()).subscribe((height) => {
      const ratio = this.followedRatio();
      if (ratio !== null && this.within(height, 'height') === null && height !== null) {
        logoWidth.setValue(this.clamped(Math.round(height * ratio), 'width'), { emitEvent: false });
      }
    });
    logoKeepsProportions.valueChanges
      .pipe(takeUntilDestroyed())
      .subscribe((keeps) => this.locked.set(keeps));
    this.form.controls.watermarkCopies.valueChanges
      .pipe(takeUntilDestroyed())
      .subscribe((copies) => this.manyCopies.set(copies !== '1'));
  }

  /** Locks or frees the logo's proportions; locking sets the height from the width again. */
  protected toggleLock(): void {
    const { logoWidth, logoHeight, logoKeepsProportions } = this.form.controls;
    const keeps = !logoKeepsProportions.value;
    const ratio = this.logoRatio();
    if (keeps && ratio !== null && logoWidth.value !== null && logoWidth.valid) {
      logoHeight.setValue(this.clamped(Math.round(logoWidth.value / ratio), 'height'), {
        emitEvent: false,
      });
    }
    logoKeepsProportions.setValue(keeps);
  }

  async ngOnInit(): Promise<void> {
    const companyId = this.company()?.id;
    if (!companyId || !this.mayManage()) return;
    // Another tab or member saving a design: the form follows it unless something is being chosen here.
    this.live.reloadOn(['setting'], () => this.load(companyId), this.destroyRef);
    void this.design.loadLogoRatio(companyId);
    await this.load(companyId);
  }

  protected async save(): Promise<void> {
    const companyId = this.company()?.id;
    const changes = designChanges(this.saved(), this.chosen());
    if (!companyId || this.busy() || this.form.invalid || changes.length === 0) return;
    if (await this.settings.save(companyId, versioned(changes, this.versions))) {
      this.adopt(savedDesign(this.settings.rows()));
      this.feedback.success('documents.saved');
    } else if (this.settings.error() === 'record_changed') {
      // The form shows the design as it is now saved; what was chosen here was not kept, which the toast says.
      this.settings.clearError();
      await this.settings.load(companyId);
      this.adopt(savedDesign(this.settings.rows()));
      await this.showPreview();
      this.feedback.failure('live.saved_since');
    }
  }

  /** A choice the API would refuse shows why rather than going nowhere. */
  private saveFromBar(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }
    void this.save();
  }

  /** Back to the saved design, through the form's own change: its preview comes once the choice rests, as any other. */
  protected cancel(): void {
    this.form.setValue({ ...this.saved() }, { emitEvent: false });
    this.form.updateValueAndValidity();
    this.locked.set(this.saved().logoKeepsProportions);
    this.manyCopies.set(this.saved().watermarkCopies !== '1');
    this.form.markAsPristine();
    this.changes.set(0);
  }

  private async load(companyId: string): Promise<void> {
    await this.settings.load(companyId);
    if (this.changes() === 0) {
      this.adopt(savedDesign(this.settings.rows()));
      // Settings that never arrived leave no accent: the form stays invalid, and the preview says it failed.
      if (this.form.invalid) this.design.without('failed');
      await this.showPreview();
    } else {
      this.saved.set(savedDesign(this.settings.rows()));
    }
  }

  /** Takes a design as the saved one and shows it, without asking for a second preview. */
  private adopt(design: DocumentDesign): void {
    this.saved.set(design);
    this.versions = versionsAt(this.settings.rows(), 'company');
    this.form.setValue({ ...design }, { emitEvent: false });
    this.locked.set(design.logoKeepsProportions);
    this.manyCopies.set(design.watermarkCopies !== '1');
    this.form.markAsPristine();
    this.changes.set(0);
  }

  private chosen(): DocumentDesign {
    return this.form.getRawValue();
  }

  /** The ratio a typed size follows: the logo's, while the lock is on. */
  private followedRatio(): number | null {
    return this.form.controls.logoKeepsProportions.value ? this.logoRatio() : null;
  }

  /** A size the other one gave, kept inside the bounds the API declares. */
  private clamped(millimetres: number, side: 'width' | 'height'): number {
    const bounds = this.room()?.[side];
    return bounds === undefined
      ? millimetres
      : Math.min(bounds.max, Math.max(bounds.min, millimetres));
  }

  /** A whole number of millimetres inside what the API declares; anything while the bounds are unread waits. */
  private within(value: unknown, side: 'width' | 'height'): ValidationErrors | null {
    const bounds = this.room()?.[side];
    if (value === null || value === '' || bounds === undefined) return null;
    return typeof value === 'number' &&
      Number.isInteger(value) &&
      value >= bounds.min &&
      value <= bounds.max
      ? null
      : { room: bounds };
  }

  private async showPreview(): Promise<void> {
    const companyId = this.company()?.id;
    if (!companyId || this.form.invalid) return;
    await this.design.preview(companyId, this.chosen());
  }
}
