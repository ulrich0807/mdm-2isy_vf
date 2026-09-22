import { Component, OnDestroy, OnInit, ViewChild, ElementRef, ChangeDetectorRef } from '@angular/core';
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
export class Dashboard implements OnInit, OnDestroy {
  
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
  private mapBounds: L.LatLngExpression[] = [];
  mapRefreshing = false;
  mapLastUpdated: Date | null = null;
  private refreshTimer: ReturnType<typeof setInterval> | null = null;

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
    this.refreshTimer = setInterval(() => this.chargerStatistiques(true), 15_000);
  }

  ngOnDestroy(): void {
    if (this.refreshTimer) clearInterval(this.refreshTimer);
    if (this.chartLine) this.chartLine.destroy();
    if (this.chartPie) this.chartPie.destroy();
    if (this.map) this.map.remove();
  }

  chargerStatistiques(silent = false) {
    this.mapRefreshing = true;
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
            if (!silent) this.initCharts();
            this.initMap(terminaux);
            this.mapLastUpdated = new Date();
            this.mapRefreshing = false;
          }, 100);
        } else {
          this.mapRefreshing = false;
        }
      },
      error: () => { this.mapRefreshing = false; },
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

    this.map = L.map('mapLive', { zoomControl: false, attributionControl: true })
      .setView([5.3599, -4.0083], 12);
    L.control.zoom({ position: 'bottomright' }).addTo(this.map);
    L.control.scale({ position: 'bottomleft', imperial: false }).addTo(this.map);

    const streetLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '&copy; contributeurs OpenStreetMap',
      maxZoom: 19
    }).addTo(this.map);
    const satelliteLayer = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
      attribution: 'Tiles &copy; Esri',
      maxZoom: 19,
    });
    L.control.layers({ 'Plan clair': streetLayer, 'Satellite': satelliteLayer }, undefined, {
      position: 'bottomright',
      collapsed: true,
    }).addTo(this.map);

    this.mapBounds = [];

    // Ajout des points GPS sur la carte
    terminaux.forEach(t => {
      const latitude = Number(t.lat);
      const longitude = Number(t.lng);
      if (t.lat != null && t.lng != null && Number.isFinite(latitude) && Number.isFinite(longitude)) {
        this.mapBounds.push([latitude, longitude]);
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
          html: `<div class="marker-pin ${colorClass} ${glowClass} d-flex justify-content-center align-items-center rounded-circle" style="width: 38px; height: 38px; border: 3px solid white; box-shadow: 0 6px 16px rgba(15,23,42,.28);"><span style="font-size:16px">🚚</span></div>`,
          iconSize: [38, 38],
          iconAnchor: [19, 19],
          popupAnchor: [0, -20]
        });

        L.marker([latitude, longitude], { icon: customIcon })
          .addTo(this.map)
          .bindTooltip(String(t.livreur || t.modele || 'Terminal'), { direction: 'top', offset: [0, -18] })
          .bindPopup(this.createDevicePopup(t));
      }
    });

    this.fitDashboardFleet();
    setTimeout(() => this.map?.invalidateSize(), 0);
  }

  fitDashboardFleet(): void {
    if (!this.map || this.mapBounds.length === 0) {
      this.map?.setView([5.3599, -4.0083], 12);
      return;
    }
    if (this.mapBounds.length === 1) {
      this.map.setView(this.mapBounds[0], 14);
      return;
    }
    this.map.fitBounds(this.mapBounds, { padding: [48, 48], maxZoom: 15 });
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
