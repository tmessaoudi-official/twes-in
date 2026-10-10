// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  afterNextRender,
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  effect,
  type ElementRef,
  inject,
  input,
  output,
  signal,
  untracked,
  viewChild,
} from '@angular/core';
import { TranslatePipe } from '@ngx-translate/core';
import {
  AmbientLight,
  BoxGeometry,
  Color,
  DirectionalLight,
  EdgesGeometry,
  Group,
  LineBasicMaterial,
  LineSegments,
  Mesh,
  MeshLambertMaterial,
  PerspectiveCamera,
  Raycaster,
  Scene,
  Vector2,
  WebGLRenderer,
} from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import { Label } from '../shared/a11y/label';
import { colourTokens, statusTokens } from '../shared/theme/accent-theme';
import { ThemeFacade } from '../shared/theme/theme-facade';
import type { StockDrawingRow, StockStructureRow } from './inventory-types';
import {
  CAMERA_FOV,
  CHOSEN_HALO,
  type CameraPresetName,
  type CameraView,
  cameraPreset,
  haloOf,
  lookingAt,
  moved,
  tonesOf,
  turned,
  type VolumeToggles,
  type VolumeToken,
  volumeBoxes,
  volumeCounts,
  volumeExtent,
} from './stock-map-volume-scene';

/** One press of « tourner » walks round a sixteenth of the circle; one press of « approcher » takes a fifth off. */
const TURN_STEP = Math.PI / 8;
const NEARER = 0.8;
const TOGGLES = ['building', 'ground', 'heights'] as const;
/** A press that travels further than this, in pixels, turned the view: it chooses nothing. */
const CLICK_SLOP = 5;

/**
 * The floor in volume (docs/SPEC.md row 83): for looking only, never for arranging. It is its own component, reached
 * through a `@defer` block on the map, so three.js is fetched the first time someone opens the 3D and never with the
 * first page. Everything it shows is worked out in `stock-map-volume-scene.ts`; this only paints it, renders a frame
 * when something changed rather than sixty a second, and gives the graphics context back when it goes.
 */
