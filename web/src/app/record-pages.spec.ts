// SPDX-License-Identifier: AGPL-3.0-or-later

// The record pages as a class (design proposal § 5, § 7.3 step 8), read from the sources so a page added later is held
// to it too: every stuck header says its height, which the scroller's scroll-padding and the sticky summary read, and
// every document with figures lays them out in the summary track.
declare const process: {
  getBuiltinModule(name: 'fs'): {
    readFileSync(path: string, encoding: 'utf8'): string;
    readdirSync(path: string, options: { recursive: true }): string[];
  };
};

const fs = process.getBuiltinModule('fs');
// Read from the web project's root, where the suite runs: the spec's own URL is the bundler's, not the disk's.
const app = 'src/app/';
const read = (path: string) => fs.readFileSync(app + path, 'utf8');
const sources = fs
  .readdirSync(app, { recursive: true })
  .filter((path) => /\.(html|ts)$/.test(path) && !path.endsWith('.spec.ts'))
  .sort();

describe('every stuck document header', () => {
  const drawing = sources.filter(
    (path) =>
      path !== 'shared/ui/sticky-header-size.ts' &&
      /class="twes-document-header\b/.test(read(path)),
  );

  it('is found where the pages draw it', () => {
    expect(drawing.length).toBeGreaterThanOrEqual(13);
  });

  it.each(drawing)('%s says its height through StickyHeaderSize', (path) => {
    const template = read(path);
    const headers = template.match(/<div\b[^>]*class="twes-document-header\b[^>]*>/g) ?? [];
    expect(headers.length).toBeGreaterThan(0);
    for (const header of headers) expect(header).toMatch(/\bappStickyHeader\b/);
    const component = path.replace(/\.html$/, '.ts');
    expect(read(component)).toMatch(/\bStickyHeaderSize\b/);
  });
});

describe('the four documents with figures', () => {
  const pages: [string, string[]][] = [
    ['invoices/invoice-page.html', ['invoice-due', 'invoice-totals']],
    ['quotes/quote-page.html', ['quote-totals']],
    ['delivery-notes/delivery-note-page.html', ['delivery-note-totals']],
    ['purchases/purchase-order-page.html', ['purchase-order-totals']],
  ];

  it.each(pages)('%s lays its figures in the summary track', (path, figures) => {
    const template = read(path);
    const summary = /<div recordSummary[^>]*>([\s\S]*?)<\/div>\s*<!-- \/recordSummary -->/.exec(
      template,
    )?.[1];
    expect(template).toContain('<app-record-columns');
    expect(summary).toBeDefined();
    for (const figure of figures) expect(summary).toContain(`data-testid="${figure}"`);
  });

  // The page box the tracks measure is the page's whole width (§ 5's arithmetic), not a narrower cap of its own.
  it.each(pages)('%s is not capped narrower than the page', (path) => {
    expect(read(path).split('\n')[0]).not.toMatch(/\bmax-w-/);
  });
});

describe('the read-only pairs', () => {
  it('go on two columns from the main track’s own width, not the page’s, at the threshold the tracks share', () => {
    const view = read('shared/form/record-view.ts');
    expect(view).not.toContain('@min-[30rem]');
    expect(view).toContain('twes-record-pairs');
    const tracks = read('shared/ui/_record-tracks.scss');
    expect(tracks).toMatch(/\$two-tracks-from:\s*55rem;/);
    expect(tracks).toMatch(/\$pairs-from:\s*34rem;/);
  });
});

// A field reached by Tab, or by a link to it, comes to rest clear of every layer stuck over the scroller it is in:
// the shell's bar and the document's header at the top, the phone's bar and the cookie notice at the foot.
describe('every scroller a document header sticks in', () => {
  it('keeps a focused field clear of the stuck layers through its scroll-padding', () => {
    const shell = read('shell/app-shell.scss');
    expect(shell).toMatch(
      /\.twes-shell-content\s*\{[^}]*scroll-padding-block-start:\s*calc\(\s*var\(--twes-top-bar-height, 0px\)\s*\+\s*var\(--twes-sticky-header-block-size, 0px\)\s*\)/,
    );
    expect(shell).toMatch(
      /scroll-padding-block-end:\s*calc\(\s*var\(--twes-bottom-bar-height, 0px\)\s*\+\s*var\(--twes-cookie-notice-height, 0px\)\s*\)/,
    );
    // From 1024 px the settings page scrolls on its own, its header stuck at its top.
    expect(read('shell/settings-area.scss')).toMatch(
      /\.twes-settings-page\s*\{[^}]*scroll-padding-block-start:\s*var\(--twes-sticky-header-block-size, 0px\)/,
    );
  });
});
