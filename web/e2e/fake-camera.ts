// SPDX-License-Identifier: AGPL-3.0-or-later
import { mkdirSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

/**
 * A video file Chrome plays as its camera (`--use-file-for-fake-video-capture`), showing one EAN-13, so a spec drives
 * the real decoder on a real picture instead of a stand-in that agrees with whatever the app expects. Written as
 * y4m, the one format Chrome's fake capture reads that needs no encoder: a header, then each frame's grey levels
 * (the colour planes held neutral). One frame; Chrome loops it.
 */
export function fakeCameraShowing(ean13: string): string {
  const width = 640;
  const height = 480;
  const luma = new Uint8Array(width * height).fill(255);
  const modules = ean13Modules(ean13);
  const moduleWidth = 4;
  const left = Math.floor((width - modules.length * moduleWidth) / 2);
  const top = 140;
  const barHeight = 200;
  modules.forEach((dark, index) => {
    if (!dark) return;
    for (let y = top; y < top + barHeight; y++) {
      luma.fill(
        0,
        y * width + left + index * moduleWidth,
        y * width + left + (index + 1) * moduleWidth,
      );
    }
  });
  const chroma = new Uint8Array((width / 2) * (height / 2)).fill(128);
  const header = Buffer.from(`YUV4MPEG2 W${width} H${height} F10:1 Ip A1:1 C420jpeg\n`);
  const frame = Buffer.concat([Buffer.from('FRAME\n'), luma, chroma, chroma]);
  const dir = join(tmpdir(), 'twes-e2e-camera');
  mkdirSync(dir, { recursive: true });
  const path = join(dir, `${ean13}.y4m`);
  writeFileSync(path, Buffer.concat([header, frame]));
  return path;
}

const L = [
  '0001101',
  '0011001',
  '0010011',
  '0111101',
  '0100011',
  '0110001',
  '0101111',
  '0111011',
  '0110111',
  '0001011',
];
const G = [
  '0100111',
  '0110011',
  '0011011',
  '0100001',
  '0011101',
  '0111001',
  '0000101',
  '0010001',
  '0001001',
  '0010111',
];
const R = [
  '1110010',
  '1100110',
  '1101100',
  '1000010',
  '1011100',
  '1001110',
  '1010000',
  '1000100',
  '1001000',
  '1110100',
];
/** Which of L and G each digit of the left half takes, by the first digit (GS1 General Specifications, 5.2.2). */
const PARITY = [
  'LLLLLL',
  'LLGLGG',
  'LLGGLG',
  'LLGGGL',
  'LGLLGG',
  'LGGLLG',
  'LGGGLL',
  'LGLGLG',
  'LGLGGL',
  'LGGLGL',
];

/** The EAN-13's modules, dark or light, with eleven light modules of quiet zone on either side. */
function ean13Modules(code: string): boolean[] {
  if (!/^\d{13}$/.test(code)) throw new Error(`not an EAN-13: ${code}`);
  const digits = [...code].map(Number);
  const parity = PARITY[digits[0]];
  let bits = '0'.repeat(11) + '101';
  for (let at = 1; at <= 6; at++) bits += (parity[at - 1] === 'L' ? L : G)[digits[at]];
  bits += '01010';
  for (let at = 7; at <= 12; at++) bits += R[digits[at]];
  bits += '101' + '0'.repeat(11);
  return [...bits].map((bit) => bit === '1');
}
