import { Component, OnInit, ChangeDetectorRef } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { AppService } from '../../services/app';
import { Auth } from '../../services/auth';
import { OrganizationService } from '../../services/organization';
import { Organization } from '../../models/fleet.models';
import { TermService } from '../../services/term';
import { Terminal } from '../../models/fleet.models';
import { finalize, timeout } from 'rxjs';

const MAX_APK_SIZE_MIB = 250;
const MAX_APK_SIZE_BYTES = MAX_APK_SIZE_MIB * 1024 * 1024;
const APK_UPLOAD_TIMEOUT_MS = 15 * 60 * 1000;

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
  uploadSubmitting = false;
  uploadError = '';
  readonly maxApkSizeMiB = MAX_APK_SIZE_MIB;
  
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

  onFileSel(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0] ?? null;

    this.uploadError = '';
    this.ficApk = null;

    if (!file) return;

    if (!file.name.toLowerCase().endsWith('.apk')) {
      this.uploadError = "Le fichier sélectionné n’est pas un fichier APK.";
      input.value = '';
      return;
    }

    if (file.size > MAX_APK_SIZE_BYTES) {
      this.uploadError = `Cet APK pèse ${this.formatFileSize(file.size)}. La taille maximale autorisée est de ${MAX_APK_SIZE_MIB} Mio.`;
      input.value = '';
      return;
    }

    this.ficApk = file;
  }

  formatFileSize(bytes: number): string {
    return `${(bytes / 1024 / 1024).toFixed(2)} Mio`;
  }

  ouvrirModal() {
    this.editingApp = null;
    this.nvApp = { nom: '', pkg: '', type: 'blanche', ver: '' };
    this.ficApk = null;
    this.uploadError = '';
    this.showModal = true;
  }

  ouvrirEdition(app: any) {
    this.editingApp = app;
    this.nvApp = { nom: app.nom, pkg: app.pkg, type: app.type, ver: app.ver || '' };
    this.ficApk = null;
    this.uploadError = '';
    this.showModal = true;
  }
  
  fermerModal() { 
    this.showModal = false;
    this.editingApp = null;
    this.nvApp = { nom: '', pkg: '', type: 'blanche', ver: '' };
    this.ficApk = null;
    this.uploadError = '';
  }

  sauverApp() {
    if (!this.nvApp.nom || !this.nvApp.pkg) return alert('Remplissez les champs obligatoires.');
    if (this.isSuperAdmin && !this.selectedOrganizationId) return alert('Sélectionnez d’abord une organisation.');
    if (this.uploadSubmitting || this.uploadError) return;
    if (this.ficApk && this.ficApk.size > MAX_APK_SIZE_BYTES) {
      this.uploadError = `La taille maximale autorisée est de ${MAX_APK_SIZE_MIB} Mio.`;
      return;
    }
    
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
    this.uploadSubmitting = true;
    this.uploadError = '';
    request.pipe(
      timeout(APK_UPLOAD_TIMEOUT_MS),
      finalize(() => {
        this.uploadSubmitting = false;
        this.cdRef.detectChanges();
      }),
    ).subscribe({
      next: (res: any) => {
        if(res.success) {
          alert(`✅ ${res.message}`);
          this.fermerModal();
          this.getApps(); // Recharge la liste
        }
      },
      error: (err) => {
        console.error(err);
        if (err?.status === 413) {
          this.uploadError = `Le serveur a refusé le fichier car il dépasse la limite d’envoi. La limite attendue est de ${MAX_APK_SIZE_MIB} Mio ; vérifiez que la dernière configuration serveur est déployée.`;
          return;
        }
        if (err?.name === 'TimeoutError') {
          this.uploadError = "L’envoi a dépassé 15 minutes. Vérifiez la connexion puis réessayez.";
          return;
        }
        this.uploadError = err?.error?.message
          || err?.error?.errors?.chemin_apk?.[0]
          || "Impossible d’enregistrer l’application. Vérifiez le fichier et réessayez.";
      }
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
