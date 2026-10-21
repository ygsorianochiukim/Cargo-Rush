import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';

import {
  Align,
  PrintElement,
  PrintLayout,
  PrintableEditor,
  PrintableSummary,
} from '../../models/printing/printable.model';
import { PrintableService } from '../../services/printing/printable.service';
import { Card } from '../../shared/card';
import { Confirm } from '../../shared/confirm';
import { ElementMove, PrintSheet } from '../../shared/print-sheet';

const INPUT =
  'h-8 w-full rounded-control border border-cr-line bg-cr-surface px-2 text-[13px] focus:border-cr-blue focus:outline-none';

function clone<T>(value: T): T {
  return JSON.parse(JSON.stringify(value)) as T;
}

@Component({
  selector: 'app-printables',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Card, PrintSheet],
  template: `
    <div class="grid gap-4 xl:grid-cols-[220px_minmax(0,1fr)_300px]">
      <app-card heading="Printables" icon="billing" [padded]="false">
        @if (list(); as items) {
          <nav class="flex flex-col py-1" aria-label="Choose a printable">
            @for (group of groups(); track group.name) {
              <p class="cr-meta px-4 pt-3 pb-1">{{ group.name }}</p>
              @for (item of group.items; track item.key) {
                <button
                  type="button"
                  class="flex items-center justify-between gap-2 px-4 py-2 text-left text-[13px] transition-colors hover:bg-cr-tint"
                  [class]="
                    item.key === current()?.key ? 'bg-cr-tint font-semibold text-cr-blue' : ''
                  "
                  (click)="open(item.key)"
                >
                  {{ item.name }}
                  @if (item.customised) {
                    <span
                      class="rounded-full bg-cr-tint px-1.5 text-[10px] font-semibold text-cr-blue"
                      >edited</span
                    >
                  }
                </button>
              }
            }
          </nav>
        } @else {
          <p class="cr-meta p-4">Loading…</p>
        }
      </app-card>

      <div class="min-w-0">
        @if (current(); as c) {
          @let l = draft()!;
          <div class="mb-3 flex flex-wrap items-center gap-2">
            <h2 class="mr-auto text-[16px] font-semibold">
              {{ c.name }}
              @if (dirty()) {
                <span class="ml-1 text-[12px] font-normal text-cr-warning">· not saved yet</span>
              }
            </h2>
            <button type="button" [class]="tool" (click)="addText()">+ Text</button>
            <label [class]="tool + ' cursor-pointer'">
              + Image
              <input type="file" accept="image/*" class="sr-only" (change)="addImage($event)" />
            </label>
            <button type="button" [class]="tool" (click)="addLine()">+ Line</button>
            <button type="button" [class]="tool" (click)="addBox()">+ Box</button>
            <span class="mx-1 h-6 w-px bg-cr-line"></span>
            <button type="button" [class]="tool" title="Zoom out" (click)="zoomBy(-0.1)">−</button>
            <span class="cr-num w-10 text-center text-[12px]">{{ zoomPct() }}%</span>
            <button type="button" [class]="tool" title="Zoom in" (click)="zoomBy(0.1)">+</button>
          </div>

          <div
            class="overflow-auto rounded-card bg-cr-tint/50 p-6"
            style="max-height: calc(100vh - 220px)"
          >
            <div class="mx-auto w-fit" [style.zoom]="zoom()">
              <app-print-sheet
                [layout]="l"
                [page]="c.sample.pages[0]"
                [orientation]="c.orientation"
                [images]="images()"
                [editable]="true"
                [selected]="selectedId()"
                (pick)="selectedId.set($event)"
                (moved)="move($event)"
                (zoneResized)="setLayout(zone($event.zone), $event.height)"
              />
            </div>
          </div>

          <div class="mt-3 flex flex-wrap items-center gap-2">
            <button
              type="button"
              class="h-10 rounded-control bg-cr-blue px-4 text-[14px] font-semibold text-cr-surface hover:bg-cr-blue-hover disabled:opacity-60"
              [disabled]="busy() || !dirty()"
              (click)="save()"
            >
              {{ busy() ? 'Saving…' : 'Save layout' }}
            </button>
            <button
              type="button"
              class="h-10 rounded-control px-3 text-[14px] font-semibold hover:bg-cr-tint disabled:opacity-60"
              [disabled]="busy() || !dirty()"
              (click)="discard()"
            >
              Discard changes
            </button>
            <button
              type="button"
              class="h-10 rounded-control px-3 text-[14px] font-semibold text-cr-blue ring-1 ring-cr-line hover:bg-cr-tint disabled:opacity-60"
              [disabled]="busy()"
              (click)="preview()"
            >
              Preview PDF
            </button>
            <button
              type="button"
              class="ml-auto h-10 rounded-control px-3 text-[13px] font-semibold text-cr-red hover:bg-cr-red-bg disabled:opacity-60"
              [disabled]="busy()"
              (click)="reset()"
            >
              Reset to default
            </button>
          </div>
          @if (notice(); as message) {
            <p
              role="status"
              class="mt-2 text-[12px] font-medium"
              [class]="noticeIsError() ? 'text-cr-red' : 'text-cr-success'"
            >
              {{ message }}
            </p>
          }
        } @else if (failed()) {
          <p class="text-[13px] text-cr-red">Could not load that printable.</p>
        } @else {
          <p class="cr-meta">Choose a printable to edit.</p>
        }
      </div>

      @if (current(); as c) {
        @let l = draft()!;
        <app-card [heading]="selected()?.label || 'Page'" icon="tag">
          @if (selected(); as e) {
            <div class="flex flex-col gap-3 text-[13px]">
              <label class="flex flex-col gap-1">
                <span class="cr-meta">NAME IN THIS LIST</span>
                <input
                  [class]="input"
                  [value]="e.label"
                  (input)="patch({ label: $any($event.target).value })"
                />
              </label>

              @if (e.type !== 'table') {
                <div class="grid grid-cols-2 gap-2">
                  <label class="flex flex-col gap-1">
                    <span class="cr-meta">AREA</span>
                    <select
                      [class]="input"
                      [value]="e.zone"
                      (change)="patch({ zone: $any($event.target).value })"
                    >
                      <option value="top">Top</option>
                      <option value="bottom">Bottom (after tables)</option>
                    </select>
                  </label>
                  <label class="flex flex-col gap-1">
                    <span class="cr-meta">WIDTH (MM)</span>
                    <input
                      type="number"
                      step="0.5"
                      [class]="input"
                      [value]="e.w ?? ''"
                      (change)="patch({ w: num($any($event.target).value) })"
                    />
                  </label>
                  <label class="flex flex-col gap-1">
                    <span class="cr-meta">FROM LEFT (MM)</span>
                    <input
                      type="number"
                      step="0.5"
                      [class]="input"
                      [value]="e.x"
                      (change)="patch({ x: num($any($event.target).value) ?? 0 })"
                    />
                  </label>
                  <label class="flex flex-col gap-1">
                    <span class="cr-meta">FROM TOP (MM)</span>
                    <input
                      type="number"
                      step="0.5"
                      [class]="input"
                      [value]="e.y"
                      (change)="patch({ y: num($any($event.target).value) ?? 0 })"
                    />
                  </label>
                </div>
              }

              @if (e.type === 'text') {
                <label class="flex flex-col gap-1">
                  <span class="cr-meta">TEXT</span>
                  <textarea
                    #textBox
                    rows="4"
                    class="w-full rounded-control border border-cr-line bg-cr-surface px-2 py-1.5 font-mono text-[12px] focus:border-cr-blue focus:outline-none"
                    [value]="e.text ?? ''"
                    (input)="patch({ text: $any($event.target).value })"
                  ></textarea>
                </label>
                <label class="flex flex-col gap-1">
                  <span class="cr-meta">INSERT A FIELD</span>
                  <select [class]="input" (change)="insertField(textBox, $any($event.target))">
                    <option value="">Choose…</option>
                    @for (f of fieldList(); track f.key) {
                      <option [value]="f.key">{{ f.label }}</option>
                    }
                  </select>
                </label>
                <p class="text-[11px] text-cr-ink-muted">
                  A line whose fields are all empty is left out, so "{{ tinExample }}" disappears
                  for a company without one.
                </p>
                <div class="grid grid-cols-2 gap-2">
                  <label class="flex flex-col gap-1">
                    <span class="cr-meta">SIZE (PT)</span>
                    <input
                      type="number"
                      step="0.5"
                      min="5"
                      max="48"
                      [class]="input"
                      [value]="e.size"
                      (change)="patch({ size: num($any($event.target).value) ?? 9 })"
                    />
                  </label>
                  <label class="flex flex-col gap-1">
                    <span class="cr-meta">COLOUR</span>
                    <input
                      type="color"
                      class="h-8 w-full cursor-pointer rounded-control border border-cr-line"
                      [value]="e.color"
                      (input)="patch({ color: $any($event.target).value })"
                    />
                  </label>
                  <label class="flex flex-col gap-1">
                    <span class="cr-meta">LINE SPACING</span>
                    <input
                      type="number"
                      step="0.05"
                      min="0.8"
                      max="3"
                      [class]="input"
                      [value]="e.line_height"
                      (change)="patch({ line_height: num($any($event.target).value) ?? 1.35 })"
                    />
                  </label>
                  <div class="flex flex-col gap-1">
                    <span class="cr-meta">ALIGN</span>
                    <div class="flex rounded-control border border-cr-line p-0.5">
                      @for (a of aligns; track a) {
                        <button
                          type="button"
                          class="h-7 flex-1 rounded-control text-[12px] capitalize"
                          [class]="e.align === a ? 'bg-cr-tint font-semibold text-cr-blue' : ''"
                          (click)="patch({ align: a })"
                        >
                          {{ a }}
                        </button>
                      }
                    </div>
                  </div>
                </div>
                <div class="flex flex-wrap gap-3">
                  <label class="flex items-center gap-1.5"
                    ><input
                      type="checkbox"
                      [checked]="e.bold"
                      (change)="patch({ bold: $any($event.target).checked })"
                    />
                    Bold</label
                  >
                  <label class="flex items-center gap-1.5"
                    ><input
                      type="checkbox"
                      [checked]="e.italic"
                      (change)="patch({ italic: $any($event.target).checked })"
                    />
                    Italic</label
                  >
                  <label class="flex items-center gap-1.5"
                    ><input
                      type="checkbox"
                      [checked]="e.upper"
                      (change)="patch({ upper: $any($event.target).checked })"
                    />
                    CAPITALS</label
                  >
                </div>
              }

              @if (e.type === 'image') {
                <label class="flex flex-col gap-1">
                  <span class="cr-meta">HEIGHT (MM)</span>
                  <input
                    type="number"
                    step="0.5"
                    [class]="input"
                    [value]="e.h ?? 15"
                    (change)="patch({ h: num($any($event.target).value) })"
                  />
                </label>
                <label class="flex flex-col gap-1">
                  <span class="cr-meta">PICTURE</span>
                  <select
                    [class]="input"
                    [value]="e.source"
                    (change)="patch({ source: $any($event.target).value })"
                  >
                    <option value="logo">Company logo</option>
                    @for (s of uploads(); track s) {
                      <option [value]="s">Uploaded image {{ $index + 1 }}</option>
                    }
                  </select>
                </label>
                <label [class]="tool + ' cursor-pointer self-start'">
                  Replace with a new image
                  <input
                    type="file"
                    accept="image/*"
                    class="sr-only"
                    (change)="replaceImage($event)"
                  />
                </label>
              }

              @if (e.type === 'line' || e.type === 'box') {
                <div class="grid grid-cols-2 gap-2">
                  <label class="flex flex-col gap-1">
                    <span class="cr-meta">COLOUR</span>
                    <input
                      type="color"
                      class="h-8 w-full cursor-pointer rounded-control border border-cr-line"
                      [value]="e.color"
                      (input)="patch({ color: $any($event.target).value })"
                    />
                  </label>
                  <label class="flex flex-col gap-1">
                    <span class="cr-meta">THICKNESS (MM)</span>
                    <input
                      type="number"
                      step="0.1"
                      min="0"
                      max="3"
                      [class]="input"
                      [value]="e.thickness"
                      (change)="patch({ thickness: num($any($event.target).value) ?? 0.3 })"
                    />
                  </label>
                  @if (e.type === 'box') {
                    <label class="flex flex-col gap-1">
                      <span class="cr-meta">HEIGHT (MM)</span>
                      <input
                        type="number"
                        step="0.5"
                        [class]="input"
                        [value]="e.h ?? 10"
                        (change)="patch({ h: num($any($event.target).value) })"
                      />
                    </label>
                    <label class="flex flex-col gap-1">
                      <span class="cr-meta">FILL</span>
                      <input
                        type="color"
                        class="h-8 w-full cursor-pointer rounded-control border border-cr-line"
                        [value]="e.fill ?? '#ffffff'"
                        (input)="patch({ fill: $any($event.target).value })"
                      />
                    </label>
                  }
                </div>
              }

              @if (e.type === 'table') {
                <label class="flex flex-col gap-1">
                  <span class="cr-meta">HEADING ABOVE THE TABLE</span>
                  <input
                    [class]="input"
                    [value]="e.title ?? ''"
                    (input)="patch({ title: $any($event.target).value })"
                  />
                </label>
                <div class="grid grid-cols-2 gap-2">
                  <label class="flex flex-col gap-1">
                    <span class="cr-meta">TEXT SIZE (PT)</span>
                    <input
                      type="number"
                      step="0.5"
                      min="5"
                      max="20"
                      [class]="input"
                      [value]="e.size"
                      (change)="patch({ size: num($any($event.target).value) ?? 8.5 })"
                    />
                  </label>
                  <label class="flex flex-col gap-1">
                    <span class="cr-meta">HEADER LINE</span>
                    <input
                      type="color"
                      class="h-8 w-full cursor-pointer rounded-control border border-cr-line"
                      [value]="e.color"
                      (input)="patch({ color: $any($event.target).value })"
                    />
                  </label>
                  <label class="flex flex-col gap-1">
                    <span class="cr-meta">SPACE ABOVE (MM)</span>
                    <input
                      type="number"
                      step="0.5"
                      [class]="input"
                      [value]="e.space_before"
                      (change)="patch({ space_before: num($any($event.target).value) ?? 4 })"
                    />
                  </label>
                  <label class="flex items-end gap-1.5 pb-1.5"
                    ><input
                      type="checkbox"
                      [checked]="e.striped"
                      (change)="patch({ striped: $any($event.target).checked })"
                    />
                    Striped rows</label
                  >
                </div>
                <div class="flex gap-2">
                  <button type="button" [class]="tool" (click)="moveTable(-1)">Move up</button>
                  <button type="button" [class]="tool" (click)="moveTable(1)">Move down</button>
                </div>
                <div>
                  <p class="cr-meta mb-1">COLUMNS</p>
                  <div class="flex flex-col gap-1.5">
                    @for (col of e.columns ?? []; track col.key; let i = $index) {
                      <div class="flex items-center gap-1">
                        <input
                          type="checkbox"
                          [checked]="!col.hidden"
                          [attr.aria-label]="'Show ' + col.label"
                          (change)="patchColumn(i, { hidden: !$any($event.target).checked })"
                        />
                        <input
                          [class]="input"
                          [value]="col.label"
                          (input)="patchColumn(i, { label: $any($event.target).value })"
                        />
                        <select
                          class="h-8 rounded-control border border-cr-line bg-cr-surface px-1 text-[12px]"
                          [value]="col.align"
                          (change)="patchColumn(i, { align: $any($event.target).value })"
                        >
                          @for (a of aligns; track a) {
                            <option [value]="a">{{ a }}</option>
                          }
                        </select>
                        <button
                          type="button"
                          class="h-8 px-1 text-cr-ink-muted hover:text-cr-blue"
                          title="Move left"
                          (click)="moveColumn(i, -1)"
                        >
                          ↑
                        </button>
                        <button
                          type="button"
                          class="h-8 px-1 text-cr-ink-muted hover:text-cr-blue"
                          title="Move right"
                          (click)="moveColumn(i, 1)"
                        >
                          ↓
                        </button>
                      </div>
                    }
                  </div>
                </div>
              }

              <div class="flex flex-wrap gap-2 border-t border-cr-line pt-3">
                <label class="flex items-center gap-1.5">
                  <input
                    type="checkbox"
                    [checked]="e.hidden"
                    (change)="patch({ hidden: $any($event.target).checked })"
                  />
                  Hide on the printable
                </label>
                @if (e.type !== 'table') {
                  <button type="button" [class]="tool" (click)="duplicate()">Duplicate</button>
                  <button
                    type="button"
                    class="h-8 rounded-control px-3 text-[12px] font-semibold text-cr-red hover:bg-cr-red-bg"
                    (click)="remove()"
                  >
                    Delete
                  </button>
                }
              </div>
            </div>
          } @else {
            <div class="flex flex-col gap-3 text-[13px]">
              <p class="text-cr-ink-muted">
                Drag anything on the page to move it. Click an item to change its text, size, colour
                or width; drag the blue edge to resize it. The dashed bars change how tall the top
                and bottom areas are — the bottom area always follows the tables, so long documents
                never overlap.
              </p>
              <div class="grid grid-cols-3 gap-2">
                <label class="flex flex-col gap-1">
                  <span class="cr-meta">MARGIN</span>
                  <input
                    type="number"
                    step="0.5"
                    min="5"
                    max="30"
                    [class]="input"
                    [value]="l.margin"
                    (change)="setLayout('margin', num($any($event.target).value) ?? 14)"
                  />
                </label>
                <label class="flex flex-col gap-1">
                  <span class="cr-meta">TOP AREA</span>
                  <input
                    type="number"
                    step="1"
                    min="0"
                    max="280"
                    [class]="input"
                    [value]="l.top_height"
                    (change)="setLayout('top_height', num($any($event.target).value) ?? 0)"
                  />
                </label>
                <label class="flex flex-col gap-1">
                  <span class="cr-meta">BOTTOM AREA</span>
                  <input
                    type="number"
                    step="1"
                    min="0"
                    max="280"
                    [class]="input"
                    [value]="l.bottom_height"
                    (change)="setLayout('bottom_height', num($any($event.target).value) ?? 0)"
                  />
                </label>
              </div>
              <p class="cr-meta mt-1">EVERYTHING ON THIS PRINTABLE</p>
              <ul
                class="flex max-h-[420px] flex-col overflow-auto rounded-control border border-cr-line"
              >
                @for (e of l.elements; track e.id) {
                  <li>
                    <button
                      type="button"
                      class="flex w-full items-center justify-between gap-2 px-2 py-1.5 text-left hover:bg-cr-tint"
                      (click)="selectedId.set(e.id)"
                    >
                      <span [class.text-cr-ink-muted]="e.hidden">{{ e.label || e.id }}</span>
                      <span class="text-[11px] text-cr-ink-muted">{{
                        e.hidden ? 'hidden' : e.type
                      }}</span>
                    </button>
                  </li>
                }
              </ul>
            </div>
          }
          @if (selected()) {
            <button
              type="button"
              class="mt-3 text-[12px] font-semibold text-cr-blue hover:underline"
              (click)="selectedId.set(null)"
            >
              ← Page settings
            </button>
          }
        </app-card>
      }
    </div>
  `,
})
export class PrintablesPage {
  private readonly api = inject(PrintableService);
  private readonly confirm = inject(Confirm);

