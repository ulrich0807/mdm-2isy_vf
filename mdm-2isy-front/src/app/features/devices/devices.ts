import { ChangeDetectorRef, Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import {
  CreateDeviceCommandPayload,
  CreatedEnrollment,
  DeviceCommand,
  DeviceCommandStatus,
  DeviceCommandType,
  DeviceEnrollment,
  DeviceGroup,
  Organization,
  Terminal,
} from '../../models/fleet.models';
import { Auth } from '../../services/auth';
import { DeviceEnrollmentService } from '../../services/device-enrollment';
import { DeviceGroupService } from '../../services/device-group';
import { OrganizationService } from '../../services/organization';
import { TermService } from '../../services/term';
import { ProfService } from '../../services/prof';

type CommandFeedback = {
  kind: 'success' | 'danger' | 'info';
  message: string;
};

@Component({
  selector: 'app-devices',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './devices.html',
})
export class Devices implements OnInit {
  terminaux: Terminal[] = [];
  fTerms: Terminal[] = [];
  organizations: Organization[] = [];
  groupes: DeviceGroup[] = [];
  enrollments: DeviceEnrollment[] = [];
  profs: any[] = [];

  chargement = true;
  chargementOrganisations = false;
  chargementGroupes = false;
  chargementEnrollments = false;
  contextError = '';
  groupError = '';
  enrollmentListError = '';

  isSuperAdmin = false;
  selectedOrganizationId: number | null = null;

  srch = '';
  fMod = '';
  fBat = '';
  fStat = '';
  fGrp: number | null = null;
  lstMod: string[] = [];

  nouveauGroupe = '';
  creationGroupe = false;

  afficherModal = false;
  generationEnCours = false;
  enrollmentError = '';
  copyStatus = '';
  generatedEnrollment: CreatedEnrollment | null = null;
  enrollmentForm: {
    device_group_id: number | null;
    label: string;
    expires_in_minutes: number;
  } = this.emptyEnrollmentForm();

  commandHistories: Record<string, DeviceCommand[]> = {};
  commandHistoryLoading: Record<string, boolean> = {};
  commandHistoryErrors: Record<string, string> = {};
  commandSubmitting: Record<string, boolean> = {};
  commandFeedback: Record<string, CommandFeedback> = {};
  expandedCommandTerminalPublicId: string | null = null;

  afficherModalWipe = false;
  wipeTerminal: Terminal | null = null;
  wipeConfirmation = '';
  wipePassword = '';
  wipeError = '';
  wipeSubmitting = false;

  constructor(
    private termSvc: TermService,
    private organizationSvc: OrganizationService,
    private groupSvc: DeviceGroupService,
    private enrollmentSvc: DeviceEnrollmentService,
    private profSvc: ProfService,
    private auth: Auth,
    private cdRef: ChangeDetectorRef,
  ) {}

  ngOnInit(): void {
    this.isSuperAdmin = this.auth.role === 'super_admin';
    this.selectedOrganizationId = this.auth.user?.organization_id ?? null;

    if (this.isSuperAdmin) {
      this.chargerOrganisations();
      return;
    }

    this.chargerContexte();
  }

  chargerOrganisations(): void {
    this.chargementOrganisations = true;
    this.contextError = '';

    this.organizationSvc.getAll().subscribe({
      next: (res) => {
        this.organizations = res.success ? res.data : [];
        const preferredOrganizationExists = this.organizations.some(
          (organization) => organization.id === this.selectedOrganizationId,
        );
        this.selectedOrganizationId = preferredOrganizationExists
          ? this.selectedOrganizationId
          : (this.organizations[0]?.id ?? null);
        this.chargementOrganisations = false;

        if (this.selectedOrganizationId !== null) {
          this.chargerContexte();
        } else {
          this.clearContext();
          this.chargement = false;
        }
        this.cdRef.detectChanges();
      },
      error: (err) => {
        this.contextError = this.apiError(err, 'Impossible de charger les organisations.');
        this.chargementOrganisations = false;
        this.chargement = false;
        this.cdRef.detectChanges();
      },
    });
  }

  changerOrganisation(organizationId: number | null): void {
    this.selectedOrganizationId = organizationId;
    this.srch = '';
    this.fMod = '';
    this.fBat = '';
    this.fStat = '';
    this.fGrp = null;
    this.resetCommandContext();

    if (organizationId === null) {
      this.clearContext();
      return;
    }

    this.chargerContexte();
  }

  chargerContexte(): void {
    this.contextError = '';
    this.chargerFlotte();
    this.chargerGroupes();
    this.chargerEnrollments();
    this.chargerProfils();
  }

  chargerFlotte(): void {
    this.chargement = true;
    this.termSvc.getAll(this.organizationIdForRequest()).subscribe({
      next: (res) => {
        this.terminaux = res.success ? res.data : [];
        this.lstMod = Array.from(
          new Set(
            this.terminaux
              .map((terminal) => this.terminalModel(terminal))
              .filter((model) => model !== '—'),
          ),
        );
        this.filtrer();
        this.chargement = false;
        this.cdRef.detectChanges();
      },
      error: (err) => {
        this.contextError = this.apiError(err, 'Impossible de charger les terminaux.');
        this.terminaux = [];
        this.fTerms = [];
        this.chargement = false;
        this.cdRef.detectChanges();
      },
    });
  }

  chargerGroupes(): void {
    this.chargementGroupes = true;
    this.groupError = '';
    this.groupSvc.getAll(this.organizationIdForRequest()).subscribe({
      next: (res) => {
        this.groupes = res.success ? res.data : [];
        this.chargementGroupes = false;
        this.filtrer();
        this.cdRef.detectChanges();
      },
      error: (err) => {
        this.groupError = this.apiError(err, 'Impossible de charger les groupes.');
        this.groupes = [];
        this.chargementGroupes = false;
        this.cdRef.detectChanges();
      },
    });
  }

  chargerEnrollments(): void {
    this.chargementEnrollments = true;
    this.enrollmentListError = '';
    this.enrollmentSvc.getAll(this.organizationIdForRequest()).subscribe({
      next: (res) => {
        this.enrollments = res.success ? res.data : [];
        this.chargementEnrollments = false;
        this.cdRef.detectChanges();
      },
      error: (err) => {
        this.enrollmentListError = this.apiError(
          err,
          "Impossible de charger les invitations d'enrôlement.",
        );
        this.enrollments = [];
        this.chargementEnrollments = false;
        this.cdRef.detectChanges();
      },
    });
  }

  filtrer(): void {
    const query = this.srch.trim().toLowerCase();

    this.fTerms = this.terminaux.filter((terminal) => {
      const searchableValues = [
        terminal.imei,
        this.terminalSerial(terminal),
        this.terminalLabel(terminal),
        this.terminalModel(terminal),
        this.terminalGroupName(terminal),
      ];
      const matchesSearch = searchableValues.some((value) =>
        String(value ?? '').toLowerCase().includes(query),
      );
      const matchesModel = this.fMod ? this.terminalModel(terminal) === this.fMod : true;
      const matchesStatus = this.fStat
        ? this.terminalStatus(terminal) === this.fStat
          || this.terminalManagementState(terminal) === this.fStat
        : true;
      const matchesGroup = this.fGrp
        ? (terminal.device_group_id ?? terminal.device_group?.id) === this.fGrp
        : true;
      const battery = this.terminalBattery(terminal);
      const matchesBattery = this.fBat === 'ok'
        ? battery !== null && battery > 20
        : this.fBat === 'low'
          ? battery !== null && battery <= 20
          : true;

      return matchesSearch && matchesModel && matchesStatus && matchesGroup && matchesBattery;
    });
  }

  creerGroupe(): void {
    const name = this.nouveauGroupe.trim();
    if (!name) {
      this.groupError = 'Le nom du groupe est obligatoire.';
      return;
    }
    if (this.isSuperAdmin && this.selectedOrganizationId === null) {
      this.groupError = 'Sélectionnez une organisation.';
      return;
    }

    this.creationGroupe = true;
    this.groupError = '';
    this.groupSvc
      .add({
        name,
        ...(this.isSuperAdmin && this.selectedOrganizationId !== null
          ? { organization_id: this.selectedOrganizationId }
          : {}),
      })
      .subscribe({
        next: (res) => {
          if (res.success) {
            this.nouveauGroupe = '';
            this.chargerGroupes();
          }
          this.creationGroupe = false;
          this.cdRef.detectChanges();
        },
        error: (err) => {
          this.groupError = this.apiError(err, 'Impossible de créer le groupe.');
          this.creationGroupe = false;
          this.cdRef.detectChanges();
        },
      });
  }

  supprimerGroupe(group: DeviceGroup): void {
    if (!confirm(`Supprimer le groupe « ${group.name} » ?`)) {
      return;
    }

    this.groupSvc.del(group.id, this.organizationIdForRequest()).subscribe({
      next: () => {
        if (this.fGrp === group.id) {
          this.fGrp = null;
        }
        this.chargerGroupes();
        this.chargerFlotte();
      },
      error: (err) => {
        this.groupError = this.apiError(err, 'Impossible de supprimer ce groupe.');
        this.cdRef.detectChanges();
      },
    });
  }

  affecterGroupe(terminal: Terminal, deviceGroupId: number | null): void {
    this.contextError = '';
    this.termSvc.updateGroup(terminal.id, deviceGroupId).subscribe({
      next: (res) => {
        if (res.success) {
          const index = this.terminaux.findIndex((item) => item.id === terminal.id);
          if (index !== -1) {
            this.terminaux[index] = res.data;
          }
          this.filtrer();
        }
        this.cdRef.detectChanges();
      },
      error: (err) => {
        this.contextError = this.apiError(err, "Impossible de modifier le groupe du terminal.");
        this.cdRef.detectChanges();
      },
    });
  }

  modifierProfil(terminal: Terminal, newProfilId: string): void {
    const parsedId = newProfilId ? parseInt(newProfilId, 10) : null;
    this.termSvc.updateProfil(terminal.id, parsedId).subscribe({
      next: (res) => {
        if (res.success && res.data) {
          const index = this.terminaux.findIndex((item) => item.id === terminal.id);
          if (index !== -1) {
            this.terminaux[index] = res.data;
          }
          this.filtrer();
        }
        this.cdRef.detectChanges();
      },
      error: (err) => {
        this.contextError = this.apiError(err, "Impossible d'affecter le profil.");
        this.cdRef.detectChanges();
      }
    });
  }

  modifierLivreur(terminal: Terminal): void {
    const currentName = this.terminalLabel(terminal);
    const newName = prompt('Nom du livreur:', currentName === '—' ? '' : currentName);
    if (newName !== null && newName.trim() !== currentName) {
      this.contextError = '';
      this.termSvc.updateLivreur(terminal.id, newName.trim()).subscribe({
        next: (res) => {
          if (res.success) {
            const index = this.terminaux.findIndex((item) => item.id === terminal.id);
            if (index !== -1) {
              this.terminaux[index] = res.data;
            }
            this.filtrer();
          }
          this.cdRef.detectChanges();
        },
        error: (err) => {
          this.contextError = this.apiError(err, "Impossible de modifier le livreur du terminal.");
          this.cdRef.detectChanges();
        },
      });
    }
  }

  ouvrirModal(): void {
    if (this.isSuperAdmin && this.selectedOrganizationId === null) {
      this.contextError = 'Sélectionnez une organisation avant de créer un enrôlement.';
      return;
    }

    this.enrollmentForm = this.emptyEnrollmentForm();
    this.generatedEnrollment = null;
    this.enrollmentError = '';
    this.copyStatus = '';
    this.afficherModal = true;
  }

  fermerModal(): void {
    this.afficherModal = false;
    this.generatedEnrollment = null;
    this.enrollmentForm = this.emptyEnrollmentForm();
    this.enrollmentError = '';
    this.copyStatus = '';
  }

  genererEnrollment(): void {
    const label = this.enrollmentForm.label.trim();
    const expiresInMinutes = Number(this.enrollmentForm.expires_in_minutes);
    if (!label) {
      this.enrollmentError = "Le libellé de l'enrôlement est obligatoire.";
      return;
    }
    if (!Number.isInteger(expiresInMinutes) || expiresInMinutes <= 0) {
      this.enrollmentError = 'La durée de validité doit être positive.';
      return;
    }

    this.generationEnCours = true;
    this.enrollmentError = '';
    this.enrollmentSvc
      .add({
        label,
        expires_in_minutes: expiresInMinutes,
        ...(this.enrollmentForm.device_group_id !== null
          ? { device_group_id: this.enrollmentForm.device_group_id }
          : {}),
        ...(this.isSuperAdmin && this.selectedOrganizationId !== null
          ? { organization_id: this.selectedOrganizationId }
          : {}),
      })
      .subscribe({
        next: (res) => {
          if (res.success && res.data.enrollment_token && res.data.enrollment_payload?.api_url) {
            this.generatedEnrollment = res.data;
            this.chargerEnrollments();
          } else {
            this.enrollmentError = "L'API n'a pas renvoyé les informations d'enrôlement attendues.";
          }
          this.generationEnCours = false;
          this.cdRef.detectChanges();
        },
        error: (err) => {
          this.enrollmentError = this.apiError(err, "Impossible de générer le jeton d'enrôlement.");
          this.generationEnCours = false;
          this.cdRef.detectChanges();
        },
      });
  }

  async copierJeton(): Promise<void> {
    const token = this.generatedEnrollment?.enrollment_token;
    if (!token) {
      return;
    }
    if (!navigator.clipboard?.writeText) {
      this.copyStatus = 'Copie automatique indisponible : sélectionnez le jeton manuellement.';
      return;
    }

    try {
      await navigator.clipboard.writeText(token);
      this.copyStatus = 'Jeton copié.';
    } catch {
      this.copyStatus = 'Échec de la copie : sélectionnez le jeton manuellement.';
    }
    this.cdRef.detectChanges();
  }

  supprimerEnrollment(enrollment: DeviceEnrollment): void {
    if (!confirm(`Révoquer l'enrôlement « ${this.enrollmentLabel(enrollment)} » ?`)) {
      return;
    }

    this.enrollmentSvc.del(enrollment.public_id, this.organizationIdForRequest()).subscribe({
      next: () => this.chargerEnrollments(),
      error: (err) => {
        this.enrollmentListError = this.apiError(
          err,
          "Impossible de révoquer cette invitation d'enrôlement.",
        );
        this.cdRef.detectChanges();
      },
    });
  }

  canSendCommand(terminal: Terminal): boolean {
    return terminal.enrollment_status === 'enrolled'
      && terminal.management_state !== 'wiped'
      && Boolean(terminal.public_id)
      && terminal.lic?.statut === 'Active';
  }

  commandActionTitle(terminal: Terminal): string {
    if (!terminal.lic || terminal.lic.statut !== 'Active') {
      return 'Commande indisponible : le terminal doit avoir une licence active.';
    }
    if (terminal.enrollment_status !== 'enrolled') {
      return 'Commande indisponible : le terminal doit être enrôlé.';
    }
    if (terminal.management_state === 'wiped') {
      return 'Commande indisponible : ce terminal a été effacé.';
    }
    if (!terminal.public_id) {
      return "Commande indisponible : l'identifiant public du terminal est absent.";
    }
    if (terminal.connectivity_status === 'offline') {
      return 'Le terminal est hors ligne : la commande sera mise en file.';
    }
    return 'Mettre la commande en file.';
  }

  envoyerCommande(terminal: Terminal, type: 'locate' | 'lock'): void {
    if (!this.canSendCommand(terminal)) {
      this.setCommandFeedback(terminal, 'danger', this.commandActionTitle(terminal));
      return;
    }

    const commandLabel = type === 'locate' ? 'localisation' : 'verrouillage';
    this.dispatchCommand(
      terminal,
      { type },
      `Commande de ${commandLabel} mise en file.`,
    );
  }

  supprimerTerminal(terminal: Terminal): void {
    if (!confirm(`Supprimer définitivement le terminal ${this.terminalImei(terminal)} de la base de données ?\nCeci n'efface pas les données du téléphone, ça le retire juste du tableau de bord.`)) {
      return;
    }

    this.termSvc.del(terminal.id).subscribe({
      next: () => this.chargerFlotte(),
      error: (err) => {
        this.contextError = this.apiError(err, 'Impossible de supprimer ce terminal.');
        this.cdRef.detectChanges();
      }
    });
  }

  ouvrirModalWipe(terminal: Terminal): void {
    if (!this.canSendCommand(terminal)) {
      this.setCommandFeedback(terminal, 'danger', this.commandActionTitle(terminal));
      return;
    }

    this.wipeTerminal = terminal;
    this.wipeConfirmation = '';
    this.wipePassword = '';
    this.wipeError = '';
    this.wipeSubmitting = false;
    this.afficherModalWipe = true;
  }

  fermerModalWipe(): void {
    this.afficherModalWipe = false;
    this.wipeTerminal = null;
    this.wipeConfirmation = '';
    this.wipePassword = '';
    this.wipeError = '';
    this.wipeSubmitting = false;
  }

  canConfirmWipe(): boolean {
    const publicId = this.wipeTerminal?.public_id;
    return Boolean(
      publicId
      && this.wipeConfirmation === publicId
      && this.wipePassword.length > 0
      && !this.wipeSubmitting,
    );
  }

  confirmerWipe(): void {
    const terminal = this.wipeTerminal;
    const publicId = terminal?.public_id;
    if (!terminal || !publicId || !this.canConfirmWipe()) {
      this.wipeError = "Saisissez exactement l'identifiant public et votre mot de passe courant.";
      return;
    }

    this.wipeSubmitting = true;
    this.wipeError = '';
    this.dispatchCommand(
      terminal,
      {
        type: 'wipe',
        confirmation: this.wipeConfirmation,
        current_password: this.wipePassword,
      },
      "Commande d'effacement mise en file.",
      () => this.fermerModalWipe(),
      (message) => {
        this.wipeError = message;
        this.wipePassword = '';
        this.wipeSubmitting = false;
      },
    );
  }

  toggleCommandHistory(terminal: Terminal): void {
    const publicId = terminal.public_id;
    if (!publicId) {
      this.setCommandFeedback(
        terminal,
        'danger',
        "Historique indisponible : l'identifiant public du terminal est absent.",
      );
      return;
    }

    if (this.expandedCommandTerminalPublicId === publicId) {
      this.expandedCommandTerminalPublicId = null;
      return;
    }

    this.expandedCommandTerminalPublicId = publicId;
    if (!Object.prototype.hasOwnProperty.call(this.commandHistories, publicId)) {
      this.chargerCommandHistory(terminal);
    }
  }

  chargerCommandHistory(terminal: Terminal): void {
    const publicId = terminal.public_id;
    if (!publicId) {
      return;
    }

    this.commandHistoryLoading[publicId] = true;
    this.commandHistoryErrors[publicId] = '';
    this.termSvc.getCommands(publicId).subscribe({
      next: (res) => {
        if (!res.success) {
          this.commandHistories[publicId] = [];
          this.commandHistoryErrors[publicId] = res.message
            || "Impossible de charger l'historique des commandes.";
          this.commandHistoryLoading[publicId] = false;
          this.cdRef.detectChanges();
          return;
        }

        this.commandHistories[publicId] = Array.isArray(res.data)
          ? this.sortCommands(res.data).slice(0, 10)
          : [];
        this.commandHistoryLoading[publicId] = false;
        this.cdRef.detectChanges();
      },
      error: (err) => {
        this.commandHistoryErrors[publicId] = this.apiError(
          err,
          "Impossible de charger l'historique des commandes.",
        );
        this.commandHistoryLoading[publicId] = false;
        this.cdRef.detectChanges();
      },
    });
  }

  isCommandHistoryExpanded(terminal: Terminal): boolean {
    return Boolean(
      terminal.public_id && this.expandedCommandTerminalPublicId === terminal.public_id,
    );
  }

  isCommandHistoryLoading(terminal: Terminal): boolean {
    return Boolean(terminal.public_id && this.commandHistoryLoading[terminal.public_id]);
  }

  commandHistoryError(terminal: Terminal): string {
    return terminal.public_id ? (this.commandHistoryErrors[terminal.public_id] ?? '') : '';
  }

  commandHistory(terminal: Terminal): DeviceCommand[] {
    return terminal.public_id ? (this.commandHistories[terminal.public_id] ?? []) : [];
  }

  isCommandSubmitting(terminal: Terminal): boolean {
    return Boolean(this.commandSubmitting[this.terminalCommandKey(terminal)]);
  }

  commandFeedbackFor(terminal: Terminal): CommandFeedback | null {
    return this.commandFeedback[this.terminalCommandKey(terminal)] ?? null;
  }

  commandTypeLabel(type: DeviceCommandType): string {
    const labels: Record<DeviceCommandType, string> = {
      locate: 'Localisation',
      lock: 'Verrouillage',
      wipe: 'Effacement',
    };
    return labels[type];
  }

  commandStatusLabel(status: DeviceCommandStatus): string {
    const labels: Record<DeviceCommandStatus, string> = {
      queued: 'En file',
      sent: 'Envoyée',
      acknowledged: 'Reçue par l’agent',
      succeeded: 'Réussie',
      failed: 'Échec',
      expired: 'Expirée',
    };
    return labels[status];
  }

  commandStatusClass(status: DeviceCommandStatus): string {
    const classes: Record<DeviceCommandStatus, string> = {
      queued: 'bg-warning text-dark',
      sent: 'bg-info text-dark',
      acknowledged: 'bg-primary',
      succeeded: 'bg-success',
      failed: 'bg-danger',
      expired: 'bg-secondary',
    };
    return classes[status];
  }

  commandDisplayDate(command: DeviceCommand): string | null {
    const timestamps = command.timestamps ?? {};
    const read = (field: keyof typeof timestamps): string | null => (
      command[field] ?? timestamps[field] ?? null
    );
    const created = read('created_at');

    if (command.status === 'queued') {
      return read('queued_at') ?? created;
    }
    if (command.status === 'sent') {
      return read('last_delivery_at') ?? read('sent_at') ?? created;
    }
    if (command.status === 'acknowledged') {
      return read('acknowledged_at') ?? read('sent_at') ?? created;
    }
    if (command.status === 'failed') {
      return read('failed_at') ?? read('completed_at') ?? read('updated_at') ?? created;
    }
    if (command.status === 'succeeded') {
      return read('completed_at') ?? read('updated_at') ?? created;
    }
    return read('expires_at') ?? read('updated_at') ?? created;
  }

  commandDetail(command: DeviceCommand): string {
    if (command.error_message || command.error_code) {
      return command.error_code && command.error_message
        ? `[${command.error_code}] ${command.error_message}`
        : (command.error_message ?? command.error_code ?? '');
    }

    const detail = command.error ?? command.result;
    if (detail === null || detail === undefined || detail === '') {
      return '';
    }
    if (typeof detail === 'string') {
      return detail;
    }

    try {
      return JSON.stringify(detail);
    } catch {
      return 'Détail non affichable.';
    }
  }

  terminalSerial(terminal: Terminal): string {
    return this.display(terminal.num_serie ?? terminal.serial_number);
  }

  terminalImei(terminal: Terminal): string {
    return this.display(terminal.imei);
  }

  terminalLabel(terminal: Terminal): string {
    return this.display(terminal.label ?? terminal.livreur);
  }

  terminalModel(terminal: Terminal): string {
    return this.display(terminal.modele ?? terminal.model);
  }

  terminalManufacturer(terminal: Terminal): string {
    return this.display(terminal.manufacturer);
  }

  terminalAndroidVersion(terminal: Terminal): string {
    return this.display(terminal.version_os ?? terminal.android_version);
  }

  terminalGroupName(terminal: Terminal): string {
    const groupId = terminal.device_group_id ?? terminal.device_group?.id;
    return this.display(
      terminal.device_group?.name ??
        terminal.groupe ??
        this.groupes.find((group) => group.id === groupId)?.name,
    );
  }

  terminalBattery(terminal: Terminal): number | null {
    return terminal.batterie ?? terminal.battery_level ?? null;
  }

  terminalStorage(terminal: Terminal): number | null {
    const total = terminal.storage_total_mb;
    const free = terminal.storage_free_mb;
    if (total === null || total === undefined || free === null || free === undefined || total <= 0) {
      return null;
    }

    const usedPercent = ((total - free) / total) * 100;
    return Math.round(Math.min(100, Math.max(0, usedPercent)));
  }

  terminalStatus(terminal: Terminal): string {
    if (terminal.connectivity_status === 'online') {
      return 'En ligne';
    }
    if (terminal.connectivity_status === 'offline') {
      return 'Hors ligne';
    }
    return '—';
  }

  terminalManagementState(terminal: Terminal): string {
    if (terminal.management_state === 'active') {
      return 'Actif';
    }
    if (terminal.management_state === 'locked') {
      return 'Verrouillé';
    }
    if (terminal.management_state === 'wiped') {
      return 'Effacé';
    }
    return '—';
  }

  terminalManagementStateClass(terminal: Terminal): string {
    if (terminal.management_state === 'active') {
      return 'bg-success';
    }
    if (terminal.management_state === 'locked') {
      return 'bg-warning text-dark';
    }
    if (terminal.management_state === 'wiped') {
      return 'bg-danger';
    }
    return 'bg-secondary';
  }

  enrollmentGroupName(enrollment: DeviceEnrollment): string {
    return this.display(
      enrollment.device_group?.name ??
        this.groupes.find((group) => group.id === enrollment.device_group_id)?.name,
    );
  }

  enrollmentLabel(enrollment: DeviceEnrollment): string {
    return this.display(enrollment.label);
  }

  enrollmentStatus(enrollment: DeviceEnrollment): 'Révoquée' | 'Utilisée' | 'Expirée' | 'En attente' {
    if (enrollment.revoked_at) {
      return 'Révoquée';
    }
    if (enrollment.used_at) {
      return 'Utilisée';
    }
    if (new Date(enrollment.expires_at).getTime() <= Date.now()) {
      return 'Expirée';
    }
    return 'En attente';
  }

  enrollmentStatusClass(enrollment: DeviceEnrollment): string {
    const status = this.enrollmentStatus(enrollment);
    if (status === 'Utilisée') {
      return 'bg-success';
    }
    if (status === 'En attente') {
      return 'bg-warning text-dark';
    }
    return 'bg-secondary';
  }

  canRevokeEnrollment(enrollment: DeviceEnrollment): boolean {
    return this.enrollmentStatus(enrollment) === 'En attente';
  }

  private organizationIdForRequest(): number | undefined {
    return this.isSuperAdmin && this.selectedOrganizationId !== null
      ? this.selectedOrganizationId
      : undefined;
  }

  private display(value: string | null | undefined): string {
    const normalizedValue = value?.trim();
    return normalizedValue ? normalizedValue : '—';
  }

  private emptyEnrollmentForm(): {
    device_group_id: number | null;
    label: string;
    expires_in_minutes: number;
  } {
    return { device_group_id: null, label: '', expires_in_minutes: 60 };
  }

  private dispatchCommand(
    terminal: Terminal,
    payload: CreateDeviceCommandPayload,
    fallbackSuccessMessage: string,
    onSuccess?: () => void,
    onFailure?: (message: string) => void,
  ): void {
    const publicId = terminal.public_id;
    const key = this.terminalCommandKey(terminal);
    if (!publicId) {
      const message = "Impossible d'envoyer la commande sans identifiant public.";
      this.setCommandFeedback(terminal, 'danger', message);
      onFailure?.(message);
      return;
    }

    this.commandSubmitting[key] = true;
    this.commandFeedback[key] = {
      kind: 'info',
      message: 'Envoi de la commande...',
    };

    this.termSvc
      .createCommand(publicId, payload, this.createIdempotencyKey())
      .subscribe({
        next: (res) => {
          this.commandSubmitting[key] = false;
          if (!res.success) {
            const message = res.message || "L'API a refusé la commande.";
            this.setCommandFeedback(terminal, 'danger', message);
            onFailure?.(message);
            this.cdRef.detectChanges();
            return;
          }

          if (res.data?.public_id) {
            this.prependCommand(publicId, res.data);
          }
          this.setCommandFeedback(
            terminal,
            'success',
            res.message || fallbackSuccessMessage,
          );
          onSuccess?.();
          this.cdRef.detectChanges();
        },
        error: (err) => {
          this.commandSubmitting[key] = false;
          const message = this.apiError(err, "Impossible de mettre la commande en file.");
          this.setCommandFeedback(terminal, 'danger', message);
          onFailure?.(message);
          this.cdRef.detectChanges();
        },
      });
  }

  private prependCommand(terminalPublicId: string, command: DeviceCommand): void {
    const currentCommands = this.commandHistories[terminalPublicId] ?? [];
    this.commandHistories[terminalPublicId] = [
      command,
      ...currentCommands.filter((item) => item.public_id !== command.public_id),
    ].slice(0, 10);
  }

  private sortCommands(commands: DeviceCommand[]): DeviceCommand[] {
    return [...commands].sort((left, right) => {
      const rightDate = this.commandDisplayDate(right);
      const leftDate = this.commandDisplayDate(left);
      return (rightDate ? new Date(rightDate).getTime() : 0)
        - (leftDate ? new Date(leftDate).getTime() : 0);
    });
  }

  private setCommandFeedback(
    terminal: Terminal,
    kind: CommandFeedback['kind'],
    message: string,
  ): void {
    this.commandFeedback[this.terminalCommandKey(terminal)] = { kind, message };
  }

  private terminalCommandKey(terminal: Terminal): string {
    return terminal.public_id ?? `terminal-${terminal.id}`;
  }

  private createIdempotencyKey(): string {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
      return crypto.randomUUID();
    }
    return `mdm-web-${Date.now()}-${Math.random().toString(36).slice(2)}`;
  }

  private resetCommandContext(): void {
    this.commandHistories = {};
    this.commandHistoryLoading = {};
    this.commandHistoryErrors = {};
    this.commandSubmitting = {};
    this.commandFeedback = {};
    this.expandedCommandTerminalPublicId = null;
    this.fermerModalWipe();
  }

  private clearContext(): void {
    this.terminaux = [];
    this.fTerms = [];
    this.groupes = [];
    this.enrollments = [];
    this.lstMod = [];
    this.chargement = false;
    this.resetCommandContext();
  }

  private apiError(error: any, fallback: string): string {
    const validationErrors = error?.error?.errors;
    if (validationErrors && typeof validationErrors === 'object') {
      for (const value of Object.values(validationErrors)) {
        if (Array.isArray(value) && typeof value[0] === 'string') {
          return value[0];
        }
        if (typeof value === 'string') {
          return value;
        }
      }
    }
    return error?.error?.message || error?.error?.msg || fallback;
  }

  chargerProfils(): void {
    this.profSvc.getAll().subscribe({
      next: (res: any) => {
        this.profs = res ?? [];
        this.cdRef.detectChanges();
      },
      error: (err: any) => {
        console.error("Impossible de charger les profils.", err);
      }
    });
  }
}
