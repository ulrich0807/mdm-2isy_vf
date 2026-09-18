import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../environments/environment';

@Injectable({
  providedIn: 'root'
})
export class ProfService {
  private readonly url = `${environment.apiUrl}/profils`;

  constructor(private http: HttpClient) {}

  getAll(): Observable<any> {
    return this.http.get(this.url);
  }

  add(data: any): Observable<any> {
    return this.http.post(this.url, data);
  }

  del(id: number): Observable<any> {
    return this.http.delete(`${this.url}/${id}`);
  }
}