  protected readonly input = INPUT;
  protected readonly tool =
    'flex h-8 items-center rounded-control px-3 text-[12px] font-semibold text-cr-blue ring-1 ring-cr-line hover:bg-cr-tint';
  protected readonly aligns: Align[] = ['left', 'center', 'right'];
  protected readonly tinExample = 'TIN {{company.tin}}';

  protected readonly list = signal<PrintableSummary[] | null>(null);
  protected readonly current = signal<PrintableEditor | null>(null);
  protected readonly draft = signal<PrintLayout | null>(null);
  protected readonly extraImages = signal<Record<string, string>>({});
  protected readonly selectedId = signal<string | null>(null);
  protected readonly zoom = signal(0.8);
  protected readonly failed = signal(false);
  protected readonly busy = signal(false);
  protected readonly notice = signal<string | null>(null);
  protected readonly noticeIsError = signal(false);

  protected readonly zoomPct = computed(() => Math.round(this.zoom() * 100));

  protected readonly groups = computed(() => {
    const groups: { name: string; items: PrintableSummary[] }[] = [];

    for (const item of this.list() ?? []) {
      const group = groups.find((g) => g.name === item.group);
      if (group) group.items.push(item);
      else groups.push({ name: item.group, items: [item] });
    }

    return groups;
  });