@Component({
  selector: 'app-stock-map-volume',
  imports: [TranslatePipe, Label],
  templateUrl: './stock-map-volume.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class StockMapVolume {
  readonly floorName = input.required<string>();
  /** The floor's measured size, or null when it never was. */
  readonly size = input.required<{ width: number; depth: number } | null>();
  readonly drawings = input.required<readonly StockDrawingRow[]>();
  readonly structures = input.required<readonly StockStructureRow[]>();
  /** The stock locations holding what is looked for. */
  readonly lit = input.required<ReadonlySet<string>>();
  /** The drawings chosen on the plan, in the list or here. */
  readonly chosen = input<ReadonlySet<string>>(new Set());
  /** Fills the space it is given, as the map over the whole window gives it, rather than sizing itself to the window. */
  readonly filling = input(false);
  /** A drawing clicked here, by its id: the page chooses it everywhere. */
  readonly pick = output<string>();

  private readonly theme = inject(ThemeFacade);
  private readonly canvas = viewChild.required<ElementRef<HTMLCanvasElement>>('canvas');

  protected readonly toggleNames = TOGGLES;
  protected readonly toggles = signal<VolumeToggles>({
    building: true,
    ground: true,
    heights: true,
  });
  /** Whether this browser can draw it: unknown until the canvas is there, then drawn or said it cannot be. */
  protected readonly webgl = signal<'pending' | 'ready' | 'missing'>('pending');

  protected readonly boxes = computed(() =>
    volumeBoxes({
      size: this.size(),
      drawings: this.drawings(),
      structures: this.structures(),
      lit: this.lit(),
      chosen: this.chosen(),
      toggles: this.toggles(),
    }),
  );
  protected readonly counts = computed(() => volumeCounts(this.boxes()));
  /** The one place chosen, which the camera turns to; compared by its key, so a repaint does not turn it again. */
  private readonly focus = computed(
    () => {
      const chosen = this.boxes().filter((box) => box.chosen);
      return chosen.length === 1 ? (chosen[0] ?? null) : null;
    },
    { equal: (one, other) => one?.key === other?.key },
  );
  // Compared by value: a live reload of the same floor hands new rows, and must not take the person back to the corner.
  private readonly extent = computed(
    () => volumeExtent(this.size(), this.drawings(), this.structures()),
    {
      equal: (one, other) =>
        one.x === other.x &&
        one.y === other.y &&
        one.width === other.width &&
        one.depth === other.depth,
    },
  );
  /** The colours the theme gives this accent and scheme, worked out as the theme works them out. */
  private readonly palette = computed(() => {
    const scheme = this.theme.scheme();
    return { ...colourTokens(this.theme.accent(), scheme), ...statusTokens(scheme) };
  });

  private renderer: WebGLRenderer | null = null;
  private controls: OrbitControls | null = null;
  private readonly scene = new Scene();
  private readonly camera = new PerspectiveCamera(CAMERA_FOV, 1, 0.1, 2000);
  private readonly content = new Group();
  // One unit box, scaled per place: the geometry is shared, and only the materials are made per paint.
  private readonly unit = new BoxGeometry(1, 1, 1);
  private readonly unitEdges = new EdgesGeometry(this.unit);
  private materials: { dispose(): void }[] = [];
  private frame = 0;
  private readonly stops: (() => void)[] = [];

  constructor() {
    // Lights are three.js's own white: the colours are the theme's, the light only gives the boxes their sides.
    this.scene.add(new AmbientLight(undefined, 1.6), this.content);
    const sun = new DirectionalLight(undefined, 1.4);
    sun.position.set(-0.5, 1, 0.8);
    this.scene.add(sun);

    afterNextRender(() => this.start());

    effect(() => {
      const boxes = this.boxes();
      const palette = this.palette();
      if (this.webgl() !== 'ready') return;
      this.paint(boxes, palette);
    });

    // A floor chosen anew is looked at from its own corner; toggling what is shown keeps where the person stands.
    effect(() => {
      const extent = this.extent();
      if (this.webgl() !== 'ready') return;
      this.look(this.framed('overview', extent));
    });

    // After the floor's own framing, so a place chosen on the plan is the one looked at on opening the 3D.
    effect(() => {
      const focus = this.focus();
      if (focus === null || this.webgl() !== 'ready') return;
      untracked(() => this.look(lookingAt(this.current(), focus)));
    });

    inject(DestroyRef).onDestroy(() => this.stop());
  }

  protected toggle(name: keyof VolumeToggles): void {
    this.toggles.update((now) => ({ ...now, [name]: !now[name] }));
  }

  protected turn(direction: 1 | -1): void {
    this.look(turned(this.current(), direction * TURN_STEP));
  }

  protected approach(nearer: boolean): void {
    this.look(moved(this.current(), nearer ? NEARER : 1 / NEARER));
  }

  protected preset(name: CameraPresetName): void {
    this.look(this.framed(name, this.extent()));
  }

  private start(): void {
    const canvas = this.canvas().nativeElement;
    // three.js draws on WebGL 2 alone; a browser or a machine without it is told so, and the plan stays one press away.
    const context = canvas.getContext('webgl2', { antialias: true });
    if (!context) {
      this.webgl.set('missing');
      return;
    }
    const renderer = new WebGLRenderer({ canvas, context });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
    const controls = new OrbitControls(this.camera, canvas);
    // Never under the ground: below it there is nothing to read, and a turned-over floor is easy to get lost in.
    controls.maxPolarAngle = Math.PI / 2 - 0.05;
    controls.listenToKeyEvents(canvas);
    controls.addEventListener('change', () => this.render());
    this.listenForPicks(canvas);
    this.renderer = renderer;
    this.controls = controls;

    const resize = new ResizeObserver(() => this.fit());
    resize.observe(canvas);
    this.stops.push(() => resize.disconnect());
    this.fit();
    this.webgl.set('ready');
  }

  private stop(): void {
    cancelAnimationFrame(this.frame);
    for (const stop of this.stops) stop();
    this.clear();
    this.unit.dispose();
    this.unitEdges.dispose();
    this.controls?.dispose();
    // Each switch to 3D opens a context; a browser keeps only a few, so this one is handed back rather than left for
    // the collector, or toggling the view a dozen times would leave the next one blank.
    this.renderer?.dispose();
    this.renderer?.forceContextLoss();
  }

  private fit(): void {
    const canvas = this.canvas().nativeElement;
    const width = canvas.clientWidth;
    const height = canvas.clientHeight;
    if (this.renderer === null || width === 0 || height === 0) return;
    this.renderer.setSize(width, height, false);
    this.camera.aspect = width / height;
    this.camera.updateProjectionMatrix();
    this.render();
  }

  private paint(
    boxes: ReturnType<typeof volumeBoxes>,
    palette: Readonly<Record<VolumeToken, string>>,
  ): void {
    this.clear();
    for (const box of boxes) {
      const tones = tonesOf(box);
      const seeThrough = tones.opacity < 1;
      const fill = new MeshLambertMaterial({
        color: new Color(palette[tones.fill]),
        transparent: seeThrough,
        opacity: tones.opacity,
        depthWrite: !seeThrough,
      });
      // The edges stay whole on a see-through wall: its line on the floor is what says where the building stops.
      const edge = new LineBasicMaterial({
        color: new Color(palette[tones.edge]),
        transparent: box.dimmed,
        opacity: box.dimmed ? tones.opacity : 1,
      });
      this.materials.push(fill, edge);
      const solid = new Mesh(this.unit, fill);
      if (box.key.startsWith('drawing-'))
        solid.userData['drawingId'] = box.key.slice('drawing-'.length);
      const lines = new LineSegments(this.unitEdges, edge);
      for (const part of [solid, lines]) {
        part.scale.set(Math.max(box.width, 0.01), box.height, Math.max(box.depth, 0.01));
        part.position.set(box.x, box.base + box.height / 2, box.y);
        part.rotation.y = box.turn;
        this.content.add(part);
      }
      if (box.chosen) this.paintHalo(box, palette);
    }
    this.render();
  }

  private paintHalo(
    box: ReturnType<typeof volumeBoxes>[number],
    palette: Readonly<Record<VolumeToken, string>>,
  ): void {
    const halo = haloOf(box);
    // Never written to depth: what stands behind the ring still shows through it.
    const glow = new MeshLambertMaterial({
      color: new Color(palette[CHOSEN_HALO.tone]),
      transparent: true,
      opacity: CHOSEN_HALO.opacity,
      depthWrite: false,
    });
    this.materials.push(glow);
    const ring = new Mesh(this.unit, glow);
    ring.scale.set(halo.width, halo.height, halo.depth);
    ring.position.set(halo.x, halo.base + halo.height / 2, halo.y);
    ring.rotation.y = halo.turn;
    this.content.add(ring);
  }

  /** A click on a rack or a zone chooses it; a press that moved turned the view instead. */
  private listenForPicks(canvas: HTMLCanvasElement): void {
    let down: { x: number; y: number } | null = null;
    const press = (event: PointerEvent): void => {
      down = { x: event.clientX, y: event.clientY };
    };
    const release = (event: PointerEvent): void => {
      const from = down;
      down = null;
      if (
        from === null ||
        Math.hypot(event.clientX - from.x, event.clientY - from.y) > CLICK_SLOP
      ) {
        return;
      }
      const id = this.drawingAt(canvas, event.clientX, event.clientY);
      if (id !== null) this.pick.emit(id);
    };
    canvas.addEventListener('pointerdown', press);
    canvas.addEventListener('pointerup', release);
    this.stops.push(() => {
      canvas.removeEventListener('pointerdown', press);
      canvas.removeEventListener('pointerup', release);
    });
  }

  /** The nearest drawn place under a point of the canvas, past any halo or wall in front of it. */
  private drawingAt(canvas: HTMLCanvasElement, clientX: number, clientY: number): string | null {
    const bounds = canvas.getBoundingClientRect();
    const pointer = new Vector2(
      ((clientX - bounds.left) / bounds.width) * 2 - 1,
      -((clientY - bounds.top) / bounds.height) * 2 + 1,
    );
    const ray = new Raycaster();
    ray.setFromCamera(pointer, this.camera);
    for (const hit of ray.intersectObjects(this.content.children, false)) {
      const id: unknown = hit.object.userData['drawingId'];
      if (typeof id === 'string') return id;
    }

    return null;
  }

  private clear(): void {
    this.content.clear();
    for (const material of this.materials) material.dispose();
    this.materials = [];
  }

  /** A preset for this floor as the picture is shaped now, its tallest piece included. */
  private framed(name: CameraPresetName, extent: ReturnType<typeof volumeExtent>): CameraView {
    const tallest = Math.max(0, ...untracked(this.boxes).map((box) => box.base + box.height));
    return cameraPreset(name, extent, this.camera.aspect, tallest);
  }

  private current(): CameraView {
    const target = this.controls?.target;
    return {
      position: [this.camera.position.x, this.camera.position.y, this.camera.position.z],
      target: target === undefined ? [0, 0, 0] : [target.x, target.y, target.z],
    };
  }

  private look(view: CameraView): void {
    if (this.controls === null) return;
    this.camera.position.set(...view.position);
    this.controls.target.set(...view.target);
    this.controls.update();
    this.render();
  }

  /** At most one frame per display refresh, and only when something asked for one. */
  private render(): void {
    if (this.renderer === null || this.frame !== 0) return;
    this.frame = requestAnimationFrame(() => {
      this.frame = 0;
      this.renderer?.render(this.scene, this.camera);
    });
  }
}
