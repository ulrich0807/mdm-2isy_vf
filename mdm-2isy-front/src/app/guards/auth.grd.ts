import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { Auth } from '../services/auth';

export const authGrd: CanActivateFn = (route, state) => {
  const auth = inject(Auth);
  const router = inject(Router);

  // Si le service confirme que l'admin est connecté (présence du token)
  if (auth.estConnecte()) {
    return true; // On laisse passer
  }

  return router.createUrlTree(['/login'], {
    queryParams: { returnUrl: state.url },
  });
};

export const superAdminGrd: CanActivateFn = () => {
  const auth = inject(Auth);
  const router = inject(Router);

  if (!auth.estConnecte()) {
    return router.createUrlTree(['/login']);
  }

  return auth.role === 'super_admin'
    ? true
    : router.createUrlTree(['/dashboard']);
};
