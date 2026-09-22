import { Injectable } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../environments/environment';

@Injectable({
  providedIn: 'root'
})
export class AppService {
  private readonly url = `${environment.apiUrl}/apps`;

  constructor(private http: HttpClient) {}

  getAll(organizationId?: number | null): Observable<any> {
    const params = organizationId ? new HttpParams().set('organization_id', organizationId) : undefined;
    return this.http.get(this.url, { params });
  }

  // Utilisation de FormData pour pouvoir envoyer des fichiers (.apk)
  add(data: FormData): Observable<any> {
    return this.http.post(this.url, data);
  }

  update(id: number, data: FormData): Observable<any> {
    return this.http.post(`${this.url}/${id}/update`, data);
  }

  deploy(id: number, terminalIds: number[]): Observable<any> {
    return this.http.post(`${this.url}/${id}/deploy`, { terminal_ids: terminalIds });
  }

  del(id: number): Observable<any> {
    return this.http.delete(`${this.url}/${id}`);
  }
}
