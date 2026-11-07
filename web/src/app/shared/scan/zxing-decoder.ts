// SPDX-License-Identifier: AGPL-3.0-or-later

/** Where the build puts the decoder and the licence texts of what it compiles in (web/angular.json, assets). */
export const ZXING_READER_PATH = '/vendor/zxing-reader/';

/** The symbologies a shop's goods and our own labels carry; fewer formats, faster frames. */
const FORMATS = [
  'EAN13',
  'EAN8',
  'UPCA',
  'UPCE',
  'Code128',
  'ITF',
  'Code39',
  'DataBar',
  'DataBarExp',
  'DataMatrix',
  'QRCode',
] as const;

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

let zxingModule: Promise<typeof import('zxing-wasm/reader')> | null = null;

/**
 * The codes on a camera frame, read by zxing-cpp compiled to WebAssembly, reader build only, served from our own origin
 * and never from the CDN the package defaults to. Loaded with the first frame, as the reader's own module is: nobody
 * who never opens the camera downloads either.
 */
export async function readCodes(frame: ImageData): Promise<readonly string[]> {
  const zxing = await loaded();
  const symbols = await zxing.readBarcodes(frame, {
    formats: [...FORMATS],
    // Plain keeps a GS1 label's group separators, where the default renders "(01)…" for people.
    textMode: 'Plain',
    tryHarder: true,
    maxNumberOfSymbols: 4,
  });
  return symbols.map(composeCode).filter((code): code is string => code !== null);
}

function loaded(): Promise<typeof import('zxing-wasm/reader')> {
  zxingModule ??= import('zxing-wasm/reader').then((zxing) => {
    zxing.prepareZXingModule({
      overrides: {
        locateFile: (path: string, prefix: string) =>
          path.endsWith('.wasm') ? `${ZXING_READER_PATH}${path}` : `${prefix}${path}`,
      },
    });
    return zxing;
  });
  return zxingModule;
}
