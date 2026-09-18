import { Component, OnInit, ChangeDetectorRef } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { LogService } from '../../services/log';

@Component({
  selector: 'app-logs',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './logs.html'
})
export class Logs implements OnInit {
  logs: any[] = [];
  fLogs: any[] = []; 
  load: boolean = true;
  
  srch: string = '';
  fTyp: string = '';

  constructor(private logSvc: LogService, private cdRef: ChangeDetectorRef) {}

  ngOnInit() {
    this.getLogs();
  }

  getLogs() {
    this.load = true;
    this.logSvc.getAll().subscribe({
      next: (res: any) => {
        this.logs = res;
        this.fLogs = [...this.logs]; // Initialise la liste filtrée
        this.load = false;
        this.cdRef.detectChanges();
      },
      error: (err) => {
        console.error('Erreur Logs:', err);
        this.load = false;
      }
    });
  }

  filtrer() {
    this.fLogs = this.logs.filter(l => {
      const q = this.srch.toLowerCase();
      // On sécurise l.cible au cas où il serait null dans la BDD
      const mtchSrch = l.act.toLowerCase().includes(q) || 
                       (l.cible && l.cible.toLowerCase().includes(q)) || 
                       l.usr.toLowerCase().includes(q);
      const mtchTyp = this.fTyp ? l.typ === this.fTyp : true;
      
      return mtchSrch && mtchTyp;
    });
  }
}