// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { MatDialogRef } from '@angular/material/dialog';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { PhonePairingDialog } from './phone-pairing-dialog';
import { type PairingState, PhonePairing } from './phone-pairing';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({});
  }
}

describe('PhonePairingDialog', () => {
  const state = signal<PairingState | null>(null);
  const pairing = {
    state,
    open: vi.fn(async () => {
      state.set({ id: 'p-1', url: 'https://twes.example/pair#abc', phone: 'waiting' });
    }),
    end: vi.fn(() => state.set(null)),
  };
  const close = vi.fn();
  let fixture: ComponentFixture<PhonePairingDialog>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  async function open(): Promise<void> {
    fixture = TestBed.createComponent(PhonePairingDialog);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  beforeEach(() => {
    state.set(null);
    pairing.open.mockClear();
    pairing.end.mockClear();
    close.mockReset();
    TestBed.configureTestingModule({
      imports: [PhonePairingDialog],
      providers: [
        provideTranslateService(),
        provideTranslateLoader(StaticLoader),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: MatDialogRef, useValue: { close } },
        { provide: PhonePairing, useValue: pairing },
      ],
    });
  });

  it('opens a link when none is open, and shows it as a code and as an address', async () => {
    await open();

    expect(pairing.open).toHaveBeenCalledOnce();
    expect(q('phone-pair-qr')?.querySelectorAll('rect[data-module]').length).toBeGreaterThan(100);
    expect(q('phone-pair-url')?.textContent).toContain('https://twes.example/pair#abc');
    expect(q('phone-pair-status')?.textContent).toContain('scan.phone.waiting');
  });

  it('keeps the link already open, and says when the phone is there', async () => {
    state.set({ id: 'p-1', url: 'https://twes.example/pair#abc', phone: 'connected' });
    await open();

    expect(pairing.open).not.toHaveBeenCalled();
    expect(q('phone-pair-status')?.textContent).toContain('scan.phone.connected');
  });

  it('lets the phone go', async () => {
    await open();

    q('phone-pair-end')!.click();

    expect(pairing.end).toHaveBeenCalled();
    expect(close).toHaveBeenCalled();
  });
});
