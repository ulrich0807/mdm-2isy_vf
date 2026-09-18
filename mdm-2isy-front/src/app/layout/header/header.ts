import { Component, OnInit, OnDestroy, HostListener } from '@angular/core';
import { CommonModule } from '@angular/common'; // Nécessaire pour les *ngIf
import { HttpClient } from '@angular/common/http';
import { environment } from '../../../environments/environment';

@Component({
  selector: 'app-header',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './header.html'
})
export class Header implements OnInit, OnDestroy {
  hr: string = '';
  tmr: any;
  sysStat: 'ok' | 'err_net' | 'err_srv' = 'ok'; // Les 3 états de notre système

  constructor(private http: HttpClient) {}

  ngOnInit() {
    this.majHr();
    this.chkSys(); // Première vérification au démarrage
    
    // On met à jour l'heure et on ping le serveur toutes les 30 secondes
    this.tmr = setInterval(() => {
      this.majHr();
      this.chkSys();
    }, 30000); 
  }

  majHr() {
    const d = new Date();
    const h = d.getHours().toString().padStart(2, '0');
    const m = d.getMinutes().toString().padStart(2, '0');
    this.hr = `${h}h${m}`;
  }

  // Écoute en direct si le navigateur perd la connexion Wifi/4G
  @HostListener('window:offline')
  onOffline() { 
    this.sysStat = 'err_net'; 
  }

  // Écoute en direct si la connexion revient
  @HostListener('window:online')
  onOnline() { 
    this.chkSys(); 
  }

  // Vérification de l'état global
  chkSys() {
    // 1. On vérifie d'abord la connexion physique (Navigateur)
    if (!navigator.onLine) {
      this.sysStat = 'err_net';
      return;
    }

    // 2. Si internet est là, on vérifie que le backend Laravel répond
    this.http.get(`${environment.apiUrl}/ping`).subscribe({
      next: () => this.sysStat = 'ok',
      error: () => this.sysStat = 'err_srv'
    });
  }

  ngOnDestroy() {
    if (this.tmr) clearInterval(this.tmr);
  }
}
