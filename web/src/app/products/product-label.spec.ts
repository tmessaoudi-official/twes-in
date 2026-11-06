// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { provideTranslateService, TranslateService } from '@ngx-translate/core';
import { describe, expect, it } from 'vitest';
import { ProductLabel } from './product-label';

describe('ProductLabel', () => {
  function render(code: string): HTMLElement {
    TestBed.configureTestingModule({ providers: [provideTranslateService()] });
    TestBed.inject(TranslateService).setTranslation('fr', {
      products: { labels: { no_bars: 'Pas de code-barres : « {{character}} » ne s’y code pas' } },
    });
    TestBed.inject(TranslateService).use('fr');
    const fixture = TestBed.createComponent(ProductLabel);
    fixture.componentRef.setInput('name', 'Vis 6 mm');
    fixture.componentRef.setInput('reference', 'VIS-6');
    fixture.componentRef.setInput('code', code);
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  it('says where its bars would be that its code cannot be drawn, and which character stops it', () => {
    const label = render('VIS-é6');

    expect(label.querySelector('svg')).toBeNull();
    expect(label.querySelector('[data-testid="label-no-bars"]')?.textContent?.trim()).toBe(
      'Pas de code-barres : « é » ne s’y code pas',
    );
    expect(label.textContent).toContain('VIS-é6');
  });

  it('draws the bars of a code it can carry, and says nothing more', () => {
    const label = render('VIS-6');

    expect(label.querySelector('svg')).not.toBeNull();
    expect(label.querySelector('[data-testid="label-no-bars"]')).toBeNull();
  });
});
