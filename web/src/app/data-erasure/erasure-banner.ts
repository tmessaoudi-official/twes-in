// SPDX-License-Identifier: AGPL-3.0-or-later

import { DOCUMENT } from '@angular/common';
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  effect,
  ElementRef,
  inject,
  signal,
  untracked,
  viewChild,
} from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { Feedback } from '../shared/feedback/feedback';
import { FormatFacade } from '../shared/i18n/format-facade';
import { LiveChanges } from '../shared/realtime/live-changes';
import { refusalToast } from './erasure-texts';
import { PendingErasure } from './pending-erasure';

/** Where the banner ends, from the top of the window; `styles.scss` sets the toasts below it. */
const TOP_NOTICE_BOTTOM = '--twes-top-notice-bottom';

/**
 * « Effacement en attente … Annuler l'effacement », on every page of an owner while an erasure may be undone: it names
 * a state of the company, so it is a status, not a toast that leaves. The shell loads it only for an owner.
 */
@Component({
  selector: 'app-erasure-banner',
  imports: [MatButtonModule, MatIconModule, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (pending.erasure(); as erasure) {
      <div
        role="status"
        #bar
        class="fixed inset-x-0 top-2 z-[1100] mx-auto flex w-fit max-w-[calc(100%-2rem)] flex-wrap items-center gap-3 rounded-xl border border-outline-variant bg-surface-container-highest px-4 py-2 text-on-surface shadow-lg"
        data-testid="erasure-banner"
      >
        <span>{{ 'data_erasure.banner.text' | translate: { parts: parts(), end: end() } }}</span>
        <button
          mat-flat-button
          type="button"
          [disabled]="undoing()"
          (click)="undo()"
          data-testid="erasure-undo"
        >
          <mat-icon aria-hidden="true">undo</mat-icon>
          {{ 'data_erasure.banner.undo' | translate }}
        </button>
      </div>
    }
  `,
})
export class ErasureBanner {
  protected readonly pending = inject(PendingErasure);
  private readonly auth = inject(AuthFacade);
  private readonly feedback = inject(Feedback);
  private readonly format = inject(FormatFacade);
  private readonly translate = inject(TranslateService);
  protected readonly undoing = signal(false);
  private readonly bar = viewChild<ElementRef<HTMLElement>>('bar');
  private readonly company = computed(() => this.auth.me()?.company ?? null);

  protected readonly parts = computed(() =>
    (this.pending.erasure()?.parts ?? [])
      .map((part) => this.translate.instant(`data_erasure.parts.${part}.inline`))
      .join(', '),
  );
  protected readonly end = computed(() => {
    const erasure = this.pending.erasure();
    return erasure ? this.format.moment(erasure.effectiveAt, this.company()?.timezone) : '';
  });

  constructor() {
    const live = inject(LiveChanges);
    const destroyRef = inject(DestroyRef);
    // Another owner's erasure, or its undo, reaches this tab as a change to the erasure itself.
    live.reloadOn(
      ['data_erasure'],
      async () => {
        const companyId = this.company()?.id;
        if (companyId) await this.pending.load(companyId);
      },
      destroyRef,
    );
    effect(() => {
      const companyId = this.company()?.id;
      if (companyId) untracked(() => void this.pending.load(companyId));
    });
    // A toast takes the top of the window too: where the banner ends is published, and the toasts stand below it.
    const root = inject(DOCUMENT).documentElement;
    effect((onCleanup) => {
      const bar = this.bar()?.nativeElement;
      // jsdom, where the component specs run, has no ResizeObserver; a browser always has one.
      if (bar === undefined || typeof ResizeObserver === 'undefined') {
        root.style.removeProperty(TOP_NOTICE_BOTTOM);
        return;
      }
      const observer = new ResizeObserver(() =>
        root.style.setProperty(TOP_NOTICE_BOTTOM, `${bar.getBoundingClientRect().bottom}px`),
      );
      observer.observe(bar);
      onCleanup(() => {
        observer.disconnect();
        root.style.removeProperty(TOP_NOTICE_BOTTOM);
      });
    });
  }

  protected async undo(): Promise<void> {
    const companyId = this.company()?.id;
    if (!companyId || this.undoing()) return;
    this.undoing.set(true);
    try {
      await this.pending.undo(companyId);
      this.feedback.success('data_erasure.undone');
    } catch (error) {
      const toast = refusalToast(error, this.translate);
      this.feedback.failure(toast.key, toast.params);
    } finally {
      this.undoing.set(false);
    }
  }
}
