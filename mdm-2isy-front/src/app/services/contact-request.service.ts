import { Injectable } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../environments/environment';
import { ApiResponse } from '../models/fleet.models';

export type ContactRequestStatus = 'new' | 'read' | 'resolved';

export interface ContactRequestPayload {
  name: string;
  company: string;
  issue: string;
  contact: string;
  email: string;
}

export interface ContactRequestItem extends ContactRequestPayload {
  id: number;
  public_id: string;
  status: ContactRequestStatus;
  read_at?: string | null;
  resolved_at?: string | null;
  created_at: string;
}

@Injectable({ providedIn: 'root' })
export class ContactRequestService {
  private readonly apiUrl = `${environment.apiUrl}/contact-requests`;

  constructor(private http: HttpClient) {}

  submit(payload: ContactRequestPayload): Observable<ApiResponse<{ public_id: string }>> {
    return this.http.post<ApiResponse<{ public_id: string }>>(this.apiUrl, payload);
  }

  getAll(status?: ContactRequestStatus): Observable<ApiResponse<ContactRequestItem[]>> {
    const params = status ? new HttpParams().set('status', status) : undefined;
    return this.http.get<ApiResponse<ContactRequestItem[]>>(this.apiUrl, { params });
  }

  setStatus(id: number, status: Exclude<ContactRequestStatus, 'new'>): Observable<ApiResponse<ContactRequestItem>> {
    return this.http.patch<ApiResponse<ContactRequestItem>>(`${this.apiUrl}/${id}`, { status });
  }
}
