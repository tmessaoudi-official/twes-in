// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, Injector } from '@angular/core';
import { MatDialog } from '@angular/material/dialog';
import { Router } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { Session } from '../session/session';
import { type AddToChoice, type AddToData, AddToDialog, type AddToTarget } from './add-to-dialog';
import { ADD_TO_SOURCES, type AddToSource } from './add-to-sources';

/**
 * « Ajouter à… »: asks which document the goods go on, a new draft or an open one, then opens that document with them
 * on its lines, unsaved (`?add=`), where its editor prices and taxes them as it does a scan. The kinds are the ones the
 * document features hand in (`ADD_TO_SOURCES`), provided with this service by the screen that offers it.
 */
@Injectable()
export class AddToDocuments {
  private readonly session = inject(Session);
  private readonly dialog = inject(MatDialog);
  private readonly router = inject(Router);
  private readonly injector = inject(Injector);
  private readonly sources = inject(ADD_TO_SOURCES);

  /** The kinds the person may add goods to; none means « Ajouter à… » is not offered. */
  kinds(): AddToSource['kind'][] {
    return this.offered().map((source) => source.kind);
  }

  /** Asks where the products go and opens it; the customer's drafts alone when the screen knows the customer. */
  async open(productIds: readonly string[], customerId: string | null = null): Promise<void> {
    const companyId = this.session.me()?.company?.id;
    const offered = this.offered();
    if (!companyId || offered.length === 0 || productIds.length === 0) return;
    const targets = await Promise.all(
      offered.map(async (source): Promise<AddToTarget> => {
        const reader = this.injector.get(await source.reader());
        return {
          kind: source.kind,
          drafts: (customer) => reader.drafts(companyId, customer),
          customers: (words) => reader.customers(companyId, words),
        };
      }),
    );
    const choice = await firstValueFrom(
      this.dialog
        .open<AddToDialog, AddToData, AddToChoice | null>(AddToDialog, {
          data: {
            title: 'documents.add_to.title',
            help: null,
            targets,
            count: productIds.length,
            customerId,
            scale: null,
          },
        })
        .afterClosed(),
    );
    if (!choice) return;
    const path = offered.find((source) => source.kind === choice.kind)!.path;
    await this.router.navigate([path, choice.draftId ?? 'new'], {
      queryParams: { add: [...productIds] },
    });
  }

  private offered(): readonly AddToSource[] {
    return this.sources.filter(
      (source) =>
        this.session.hasModule(source.module) && this.session.hasPermission(source.permission),
    );
  }
}
