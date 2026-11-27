import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { of } from 'rxjs';
import { describe, expect, it } from 'vitest';

import { CustomerService } from '../services/customer/customer.service';
import { DriverService } from '../services/driver/driver.service';
import { IdentityService } from '../services/identity/identity.service';
import { PricingService } from '../services/pricing/pricing.service';
import { TripService } from '../services/trip/trip.service';
import { VehicleService } from '../services/vehicle/vehicle.service';
import { TripDialog } from './trip-dialog';
import { TripForm } from './trip-form';

describe('trip draft vehicle changes', () => {
  function mount() {
    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        { provide: TripService, useValue: {} },
        {
          provide: VehicleService,
          useValue: {
            list: () =>
              of({
                data: [
                  { id: 'one', driver_id: 'driver-one', driver_name: 'Driver One' },
                  { id: 'two', driver_id: 'driver-two', driver_name: 'Driver Two' },
                ],
              }),
          },
        },
        { provide: DriverService, useValue: { crew: () => of({ data: [] }) } },
        { provide: CustomerService, useValue: { list: () => of({ data: [] }) } },
        { provide: IdentityService, useValue: { can: () => false } },
        { provide: PricingService, useValue: {} },
      ],
    });

    TestBed.overrideComponent(TripForm, { set: { template: '' } });
    const fixture = TestBed.createComponent(TripForm);
    TestBed.inject(TripDialog).create();
    fixture.detectChanges();
    return fixture;
  }

  it('preserves the draft while changing the suggested driver with the truck', () => {
    const fixture = mount();
    const component = fixture.componentInstance;
    const form = component['form'];
    component['origin'].set({ place: 'Pagadian', lat: 7.8, lng: 123.4 });
    component['destination'].set({ place: 'Ozamiz', lat: 8.1, lng: 123.8 });
    component['helpers'].set(['helper']);
    form.patchValue({
      cargo: '12 pallets',
      weight_kg: 1800,
      customer_id: 'customer',
      scheduled_at: '2026-12-01T08:00',
    });

    form.controls.vehicle_id.setValue('one');
    fixture.detectChanges();
    expect(form.controls.driver_id.value).toBe('driver-one');
    form.controls.vehicle_id.setValue('two');
    fixture.detectChanges();

    expect(form.getRawValue()).toMatchObject({
      vehicle_id: 'two',
      driver_id: 'driver-two',
      origin: 'Pagadian',
      destination: 'Ozamiz',
      cargo: '12 pallets',
      weight_kg: 1800,
      customer_id: 'customer',
      scheduled_at: '2026-12-01T08:00',
    });
    expect(component['helpers']()).toEqual(['helper']);
    expect(component['origin']().lat).toBe(7.8);
  });

  it('keeps an explicitly chosen driver when another truck is selected', () => {
    const fixture = mount();
    const component = fixture.componentInstance;
    const form = component['form'];
    form.controls.vehicle_id.setValue('one');
    fixture.detectChanges();
    form.controls.driver_id.setValue('manual-driver');
    form.controls.driver_id.markAsDirty();
    form.controls.vehicle_id.setValue('two');
    fixture.detectChanges();

    expect(form.controls.driver_id.value).toBe('manual-driver');
    expect(component['swapping']()).toBe(true);
  });
});
