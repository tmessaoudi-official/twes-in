// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  afterNextRender,
  ChangeDetectionStrategy,
  Component,
  computed,
  ElementRef,
  inject,
  signal,
  viewChild,
} from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { Router } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { CustomerView } from '../shared/customer-view/customer-view';
import { FormatFacade } from '../shared/i18n/format-facade';
import { AmountPipe, DayPipe } from '../shared/i18n/format-pipes';
import { Session } from '../shared/session/session';
import { CustomerScreenApi } from './customer-screen-api';
import type { ScreenProduct } from './customer-screen-types';

/**
 * The customer screen (docs/SPEC.md § 7, 2026-10-03 08:20): the one screen a customer may be left in front of. Opening
 * it locks the tab on it (`CustomerView`), and everything it draws is what the API sent of an allow-list — name, final
 * price, our own reference and barcode, in or out of stock when the company says so, the promotions open to everyone —
 * so there is no cost, no supplier and no other customer on it to hide. A scanner typing into the field and pressing
 * Enter is a search like any other. Outside the shell, so it carries no menu.
 */
@Component({
  selector: 'app-customer-screen-page',
  imports: [
    AmountPipe,
    DayPipe,
    FormsModule,
    MatButtonModule,
    MatCardModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    TranslatePipe,
  ],
  templateUrl: './customer-screen-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class CustomerScreenPage {
  private readonly api = inject(CustomerScreenApi);
  private readonly session = inject(Session);
  private readonly view = inject(CustomerView);
  private readonly router = inject(Router);
  private readonly format = inject(FormatFacade);
  private readonly field = viewChild<ElementRef<HTMLInputElement>>('field');
  /** The latest search, so that a slow earlier answer never replaces a later one. */
  private turn = 0;

  protected readonly words = signal('');
  /** What the last search asked, for the line that says nothing answered it. */
  protected readonly asked = signal('');
  /** The products the last search found; null before any search. */
  protected readonly results = signal<readonly ScreenProduct[] | null>(null);
  protected readonly failed = signal(false);
  protected readonly currency = computed(() => this.session.me()?.company?.currency ?? '');

  constructor() {
    this.view.on();
    afterNextRender(() => this.field()?.nativeElement.focus());
  }

  protected async search(event: Event): Promise<void> {
    event.preventDefault();
    const words = this.words().trim();
    const companyId = this.session.me()?.company?.id;
    if (words === '' || companyId === undefined) return;
    const turn = ++this.turn;
    this.words.set('');
    this.asked.set(words);
    try {
      const found = await this.api.find(companyId, words);
      if (turn !== this.turn) return;
      this.results.set(found);
      this.failed.set(false);
    } catch {
      if (turn !== this.turn) return;
      // What a customer reads must not be a stale answer: the list is dropped and the line says it could not look.
      this.results.set(null);
      this.failed.set(true);
    }
    this.field()?.nativeElement.focus();
  }

  protected async leave(): Promise<void> {
    if (await this.view.leave()) await this.router.navigateByUrl('/');
  }

  /** A minimum quantity as the price list writes it, without the trailing zeros of its three decimals. */
  protected quantity(value: string): string {
    return this.format.decimal(String(Number(value)));
  }
}
