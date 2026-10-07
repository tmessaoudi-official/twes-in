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
import { FormControl, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatRadioModule } from '@angular/material/radio';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { debounceTime } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { CompanySettings } from '../settings/company-settings-facade';
import { Feedback } from '../shared/feedback/feedback';
import { ColourField } from '../shared/form/colour-field';
import { LiveChanges } from '../shared/realtime/live-changes';
import { DocumentDesignFacade } from './document-design-facade';
import { DEFAULT_DESIGN, designChanges, savedDesign } from './document-design-forms';
import {
  DOCUMENT_LAYOUTS,
  type DocumentDesign,
  type DocumentLayout,
} from './document-design-types';

/** How long the choice rests before its preview is asked for: a picture per keystroke would be wasted. */
export const PREVIEW_DELAY_MS = 300;

/**
 * How the company's documents print (docs/SPEC.md § 7, 2026-10-06 10:19): a built-in layout and the accent, tried on
 * the company's latest invoice before they are saved. Saving writes the two company settings; an invoice issued after
 * keeps them. The logo is the profile's, which this page leads to.
 */
@Component({
  selector: 'app-document-design-page',
  imports: [
    ReactiveFormsModule,
    MatButtonModule,
    MatRadioModule,
    RouterLink,
    TranslatePipe,
    ColourField,
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
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly mayManage = computed(() => this.auth.hasPermission('company.settings'));
  protected readonly busy = this.settings.busy;
  protected readonly error = this.settings.error;
  protected readonly picture = this.design.picture;
  protected readonly previewState = this.design.state;

  protected readonly form = new FormGroup({
    layout: new FormControl<DocumentLayout>(DEFAULT_DESIGN.layout, { nonNullable: true }),
    accent: new FormControl<string>(DEFAULT_DESIGN.accent, {
      nonNullable: true,
      validators: [Validators.required],
    }),
  });
  /** The design as the API holds it, which the form starts from and returns to. */
  private readonly saved = signal<DocumentDesign>(DEFAULT_DESIGN);
  /** What saving would change, which the save and cancel buttons follow. */
  protected readonly changes = signal(0);

  constructor() {
    this.form.valueChanges
      .pipe(debounceTime(PREVIEW_DELAY_MS), takeUntilDestroyed())
      .subscribe(() => void this.showPreview());
    this.form.valueChanges
      .pipe(takeUntilDestroyed())
      .subscribe(() => this.changes.set(designChanges(this.saved(), this.chosen()).length));
  }

  async ngOnInit(): Promise<void> {
    const companyId = this.company()?.id;
    if (!companyId || !this.mayManage()) return;
    // Another tab or member saving a design: the form follows it unless something is being chosen here.
    this.live.reloadOn(['setting'], () => this.load(companyId), this.destroyRef);
    await this.load(companyId);
  }

  protected async save(): Promise<void> {
    const companyId = this.company()?.id;
    const changes = designChanges(this.saved(), this.chosen());
    if (!companyId || this.busy() || this.form.invalid || changes.length === 0) return;
    if (await this.settings.save(companyId, changes)) {
      this.adopt(savedDesign(this.settings.rows()));
      this.feedback.success('documents.saved');
    }
  }

  /** Back to the saved design, through the form's own change: its preview comes once the choice rests, as any other. */
  protected cancel(): void {
    const saved = this.saved();
    this.form.setValue({ layout: saved.layout, accent: saved.accent });
    this.form.markAsPristine();
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
    this.form.setValue({ layout: design.layout, accent: design.accent }, { emitEvent: false });
    this.form.markAsPristine();
    this.changes.set(0);
  }

  private chosen(): DocumentDesign {
    return this.form.getRawValue();
  }

  private async showPreview(): Promise<void> {
    const companyId = this.company()?.id;
    if (!companyId || this.form.invalid) return;
    await this.design.preview(companyId, this.chosen());
  }
}
