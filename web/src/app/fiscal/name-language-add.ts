// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  afterNextRender,
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  inject,
  Injector,
  input,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormControl, ReactiveFormsModule } from '@angular/forms';
import { TranslatePipe } from '@ngx-translate/core';
import { Select } from '../shared/form/select';
import { DOCUMENT_LANGUAGES } from '../shared/i18n/languages';
import { nameField } from './fiscal-forms';
import type { NameLanguages } from './name-languages';

/**
 * « Ajouter une langue », closing a names section: the shared language dropdown, offering the languages the form
 * withholds. A language picked shows its field, and the person is put in it.
 */
@Component({
  selector: 'app-name-language-add',
  imports: [ReactiveFormsModule, TranslatePipe, Select],
  template: `
    @if (languages().addable().length > 0) {
      <app-select
        class="w-full max-w-72"
        [label]="'fiscal.names.add' | translate"
        [labelInside]="true"
        [languages]="true"
        [options]="languages().addable()"
        [formControl]="choice"
        testId="names-add"
      />
    }
  `,
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { class: 'block w-full' },
})
export class NameLanguageAdd {
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);
  private readonly injector = inject(Injector);

  readonly languages = input.required<NameLanguages>();
  protected readonly choice = new FormControl('', { nonNullable: true });

  constructor() {
    this.choice.valueChanges.pipe(takeUntilDestroyed()).subscribe((language) => {
      if (!DOCUMENT_LANGUAGES.includes(language)) return;
      this.languages().add(language);
      // The dropdown asks again from nothing: it adds, it does not hold a value.
      this.choice.setValue('', { emitEvent: false });
      afterNextRender(
        () =>
          this.host.nativeElement
            .closest('form')
            ?.querySelector<HTMLElement>(`[data-testid="field-${nameField(language)}"]`)
            ?.focus(),
        { injector: this.injector },
      );
    });
  }
}
