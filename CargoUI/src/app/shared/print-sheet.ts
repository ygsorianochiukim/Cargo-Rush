import { NgTemplateOutlet } from '@angular/common';
import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  computed,
  input,
  output,
  viewChild,
} from '@angular/core';

import { PrintElement, PrintLayout, PrintPage } from '../models/printing/printable.model';
import { fillText } from './print-fill';

export interface ElementMove {
  id: string;
  x: number;
  y: number;
  w: number | null;
}

type Drag =
  | {
      kind: 'move';
      id: string;
      startX: number;
      startY: number;
      x: number;
      y: number;
      w: number | null;
    }
  | { kind: 'width'; id: string; startX: number; x: number; y: number; w: number }
  | { kind: 'zone'; zone: 'top' | 'bottom'; startY: number; height: number };

@Component({
  selector: 'app-print-sheet',
  changeDetection: ChangeDetectionStrategy.OnPush,
  styles: `
    :host {
      display: block;
    }
    .page {
      background: #fff;
      color: #1b2430;
      font-family: 'DejaVu Sans', 'Segoe UI', sans-serif;
      box-shadow: 0 1px 3px rgb(0 0 0 / 0.15);
      position: relative;
    }
    .zone {
      position: relative;
    }
    .editable .zone {
      outline: 1px dashed #b8c4d2;
    }
    .el {
      position: absolute;
      white-space: pre-wrap;
      overflow-wrap: anywhere;
    }
    .editable .el {
      cursor: move;
    }
    .editable .el:hover {
      outline: 1px dashed #15589c;
    }
    .el.selected {
      outline: 1.5px solid #15589c !important;
      z-index: 2;
    }
    .grip {
      position: absolute;
      top: 0;
      right: -3px;
      width: 6px;
      height: 100%;
      cursor: ew-resize;
      background: #15589c;
      opacity: 0.6;
    }
    .zone-grip {
      position: absolute;
      left: 0;
      right: 0;
      bottom: -3px;
      height: 6px;
      cursor: ns-resize;
      background: repeating-linear-gradient(90deg, #15589c 0 6px, transparent 6px 10px);
      opacity: 0.5;
    }
    .zone-name {
      position: absolute;
      right: 0;
      top: -14px;
      font-size: 9px;
      color: #6b7a8c;
      font-family: system-ui, sans-serif;
    }
    table {
      width: 100%;
      border-collapse: collapse;
    }
    th {
      font-weight: normal;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: #6b7a8c;
      padding: 1.4mm 1.5mm 1.4mm 0;
    }
    td {
      border-bottom: 0.2mm solid #eef1f4;
      padding: 1.6mm 1.5mm 1.6mm 0;
      vertical-align: top;
      white-space: pre-wrap;
    }
    tr.foot td {
      border-bottom: 0;
      border-top: 0.3mm solid #dfe4ea;
      font-weight: bold;
    }
    tr.striped td {
      background: #f6f8fa;
    }
    .flow {
      position: relative;
    }
    .editable .flow {
      cursor: pointer;
    }
    .editable .flow:hover {
      outline: 1px dashed #15589c;
    }
    .flow.selected {
      outline: 1.5px solid #15589c;
    }
  `,
  template: `
    @let l = layout();
    @let p = page();

    <div
      #pageEl
      class="page"
      [class.editable]="editable()"
      [style.width.mm]="pageWidth()"
      [style.min-height.mm]="pageHeight()"
      [style.padding.mm]="l.margin"
      (pointermove)="drag($event)"
      (pointerup)="drop($event)"
      (pointercancel)="drop($event)"
    >
      <div
        class="zone"
        [style.height.mm]="l.top_height"
        (pointerdown)="editable() && pick.emit(null)"
      >
        @if (editable()) {
          <span class="zone-name">Top area · {{ l.top_height }} mm</span>
        }
        @for (e of top(); track e.id) {
          <ng-container *ngTemplateOutlet="item; context: { $implicit: e }" />
        }
        @if (editable()) {
          <div class="zone-grip" (pointerdown)="startZone($event, 'top')"></div>
        }
      </div>

      @for (e of flow(); track e.id) {
        @let t = p.tables[e.table ?? ''];
        <div
          class="flow"
          [class.selected]="selected() === e.id"
          [style.margin-top.mm]="e.space_before ?? 4"
          [style.font-size.pt]="e.size ?? 8.5"
          [style.opacity]="e.hidden ? 0.35 : 1"
          (pointerdown)="editable() && selectFlow($event, e.id)"
        >
          @if (e.title && fill(e.title)) {
            <div
              style="text-transform: uppercase; letter-spacing: 0.05em; color: #6b7a8c; margin-bottom: 1.5mm"
              [style.font-size.pt]="(e.size ?? 8.5) - 1"
            >
              {{ fill(e.title) }}
            </div>
          }
          <table>
            <thead>
              <tr>
                @for (c of visibleColumns(e); track c.key) {
                  <th
                    [style.text-align]="c.align"
                    [style.font-size.pt]="(e.size ?? 8.5) - 1.5"
                    [style.border-bottom]="'0.4mm solid ' + (e.color ?? '#15589c')"
                  >
                    {{ c.label }}
                  </th>
                }
              </tr>
            </thead>
            <tbody>
              @if (!t || (t.rows.length === 0 && !t.foot?.length)) {
                <tr>
                  <td
                    [attr.colspan]="visibleColumns(e).length"
                    style="color: #6b7a8c; font-style: italic"
                  >
                    No rows in the sample — this table is skipped when empty.
                  </td>
                </tr>
              } @else {
                @for (row of t.rows.slice(0, maxRows()); track $index; let i = $index) {
                  <tr [class.striped]="e.striped && i % 2 === 1">
                    @for (c of visibleColumns(e); track c.key) {
                      <td [style.text-align]="c.align">{{ row[c.key] || '' }}</td>
                    }
                  </tr>
                }
                @for (row of t.foot ?? []; track $index) {
                  <tr class="foot">
                    @for (c of visibleColumns(e); track c.key) {
                      <td [style.text-align]="c.align">{{ row[c.key] || '' }}</td>
                    }
                  </tr>
                }
              }
            </tbody>
          </table>
        </div>
      }

      <div
        class="zone"
        style="margin-top: 3mm"
        [style.height.mm]="l.bottom_height"
        (pointerdown)="editable() && pick.emit(null)"
      >
        @if (editable()) {
          <span class="zone-name">Bottom area · follows the tables · {{ l.bottom_height }} mm</span>
        }
        @for (e of bottom(); track e.id) {
          <ng-container *ngTemplateOutlet="item; context: { $implicit: e }" />
        }
        @if (editable()) {
          <div class="zone-grip" (pointerdown)="startZone($event, 'bottom')"></div>
        }
      </div>
    </div>

    <ng-template #item let-e>
      @if (e.type === 'text') {
        <div
          class="el"
          [class.selected]="selected() === e.id"
          [style.left.mm]="e.x"
          [style.top.mm]="e.y"
          [style.width.mm]="e.w"
          [style.font-size.pt]="e.size"
          [style.line-height]="e.line_height ?? 1.35"
          [style.color]="e.color"
          [style.text-align]="e.align"
          [style.font-weight]="e.bold ? 700 : 400"
          [style.font-style]="e.italic ? 'italic' : 'normal'"
          [style.text-transform]="e.upper ? 'uppercase' : 'none'"
          [style.letter-spacing]="e.upper ? '0.06em' : 'normal'"
          [style.opacity]="e.hidden ? 0.35 : 1"
          [style.min-height.mm]="editable() ? 3 : null"
          (pointerdown)="startMove($event, e)"
        >
          {{ editable() && !fill(e.text ?? '') ? e.label || 'Empty text' : fill(e.text ?? '') }}
          @if (editable() && selected() === e.id && e.w) {
            <span class="grip" (pointerdown)="startWidth($event, e)"></span>
          }
        </div>
      } @else if (e.type === 'image') {
        <div
          class="el"
          [class.selected]="selected() === e.id"
          [style.left.mm]="e.x"
          [style.top.mm]="e.y"
          [style.width.mm]="e.w ?? 15"
          [style.height.mm]="e.h ?? 15"
          [style.opacity]="e.hidden ? 0.35 : 1"
          (pointerdown)="startMove($event, e)"
        >
          @if (images()[e.source ?? 'logo']; as url) {
            <img
              [src]="url"
              alt=""
              draggable="false"
              style="max-width: 100%; max-height: 100%; object-fit: contain; pointer-events: none"
            />
          } @else if (editable()) {
            <div
              style="width: 100%; height: 100%; border: 1px dashed #b8c4d2; font: 8px system-ui; color: #6b7a8c; display: flex; align-items: center; justify-content: center; text-align: center"
            >
              {{ e.source === 'logo' ? 'Company logo (none uploaded)' : 'Image' }}
            </div>
          }
          @if (editable() && selected() === e.id) {
            <span class="grip" (pointerdown)="startWidth($event, e)"></span>
          }
        </div>
      } @else if (e.type === 'line') {
        <div
          class="el"
          [class.selected]="selected() === e.id"
          [style.left.mm]="e.x"
          [style.top.mm]="e.y"
          [style.width.mm]="e.w ?? 50"
          [style.height.mm]="editable() ? 2 : 0"
          [style.border-top]="(e.thickness ?? 0.3) + 'mm solid ' + (e.color ?? '#dfe4ea')"
          [style.opacity]="e.hidden ? 0.35 : 1"
          (pointerdown)="startMove($event, e)"
        >
          @if (editable() && selected() === e.id) {
            <span class="grip" (pointerdown)="startWidth($event, e)"></span>
          }
        </div>
      } @else if (e.type === 'box') {
        <div
          class="el"
          [class.selected]="selected() === e.id"
          [style.left.mm]="e.x"
          [style.top.mm]="e.y"
          [style.width.mm]="e.w ?? 40"
          [style.height.mm]="e.h ?? 10"
          [style.border]="(e.thickness ?? 0.3) + 'mm solid ' + (e.color ?? '#dfe4ea')"
          [style.background]="e.fill ?? 'transparent'"
          [style.opacity]="e.hidden ? 0.35 : 1"
          (pointerdown)="startMove($event, e)"
        >
          @if (editable() && selected() === e.id) {
            <span class="grip" (pointerdown)="startWidth($event, e)"></span>
          }
        </div>
      }
    </ng-template>
  `,
  imports: [NgTemplateOutlet],
})
export class PrintSheet {
  readonly layout = input.required<PrintLayout>();
  readonly page = input.required<PrintPage>();
  readonly orientation = input<'portrait' | 'landscape'>('portrait');
  readonly images = input<Record<string, string | null>>({});
  readonly editable = input(false);
  readonly selected = input<string | null>(null);
  readonly maxRows = input(12);

