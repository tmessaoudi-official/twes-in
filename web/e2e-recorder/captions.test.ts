// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test } from '@playwright/test';
import { chapters, holdFor, subtitles, timestamp } from './captions';

// The recorder's arithmetic, run before any recording by the same command: how long a line stays on screen, and the
// WebVTT files written from the times each step began.

test('a line stays as long as it takes to read, never too short nor too long', () => {
  expect(holdFor('Court.')).toBe(2_500);
  // 13 words (the colon is not one) at three a second, plus the moment the eye needs to find the line.
  expect(
    holdFor('Vos factures sont ici : cherchez-en une par son numéro ou son client, ici.'),
  ).toBe(5_500);
  expect(holdFor('mot '.repeat(60))).toBe(9_000);
});

test('a time is written as WebVTT writes it', () => {
  expect(timestamp(0)).toBe('00:00:00.000');
  expect(timestamp(61_234)).toBe('00:01:01.234');
  expect(timestamp(3_723_004)).toBe('01:02:03.004');
  expect(timestamp(-40)).toBe('00:00:00.000');
});

test('the captions file numbers its cues and keeps their words', () => {
  expect(
    subtitles([
      { start: 1_000, end: 3_500, text: 'Vos factures sont ici.' },
      { start: 3_500, end: 6_000, text: 'Ouvrez « Créer ».' },
    ]),
  ).toBe(
    'WEBVTT\n\n1\n00:00:01.000 --> 00:00:03.500\nVos factures sont ici.\n\n' +
      '2\n00:00:03.500 --> 00:00:06.000\nOuvrez « Créer ».\n',
  );
});

test('the chapters file names each chapter by its step', () => {
  expect(chapters([{ start: 0, end: 2_000, text: 'Vos factures' }])).toBe(
    'WEBVTT\n\nchapter-1\n00:00:00.000 --> 00:00:02.000\nVos factures\n',
  );
});

test('a cue that ends before it starts is refused', () => {
  expect(() => subtitles([{ start: 2_000, end: 1_000, text: 'x' }])).toThrow(/ends before/);
});
