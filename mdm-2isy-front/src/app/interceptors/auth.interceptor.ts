import { inject } from '@angular/core';
import { HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { Router } from '@angular/router';
import { catchError, throwError } from 'rxjs';
import { Auth } from '../services/auth';
import { environment } from '../../environments/environment';

export const authInt: HttpInterceptorFn = (req, next) => {
  const auth = inject(Auth);
  const router = inject(Router);
  const tok = auth.token;
  const isApiRequest = req.url === environment.apiUrl || req.url.startsWith(`${environment.apiUrl}/`);
  const isLoginRequest = req.url.split('?', 1)[0] === `${environment.apiUrl}/auth/in`;
  const request = tok && isApiRequest
    ? req.clone({
        setHeaders: {
          Authorization: `Bearer ${tok}`,
        },
      })
    : req;

  return next(request).pipe(
    catchError((error: unknown) => {
      if (
        error instanceof HttpErrorResponse
        && error.status === 401
        && isApiRequest
        && !isLoginRequest
      ) {
        const currentUrl = router.url || '/';
        const currentPath = currentUrl.split(/[?#]/, 1)[0];
        auth.clearSession();

        if (currentPath !== '/login') {
          const queryParams = currentPath === '/' ? undefined : { returnUrl: currentUrl };
          void router.navigate(['/login'], { queryParams });
        }
      }

      return throwError(() => error);
    }),
  );
};
