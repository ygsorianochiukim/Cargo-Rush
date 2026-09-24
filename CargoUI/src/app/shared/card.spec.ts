import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { describe, expect, it } from 'vitest';

import { Card } from './card';

/**
 * A card is a block, and the reason is a bug nobody could see in the code.
 *
 * The section inside carries `h-full`, so that a card fills its grid row rather
 * than leaving a gap under a taller neighbour. That is only inert outside a
 * grid if the host has an auto height — and an Angular element is
 * `display: inline` until something says otherwise. A block-level child of an
 * inline box takes its containing block from the nearest block *ancestor*
 * instead, which on a page is the scroll container, and that has a real height.
 *
 * So a single card with one figure in it grew to fill the whole viewport.
 * Payables showed ₱4,963.20 at the top of eight hundred empty pixels, and the
 * eleven bare `<app-card>` elements across six pages were all one parent away
 * from doing the same.
 *
 * The call sites that wrote `class="block"` were working around it without
 * saying so. This is the rule in one place, and a test because the symptom is
 * a screenshot rather than a stack trace.
 */
@Component({
  imports: [Card],
  template: `<app-card heading="Total owed">₱4,963.20</app-card>`,
})
class Host {}

describe('Card', () => {
  it('is a block, so its full-height section stays inert on a page', () => {
    const fixture = TestBed.createComponent(Host);
    fixture.detectChanges();

    const card = (fixture.nativeElement as HTMLElement).querySelector('app-card');

    expect(card).not.toBeNull();
    expect(card!.classList.contains('block')).toBe(true);
  });

  it('keeps the full-height section, which is what fills a grid row', () => {
    const fixture = TestBed.createComponent(Host);
    fixture.detectChanges();

    // The two halves are a pair: drop `h-full` and a row of cards goes ragged,
    // drop the block and a lone card eats the viewport. Neither is safe to
    // remove on its own.
    const section = (fixture.nativeElement as HTMLElement).querySelector('app-card section');

    expect(section!.classList.contains('h-full')).toBe(true);
  });
});
