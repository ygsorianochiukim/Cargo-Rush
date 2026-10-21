import { TestBed } from '@angular/core/testing';
import { of } from 'rxjs';
import { describe, expect, it, vi } from 'vitest';

import { TruckSize } from '../../models/pricing/pricing.model';
import { RateCardService } from '../../services/pricing/pricing.service';
import { Confirm } from '../../shared/confirm';
import { CbmCard } from './cbm-card';

const size = (id: string, name: string, cbm: number) =>
  ({ id, key: id, name, cbm, status: 'active' }) as unknown as TruckSize;

function mount() {
  const api = {
    createSize: vi.fn((payload: { name: string }) => of(size('new', payload.name, 70))),
    updateSize: vi.fn(() => of(size('ten', '10 Wheeler', 60))),
    removeSize: vi.fn(() => of({ retired: null, reason: null })),
  };

  TestBed.configureTestingModule({
    providers: [
      { provide: RateCardService, useValue: api },
      { provide: Confirm, useValue: { ask: async () => true } },
    ],
  });

  const fixture = TestBed.createComponent(CbmCard);
  fixture.componentRef.setInput('truckSizes', [
    size('ten', '10 Wheeler', 54),
    size('small', 'Small Truck', 17),
  ]);
  fixture.componentRef.setInput('cardCbm', 28);
  fixture.componentRef.setInput('fleet', { code: 'G', cents: 2_188_200 });

  const changed = vi.fn();
  fixture.componentInstance.changed.subscribe(changed);
  fixture.detectChanges();

  const el = fixture.nativeElement as HTMLElement;
  const rows = () =>
    Array.from(el.querySelectorAll('tbody tr')).map((row) =>
      Array.from(row.querySelectorAll('td')).map((cell) => cell.textContent?.trim() ?? ''),
    );

  return { fixture, el, api, changed, rows };
}

describe('the truck sizes card', () => {
  it('lists the sizes smallest first, after the 28 cbm card', () => {
    const { rows } = mount();

    expect(rows().map((row) => row[0])).toEqual(['Current fleet', 'Small Truck', '10 Wheeler']);
  });

  it('prices a 10 wheeler as the fleet rate divided by 28 and times 54', () => {
    const { rows } = mount();
    const ten = rows().find((row) => row[0] === '10 Wheeler') ?? [];

    expect(ten[1]).toBe('54');
    expect(ten[2]).toBe('1.93');
    expect(ten[3].replace(/[^\d]/g, '')).toBe('42201');
  });

  it('adds a size with its cbm and tells the page', () => {
    const { el, api, changed, fixture } = mount();
    const [name, cbm] = Array.from(el.querySelectorAll<HTMLInputElement>('form input'));

    name.value = '12 Wheeler';
    cbm.value = '70';
    el.querySelector('form')?.dispatchEvent(new Event('submit'));
    fixture.detectChanges();

    expect(api.createSize).toHaveBeenCalledWith({ name: '12 Wheeler', cbm: 70 });
    expect(changed).toHaveBeenCalled();
    expect(name.value).toBe('');
  });

  it('refuses a size with no cbm', () => {
    const { el, api, fixture } = mount();
    const [name] = Array.from(el.querySelectorAll<HTMLInputElement>('form input'));

    name.value = '12 Wheeler';
    el.querySelector('form')?.dispatchEvent(new Event('submit'));
    fixture.detectChanges();

    expect(api.createSize).not.toHaveBeenCalled();
    expect(el.querySelector('[role="alert"]')?.textContent).toContain('cbm from 1 to 200');
  });

  it('removes a size once confirmed', async () => {
    const { el, api, changed } = mount();

    el.querySelector<HTMLButtonElement>('button[aria-label="Remove 10 Wheeler"]')?.click();
    await Promise.resolve();
    await Promise.resolve();

    expect(api.removeSize).toHaveBeenCalledWith('ten');
    expect(changed).toHaveBeenCalled();
  });
});
