import { Injectable } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../environments/environment';

@Injectable({
  providedIn: 'root'
})
export class ProfService {
  private readonly url = `${environment.apiUrl}/profils`;

  constructor(private http: HttpClient) {}

  getAll(organizationId?: number | null): Observable<any> {
    const params = organizationId ? new HttpParams().set('organization_id', organizationId) : undefined;
    return this.http.get(this.url, { params });
  }

  add(data: any): Observable<any> {
    return this.http.post(this.url, data);
  }

  update(id: number, data: any): Observable<any> {
    return this.http.put(`${this.url}/${id}`, data);
  }

  del(id: number): Observable<any> {
    return this.http.delete(`${this.url}/${id}`);
  }
}
