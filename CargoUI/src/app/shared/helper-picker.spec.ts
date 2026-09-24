import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { describe, expect, it } from 'vitest';

import { Driver } from '../models/driver/driver.model';
import { HelperPicker } from './helper-picker';

/**
 * A run's helpers — several of them, where the trip form used to take one.
 *
 * What is pinned is what would pay somebody twice: the driver is never offered
 * as their own helper, and nobody already on the run is offered again.
 */
const person = (id: string, name: string): Driver =>
  ({ id, name, status: 'available' }) as unknown as Driver;

const ROSTER = [person('d1', 'Marco Reyes'), person('h1', 'Ana Lim'), person('h2', 'Ben Cruz')];

@Component({
  imports: [HelperPicker],
  template: `<app-helper-picker [drivers]="roster" [driverId]="driver()" [(value)]="helpers" />`,
})
class Host {
  readonly roster = ROSTER;
  readonly driver = signal<string | null>('d1');
  readonly helpers = signal<string[]>([]);
}

function mount() {
  const fixture = TestBed.createComponent(Host);
  fixture.detectChanges();

  const el = fixture.nativeElement as HTMLElement;
  const select = () => el.querySelector('select') as HTMLSelectElement;
  const offered = () =>
    Array.from(select().options)
      .map((o) => o.value)
      .filter(Boolean);
  const chips = () => Array.from(el.querySelectorAll('li')).map((li) => li.textContent?.trim());

  const pick = (id: string) => {
    select().value = id;
    select().dispatchEvent(new Event('change'));
    fixture.detectChanges();
  };

  return { fixture, el, select, offered, chips, pick };
}

describe('HelperPicker', () => {
  it('does not offer the driver as a helper', () => {
    const { offered } = mount();

    expect(offered()).toEqual(['h1', 'h2']);
  });

  it('adds several helpers in order, and stops offering the ones already on', () => {
    const { fixture, offered, chips, pick } = mount();

    pick('h2');
    pick('h1');

    expect(fixture.componentInstance.helpers()).toEqual(['h2', 'h1']);
    expect(chips()).toEqual(['Ben Cruz', 'Ana Lim']);
    expect(offered()).toEqual([]);
  });

  it('takes a helper off with their remove button', () => {
    const { fixture, el, pick } = mount();

    pick('h1');
    pick('h2');

    (el.querySelector('button[aria-label="Remove Ana Lim"]') as HTMLButtonElement).click();
    fixture.detectChanges();

    expect(fixture.componentInstance.helpers()).toEqual(['h2']);
  });
});
