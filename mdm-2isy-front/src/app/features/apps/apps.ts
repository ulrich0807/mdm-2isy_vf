import { Component, OnInit, ChangeDetectorRef } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { AppService } from '../../services/app';

@Component({
  selector: 'app-apps',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './apps.html'
})
export class Apps implements OnInit {
  apps: any[] = [];
  load: boolean = true;
  showModal: boolean = false;
  
  nvApp = { nom: '', pkg: '', type: 'blanche' }; 
  ficApk: File | null = null;

  constructor(private appSvc: AppService, private cdRef: ChangeDetectorRef) {}

  ngOnInit() {
    this.getApps();
  }

  getApps() {
    this.load = true;
    this.appSvc.getAll().subscribe({
      next: (res: any) => {
        this.apps = res; // Laravel renvoie directement le tableau
        this.load = false;
        this.cdRef.detectChanges();
      },
      error: (err: any) => {
        console.error('Erreur Apps:', err);
        this.load = false;
      }
    });
  }

  onFileSel(evt: any) {
    this.ficApk = evt.target.files[0];
  }

  ouvrirModal() { this.showModal = true; }
  
  fermerModal() { 
    this.showModal = false;
    this.nvApp = { nom: '', pkg: '', type: 'blanche' };
    this.ficApk = null;
  }

  sauverApp() {
    if (!this.nvApp.nom || !this.nvApp.pkg) return alert('Remplissez les champs obligatoires.');
    
    // --- CORRECTION : Construction de l'objet FormData ---
    const fd = new FormData();
    fd.append('nom', this.nvApp.nom);
    fd.append('pkg', this.nvApp.pkg);
    fd.append('type', this.nvApp.type);
    
    // Si un fichier APK a été sélectionné, on l'ajoute au FormData
    if (this.ficApk) {
      fd.append('chemin_apk', this.ficApk);
    }

    // On envoie le FormData (fd) au lieu de l'objet JSON (this.nvApp)
    this.appSvc.add(fd).subscribe({
      next: (res: any) => {
        if(res.success) {
          alert(`✅ ${res.message}`);
          this.fermerModal();
          this.getApps(); // Recharge la liste
        }
      },
      error: (err) => console.error(err)
    });
  }

  delApp(id: number) {
    if(confirm("Supprimer cette règle/application ?")) {
      this.appSvc.del(id).subscribe((res: any) => {
        alert(`🗑️ ${res.message}`);
        this.getApps();
      });
    }
  }
}