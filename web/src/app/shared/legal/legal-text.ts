// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject, input, linkedSignal } from '@angular/core';
import { rxResource } from '@angular/core/rxjs-interop';
import { FormsModule } from '@angular/forms';
import { TranslatePipe } from '@ngx-translate/core';
import { Select } from '../form/select';
import { formatDay } from '../i18n/format';
import { LanguageFacade } from '../i18n/language-facade';
import { languageChoices } from '../i18n/language-options';
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
  imports: [FormsModule, LegalMarkdown, Select, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="flex flex-col gap-4">
      <app-select
        class="w-full max-w-64"
        [label]="'legal.language' | translate"
        [labelInside]="true"
        [languages]="true"
        [options]="options"
        [ngModel]="language()"
        (ngModelChange)="choose($event)"
        testId="legal-language"
      />
      @if (text.error()) {
        <p role="alert" data-testid="legal-unavailable">{{ 'legal.unavailable' | translate }}</p>
      } @else if (text.hasValue()) {
        @let shown = text.value();
        @if (shown === null) {
          <p data-testid="legal-drafting">{{ 'legal.drafting' | translate }}</p>
        } @else {
          <p class="flex flex-wrap items-center gap-2">
            <span data-testid="legal-version">{{
              'legal.version' | translate: { date: day(shown.publishedOn) }
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
          <app-legal-markdown
            [body]="shown.body"
            [language]="shown.language"
            [values]="shown.values"
          />
        }
      }
      <!-- Rendered from the one declaration scripts/gates/stored-items.sh checks against the code. -->
      @if (slug() === 'cookies') {
        <section class="flex flex-col gap-3" data-testid="stored-items">
          <h2 class="text-lg font-semibold">{{ 'legal.stored.title' | translate }}</h2>
          <p>{{ 'legal.stored.intro' | translate }}</p>
          <div class="relative overflow-x-auto">
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
  protected readonly names = LEGAL_LANGUAGE_NAMES;
  protected readonly stored = STORED_ITEMS;
  /** The interface's language until the reader picks another here. */
  protected readonly language = linkedSignal<LegalLanguage>(() => this.interface.current());
  protected readonly options = languageChoices(LEGAL_LANGUAGES, 'legal-language');

  protected choose(tag: string | null): void {
    if (tag !== null) {
      this.language.set(tag);
    }
  }

  protected readonly text = rxResource({
    params: () => ({ page: this.slug(), language: this.language() }),
    stream: ({ params }) => this.api.read(params.page, params.language),
  });

  /** In the interface's language, like the sentence around it, whatever language the text is in. */
  protected day(value: string): string {
    return formatDay(value, this.interface.current());
  }
}
