export interface ApiResponse<T> {
  success: boolean;
  data: T;
  message?: string;
}

export interface Organization {
  id: number;
  name: string;
  public_id?: string;
  active?: boolean;
}

export interface DeviceGroup {
  id: number;
  name: string;
  organization_id: number;
  organization?: Organization | null;
  terminals_count?: number;
}

export interface Terminal {
  id: number;
  public_id?: string;
  organization_id?: number | null;
  organization?: Organization | null;
  device_group_id?: number | null;
  device_group?: DeviceGroup | null;
  imei?: string | null;
  num_serie?: string | null;
  serial_number?: string | null;
  modele?: string | null;
  model?: string | null;
  manufacturer?: string | null;
  version_os?: string | null;
  android_version?: string | null;
  livreur?: string | null;
  label?: string | null;
  groupe?: string | null;
  batterie?: number | null;
  battery_level?: number | null;
  storage_total_mb?: number | null;
  storage_free_mb?: number | null;
  statut?: string | null;
  status?: string | null;
  connectivity_status?: 'online' | 'offline' | null;
  management_state?: 'active' | 'locked' | 'wiped' | null;
  enrollment_status?: string | null;
  agent_version?: string | null;
  last_seen_at?: string | null;
  lic?: any;
}

export interface EnrollmentPayload {
  api_url: string;
}

export interface DeviceEnrollment {
  public_id: string;
  id?: number;
  organization_id: number;
  organization?: Organization | null;
  device_group_id?: number | null;
  device_group?: DeviceGroup | null;
  label: string | null;
  expires_at: string;
  used_at?: string | null;
  revoked_at?: string | null;
  created_at?: string;
  enrollment_token?: string;
  enrollment_payload?: EnrollmentPayload;
}

export interface CreateDeviceGroupPayload {
  name: string;
  organization_id?: number;
}

export interface CreateEnrollmentPayload {
  label: string;
  expires_in_minutes: number;
  organization_id?: number;
  device_group_id?: number;
}

export interface CreatedEnrollment extends DeviceEnrollment {
  label: string;
  enrollment_token: string;
  enrollment_payload: EnrollmentPayload;
}

export type DeviceCommandType = 'locate' | 'lock' | 'wipe';

export type DeviceCommandStatus =
  | 'queued'
  | 'sent'
  | 'acknowledged'
  | 'succeeded'
  | 'failed'
  | 'expired';

export interface DeviceCommandTimestamps {
  queued_at?: string | null;
  sent_at?: string | null;
  last_delivery_at?: string | null;
  acknowledged_at?: string | null;
  completed_at?: string | null;
  failed_at?: string | null;
  expires_at?: string | null;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface DeviceCommand extends DeviceCommandTimestamps {
  public_id: string;
  terminal_id?: number;
  type: DeviceCommandType;
  status: DeviceCommandStatus;
  payload?: Record<string, unknown> | null;
  timestamps?: DeviceCommandTimestamps;
  result?: unknown;
  error?: unknown;
  error_code?: string | null;
  error_message?: string | null;
}

export interface CreateDeviceCommandPayload {
  type: DeviceCommandType;
  payload?: Record<string, unknown>;
  confirmation?: string;
  current_password?: string;
}