  readonly pick = output<string | null>();
  readonly moved = output<ElementMove>();
  readonly zoneResized = output<{ zone: 'top' | 'bottom'; height: number }>();

  private readonly pageEl = viewChild.required<ElementRef<HTMLElement>>('pageEl');

  private dragging: Drag | null = null;

  protected readonly pageWidth = computed(() => (this.orientation() === 'landscape' ? 297 : 210));
  protected readonly pageHeight = computed(() => (this.orientation() === 'landscape' ? 210 : 297));
  protected readonly contentWidth = computed(() => this.pageWidth() - 2 * this.layout().margin);

  private readonly shown = computed(() =>
    this.layout().elements.filter((e) => this.editable() || !e.hidden),
  );

  protected readonly top = computed(() => this.shown().filter((e) => e.zone === 'top'));
  protected readonly bottom = computed(() => this.shown().filter((e) => e.zone === 'bottom'));
  protected readonly flow = computed(() =>
    this.shown()
      .filter((e) => e.zone === 'flow')
      .sort((a, b) => a.y - b.y),
  );

  protected fill(text: string): string {
    return fillText(text, this.page().values);
  }

  protected visibleColumns(e: PrintElement) {
    return (e.columns ?? []).filter((c) => !c.hidden);
  }

  protected selectFlow(event: PointerEvent, id: string): void {
    event.stopPropagation();
    this.pick.emit(id);
  }

