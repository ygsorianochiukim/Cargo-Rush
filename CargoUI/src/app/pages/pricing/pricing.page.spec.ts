import { NO_ERRORS_SCHEMA } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { of } from 'rxjs';
import { describe, expect, it } from 'vitest';

import { PricingZone } from '../../models/pricing/pricing.model';
import { PricingService, RateCardService } from '../../services/pricing/pricing.service';
import { Confirm } from '../../shared/confirm';
import { DistanceCard } from './distance-card';
import { PricingPage } from './pricing.page';

const zone = (id: string, code: string, base: number, step: number): PricingZone =>
  ({
    id,
    code,
    name: `Zone ${code}`,
    min_km: 1,
    max_km: 41,
    band: '1 – 40 km',
    status: 'active',
    diesel_baseline_cents: 4_300,
    notes: null,
    bracket_count: 1,
    brackets: [
      {
        id: `${id}-fleet`,
        label: 'Current fleet',
        truck_category_id: null,
        base_cents: base * 100,
        diesel_step_cents: step * 100,
        per_km_cents: 0,
        per_kg_cents: 0,
        minimum_cents: 0,
      },
    ],
  }) as unknown as PricingZone;

const ZONES = [zone('a1', 'A1', 4_165, 14), zone('g', 'G', 17_766, 98)];

function mount(categories: unknown[] = []) {
  TestBed.configureTestingModule({
    providers: [
      {
        provide: PricingService,
        useValue: {
          list: () => of({ data: ZONES }),
          diesel: () =>
            of({
              current: { effective_on: '2026-10-10', price_per_litre_cents: 8_500, source: null },
              baseline_cents: 4_300,
              band_floor_cents: 3_000,
              sensitivity: 0.35,
              cap_bp: 1_500,
              adjustment_bp: 0,
              capped: false,
              currency: 'PHP',
              card_cbm: 28,
              history: [],
            }),
        },
      },
      { provide: RateCardService, useValue: { truckCategories: () => of({ data: categories }) } },
      { provide: Confirm, useValue: { ask: async () => false } },
    ],
  });

  TestBed.overrideComponent(PricingPage, {
    remove: { imports: [DistanceCard] },
    add: { schemas: [NO_ERRORS_SCHEMA] },
  });

  const fixture = TestBed.createComponent(PricingPage);
  fixture.detectChanges();

  const el = fixture.nativeElement as HTMLElement;
  const rateInputs = () =>
    Array.from(el.querySelectorAll<HTMLInputElement>('tbody tr input')).map((input) => input.value);
  const open = (code: string) => {
    const button = Array.from(el.querySelectorAll<HTMLButtonElement>('li button')).find((b) =>
      b.textContent?.includes(`${code} ·`),
    );
    button?.click();
    fixture.detectChanges();
  };

  return { el, rateInputs, open };
}

describe('the rate card editor', () => {
  it('shows the rates of the first band', () => {
    const { rateInputs } = mount();

    expect(rateInputs()).toEqual(['Current fleet', '4165', '14']);
  });

  it('fills the rates in again when another band is opened', () => {
    const { rateInputs, open } = mount();

    open('G');

    expect(rateInputs()).toEqual(['Current fleet', '17766', '98']);
  });

  it('prints the diesel grid the way the subsidy table does', () => {
    const { el, open } = mount();

    open('G');

    const today = el.querySelector('td.font-semibold.bg-cr-tint')?.textContent ?? '';
    expect(today.replace(/[^\d]/g, '')).toBe('21882');
  });
});

describe('a class with no rate of its own', () => {
  it('prints a 10 wheeler as the fleet line divided by 28 and times 54', () => {
    const { el, open } = mount([
      { id: 'ten', key: '10-wheeler', name: '10 Wheeler', cbm: 54, status: 'active' },
      { id: 'recon', key: 'recon-truck', name: 'Recon Truck', cbm: null, status: 'active' },
    ]);

    open('G');

    const rows = Array.from(el.querySelectorAll('tbody tr')).filter((row) =>
      row.querySelector('td.sticky'),
    );
    const ten = rows.find((row) => row.textContent?.includes('10 Wheeler · 54 cbm'));
    const today = ten?.querySelector('td.font-semibold.bg-cr-tint')?.textContent ?? '';

    expect(rows.some((row) => row.textContent?.includes('Recon Truck'))).toBe(false);
    expect(today.replace(/[^\d]/g, '')).toBe('42201');
  });
});
