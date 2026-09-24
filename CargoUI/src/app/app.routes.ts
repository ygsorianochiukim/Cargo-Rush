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

  // The way onto the platform. Behind `guestGuard` for the same reason sign-in
  // is: somebody already signed in has a company, and offering them a form to
  // create a second one from inside the first is a question with no good
  // answer.
  {
    path: 'register',
    title: 'Register your company · Cargo Rush',
    canActivate: [guestGuard],
    loadComponent: () => import('./pages/auth/register.page').then((m) => m.RegisterPage),
  },

  // Getting back in. Behind `guestGuard` like the other two: somebody already
  // signed in has no use for a reset link, and changing a password from inside
  // the app is a different screen with a different rule (it asks for the
  // current one).
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

      // Operations
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
      /**
       * The books, in the order they are worked in: entries are written in the
       * journal, the ledger is read off them, and the chart is what both point
       * at. Same order as the sidebar (`NavigationSeeder`), which is what the
       * `accounting.view` permission gates — the routes are reachable by URL to
       * anybody signed in, and the API refuses each one for an account that
       * does not hold it.
       */
      /**
       * One invoice as a document — the printable copy.
       *
       * A child of billing rather than a modal on it, because a document is a
       * place: the URL is a link somebody can send to a colleague or keep in a
       * tab, and printing needs a page of its own for the print stylesheet to
       * strip the shell off. Declared before `billing` so the parameterised
       * path is not shadowed by it.
       */
      {
        path: 'billing/:invoice',
        title: 'Invoice · Cargo Rush',
        data: { title: 'Invoice' },
        loadComponent: () => import('./pages/invoice/invoice.page').then((m) => m.InvoicePage),
      },
      /**
       * The statements first, because they are what the books are *for*.
       *
       * The journal and the ledger below are how a figure here got to be what
       * it is; this is the page somebody opens to find out whether the month
       * paid. Same order as the sidebar (`NavigationSeeder`).
       */
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

      // Business
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
      /**
       * Payroll sits with the people rather than with the money.
       *
       * It is read by whoever answers for the roster and run by whoever signs
       * the cheque, which is why the API gates it on its own `payroll.*`
       * permissions rather than on `hr.*` — a payslip is somebody's private
       * business, and approving a run is the money side.
       */
      {
        path: 'payroll',
        title: 'Payroll · Cargo Rush',
        data: { title: 'Payroll' },
        loadComponent: () => import('./pages/payroll/payroll.page').then((m) => m.PayrollPage),
      },
      /*
       * Salary Structure had a page here, and it is gone.
       *
       * It was a second pay system standing beside the first: a catalogue to
       * learn, and an assignment to make, before an office could put ₱500 on
       * one payslip. Most fleets pay a salary or a trip rate and a handful of
       * one-off charges, and for those the page was a detour — it was never in
       * the sidebar, so in practice nobody found it anyway.
       *
       * What replaced it is where the work actually happens. A recurring
       * allowance is assigned on the employee (see `employee-pay-components`
       * on the Employees screen), and a one-off deduction is added on the pay
       * run itself, on the payslip it belongs to. The catalogue is still there
       * and still reached by both.
       */
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
