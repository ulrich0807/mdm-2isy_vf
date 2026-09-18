import { Component, OnInit, OnDestroy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { TermService } from '../../services/term';
import * as L from 'leaflet';

@Component({
  selector: 'app-location',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './location.html'
})
export class Location implements OnInit, OnDestroy {
  map: any;
  terminaux: any[] = [];
  pollInterval: any;

  constructor(private termSvc: TermService) {}

  ngOnInit() {
    this.initMap();
    this.chargerDonnees();
    // Rafraîchissement automatique toutes les 15 secondes
    this.pollInterval = setInterval(() => this.chargerDonnees(), 15000);
  }

  ngOnDestroy() {
    if (this.pollInterval) {
      clearInterval(this.pollInterval);
    }
    if (this.map) {
      this.map.remove();
    }
  }

  chargerDonnees() {
    this.termSvc.getAll().subscribe({
      next: (res: any) => {
        if(res && res.success) {
          this.terminaux = res.data;
          this.rafraichirMarqueurs();
        }
      }
    });
  }

  initMap() {
    // Vue sur Abidjan
    this.map = L.map('fullscreenMap', { zoomControl: false }).setView([5.3599, -4.0083], 12);
    L.control.zoom({ position: 'bottomright' }).addTo(this.map);

    // Style de carte Premium moderne (CartoDB Voyager)
    L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{z}/{y}/{x}', {
      attribution: 'Tiles &copy; Esri &mdash; Source: Esri, DeLorme, NAVTEQ, USGS, Intermap, iPC, NRCAN, Esri Japan, METI, Esri China (Hong Kong), Esri (Thailand), TomTom, 2012',
      maxZoom: 19
    }).addTo(this.map);
  }

  rafraichirMarqueurs() {
    // Supprimer les anciens marqueurs de terminaux
    this.map.eachLayer((layer: any) => {
      if (layer instanceof L.Marker && layer.options.title !== 'ignorer') {
        this.map.removeLayer(layer);
      }
    });

    this.terminaux.forEach(t => {
      if (t.lat !== null && t.lat !== undefined && t.lng !== null && t.lng !== undefined) {
        
        const isOnline = (t.connectivity_status || t.statut) === 'online' || (t.connectivity_status || t.statut) === 'En ligne';
        const isAlert = t.statut === 'Verrouillé' || t.batterie < 15;
        
        let colorClass = 'bg-warning text-dark border-warning';
        let glowClass = '';
        
        if (isAlert) {
          colorClass = 'bg-danger text-white border-danger';
          glowClass = 'shadow-danger';
        } else if (isOnline) {
          colorClass = 'bg-success text-white border-success';
          glowClass = 'shadow-success pulse-animation';
        }

        // Création d'une icône HTML (DivIcon) pour avoir un style ultra moderne
        const customIcon = L.divIcon({
          className: 'custom-div-icon',
          html: `<div class="marker-pin ${colorClass} ${glowClass} d-flex justify-content-center align-items-center rounded-circle" style="width: 32px; height: 32px; border: 2px solid white; box-shadow: 0 4px 10px rgba(0,0,0,0.2);">
                   <span style="font-size: 14px;">🚚</span>
                 </div>`,
          iconSize: [32, 32],
          iconAnchor: [16, 16],
          popupAnchor: [0, -16]
        });

        L.marker([t.lat, t.lng], { icon: customIcon })
          .addTo(this.map)
          .bindPopup(this.createPopup(t));
      }
    });
  }

  private createPopup(terminal: any): HTMLElement {
    const container = document.createElement('div');
    container.className = 'p-2';
    container.style.minWidth = '200px';

    const isOnline = (terminal.connectivity_status || terminal.statut) === 'online' || (terminal.connectivity_status || terminal.statut) === 'En ligne';
    const badgeColor = isOnline ? 'success' : 'warning';
    const textStatus = isOnline ? 'En mouvement' : 'Hors Ligne';

    container.innerHTML = `
      <div class="d-flex align-items-center mb-3 pb-2 border-bottom">
        <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 40px; height: 40px;">
          <span class="fs-5">📦</span>
        </div>
        <div>
          <h6 class="fw-bold m-0 text-dark">${terminal.livreur || 'Livreur inconnu'}</h6>
          <small class="text-muted fw-bold" style="font-size: 0.75rem;">${terminal.modele || 'Appareil'}</small>
        </div>
      </div>
      
      <div class="d-flex justify-content-between align-items-center mb-2">
        <span class="text-muted small fw-bold">Statut</span>
        <span class="badge bg-${badgeColor}">${textStatus}</span>
      </div>
      
      <div class="d-flex justify-content-between align-items-center">
        <span class="text-muted small fw-bold">Batterie</span>
        <span class="fw-bold d-flex align-items-center gap-1 ${terminal.batterie < 15 ? 'text-danger' : 'text-success'}">
          ${terminal.batterie || '--'}%
        </span>
      </div>
    `;

    return container;
  }
}
