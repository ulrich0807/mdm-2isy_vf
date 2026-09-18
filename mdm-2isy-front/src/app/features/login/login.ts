import { Component, ChangeDetectorRef } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router } from '@angular/router';
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
        // Si Laravel valide, on fonce vers le Dashboard
        this.router.navigate(['/dashboard']);
      },
      error: (err: any) => {
        this.chargement = false;
        // Si Laravel refuse (ex: mauvais mot de passe)
        this.erreur = 'Identifiants incorrects. Veuillez réessayer.';
        this.cdr.detectChanges(); // Force la mise à jour de l'UI
      }
    });
  }
}