import { CommonModule } from '@angular/common';
import { Component, OnDestroy, OnInit } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Organization, } from '../../models/fleet.models';
import { ClientUser } from '../../models/user.models';
import { Auth } from '../../services/auth';
import { OrganizationService } from '../../services/organization';
import { OrganizationUserService } from '../../services/organization-user.service';
import { Subscription } from 'rxjs';

@Component({
  selector: 'app-users',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './users.html',
})
export class Users implements OnInit, OnDestroy {
  users: ClientUser[] = [];
  organizations: Organization[] = [];
  selectedOrganizationId: number | null = null;
  isSuperAdmin = false;
  loading = true;
  saving = false;
  showModal = false;
  errorMessage = '';
  search = '';
  form = { name: '', email: '', role: 'viewer', password: '' };
  private usersRequest?: Subscription;

  constructor(
    private usersService: OrganizationUserService,
    private organizationService: OrganizationService,
    private auth: Auth,
  ) {}

  ngOnInit(): void {
    this.isSuperAdmin = this.auth.role === 'super_admin';
    this.selectedOrganizationId = this.auth.user?.organization_id ?? null;
    if (this.isSuperAdmin) {
      this.loadOrganizations();
      return;
    }
    this.loadUsers();
  }

  ngOnDestroy(): void {
    this.usersRequest?.unsubscribe();
  }

  loadOrganizations(): void {
    this.loading = true;
    this.errorMessage = '';
    this.organizationService.getAll().subscribe({
      next: (res) => {
        this.organizations = res.success ? res.data : [];
        const currentExists = this.organizations.some(
          (organization) => organization.id === this.selectedOrganizationId,
        );
        this.selectedOrganizationId = currentExists
          ? this.selectedOrganizationId
          : (this.organizations[0]?.id ?? null);
        if (this.selectedOrganizationId !== null) {
          this.loadUsers();
        } else {
          this.users = [];
          this.loading = false;
        }
      },
      error: () => {
        this.organizations = [];
        this.users = [];
        this.loading = false;
        this.errorMessage = 'Impossible de charger les organisations.';
      },
    });
  }

  changeOrganization(): void {
    this.search = '';
    if (this.selectedOrganizationId === null) {
      this.usersRequest?.unsubscribe();
      this.users = [];
      this.loading = false;
      return;
    }
    this.loadUsers();
  }

  loadUsers(): void {
    if (this.isSuperAdmin && this.selectedOrganizationId === null) {
      this.users = [];
      this.loading = false;
      return;
    }
    this.usersRequest?.unsubscribe();
    this.loading = true;
    this.errorMessage = '';
    this.usersRequest = this.usersService.getAll(this.selectedOrganizationId).subscribe({
      next: res => { this.users = res.data; this.loading = false; },
      error: () => {
        this.users = [];
        this.loading = false;
        this.errorMessage = 'Impossible de charger les utilisateurs. Réessayez dans quelques instants.';
      },
    });
  }

  get filteredUsers(): ClientUser[] {
    const query = this.search.trim().toLowerCase();
    if (!query) return this.users;
    return this.users.filter((user) => [user.name, user.email, user.role, user.organization?.name]
      .some((value) => String(value ?? '').toLowerCase().includes(query)));
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
