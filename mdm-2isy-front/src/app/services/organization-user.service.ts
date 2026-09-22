import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable } from '@angular/core';
import { Observable } from 'rxjs';
import { environment } from '../../environments/environment';
import { ApiResponse } from '../models/fleet.models';
import { ClientUser } from '../models/user.models';

@Injectable({ providedIn: 'root' })
export class OrganizationUserService {
  private readonly url = `${environment.apiUrl}/organization-users`;

  constructor(private http: HttpClient) {}

  getAll(organizationId?: number | null): Observable<ApiResponse<ClientUser[]>> {
    const params = organizationId ? new HttpParams().set('organization_id', organizationId) : undefined;
    return this.http.get<ApiResponse<ClientUser[]>>(this.url, { params });
  }

  add(payload: Record<string, unknown>): Observable<ApiResponse<ClientUser>> {
    return this.http.post<ApiResponse<ClientUser>>(this.url, payload);
  }

  remove(id: number): Observable<ApiResponse<null>> {
    return this.http.delete<ApiResponse<null>>(`${this.url}/${id}`);
  }
}