  protected readonly selected = computed(
    () => this.draft()?.elements.find((e) => e.id === this.selectedId()) ?? null,
  );

  protected readonly images = computed(() => ({
    ...(this.current()?.images ?? {}),
    ...this.extraImages(),
  }));

  protected readonly uploads = computed(() =>
    Object.keys(this.images()).filter((s) => s.startsWith('upload:')),
  );

  protected readonly fieldList = computed(() =>
    Object.entries(this.current()?.fields ?? {}).map(([key, label]) => ({ key, label })),
  );

  protected readonly dirty = computed(() => {
    const current = this.current();
    const draft = this.draft();

    return !!current && !!draft && JSON.stringify(current.layout) !== JSON.stringify(draft);
  });

  constructor() {
    this.api.list().subscribe({
      next: (items) => {
        this.list.set(items);
        if (items[0]) this.open(items[0].key);
      },
      error: () => this.failed.set(true),
    });
  }

  protected async open(key: string): Promise<void> {
    if (this.dirty()) {
      const ok = await this.confirm.ask({
        title: 'Leave without saving?',
        body: 'Your changes to this printable are not saved yet.',
        confirmLabel: 'Leave',
        danger: true,
      });

      if (!ok) return;
    }

    this.failed.set(false);
    this.notice.set(null);
    this.selectedId.set(null);

    this.api.show(key).subscribe({
      next: (editor) => this.apply(editor),
      error: () => this.failed.set(true),
    });
  }

