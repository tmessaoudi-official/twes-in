// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  inject,
  type OnInit,
  signal,
} from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../../auth/auth-facade';
import { HOME_TEXTS } from '../../shared/i18n/home-texts';
import { TextsParts } from '../../shared/i18n/texts-part';
import { LiveChanges } from '../../shared/realtime/live-changes';
import { withSessionKinds } from '../../shared/session/session-kinds';
import { KpiTile } from '../../shared/ui/kpi-tile';
import { InventoryApi } from '../inventory-api';
import type { StockSearch } from '../inventory-types';

/** What the panel read: the stock's worth for whoever may read costs, and what is wrong in it for every reader. */
interface StockFigures {
  readonly value: { readonly total: string; readonly estimated: boolean } | null;
  readonly negative: number;
  readonly expired: number;
}

/** One line of the stock list, only to read how many rows a filter leaves. */
const COUNT_ONLY: StockSearch = {
  page: 1,
  itemsPerPage: 1,
  q: '',
  locationIds: [],
  establishmentIds: [],
  productIds: [],
  negative: null,
  expired: null,
  intervals: {},
  order: null,
};

/**
 * The stock module's panel on the home (plan row S7.5, design review D4): what the stock is worth at the average cost
 * of what came in, for whoever may read costs (the valuation answers no one else), and for every reader of the stock
 * the lines below zero and the lots past their use-by day, each opening the stock list filtered to them. Every figure
 * is the API's; what to reorder is « À surveiller »'s, and the day's low stock the lively home's.
 */
@Component({
  selector: 'app-stock-home',
  imports: [KpiTile, RouterLink, TranslatePipe],
  template: `
    @if (ready()) {
      @if (failed()) {
        <p role="alert" class="text-error" data-testid="home-stock-error">
          {{ 'home.stock.error' | translate }}
        </p>
      } @else if (shown(); as f) {
        <app-kpi-tile
          data-testid="home-stock"
          [icon]="'warehouse'"
          [label]="(f.value ? 'home.stock.value' : 'home.stock.title') | translate"
          [figure]="f.value?.total ?? null"
          [scale]="scale()"
          [currency]="currency()"
          [caption]="
            f.value
              ? ((f.value.estimated ? 'home.stock.value_estimated' : 'home.stock.value_caption')
                | translate)
              : null
          "
          [blank]="f.negative + f.expired === 0 ? ('home.stock.clear' | translate) : null"
          [see]="{
            route: f.value ? '/stock/valuation' : '/stock',
            label: 'home.stock.see' | translate,
          }"
        >
          @if (f.value && f.negative + f.expired === 0) {
            <span class="text-sm text-on-surface-variant">{{
              'home.stock.clear' | translate
            }}</span>
          }
          @if (f.negative > 0) {
            <a
              routerLink="/stock"
              [queryParams]="{ negative: 'yes' }"
              class="text-sm font-medium text-accent-text"
              data-testid="home-stock-negative"
              >{{ 'home.stock.negative' | translate: { count: f.negative } }}</a
            >
          }
          @if (f.expired > 0) {
            <a
              routerLink="/stock"
              [queryParams]="{ expired: 'yes' }"
              class="text-sm font-medium text-accent-text"
              data-testid="home-stock-expired"
              >{{ 'home.stock.expired' | translate: { count: f.expired } }}</a
            >
          }
        </app-kpi-tile>
      }
    }
  `,
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class StockHome implements OnInit {
  private readonly api = inject(InventoryApi);
  private readonly auth = inject(AuthFacade);
  private readonly live = inject(LiveChanges);
  private readonly texts = inject(TextsParts);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly figures = signal<StockFigures | null>(null);
  protected readonly failed = signal(false);
  private readonly opened = signal(false);
  /** Drawn once its words are there, so it never shows a key, and once something was read. */
  protected readonly ready = computed(
    () => this.opened() && (this.figures() !== null || this.failed()),
  );
  protected readonly currency = computed(() => this.auth.me()?.company?.currency ?? null);
  /** A currency's decimals are CLDR's for the currency, whatever the language. */
  protected readonly scale = computed(() => {
    const currency = this.currency();
    return currency === null
      ? null
      : (new Intl.NumberFormat(undefined, { style: 'currency', currency }).resolvedOptions()
          .maximumFractionDigits ?? null);
  });
  /** What the stock is worth shows only while the session still holds the right to read costs, not until the next read. */
  protected readonly shown = computed(() => {
    const figures = this.figures();
    if (figures === null || this.auth.hasPermission('product.cost.read')) return figures;
    return { ...figures, value: null };
  });
  private requests = 0;

  async ngOnInit(): Promise<void> {
    const companyId = this.auth.me()?.company?.id;
    if (!companyId) return;
    // A receipt, a sale, a count or a cost changed by a teammate moves the stock and what it is worth; a role or a
    // membership changed grants or takes away the right to read what it is worth.
    this.live.reloadOn(
      withSessionKinds(['stock', 'delivery_note', 'invoice', 'product']),
      () => this.load(companyId),
      this.destroyRef,
    );
    await Promise.all([this.openTexts(), this.load(companyId)]);
  }

  private async openTexts(): Promise<void> {
    try {
      await this.texts.open(HOME_TEXTS);
      this.opened.set(true);
    } catch {
      // Words that did not come draw nothing rather than keys; the next screen needing them asks again.
      this.failed.set(true);
    }
  }

  private async load(companyId: string): Promise<void> {
    const request = ++this.requests;
    try {
      const [negative, expired, value] = await Promise.all([
        this.api.levels(companyId, { ...COUNT_ONLY, negative: 'yes' }),
        this.api.levels(companyId, { ...COUNT_ONLY, expired: 'yes' }),
        this.auth.hasPermission('product.cost.read')
          ? this.api.valuation(companyId)
          : Promise.resolve(null),
      ]);
      if (request !== this.requests) return;
      this.figures.set({
        value: value === null ? null : { total: value.total, estimated: value.estimated },
        negative: negative.total,
        expired: expired.total,
      });
      this.failed.set(false);
    } catch {
      if (request !== this.requests) return;
      this.failed.set(true);
    }
  }
}
