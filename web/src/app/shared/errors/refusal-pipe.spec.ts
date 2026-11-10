// SPDX-License-Identifier: AGPL-3.0-or-later

import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideTranslateService, TranslateService } from '@ngx-translate/core';
import { RefusalPipe } from './refusal-pipe';
import type { UnknownRefusal, UnreadPart } from './unknown-refusal';

@Component({
  imports: [RefusalPipe],
  template: `<p>{{ code() | refusal: 'things.errors' : { retry: 'demain' } }}</p>`,
})
class Said {
  readonly code = signal<string | UnknownRefusal | UnreadPart>('locked');
}

describe('RefusalPipe', () => {
  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideTranslateService()] });
    const translate = TestBed.inject(TranslateService);
    translate.setTranslation('fr', {
      things: { errors: { locked: 'Verrouillé jusqu’à {{retry}}.' } },
      feedback: { unknown_refusal: 'Refusé (code : {{code}}).' },
      unread: { stock_locations: 'Emplacements non lus (code : {{code}}).' },
    });
    translate.use('fr');
  });

  it("says a code it knows in the screen's own words, with their parameters", () => {
    const fixture = TestBed.createComponent(Said);
    fixture.detectChanges();
    expect(fixture.nativeElement.textContent.trim()).toBe('Verrouillé jusqu’à demain.');
  });

  it('says an answer it does not know in the one sentence naming its code', () => {
    const fixture = TestBed.createComponent(Said);
    fixture.componentInstance.code.set({ unknown: 'brand_new' });
    fixture.detectChanges();
    expect(fixture.nativeElement.textContent.trim()).toBe('Refusé (code : brand_new).');
  });

  it('says a part another feature could not read for the screen in that part’s sentence, naming its code', () => {
    const fixture = TestBed.createComponent(Said);
    fixture.componentInstance.code.set({ unread: 'stock_locations', code: 'invalid' });
    fixture.detectChanges();
    expect(fixture.nativeElement.textContent.trim()).toBe('Emplacements non lus (code : invalid).');
  });
});
