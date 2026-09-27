// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { TranslatePipe } from '@ngx-translate/core';
import { STORED_ITEMS } from './stored-items';

/**
 * The text of one legal page, under the title its container gives it: the full page and the panel opened over a
 * screen show the same words. Until the platform operator writes the texts, each says it is a draft.
 */
@Component({
  selector: 'app-legal-text',
  imports: [TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="flex flex-col gap-4">
      <p>
        <span class="twes-soon" data-testid="legal-draft">{{ 'legal.draft' | translate }}</span>
      </p>
      <p data-testid="legal-drafting">{{ 'legal.drafting' | translate }}</p>
      <!-- Rendered from the one declaration scripts/gates/stored-items.sh checks against the code. -->
      @if (slug() === 'cookies') {
        <section class="flex flex-col gap-3" data-testid="stored-items">
          <h2 class="text-lg font-semibold">{{ 'legal.stored.title' | translate }}</h2>
          <p>{{ 'legal.stored.intro' | translate }}</p>
          <div class="overflow-x-auto">
            <table class="twes-legal-table">
              <thead>
                <tr>
                  <th scope="col">{{ 'legal.stored.name' | translate }}</th>
                  <th scope="col">{{ 'legal.stored.kind' | translate }}</th>
                  <th scope="col">{{ 'legal.stored.purpose' | translate }}</th>
                  <th scope="col">{{ 'legal.stored.lasts' | translate }}</th>
                </tr>
              </thead>
              <tbody>
                @for (item of stored; track item.id) {
                  <tr data-testid="stored-row">
                    <td>
                      <code>{{ item.name }}</code>
                    </td>
                    <td>{{ 'legal.stored.kinds.' + item.kind | translate }}</td>
                    <td>{{ 'legal.stored.purposes.' + item.id | translate }}</td>
                    <td>{{ 'legal.stored.durations.' + item.lasts | translate }}</td>
                  </tr>
                }
              </tbody>
            </table>
          </div>
          <p data-testid="stored-third-party">{{ 'legal.stored.third_party' | translate }}</p>
        </section>
      }
    </div>
  `,
})
export class LegalText {
  readonly slug = input.required<string>();
  protected readonly stored = STORED_ITEMS;
}
