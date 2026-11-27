import { DestroyRef, Directive, ElementRef, inject, output, signal } from '@angular/core';

interface Point {
  x: number;
  y: number;
}

@Directive({
  selector: '[officeCamera]',
  host: {
    '[class.dragging]': 'dragging()',
    '(pointerdown)': 'pointerDown($event)',
    '(pointermove)': 'pointerMove($event)',
    '(pointerup)': 'pointerEnd($event)',
    '(pointercancel)': 'pointerEnd($event)',
    '(pointerleave)': 'pointerLeave($event)',
    '(lostpointercapture)': 'pointerEnd($event)',
  },
})
export class OfficeCamera {
  private readonly viewport = inject(ElementRef<HTMLDivElement>).nativeElement;
  private readonly pointers = new Map<number, Point>();
  private start: Point | null = null;
  private suppressClick = false;
  private clickWasDragged = false;
  readonly dragging = signal(false);
  readonly zoom = signal(1);
  readonly moved = output<void>();

  constructor() {
    this.viewport.addEventListener('wheel', this.wheel, { passive: false });
    this.viewport.addEventListener('click', this.guardClick, true);
    inject(DestroyRef).onDestroy(() => {
      this.viewport.removeEventListener('wheel', this.wheel);
      this.viewport.removeEventListener('click', this.guardClick, true);
    });
  }

  adjust(factor: number): void {
    this.zoomAt(this.zoom() * factor);
  }

  fit(): void {
    this.zoomAt(Math.min(1, this.viewport.clientWidth / 900));
    this.viewport.scrollTo({ left: 0, top: 0 });
  }

  center(x: number, y: number, behavior: ScrollBehavior): void {
    const frame = this.frame();
    if (!frame) return;
    this.viewport.scrollTo({
      left: x * frame.width - this.viewport.clientWidth / 2,
      top: y * frame.height - this.viewport.clientHeight / 2,
      behavior,
    });
  }

  keepVisible(x: number, y: number): void {
    const frame = this.frame();
    if (!frame) return;
    const { scrollLeft, scrollTop, clientWidth, clientHeight } = this.viewport;
    const px = x * frame.width;
    const py = y * frame.height;
    if (
      px < scrollLeft + 30 ||
      px > scrollLeft + clientWidth - 30 ||
      py < scrollTop + 40 ||
      py > scrollTop + clientHeight - 24
    ) {
      this.center(x, y, 'auto');
    }
  }

  protected pointerDown(event: PointerEvent): void {
    if (event.button !== 0 || this.pointers.size >= 2) return;
    const point = { x: event.clientX, y: event.clientY };
    if (this.pointers.size === 0) {
      this.start = point;
      this.suppressClick = false;
    }
    this.pointers.set(event.pointerId, point);
    if (this.pointers.size === 2) this.beginGesture();
  }

  protected pointerMove(event: PointerEvent): void {
    const previous = this.pointers.get(event.pointerId);
    if (!previous) return;
    const next = { x: event.clientX, y: event.clientY };
    this.pointers.set(event.pointerId, next);

    if (this.pointers.size === 2) {
      const other = [...this.pointers.entries()].find(([id]) => id !== event.pointerId)![1];
      const distance = Math.hypot(previous.x - other.x, previous.y - other.y);
      if (distance > 0) {
        this.zoomAt((this.zoom() * Math.hypot(next.x - other.x, next.y - other.y)) / distance, {
          x: (previous.x + other.x) / 2,
          y: (previous.y + other.y) / 2,
        });
      }
      this.pan((previous.x - next.x) / 2, (previous.y - next.y) / 2);
      return;
    }

    if (!this.dragging()) {
      const origin = this.start ?? previous;
      if (Math.hypot(next.x - origin.x, next.y - origin.y) < 6) return;
      this.beginGesture();
      this.pan(origin.x - next.x, origin.y - next.y);
    } else {
      this.pan(previous.x - next.x, previous.y - next.y);
    }
  }

  protected pointerEnd(event: PointerEvent): void {
    if (!this.pointers.delete(event.pointerId)) return;
    if (!this.pointers.size) {
      this.dragging.set(false);
      this.start = null;
      this.suppressClick = this.clickWasDragged;
      this.clickWasDragged = false;
    }
  }

  protected pointerLeave(event: PointerEvent): void {
    if (!this.dragging()) this.pointerEnd(event);
  }

  private beginGesture(): void {
    this.clickWasDragged = true;
    this.dragging.set(true);
    this.moved.emit();
    for (const id of this.pointers.keys()) this.viewport.setPointerCapture(id);
  }

  private pan(x: number, y: number): void {
    this.viewport.scrollBy({ left: x, top: y });
  }

  private zoomAt(level: number, point?: Point): void {
    const next = Math.max(0.25, Math.min(3, level));
    const before = this.frame();
    if (!before || next === this.zoom()) return;
    const bounds = this.viewport.getBoundingClientRect();
    const x = point ? point.x - bounds.left : this.viewport.clientWidth / 2;
    const y = point ? point.y - bounds.top : this.viewport.clientHeight / 2;
    const originX = (this.viewport.scrollLeft + x) / before.width;
    const originY = (this.viewport.scrollTop + y) / before.height;
    this.viewport.style.setProperty('--office-zoom', String(next));
    this.zoom.set(next);
    const after = this.frame();
    if (after)
      this.viewport.scrollTo({ left: originX * after.width - x, top: originY * after.height - y });
  }

  private frame(): DOMRect | undefined {
    return this.viewport.firstElementChild?.getBoundingClientRect();
  }

  private readonly wheel = (event: WheelEvent): void => {
    if (!event.ctrlKey && !event.metaKey) return;
    event.preventDefault();
    this.zoomAt(this.zoom() * Math.exp(-event.deltaY / 220), {
      x: event.clientX,
      y: event.clientY,
    });
  };

  private readonly guardClick = (event: MouseEvent): void => {
    if (!this.suppressClick || event.detail === 0) return;
    this.suppressClick = false;
    event.preventDefault();
    event.stopImmediatePropagation();
  };
}
