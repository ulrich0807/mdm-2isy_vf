import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable } from '@angular/core';
import { Observable } from 'rxjs';
import { environment } from '../../environments/environment';
import {
  ApiResponse,
  CreatedEnrollment,
  CreateEnrollmentPayload,
  DeviceEnrollment,
} from '../models/fleet.models';

@Injectable({ providedIn: 'root' })
export class DeviceEnrollmentService {
  private readonly url = `${environment.apiUrl}/device-enrollments`;

  constructor(private http: HttpClient) {}

  getAll(organizationId?: number): Observable<ApiResponse<DeviceEnrollment[]>> {
    return this.http.get<ApiResponse<DeviceEnrollment[]>>(this.url, {
      params: this.organizationParams(organizationId),
    });
  }

  add(payload: CreateEnrollmentPayload): Observable<ApiResponse<CreatedEnrollment>> {
    return this.http.post<ApiResponse<CreatedEnrollment>>(this.url, payload);
  }

  del(publicId: string, organizationId?: number): Observable<ApiResponse<null>> {
    return this.http.delete<ApiResponse<null>>(`${this.url}/${encodeURIComponent(publicId)}`, {
      params: this.organizationParams(organizationId),
    });
  }

  private organizationParams(organizationId?: number): HttpParams {
    return organizationId === undefined
      ? new HttpParams()
      : new HttpParams().set('organization_id', organizationId);
  }
}