  protected move(m: ElementMove): void {
    this.update(m.id, { x: m.x, y: m.y, w: m.w });
  }

  protected zone(zone: 'top' | 'bottom'): 'top_height' | 'bottom_height' {
    return zone === 'top' ? 'top_height' : 'bottom_height';
  }

  protected setLayout(key: 'margin' | 'top_height' | 'bottom_height', value: number): void {
    const draft = this.draft();
    if (draft) this.draft.set({ ...draft, [key]: value });
  }

  protected patch(change: Partial<PrintElement>): void {
    const id = this.selectedId();
    if (id) this.update(id, change);
  }

  protected patchColumn(
    index: number,
    change: Partial<NonNullable<PrintElement['columns']>[number]>,
  ): void {
    const e = this.selected();
    if (!e?.columns) return;

    this.patch({ columns: e.columns.map((c, i) => (i === index ? { ...c, ...change } : c)) });
  }

  protected moveColumn(index: number, step: number): void {
    const e = this.selected();
    const columns = [...(e?.columns ?? [])];
    const target = index + step;
    if (target < 0 || target >= columns.length) return;

    [columns[index], columns[target]] = [columns[target], columns[index]];
    this.patch({ columns });
  }

  protected moveTable(step: number): void {
    const draft = this.draft();
    const id = this.selectedId();
    if (!draft || !id) return;

    const tables = draft.elements.filter((e) => e.zone === 'flow').sort((a, b) => a.y - b.y);
    const index = tables.findIndex((e) => e.id === id);
    const target = index + step;
    if (index < 0 || target < 0 || target >= tables.length) return;

    [tables[index], tables[target]] = [tables[target], tables[index]];
    const order = new Map(tables.map((t, i) => [t.id, i + 1]));

    this.draft.set({
      ...draft,
      elements: draft.elements.map((e) => (order.has(e.id) ? { ...e, y: order.get(e.id)! } : e)),
    });
  }

