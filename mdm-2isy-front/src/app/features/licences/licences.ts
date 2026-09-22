import { Component, OnInit, ChangeDetectorRef } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { LicService } from '../../services/lic';
import { TermService } from '../../services/term';
import { Auth } from '../../services/auth';
import { OrganizationService } from '../../services/organization';
import { Organization } from '../../models/fleet.models';

@Component({
  selector: 'app-licences',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './licences.html'
})
export class Licences implements OnInit {
  isSuperAdmin: boolean = false;
  lics: any[] = [];
  terminaux: any[] = [];
  load: boolean = true;
  organizations: Organization[] = [];
  selectedOrganizationId: number | null = null;

  // --- NOUVEAU : Objet pour les statistiques ---
  stats = {
    total: 0,
    actives: 0,
    rattachees: 0,
    actNonRattachees: 0
  };

  showModalAssign: boolean = false;
  licenceEnCours: number | null = null;
  terminalSelectionne: string = '';

  constructor(
    private licSvc: LicService, 
    private termSvc: TermService,
    private auth: Auth,
    private organizationSvc: OrganizationService,
    private cdRef: ChangeDetectorRef
  ) {}

  ngOnInit() {
    this.isSuperAdmin = this.auth.role === 'super_admin';
    this.selectedOrganizationId = this.auth.user?.organization_id ?? null;
    if (this.isSuperAdmin) {
      this.organizationSvc.getAll().subscribe(res => {
        this.organizations = res.data;
      });
    }
    this.getLics();
    this.getTerminaux();
  }

  getLics() {
    this.licSvc.list(this.selectedOrganizationId).subscribe((res: any) => {
      this.lics = res.success ? res.data : [];
      this.calcStats(); // <-- Calcul des stats après chargement
      this.load = false;
      this.cdRef.detectChanges();
    });
  }

  // --- NOUVEAU : Fonction de calcul des stats ---
  calcStats() {
    this.stats.total = this.lics.length;
    this.stats.actives = this.lics.filter(l => l.statut === 'Active').length;
    this.stats.rattachees = this.lics.filter(l => l.term_id !== null).length;
    this.stats.actNonRattachees = this.lics.filter(l => l.statut === 'Active' && l.term_id === null).length;
  }

  getTerminaux() {
    this.termSvc.getAll(this.selectedOrganizationId ?? undefined).subscribe((res: any) => {
      this.terminaux = res.success ? res.data : [];
    });
  }

  getImei(id: number): string {
    const t = this.terminaux.find(term => term.id === id);
    return t ? t.imei : 'Inconnu';
  }

  genererLic() {
    if (!this.selectedOrganizationId) return alert('Sélectionnez d’abord une organisation.');
    this.licSvc.gen(this.selectedOrganizationId).subscribe((res: any) => {
      if (res.success) {
        alert(`✅ Nouvelle clé générée : ${res.data.cle}`);
        this.getLics();
      }
    });
  }

  onOrganizationChange() {
    this.getLics();
    this.getTerminaux();
  }

  actvLic(id: number) {
    if(confirm(`Voulez-vous activer cette licence ?`)) {
      this.licSvc.actv(id).subscribe((res: any) => {
        alert(`✅ ${res.message}`);
        this.getLics();
      });
    }
  }

  ouvrirModalAssign(id: number) {
    this.licenceEnCours = id;
    this.terminalSelectionne = '';
    this.showModalAssign = true;
  }

  fermerModalAssign() {
    this.showModalAssign = false;
    this.licenceEnCours = null;
  }

  get terminauxDispo() {
    const terminauxAssignes = this.lics.map(l => l.term_id).filter(id => id !== null);
    return this.terminaux.filter(t => !terminauxAssignes.includes(t.id));
  }

  confirmerAssignation() {
    if (!this.terminalSelectionne) return alert("Veuillez sélectionner un terminal");
    
    this.licSvc.assign(this.licenceEnCours!, parseInt(this.terminalSelectionne)).subscribe((res: any) => {
      alert(`✅ ${res.message}`);
      this.fermerModalAssign();
      this.getLics();
    });
  }

  detacherLic(id: number) {
    if(confirm(`Voulez-vous vraiment détacher cette licence de son terminal actuel ?`)) {
      this.licSvc.assign(id, null).subscribe((res: any) => {
        alert(`🔓 ${res.message}`);
        this.getLics();
      });
    }
  }
}
