import { Component, input, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import { expect, it } from 'vitest';

import { NavItem } from '../models/identity/identity.model';
import { IdentityService } from '../services/identity/identity.service';
import { AccountMenu } from './account-menu';
import { Sidebar } from './sidebar';

@Component({ selector: 'app-account-menu', template: '' })
class AccountMenuStub {
  readonly collapsed = input(false);
}

@Component({ template: '' })
class PageStub {}

const items: NavItem[] = [
  {
    key: 'dashboard',
    label: 'Dashboard',
    icon: 'dashboard',
    route: '/dashboard',
    order: 1,
    mobile: true,
    group: 'Operations',
    badge: 2,
  },
  {
    key: 'payroll',
    label: 'Office Payroll',
    icon: 'wallet',
    route: '/payroll',
    order: 2,
    mobile: false,
    group: 'People',
  },
  {
    key: 'crew-payroll',
    label: 'Crew Payroll',
    icon: 'route',
    route: '/payroll/crew',
    order: 3,
    mobile: false,
    group: 'People',
  },
];

it('reveals the current page group, keeps only one group open, and follows navigation in the icon rail', async () => {
  TestBed.configureTestingModule({
    providers: [
      provideRouter([
        { path: 'dashboard', component: PageStub },
        { path: 'payroll/crew', component: PageStub },
        { path: 'payroll', component: PageStub },
      ]),
      {
        provide: IdentityService,
        useValue: {
          navigation: signal(items),
          navGroups: signal([
            { name: 'Operations', items: items.slice(0, 1) },
            { name: 'People', items: items.slice(1) },
          ]),
        },
      },
    ],
  }).overrideComponent(Sidebar, {
    remove: { imports: [AccountMenu] },
    add: { imports: [AccountMenuStub] },
  });

  const router = TestBed.inject(Router);
  await router.navigateByUrl('/payroll/crew?run=demo');
  const fixture = TestBed.createComponent(Sidebar);
  const sidebar = fixture.nativeElement as HTMLElement;
  fixture.detectChanges();

  const operations = sidebar.querySelector<HTMLButtonElement>(
    '[aria-controls="sidebar-section-dashboard"]',
  )!;
  const people = sidebar.querySelector<HTMLButtonElement>(
    '[aria-controls="sidebar-section-payroll"]',
  )!;
  const visibleLinks = () =>
    [...sidebar.querySelectorAll<HTMLAnchorElement>('ul:not([hidden]) > li > a')].map((link) =>
      link.getAttribute('href'),
    );

  expect(people.getAttribute('aria-expanded')).toBe('true');
  expect(operations.textContent).toContain('2 pending');
  expect(visibleLinks()).toEqual(['/payroll', '/payroll/crew']);
  expect(sidebar.querySelector('[aria-current="page"]')?.getAttribute('href')).toBe(
    '/payroll/crew',
  );

  operations.click();
  fixture.detectChanges();
  expect(people.getAttribute('aria-expanded')).toBe('false');
  expect(visibleLinks()).toEqual(['/dashboard']);
  operations.click();
  fixture.detectChanges();
  expect(visibleLinks()).toEqual([]);

  fixture.componentRef.setInput('collapsed', true);
  await router.navigateByUrl('/dashboard');
  fixture.detectChanges();
  expect(operations.getAttribute('aria-expanded')).toBe('true');
  expect(visibleLinks()).toEqual(['/dashboard']);
  expect(sidebar.querySelector('[aria-current="page"]')?.textContent).toContain('Dashboard');
});