  protected insertField(box: HTMLTextAreaElement, select: HTMLSelectElement): void {
    const key = select.value;
    select.value = '';
    if (!key) return;

    const token = `{{${key}}}`;
    const text = this.selected()?.text ?? '';
    const at = box.selectionStart ?? text.length;

    this.patch({ text: text.slice(0, at) + token + text.slice(box.selectionEnd ?? at) });
  }

  protected addText(): void {
    this.add({
      type: 'text',
      label: 'New text',
      w: 60,
      h: null,
      text: 'New text',
      size: 9,
      bold: false,
      italic: false,
      upper: false,
      align: 'left',
      color: '#1b2430',
      line_height: 1.35,
    });
  }

  protected addLine(): void {
    this.add({ type: 'line', label: 'New line', w: 60, h: null, color: '#dfe4ea', thickness: 0.3 });
  }

  protected addBox(): void {
    this.add({
      type: 'box',
      label: 'New box',
      w: 50,
      h: 15,
      color: '#dfe4ea',
      thickness: 0.3,
      fill: null,
    });
  }

  protected addImage(event: Event): void {
    this.upload(event, (source, ratio) =>
      this.add({
        type: 'image',
        label: 'New image',
        w: 30,
        h: Math.round((30 / ratio) * 2) / 2,
        source,
      }),
    );
  }

