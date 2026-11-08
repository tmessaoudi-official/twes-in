// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { describe, expect, it } from 'vitest';
import { BarcodeReader } from './barcode-reader';
import { BrowserCamera, Camera } from './camera';
import { ZxingBarcodeReader } from './zxing-barcode-reader';

// The camera's two ports answer for themselves wherever they are injected, so the application's start names neither and
// neither rides the first page, which only the lazy shell and scanning screens draw from.
describe('the camera ports, with no provider given', () => {
  it('answers this device’s cameras through the browser', () => {
    expect(TestBed.inject(Camera)).toBeInstanceOf(BrowserCamera);
  });

  it('reads a frame through zxing-cpp', () => {
    expect(TestBed.inject(BarcodeReader)).toBeInstanceOf(ZxingBarcodeReader);
  });

  it('gives one of each to the whole application', () => {
    expect(TestBed.inject(Camera)).toBe(TestBed.inject(Camera));
    expect(TestBed.inject(BarcodeReader)).toBe(TestBed.inject(BarcodeReader));
  });
});
