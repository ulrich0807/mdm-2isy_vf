import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { BehaviorSubject, Observable, catchError, finalize, map, of, tap } from 'rxjs';
import { environment } from '../../environments/environment';
import { Organization } from '../models/fleet.models';

export interface AuthUser {
  name: string;
  email: string;
  role: string;
  organization_id: number | null;
  organization: Organization | null;
}

interface AuthSession {
  token: string;
  user: AuthUser;
}

interface LoginResponse {
  success: boolean;
  tok?: string;
  usr?: Partial<AuthUser>;
  role?: string;
}

@Injectable({
  providedIn: 'root',
})
export class Auth {
  private static readonly sessionKey = 'mdm_session';
  private readonly apiUrl = environment.apiUrl;
  private readonly sessionSubject = new BehaviorSubject<AuthSession | null>(this.readSession());

  readonly session$ = this.sessionSubject.asObservable();
  readonly user$ = this.session$.pipe(map((session) => session?.user ?? null));
  readonly role$ = this.session$.pipe(map((session) => session?.user.role ?? null));

  constructor(private http: HttpClient) {}

  get token(): string | null {
    return this.sessionSubject.value?.token ?? null;
  }

  get user(): AuthUser | null {
    return this.sessionSubject.value?.user ?? null;
  }

  get role(): string | null {
    return this.user?.role ?? null;
  }

  login(email: string, motDePasse: string): Observable<LoginResponse> {
    return this.http
      .post<LoginResponse>(`${this.apiUrl}/auth/in`, {
        email,
        password: motDePasse,
      })
      .pipe(
        tap((response) => {
          if (!response.success || !response.tok || !response.usr) {
            return;
          }

          const user: AuthUser = {
            name: response.usr.name ?? '',
            email: response.usr.email ?? email,
            role: response.usr.role ?? response.role ?? 'admin',
            organization_id: response.usr.organization_id ?? null,
            organization: response.usr.organization ?? null,
          };

          this.saveSession({ token: response.tok, user });
        }),
      );
  }

  estConnecte(): boolean {
    return Boolean(this.token);
  }

  updateUser(user: Partial<AuthUser>): void {
    const session = this.sessionSubject.value;
    if (!session) {
      return;
    }

    this.saveSession({
      ...session,
      user: { ...session.user, ...user },
    });
  }

  logout(): Observable<void> {
    const revokeToken$ = this.token
      ? this.http.post(`${this.apiUrl}/auth/out`, {}).pipe(
          map(() => undefined),
          catchError(() => of(undefined)),
        )
      : of(undefined);

    return revokeToken$.pipe(finalize(() => this.clearSession()));
  }

  private saveSession(session: AuthSession): void {
    if (typeof localStorage !== 'undefined') {
      localStorage.setItem(Auth.sessionKey, JSON.stringify(session));
    }
    this.sessionSubject.next(session);
  }

  clearSession(): void {
    if (typeof localStorage !== 'undefined') {
      localStorage.removeItem(Auth.sessionKey);
    }
    this.sessionSubject.next(null);
  }

  private readSession(): AuthSession | null {
    if (typeof localStorage === 'undefined') {
      return null;
    }

    const storedSession = localStorage.getItem(Auth.sessionKey);
    if (!storedSession) {
      return null;
    }

    try {
      const session = JSON.parse(storedSession) as AuthSession;
      if (!session.token || !session.user) {
        localStorage.removeItem(Auth.sessionKey);
        return null;
      }

      return {
        token: session.token,
        user: {
          name: session.user.name ?? '',
          email: session.user.email ?? '',
          role: session.user.role ?? 'admin',
          organization_id: session.user.organization_id ?? null,
          organization: session.user.organization ?? null,
        },
      };
    } catch {
      localStorage.removeItem(Auth.sessionKey);
      return null;
    }
  }
}
