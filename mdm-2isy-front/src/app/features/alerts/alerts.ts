import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { AlertService } from '../../services/alert.service';
import { Alert } from '../../models/fleet.models';
import { Organization } from '../../models/fleet.models';
import { Auth } from '../../services/auth';
import { OrganizationService } from '../../services/organization';

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
  isSuperAdmin = false;
  organizations: Organization[] = [];
  selectedOrganizationId: number | null = null;

  constructor(private alertSvc: AlertService, private auth: Auth, private organizationSvc: OrganizationService) {}

  ngOnInit(): void {
    this.isSuperAdmin = this.auth.role === 'super_admin';
    this.selectedOrganizationId = this.auth.user?.organization_id ?? null;
    if (this.isSuperAdmin) {
      this.organizationSvc.getAll().subscribe(res => this.organizations = res.data);
    }
    this.loadAlerts();
  }

  loadAlerts(): void {
    this.loading = true;
    const statusParam = this.filterStatus === 'all' ? undefined : this.filterStatus;
    this.alertSvc.getAlerts(statusParam, this.selectedOrganizationId).subscribe({
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
      case 'licence_expiring': return 'bi-calendar-event text-warning';
      case 'licence_expired': return 'bi-key-fill text-danger';
      case 'command_failure': return 'bi-x-octagon-fill text-danger';
      default: return 'bi-exclamation-triangle text-info';
    }
  }

  getAlertLabel(type: string): string {
    const labels: Record<string, string> = {
      offline: 'Hors ligne',
      battery: 'Batterie',
      storage: 'Stockage',
      licence_expiring: 'Licence à renouveler',
      licence_expired: 'Licence expirée',
      command_failure: 'Commande échouée',
    };
    return labels[type] || type;
  }
}
