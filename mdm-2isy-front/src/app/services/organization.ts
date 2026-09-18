import { HttpClient } from '@angular/common/http';
import { Injectable } from '@angular/core';
import { Observable } from 'rxjs';
import { environment } from '../../environments/environment';
import { ApiResponse, Organization } from '../models/fleet.models';

@Injectable({ providedIn: 'root' })
export class OrganizationService {
  private readonly url = `${environment.apiUrl}/organizations`;

  constructor(private http: HttpClient) {}

  getAll(): Observable<ApiResponse<Organization[]>> {
    return this.http.get<ApiResponse<Organization[]>>(this.url);
  }
}
