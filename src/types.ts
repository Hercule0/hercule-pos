export type Role = 'owner' | 'admin' | 'support' | 'read_only';
export type AdminRole = Role;

export interface AdminUser {
  id: number;
  username: string;
  email?: string;
  role: Role;
  is_active: boolean;
  must_change_password: boolean;
  totp_enabled: boolean;
  mfa_enabled?: boolean;
  totp_secret?: string;
  recovery_codes?: string[];
  created_at: string;
  last_login?: string;
  last_login_at?: string;
  avatar_color: string;
}

export type LicensePlan = 'trial' | 'monthly' | 'semi_annual' | 'annual' | 'custom' | 'lifetime';
export type LicenseStatus = 'active' | 'suspended' | 'revoked' | 'expired';

export interface LicenseActivation {
  id: number;
  license_id: number;
  hwid: string;
  device_name: string;
  is_active: boolean;
  activated_at: string;
  last_seen_at: string;
  ip_address: string;
}

export interface License {
  id: number;
  customer_id: number;
  customer_name: string;
  customer_email?: string;
  license_key: string; // format: XXXX-XXXX-XXXX-XXXX-XXXX
  plan: LicensePlan;
  status: LicenseStatus;
  max_activations: number;
  issued_at: string;
  expires_at: string | null; // null = lifetime
  last_verified_at: string | null;
  notes?: string;
  created_at: string;
  updated_at: string;
  activations: LicenseActivation[];
}

export interface Customer {
  id: number;
  name: string;
  email?: string;
  phone?: string;
  notes?: string;
  created_at: string;
  active_licenses_count?: number;
}

export type VerificationResult = 
  | 'ok' 
  | 'invalid_key' 
  | 'hwid_mismatch' 
  | 'expired' 
  | 'suspended' 
  | 'revoked' 
  | 'activation_limit';

export interface VerificationLog {
  id: number;
  license_id?: number;
  license_key: string;
  hwid?: string;
  device_name?: string;
  result: VerificationResult;
  ip_address: string;
  created_at: string;
}

export type RecoveryStatus = 'pending' | 'approved' | 'rejected' | 'expired' | 'completed';

export interface PasswordRecoveryRequest {
  id: number;
  license_key: string;
  customer_name: string;
  hwid: string;
  requested_username: string;
  status: RecoveryStatus;
  admin_note?: string;
  token_hash?: string;
  token_raw?: string; // transient for approval demo
  token_expires_at?: string | null;
  delivered_at?: string | null;
  used_at?: string | null;
  reviewed_by?: string | null;
  reviewed_at?: string | null;
  created_at: string;
  updated_at: string;
}

export interface SubscriptionEvent {
  id: number;
  license_id: number;
  license_key: string;
  event_type: 'issued' | 'renewed' | 'plan_changed' | 'suspended' | 'revoked' | 'reactivated';
  previous_expires_at: string | null;
  new_expires_at: string | null;
  note?: string;
  created_by: string;
  created_at: string;
}

export interface AdminAuditLog {
  id: number;
  actor_id: number;
  actor_username: string;
  target_id?: number;
  action: string;
  details?: string;
  ip_address: string;
  created_at: string;
}

export interface AppNotification {
  id: string;
  type: 'recovery' | 'expiry' | 'security' | 'activation';
  title: string;
  message: string;
  timestamp: string;
  read: boolean;
  linkTab?: string;
  linkId?: number | string;
}
