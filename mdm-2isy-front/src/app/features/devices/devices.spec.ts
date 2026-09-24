import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { environment } from '../../../environments/environment';
import { Devices } from './devices';

describe('Devices', () => {
  let fixture: ComponentFixture<Devices>;
  let component: Devices;
  let httpMock: HttpTestingController;

  beforeEach(async () => {
    localStorage.clear();
    await TestBed.configureTestingModule({
      imports: [Devices],
      providers: [provideHttpClient(), provideHttpClientTesting()],
    }).compileComponents();

    fixture = TestBed.createComponent(Devices);
    component = fixture.componentInstance;
    httpMock = TestBed.inject(HttpTestingController);
    fixture.detectChanges();
  });

  afterEach(() => {
    httpMock.verify();
    localStorage.clear();
  });

  function flushInitialContext(terminals: unknown[] = []): void {
    httpMock
      .expectOne(`${environment.apiUrl}/terminals`)
      .flush({ success: true, data: terminals });
    httpMock
      .expectOne(`${environment.apiUrl}/device-groups`)
      .flush({ success: true, data: [] });
    httpMock
      .expectOne(`${environment.apiUrl}/device-enrollments`)
      .flush({ success: true, data: [] });
    httpMock
      .expectOne(`${environment.apiUrl}/profils`)
      .flush([]);
  }

  it('keeps missing device information empty instead of inventing values', () => {
    flushInitialContext([
      {
        id: 1,
        imei: '123456789012345',
        connectivity_status: 'online',
        storage_total_mb: 1000,
        storage_free_mb: 250,
      },
    ]);

    expect(component.terminaux[0].imei).toBe('123456789012345');
    expect(component.terminalSerial(component.terminaux[0])).toBe('—');
    expect(component.terminalModel(component.terminaux[0])).toBe('—');
    expect(component.terminalAndroidVersion(component.terminaux[0])).toBe('—');
    expect(component.terminalStatus(component.terminaux[0])).toBe('En ligne');
    expect(component.terminalStorage(component.terminaux[0])).toBe(75);
    expect(component.fleetStats).toEqual({ total: 1, online: 1, offline: 0, lowBattery: 0 });
  });

  it('recalculates every fleet statistic from the loaded organization', () => {
    flushInitialContext([
      { id: 1, connectivity_status: 'online', batterie: 18 },
      { id: 2, connectivity_status: 'offline', batterie: 75 },
      { id: 3, connectivity_status: 'offline', batterie: null },
    ]);

    expect(component.fleetStats).toEqual({ total: 3, online: 1, offline: 2, lowBattery: 1 });
  });

  it('generates an enrollment without asking for hardware information', () => {
    flushInitialContext();
    component.enrollmentForm = {
      label: 'Livreur 24',
      device_group_id: null,
      expires_in_minutes: 60,
    };

    component.genererEnrollment();

    const request = httpMock.expectOne(`${environment.apiUrl}/device-enrollments`);
    expect(request.request.body).toEqual({ label: 'Livreur 24', expires_in_minutes: 60 });
    request.flush({
      success: true,
      data: {
        public_id: 'bef6aa2a-142f-452e-9e78-7d6595f947af',
        id: 4,
        organization_id: 1,
        label: 'Livreur 24',
        expires_at: '2026-08-08T12:00:00Z',
        enrollment_token: 'one-time-token',
        enrollment_payload: { api_url: 'https://mdm.example/api' },
      },
    });
    httpMock
      .expectOne(`${environment.apiUrl}/device-enrollments`)
      .flush({ success: true, data: [] });

    expect(component.generatedEnrollment?.enrollment_token).toBe('one-time-token');
  });

  it('keeps commands available offline only for enrolled, non-wiped terminals', () => {
    flushInitialContext([
      {
        id: 1,
        public_id: 'offline-enrolled',
        enrollment_status: 'enrolled',
        connectivity_status: 'offline',
        management_state: 'active',
        lic: { statut: 'Active' },
      },
      {
        id: 2,
        public_id: 'not-enrolled',
        enrollment_status: 'pending',
        connectivity_status: 'online',
        management_state: 'active',
      },
      {
        id: 3,
        public_id: 'already-wiped',
        enrollment_status: 'enrolled',
        connectivity_status: 'offline',
        management_state: 'wiped',
      },
    ]);

    expect(component.canSendCommand(component.terminaux[0])).toBe(true);
    expect(component.canSendCommand(component.terminaux[1])).toBe(false);
    expect(component.canSendCommand(component.terminaux[2])).toBe(false);
    expect(component.terminalStatus(component.terminaux[0])).toBe('Hors ligne');
    expect(component.terminalManagementState(component.terminaux[2])).toBe('Effacé');
  });

  it('queues a generic locate command and keeps it in recent history', () => {
    flushInitialContext([
      {
        id: 1,
        public_id: 'terminal-public-id',
        enrollment_status: 'enrolled',
        connectivity_status: 'offline',
        management_state: 'active',
        lic: { statut: 'Active' },
      },
    ]);
    const terminal = component.terminaux[0];

    component.envoyerCommande(terminal, 'locate');

    const request = httpMock.expectOne(
      `${environment.apiUrl}/terminals/terminal-public-id/commands`,
    );
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({ type: 'locate' });
    expect(request.request.headers.get('Idempotency-Key')).toBeTruthy();
    request.flush({
      success: true,
      message: 'Commande mise en file.',
      data: {
        public_id: 'command-public-id',
        type: 'locate',
        status: 'queued',
        created_at: '2026-08-08T12:00:00Z',
      },
    });

    expect(component.commandFeedbackFor(terminal)?.message).toBe('Commande mise en file.');
    expect(component.commandHistory(terminal)[0].public_id).toBe('command-public-id');
  });

  it('requires the exact public id and current password in the wipe modal', () => {
    flushInitialContext([
      {
        id: 1,
        public_id: 'terminal-public-id',
        enrollment_status: 'enrolled',
        connectivity_status: 'online',
        management_state: 'active',
        lic: { statut: 'Active' },
      },
    ]);
    const terminal = component.terminaux[0];

    component.ouvrirModalWipe(terminal);
    component.wipeConfirmation = 'wrong-id';
    component.wipePassword = 'secret';
    expect(component.canConfirmWipe()).toBe(false);

    component.wipeConfirmation = 'terminal-public-id';
    expect(component.canConfirmWipe()).toBe(true);
    component.confirmerWipe();

    const request = httpMock.expectOne(
      `${environment.apiUrl}/terminals/terminal-public-id/commands`,
    );
    expect(request.request.method).toBe('POST');
    expect(request.request.headers.get('Idempotency-Key')).toBeTruthy();
    expect(request.request.body).toEqual({
      type: 'wipe',
      confirmation: 'terminal-public-id',
      current_password: 'secret',
    });
    request.flush({
      success: true,
      message: "Commande d'effacement mise en file.",
      data: { public_id: 'wipe-command', type: 'wipe', status: 'queued' },
    });

    expect(component.afficherModalWipe).toBe(false);
    expect(component.wipePassword).toBe('');
    expect(component.commandFeedbackFor(terminal)?.kind).toBe('success');
  });

  it('clears the pending state after a successful uninstall request', () => {
    flushInitialContext([
      {
        id: 1,
        public_id: 'terminal-public-id',
        enrollment_status: 'enrolled',
        connectivity_status: 'online',
        management_state: 'active',
        lic: { statut: 'Active' },
      },
    ]);
    const terminal = component.terminaux[0];

    component.ouvrirModalUninstall(terminal);
    component.uninstallPackage = 'com.example.legacy';
    component.confirmerUninstall();

    expect(component.isCommandSubmitting(terminal)).toBe(true);
    const request = httpMock.expectOne(`${environment.apiUrl}/terminals/1/uninstall-app`);
    expect(request.request.body).toEqual({
      payload: { packageName: 'com.example.legacy' },
    });
    request.flush({ success: true, message: 'Commande mise en file.' });

    expect(component.isCommandSubmitting(terminal)).toBe(false);
    expect(component.afficherModalUninstall).toBe(false);
    expect(component.commandFeedbackFor(terminal)?.kind).toBe('success');
  });

  it('assigns a numeric profile and keeps the returned profile on the terminal', () => {
    flushInitialContext([{ id: 8, profil_id: null, connectivity_status: 'online' }]);
    const terminal = component.terminaux[0];

    component.modifierProfil(terminal, 12);

    const request = httpMock.expectOne(`${environment.apiUrl}/terminals/8/profil`);
    expect(request.request.method).toBe('PUT');
    expect(request.request.body).toEqual({ profil_id: 12 });
    request.flush({
      success: true,
      message: 'Profil mis à jour et synchronisation demandée.',
      data: { ...terminal, profil_id: 12, profil: { id: 12, nom: 'Livreur strict' } },
    });

    expect(component.terminaux[0].profil_id).toBe(12);
    expect(component.profileUpdating[8]).toBe(false);
    expect(component.profileFeedback[8]).toContain('Profil appliqué');
  });
});
