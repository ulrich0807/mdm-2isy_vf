import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router, RouterModule } from '@angular/router';
import { Auth } from '../../services/auth';

@Component({
  selector: 'app-sidebar',
  standalone: true,
  imports: [CommonModule, RouterModule],
  templateUrl: './sidebar.html'
})
export class Sidebar implements OnInit {
  isSuperAdmin: boolean = false;
  usrName: string = '';
  usrRole: string = '';
  usrInitials: string = 'U';
  isLoggingOut: boolean = false;

  menuItems = [
    { label: 'Tableau de bord', icon: '📊', route: '/dashboard' },
    { label: 'Flotte Terminaux', icon: '📱', route: '/devices' },
    { label: 'Localisation', icon: '📍', route: '/location' },
    { label: 'Licences', icon: '🔑', route: '/licences' },
    { label: 'Clients', icon: '👥', route: '/clients' },
    { label: 'Applications', icon: '📦', route: '/apps' },
    { label: 'Profils', icon: '👤', route: '/profils' },
    { label: 'Journal d\'Audit', icon: '📝', route: '/logs' },
    { label: 'Paramètres', icon: '⚙️', route: '/settings' }
  ];

  constructor(
    private auth: Auth,
    private router: Router,
  ) {}

  ngOnInit() {
    const usr = this.auth.user;

    this.isSuperAdmin = this.auth.role === 'super_admin';
    
    // Assignation dynamique du nom et du rôle formaté
    this.usrName = usr?.name || 'Utilisateur';
    this.usrRole = this.isSuperAdmin ? 'Super Administrateur' : 'Administrateur';
    this.usrInitials = this.getInitials(this.usrName);

    // On masque "Clients" pour les simples admins
    if (!this.isSuperAdmin) {
      this.menuItems = this.menuItems.filter(item => item.route !== '/clients');
    }
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

  private getInitials(name: string): string {
    const parts = name.trim().split(/\s+/).filter(Boolean);
    return (parts.length > 1 ? `${parts[0][0]}${parts[parts.length - 1][0]}` : parts[0]?.slice(0, 2) || 'U')
      .toUpperCase();
  }
}
