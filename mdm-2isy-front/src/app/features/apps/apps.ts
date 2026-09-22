import { Component, OnInit, ChangeDetectorRef } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { AppService } from '../../services/app';
import { Auth } from '../../services/auth';
import { OrganizationService } from '../../services/organization';
import { Organization } from '../../models/fleet.models';
import { TermService } from '../../services/term';
import { Terminal } from '../../models/fleet.models';
import { finalize } from 'rxjs';

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
  isSuperAdmin = false;
  organizations: Organization[] = [];
  selectedOrganizationId: number | null = null;
  terminals: Terminal[] = [];
  deployApp: any | null = null;
  deployTerminalIds: number[] = [];
  deploySubmitting = false;
  deployNotice = '';
  deployError = '';
  editingApp: any | null = null;
  
  nvApp = { nom: '', pkg: '', type: 'blanche', ver: '' };
  ficApk: File | null = null;

  constructor(private appSvc: AppService, private termSvc: TermService, private auth: Auth, private organizationSvc: OrganizationService, private cdRef: ChangeDetectorRef) {}

  ngOnInit() {
    this.isSuperAdmin = this.auth.role === 'super_admin';
    this.selectedOrganizationId = this.auth.user?.organization_id ?? null;
    if (this.isSuperAdmin) {
      this.organizationSvc.getAll().subscribe(res => this.organizations = res.data);
    }
    this.getApps();
    this.getTerminals();
  }

  getTerminals() {
    this.termSvc.getAll(this.selectedOrganizationId ?? undefined).subscribe({
      next: res => this.terminals = res.data,
      error: () => this.terminals = [],
    });
  }

  onOrganizationChange() {
    this.getApps();
    this.getTerminals();
  }

  ouvrirDeploiement(app: any) {
    if (!app.chemin_apk) return alert("Ajoutez d’abord un fichier APK à cette application.");
    this.deployApp = app;
    this.deployTerminalIds = [];
    this.deployNotice = '';
    this.deployError = '';
  }

  fermerDeploiement() {
    if (this.deploySubmitting) return;
    this.deployApp = null;
    this.deployTerminalIds = [];
  }

  toggleTerminal(terminalId: number, checked: boolean) {
    this.deployTerminalIds = checked
      ? [...this.deployTerminalIds, terminalId]
      : this.deployTerminalIds.filter(id => id !== terminalId);
  }

  pousserApp() {
    if (!this.deployApp || this.deployTerminalIds.length === 0) return;
    this.deploySubmitting = true;
    this.deployError = '';
    this.appSvc.deploy(this.deployApp.id, this.deployTerminalIds).pipe(
      finalize(() => {
        this.deploySubmitting = false;
        this.cdRef.detectChanges();
      }),
    ).subscribe({
      next: res => {
        this.deployNotice = res.message || "Commande d’installation mise en file.";
        this.deployApp = null;
        this.deployTerminalIds = [];
      },
      error: err => {
        this.deployError = err?.error?.message
          || err?.error?.errors?.terminal?.[0]
          || "Impossible d’envoyer la commande d’installation.";
      },
    });
  }

  getApps() {
    this.load = true;
    this.appSvc.getAll(this.selectedOrganizationId).subscribe({
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

  ouvrirModal() {
    this.editingApp = null;
    this.nvApp = { nom: '', pkg: '', type: 'blanche', ver: '' };
    this.showModal = true;
  }

  ouvrirEdition(app: any) {
    this.editingApp = app;
    this.nvApp = { nom: app.nom, pkg: app.pkg, type: app.type, ver: app.ver || '' };
    this.ficApk = null;
    this.showModal = true;
  }
  
  fermerModal() { 
    this.showModal = false;
    this.editingApp = null;
    this.nvApp = { nom: '', pkg: '', type: 'blanche', ver: '' };
    this.ficApk = null;
  }

  sauverApp() {
    if (!this.nvApp.nom || !this.nvApp.pkg) return alert('Remplissez les champs obligatoires.');
    if (this.isSuperAdmin && !this.selectedOrganizationId) return alert('Sélectionnez d’abord une organisation.');
    
    // --- CORRECTION : Construction de l'objet FormData ---
    const fd = new FormData();
    fd.append('nom', this.nvApp.nom);
    fd.append('pkg', this.nvApp.pkg);
    fd.append('type', this.nvApp.type);
    fd.append('ver', this.nvApp.ver);
    if (this.selectedOrganizationId) fd.append('organization_id', String(this.selectedOrganizationId));
    
    // Si un fichier APK a été sélectionné, on l'ajoute au FormData
    if (this.ficApk) {
      fd.append('chemin_apk', this.ficApk);
    }

    // On envoie le FormData (fd) au lieu de l'objet JSON (this.nvApp)
    const request = this.editingApp
      ? this.appSvc.update(this.editingApp.id, fd)
      : this.appSvc.add(fd);
    request.subscribe({
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
