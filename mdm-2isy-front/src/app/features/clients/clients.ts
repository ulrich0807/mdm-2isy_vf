import { Component, OnInit, ChangeDetectorRef } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { UsrService } from '../../services/usr';
import { ClientUser, CreateClientPayload } from '../../models/user.models';

@Component({
  selector: 'app-clients',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './clients.html'
})
export class Clients implements OnInit {
  clients: ClientUser[] = [];
  load: boolean = true;
  
  showModal: boolean = false;
  nvClient: CreateClientPayload = { name: '', organization_name: '', email: '', password: '' };

  constructor(private usrSvc: UsrService, private cdRef: ChangeDetectorRef) {}

  ngOnInit() {
    this.getClients();
  }

  getClients() {
    this.usrSvc.getAll().subscribe({
      next: (res: any) => {
        if (res && res.success) {
          this.clients = res.data;
        }
        this.load = false;
        this.cdRef.detectChanges();
      },
      error: (err: any) => {
        console.error('Erreur de chargement', err);
        this.load = false;
        this.cdRef.detectChanges();
      }
    });
  }

  ouvrirModal() {
    this.showModal = true;
  }

  fermerModal() {
    this.showModal = false;
    this.nvClient = { name: '', organization_name: '', email: '', password: '' };
  }

  ajouterClient() {
    if (!this.nvClient.name || !this.nvClient.organization_name || !this.nvClient.email || !this.nvClient.password) {
      alert("Veuillez remplir tous les champs.");
      return;
    }
    if (this.nvClient.password.length < 12) {
      alert('Le mot de passe provisoire doit contenir au moins 12 caractères.');
      return;
    }
    if (!/[a-z]/.test(this.nvClient.password) || !/[A-Z]/.test(this.nvClient.password) || !/\d/.test(this.nvClient.password)) {
      alert('Le mot de passe doit contenir une majuscule, une minuscule et un chiffre.');
      return;
    }

    this.usrSvc.add(this.nvClient).subscribe({
      next: (res: any) => {
        if (res.success) {
          alert('✅ ' + res.message);
          this.fermerModal();
          this.getClients(); // Rafraîchit la liste
        }
      },
      error: (err) => {
        alert("Erreur lors de l'ajout. L'email est peut-être déjà utilisé.");
        console.error(err);
      }
    });
  }
}
