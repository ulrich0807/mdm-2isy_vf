import { Routes } from '@angular/router';
import { Login } from './features/login/login';
import { authGrd, superAdminGrd } from './guards/auth.grd';

export const routes: Routes = [
  { path: 'login', component: Login },
  
  // Routes protégées par le Guard
  {
    path: 'dashboard',
    loadComponent: () => import('./features/dashboard/dashboard').then((module) => module.Dashboard),
    canActivate: [authGrd],
  },
  {
    path: 'devices',
    loadComponent: () => import('./features/devices/devices').then((module) => module.Devices),
    canActivate: [authGrd],
  },
  {
    path: 'location',
    loadComponent: () => import('./features/location/location').then((module) => module.Location),
    canActivate: [authGrd],
  },
  {
    path: 'clients',
    loadComponent: () => import('./features/clients/clients').then((module) => module.Clients),
    canActivate: [authGrd, superAdminGrd],
  },
  {
    path: 'contact-requests',
    loadComponent: () => import('./features/contact-requests/contact-requests').then((module) => module.ContactRequests),
    canActivate: [authGrd, superAdminGrd],
  },
  {
    path: 'licences',
    loadComponent: () => import('./features/licences/licences').then((module) => module.Licences),
    canActivate: [authGrd],
  },
  {
    path: 'settings',
    loadComponent: () => import('./features/settings/settings').then((module) => module.Settings),
    canActivate: [authGrd],
  },
  {
    path: 'users',
    loadComponent: () => import('./features/users/users').then((module) => module.Users),
    canActivate: [authGrd],
  },
  {
    path: 'apps',
    loadComponent: () => import('./features/apps/apps').then((module) => module.Apps),
    canActivate: [authGrd],
  },
  {
    path: 'profils',
    loadComponent: () => import('./features/profils/profils').then((module) => module.Profils),
    canActivate: [authGrd],
  },
  {
    path: 'logs',
    loadComponent: () => import('./features/logs/logs').then((module) => module.Logs),
    canActivate: [authGrd, superAdminGrd],
  },
  {
    path: 'alerts',
    loadComponent: () => import('./features/alerts/alerts').then((m) => m.AlertsComponent),
    canActivate: [authGrd, superAdminGrd],
  },
  
  { path: '', redirectTo: '/login', pathMatch: 'full' },
  { path: '**', redirectTo: '/login' }
];
