import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { environment } from '../../environments/environment';
import { DeviceEnrollmentService } from './device-enrollment';
import { DeviceGroupService } from './device-group';
import { OrganizationService } from './organization';

describe('Fleet services', () => {
  let httpMock: HttpTestingController;
  let organizations: OrganizationService;
  let groups: DeviceGroupService;
  let enrollments: DeviceEnrollmentService;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    httpMock = TestBed.inject(HttpTestingController);
    organizations = TestBed.inject(OrganizationService);
    groups = TestBed.inject(DeviceGroupService);
    enrollments = TestBed.inject(DeviceEnrollmentService);
  });

  afterEach(() => httpMock.verify());

  it('loads organizations', () => {
    organizations.getAll().subscribe();
    const request = httpMock.expectOne(`${environment.apiUrl}/organizations`);
    expect(request.request.method).toBe('GET');
    request.flush({ success: true, data: [{ id: 1, name: '2ISY' }] });
  });

  it('lists and creates groups in an organization', () => {
    groups.getAll(7).subscribe();
    httpMock
      .expectOne(`${environment.apiUrl}/device-groups?organization_id=7`)
      .flush({ success: true, data: [] });

    groups.add({ name: 'Zone Nord', organization_id: 7 }).subscribe();
    const request = httpMock.expectOne(`${environment.apiUrl}/device-groups`);
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({ name: 'Zone Nord', organization_id: 7 });
    request.flush({
      success: true,
      data: { id: 2, name: 'Zone Nord', organization_id: 7 },
    });
  });

  it('creates and revokes an enrollment', () => {
    enrollments
      .add({
        organization_id: 7,
        device_group_id: 2,
        label: 'Livreur 24',
        expires_in_minutes: 60,
      })
      .subscribe();

    const createRequest = httpMock.expectOne(`${environment.apiUrl}/device-enrollments`);
    expect(createRequest.request.method).toBe('POST');
    createRequest.flush({
      success: true,
      data: {
        public_id: 'bef6aa2a-142f-452e-9e78-7d6595f947af',
        id: 3,
        organization_id: 7,
        device_group_id: 2,
        label: 'Livreur 24',
        expires_at: '2026-08-08T12:00:00Z',
        enrollment_token: 'secret-token',
        enrollment_payload: { api_url: 'https://mdm.example/api' },
      },
    });

    enrollments.del('bef6aa2a-142f-452e-9e78-7d6595f947af', 7).subscribe();
    const deleteRequest = httpMock.expectOne(
      `${environment.apiUrl}/device-enrollments/bef6aa2a-142f-452e-9e78-7d6595f947af?organization_id=7`,
    );
    expect(deleteRequest.request.method).toBe('DELETE');
    deleteRequest.flush({ success: true, data: null });
  });
});
