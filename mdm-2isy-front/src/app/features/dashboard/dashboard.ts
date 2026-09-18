import { Component, OnInit, ViewChild, ElementRef, ChangeDetectorRef } from '@angular/core';
import { CommonModule } from '@angular/common';
import { TermService } from '../../services/term';
import { LogService } from '../../services/log';
import { LicService } from '../../services/lic'; 
import Chart from 'chart.js/auto'; 
import * as L from 'leaflet'; 

@Component({
  selector: 'app-dashboard',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './dashboard.html'
})
export class Dashboard implements OnInit {
  
  @ViewChild('lineChart') lineChart!: ElementRef;
  @ViewChild('pieChart') pieChart!: ElementRef;

  // Statistiques Terminaux
  totTerm: number = 0;
  actv: number = 0;
  inactv: number = 0;
  alrt: number = 0;
  avgBat: number = 0;
  
  // Statistiques Licences
  totLic: number = 0;
  actvLic: number = 0;

  // Journal d'Audit
  actRec: any[] = [];

  // Instances
  chartLine: any;
  chartPie: any;
  map: any;

  // Données dynamiques du graphe
  lineLabels: string[] = [];
  lineData: number[] = [];

  constructor(
    private termSvc: TermService, 
    private logSvc: LogService,
    private licSvc: LicService,
    private cdRef: ChangeDetectorRef
  ) {}

  ngOnInit() {
    this.chargerStatistiques();
    this.chargerLogs();
    this.chargerLicences();
  }

  chargerStatistiques() {
    this.termSvc.getAll().subscribe({
      next: (res: any) => {
        if(res && res.success) {
          const terminaux = res.data;
          
          this.totTerm = terminaux.length;
          this.actv = terminaux.filter((t: any) => this.isOnline(t)).length;
          this.inactv = terminaux.filter((t: any) => !this.isOnline(t)).length;
          this.alrt = terminaux.filter((t: any) => t.statut === 'Verrouillé' || t.batterie < 15).length;

          const totalBat = terminaux.reduce((acc: number, curr: any) => acc + (curr.batterie || 0), 0);
          this.avgBat = this.totTerm > 0 ? Math.round(totalBat / this.totTerm) : 0;

          this.cdRef.detectChanges(); 
          
          setTimeout(() => {
            this.initCharts();
            this.initMap(terminaux);
          }, 100);
        }
      }
    });
  }

  chargerLogs() {
    this.logSvc.getAll().subscribe((res: any) => {
      // On récupère les 5 dernières actions réelles de la base
      this.actRec = res.slice(0, 5); 

      // Extraction des statistiques dynamiques pour le graphe
      const statsJour: { [key: string]: number } = {};
      res.forEach((log: any) => {
        if(log.date) {
           const jour = log.date.split(' ')[0]; // ex: 07/09/2026
           statsJour[jour] = (statsJour[jour] || 0) + 1;
        }
      });
      
      // Trier par date si nécessaire ou garder l'ordre
      const datesKeys = Object.keys(statsJour).reverse().slice(-7);
      this.lineLabels = datesKeys;
      this.lineData = datesKeys.map(k => statsJour[k]);

      this.cdRef.detectChanges();

      // Mise à jour du graphe s'il existe déjà
      if (this.chartLine) {
        this.chartLine.data.labels = this.lineLabels.length > 0 ? this.lineLabels : ['Aucune donnée'];
        this.chartLine.data.datasets[0].data = this.lineData.length > 0 ? this.lineData : [0];
        this.chartLine.update();
      }
    });
  }

  chargerLicences() {
    this.licSvc.list().subscribe((res: any) => {
      if(res && res.success) {
        this.totLic = res.data.length;
        this.actvLic = res.data.filter((l: any) => l.statut === 'Active').length;
        this.cdRef.detectChanges();
      }
    });
  }

