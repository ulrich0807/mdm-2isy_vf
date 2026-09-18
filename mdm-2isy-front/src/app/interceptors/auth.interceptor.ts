import { inject } from '@angular/core';
import { HttpInterceptorFn } from '@angular/common/http';
import { Auth } from '../services/auth';
import { environment } from '../../environments/environment';

export const authInt: HttpInterceptorFn = (req, next) => {
  const tok = inject(Auth).token;
  const isApiRequest = req.url === environment.apiUrl || req.url.startsWith(`${environment.apiUrl}/`);

  // Le token ne doit être transmis qu'à l'API MDM.
  if (tok && isApiRequest) {
    const clonedReq = req.clone({
      setHeaders: {
        Authorization: `Bearer ${tok}`
      }
    });
    // On laisse passer la requête modifiée
    return next(clonedReq);
  }

  // S'il n'y a pas de token (ex: page de login), on laisse passer la requête telle quelle
  return next(req);
};
