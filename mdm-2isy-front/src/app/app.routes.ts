import { Routes } from '@angular/router';
import { Login } from './features/login/login';
import { Dashboard } from './features/dashboard/dashboard';
import { authGrd, superAdminGrd } from './guards/auth.grd';
import { Clients } from './features/clients/clients';
import { Licences } from './features/licences/licences';
import { Settings } from './features/settings/settings';
import { Apps } from './features/apps/apps';
import { Profils } from './features/profils/profils';
import { Logs } from './features/logs/logs';

export const routes: Routes = [
  { path: 'login', component: Login },
  
  // Routes protégées par le Guard
  { path: 'dashboard', component: Dashboard, canActivate: [authGrd] },
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
  { path: 'clients', component: Clients, canActivate: [authGrd, superAdminGrd] },
  { path: 'licences', component: Licences, canActivate: [authGrd] },
  { path: 'settings', component: Settings, canActivate: [authGrd] },
  { path: 'apps', component: Apps , canActivate: [authGrd]},
  { path: 'profils', component: Profils, canActivate: [authGrd] },
  { path: 'logs', component: Logs, canActivate: [authGrd] },
  {
    path: 'alerts',
    loadComponent: () => import('./features/alerts/alerts').then((m) => m.AlertsComponent),
    canActivate: [authGrd],
  },
  
  { path: '', redirectTo: '/login', pathMatch: 'full' },
  { path: '**', redirectTo: '/login' }
];
