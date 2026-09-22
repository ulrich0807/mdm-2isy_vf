import { CommonModule } from '@angular/common';
import { Component, OnInit } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Organization, } from '../../models/fleet.models';
import { ClientUser } from '../../models/user.models';
import { Auth } from '../../services/auth';
import { OrganizationService } from '../../services/organization';
import { OrganizationUserService } from '../../services/organization-user.service';

@Component({
  selector: 'app-users',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './users.html',
})
export class Users implements OnInit {
  users: ClientUser[] = [];
  organizations: Organization[] = [];
  selectedOrganizationId: number | null = null;
  isSuperAdmin = false;
  loading = true;
  saving = false;
  showModal = false;
  form = { name: '', email: '', role: 'viewer', password: '' };

  constructor(
    private usersService: OrganizationUserService,
    private organizationService: OrganizationService,
    private auth: Auth,
  ) {}

  ngOnInit(): void {
    this.isSuperAdmin = this.auth.role === 'super_admin';
    this.selectedOrganizationId = this.auth.user?.organization_id ?? null;
    if (this.isSuperAdmin) {
      this.organizationService.getAll().subscribe(res => this.organizations = res.data);
    }
    this.loadUsers();
  }

  loadUsers(): void {
    this.loading = true;
    this.usersService.getAll(this.selectedOrganizationId).subscribe({
      next: res => { this.users = res.data; this.loading = false; },
      error: () => { this.users = []; this.loading = false; },
    });
  }

  openModal(): void {
    if (this.isSuperAdmin && !this.selectedOrganizationId) {
      alert('Sélectionnez d’abord une organisation.');
      return;
    }
    this.form = { name: '', email: '', role: 'viewer', password: '' };
    this.showModal = true;
  }

  save(): void {
    if (!this.form.name || !this.form.email || !this.form.password) return;
    this.saving = true;
    this.usersService.add({ ...this.form, organization_id: this.selectedOrganizationId }).subscribe({
      next: () => { this.saving = false; this.showModal = false; this.loadUsers(); },
      error: err => { this.saving = false; alert(err?.error?.message || 'Création impossible.'); },
    });
  }

  remove(user: ClientUser): void {
    if (!confirm(`Supprimer le compte de ${user.name} ?`)) return;
    this.usersService.remove(user.id).subscribe(() => this.loadUsers());
  }

  roleLabel(role: string): string {
    return ({ admin: 'Administrateur', operator: 'Opérateur', viewer: 'Lecteur' } as Record<string, string>)[role] || role;
  }
}