  protected replaceImage(event: Event): void {
    this.upload(event, (source) => this.patch({ source }));
  }

  protected duplicate(): void {
    const e = this.selected();
    if (!e) return;

    const { id: _id, ...rest } = e;
    this.add({ ...rest, label: `${e.label} copy`, x: e.x + 3, y: e.y + 3, zone: e.zone });
  }

  protected remove(): void {
    const draft = this.draft();
    const id = this.selectedId();
    if (!draft || !id) return;

    this.draft.set({ ...draft, elements: draft.elements.filter((e) => e.id !== id) });
    this.selectedId.set(null);
  }

  protected zoomBy(step: number): void {
    this.zoom.set(Math.min(1.6, Math.max(0.4, Math.round((this.zoom() + step) * 10) / 10)));
  }

  protected num(value: string): number | null {
    const n = parseFloat(value);

    return Number.isFinite(n) ? n : null;
  }

  protected save(): void {
    const current = this.current();
    const draft = this.draft();
    if (!current || !draft) return;

    this.run(this.api.save(current.key, draft), 'Saved. Every new print and PDF uses this layout.');
  }

  protected discard(): void {
    const current = this.current();
    if (current) this.draft.set(clone(current.layout));
    this.selectedId.set(null);
  }

  protected async reset(): Promise<void> {
    const current = this.current();
    if (!current) return;

    const ok = await this.confirm.ask({
      title: `Reset the ${current.name.toLowerCase()} layout?`,
      body: 'Everything you moved, rewrote or added on this printable goes back to how Cargo Rush ships it.',
      confirmLabel: 'Reset layout',
      danger: true,
    });

    if (ok) this.run(this.api.reset(current.key), 'Back to the default layout.');
  }

