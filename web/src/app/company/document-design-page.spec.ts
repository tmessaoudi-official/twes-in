// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { provideRouter } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { CompanySettings } from '../settings/company-settings-facade';
import type { SettingRow } from '../shared/settings/settings-types';
import { Session } from '../shared/session/session';
import { provideQuietFeedback, successToasts } from '../shared/testing/feedback';
import { DocumentDesignFacade } from './document-design-facade';
import { DocumentDesignPage, PREVIEW_DELAY_MS } from './document-design-page';
import type { PreviewState } from './document-design-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      documents: {
        title: 'Modèles de documents',
        saved: 'Enregistré',
        preview_states: { nothing: 'Aucune facture pour l’instant' },
        logo_room:
          'Largeur de {{widthMin}} à {{widthMax}} mm, hauteur de {{heightMin}} à {{heightMax}} mm.',
        logo_stretched: 'Le logo sera étiré à cette taille.',
      },
      settings: { forbidden: 'Réservé' },
    });
  }
}

function row(
  key: string,
  defaultValue: string | number | boolean,
  company?: string | number | boolean,
): SettingRow {
  const bounds: Record<string, [number, number]> = {
    'document.logo_width': [10, 105],
    'document.logo_height': [5, 70],
  };
  return {
    key,
    chain: 'parties',
    type:
      key === 'document.accent'
        ? 'colour'
        : typeof defaultValue === 'number'
          ? 'int'
          : typeof defaultValue === 'boolean'
            ? 'bool'
            : 'enum',
    labelKey: `settings.${key}`,
    module: 'core',
    defaultValue,
    value: company ?? defaultValue,
    source: company === undefined ? null : 'company',
    levels: company === undefined ? [] : [{ level: 'company', value: company }],
    overridableLevels: ['company'],
    writableLevels: ['company'],
    choices: key === 'document.layout' ? ['classic', 'modern', 'compact'] : [],
    min: bounds[key]?.[0] ?? null,
    max: bounds[key]?.[1] ?? null,
    maxLength: null,
    pattern: null,
  };
}

