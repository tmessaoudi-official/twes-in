// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, inject } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import { TranslatePipe } from '@ngx-translate/core';
import type { SupportContact } from '../auth/auth-types';
import { BuildLine } from '../shared/build/build-line';
import type { Tour } from '../shared/tour/tour';
import type { GlossaryEntry } from './glossary';

export interface HelpPanelData {
  /** The guides this person may follow, those of the screen on view first. */
  readonly tours: readonly Tour[];
  readonly glossary: readonly GlossaryEntry[];
  /** How to reach the platform's operators, as the signed-in state carries it. */
  readonly support?: SupportContact;
}

/** One way of reaching the operators, as a link that opens it: a call, a WhatsApp chat or a mail. */
interface Channel {
  readonly key: 'phone' | 'whatsapp' | 'email';
  readonly icon: 'call' | 'chat' | 'mail';
  readonly href: string;
  readonly shown: string;
}

/** What the panel was closed for: a guide to start, the keyboard's list to open, or nothing. */
export type HelpChoice = { readonly tour: Tour } | 'shortcuts' | undefined;

/**
 * The « ? » of every screen: the guides that walk through a task, the words the screens use, the keyboard's
 * shortcuts, and how to reach the people who run the platform, with the build they will ask for. It closes with the
 * person's choice, and the shell acts on it, so a guide starts over the page itself.
 */
@Component({
  selector: 'app-help-panel',
  imports: [BuildLine, MatButtonModule, MatDialogModule, MatIconModule, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title data-testid="help-title">{{ 'help.title' | translate }}</h2>
    <mat-dialog-content>
      <!-- Material's own display beats a layout class on mat-dialog-content: the layout goes on a div inside it. -->
      <div class="flex flex-col gap-6">
        <section class="flex flex-col gap-2" aria-labelledby="twes-help-guides">
          <h3 id="twes-help-guides" class="text-base font-semibold">
            {{ 'help.guides' | translate }}
          </h3>
          @if (data.tours.length === 0) {
            <p class="text-sm text-on-surface-variant" data-testid="help-no-guide">
              {{ 'help.no_guide' | translate }}
            </p>
          } @else {
            <ul class="flex flex-col gap-1">
              @for (tour of data.tours; track tour.key) {
                <li>
                  <button
                    mat-button
                    type="button"
                    (click)="ref.close({ tour })"
                    [attr.data-testid]="'help-guide-' + tour.key"
                  >
                    <mat-icon aria-hidden="true">help</mat-icon>
                    {{ tour.titleKey | translate }}
                  </button>
                </li>
              }
            </ul>
          }
        </section>

        <section class="flex flex-col gap-2" aria-labelledby="twes-help-glossary">
          <h3 id="twes-help-glossary" class="text-base font-semibold">
            {{ 'help.glossary' | translate }}
          </h3>
          <dl class="flex flex-col gap-3" data-testid="help-glossary">
            @for (entry of data.glossary; track entry.key) {
              <div [attr.data-testid]="'glossary-' + entry.key">
                <dt class="font-semibold">{{ entry.termKey | translate }}</dt>
                <dd class="text-sm">{{ entry.definitionKey | translate }}</dd>
              </div>
            }
          </dl>
        </section>

        <!-- A channel no operator gave is left out, and with none the heading goes too. -->
        @if (channels().length > 0) {
          <section
            class="flex flex-col gap-2"
            aria-labelledby="twes-help-contact"
            data-testid="help-contact"
          >
            <h3 id="twes-help-contact" class="text-base font-semibold">
              {{ 'help.contact.title' | translate }}
            </h3>
            <ul class="flex flex-col gap-1">
              @for (channel of channels(); track channel.key) {
                <li>
                  <a
                    mat-button
                    [href]="channel.href"
                    [attr.target]="channel.key === 'whatsapp' ? '_blank' : null"
                    [attr.rel]="channel.key === 'whatsapp' ? 'noopener' : null"
                    [attr.data-testid]="'help-contact-' + channel.key"
                  >
                    <mat-icon aria-hidden="true">{{ channel.icon }}</mat-icon>
                    {{ 'help.contact.' + channel.key | translate }} {{ channel.shown }}
                  </a>
                </li>
              }
            </ul>
          </section>
        }

        <p class="text-sm text-on-surface-variant" data-testid="help-build">
          <app-build-line />
        </p>
      </div>
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button
        mat-stroked-button
        type="button"
        (click)="ref.close('shortcuts')"
        data-testid="help-shortcuts"
      >
        {{ 'help.shortcuts' | translate }}
      </button>
      <button mat-flat-button type="button" (click)="ref.close()" data-testid="help-close">
        {{ 'help.close' | translate }}
      </button>
    </mat-dialog-actions>
  `,
})
export class HelpPanel {
  protected readonly ref = inject<MatDialogRef<HelpPanel, HelpChoice>>(MatDialogRef);
  protected readonly data = inject<HelpPanelData>(MAT_DIALOG_DATA);

  protected readonly channels = computed<readonly Channel[]>(() => {
    const support = this.data.support;
    const channels: Channel[] = [];
    if (support?.phone) {
      channels.push({
        key: 'phone',
        icon: 'call',
        href: `tel:${support.phone.replace(/[^+\d]/g, '')}`,
        shown: support.phone,
      });
    }
    if (support?.whatsapp) {
      // WhatsApp's link takes the international number in digits alone, without its « + ».
      channels.push({
        key: 'whatsapp',
        icon: 'chat',
        href: `https://wa.me/${support.whatsapp.replace(/\D/g, '')}`,
        shown: support.whatsapp,
      });
    }
    if (support?.email) {
      channels.push({
        key: 'email',
        icon: 'mail',
        href: `mailto:${support.email}`,
        shown: support.email,
      });
    }
    return channels;
  });
}
