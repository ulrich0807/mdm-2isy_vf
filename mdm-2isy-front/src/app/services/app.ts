import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../environments/environment';

@Injectable({
  providedIn: 'root'
})
export class AppService {
  private readonly url = `${environment.apiUrl}/apps`;

  constructor(private http: HttpClient) {}

  getAll(): Observable<any> {
    return this.http.get(this.url);
  }

  // Utilisation de FormData pour pouvoir envoyer des fichiers (.apk)
  add(data: FormData): Observable<any> {
    return this.http.post(this.url, data);
  }

  del(id: number): Observable<any> {
    return this.http.delete(`${this.url}/${id}`);
  }
}
