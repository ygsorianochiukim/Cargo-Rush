import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { describe, expect, it } from 'vitest';

import { Card } from './card';

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

    const section = (fixture.nativeElement as HTMLElement).querySelector('app-card section');

    expect(section!.classList.contains('h-full')).toBe(true);
  });
});
