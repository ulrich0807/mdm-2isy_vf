import { Component, EventEmitter, OnInit, Output } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router, RouterModule } from '@angular/router';
import { Auth } from '../../services/auth';
import { AlertService } from '../../services/alert.service';

@Component({
  selector: 'app-sidebar',
  standalone: true,
  imports: [CommonModule, RouterModule],
  templateUrl: './sidebar.html'
})
export class Sidebar implements OnInit {
  @Output() navigate = new EventEmitter<void>();
  isSuperAdmin: boolean = false;
  usrName: string = '';
  usrRole: string = '';
  usrInitials: string = 'U';
  isLoggingOut: boolean = false;

  menuItems: Array<{ label: string; icon: string; route: string; badge?: number; roles: string[] }> = [];

  constructor(
    private auth: Auth,
    private router: Router,
    private alertSvc: AlertService
  ) {}

  ngOnInit() {
    const usr = this.auth.user;

    this.isSuperAdmin = this.auth.role === 'super_admin';
    
    // Assignation dynamique du nom et du rôle formaté
    this.usrName = usr?.name || 'Utilisateur';
    this.usrRole = ({ super_admin: 'Super Administrateur', admin: 'Administrateur', operator: 'Opérateur', viewer: 'Lecture seule' } as Record<string, string>)[this.auth.role || ''] || 'Utilisateur';
    this.usrInitials = this.getInitials(this.usrName);
    const role = this.auth.role || 'viewer';
    this.menuItems = [
      { label: 'Tableau de bord', icon: '📊', route: '/dashboard', roles: ['super_admin', 'admin', 'operator', 'viewer'] },
      { label: 'Flotte Terminaux', icon: '📱', route: '/devices', roles: ['super_admin', 'admin', 'operator', 'viewer'] },
      { label: 'Alertes', icon: '🔔', route: '/alerts', badge: 0, roles: ['super_admin'] },
      { label: 'Localisation', icon: '📍', route: '/location', roles: ['super_admin', 'admin', 'operator', 'viewer'] },
      { label: 'Licences', icon: '🔑', route: '/licences', roles: ['super_admin', 'admin'] },
      { label: 'Clients', icon: '👥', route: '/clients', roles: ['super_admin'] },
      { label: 'Messages reçus', icon: '✉️', route: '/contact-requests', roles: ['super_admin'] },
      { label: 'Utilisateurs', icon: '🧑‍💼', route: '/users', roles: ['super_admin', 'admin'] },
      { label: 'Applications', icon: '📦', route: '/apps', roles: ['super_admin', 'admin', 'operator', 'viewer'] },
      { label: 'Profils', icon: '👤', route: '/profils', roles: ['super_admin', 'admin', 'operator', 'viewer'] },
      { label: 'Journal d\'Audit', icon: '📝', route: '/logs', roles: ['super_admin'] },
      { label: 'Paramètres', icon: '⚙️', route: '/settings', roles: ['super_admin', 'admin', 'operator', 'viewer'] },
    ].filter((item) => item.roles.includes(role));

    if (this.isSuperAdmin) this.loadAlertsCount();
  }

  private loadAlertsCount(): void {
    this.alertSvc.getAlerts('active').subscribe({
      next: (res) => {
        if (res.success) {
          const alertItem = this.menuItems.find(i => i.route === '/alerts');
          if (alertItem) {
            alertItem.badge = res.data.length;
          }
        }
      }
    });
  }

  logout(): void {
    if (this.isLoggingOut) {
      return;
    }

    this.isLoggingOut = true;
    this.auth.logout().subscribe({
      complete: () => this.router.navigate(['/login']),
    });
  }

  onNavigate(): void {
    this.navigate.emit();
  }

  private getInitials(name: string): string {
    const parts = name.trim().split(/\s+/).filter(Boolean);
    return (parts.length > 1 ? `${parts[0][0]}${parts[parts.length - 1][0]}` : parts[0]?.slice(0, 2) || 'U')
      .toUpperCase();
  }
}
