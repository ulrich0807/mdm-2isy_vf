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
  markerLayer: any;
  terminaux: any[] = [];
  pollInterval: any;
  loading = false;
  errorMessage = '';
  lastUpdated: Date | null = null;
  private hasFittedFleet = false;

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
    this.loading = true;
    this.errorMessage = '';
    this.termSvc.getAll().subscribe({
      next: (res: any) => {
        if(res && res.success) {
          this.terminaux = res.data;
          this.rafraichirMarqueurs();
          this.lastUpdated = new Date();
        }
        this.loading = false;
      },
      error: () => {
        this.loading = false;
        this.errorMessage = 'La position des terminaux ne peut pas être actualisée.';
      },
    });
  }

  initMap() {
    // Vue sur Abidjan
    this.map = L.map('fullscreenMap', { zoomControl: false, attributionControl: true }).setView([5.3599, -4.0083], 12);
    L.control.zoom({ position: 'bottomright' }).addTo(this.map);
    L.control.scale({ position: 'bottomleft', imperial: false }).addTo(this.map);

    // Style de carte Premium moderne (CartoDB Voyager)
    const streetLayer = L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png', {
      attribution: '&copy; OpenStreetMap &copy; CARTO',
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
    this.markerLayer = L.layerGroup().addTo(this.map);
    setTimeout(() => this.map.invalidateSize(), 0);
  }

  rafraichirMarqueurs() {
    this.markerLayer.clearLayers();
    const visibleCoordinates: L.LatLngExpression[] = [];

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
        visibleCoordinates.push([Number(t.lat), Number(t.lng)]);
        const customIcon = L.divIcon({
          className: 'custom-div-icon',
          html: `<div class="marker-pin ${colorClass} ${glowClass} d-flex justify-content-center align-items-center rounded-circle" style="width: 38px; height: 38px; border: 3px solid white; box-shadow: 0 6px 16px rgba(15,23,42,.28);">
                   <span style="font-size: 16px;">🚚</span>
                 </div>`,
          iconSize: [38, 38],
          iconAnchor: [19, 19],
          popupAnchor: [0, -20]
        });

        L.marker([t.lat, t.lng], { icon: customIcon })
          .addTo(this.markerLayer)
          .bindTooltip(String(t.livreur || t.modele || 'Terminal'), { direction: 'top', offset: [0, -18] })
          .bindPopup(this.createPopup(t), { maxWidth: 300 });
      }
    });

    if (!this.hasFittedFleet && visibleCoordinates.length > 0) {
      this.fitFleet();
      this.hasFittedFleet = true;
    }
  }

  fitFleet(): void {
    const points = this.terminaux
      .filter((terminal) => terminal.lat != null && terminal.lng != null)
      .map((terminal) => L.latLng(Number(terminal.lat), Number(terminal.lng)));
    if (points.length === 0) {
      this.map.setView([5.3599, -4.0083], 12);
      return;
    }
    if (points.length === 1) {
      this.map.setView(points[0], 14);
      return;
    }
    this.map.fitBounds(L.latLngBounds(points), { padding: [60, 60], maxZoom: 15 });
  }

  get activeCount(): number {
    return this.terminaux.filter((terminal) => this.isOnline(terminal) && !this.isAlert(terminal)).length;
  }

  get offlineCount(): number {
    return this.terminaux.filter((terminal) => !this.isOnline(terminal) && !this.isAlert(terminal)).length;
  }

  get alertCount(): number {
    return this.terminaux.filter((terminal) => this.isAlert(terminal)).length;
  }

  private isOnline(terminal: any): boolean {
    const status = terminal.connectivity_status || terminal.statut;
    return status === 'online' || status === 'En ligne';
  }

  private isAlert(terminal: any): boolean {
    return terminal.statut === 'Verrouillé' || Number(terminal.batterie) < 15;
  }

  private createPopup(terminal: any): HTMLElement {
    const container = document.createElement('div');
    container.className = 'p-2';
    container.style.minWidth = '200px';

    const createElement = <K extends keyof HTMLElementTagNameMap>(
      tagName: K,
      className: string,
      text?: string,
    ): HTMLElementTagNameMap[K] => {
      const element = document.createElement(tagName);
      element.className = className;
      if (text !== undefined) {
        element.textContent = text;
      }
      return element;
    };

    const isOnline = (terminal.connectivity_status || terminal.statut) === 'online' || (terminal.connectivity_status || terminal.statut) === 'En ligne';
    const badgeColor = isOnline ? 'success' : 'warning';
    const textStatus = isOnline ? 'En mouvement' : 'Hors Ligne';

    const header = createElement('div', 'd-flex align-items-center mb-3 pb-2 border-bottom');
    const icon = createElement(
      'div',
      'bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center me-3',
    );
    icon.style.width = '40px';
    icon.style.height = '40px';
    icon.appendChild(createElement('span', 'fs-5', '📦'));

    const identity = document.createElement('div');
    identity.appendChild(
      createElement('h6', 'fw-bold m-0 text-dark', String(terminal.livreur || 'Livreur inconnu')),
    );
    const model = createElement(
      'small',
      'text-muted fw-bold',
      String(terminal.modele || 'Appareil'),
    );
    model.style.fontSize = '0.75rem';
    identity.appendChild(model);
    header.append(icon, identity);
    container.appendChild(header);

    const statusRow = createElement(
      'div',
      'd-flex justify-content-between align-items-center mb-2',
    );
    statusRow.append(
      createElement('span', 'text-muted small fw-bold', 'Statut'),
      createElement('span', `badge bg-${badgeColor}`, textStatus),
    );
    container.appendChild(statusRow);

    const batteryRow = createElement(
      'div',
      'd-flex justify-content-between align-items-center',
    );
    batteryRow.append(
      createElement('span', 'text-muted small fw-bold', 'Batterie'),
      createElement(
        'span',
        `fw-bold d-flex align-items-center gap-1 ${terminal.batterie < 15 ? 'text-danger' : 'text-success'}`,
        `${terminal.batterie ?? '--'}%`,
      ),
    );
    container.appendChild(batteryRow);

    const btnTrajet = document.createElement('button');
    btnTrajet.className = 'btn btn-sm btn-outline-primary w-100 mt-3 fw-bold rounded-pill';
    btnTrajet.textContent = '📍 Voir le trajet (24h)';
    btnTrajet.addEventListener('click', () => {
      this.afficherTrajet(terminal);
    });
    container.appendChild(btnTrajet);

    return container;
  }

  historyPolyline: any = null;
  historyStartMarker: any = null;
  currentHistoryTerminal: any = null;

  afficherTrajet(terminal: any) {
    this.fermerTrajet(); // Clean up previous
    
    this.currentHistoryTerminal = terminal;
    this.map.closePopup();

    this.termSvc.getLocationHistory(terminal.id, 24).subscribe({
      next: (res: any) => {
        if(res && res.success && res.data && res.data.length > 0) {
          const points = res.data.map((h: any) => [parseFloat(h.lat), parseFloat(h.lng)]);
          
          // Add current position at the end if it exists
          if (terminal.lat && terminal.lng) {
            points.push([parseFloat(terminal.lat), parseFloat(terminal.lng)]);
          }

          if (points.length < 2) {
            alert("Pas assez de données d'historique pour tracer un trajet.");
            this.currentHistoryTerminal = null;
            return;
          }

          // Dessiner le tracé
          this.historyPolyline = L.polyline(points, {
            color: '#0d6efd',
            weight: 5,
            opacity: 0.7,
            dashArray: '10, 10',
            lineJoin: 'round'
          }).addTo(this.map);

          // Marqueur de départ
          const startPoint = points[0];
          this.historyStartMarker = L.circleMarker(startPoint as any, {
            radius: 8,
            fillColor: "#ffc107",
            color: "#fff",
            weight: 2,
            opacity: 1,
            fillOpacity: 1
          }).addTo(this.map).bindPopup("Point de départ (il y a 24h)");

          // Ajuster la vue
          this.map.fitBounds(this.historyPolyline.getBounds(), { padding: [50, 50] });
        } else {
          alert("Aucun historique disponible pour ce terminal sur les 24 dernières heures.");
          this.currentHistoryTerminal = null;
        }
      },
      error: () => {
        alert("Erreur lors du chargement de l'historique.");
        this.currentHistoryTerminal = null;
      }
    });
  }

  fermerTrajet() {
    if (this.historyPolyline) {
      this.map.removeLayer(this.historyPolyline);
      this.historyPolyline = null;
    }
    if (this.historyStartMarker) {
      this.map.removeLayer(this.historyStartMarker);
      this.historyStartMarker = null;
    }
    this.currentHistoryTerminal = null;
    this.chargerDonnees();
  }
}
