// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  HostListener,
  inject,
  signal,
} from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatDialog } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';
import { firstValueFrom } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { Feedback } from '../shared/feedback/feedback';
import { FormatFacade } from '../shared/i18n/format-facade';
import { LiveChanges } from '../shared/realtime/live-changes';
import { StepUp } from '../shared/step-up/step-up';
import { ThemeFacade } from '../shared/theme/theme-facade';
import {
  DataErasureApi,
  ERASURE_PARTS,
  ErasureRefused,
  type ErasurePart,
  type PartCounts,
} from './data-erasure-api';
import { ErasurePreviewDialog, type ErasurePreviewLine } from './erasure-preview-dialog';
import { refusalToast } from './erasure-texts';
import { PendingErasure } from './pending-erasure';

/** How long the open page waits for anything to be done on it before it closes again. */
export const ERASURE_PAGE_IDLE_MS = 10 * 60 * 1000;

/** The parts of the vision not built yet, listed « Bientôt » after the working ones, in the page's order. */
export const COMING_ERASURE_PARTS = [
  'products',
  'customers',
  'vendors',
  'stock_reset',
  'files',
  'settings',
] as const;

/**
 * « Effacer des données »: the owner, having just proved who they are, chooses parts of the company's data, sees what
 * they would take, and erases them for every member at once; the banner offers the undo for 24 hours. The page opens
 * only on that proof and closes again after ten minutes with nothing done on it.
 */
@Component({
  selector: 'app-data-erasure-page',
  imports: [MatButtonModule, MatCheckboxModule, MatIconModule, TranslatePipe],
  templateUrl: './data-erasure-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class DataErasurePage {
  private readonly api = inject(DataErasureApi);
  private readonly auth = inject(AuthFacade);
  private readonly stepUp = inject(StepUp);
  private readonly dialog = inject(MatDialog);
  private readonly feedback = inject(Feedback);
  private readonly format = inject(FormatFacade);
  private readonly translate = inject(TranslateService);
  protected readonly pending = inject(PendingErasure);
  protected readonly showComing = inject(ThemeFacade).showComing;

  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  /** When the identity was confirmed, the page open since; null while it is closed. */
  protected readonly confirmedAt = signal<string | null>(null);
  protected readonly parts = signal<readonly PartCounts[]>([]);
  protected readonly chosen = signal<ReadonlySet<ErasurePart>>(new Set());
  protected readonly busy = signal(false);
  protected readonly comingParts = COMING_ERASURE_PARTS;
  protected readonly confirmedTime = computed(() => {
    const at = this.confirmedAt();
    return at === null ? '' : this.format.time(at, this.company()?.timezone);
  });
  private idle: ReturnType<typeof setTimeout> | null = null;

  constructor() {
    const destroyRef = inject(DestroyRef);
    // What another member or tab makes or erases meanwhile changes the counts the page shows.
    inject(LiveChanges).reloadOn(
      [
        'data_erasure',
        'venue_area',
        'venue_spot',
        'venue_structure',
        'invoice',
        'delivery_note',
        'quote',
      ],
      () => this.reload(),
      destroyRef,
    );
    destroyRef.onDestroy(() => this.stopIdle());
  }

  /** Anything done on the page keeps it open. */
  @HostListener('document:pointerdown')
  @HostListener('document:keydown')
  protected active(): void {
    if (this.confirmedAt() !== null) this.startIdle();
  }

  protected async confirm(): Promise<void> {
    if (!(await this.stepUp.request('step_up.intro_erasure'))) return;
    this.confirmedAt.set(new Date().toISOString());
    this.startIdle();
    await this.reload();
  }

  protected toggle(part: ErasurePart, checked: boolean): void {
    const next = new Set(this.chosen());
    if (checked) next.add(part);
    else next.delete(part);
    this.chosen.set(next);
  }

  protected summary(part: PartCounts, short = false): string {
    return this.translate.instant(
      `data_erasure.parts.${part.part}.${short ? 'short' : 'count'}`,
      part.counts,
    );
  }

  /** The dry run, counted again as it opens, then the erasure when it is answered « Effacer ». */
  protected async review(): Promise<void> {
    const companyId = this.company()?.id;
    if (!companyId || this.busy() || this.chosen().size === 0) return;
    this.busy.set(true);
    try {
      if (!(await this.reload())) return;
      const chosen = ERASURE_PARTS.filter((part) => this.chosen().has(part));
      const lines: ErasurePreviewLine[] = this.parts()
        .filter((part) => this.chosen().has(part.part))
        .map((part) => ({
          label: this.translate.instant(`data_erasure.parts.${part.part}.title`),
          summary: this.summary(part, true),
        }));
      const answer = await firstValueFrom(
        this.dialog
          .open<ErasurePreviewDialog, readonly ErasurePreviewLine[], boolean>(
            ErasurePreviewDialog,
            { data: lines, autoFocus: 'first-tabbable' },
          )
          .afterClosed(),
      );
      if (answer === true) await this.erase(companyId, chosen);
    } finally {
      this.busy.set(false);
    }
  }

  private async erase(
    companyId: string,
    parts: readonly ErasurePart[],
    again = true,
  ): Promise<void> {
    try {
      const erasure = await this.api.erase(companyId, parts);
      this.pending.erased(erasure);
      this.chosen.set(new Set());
      this.feedback.effect(
        'data_erasure.erased',
        {
          parts: parts
            .map((part) => this.translate.instant(`data_erasure.parts.${part}.inline`))
            .join(', '),
        },
        'annulable',
      );
      await this.reload();
    } catch (error) {
      // The proof is older than the API keeps it: asked again where the person is, then the same erasure once more.
      if (error instanceof ErasureRefused && error.code === 'step_up_required' && again) {
        if (await this.stepUp.request('step_up.intro_erasure')) {
          this.confirmedAt.set(new Date().toISOString());
          await this.erase(companyId, parts, false);
        }
        return;
      }
      const toast = refusalToast(error, this.translate);
      this.feedback.failure(toast.key, toast.params);
    }
  }

  /** Reads the counts again while the page is open; says whether it could. */
  private async reload(): Promise<boolean> {
    const companyId = this.company()?.id;
    if (!companyId || this.confirmedAt() === null) return false;
    try {
      const preview = await this.api.preview(companyId);
      this.parts.set(preview.parts);
      this.pending.shown(preview.pending);
      return true;
    } catch (error) {
      if (error instanceof ErasureRefused && error.code === 'step_up_required') {
        this.close();
      } else {
        const toast = refusalToast(error, this.translate);
        this.feedback.failure(toast.key, toast.params);
      }
      return false;
    }
  }

  private close(): void {
    this.stopIdle();
    this.confirmedAt.set(null);
    this.parts.set([]);
    this.chosen.set(new Set());
  }

  private startIdle(): void {
    this.stopIdle();
    this.idle = setTimeout(() => this.close(), ERASURE_PAGE_IDLE_MS);
  }

  private stopIdle(): void {
    if (this.idle !== null) clearTimeout(this.idle);
    this.idle = null;
  }
}
