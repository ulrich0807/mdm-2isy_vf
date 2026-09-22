import { Injectable } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../environments/environment';

@Injectable({
  providedIn: 'root'
})
export class LicService {
  private readonly apiUrl = `${environment.apiUrl}/lics`;

  constructor(private http: HttpClient) {}

  // Récupérer la liste des licences
  list(organizationId?: number | null): Observable<any> {
    const params = organizationId ? new HttpParams().set('organization_id', organizationId) : undefined;
    return this.http.get(this.apiUrl, { params });
  }

  // Générer une nouvelle licence (Réservé au Super Admin)
  gen(organizationId: number): Observable<any> {
    return this.http.post(this.apiUrl, { organization_id: organizationId });
  }

  // Activer une licence sur un terminal
  actv(id: number): Observable<any> {
    return this.http.post(`${this.apiUrl}/${id}/actv`, {});
  }

  // Vérifier l'état d'une licence
  chk(id: number): Observable<any> {
    return this.http.get(`${this.apiUrl}/${id}/chk`);
  }

  assign(id: number, term_id: number | null): Observable<any> {
    return this.http.post(`${this.apiUrl}/${id}/assign`, { term_id });
  }
}
