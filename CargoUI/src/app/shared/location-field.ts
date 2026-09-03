import {
  ChangeDetectionStrategy,
  Component,
  booleanAttribute,
  computed,
  input,
  output,
  signal,
} from '@angular/core';

import { GeoPoint, TripLocation } from '../models/geo/geo.model';
import { Field } from './field';
import { Icon } from './icon';
import { MapPicker } from './map-picker';
import { Modal } from './modal';

/**
 * A place on a form: type the name, or pin it on a map.
 *
 * Typing is kept as the primary path deliberately. Most trips are booked over
 * the phone against a town somebody already knows, and making them open a map
 * to write "Ozamis" would be slower than the spreadsheet this replaces. The
 * map is there for the cases typing cannot answer — a depot gate with no
 * address, or two towns of the same name.
 *
 * Pinning fills the name in as well, so the two never drift apart. Editing the
 * name afterwards keeps the pin: renaming "Poblacion" to "Ozamis depot" is
 * labelling the same point, not moving it.
 */
@Component({
  selector: 'app-location-field',
  changeDetection: ChangeDetectionStrategy.OnPush,
  // `MapPicker` is used only inside the `@defer` block below, which is what
  // lets Angular turn this static import into a dynamic one and keep Leaflet
  // out of the initial bundle.
  imports: [Field, Icon, MapPicker, Modal],
  template: `
    <app-field [label]="label()" [required]="required()" [error]="error()" [hint]="hint()">
      <span class="flex gap-2">
        <input
          type="text"
          [value]="value().place"
          (input)="onName($any($event.target).value)"
          (blur)="touched.emit()"
          [placeholder]="placeholder()"
          class="h-10 min-w-0 flex-1 rounded-control border border-cr-line bg-cr-surface px-3 text-[14px] text-cr-ink placeholder:text-cr-ink-muted focus:border-cr-blue focus:outline-none" />

        <button
          type="button"
          class="flex h-10 flex-none items-center gap-1.5 rounded-control border px-3 text-[13px] font-semibold transition-colors"
          [class.border-cr-blue]="pinned()"
          [class.text-cr-blue]="pinned()"
          [class.border-cr-line]="!pinned()"
          [class.text-cr-ink-muted]="!pinned()"
          [attr.aria-label]="(pinned() ? 'Change the map pin for ' : 'Pin ') + label()"
          (click)="open.set(true)">
          <app-icon name="map-pin" [size]="15" />
          {{ pinned() ? 'Pinned' : 'Map' }}
        </button>
      </span>

      @if (pinned()) {
        <span class="cr-num mt-1 block text-[11px] text-cr-ink-muted">
          {{ value().lat!.toFixed(5) }}, {{ value().lng!.toFixed(5) }}
        </span>
      }
    </app-field>

    <app-modal
      [(open)]="open"
      [title]="'Pin ' + label().toLowerCase()"
      subtitle="Search for the place, or click the map to drop a pin."
      icon="map-pin"
      size="lg">
      <!-- Deferred, not just hidden. Leaflet is 64kB and most trips are
           booked without opening a map, so it has no business in the chunk
           every page load pays for. Building only while open also matters
           in its own right: Leaflet measures its container on creation,
           and a hidden one measures zero. -->
      @defer (when open()) {
        <app-map-picker [value]="asPoint()" (changed)="onPinned($event)" />
      } @placeholder {
        <div class="flex h-[360px] items-center justify-center rounded-card bg-cr-tint">
          <p class="text-[13px] text-cr-ink-muted">Loading the map…</p>
        </div>
      }

      <ng-container modal-footer>
        <button
          type="button"
          class="h-10 rounded-control bg-cr-blue px-4 text-[14px] font-semibold text-cr-surface transition-colors hover:bg-cr-blue-hover"
          (click)="open.set(false)">
          Done
        </button>
      </ng-container>
    </app-modal>
  `,
})
export class LocationField {
  readonly label = input.required<string>();
  readonly value = input.required<TripLocation>();
  /** Transformed so a bare `required` attribute works, as on `app-field`. */
  readonly required = input(false, { transform: booleanAttribute });
  readonly error = input<string | null>(null);
  readonly hint = input('');
  readonly placeholder = input('');

  readonly changed = output<TripLocation>();
  readonly touched = output<void>();

  protected readonly open = signal(false);

  protected readonly pinned = computed(
    () => this.value().lat !== null && this.value().lng !== null,
  );

  /** The map wants a whole point or nothing; a name with no pin is nothing. */
  protected readonly asPoint = computed<GeoPoint | null>(() => {
    const location = this.value();

    return location.lat !== null && location.lng !== null
      ? { place: location.place, lat: location.lat, lng: location.lng }
      : null;
  });

  protected onName(place: string): void {
    // Coordinates are kept: renaming a pinned place is labelling the point,
    // not moving it.
    this.changed.emit({ ...this.value(), place });
  }

  protected onPinned(point: GeoPoint | null): void {
    if (point === null) {
      this.changed.emit({ place: this.value().place, lat: null, lng: null });

      return;
    }

    // The looked-up name fills an empty field, but never overwrites one
    // somebody has already written — "Ozamis depot" beats "Poblacion".
    const place = this.value().place.trim() || point.place;

    this.changed.emit({ place, lat: point.lat, lng: point.lng });
  }
}
