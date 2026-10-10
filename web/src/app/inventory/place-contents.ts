// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  effect,
  inject,
  input,
  linkedSignal,
  output,
  untracked,
} from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatMenuModule } from '@angular/material/menu';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { ProductThumbnail } from '../products/product-thumbnail';
import { Label } from '../shared/a11y/label';
import { COARSE_POINTER } from '../shared/ui/pointer';
import { AmountPipe } from '../shared/i18n/format-pipes';
import { LiveChanges } from '../shared/realtime/live-changes';
import { InventoryFacade } from './inventory-facade';
import type { StockLevelRow } from './inventory-types';
import type { MovementAsked } from './place-movement';

/** What a line was asked to do, for the map's panel to open its movement. */
export type PlaceAct = MovementAsked;

/** Past this many lines a place is searched rather than read down: a shelf of six is read at a glance. */
export const CONTENTS_SEARCH_FROM = 6;
/** The words are asked once typing rests, as a paged list asks: one read per word, not one per letter. */
export const CONTENTS_SEARCH_PAUSE_MS = 300;

/**
 * « Ce qu'il y a ici »: what a place holds, with every place under it, and the homes there holding nothing, which are
 * the shelves to refill. The map shows it beside the plan and « Emplacements » in a dialog, so both read one answer;
 * a place holding more than a glance takes is searched, on the API, through every place under it.
 */
@Component({
  selector: 'app-place-contents',
  imports: [
    MatButtonModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatMenuModule,
    Label,
    RouterLink,
    TranslatePipe,
    AmountPipe,
    ProductThumbnail,
  ],
  templateUrl: './place-contents.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class PlaceContents {
  private readonly facade = inject(InventoryFacade);
  private readonly live = inject(LiveChanges);
  private readonly destroyRef = inject(DestroyRef);

  private readonly auth = inject(AuthFacade);

  readonly companyId = input.required<string>();
  readonly locationId = input.required<string>();
  /**
   * Each line offers what is done with an article on a shelf (docs/SPEC.md § 7, 2026-10-09 23:19): the map's panel
   * records the movement; « Emplacements » only reads, so it offers none.
   */
  readonly actions = input(false);
  /** A line's ⋮ asked to move, count or receive it. */
  readonly act = output<PlaceAct>();
  /** The line carried by its grip, onto a place of the plan; null once it is let go. */
  readonly dragged = output<StockLevelRow | null>();

  protected readonly mayMove = computed(() => this.auth.hasPermission('stock.write'));
  /** A finger drags nothing a browser can drop; there the line's ⋮ moves it. */
  protected readonly finger = inject(COARSE_POINTER);
  protected readonly mayOpen = computed(() => this.auth.hasPermission('product.read'));

  /** What is typed, emptied when another place is chosen: words for one shelf are rarely those for the next. */
  protected readonly typed = linkedSignal({ source: this.locationId, computation: () => '' });
  /** What was asked of the API, the typed words once they rest. */
  private readonly searched = linkedSignal({ source: this.locationId, computation: () => '' });
  private searchTimer: ReturnType<typeof setTimeout> | null = null;

  /**
   * The answer for this place, never a late one for a place left behind; while new words are read the last answer
   * stays, as a list keeps its rows while it searches. A home is called empty only when the whole of the place's stock
   * was read and nothing narrowed it: past one page, or under words, a good not shown would read as missing, which is
   * worse than saying nothing.
   */
  protected readonly here = computed(() => {
    const contents = this.facade.contents();
    if (contents === null || contents.locationId !== this.locationId()) return null;
    const held = new Set(
      contents.levels.filter((line) => Number(line.quantity) > 0).map((line) => line.productId),
    );
    const complete = contents.q === '' && contents.total <= contents.levels.length;

    return {
      locationId: contents.locationId,
      q: contents.q,
      lines: contents.levels.map((line) => ({ ...line, negative: Number(line.quantity) < 0 })),
      more: Math.max(0, contents.total - contents.levels.length),
      emptyHomes: complete
        ? contents.homes.filter(
            (home, index, homes) =>
              !held.has(home.productId) &&
              homes.findIndex((other) => other.productId === home.productId) === index,
          )
        : [],
    };
  });
  /** The field is offered once the place holds more than a glance takes, and stays while words narrow it. */
  protected readonly searchable = computed(() => {
    const now = this.here();
    return (
      this.typed() !== '' ||
      (now !== null && (now.q !== '' || now.lines.length + now.more > CONTENTS_SEARCH_FROM))
    );
  });

  constructor() {
    // Read once per place and per words, never once per change of anything else on the screen around it.
    effect(() => {
      const companyId = this.companyId();
      const locationId = this.locationId();
      const q = this.searched();
      untracked(() => void this.facade.loadContents(companyId, locationId, q));
    });
    // What a place holds changes with every movement anywhere, and with the homes a product file sets.
    this.live.reloadOn(
      ['stock', 'product', 'product_home_location', 'delivery_note', 'invoice'],
      () => this.facade.reloadContents(this.companyId()),
      this.destroyRef,
    );
    this.destroyRef.onDestroy(() => {
      if (this.searchTimer !== null) clearTimeout(this.searchTimer);
    });
  }

  protected carry(event: DragEvent, line: StockLevelRow): void {
    // A browser starts no drag without data; the line itself goes to the map through the output.
    event.dataTransfer?.setData('text/plain', `${line.productReference} ${line.productName}`);
    if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move';
    this.dragged.emit(line);
  }

  protected search(words: string): void {
    this.typed.set(words);
    if (this.searchTimer !== null) clearTimeout(this.searchTimer);
    // Words typed for one place are never asked of the next, should the person press another before the pause ends.
    const place = this.locationId();
    this.searchTimer = setTimeout(() => {
      this.searchTimer = null;
      if (this.locationId() === place) this.searched.set(words.trim());
    }, CONTENTS_SEARCH_PAUSE_MS);
  }
}
