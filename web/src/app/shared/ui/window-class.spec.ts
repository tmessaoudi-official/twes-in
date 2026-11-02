// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { TextFactor, WINDOW_CLASS, windowClassOf } from './window-class';

describe('the window class', () => {
  it('decides on the window over the text factor', () => {
    expect(windowClassOf(320, 1)).toBe('compact');
    expect(windowClassOf(599, 1)).toBe('compact');
    expect(windowClassOf(600, 1)).toBe('medium');
    expect(windowClassOf(1199, 1)).toBe('medium');
    expect(windowClassOf(1200, 1)).toBe('expanded');
  });

  it('reads a 1280 px window at 125 % as the rail class: 1,024 effective pixels', () => {
    expect(windowClassOf(1280, 1.25)).toBe('medium');
    expect(windowClassOf(1500, 1.25)).toBe('expanded');
    expect(windowClassOf(700, 1.25)).toBe('compact');
    // Twice the text: a 1,280 px window is a 640 px one.
    expect(windowClassOf(1280, 2)).toBe('medium');
  });
});

describe('TextFactor', () => {
  const root = document.documentElement;
  const realWidth = Object.getOwnPropertyDescriptor(window, 'innerWidth')!;
  const widen = (width: number) => {
    Object.defineProperty(window, 'innerWidth', { configurable: true, value: width });
    window.dispatchEvent(new Event('resize'));
  };

  afterEach(() => {
    root.style.removeProperty('font-size');
    Object.defineProperty(window, 'innerWidth', realWidth);
  });

  it('is the root size over 16 px', () => {
    root.style.fontSize = '20px';
    TestBed.resetTestingModule();
    expect(TestBed.inject(TextFactor).factor()).toBe(1.25);
  });

  it('is 1 where nothing is laid out and the root size reads as nothing', () => {
    root.style.fontSize = '';
    TestBed.resetTestingModule();
    const factor = TestBed.inject(TextFactor).factor();
    expect(Number.isFinite(factor) && factor > 0).toBe(true);
  });

  it('gives the window class the text leaves, and follows the window', () => {
    root.style.fontSize = '20px';
    widen(1280);
    TestBed.resetTestingModule();
    const windowClass = TestBed.inject(WINDOW_CLASS);
    expect(windowClass()).toBe('medium');
    widen(700);
    expect(windowClass()).toBe('compact');
    widen(1600);
    expect(windowClass()).toBe('expanded');
  });
});
