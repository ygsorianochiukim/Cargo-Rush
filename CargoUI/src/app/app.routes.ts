import { Routes } from '@angular/router';

import { Layout } from './layout/layout';
import { authGuard, guestGuard } from './services/identity/auth.guard';

export const routes: Routes = [
  {
    path: 'login',
    title: 'Sign in · Cargo Rush',
    canActivate: [guestGuard],
    loadComponent: () => import('./pages/auth/login.page').then((m) => m.LoginPage),
  },

  {
    path: 'register',
    title: 'Register your company · Cargo Rush',
    canActivate: [guestGuard],
    loadComponent: () => import('./pages/auth/register.page').then((m) => m.RegisterPage),
  },

  {
    path: 'forgot-password',
    title: 'Forgotten password · Cargo Rush',
    canActivate: [guestGuard],
    loadComponent: () =>
      import('./pages/auth/forgot-password.page').then((m) => m.ForgotPasswordPage),
  },
  {
    path: 'reset-password',
    title: 'Choose a new password · Cargo Rush',
    canActivate: [guestGuard],
    loadComponent: () =>
      import('./pages/auth/reset-password.page').then((m) => m.ResetPasswordPage),
  },

  {
    path: '',
    component: Layout,
    canActivate: [authGuard],
    children: [
      { path: '', pathMatch: 'full', redirectTo: 'dashboard' },

      {
        path: 'dashboard',
        title: 'Dashboard · Cargo Rush',
        data: { title: 'Dashboard' },
        loadComponent: () =>
          import('./pages/dashboard/dashboard.page').then((m) => m.DashboardPage),
      },
      {
        path: 'gps',
        title: 'GPS Dashboard · Cargo Rush',
        data: { title: 'GPS Dashboard' },
        loadComponent: () => import('./pages/gps/gps.page').then((m) => m.GpsPage),
      },
      {
        path: 'trips/:trip/ticket',
        title: 'Trip ticket · Cargo Rush',
        data: { title: 'Trip ticket' },
        loadComponent: () =>
          import('./pages/trip-ticket/trip-ticket.page').then((m) => m.TripTicketPage),
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
        loadComponent: () =>
          import('./pages/delivery-logs/delivery-logs.page').then((m) => m.DeliveryLogsPage),
      },

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
        path: 'helpers',
        title: 'Helpers Management · Cargo Rush',
        data: { title: 'Helpers Management' },
        loadComponent: () => import('./pages/helpers/helpers.page').then((m) => m.HelpersPage),
      },
      {
        path: 'truckers',
        title: 'Truckers · Cargo Rush',
        data: { title: 'Truckers' },
        loadComponent: () => import('./pages/truckers/truckers.page').then((m) => m.TruckersPage),
      },
      {
        path: 'payables',
        title: 'Payables · Cargo Rush',
        data: { title: 'Payables' },
        loadComponent: () => import('./pages/payables/payables.page').then((m) => m.PayablesPage),
      },
      {
        path: 'fuel',
        title: 'Fuel Expense · Cargo Rush',
        data: { title: 'Fuel Expense Monitoring' },
        loadComponent: () => import('./pages/fuel/fuel.page').then((m) => m.FuelPage),
      },

      {
        path: 'monitoring',
        title: 'Trip Monitoring · Cargo Rush',
        data: { title: 'Daily Trip Monitoring' },
        loadComponent: () =>
          import('./pages/monitoring/monitoring.page').then((m) => m.MonitoringPage),
      },
      {
        path: 'profitability',
        title: 'Profitability · Cargo Rush',
        data: { title: 'Profitability' },
        loadComponent: () =>
          import('./pages/profitability/profitability.page').then((m) => m.ProfitabilityPage),
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
        path: 'billing/:invoice',
        title: 'Invoice · Cargo Rush',
        data: { title: 'Invoice' },
        loadComponent: () => import('./pages/invoice/invoice.page').then((m) => m.InvoicePage),
      },
      {
        path: 'statements',
        title: 'Financial Statements · Cargo Rush',
        data: { title: 'Financial Statements' },
        loadComponent: () =>
          import('./pages/statements/statements.page').then((m) => m.StatementsPage),
      },
      {
        path: 'journal',
        title: 'General Journal · Cargo Rush',
        data: { title: 'General Journal' },
        loadComponent: () => import('./pages/journal/journal.page').then((m) => m.JournalPage),
      },
      {
        path: 'ledger',
        title: 'General Ledger · Cargo Rush',
        data: { title: 'General Ledger' },
        loadComponent: () => import('./pages/ledger/ledger.page').then((m) => m.LedgerPage),
      },
      {
        path: 'accounts',
        title: 'Chart of Accounts · Cargo Rush',
        data: { title: 'Chart of Accounts' },
        loadComponent: () => import('./pages/accounts/accounts.page').then((m) => m.AccountsPage),
      },
      {
        path: 'sales',
        title: 'Sales Report · Cargo Rush',
        data: { title: 'Sales Report' },
        loadComponent: () => import('./pages/sales/sales.page').then((m) => m.SalesPage),
      },

      {
        path: 'customers',
        title: 'Customer Management · Cargo Rush',
        data: { title: 'Customer Management' },
        loadComponent: () =>
          import('./pages/customers/customers.page').then((m) => m.CustomersPage),
      },
      {
        path: 'billing',
        title: 'Billing & Invoice · Cargo Rush',
        data: { title: 'Billing & Invoice' },
        loadComponent: () => import('./pages/billing/billing.page').then((m) => m.BillingPage),
      },
      {
        path: 'suppliers',
        title: 'Suppliers · Cargo Rush',
        data: { title: 'Suppliers' },
        loadComponent: () =>
          import('./pages/suppliers/suppliers.page').then((m) => m.SuppliersPage),
      },
      {
        path: 'maintenance',
        title: 'Truck Maintenance · Cargo Rush',
        data: { title: 'Truck Maintenance' },
        loadComponent: () =>
          import('./pages/maintenance/maintenance.page').then((m) => m.MaintenancePage),
      },
      {
        path: 'pricing',
        title: 'Rate Card · Cargo Rush',
        data: { title: 'Rate Card' },
        loadComponent: () => import('./pages/pricing/pricing.page').then((m) => m.PricingPage),
      },

      {
        path: 'employees',
        title: 'Employees · Cargo Rush',
        data: { title: 'Employees' },
        loadComponent: () =>
          import('./pages/employees/employees.page').then((m) => m.EmployeesPage),
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
        path: 'payroll',
        title: 'Payroll · Cargo Rush',
        data: { title: 'Payroll' },
        loadComponent: () => import('./pages/payroll/payroll.page').then((m) => m.PayrollPage),
      },
      {
        path: 'access',
        title: 'Access Control · Cargo Rush',
        data: { title: 'Access Control' },
        loadComponent: () => import('./pages/access/access.page').then((m) => m.AccessPage),
      },

      {
        path: 'incidents',
        title: 'Incident Management · Cargo Rush',
        data: { title: 'Incident Management' },
        loadComponent: () =>
          import('./pages/incidents/incidents.page').then((m) => m.IncidentsPage),
      },
      {
        path: 'notifications',
        title: 'Notifications · Cargo Rush',
        data: { title: 'Notification Management' },
        loadComponent: () =>
          import('./pages/notifications/notifications.page').then((m) => m.NotificationsPage),
      },

      { path: '**', redirectTo: 'dashboard' },
    ],
  },
];
