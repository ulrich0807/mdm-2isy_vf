import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable } from '@angular/core';
import { Observable } from 'rxjs';
import { environment } from '../../environments/environment';
import { ApiResponse, CreateDeviceGroupPayload, DeviceGroup } from '../models/fleet.models';

@Injectable({ providedIn: 'root' })
export class DeviceGroupService {
  private readonly url = `${environment.apiUrl}/device-groups`;

  constructor(private http: HttpClient) {}

  getAll(organizationId?: number): Observable<ApiResponse<DeviceGroup[]>> {
    return this.http.get<ApiResponse<DeviceGroup[]>>(this.url, {
      params: this.organizationParams(organizationId),
    });
  }

  add(payload: CreateDeviceGroupPayload): Observable<ApiResponse<DeviceGroup>> {
    return this.http.post<ApiResponse<DeviceGroup>>(this.url, payload);
  }

  del(id: number, organizationId?: number): Observable<ApiResponse<null>> {
    return this.http.delete<ApiResponse<null>>(`${this.url}/${id}`, {
      params: this.organizationParams(organizationId),
    });
  }

  private organizationParams(organizationId?: number): HttpParams {
    return organizationId === undefined
      ? new HttpParams()
      : new HttpParams().set('organization_id', organizationId);
  }
}