describe('DocumentDesignPage', () => {
  const rows = signal<readonly SettingRow[]>([]);
  const settings = {
    rows: rows.asReadonly(),
    busy: signal(false).asReadonly(),
    error: signal(null).asReadonly(),
    load: vi.fn(),
    save: vi.fn(),
  };
  const picture = signal<string | null>(null);
  const state = signal<PreviewState>('loading');
  const design = {
    picture: picture.asReadonly(),
    state: state.asReadonly(),
    preview: vi.fn(),
    without: vi.fn((why: PreviewState) => state.set(why)),
    logoRatio: signal<number | null>(3).asReadonly(),
    loadLogoRatio: vi.fn(),
  };
  const permissions = new Set<string>();
  const auth = {
    me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }),
    hasPermission: (permission: string) => permissions.has(permission),
  };
  let fixture: ComponentFixture<DocumentDesignPage>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);
  const button = (testId: string): HTMLButtonElement => q(testId) as HTMLButtonElement;
  const layoutChecked = (): string | undefined =>
    (
      fixture.nativeElement.querySelector(
        'mat-radio-button.mat-mdc-radio-checked',
      ) as HTMLElement | null
    )?.dataset['testid'];

  async function settle(wait = 0): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    await new Promise((resolve) => setTimeout(resolve, wait));
    await fixture.whenStable();
    fixture.detectChanges();
  }

  beforeEach(async () => {
    permissions.clear();
    permissions.add('company.settings');
    rows.set([]);
    picture.set(null);
    state.set('loading');
    settings.load.mockReset().mockImplementation(async () => {
      rows.set([
        row('document.layout', 'classic', 'modern'),
        row('document.accent', '#1f2328', '#1f6feb'),
        row('document.logo_width', 48),
        row('document.logo_height', 17),
        row('document.logo_keep_proportions', true),
      ]);
    });
    settings.save.mockReset().mockImplementation(async () => {
      rows.set([
        row('document.layout', 'classic', 'compact'),
        row('document.accent', '#1f2328', '#1f6feb'),
        row('document.logo_width', 48),
        row('document.logo_height', 17),
        row('document.logo_keep_proportions', true),
      ]);
      return true;
    });
    design.preview.mockReset().mockImplementation(async () => {
      picture.set('data:image/png;base64,UE5H');
      state.set('ready');
    });
    TestBed.configureTestingModule({
      imports: [DocumentDesignPage],
      providers: [
        ...provideQuietFeedback(),
        provideRouter([]),
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: CompanySettings, useValue: settings },
        { provide: DocumentDesignFacade, useValue: design },
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
      ],
    });
  });

  async function open(): Promise<void> {
    fixture = TestBed.createComponent(DocumentDesignPage);
    await settle();
  }

  it('opens on the design the company saved, and shows it on its latest invoice', async () => {
    await open();

    expect(settings.load).toHaveBeenCalledWith('c1');
    expect(layoutChecked()).toBe('documents-layout-modern');
    expect(design.preview).toHaveBeenCalledExactlyOnceWith('c1', {
      layout: 'modern',
      accent: '#1f6feb',
      logoWidth: 48,
      logoHeight: 17,
      logoKeepsProportions: true,
    });
    expect(q('documents-preview-image')?.getAttribute('src')).toBe('data:image/png;base64,UE5H');
    expect(q('documents-logo-link')?.getAttribute('href')).toBe('/company/profile');
    expect([button('documents-save').disabled, button('documents-cancel').disabled]).toEqual([
      true,
      true,
    ]);
  });

  it('tries a layout once the choice rests, and saves only what changed', async () => {
    await open();
    design.preview.mockClear();

    (q('documents-layout-compact')?.querySelector('input') as HTMLInputElement).click();
    await settle();
    expect(design.preview).not.toHaveBeenCalled();
    await settle(PREVIEW_DELAY_MS + 50);

    expect(design.preview).toHaveBeenCalledExactlyOnceWith('c1', {
      layout: 'compact',
      accent: '#1f6feb',
      logoWidth: 48,
      logoHeight: 17,
      logoKeepsProportions: true,
    });
    expect(button('documents-save').disabled).toBe(false);

    button('documents-save').click();
    await settle();

    expect(settings.save).toHaveBeenCalledWith('c1', [
      { key: 'document.layout', value: 'compact' },
    ]);
    expect(successToasts()).toEqual(['documents.saved']);
    expect(layoutChecked()).toBe('documents-layout-compact');
    expect(button('documents-save').disabled).toBe(true);
  });

  it('goes back to the saved design, and shows it again', async () => {
    await open();
    (q('documents-layout-classic')?.querySelector('input') as HTMLInputElement).click();
    await settle();
    design.preview.mockClear();

    button('documents-cancel').click();
    await settle(PREVIEW_DELAY_MS + 50);

    expect(layoutChecked()).toBe('documents-layout-modern');
    expect(design.preview).toHaveBeenCalledExactlyOnceWith('c1', {
      layout: 'modern',
      accent: '#1f6feb',
      logoWidth: 48,
      logoHeight: 17,
      logoKeepsProportions: true,
    });
    expect(settings.save).not.toHaveBeenCalled();
  });

  it("sets the height from the logo's proportions while locked, refuses a size no page holds, and saves both", async () => {
    await open();
    design.preview.mockClear();
    const width = q('documents-logo-width') as HTMLInputElement;
    const height = q('documents-logo-height') as HTMLInputElement;
    expect(width.value).toBe('48');
    expect(q('documents-logo-lock')?.getAttribute('aria-pressed')).toBe('true');
    expect(q('documents-logo-room')?.textContent).toContain('10 à 105 mm');
    expect(q('documents-logo-stretched')).toBeNull();

    // Past the bounds, or between two millimetres, nothing is tried or saved.
    for (const refused of ['200', '60.5']) {
      width.value = refused;
      width.dispatchEvent(new Event('input'));
      await settle(PREVIEW_DELAY_MS + 50);
      expect(design.preview).not.toHaveBeenCalled();
      expect(button('documents-save').disabled).toBe(true);
    }

    width.value = '60';
    width.dispatchEvent(new Event('input'));
    await settle(PREVIEW_DELAY_MS + 50);
    expect(height.value).toBe('20');
    expect(design.preview).toHaveBeenCalledExactlyOnceWith('c1', {
      layout: 'modern',
      accent: '#1f6feb',
      logoWidth: 60,
      logoHeight: 20,
      logoKeepsProportions: true,
    });

    button('documents-save').click();
    await settle();
    expect(settings.save).toHaveBeenCalledWith('c1', [
      { key: 'document.logo_width', value: 60 },
      { key: 'document.logo_height', value: 20 },
    ]);
  });

  it('frees the two sizes once unlocked, says the logo will be stretched, and saves the choice', async () => {
    await open();
    design.preview.mockClear();

    (q('documents-logo-lock') as HTMLButtonElement).click();
    await settle();
    expect(q('documents-logo-lock')?.getAttribute('aria-pressed')).toBe('false');
    expect(q('documents-logo-stretched')?.textContent).toContain('étiré');

    const height = q('documents-logo-height') as HTMLInputElement;
    height.value = '30';
    height.dispatchEvent(new Event('input'));
    await settle(PREVIEW_DELAY_MS + 50);
    expect((q('documents-logo-width') as HTMLInputElement).value).toBe('48');
    expect(design.preview).toHaveBeenLastCalledWith('c1', {
      layout: 'modern',
      accent: '#1f6feb',
      logoWidth: 48,
      logoHeight: 30,
      logoKeepsProportions: false,
    });

    button('documents-save').click();
    await settle();
    expect(settings.save).toHaveBeenCalledWith('c1', [
      { key: 'document.logo_height', value: 30 },
      { key: 'document.logo_keep_proportions', value: false },
    ]);
  });

  it('says why there is no picture', async () => {
    design.preview.mockImplementation(async () => {
      picture.set(null);
      state.set('nothing');
    });
    await open();

    expect(q('documents-preview-image')).toBeNull();
    expect(q('documents-preview-message')?.textContent).toContain('Aucune facture pour l’instant');
  });

  it('says the preview failed, not that it is coming, when the settings never arrived', async () => {
    settings.load.mockImplementation(async () => rows.set([]));
    await open();

    expect(design.preview).not.toHaveBeenCalled();
    expect(q('documents-preview-loading')).toBeNull();
    expect(q('documents-preview-message')).not.toBeNull();
    expect(state()).toBe('failed');
  });

  it('is the settings page’s: without the permission it reads nothing', async () => {
    permissions.clear();
    await open();

    expect(q('documents-forbidden')?.textContent).toContain('Réservé');
    expect(settings.load).not.toHaveBeenCalled();
    expect(design.preview).not.toHaveBeenCalled();
  });
});
