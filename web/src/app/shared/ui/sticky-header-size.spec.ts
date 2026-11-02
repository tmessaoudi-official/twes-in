// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { STICKY_HEADER_BLOCK_SIZE, StickyHeaderSize } from './sticky-header-size';

@Component({
  imports: [StickyHeaderSize],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `@if (shown()) {
    <div appStickyHeader class="twes-document-header">Facture</div>
  }`,
})
class Host {
  readonly shown = signal(true);
}

describe('StickyHeaderSize', () => {
  const root = document.documentElement;
  let watched: { told: ResizeObserverCallback; target: Element }[] = [];

  beforeEach(() => {
    watched = [];
    vi.stubGlobal(
      'ResizeObserver',
      class {
        constructor(private readonly told: ResizeObserverCallback) {}
        observe(target: Element): void {
          watched.push({ told: this.told, target });
          this.told([], this as unknown as ResizeObserver);
        }
        disconnect(): void {
          watched = watched.filter((one) => one.told !== this.told);
        }
      },
    );
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    root.style.removeProperty(STICKY_HEADER_BLOCK_SIZE);
  });

  function sized(element: Element, height: number): void {
    Object.defineProperty(element, 'offsetHeight', { configurable: true, value: height });
  }

  // jsdom lays nothing out: the header's height is given, as a browser would measure it.
  it('says how tall the stuck header is on the root, and again when it grows', async () => {
    TestBed.configureTestingModule({ imports: [Host] });
    const fixture = TestBed.createComponent(Host);
    const header = () =>
      fixture.nativeElement.querySelector('.twes-document-header') as HTMLElement;
    Object.defineProperty(HTMLElement.prototype, 'offsetHeight', { configurable: true, value: 64 });
    try {
      await fixture.whenStable();
      expect(root.style.getPropertyValue(STICKY_HEADER_BLOCK_SIZE)).toBe('64px');

      sized(header(), 112);
      for (const one of watched) one.told([], {} as ResizeObserver);
      expect(root.style.getPropertyValue(STICKY_HEADER_BLOCK_SIZE)).toBe('112px');
    } finally {
      delete (HTMLElement.prototype as { offsetHeight?: number }).offsetHeight;
    }
  });

  it('takes it back when the header goes, so a page without one keeps no room for it', async () => {
    TestBed.configureTestingModule({ imports: [Host] });
    const fixture = TestBed.createComponent(Host);
    await fixture.whenStable();
    sized(fixture.nativeElement.querySelector('.twes-document-header'), 80);
    for (const one of watched) one.told([], {} as ResizeObserver);
    expect(root.style.getPropertyValue(STICKY_HEADER_BLOCK_SIZE)).toBe('80px');

    fixture.componentInstance.shown.set(false);
    await fixture.whenStable();
    expect(root.style.getPropertyValue(STICKY_HEADER_BLOCK_SIZE)).toBe('');
  });

  // Moving from one record to another builds the next page before the last one goes: the one leaving must not take
  // away what the one arriving has just said.
  it('leaves the size the next header said when the one before goes after it', async () => {
    TestBed.configureTestingModule({ imports: [Host] });
    const first = TestBed.createComponent(Host);
    await first.whenStable();
    const second = TestBed.createComponent(Host);
    await second.whenStable();
    sized(second.nativeElement.querySelector('.twes-document-header'), 96);
    const secondWatch = watched[watched.length - 1]!;
    secondWatch.told([], {} as ResizeObserver);

    first.destroy();
    expect(root.style.getPropertyValue(STICKY_HEADER_BLOCK_SIZE)).toBe('96px');
    second.destroy();
    expect(root.style.getPropertyValue(STICKY_HEADER_BLOCK_SIZE)).toBe('');
  });

  it('says nothing where nothing can be measured', async () => {
    vi.unstubAllGlobals();
    vi.stubGlobal('ResizeObserver', undefined);
    TestBed.configureTestingModule({ imports: [Host] });
    const fixture = TestBed.createComponent(Host);
    await fixture.whenStable();
    expect(root.style.getPropertyValue(STICKY_HEADER_BLOCK_SIZE)).toBe('');
  });
});
