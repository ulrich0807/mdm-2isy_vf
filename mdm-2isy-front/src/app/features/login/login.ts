import { Component, ChangeDetectorRef } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ActivatedRoute, Router } from '@angular/router';
import { FormsModule } from '@angular/forms'; // Nécessaire pour [(ngModel)]
import { Auth } from '../../services/auth';
import { ContactRequestPayload, ContactRequestService } from '../../services/contact-request.service';

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
  afficherMotDePasse = false;
  afficherContact = false;
  envoiContact = false;
  contactErreur = '';
  contactSucces = '';
  contactForm: ContactRequestPayload = this.emptyContactForm();

  constructor(
    private router: Router,
    private route: ActivatedRoute,
    private authService: Auth,
    private cdr: ChangeDetectorRef,
    private contactService: ContactRequestService,
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

  basculerMotDePasse(): void {
    this.afficherMotDePasse = !this.afficherMotDePasse;
  }

  ouvrirContact(event?: Event): void {
    event?.preventDefault();
    this.contactErreur = '';
    this.contactSucces = '';
    this.afficherContact = true;
  }

  fermerContact(): void {
    if (!this.envoiContact) this.afficherContact = false;
  }

  envoyerContact(): void {
    if (this.envoiContact) return;
    this.envoiContact = true;
    this.contactErreur = '';
    this.contactSucces = '';
    this.contactService.submit({
      ...this.contactForm,
      name: this.contactForm.name.trim(),
      company: this.contactForm.company.trim(),
      email: this.contactForm.email.trim(),
      contact: this.contactForm.contact.trim(),
      issue: this.contactForm.issue.trim(),
    }).subscribe({
      next: (response) => {
        this.envoiContact = false;
        this.contactSucces = response.message || 'Votre message a bien été envoyé.';
        this.contactForm = this.emptyContactForm();
        this.cdr.detectChanges();
      },
      error: (error) => {
        this.envoiContact = false;
        const errors = error?.error?.errors;
        this.contactErreur = errors
          ? String(Object.values(errors).flat()[0] ?? 'Vérifiez les informations saisies.')
          : (error?.error?.message || "Impossible d'envoyer votre message pour le moment.");
        this.cdr.detectChanges();
      },
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

  private emptyContactForm(): ContactRequestPayload {
    return { name: '', company: '', issue: '', contact: '', email: '' };
  }
}
