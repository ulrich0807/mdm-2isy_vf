import { Component, ChangeDetectorRef } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ActivatedRoute, Router } from '@angular/router';
import { FormsModule } from '@angular/forms'; // Nécessaire pour [(ngModel)]
import { Auth } from '../../services/auth';

@Component({
  selector: 'app-login',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './login.html'
})
export class Login {
  email: string = '';
  motDePasse: string = '';
  erreur: string = '';
  chargement: boolean = false;

  constructor(
    private router: Router,
    private route: ActivatedRoute,
    private authService: Auth,
    private cdr: ChangeDetectorRef
  ) {}

  seConnecter(event: Event) {
    event.preventDefault();
    this.erreur = '';
    this.chargement = true;

    // Appel à l'API Laravel via notre service
    this.authService.login(this.email, this.motDePasse).subscribe({
      next: (res: any) => {
        this.chargement = false;
        this.router.navigateByUrl(this.safeReturnUrl());
      },
      error: (err: any) => {
        this.chargement = false;
        // Si Laravel refuse (ex: mauvais mot de passe)
        this.erreur = 'Identifiants incorrects. Veuillez réessayer.';
        this.cdr.detectChanges(); // Force la mise à jour de l'UI
      }
    });
  }

  private safeReturnUrl(): string {
    const returnUrl = this.route.snapshot.queryParamMap.get('returnUrl');
    if (!returnUrl || !returnUrl.startsWith('/') || returnUrl.startsWith('//')) {
      return '/dashboard';
    }

    const targetPath = returnUrl.split(/[?#]/, 1)[0];
    return targetPath === '/' || targetPath === '/login' ? '/dashboard' : returnUrl;
  }
}
