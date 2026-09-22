import { Injectable } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { Alert, ApiResponse } from '../models/fleet.models';
import { environment } from '../../environments/environment';

@Injectable({
  providedIn: 'root'
})
export class AlertService {
  private apiUrl = `${environment.apiUrl}/alerts`;

  constructor(private http: HttpClient) {}

  getAlerts(status?: 'active' | 'resolved', organizationId?: number | null): Observable<ApiResponse<Alert[]>> {
    let params = new HttpParams();
    if (status) params = params.set('status', status);
    if (organizationId) params = params.set('organization_id', organizationId);
    return this.http.get<ApiResponse<Alert[]>>(this.apiUrl, { params });
  }

  resolveAlert(id: number): Observable<ApiResponse<null>> {
    return this.http.post<ApiResponse<null>>(`${this.apiUrl}/${id}/resolve`, {});
  }
}
