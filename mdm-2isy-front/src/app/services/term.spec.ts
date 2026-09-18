import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { environment } from '../../environments/environment';
import { TermService } from './term';

describe('TermService', () => {
  let service: TermService;
  let httpMock: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    service = TestBed.inject(TermService);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => httpMock.verify());

  it('should be created', () => {
    expect(service).toBeTruthy();
  });

  it('scopes the terminal list to the selected organization', () => {
    service.getAll(12).subscribe();

    const request = httpMock.expectOne(`${environment.apiUrl}/terminals?organization_id=12`);
    expect(request.request.method).toBe('GET');
    request.flush({ success: true, data: [] });
  });

  it('updates a terminal group without changing its organization', () => {
    service.updateGroup(7, 3).subscribe();

    const request = httpMock.expectOne(`${environment.apiUrl}/terminals/7/group`);
    expect(request.request.method).toBe('PUT');
    expect(request.request.body).toEqual({ device_group_id: 3 });
    request.flush({ success: true, data: { id: 7, device_group_id: 3 } });
  });

  it('lists commands through the canonical public terminal identifier', () => {
    service.getCommands('terminal-public-id').subscribe();

    const request = httpMock.expectOne(
      `${environment.apiUrl}/terminals/terminal-public-id/commands`,
    );
    expect(request.request.method).toBe('GET');
    request.flush({ success: true, data: [] });
  });

  it('creates a generic command with an idempotency key', () => {
    service
      .createCommand(
        'terminal-public-id',
        {
          type: 'wipe',
          confirmation: 'terminal-public-id',
          current_password: 'secret',
        },
        'idem-123',
      )
      .subscribe();

    const request = httpMock.expectOne(
      `${environment.apiUrl}/terminals/terminal-public-id/commands`,
    );
    expect(request.request.method).toBe('POST');
    expect(request.request.headers.get('Idempotency-Key')).toBe('idem-123');
    expect(request.request.body).toEqual({
      type: 'wipe',
      confirmation: 'terminal-public-id',
      current_password: 'secret',
    });
    request.flush({
      success: true,
      data: { public_id: 'command-public-id', type: 'wipe', status: 'queued' },
    });
  });
});
