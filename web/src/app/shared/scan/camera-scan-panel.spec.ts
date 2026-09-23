// SPDX-License-Identifier: AGPL-3.0-or-later

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
import { PageMemoryStorage, SETTINGS_STORAGE } from '../settings/settings-facade';
import { provideQuietFeedback } from '../testing/feedback';
import { BarcodeReader } from './barcode-reader';
import { Camera, type CameraDevice, CameraRefused } from './camera';
import { CAMERA_CHOICE_KEY, CameraScanPanel } from './camera-scan-panel';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({});
  }
}

/** A stream as far as the panel touches it: tracks it stops when it is done with them. */
function stream() {
  const stop = vi.fn();
  return { stream: { getTracks: () => [{ stop }] } as unknown as MediaStream, stop };
}

describe('CameraScanPanel', () => {
  const devices: CameraDevice[] = [
    { id: 'front', label: 'Front camera' },
    { id: 'back', label: 'Back camera' },
  ];
  const camera = {
    available: () => true,
    devices: vi.fn(async () => devices),
    open: vi.fn(),
  };
  let fixture: ComponentFixture<CameraScanPanel>;
  let storage: PageMemoryStorage;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  /** Opens the panel and waits until it has asked for a camera and, when one opened, listed them. */
  async function open(): Promise<void> {
    fixture = TestBed.createComponent(CameraScanPanel);
    fixture.detectChanges();
    await vi.waitFor(() => expect(camera.open).toHaveBeenCalled());
    await new Promise((resolve) => setTimeout(resolve));
    fixture.detectChanges();
  }

  beforeEach(() => {
    storage = new PageMemoryStorage();
    camera.open.mockReset();
    camera.devices.mockClear();
    TestBed.configureTestingModule({
      imports: [CameraScanPanel],
      providers: [
        provideTranslateService(),
        provideTranslateLoader(StaticLoader),
        provideQuietFeedback(),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: MatDialogRef, useValue: { close: vi.fn() } },
        { provide: Camera, useValue: camera },
        { provide: SETTINGS_STORAGE, useValue: storage },
        { provide: BarcodeReader, useValue: { read: vi.fn(async () => []) } },
      ],
    });
  });

  it('opens the camera facing away the first time, then offers every camera by its name', async () => {
    camera.open.mockResolvedValue(stream().stream);
    await open();

    expect(camera.open).toHaveBeenCalledWith(null);
    const options = (q('camera-choice') as HTMLSelectElement).options;
    expect(Array.from(options).map((option) => option.textContent?.trim())).toEqual([
      'Front camera',
      'Back camera',
    ]);
  });

  it('opens the camera chosen here last time, and remembers a new choice', async () => {
    storage.setItem(CAMERA_CHOICE_KEY, 'back');
    const first = stream();
    camera.open.mockResolvedValueOnce(first.stream).mockResolvedValueOnce(stream().stream);
    await open();
    expect(camera.open).toHaveBeenLastCalledWith('back');

    const choice = q('camera-choice') as HTMLSelectElement;
    choice.value = 'front';
    choice.dispatchEvent(new Event('change'));
    await vi.waitFor(() => expect(camera.open).toHaveBeenLastCalledWith('front'));

    expect(first.stop).toHaveBeenCalled();
    expect(camera.open).toHaveBeenLastCalledWith('front');
    expect(storage.getItem(CAMERA_CHOICE_KEY)).toBe('front');
  });

  it('falls back to any camera when the one remembered is gone', async () => {
    storage.setItem(CAMERA_CHOICE_KEY, 'unplugged');
    camera.open
      .mockRejectedValueOnce(new CameraRefused('none'))
      .mockResolvedValueOnce(stream().stream);
    await open();

    expect(camera.open.mock.calls).toEqual([['unplugged'], [null]]);
    expect(q('camera-refused')).toBeNull();
  });

  it('says why the camera would not open', async () => {
    camera.open.mockRejectedValue(new CameraRefused('denied'));
    await open();

    expect(q('camera-refused')?.textContent).toContain('scan.camera.refused.denied');
  });

  it('lets go of the camera when it closes', async () => {
    const opened = stream();
    camera.open.mockResolvedValue(opened.stream);
    await open();

    fixture.destroy();

    expect(opened.stop).toHaveBeenCalled();
  });
});
