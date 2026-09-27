// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject, input, linkedSignal } from '@angular/core';
import { rxResource } from '@angular/core/rxjs-interop';
import { MatButtonModule } from '@angular/material/button';
import { TranslatePipe } from '@ngx-translate/core';
import { formatDay } from '../i18n/format';
import { LanguageFacade } from '../i18n/language-facade';
import { LEGAL_LANGUAGE_NAMES, LEGAL_LANGUAGES, LegalApi, type LegalLanguage } from './legal-api';
import { LegalMarkdown } from './legal-markdown';
import { STORED_ITEMS } from './stored-items';

/**
 * The text of one legal page, under the title its container gives it: the full page and the panel opened over a
 * screen show the same words. The API answers the page's latest version, in Markdown, in the language chosen here
 * (the interface's to begin with) or in the one it fell back to; the page says which, gives its date, and says it is a
 * draft until someone validated it (docs/SPEC.md § 8 row 148). `LegalMarkdown` renders the text, sanitized.
 */
@Component({
  selector: 'app-legal-text',
  imports: [LegalMarkdown, MatButtonModule, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="flex flex-col gap-4">
      <div
        class="flex flex-wrap gap-2"
        role="group"
        [attr.aria-label]="'legal.language' | translate"
      >
        @for (code of languages; track code) {
          @if (language() === code) {
            <button
              mat-flat-button
              type="button"
              aria-pressed="true"
              [attr.lang]="code"
              [attr.data-testid]="'legal-language-' + code"
            >
              {{ names[code] }}
            </button>
          } @else {
            <button
              mat-stroked-button
              type="button"
              aria-pressed="false"
              (click)="language.set(code)"
              [attr.lang]="code"
              [attr.data-testid]="'legal-language-' + code"
            >
              {{ names[code] }}
            </button>
          }
        }
      </div>
      @if (text.error()) {
        <p role="alert" data-testid="legal-unavailable">{{ 'legal.unavailable' | translate }}</p>
      } @else if (text.hasValue()) {
        @let shown = text.value();
        @if (shown === null) {
          <p data-testid="legal-drafting">{{ 'legal.drafting' | translate }}</p>
        } @else {
          <p class="flex flex-wrap items-center gap-2">
            <span data-testid="legal-version">{{
              'legal.version' | translate: { date: day(shown.publishedOn, shown.language) }
            }}</span>
            @if (!shown.validated) {
              <span class="twes-soon" data-testid="legal-draft">{{
                'legal.draft' | translate
              }}</span>
            }
          </p>
          @if (shown.language !== language()) {
            <p data-testid="legal-fallback">
              {{ 'legal.fallback' | translate: { language: names[shown.language] } }}
            </p>
          }
          <app-legal-markdown [body]="shown.body" [language]="shown.language" />
        }
      }
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
  private readonly api = inject(LegalApi);
  private readonly interface = inject(LanguageFacade);
  protected readonly languages = LEGAL_LANGUAGES;
  protected readonly names = LEGAL_LANGUAGE_NAMES;
  protected readonly stored = STORED_ITEMS;
  /** The interface's language until the reader picks another here. */
  protected readonly language = linkedSignal<LegalLanguage>(() => this.interface.current());

  protected readonly text = rxResource({
    params: () => ({ page: this.slug(), language: this.language() }),
    stream: ({ params }) => this.api.read(params.page, params.language),
  });

  protected day(value: string, language: string): string {
    return formatDay(value, language);
  }
}
