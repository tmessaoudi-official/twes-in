// SPDX-License-Identifier: AGPL-3.0-or-later

import { Directionality } from '@angular/cdk/bidi';
import { Overlay, type OverlayRef } from '@angular/cdk/overlay';
import { ComponentPortal } from '@angular/cdk/portal';
import { DOCUMENT, inject, Injectable, Injector, signal } from '@angular/core';
import { Router } from '@angular/router';
import type { Tour, TourStep } from './tour';
import type { TourAnchor } from './tour-anchors';
import { TourCard } from './tour-card';

/** How long a step waits for its anchor to be drawn: a lazily loaded page and its first answer. */
const ANCHOR_WAIT_MS = 2000;
const ANCHOR_POLL_MS = 50;

/**
 * Runs a task tour: each step opens its page if it stands on another, points at its anchor and puts a card beside it,
 * over the page but never blocking it, so the person can do what the step says. The card takes the focus at every
 * step and gives it back where the tour began when it ends.
 */
@Injectable({ providedIn: 'root' })
export class TourGuide {
  private readonly overlay = inject(Overlay);
  private readonly router = inject(Router);
  private readonly injector = inject(Injector);
  private readonly document = inject(DOCUMENT);
  private readonly direction = inject(Directionality, { optional: true });

  private ref: OverlayRef | null = null;
  private marked: HTMLElement | null = null;
  private returnTo: HTMLElement | null = null;
  /** Bumped by every move, so a step still waiting for its anchor never draws over a later one. */
  private move = 0;

  readonly tour = signal<Tour | null>(null);
  readonly index = signal(0);
  /** Whether the step's anchor is not on this screen: said on the card, never skipped in silence. */
  readonly missing = signal(false);
  /** Bumped when the card should take the focus: each step drawn. */
  readonly focusRequest = signal(0);

  async start(tour: Tour): Promise<void> {
    if (this.tour() === null) {
      const active = this.document.activeElement;
      this.returnTo = active instanceof HTMLElement ? active : null;
    }
    this.tour.set(tour);
    await this.show(0);
  }

  async next(): Promise<void> {
    const tour = this.tour();
    if (tour === null) return;
    if (this.index() + 1 >= tour.steps.length) {
      this.end();
      return;
    }
    await this.show(this.index() + 1);
  }

  async back(): Promise<void> {
    if (this.tour() === null || this.index() === 0) return;
    await this.show(this.index() - 1);
  }

  end(): void {
    this.move++;
    this.unmark();
    this.ref?.dispose();
    this.ref = null;
    if (this.tour() === null) return;
    this.tour.set(null);
    this.index.set(0);
    this.missing.set(false);
    this.returnTo?.focus();
    this.returnTo = null;
  }

  private async show(index: number): Promise<void> {
    const tour = this.tour();
    const step = tour?.steps[index];
    if (step === undefined) return;
    const move = ++this.move;
    // A page with unsaved changes may refuse to be left: the tour then stays where it was.
    if (!(await this.reach(step))) return;
    if (move !== this.move) return;
    const anchor = await this.find(step.anchor);
    if (move !== this.move) return;
    this.index.set(index);
    this.missing.set(anchor === null);
    this.unmark();
    if (anchor !== null) {
      anchor.setAttribute('data-tour-active', '');
      this.marked = anchor;
      // Optional, as in the command palette: jsdom draws nothing to scroll.
      anchor.scrollIntoView?.({ block: 'center', inline: 'nearest' });
    }
    this.attach(anchor);
    this.focusRequest.update((count) => count + 1);
  }

  private async reach(step: TourStep): Promise<boolean> {
    if (step.route === undefined || this.router.url.split(/[?#]/)[0] === step.route) return true;
    return this.router.navigateByUrl(step.route);
  }

  /**
   * The anchor on screen: a shared component draws one per layout (the rail's and the bottom bar's « Créer »), and the
   * one a hidden layout carries has no box to point at.
   */
  private async find(anchor: TourAnchor): Promise<HTMLElement | null> {
    const deadline = Date.now() + ANCHOR_WAIT_MS;
    for (;;) {
      const drawn = [
        ...this.document.querySelectorAll<HTMLElement>(`[data-tour="${anchor}"]`),
      ].find(shown);
      if (drawn !== undefined || Date.now() >= deadline) return drawn ?? null;
      await new Promise((resolve) => setTimeout(resolve, ANCHOR_POLL_MS));
    }
  }

  private attach(anchor: HTMLElement | null): void {
    const position =
      anchor === null
        ? this.overlay.position().global().centerHorizontally().centerVertically()
        : this.overlay
            .position()
            .flexibleConnectedTo(anchor)
            .withPositions([
              {
                originX: 'center',
                originY: 'bottom',
                overlayX: 'center',
                overlayY: 'top',
                offsetY: 12,
              },
              {
                originX: 'center',
                originY: 'top',
                overlayX: 'center',
                overlayY: 'bottom',
                offsetY: -12,
              },
              {
                originX: 'end',
                originY: 'center',
                overlayX: 'start',
                overlayY: 'center',
                offsetX: 12,
              },
              {
                originX: 'start',
                originY: 'center',
                overlayX: 'end',
                overlayY: 'center',
                offsetX: -12,
              },
            ])
            .withPush(true)
            .withViewportMargin(16);
    if (this.ref === null) {
      this.ref = this.overlay.create({
        positionStrategy: position,
        scrollStrategy: this.overlay.scrollStrategies.reposition(),
        // No backdrop: a step asks the person to try what it points at.
        hasBackdrop: false,
        direction: this.direction?.value ?? 'ltr',
        maxWidth: 'min(22rem, calc(100vw - 32px))',
        panelClass: 'twes-tour-panel',
      });
      this.ref.attach(new ComponentPortal(TourCard, null, this.injector));
      return;
    }
    this.ref.updatePositionStrategy(position);
  }

  private unmark(): void {
    this.marked?.removeAttribute('data-tour-active');
    this.marked = null;
  }
}

/** Whether an element is drawn: `checkVisibility` where the browser has it, else no ancestor laid out as nothing. */
function shown(element: HTMLElement): boolean {
  if (typeof element.checkVisibility === 'function') return element.checkVisibility();
  for (let node: Element | null = element; node !== null; node = node.parentElement) {
    if (getComputedStyle(node).display === 'none') return false;
  }
  return true;
}
