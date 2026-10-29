// SPDX-License-Identifier: AGPL-3.0-or-later

import { computed, inject, Injectable, signal } from '@angular/core';
import { MatDialog } from '@angular/material/dialog';
import { firstValueFrom } from 'rxjs';
import type { CompanyProofDialog } from './company-proof-dialog';
import { CompanyEntryProof, type EntryAsked } from './company-entry-proof';

/**
 * Asks for the proof the working company wants before this sign-in acts in it, once at a time: the pages and the
 * shell's own requests refused together wait on one question. A person who turns it down is not asked again by every
 * refused request; the shell's panel asks again when they choose to.
 */
@Injectable({ providedIn: 'root' })
export class CompanyEntry {
  private readonly proof = inject(CompanyEntryProof);
  private readonly dialog = inject(MatDialog);
  private pending: Promise<boolean> | null = null;
  private readonly declinedFor = signal<string | null>(null);

  /** What the working company asks, null once it is proved or when it asks nothing. */
  readonly asked = this.proof.asked;
  /** Whether the person turned down the proof the working company asks. */
  readonly declined = computed(() => {
    const asked = this.asked();
    return asked !== null && this.declinedFor() === asked.companyId;
  });

  /**
   * True once the working company is proved, false when the person gives up or it asks nothing. A proof turned down
   * is asked again only when `again` is set, the person asking for it.
   */
  prove(again = false): Promise<boolean> {
    this.pending ??= this.ask(again).finally(() => (this.pending = null));
    return this.pending;
  }

  private async ask(again: boolean): Promise<boolean> {
    // A proof that lapsed while the page was open is not in the session read earlier: the API's refusal says so.
    if (this.asked() === null) await this.proof.reread();
    const asked = this.asked();
    if (asked === null || (this.declinedFor() === asked.companyId && !again)) return false;

    const given = await this.open(asked);
    if (!given) {
      this.declinedFor.set(asked.companyId);
      return false;
    }
    this.declinedFor.set(null);
    await this.proof.reread();
    return true;
  }

  private async open(asked: EntryAsked): Promise<boolean> {
    // Loaded when first asked: the service is reached from the first page, which has no room for the dialog itself.
    const component = (await import('./company-proof-dialog')).CompanyProofDialog;
    const ref = this.dialog.open<CompanyProofDialog, EntryAsked, boolean>(component, {
      data: asked,
      autoFocus: 'first-tabbable',
    });
    return (await firstValueFrom(ref.afterClosed())) === true;
  }
}
