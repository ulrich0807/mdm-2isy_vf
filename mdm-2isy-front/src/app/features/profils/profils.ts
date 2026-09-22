import { Component, OnInit, ChangeDetectorRef } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ProfService } from '../../services/prof';
import { Auth } from '../../services/auth';
import { OrganizationService } from '../../services/organization';
import { Organization } from '../../models/fleet.models';

@Component({
  selector: 'app-profils',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './profils.html'
})
export class Profils implements OnInit {
  profs: any[] = [];
  load: boolean = true;
  showMod: boolean = false;
  isSuperAdmin = false;
  organizations: Organization[] = [];
  selectedOrganizationId: number | null = null;
  editingProfileId: number | null = null;
  
  nvProf = { nom: '', kiosk: false, appKiosk: '', kioskApps: '', noCam: false, noUsb: false, noBt: false, noWifi: false, noData: false, noAirplane: false, pinFort: false, blacklistApps: '', whitelistApps: '' };

  constructor(private profSvc: ProfService, private auth: Auth, private organizationSvc: OrganizationService, private cdRef: ChangeDetectorRef) {}

  ngOnInit() {
    this.isSuperAdmin = this.auth.role === 'super_admin';
    this.selectedOrganizationId = this.auth.user?.organization_id ?? null;
    if (this.isSuperAdmin) {
      this.organizationSvc.getAll().subscribe(res => this.organizations = res.data);
    }
    this.getProfs();
  }

  getProfs() {
    this.load = true;
    this.profSvc.getAll(this.selectedOrganizationId).subscribe({
      next: (res: any) => {
        // Mapping des variables snake_case (Laravel) vers camelCase (Angular HTML)
        this.profs = res.map((p: any) => ({
          id: p.id,
          nom: p.nom,
          kiosk: p.kiosk == 1,
          appKiosk: p.app_kiosk,
          kioskApps: Array.isArray(p.kiosk_apps) ? p.kiosk_apps.join(', ') : '',
          noCam: p.no_cam == 1,
          noUsb: p.no_usb == 1,
          noBt: p.no_bt == 1,
          noWifi: p.no_wifi == 1,
          noData: p.no_data == 1,
          noAirplane: p.no_airplane == 1,
          pinFort: p.pin_fort == 1,
          blacklistApps: Array.isArray(p.blacklist_apps) ? p.blacklist_apps.join(', ') : '',
          whitelistApps: Array.isArray(p.whitelist_apps) ? p.whitelist_apps.join(', ') : ''
        }));
        this.load = false;
        this.cdRef.detectChanges();
      },
      error: (err: any) => {
        console.error('Erreur Profils:', err);
        this.load = false;
      }
    });
  }

  ouvMod() {
    this.editingProfileId = null;
    this.nvProf = { nom: '', kiosk: false, appKiosk: '', kioskApps: '', noCam: false, noUsb: false, noBt: false, noWifi: false, noData: false, noAirplane: false, pinFort: false, blacklistApps: '', whitelistApps: '' };
    this.showMod = true;
  }

  editProf(profile: any) {
    this.editingProfileId = profile.id;
    this.nvProf = {
      nom: profile.nom,
      kiosk: profile.kiosk,
      appKiosk: profile.appKiosk || '',
      kioskApps: profile.kioskApps || '',
      noCam: profile.noCam,
      noUsb: profile.noUsb,
      noBt: profile.noBt,
      noWifi: profile.noWifi,
      noData: profile.noData,
      noAirplane: profile.noAirplane,
      pinFort: profile.pinFort,
      blacklistApps: profile.blacklistApps || '',
      whitelistApps: profile.whitelistApps || '',
    };
    this.showMod = true;
  }
  
  fermMod() { 
    this.showMod = false;
  }

  savProf() {
    if (!this.nvProf.nom) return alert('Le nom du profil est obligatoire.');
    if (this.isSuperAdmin && !this.selectedOrganizationId) return alert('Sélectionnez d’abord une organisation.');
    const kioskApps = this.nvProf.kioskApps
      ? this.nvProf.kioskApps.split(',').map(s => s.trim()).filter(s => s)
      : [];
    if (this.nvProf.kiosk && !this.nvProf.appKiosk.trim() && kioskApps.length === 0) {
      return alert('Précisez au moins une application pour le mode kiosque.');
    }
    
    // Préparation des données pour correspondre aux colonnes Laravel
    const payload = {
      organization_id: this.selectedOrganizationId,
      nom: this.nvProf.nom,
      kiosk: this.nvProf.kiosk,
      app_kiosk: this.nvProf.appKiosk.trim() || null,
      kiosk_apps: kioskApps,
      no_cam: this.nvProf.noCam,
      no_usb: this.nvProf.noUsb,
      no_bt: this.nvProf.noBt,
      no_wifi: this.nvProf.noWifi,
      no_data: this.nvProf.noData,
      no_airplane: this.nvProf.noAirplane,
      pin_fort: this.nvProf.pinFort,
      blacklist_apps: this.nvProf.blacklistApps ? this.nvProf.blacklistApps.split(',').map(s => s.trim()).filter(s => s) : [],
      whitelist_apps: this.nvProf.whitelistApps ? this.nvProf.whitelistApps.split(',').map(s => s.trim()).filter(s => s) : []
    };

    const request = this.editingProfileId
      ? this.profSvc.update(this.editingProfileId, payload)
      : this.profSvc.add(payload);
    request.subscribe({
      next: (res: any) => {
        if(res.success) {
          alert(`✅ ${res.message}`);
          this.fermMod();
          this.getProfs();
        }
      },
      error: (err) => console.error(err)
    });
  }

  delProf(id: number) {
    if(confirm("Supprimer cette politique de sécurité ?")) {
      this.profSvc.del(id).subscribe((res: any) => {
        alert(`🗑️ ${res.message}`);
        this.getProfs();
      });
    }
  }
}
