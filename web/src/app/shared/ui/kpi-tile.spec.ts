// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideTranslateService } from '@ngx-translate/core';
import { Session } from '../session/session';
import { BrowserStorageSettings } from '../settings/browser-storage-settings';
import { PageMemoryStorage, SETTINGS_STORAGE, SettingsFacade } from '../settings/settings-facade';
import type { LifecycleStage } from '../theme/lifecycle-tones';
import { KpiTile, type KpiDelta, type KpiLink } from './kpi-tile';

@Component({
  imports: [KpiTile],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<app-kpi-tile
    data-testid="tile"
    icon="payments"
    [label]="'Encaissé · octobre'"
    [figure]="figure()"
    [scale]="3"
    [currency]="'TND'"
    [delta]="delta()"
    [deltaStage]="deltaStage()"
    [caption]="caption()"
    [blank]="blank()"
    [see]="see()"
    [hero]="hero()"
    ><span data-testid="projected">en plus</span></app-kpi-tile
  >`,
})
class Host {
  readonly figure = signal<string | null>('41090.100');
  readonly delta = signal<KpiDelta | null>({
    kind: 'up',
    words: '+960,000 sur les mêmes jours du mois dernier',
  });
  readonly deltaStage = signal<LifecycleStage>('not-started');
  readonly caption = signal<string | null>('hors taxes');
  readonly blank = signal<string | null>(null);
  readonly see = signal<KpiLink | null>({ route: '/invoices', label: 'Voir' });
  readonly hero = signal(false);
}

describe('KpiTile', () => {
  async function render() {
    TestBed.configureTestingModule({
      imports: [Host],
      providers: [
        provideRouter([]),
        provideTranslateService({ lang: 'fr' }),
        // The amount pipe reads the company's country and the person's number format.
        { provide: Session, useValue: { me: signal(null) } },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
    const fixture = TestBed.createComponent(Host);
    await fixture.whenStable();
    fixture.detectChanges();
    const el = fixture.nativeElement as HTMLElement;
    const part = (name: string) => el.querySelector<HTMLElement>(`[data-part="${name}"]`);
    const settle = async () => {
      fixture.detectChanges();
      await fixture.whenStable();
    };
    return { fixture, el, part, settle };
  }

  it('names its figure in a strip with its icon, then the figure, its currency, the change and the caption', async () => {
    const { el, part } = await render();

    expect(part('label')?.textContent?.trim()).toBe('Encaissé · octobre');
    expect(part('strip')?.querySelector('mat-icon')?.getAttribute('aria-hidden')).toBe('true');
    expect(part('figure')?.textContent).toContain('41');
    expect(part('figure')?.querySelector('bdi')?.textContent?.trim()).toBe('TND');
    expect(part('delta')?.textContent).toContain('+960,000 sur les mêmes jours du mois dernier');
    expect(part('caption')?.textContent?.trim()).toBe('hors taxes');
    expect(el.querySelector('[data-testid="projected"]')).not.toBeNull();
  });

  it('offers its list from the strip as a link, and is not itself one, so the link is the only control in it', async () => {
    const { el, part } = await render();

    const link = part('see');
    expect(link?.tagName).toBe('A');
    expect(link?.getAttribute('href')).toBe('/invoices');
    expect(el.querySelectorAll('a')).toHaveLength(1);
  });

  it('says its sentence instead of a figure while it has none, never a dash', async () => {
    const { fixture, part, settle } = await render();
    fixture.componentInstance.figure.set(null);
    fixture.componentInstance.delta.set(null);
    fixture.componentInstance.blank.set('La marge apparaît dès qu’une ligne émise a un coût.');
    await settle();

    expect(part('figure')).toBeNull();
    expect(part('blank')?.textContent?.trim()).toBe(
      'La marge apparaît dès qu’une ligne émise a un coût.',
    );
    expect(part('tile')?.textContent).not.toContain('—');
  });

  /**
   * A change of a figure is a meaning, which takes a status tone (lifecycle-tones.ts); the accent names the company,
   * never a state (docs/SPEC.md § 7, 2026-09-16 status tones; S7.13).
   */
  it.each<LifecycleStage>(['not-started', 'done', 'needs-action', 'alarm'])(
    'colours the change of a figure with the %s stage’s status tone, never with the accent',
    async (stage) => {
      const { fixture, part, settle } = await render();
      fixture.componentInstance.deltaStage.set(stage);
      await settle();

      const delta = part('delta')!;
      const said = [delta.className, delta.getAttribute('style') ?? '', delta.innerHTML].join(' ');
      expect(said).not.toMatch(/accent/);
      expect(delta.getAttribute('style')).toMatch(/--twes-status-[a-z]+-bg/);
    },
  );

  it('reads the change as neutral unless the panel names a stage for it, as the approved design draws it', async () => {
    const { part } = await render();

    expect(part('delta')?.getAttribute('data-tone')).toBe('neutral');
  });

  it('draws a pointing arrow for the way the figure moved, mirrored in a right-to-left screen', async () => {
    const { fixture, part, settle } = await render();
    expect(part('delta')?.querySelector('mat-icon')?.textContent?.trim()).toBe('trending_up');
    expect(part('delta')?.querySelector('mat-icon')?.classList).toContain('mat-icon-rtl-mirror');

    fixture.componentInstance.delta.set({ kind: 'down', words: '−3,000' });
    await settle();
    expect(part('delta')?.querySelector('mat-icon')?.textContent?.trim()).toBe('trending_down');

    fixture.componentInstance.delta.set({ kind: 'same', words: 'Comme le mois dernier' });
    await settle();
    expect(part('delta')?.querySelector('mat-icon')?.textContent?.trim()).toBe('trending_flat');
  });

  it('draws its figure larger as the hero of a row', async () => {
    const { fixture, part, settle } = await render();
    expect(part('tile')?.getAttribute('data-hero')).toBe('false');

    fixture.componentInstance.hero.set(true);
    await settle();
    expect(part('tile')?.getAttribute('data-hero')).toBe('true');
  });
});
