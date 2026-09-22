import { HttpClient, provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import { vi } from 'vitest';
import { environment } from '../../environments/environment';
import { Auth } from '../services/auth';
import { authInt } from './auth.interceptor';

describe('authInt', () => {
  let http: HttpClient;
  let httpMock: HttpTestingController;
  let auth: Auth;
  let router: Router;

  beforeEach(() => {
    localStorage.setItem('mdm_session', JSON.stringify({
      token: 'token-123',
      user: {
        name: 'MDM Admin',
        email: 'admin@mdm.test',
        role: 'admin',
        organization_id: 7,
        organization: null,
      },
    }));

    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        provideHttpClient(withInterceptors([authInt])),
        provideHttpClientTesting(),
      ],
    });

    http = TestBed.inject(HttpClient);
    httpMock = TestBed.inject(HttpTestingController);
    auth = TestBed.inject(Auth);
    router = TestBed.inject(Router);
  });

  afterEach(() => {
    httpMock.verify();
    localStorage.clear();
  });

  it('clears an expired API session and redirects to login', () => {
    const navigate = vi.spyOn(router, 'navigate').mockResolvedValue(true);

    http.get(`${environment.apiUrl}/terminals`).subscribe({
      error: () => undefined,
    });

    const request = httpMock.expectOne(`${environment.apiUrl}/terminals`);
    expect(request.request.headers.get('Authorization')).toBe('Bearer token-123');
    request.flush(
      { message: 'Unauthenticated.' },
      { status: 401, statusText: 'Unauthorized' },
    );

    expect(auth.estConnecte()).toBe(false);
    expect(localStorage.getItem('mdm_session')).toBeNull();
    expect(navigate).toHaveBeenCalledWith(['/login'], expect.any(Object));
  });

  it('does not clear the session when the login endpoint rejects credentials', () => {
    http.post(`${environment.apiUrl}/auth/in`, {}).subscribe({
      error: () => undefined,
    });

    httpMock.expectOne(`${environment.apiUrl}/auth/in`).flush(
      { message: 'Identifiants incorrects.' },
      { status: 401, statusText: 'Unauthorized' },
    );

    expect(auth.estConnecte()).toBe(true);
  });
});
