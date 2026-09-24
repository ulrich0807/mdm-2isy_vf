import { CommonModule } from '@angular/common';
import { ChangeDetectorRef, Component, OnInit } from '@angular/core';
import { FormsModule } from '@angular/forms';
import {
  ContactRequestItem,
  ContactRequestService,
  ContactRequestStatus,
} from '../../services/contact-request.service';

@Component({
  selector: 'app-contact-requests',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './contact-requests.html',
})
export class ContactRequests implements OnInit {
  requests: ContactRequestItem[] = [];
  filter: 'all' | ContactRequestStatus = 'all';
  loading = true;
  error = '';
  updating: Record<number, boolean> = {};
  selected: ContactRequestItem | null = null;

  constructor(
    private contactService: ContactRequestService,
    private cdr: ChangeDetectorRef,
  ) {}

  get newCount(): number {
    return this.requests.filter((request) => request.status === 'new').length;
  }

  ngOnInit(): void {
    this.load();
  }

  load(): void {
    this.loading = true;
    this.error = '';
    this.contactService.getAll(this.filter === 'all' ? undefined : this.filter).subscribe({
      next: (response) => {
        this.requests = response.success ? response.data : [];
        this.loading = false;
        this.cdr.detectChanges();
      },
      error: () => {
        this.requests = [];
        this.error = 'Impossible de charger les messages reçus.';
        this.loading = false;
        this.cdr.detectChanges();
      },
    });
  }

  open(request: ContactRequestItem): void {
    this.selected = request;
    if (request.status === 'new') this.updateStatus(request, 'read');
  }

  close(): void {
    this.selected = null;
  }

  updateStatus(request: ContactRequestItem, status: 'read' | 'resolved'): void {
    if (this.updating[request.id]) return;
    this.updating[request.id] = true;
    this.contactService.setStatus(request.id, status).subscribe({
      next: (response) => {
        this.updating[request.id] = false;
        if (!response.success) return;
        const index = this.requests.findIndex((item) => item.id === request.id);
        if (index !== -1) this.requests[index] = response.data;
        if (this.selected?.id === request.id) this.selected = response.data;
        if (this.filter !== 'all' && this.filter !== response.data.status) this.load();
        else this.cdr.detectChanges();
      },
      error: () => {
        this.updating[request.id] = false;
        this.error = 'La mise à jour du message a échoué.';
        this.cdr.detectChanges();
      },
    });
  }

  statusLabel(status: ContactRequestStatus): string {
    return ({ new: 'Nouveau', read: 'Lu', resolved: 'Résolu' })[status];
  }
}
