// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  CdkDrag,
  type CdkDragDrop,
  CdkDragHandle,
  CdkDropList,
  moveItemInArray,
} from '@angular/cdk/drag-drop';
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  inject,
  input,
  type OnInit,
} from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { Label } from '../shared/a11y/label';
import { Feedback } from '../shared/feedback/feedback';
import { FileDrop } from '../shared/form/file-drop';
import { LiveChanges } from '../shared/realtime/live-changes';
import { StatusBadge } from '../shared/ui/status-badge';
import { ProductPhotos } from './product-photos-facade';
import { PHOTO_TYPES, type ProductPhotoRow } from './product-photos-types';
import { PhonePairing, type PhotoOutcome } from '../shared/scan/phone-pairing';

/**
 * A product's photos: the gallery in its order, the main one marked, each sent from this device or taken with the
 * phone lent to this tab. Reordered by dragging or with the arrow buttons beside each photo, so no step needs a pointer.
 * Removing one is immediate and undone from its toast, as a reversible deletion is here: nothing asks first.
 */
@Component({
  selector: 'app-product-photos',
  imports: [
    CdkDrag,
    CdkDragHandle,
    CdkDropList,
    FileDrop,
    Label,
    MatButtonModule,
    MatIconModule,
    StatusBadge,
    TranslatePipe,
  ],
  templateUrl: './product-photos.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ProductPhotosSection implements OnInit {
  private readonly gallery = inject(ProductPhotos);
  private readonly auth = inject(AuthFacade);
  private readonly feedback = inject(Feedback);

  readonly productId = input.required<string>();
  readonly readOnly = input(false);
  /** What the phone is told a photo it took was added to. */
  readonly productName = input('');
  /** How many photos a product may have and how large one may be: the API's parameters, read with its options. */
  readonly maxPhotos = input(6);
  readonly maxBytes = input<number | null>(null);

  protected readonly accept = PHOTO_TYPES;
  protected readonly photos = this.gallery.photos;
  protected readonly busy = this.gallery.busy;
  protected readonly refusal = this.gallery.refusal;
  protected readonly full = computed(() => this.photos().length >= this.maxPhotos());
  /** What a refusal says in numbers, from the limits this screen was given where the API's answer named none. */
  protected readonly refusalParams = computed(() => {
    const maxBytes = this.maxBytes();
    return {
      max: this.maxPhotos(),
      ...(maxBytes === null ? {} : { megabytes: Math.round(maxBytes / 1048576) }),
      ...this.refusal()?.params,
    };
  });

  private readonly companyId = computed(() => this.auth.me()?.company?.id ?? null);

  private readonly live = inject(LiveChanges);
  private readonly phone = inject(PhonePairing);
  /** Whether a phone is lent to this tab, which can take the photo too. */
  protected readonly phoneLent = computed(() => this.phone.state()?.phone === 'connected');
  private readonly destroyRef = inject(DestroyRef);

  async ngOnInit(): Promise<void> {
    // Another member's change to the product, its photos included, shows here too.
    this.live.reloadOn(['product'], () => this.reload(), this.destroyRef);
    // A phone lent to this tab may take the photo; it arrives here while this gallery is on view.
    if (!this.readOnly()) this.phone.takePhotos((photo) => this.fromPhone(photo), this.destroyRef);
    await this.reload();
  }

  protected src(photo: ProductPhotoRow): string {
    const companyId = this.companyId();
    return companyId === null
      ? ''
      : this.gallery.url(companyId, this.productId(), photo.id, 'large');
  }

  protected original(photo: ProductPhotoRow): string {
    const companyId = this.companyId();
    return companyId === null
      ? ''
      : this.gallery.url(companyId, this.productId(), photo.id, 'original');
  }

  protected async upload(file: File): Promise<void> {
    const companyId = this.companyId();
    if (companyId === null) return;
    if (await this.gallery.add(companyId, this.productId(), file)) {
      this.feedback.success('products.photos.added');
    }
  }

  /** A photo the lent phone took, added as one picked here, and what the phone is told of it. */
  private async fromPhone(photo: File): Promise<PhotoOutcome> {
    const companyId = this.companyId();
    if (companyId !== null && (await this.gallery.add(companyId, this.productId(), photo))) {
      this.feedback.success('products.photos.added');
      return {
        outcome: 'done',
        message: 'products.photos.from_phone',
        params: { name: this.productName() },
      };
    }
    const code = this.refusal()?.code ?? 'network';
    return {
      outcome: 'refused',
      message: `products.photos.errors.${code}`,
      params: this.refusalParams(),
    };
  }

  protected async markMain(photo: ProductPhotoRow): Promise<void> {
    const companyId = this.companyId();
    if (companyId === null) return;
    if (await this.gallery.markMain(companyId, this.productId(), photo.id)) {
      this.feedback.success('products.photos.main_set');
    }
  }

  protected async move(photo: ProductPhotoRow, step: -1 | 1): Promise<void> {
    const companyId = this.companyId();
    if (companyId === null) return;
    if (await this.gallery.move(companyId, this.productId(), photo.id, step)) {
      this.feedback.success('products.photos.reordered');
    }
  }

  protected async dropped(event: CdkDragDrop<readonly ProductPhotoRow[]>): Promise<void> {
    const companyId = this.companyId();
    if (companyId === null || event.previousIndex === event.currentIndex) return;
    const ids = this.photos().map((photo) => photo.id);
    moveItemInArray(ids, event.previousIndex, event.currentIndex);
    if (await this.gallery.order(companyId, this.productId(), ids)) {
      this.feedback.success('products.photos.reordered');
    }
  }

  protected async remove(photo: ProductPhotoRow): Promise<void> {
    const companyId = this.companyId();
    const productId = this.productId();
    if (companyId === null) return;
    if (await this.gallery.remove(companyId, productId, photo.id)) {
      this.feedback.success(
        'products.photos.removed',
        {},
        {
          key: 'products.photos.undo',
          run: () => void this.restore(companyId, productId, photo.id),
        },
      );
    }
  }

  private async restore(companyId: string, productId: string, photoId: string): Promise<void> {
    if (await this.gallery.restore(companyId, productId, photoId)) {
      this.feedback.success('products.photos.restored');
    }
  }

  private async reload(): Promise<void> {
    const companyId = this.companyId();
    if (companyId !== null) await this.gallery.load(companyId, this.productId());
  }
}
