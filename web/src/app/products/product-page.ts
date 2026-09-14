// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
  signal,
  untracked,
} from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { Router, RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { DescriptorForm } from '../shared/form/descriptor-form';
import { buildFormGroup } from '../shared/form/form-builder';
import type { FormValues } from '../shared/form/form-types';
import { productForm, productInput, productValues } from './product-forms';
import { ProductsFacade } from './products-facade';

/** One product: a new one to fill in, or an existing one to revise. */
@Component({
  selector: 'app-product-page',
  imports: [MatButtonModule, MatCardModule, RouterLink, TranslatePipe, DescriptorForm],
  templateUrl: './product-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ProductPage {
  private readonly facade = inject(ProductsFacade);
  private readonly auth = inject(AuthFacade);
  private readonly router = inject(Router);

  /** Bound from the route parameter by withComponentInputBinding(); absent on `products/new`. */
  readonly productId = input<string | undefined>(undefined);

  protected readonly id = computed(() => this.productId() ?? null);
  protected readonly busy = this.facade.busy;
  protected readonly error = this.facade.error;
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly mayWrite = computed(() => this.auth.hasPermission('product.write'));
  protected readonly saved = signal(false);

  /** Null while a new product is filled in; undefined until the product asked for has been read. */
  protected readonly current = computed(() => {
    const id = this.id();
    if (id === null) return null;
    const product = this.facade.product();
    return product?.id === id ? product : undefined;
  });
  protected readonly descriptor = computed(() => {
    const options = this.facade.options();
    return options === null
      ? null
      : productForm(options, this.facade.categories(), this.facade.customFields());
  });
  /**
   * What the form is of: the product and the fields shown. Reading the product again yields new objects with the same
   * content, and must not rebuild the form over what is being typed; another product or other fields must.
   */
  private readonly formKey = computed(() => {
    const descriptor = this.descriptor();
    const current = this.current();
    if (descriptor === null || current === undefined) return null;
    return `${current?.id ?? 'new'}|${JSON.stringify(descriptor)}`;
  });
  protected readonly form = computed(() => {
    if (this.formKey() === null) return null;
    return untracked(() => {
      const descriptor = this.descriptor();
      const options = this.facade.options();
      const current = this.current();
      if (descriptor === null || options === null || current === undefined) return null;
      return buildFormGroup(
        descriptor,
        productValues(current, options, this.facade.customFields()),
      );
    });
  });

  constructor() {
    effect(() => {
      const companyId = this.company()?.id;
      const id = this.id();
      untracked(() => {
        this.saved.set(false);
        if (companyId) {
          void this.facade.loadProduct(companyId, id);
        }
      });
    });
  }

  protected async save(values: FormValues): Promise<void> {
    const companyId = this.company()?.id;
    const options = this.facade.options();
    if (!companyId || options === null || this.busy()) return;
    const input = productInput(values, options, this.facade.customFields());
    const id = this.id();
    this.saved.set(false);
    if (id === null) {
      const created = await this.facade.createProduct(companyId, input);
      if (created !== null) {
        await this.router.navigate(['/products', created.id], { replaceUrl: true });
      }
    } else if ((await this.facade.reviseProduct(companyId, id, input)) !== null) {
      this.saved.set(true);
    }
  }
}