  protected preview(): void {
    const current = this.current();
    const draft = this.draft();
    if (!current || !draft) return;

    const tab = window.open('', '_blank');
    this.busy.set(true);

    this.api.preview(current.key, draft).subscribe({
      next: (blob) => {
        this.busy.set(false);
        const url = URL.createObjectURL(blob);
        if (tab) tab.location.href = url;
        else window.open(url, '_blank');
      },
      error: () => {
        this.busy.set(false);
        tab?.close();
        this.say('Could not build the preview. Check the connection and try again.', true);
      },
    });
  }

  private add(element: Partial<PrintElement> & Pick<PrintElement, 'type'>): void {
    const draft = this.draft();
    if (!draft) return;

    const id = `custom-${Date.now().toString(36)}`;
    const full: PrintElement = {
      id,
      label: 'New item',
      zone: 'top',
      x: 10,
      y: 10,
      w: null,
      h: null,
      hidden: false,
      ...element,
      custom: true,
    } as PrintElement;

    this.draft.set({ ...draft, elements: [...draft.elements, full] });
    this.selectedId.set(id);
  }

  private update(id: string, change: Partial<PrintElement>): void {
    const draft = this.draft();
    if (!draft) return;

    this.draft.set({
      ...draft,
      elements: draft.elements.map((e) => (e.id === id ? { ...e, ...change } : e)),
    });
  }

  private upload(event: Event, done: (source: string, ratio: number) => void): void {
    const target = event.target as HTMLInputElement;
    const file = target.files?.[0];
    target.value = '';
    if (!file) return;

    this.busy.set(true);
    this.api.uploadImage(file).subscribe({
      next: (image) => {
        this.busy.set(false);
        this.extraImages.set({ ...this.extraImages(), [image.source]: image.url });
        done(image.source, image.ratio || 1);
      },
      error: (error: HttpErrorResponse) => {
        this.busy.set(false);
        this.say(this.message(error), true);
      },
    });
  }

  private run(request: ReturnType<PrintableService['show']>, success: string): void {
    this.busy.set(true);
    this.notice.set(null);

    request.subscribe({
      next: (editor) => {
        this.busy.set(false);
        this.apply(editor);
        this.list.set(
          (this.list() ?? []).map((i) =>
            i.key === editor.key ? { ...i, customised: editor.customised } : i,
          ),
        );
        this.say(success, false);
      },
      error: (error: HttpErrorResponse) => {
        this.busy.set(false);
        this.say(this.message(error), true);
      },
    });
  }

  private apply(editor: PrintableEditor): void {
    this.current.set(editor);
    this.draft.set(clone(editor.layout));
    this.zoom.set(editor.orientation === 'landscape' ? 0.6 : 0.8);
  }

  private message(error: HttpErrorResponse): string {
    const errors = error.error?.errors as Record<string, string[]> | undefined;

    return (
      (errors && Object.values(errors)[0]?.[0]) ??
      error.error?.message ??
      'Could not save that. Check the connection and try again.'
    );
  }

  private say(message: string, error: boolean): void {
    this.noticeIsError.set(error);
    this.notice.set(message);
  }
}
