import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../environments/environment';

@Injectable({
  providedIn: 'root'
})
export class LicService {
  private readonly apiUrl = `${environment.apiUrl}/lics`;

  constructor(private http: HttpClient) {}

  // Récupérer la liste des licences
  list(): Observable<any> {
    return this.http.get(this.apiUrl);
  }

  // Générer une nouvelle licence (Réservé au Super Admin)
  gen(): Observable<any> {
    return this.http.post(this.apiUrl, {});
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
