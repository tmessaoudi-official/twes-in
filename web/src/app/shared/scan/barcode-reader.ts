// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * The port a camera frame is read through: the codes on it, each composed exactly as a handheld scanner types it, so
 * that what the camera reads enters the same path a scanner's keystrokes do (docs/SPEC.md § 7, 2026-09-22 22:38).
 */
export abstract class BarcodeReader {
  abstract read(frame: ImageData): Promise<readonly string[]>;
}

/** What a decoder answers for one symbol, as far as composing it needs. */
export interface DecodedSymbol {
  readonly text: string;
  /** The AIM symbology identifier: "]C1" GS1-128, "]d2" GS1 DataMatrix, "]Q3" GS1 QR, "]E0" EAN-13… */
  readonly symbologyIdentifier: string;
  readonly contentType: string;
  readonly isValid: boolean;
}

/**
 * A symbol as a scanner types it. GS1 content keeps its symbology identifier in front, as a scanner configured for
 * GS1 sends it, and its group separators inside: that is what tells the API a GS1 label from any other code. Anything
 * else is its text alone, as the scanner types it.
 */
export function composeCode(symbol: DecodedSymbol): string | null {
  if (!symbol.isValid || symbol.text === '') return null;
  return symbol.contentType === 'GS1' ? `${symbol.symbologyIdentifier}${symbol.text}` : symbol.text;
}
