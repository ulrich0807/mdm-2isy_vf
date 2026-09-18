import { Organization } from './fleet.models';

export interface ClientUser {
  id: number;
  name: string;
  email: string;
  role: string;
  organization_id?: number | null;
  organization_name?: string | null;
  organization?: Organization | null;
  created_at: string;
}

export interface CreateClientPayload {
  name: string;
  organization_name: string;
  email: string;
  password: string;
}
