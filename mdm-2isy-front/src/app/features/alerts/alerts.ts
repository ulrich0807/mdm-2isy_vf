import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { AlertService } from '../../services/alert.service';
import { Alert } from '../../models/fleet.models';

@Component({
  selector: 'app-alerts',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './alerts.html',
})
export class AlertsComponent implements OnInit {
  alerts: Alert[] = [];
  loading = true;
  filterStatus: 'all' | 'active' | 'resolved' = 'active';

  constructor(private alertSvc: AlertService) {}

  ngOnInit(): void {
    this.loadAlerts();
  }

  loadAlerts(): void {
    this.loading = true;
    const statusParam = this.filterStatus === 'all' ? undefined : this.filterStatus;
    this.alertSvc.getAlerts(statusParam).subscribe({
      next: (res) => {
        if (res.success) {
          this.alerts = res.data;
        }
        this.loading = false;
      },
      error: () => {
        this.loading = false;
      }
    });
  }

  setFilter(status: 'all' | 'active' | 'resolved'): void {
    this.filterStatus = status;
    this.loadAlerts();
  }

  resolveAlert(id: number): void {
    if (confirm('Voulez-vous vraiment marquer cette alerte comme résolue ?')) {
      this.alertSvc.resolveAlert(id).subscribe({
        next: (res) => {
          if (res.success) {
            this.loadAlerts();
          }
        }
      });
    }
  }

  getAlertIcon(type: string): string {
    switch(type) {
      case 'offline': return 'bi-wifi-off text-danger';
      case 'battery': return 'bi-battery-half text-warning';
      case 'storage': return 'bi-hdd-fill text-danger';
      default: return 'bi-exclamation-triangle text-info';
    }
  }
}
