import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../environments/environment';
import { ApiResponse } from '../models/fleet.models';
import { ClientUser, CreateClientPayload } from '../models/user.models';

@Injectable({
  providedIn: 'root'
})
export class UsrService {
  private readonly apiUrl = `${environment.apiUrl}/users`;

  constructor(private http: HttpClient) {}

  getAll(): Observable<ApiResponse<ClientUser[]>> {
    return this.http.get<ApiResponse<ClientUser[]>>(this.apiUrl);
  }

  add(data: CreateClientPayload): Observable<ApiResponse<ClientUser>> {
    return this.http.post<ApiResponse<ClientUser>>(this.apiUrl, data);
  }
  updProf(data: any): Observable<any> {
    return this.http.put(`${this.apiUrl}/prof`, data);
  }

  updPwd(data: any): Observable<any> {
    return this.http.put(`${this.apiUrl}/pwd`, data);
  }
}