  protected startMove(event: PointerEvent, e: PrintElement): void {
    if (!this.editable()) return;

    event.stopPropagation();
    event.preventDefault();
    this.pick.emit(e.id);
    this.capture(event);
    this.dragging = {
      kind: 'move',
      id: e.id,
      startX: event.clientX,
      startY: event.clientY,
      x: e.x,
      y: e.y,
      w: e.w,
    };
  }

  protected startWidth(event: PointerEvent, e: PrintElement): void {
    event.stopPropagation();
    event.preventDefault();
    this.capture(event);
    this.dragging = {
      kind: 'width',
      id: e.id,
      startX: event.clientX,
      x: e.x,
      y: e.y,
      w: e.w ?? 15,
    };
  }

  protected startZone(event: PointerEvent, zone: 'top' | 'bottom'): void {
    event.stopPropagation();
    event.preventDefault();
    this.capture(event);

    const l = this.layout();
    this.dragging = {
      kind: 'zone',
      zone,
      startY: event.clientY,
      height: zone === 'top' ? l.top_height : l.bottom_height,
    };
  }

  protected drag(event: PointerEvent): void {
    const d = this.dragging;
    if (!d) return;

    const mm = this.pxPerMm();
    const snap = (v: number) => Math.round(v * 2) / 2;

    if (d.kind === 'move') {
      const maxX = Math.max(0, this.contentWidth() - (d.w ?? 5));
      const x = Math.min(maxX, Math.max(0, snap(d.x + (event.clientX - d.startX) / mm)));
      const y = Math.min(400, Math.max(0, snap(d.y + (event.clientY - d.startY) / mm)));
      this.moved.emit({ id: d.id, x, y, w: d.w });
    } else if (d.kind === 'width') {
      const w = Math.min(
        this.contentWidth() - d.x,
        Math.max(2, snap(d.w + (event.clientX - d.startX) / mm)),
      );
      this.moved.emit({ id: d.id, x: d.x, y: d.y, w });
    } else {
      const height = Math.min(280, Math.max(0, snap(d.height + (event.clientY - d.startY) / mm)));
      this.zoneResized.emit({ zone: d.zone, height });
    }
  }

  protected drop(event: PointerEvent): void {
    if (!this.dragging) return;

    this.dragging = null;
    (event.target as Element | null)?.releasePointerCapture?.(event.pointerId);
  }

  private capture(event: PointerEvent): void {
    this.pageEl().nativeElement.setPointerCapture(event.pointerId);
  }

  private pxPerMm(): number {
    return this.pageEl().nativeElement.getBoundingClientRect().width / this.pageWidth();
  }
}
