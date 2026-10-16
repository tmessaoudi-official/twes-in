// SPDX-License-Identifier: AGPL-3.0-or-later

import type { Routes } from '@angular/router';
import { ProductLabelsPage } from './product-labels-page';
import { ProductLabelsSheetPage } from './product-labels-sheet-page';

/**
 * The sheets of product labels, loaded with them: one product's own (`:productId`), and one of several products
 * selected on the list (`?id=…&id=…`). Declared here rather than beside the other routes, which load with the first page.
 */
export const PRODUCT_LABELS_ROUTES: Routes = [
  { path: '', component: ProductLabelsSheetPage },
  { path: ':productId', component: ProductLabelsPage },
];
