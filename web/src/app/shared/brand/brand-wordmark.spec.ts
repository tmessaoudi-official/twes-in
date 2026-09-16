// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { BrandWordmark, wordmarkSegments } from './brand-wordmark';

describe('wordmarkSegments', () => {
  it('turns the dot of the last "i" into the rising arrow and mutes the hyphen', () => {
    expect(wordmarkSegments('twes-in')).toEqual([
      { text: 'twes', kind: 'letters' },
      { text: '-', kind: 'joiner' },
      { text: 'ı', kind: 'arrow' },
      { text: 'n', kind: 'letters' },
    ]);
  });

  it('puts the arrow over the last letter of a name with no "i", lowercased', () => {
    expect(wordmarkSegments('Nova-Pay')).toEqual([
      { text: 'nova', kind: 'letters' },
      { text: '-', kind: 'joiner' },
      { text: 'pa', kind: 'letters' },
      { text: 'y', kind: 'arrow' },
    ]);
  });

  it('uses the last "i" when there are several, and never a joiner for the arrow', () => {
    expect(wordmarkSegments('mini facture-')).toEqual([
      { text: 'min', kind: 'letters' },
      { text: 'ı', kind: 'arrow' },
      { text: ' ', kind: 'joiner' },
      { text: 'facture', kind: 'letters' },
      { text: '-', kind: 'joiner' },
    ]);
  });

  it('draws nothing for an empty name', () => {
    expect(wordmarkSegments('  ')).toEqual([]);
  });
});

describe('BrandWordmark', () => {
  it('is read as the name itself, the drawn letters hidden from assistive technology', async () => {
    const fixture = TestBed.createComponent(BrandWordmark);
    fixture.componentRef.setInput('name', 'twes-in');
    await fixture.whenStable();
    const el = fixture.nativeElement as HTMLElement;

    const mark = el.querySelector('[role="img"]');
    expect(mark?.getAttribute('aria-label')).toBe('twes-in');
    expect(mark?.querySelector('[aria-hidden="true"]')?.textContent).toBe('twes-ın');
    expect(el.querySelector('.twes-wordmark-joiner')?.textContent).toBe('-');
    expect(el.querySelector('.twes-wordmark-arrow')?.textContent).toBe('ı');
  });
});
