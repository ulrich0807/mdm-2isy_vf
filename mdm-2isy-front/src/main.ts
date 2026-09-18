import { bootstrapApplication } from '@angular/platform-browser';
import { provideRouter } from '@angular/router';
import { provideHttpClient, withInterceptors } from '@angular/common/http'; // <-- Ajout de l'import
import { App } from './app/app';
import { routes } from './app/app.routes';
import { authInt } from './app/interceptors/auth.interceptor';

bootstrapApplication(App, {
  providers: [
    provideRouter(routes),
provideHttpClient(withInterceptors([authInt]))  ]
}).catch(err => console.error(err));