import {
  AfterViewInit,
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  ElementRef,
  OnDestroy,
  inject,
  input,
  output,
  signal,
  viewChild,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { Subject, debounceTime, distinctUntilChanged, switchMap } from 'rxjs';
import * as L from 'leaflet';

import { GeoPoint, GeoResult } from '../models/geo/geo.model';
import { GeoService } from '../services/geo/geo.service';
import { Icon } from './icon';

const DEFAULT_CENTRE: L.LatLngTuple = [8.4856, 124.5808];

L.Icon.Default.imagePath = 'leaflet/';

@Component({
  selector: 'app-map-picker',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Icon],
  template: `
    <div class="flex flex-col gap-3">
      <label class="relative flex items-center">
        <span class="sr-only">Search for a place</span>
        <app-icon
          name="search"
          [size]="16"
          class="pointer-events-none absolute left-3 text-cr-ink-muted" />
        <input
          type="search"
          [value]="term()"
          (input)="onSearch($any($event.target).value)"
          (keydown.enter)="$event.preventDefault()"
          placeholder="Search a town, depot or landmark…"
          class="h-10 w-full rounded-control border border-cr-line bg-cr-surface pr-3 pl-9 text-[14px] placeholder:text-cr-ink-muted focus:border-cr-blue focus:outline-none" />
      </label>

      @if (results().length > 0) {
        <ul class="max-h-40 overflow-y-auto rounded-control border border-cr-line">
          @for (result of results(); track result.id) {
            <li>
              <button
                type="button"
                class="flex w-full flex-col items-start gap-0.5 border-b border-cr-line/70 px-3 py-2 text-left transition-colors last:border-0 hover:bg-cr-tint"
                (click)="choose(result)">
                <span class="text-[13px] font-semibold">{{ result.place }}</span>
                @if (result.detail) {
                  <span class="text-[12px] text-cr-ink-muted">{{ result.detail }}</span>
                }
              </button>
            </li>
          }
        </ul>
      } @else if (searching()) {
        <p class="text-[12px] text-cr-ink-muted">Searching…</p>
      }

      <div
        #canvas
        class="h-[280px] w-full overflow-hidden rounded-card border border-cr-line"
        role="application"
        aria-label="Map. Click to place the pin, or use the search box above."></div>

      <div class="flex items-center gap-2">
        <app-icon name="map-pin" [size]="14" class="flex-none text-cr-blue" />
        @if (picked(); as point) {
          <p class="min-w-0 flex-1 truncate text-[13px]">
            @if (point.place) {
              <span class="font-semibold">{{ point.place }}</span>
            } @else if (resolving()) {
              <span class="text-cr-ink-muted italic">Looking up the place name…</span>
            } @else {
              <span class="text-cr-ink-muted">Unnamed point</span>
            }
            <span class="cr-num ml-2 text-cr-ink-muted">
              {{ point.lat.toFixed(5) }}, {{ point.lng.toFixed(5) }}
            </span>
          </p>
          <button
            type="button"
            class="flex-none rounded-control px-2 py-1 text-[12px] font-semibold text-cr-red transition-colors hover:bg-cr-red-bg"
            (click)="clear()">
            Clear
          </button>
        } @else {
          <p class="flex-1 text-[13px] text-cr-ink-muted">
            Nothing pinned yet — search above, or click the map.
          </p>
        }
      </div>
    </div>
  `,
})
export class MapPicker implements AfterViewInit, OnDestroy {
  private readonly geo = inject(GeoService);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly resolving = signal(false);

  readonly value = input<GeoPoint | null>(null);

  readonly changed = output<GeoPoint | null>();

  private readonly canvas = viewChild.required<ElementRef<HTMLElement>>('canvas');

  protected readonly term = signal('');
  protected readonly results = signal<GeoResult[]>([]);
  protected readonly searching = signal(false);
  protected readonly picked = signal<GeoPoint | null>(null);

  private map: L.Map | null = null;
  private marker: L.Marker | null = null;

  private readonly terms = new Subject<string>();

  constructor() {
    this.terms
      .pipe(
        debounceTime(450),
        distinctUntilChanged(),
        switchMap((term) => {
          this.searching.set(term.trim().length >= 3);

          return this.geo.search(term);
        }),
        takeUntilDestroyed(),
      )
      .subscribe((results) => {
        this.results.set(results);
        this.searching.set(false);
      });
  }

  ngAfterViewInit(): void {
    const existing = this.value();
    this.picked.set(existing);

    this.map = L.map(this.canvas().nativeElement, {
      center: existing ? [existing.lat, existing.lng] : DEFAULT_CENTRE,
      zoom: 13,
      scrollWheelZoom: false,
    });

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '© OpenStreetMap contributors',
    }).addTo(this.map);

    if (existing) this.placeMarker(existing.lat, existing.lng);

    this.map.on('click', (event: L.LeafletMouseEvent) => {
      this.dropPin(event.latlng.lat, event.latlng.lng);
    });

    setTimeout(() => this.map?.invalidateSize(), 60);
  }

  ngOnDestroy(): void {
    this.map?.remove();
    this.map = null;
  }

  protected onSearch(term: string): void {
    this.term.set(term);
    this.terms.next(term);

    if (term.trim().length < 3) this.results.set([]);
  }

  protected choose(result: GeoResult): void {
    this.results.set([]);
    this.term.set(result.place);

    this.map?.setView([result.lat, result.lng], 14);
    this.placeMarker(result.lat, result.lng);
    this.commit({ place: result.place, lat: result.lat, lng: result.lng });
  }

  protected clear(): void {
    this.picked.set(null);
    this.term.set('');

    if (this.marker) {
      this.marker.remove();
      this.marker = null;
    }

    this.changed.emit(null);
  }

  private dropPin(lat: number, lng: number): void {
    this.placeMarker(lat, lng);

    const known = this.picked()?.place.trim() ?? '';

    this.commit({ place: known, lat, lng });
    this.resolving.set(known === '');

    this.geo
      .reverse(lat, lng)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe((point) => {
        this.resolving.set(false);
        this.commit({
          place: known || point?.place || this.coordinateLabel(lat, lng),
          lat,
          lng,
        });
      });
  }

  private coordinateLabel(lat: number, lng: number): string {
    return `${lat.toFixed(5)}, ${lng.toFixed(5)}`;
  }

  private placeMarker(lat: number, lng: number): void {
    if (this.map === null) return;

    if (this.marker === null) {
      this.marker = L.marker([lat, lng], { draggable: true }).addTo(this.map);

      this.marker.on('dragend', () => {
        const position = this.marker?.getLatLng();
        if (!position) return;

        const known = this.picked()?.place.trim() ?? '';

        if (known === '') {
          this.dropPin(position.lat, position.lng);

          return;
        }

        this.commit({ place: known, lat: position.lat, lng: position.lng });
      });

      return;
    }

    this.marker.setLatLng([lat, lng]);
  }

  private commit(point: GeoPoint): void {
    this.picked.set(point);
    this.changed.emit(point);
  }
}
