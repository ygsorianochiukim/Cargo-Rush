import { Routes } from '@angular/router';

import { Layout } from './layout/layout';
import { authGuard, guestGuard } from './services/identity/auth.guard';

/**
 * The web modules from DESIGN.md section 5.1, one lazy chunk each.
 *
 * Every page renders inside the layout (section 4); `data.title` feeds the
 * canvas header, which uppercases it. A route's path matches its module
 * folder under `pages/`, so the map in DESIGN.md and the directory listing
 * are the same list.
 */
export const routes: Routes = [
  // Outside the layout: there is no sidebar to render until somebody is
  // signed in, because the nav and the user chip are both authenticated calls.
  {
    path: 'login',
    title: 'Sign in · Cargo Rush',
    canActivate: [guestGuard],
    loadComponent: () => import('./pages/auth/login.page').then((m) => m.LoginPage),
  },

  {
    path: '',
    component: Layout,
    canActivate: [authGuard],
    children: [
      { path: '', pathMatch: 'full', redirectTo: 'dashboard' },

      // Operations
      {
        path: 'dashboard',
        title: 'Dashboard · Cargo Rush',
        data: { title: 'Dashboard' },
        loadComponent: () => import('./pages/dashboard/dashboard.page').then((m) => m.DashboardPage),
      },
      {
        path: 'gps',
        title: 'GPS Dashboard · Cargo Rush',
        data: { title: 'GPS Dashboard' },
        loadComponent: () => import('./pages/gps/gps.page').then((m) => m.GpsPage),
      },
      {
        path: 'trips',
        title: 'Trip Management · Cargo Rush',
        data: { title: 'Trip Management' },
        loadComponent: () => import('./pages/trips/trips.page').then((m) => m.TripsPage),
      },
      {
        path: 'dispatch',
        title: 'Dispatch Monitoring · Cargo Rush',
        data: { title: 'Dispatch Monitoring' },
        loadComponent: () => import('./pages/dispatch/dispatch.page').then((m) => m.DispatchPage),
      },
      {
        path: 'delivery-logs',
        title: 'Delivery Logs · Cargo Rush',
        data: { title: 'Delivery Logs' },
        loadComponent: () => import('./pages/delivery-logs/delivery-logs.page').then((m) => m.DeliveryLogsPage),
      },

      // Assets
      {
        path: 'vehicles',
        title: 'Vehicle Management · Cargo Rush',
        data: { title: 'Vehicle Management' },
        loadComponent: () => import('./pages/vehicles/vehicles.page').then((m) => m.VehiclesPage),
      },
      {
        path: 'drivers',
        title: 'Drivers Management · Cargo Rush',
        data: { title: 'Drivers Management' },
        loadComponent: () => import('./pages/drivers/drivers.page').then((m) => m.DriversPage),
      },
      {
        path: 'fuel',
        title: 'Fuel Expense · Cargo Rush',
        data: { title: 'Fuel Expense Monitoring' },
        loadComponent: () => import('./pages/fuel/fuel.page').then((m) => m.FuelPage),
      },

      // Finance — the "Master Dashboard" workbook
      {
        path: 'monitoring',
        title: 'Trip Monitoring · Cargo Rush',
        data: { title: 'Daily Trip Monitoring' },
        loadComponent: () => import('./pages/monitoring/monitoring.page').then((m) => m.MonitoringPage),
      },
      {
        path: 'profitability',
        title: 'Profitability · Cargo Rush',
        data: { title: 'Profitability' },
        loadComponent: () => import('./pages/profitability/profitability.page').then((m) => m.ProfitabilityPage),
      },
      {
        path: 'summary',
        title: 'Quarterly Summary · Cargo Rush',
        data: { title: 'Quarterly Summary' },
        loadComponent: () => import('./pages/summary/summary.page').then((m) => m.SummaryPage),
      },
      {
        path: 'expenses',
        title: 'Other Expenses · Cargo Rush',
        data: { title: 'Other Expenses' },
        loadComponent: () => import('./pages/expenses/expenses.page').then((m) => m.ExpensesPage),
      },
      {
        path: 'sales',
        title: 'Sales Report · Cargo Rush',
        data: { title: 'Sales Report' },
        loadComponent: () => import('./pages/sales/sales.page').then((m) => m.SalesPage),
      },

      // Business
      {
        path: 'customers',
        title: 'Customer Management · Cargo Rush',
        data: { title: 'Customer Management' },
        loadComponent: () => import('./pages/customers/customers.page').then((m) => m.CustomersPage),
      },
      {
        path: 'billing',
        title: 'Billing & Invoice · Cargo Rush',
        data: { title: 'Billing & Invoice' },
        loadComponent: () => import('./pages/billing/billing.page').then((m) => m.BillingPage),
      },
      {
        path: 'pricing',
        title: 'Rate Card · Cargo Rush',
        data: { title: 'Rate Card' },
        loadComponent: () => import('./pages/pricing/pricing.page').then((m) => m.PricingPage),
      },

      // HR
      {
        path: 'employees',
        title: 'Employees · Cargo Rush',
        data: { title: 'Employees' },
        loadComponent: () => import('./pages/employees/employees.page').then((m) => m.EmployeesPage),
      },
      {
        path: 'applicants',
        title: 'Applicants · Cargo Rush',
        data: { title: 'Applicants' },
        loadComponent: () =>
          import('./pages/applicants/applicants.page').then((m) => m.ApplicantsPage),
      },
      {
        path: 'time-off',
        title: 'Leave & Undertime · Cargo Rush',
        data: { title: 'Leave & Undertime' },
        loadComponent: () => import('./pages/time-off/time-off.page').then((m) => m.TimeOffPage),
      },
      {
        path: 'performance',
        title: 'Performance · Cargo Rush',
        data: { title: 'Performance' },
        loadComponent: () =>
          import('./pages/performance/performance.page').then((m) => m.PerformancePage),
      },
      {
        path: 'access',
        title: 'Access Control · Cargo Rush',
        data: { title: 'Access Control' },
        loadComponent: () => import('./pages/access/access.page').then((m) => m.AccessPage),
      },

      // Support
      {
        path: 'incidents',
        title: 'Incident Management · Cargo Rush',
        data: { title: 'Incident Management' },
        loadComponent: () => import('./pages/incidents/incidents.page').then((m) => m.IncidentsPage),
      },
      {
        path: 'notifications',
        title: 'Notifications · Cargo Rush',
        data: { title: 'Notification Management' },
        loadComponent: () => import('./pages/notifications/notifications.page').then((m) => m.NotificationsPage),
      },

      { path: '**', redirectTo: 'dashboard' },
    ],
  },
];
