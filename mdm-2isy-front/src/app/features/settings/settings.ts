import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router'; // <-- 1. Import du Router
import { UsrService } from '../../services/usr';
import { Auth } from '../../services/auth';

@Component({
  selector: 'app-settings',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './settings.html'
})
export class Settings implements OnInit {
  prof = { name: '', email: '' };
  pwd = { old_pwd: '', new_pwd: '', new_pwd_confirmation: '' };

  constructor(
    private usrSvc: UsrService,
    private router: Router, // <-- 2. Injection du Router
    private auth: Auth,
  ) {}

  ngOnInit() {
    const user = this.auth.user;
    this.prof.name = user?.name || '';
    this.prof.email = user?.email || '';
  }

  saveProf() {
    if (!this.prof.name || !this.prof.email) return alert('Veuillez remplir tous les champs.');

    this.usrSvc.updProf(this.prof).subscribe({
      next: (res: any) => {
        if (res.success) {
          alert('✅ ' + res.message);
          this.auth.updateUser({
            name: res.data.name,
            email: res.data.email,
          });
          window.location.reload();
        }
      },
      error: (err) => {
        alert("Erreur lors de la mise à jour. Cet email est peut-être déjà utilisé.");
        console.error(err);
      }
    });
  }

  savePwd() {
    if (this.pwd.new_pwd !== this.pwd.new_pwd_confirmation) {
      return alert("Les nouveaux mots de passe ne correspondent pas.");
    }
    if (this.pwd.new_pwd.length < 12) {
      return alert('Le nouveau mot de passe doit contenir au moins 12 caractères.');
    }
    if (!/[a-z]/.test(this.pwd.new_pwd) || !/[A-Z]/.test(this.pwd.new_pwd) || !/\d/.test(this.pwd.new_pwd)) {
      return alert('Le nouveau mot de passe doit contenir une majuscule, une minuscule et un chiffre.');
    }

    this.usrSvc.updPwd(this.pwd).subscribe({
      next: (res: any) => {
        if (res.success) {
          alert('✅ ' + res.message + '\n\nPour des raisons de sécurité, vous allez être déconnecté.');
          
          // 3. Révocation du token, nettoyage local et redirection
          this.auth.logout().subscribe({
            complete: () => this.router.navigate(['/login']),
          });
        }
      },
      error: (err) => {
        alert(err.error?.message || "Erreur de mise à jour du mot de passe.");
        console.error(err);
      }
    });
  }
}
