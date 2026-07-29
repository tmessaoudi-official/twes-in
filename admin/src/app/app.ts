/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { Component, computed, inject } from '@angular/core';
import { RouterOutlet } from '@angular/router';

import { BRANDING } from './branding';

@Component({
  selector: 'app-root',
  imports: [RouterOutlet],
  templateUrl: './app.html',
  styleUrl: './app.scss',
})
export class App {
  private readonly branding = inject(BRANDING);

  protected readonly productName = computed(() => this.branding.productName);
}