  initMap(terminaux: any[]) {
    // Évite d'initialiser deux fois la carte si on recharge
    if (this.map) {
      this.map.remove();
    }

    // Centrage sur Abidjan 
    this.map = L.map('mapLive').setView([5.3599, -4.0083], 12);

    L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{z}/{y}/{x}', {
      attribution: 'Tiles &copy; Esri &mdash; Source: Esri, DeLorme, NAVTEQ, USGS, Intermap, iPC, NRCAN, Esri Japan, METI, Esri China (Hong Kong), Esri (Thailand), TomTom, 2012',
      maxZoom: 19
    }).addTo(this.map);

    // Ajout des points GPS sur la carte
    terminaux.forEach(t => {
      if (t.lat !== null && t.lat !== undefined && t.lng !== null && t.lng !== undefined) {
        let colorClass = 'bg-warning text-dark border-warning';
        let glowClass = '';
        
        if (t.statut === 'Verrouillé' || t.batterie < 15) {
          colorClass = 'bg-danger text-white border-danger';
          glowClass = 'shadow-danger';
        } else if (this.isOnline(t)) {
          colorClass = 'bg-success text-white border-success';
          glowClass = 'shadow-success pulse-animation';
        }

        const customIcon = L.divIcon({
          className: 'custom-div-icon',
          html: `<div class="marker-pin ${colorClass} ${glowClass} d-flex justify-content-center align-items-center rounded-circle" style="width: 24px; height: 24px; border: 2px solid white; box-shadow: 0 4px 10px rgba(0,0,0,0.2);"></div>`,
          iconSize: [24, 24],
          iconAnchor: [12, 12],
          popupAnchor: [0, -12]
        });

        L.marker([t.lat, t.lng], { icon: customIcon })
          .addTo(this.map)
          .bindPopup(this.createDevicePopup(t));
      }
    });
  }

  private createDevicePopup(terminal: any): HTMLElement {
    const container = document.createElement('div');
    container.style.fontSize = '13px';

    const title = document.createElement('strong');
    title.style.fontSize = '15px';
    title.textContent = terminal.livreur || 'Terminal non assigné';
    container.append(title, document.createElement('br'));

    const details = [
      `Modèle : ${terminal.modele || '—'}`,
      `Batterie : ${terminal.batterie ?? '—'}%`,
      `Statut : ${this.isOnline(terminal) ? 'En ligne' : 'Hors ligne'}`,
    ];

    details.forEach((detail) => {
      container.append(document.createTextNode(detail), document.createElement('br'));
    });

    return container;
  }

  private isOnline(terminal: any): boolean {
    const status = terminal.connectivity_status || terminal.statut;
    return status === 'online' || status === 'En ligne';
  }

  initCharts() {
    if (this.chartLine) this.chartLine.destroy();
    if (this.chartPie) this.chartPie.destroy();

    if (this.lineChart?.nativeElement) {
      this.chartLine = new Chart(this.lineChart.nativeElement, {
        type: 'line',
        data: {
          labels: this.lineLabels.length > 0 ? this.lineLabels : ['Aucune donnée'],
          datasets: [{
            label: 'Actions MDM',
            data: this.lineData.length > 0 ? this.lineData : [0],
            borderColor: '#0077ff',
            tension: 0.4,
            fill: true,
            backgroundColor: 'rgba(0, 119, 255, 0.1)'
          }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
      });
    }

    if (this.pieChart?.nativeElement) {
      const autres = this.totTerm - this.actv - this.inactv; 
      this.chartPie = new Chart(this.pieChart.nativeElement, {
        type: 'doughnut',
        data: {
          labels: ['En Ligne', 'Hors Ligne', 'Alertes/Verrouillés'],
          datasets: [{
            data: [this.actv, this.inactv, autres > 0 ? autres : this.alrt],
            backgroundColor: ['#198754', '#ffc107', '#dc3545'],
            borderWidth: 0
          }]
        },
        options: { responsive: true, maintainAspectRatio: false, cutout: '75%', plugins: { legend: { position: 'bottom' } } }
      });
    }
  }
}
