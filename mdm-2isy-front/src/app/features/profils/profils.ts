import { Component, OnInit, ChangeDetectorRef } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ProfService } from '../../services/prof';

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
  
  nvProf = { nom: '', kiosk: false, appKiosk: '', noCam: false, noUsb: false, noBt: false, pinFort: false };

  constructor(private profSvc: ProfService, private cdRef: ChangeDetectorRef) {}

  ngOnInit() {
    this.getProfs();
  }

  getProfs() {
    this.load = true;
    this.profSvc.getAll().subscribe({
      next: (res: any) => {
        // Mapping des variables snake_case (Laravel) vers camelCase (Angular HTML)
        this.profs = res.map((p: any) => ({
          id: p.id,
          nom: p.nom,
          kiosk: p.kiosk == 1,
          appKiosk: p.app_kiosk,
          noCam: p.no_cam == 1,
          noUsb: p.no_usb == 1,
          noBt: p.no_bt == 1,
          pinFort: p.pin_fort == 1
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

  ouvMod() { this.showMod = true; }
  
  fermMod() { 
    this.showMod = false;
    this.nvProf = { nom: '', kiosk: false, appKiosk: '', noCam: false, noUsb: false, noBt: false, pinFort: false };
  }

  savProf() {
    if (!this.nvProf.nom) return alert('Le nom du profil est obligatoire.');
    if (this.nvProf.kiosk && !this.nvProf.appKiosk) return alert("Précisez l'ID de l'application pour le Kiosque.");
    
    // Préparation des données pour correspondre aux colonnes Laravel
    const payload = {
      nom: this.nvProf.nom,
      kiosk: this.nvProf.kiosk,
      app_kiosk: this.nvProf.appKiosk,
      no_cam: this.nvProf.noCam,
      no_usb: this.nvProf.noUsb,
      no_bt: this.nvProf.noBt,
      pin_fort: this.nvProf.pinFort
    };

    this.profSvc.add(payload).subscribe({
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