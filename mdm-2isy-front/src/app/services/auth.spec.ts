import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { environment } from '../../environments/environment';
import { Auth } from './auth';

describe('Auth', () => {
  let service: Auth;
  let httpMock: HttpTestingController;

  beforeEach(() => {
    localStorage.clear();
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    service = TestBed.inject(Auth);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpMock.verify();
    localStorage.clear();
  });

  it('should be created', () => {
    expect(service).toBeTruthy();
  });

  it('stores one coherent session and exposes its user and role', () => {
    service.login('admin@mdm.test', 'secret').subscribe();

    const request = httpMock.expectOne(`${environment.apiUrl}/auth/in`);
    expect(request.request.method).toBe('POST');
    request.flush({
      success: true,
      tok: 'token-123',
      usr: {
        name: 'MDM Admin',
        email: 'admin@mdm.test',
        role: 'super_admin',
        organization_id: 7,
        organization: { id: 7, name: '2ISY' },
      },
    });

    expect(service.token).toBe('token-123');
    expect(service.user?.name).toBe('MDM Admin');
    expect(service.role).toBe('super_admin');
    expect(service.user?.organization?.name).toBe('2ISY');
    expect(JSON.parse(localStorage.getItem('mdm_session') ?? '{}').token).toBe('token-123');
  });

  it('revokes the token and clears the local session on logout', () => {
    service.login('admin@mdm.test', 'secret').subscribe();
    httpMock.expectOne(`${environment.apiUrl}/auth/in`).flush({
      success: true,
      tok: 'token-123',
      usr: { name: 'MDM Admin', email: 'admin@mdm.test', role: 'admin' },
    });

    service.logout().subscribe();

    const request = httpMock.expectOne(`${environment.apiUrl}/auth/out`);
    expect(request.request.method).toBe('POST');
    request.flush({ success: true });

    expect(service.estConnecte()).toBe(false);
    expect(localStorage.getItem('mdm_session')).toBeNull();
  });
});
