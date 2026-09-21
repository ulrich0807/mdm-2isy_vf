import { Injectable } from '@angular/core';
import { HttpClient, HttpHeaders, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../environments/environment';
import {
  ApiResponse,
  CreateDeviceCommandPayload,
  DeviceCommand,
  Terminal,
} from '../models/fleet.models';

@Injectable({
  providedIn: 'root'
})
export class TermService {
  private readonly apiUrl = `${environment.apiUrl}/terminals`;

  constructor(private http: HttpClient) {}

  // Récupérer la flotte autorisée, avec un filtre d'organisation pour le super admin.
  getAll(organizationId?: number): Observable<ApiResponse<Terminal[]>> {
    const params = organizationId === undefined
      ? new HttpParams()
      : new HttpParams().set('organization_id', organizationId);

    return this.http.get<ApiResponse<Terminal[]>>(this.apiUrl, { params });
  }

  del(id: number): Observable<ApiResponse<void>> {
    return this.http.delete<ApiResponse<void>>(`${this.apiUrl}/${id}`);
  }

  updateGroup(id: number, deviceGroupId: number | null): Observable<ApiResponse<Terminal>> {
    return this.http.put<ApiResponse<Terminal>>(`${this.apiUrl}/${id}/group`, {
      device_group_id: deviceGroupId,
    });
  }

  updateLivreur(id: number, livreur: string): Observable<ApiResponse<Terminal>> {
    return this.http.put<ApiResponse<Terminal>>(`${this.apiUrl}/${id}/livreur`, {
      livreur: livreur,
    });
  }

  updateProfil(id: number, profil_id: number | null): Observable<ApiResponse<Terminal>> {
    return this.http.put<ApiResponse<Terminal>>(`${this.apiUrl}/${id}/profil`, {
      profil_id: profil_id,
    });
  }

  getCommands(terminalPublicId: string): Observable<ApiResponse<DeviceCommand[]>> {
    return this.http.get<ApiResponse<DeviceCommand[]>>(
      `${this.apiUrl}/${encodeURIComponent(terminalPublicId)}/commands`,
    );
  }

  createCommand(
    terminalPublicId: string,
    payload: CreateDeviceCommandPayload,
    idempotencyKey: string,
  ): Observable<ApiResponse<DeviceCommand>> {
    const headers = new HttpHeaders({ 'Idempotency-Key': idempotencyKey });
    return this.http.post<ApiResponse<DeviceCommand>>(
      `${this.apiUrl}/${encodeURIComponent(terminalPublicId)}/commands`,
      payload,
      { headers },
    );
  }

  uninstallApp(id: number, payload: { packageName: string }): Observable<ApiResponse<void>> {
    return this.http.post<ApiResponse<void>>(`${this.apiUrl}/${id}/uninstall-app`, {
      payload: payload
    });
  }

  getLocationHistory(id: number, hours: number = 24): Observable<ApiResponse<{lat: number, lng: number, recorded_at: string}[]>> {
    const params = new HttpParams().set('hours', hours.toString());
    return this.http.get<ApiResponse<{lat: number, lng: number, recorded_at: string}[]>>(`${this.apiUrl}/${id}/history`, { params });
  }
}
