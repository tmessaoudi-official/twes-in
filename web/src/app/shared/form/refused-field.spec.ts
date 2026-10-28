// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { beforeEach, describe, expect, it } from 'vitest';
import { RefusedField } from './refused-field';

@Component({
  imports: [MatFormFieldModule, MatInputModule, RefusedField],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <mat-form-field data-testid="field">
      <mat-label>Lignes</mat-label>
      <input matInput [appRefused]="refused()" data-testid="input" />
      <mat-hint data-testid="hint">De 1 à 5 000.</mat-hint>
      <mat-error data-testid="error">De 1 à 5 000 lignes.</mat-error>
    </mat-form-field>
  `,
})
class Host {
  readonly refused = signal(false);
}

describe('RefusedField', () => {
  let fixture: ComponentFixture<Host>;
  const query = (id: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${id}"]`);
  const settle = async (): Promise<void> => {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  };

  beforeEach(async () => {
    TestBed.configureTestingModule({
      providers: [{ provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } }],
    });
    fixture = TestBed.createComponent(Host);
    await settle();
  });

  it('shows the hint and no refusal while the value is accepted', () => {
    expect(query('field')?.classList.contains('mat-form-field-invalid')).toBe(false);
    expect(query('input')?.getAttribute('aria-invalid')).toBe('false');
    expect(query('hint')).not.toBeNull();
    expect(query('error')).toBeNull();
  });

  // The outline turns red as a form's refused field does, not only the words under it (support screenshots).
  it('turns the field red and says the refusal in the hint’s place once refused, and back once right', async () => {
    fixture.componentInstance.refused.set(true);
    await settle();

    expect(query('field')?.classList.contains('mat-form-field-invalid')).toBe(true);
    expect(query('input')?.getAttribute('aria-invalid')).toBe('true');
    expect(query('error')?.textContent).toContain('5 000');
    expect(query('hint')).toBeNull();

    fixture.componentInstance.refused.set(false);
    await settle();

    expect(query('field')?.classList.contains('mat-form-field-invalid')).toBe(false);
    expect(query('error')).toBeNull();
  });
});
