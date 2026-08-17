import React, { createContext, useContext, useState, useEffect } from 'react';
import {
  AdminUser,
  Customer,
  License,
  LicenseActivation,
  LicensePlan,
  LicenseStatus,
  VerificationLog,
  PasswordRecoveryRequest,
  SubscriptionEvent,
  AdminAuditLog,
  AppNotification,
  Role,
  VerificationResult
} from '../types';
import {
  INITIAL_ADMINS,
  INITIAL_CUSTOMERS,
  INITIAL_LICENSES,
  INITIAL_VERIFICATIONS,
  INITIAL_RECOVERY_REQUESTS,
  INITIAL_SUBSCRIPTION_EVENTS,
  INITIAL_AUDIT_LOG,
  INITIAL_NOTIFICATIONS
} from '../data/initialData';

// Helper to generate formatted license keys
export function generateLicenseKey(prefix = 'HERC'): string {
  const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  const seg = (len = 4) => {
    let s = '';
    for (let i = 0; i < len; i++) {
      s += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    return s;
  };
  return `${prefix.substring(0, 4).toUpperCase()}-${seg()}-${seg()}-${seg()}-${seg()}`;
}

// Web Audio synthesizer for pleasant non-intrusive notification chime
function playNotificationChime() {
  try {
    const AudioContextClass = window.AudioContext || (window as unknown as { webkitAudioContext: typeof AudioContext }).webkitAudioContext;
    if (!AudioContextClass) return;
    const ctx = new AudioContextClass();
    const now = ctx.currentTime;
    
    // Note 1
    const osc1 = ctx.createOscillator();
    const gain1 = ctx.createGain();
    osc1.type = 'sine';
    osc1.frequency.setValueAtTime(587.33, now); // D5
    gain1.gain.setValueAtTime(0.08, now);
    gain1.gain.exponentialRampToValueAtTime(0.001, now + 0.3);
    osc1.connect(gain1);
    gain1.connect(ctx.destination);
    osc1.start(now);
    osc1.stop(now + 0.3);

    // Note 2
    const osc2 = ctx.createOscillator();
    const gain2 = ctx.createGain();
    osc2.type = 'sine';
    osc2.frequency.setValueAtTime(880, now + 0.12); // A5
    gain2.gain.setValueAtTime(0.1, now + 0.12);
    gain2.gain.exponentialRampToValueAtTime(0.001, now + 0.5);
    osc2.connect(gain2);
    gain2.connect(ctx.destination);
    osc2.start(now + 0.12);
    osc2.stop(now + 0.5);
  } catch (e) {
    // Ignore audio play errors
  }
}

export interface ToastItem {
  id: string;
  title: string;
  message: string;
  type: 'recovery' | 'expiry' | 'security' | 'activation';
  timestamp: string;
  linkTab?: string;
  linkId?: number | string;
}

interface AppContextType {
  currentUser: AdminUser;
  setCurrentUser: (user: AdminUser) => void;
  users: AdminUser[];
  customers: Customer[];
  licenses: License[];
  verifications: VerificationLog[];
  recoveryRequests: PasswordRecoveryRequest[];
  subscriptionEvents: SubscriptionEvent[];
  auditLogs: AdminAuditLog[];
  notifications: AppNotification[];
  soundEnabled: boolean;
  setSoundEnabled: (enabled: boolean) => void;
  selectedLicenseId: number | null;
  setSelectedLicenseId: (id: number | null) => void;
  
  pushPermission: 'default' | 'granted' | 'denied' | 'unsupported';
  requestPushPermission: () => Promise<boolean>;
  triggerSimulatedAlert: (type?: 'activation' | 'recovery' | 'expiry' | 'security') => void;
  fastNotifyMode: boolean;
  setFastNotifyMode: (active: boolean) => void;
  toasts: ToastItem[];
  dismissToast: (id: string) => void;
  testInstantNotification: (customTitle?: string, customMessage?: string) => void;
  addNotification: (notif: Omit<AppNotification, 'id' | 'timestamp' | 'read'>) => void;
  
  // Actions
  issueLicense: (data: {
    customerId: number;
    plan: LicensePlan;
    maxActivations: number;
    notes?: string;
    customKey?: string;
    customExpiryDays?: number;
  }) => License;
  renewLicense: (licenseId: number, additionalDays: number, note?: string) => void;
  suspendLicense: (licenseId: number, reason: string) => void;
  reactivateLicense: (licenseId: number, note?: string) => void;
  revokeLicense: (licenseId: number, reason: string) => void;
  deleteLicense: (licenseId: number) => void;
  deactivateDevice: (licenseId: number, hwid: string) => void;
  
  addCustomer: (data: { name: string; email?: string; phone?: string; notes?: string }) => Customer;
  editCustomer: (id: number, data: { name: string; email?: string; phone?: string; notes?: string }) => void;
  deleteCustomer: (id: number) => void;
  
  approveRecoveryRequest: (id: number, adminNote: string, validityHours?: number) => { token: string; expiresAt: string };
  rejectRecoveryRequest: (id: number, adminNote: string) => void;
  
  addAdminUser: (data: { username: string; email?: string; role: Role } | string, roleParam?: Role) => AdminUser;
  updateAdminRole: (id: number, role: Role) => void;
  toggleAdminActive: (id: number) => void;
  toggleMfa: (id: number, enabled: boolean) => void;
  resetAdminMfa: (id: number) => void;
  deleteAdminUser: (id: number) => void;
  adminUsers: AdminUser[];
  
  simulateVerify: (licenseKey: string, hwid: string, ip?: string) => {
    success: boolean;
    result: VerificationResult;
    message: string;
    signature?: string;
    expiresAt?: string | null;
    plan?: string;
  };
  
  markNotificationAsRead: (id: string) => void;
  markAllNotificationsAsRead: () => void;
  clearNotifications: () => void;
  exportLicensesCsv: () => void;
  exportAuditLogCsv: () => void;
}

const AppContext = createContext<AppContextType | undefined>(undefined);

export const AppProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  // Local storage state with initial fallbacks
  const [users, setUsers] = useState<AdminUser[]>(() => {
    const saved = localStorage.getItem('hercule_admins');
    return saved ? JSON.parse(saved) : INITIAL_ADMINS;
  });

  const [currentUser, setCurrentUser] = useState<AdminUser>(() => users[0] || INITIAL_ADMINS[0]);

  const [customers, setCustomers] = useState<Customer[]>(() => {
    const saved = localStorage.getItem('hercule_customers');
    return saved ? JSON.parse(saved) : INITIAL_CUSTOMERS;
  });

  const [licenses, setLicenses] = useState<License[]>(() => {
    const saved = localStorage.getItem('hercule_licenses');
    return saved ? JSON.parse(saved) : INITIAL_LICENSES;
  });

  const [verifications, setVerifications] = useState<VerificationLog[]>(() => {
    const saved = localStorage.getItem('hercule_verifications');
    return saved ? JSON.parse(saved) : INITIAL_VERIFICATIONS;
  });

  const [recoveryRequests, setRecoveryRequests] = useState<PasswordRecoveryRequest[]>(() => {
    const saved = localStorage.getItem('hercule_recovery_requests');
    return saved ? JSON.parse(saved) : INITIAL_RECOVERY_REQUESTS;
  });

  const [subscriptionEvents, setSubscriptionEvents] = useState<SubscriptionEvent[]>(() => {
    const saved = localStorage.getItem('hercule_sub_events');
    return saved ? JSON.parse(saved) : INITIAL_SUBSCRIPTION_EVENTS;
  });

  const [auditLogs, setAuditLogs] = useState<AdminAuditLog[]>(() => {
    const saved = localStorage.getItem('hercule_audit_log');
    return saved ? JSON.parse(saved) : INITIAL_AUDIT_LOG;
  });

  const [notifications, setNotifications] = useState<AppNotification[]>(() => {
    const saved = localStorage.getItem('hercule_notifications');
    return saved ? JSON.parse(saved) : INITIAL_NOTIFICATIONS;
  });

  const [toasts, setToasts] = useState<ToastItem[]>([]);
  const [soundEnabled, setSoundEnabled] = useState<boolean>(true);
  const [selectedLicenseId, setSelectedLicenseId] = useState<number | null>(null);
  const [fastNotifyMode, setFastNotifyMode] = useState<boolean>(false);
  
  const [pushPermission, setPushPermission] = useState<'default' | 'granted' | 'denied' | 'unsupported'>(() => {
    if (typeof window !== 'undefined' && 'Notification' in window) {
      return Notification.permission as 'default' | 'granted' | 'denied';
    }
    return 'unsupported';
  });

  // Sync to local storage
  useEffect(() => {
    localStorage.setItem('hercule_admins', JSON.stringify(users));
  }, [users]);
  useEffect(() => {
    localStorage.setItem('hercule_customers', JSON.stringify(customers));
  }, [customers]);
  useEffect(() => {
    localStorage.setItem('hercule_licenses', JSON.stringify(licenses));
  }, [licenses]);
  useEffect(() => {
    localStorage.setItem('hercule_verifications', JSON.stringify(verifications));
  }, [verifications]);
  useEffect(() => {
    localStorage.setItem('hercule_recovery_requests', JSON.stringify(recoveryRequests));
  }, [recoveryRequests]);
  useEffect(() => {
    localStorage.setItem('hercule_sub_events', JSON.stringify(subscriptionEvents));
  }, [subscriptionEvents]);
  useEffect(() => {
    localStorage.setItem('hercule_audit_log', JSON.stringify(auditLogs));
  }, [auditLogs]);
  useEffect(() => {
    localStorage.setItem('hercule_notifications', JSON.stringify(notifications));
  }, [notifications]);

  const addAuditLog = (action: string, details: string, targetId?: number) => {
    const newLog: AdminAuditLog = {
      id: Date.now(),
      actor_id: currentUser.id,
      actor_username: currentUser.username,
      target_id: targetId,
      action,
      details,
      ip_address: '192.168.1.100',
      created_at: new Date().toISOString().replace('T', ' ').substring(0, 19),
    };
    setAuditLogs(prev => [newLog, ...prev]);
  };

  // Request native system / phone push notification permission
  const requestPushPermission = async (): Promise<boolean> => {
    if (typeof window === 'undefined' || !('Notification' in window)) {
      setPushPermission('unsupported');
      return false;
    }
    try {
      const result = await Notification.requestPermission();
      setPushPermission(result as 'default' | 'granted' | 'denied');
      if (result === 'granted') {
        // Send a test welcome push directly to phone / browser notification tray
        try {
          const welcomeNotif = new Notification('🔔 Hercule Push Notifications Enabled', {
            body: 'Real-time phone alerts are active for license activations, expiration warnings, and terminal recovery.',
            icon: '/favicon.ico',
            badge: '/favicon.ico',
            tag: 'hercule-welcome',
          });
          welcomeNotif.onclick = () => {
            window.focus();
            welcomeNotif.close();
          };
        } catch (err) {
          console.warn('System push notification error:', err);
        }

        if (typeof navigator !== 'undefined' && 'vibrate' in navigator) {
          try { navigator.vibrate([150, 50, 150]); } catch (e) {}
        }

        addNotification({
          type: 'security',
          title: 'System Notifications Activated',
          message: 'Push notifications granted. You will receive live alerts directly in your phone shade & desktop tray.',
        });
        return true;
      }
      return false;
    } catch (e) {
      console.error('Error requesting notification permission:', e);
      return false;
    }
  };

  const dismissToast = (id: string) => {
    setToasts(prev => prev.filter(t => t.id !== id));
  };

  const addNotification = (notif: Omit<AppNotification, 'id' | 'timestamp' | 'read'>) => {
    const newId = `notif-${Date.now()}-${Math.random().toString(36).substring(2, 5)}`;
    const timestamp = new Date().toISOString().replace('T', ' ').substring(0, 19);
    
    const newNotif: AppNotification = {
      ...notif,
      id: newId,
      timestamp,
      read: false,
    };
    
    setNotifications(prev => [newNotif, ...prev]);

    // Instant in-app floating toast banner
    const toastItem: ToastItem = {
      id: newId,
      title: notif.title,
      message: notif.message,
      type: notif.type,
      timestamp,
      linkTab: notif.linkTab,
      linkId: notif.linkId,
    };
    setToasts(prev => [toastItem, ...prev.slice(0, 3)]); // Keep max 4 toasts

    // Auto dismiss toast after 4.5 seconds
    setTimeout(() => {
      dismissToast(newId);
    }, 4500);

    // Audio chime
    if (soundEnabled) {
      playNotificationChime();
    }

    // Physical Mobile Vibration (Haptic feedback)
    if (typeof navigator !== 'undefined' && 'vibrate' in navigator) {
      try {
        if (notif.type === 'recovery' || notif.type === 'security') {
          navigator.vibrate([200, 100, 200]);
        } else {
          navigator.vibrate([120, 60, 120]);
        }
      } catch (e) {}
    }

    // Native Browser / Phone System Notification
    if (typeof window !== 'undefined' && 'Notification' in window && Notification.permission === 'granted') {
      try {
        const sysNotif = new Notification(notif.title, {
          body: notif.message,
          icon: '/favicon.ico',
          badge: '/favicon.ico',
          tag: newId,
        });
        sysNotif.onclick = () => {
          window.focus();
          sysNotif.close();
        };
      } catch (e) {
        console.warn('System push error', e);
      }
    }
  };

  // Instant Test Notification
  const testInstantNotification = (customTitle?: string, customMessage?: string) => {
    addNotification({
      type: 'activation',
      title: customTitle || '⚡ Live Device Activation Alert',
      message: customMessage || 'POS Terminal #07 (HWID: A9-8B-3C-91-F2) successfully validated license key.',
      linkTab: 'licenses',
    });
  };

  // Trigger Simulated Alert
  const triggerSimulatedAlert = (specificType?: 'activation' | 'recovery' | 'expiry' | 'security') => {
    const types: Array<'activation' | 'recovery' | 'expiry' | 'security'> = ['activation', 'recovery', 'expiry', 'security'];
    const chosenType = specificType || types[Math.floor(Math.random() * types.length)];
    
    if (chosenType === 'activation') {
      const termId = Math.floor(Math.random() * 20) + 1;
      const hwid = `A${Math.floor(Math.random()*9)}-${Math.floor(Math.random()*90+10)}-C${Math.floor(Math.random()*9)}-${Math.floor(Math.random()*90+10)}`;
      addNotification({
        type: 'activation',
        title: `POS Terminal #${termId} Online`,
        message: `Hardware fingerprint [${hwid}] authenticated and signed session token.`,
        linkTab: 'licenses',
      });
    } else if (chosenType === 'recovery') {
      const username = ['admin_support', 'cashier_manager', 'supervisor_pos', 'shift_leader'][Math.floor(Math.random() * 4)];
      addNotification({
        type: 'recovery',
        title: 'Urgent Recovery Request',
        message: `Staff account "${username}" requested emergency 2FA master token bypass.`,
        linkTab: 'recovery',
      });
    } else if (chosenType === 'expiry') {
      const days = Math.floor(Math.random() * 5) + 1;
      addNotification({
        type: 'expiry',
        title: `License Expiring in ${days} Days`,
        message: `Plan "Enterprise POS" for client will automatically suspend unless renewed.`,
        linkTab: 'licenses',
      });
    } else {
      addNotification({
        type: 'security',
        title: 'Abnormal Verification Attempt',
        message: `Hardware mismatch detected from untrusted remote node 198.51.100.${Math.floor(Math.random()*200+1)}.`,
        linkTab: 'health',
      });
    }
  };

  // Fast Notification Simulator Timer
  useEffect(() => {
    if (!fastNotifyMode) return;
    const interval = setInterval(() => {
      triggerSimulatedAlert();
    }, 12000); // Trigger live notification every 12 seconds in fast mode
    return () => clearInterval(interval);
  }, [fastNotifyMode]);

  // License Management
  const issueLicense = (data: {
    customerId: number;
    plan: LicensePlan;
    maxActivations: number;
    notes?: string;
    customKey?: string;
    customExpiryDays?: number;
  }) => {
    const cust = customers.find(c => c.id === data.customerId);
    const now = new Date();
    let expiresAt: string | null = null;

    if (data.plan === 'trial') {
      const d = new Date(now);
      d.setDate(d.getDate() + 21);
      expiresAt = d.toISOString().replace('T', ' ').substring(0, 19);
    } else if (data.plan === 'monthly') {
      const d = new Date(now);
      d.setMonth(d.getMonth() + 1);
      expiresAt = d.toISOString().replace('T', ' ').substring(0, 19);
    } else if (data.plan === 'semi_annual') {
      const d = new Date(now);
      d.setMonth(d.getMonth() + 6);
      expiresAt = d.toISOString().replace('T', ' ').substring(0, 19);
    } else if (data.plan === 'annual') {
      const d = new Date(now);
      d.setFullYear(d.getFullYear() + 1);
      expiresAt = d.toISOString().replace('T', ' ').substring(0, 19);
    } else if (data.plan === 'custom' && data.customExpiryDays) {
      const d = new Date(now);
      d.setDate(d.getDate() + data.customExpiryDays);
      expiresAt = d.toISOString().replace('T', ' ').substring(0, 19);
    } else if (data.plan === 'lifetime') {
      expiresAt = null;
    }

    const key = data.customKey && data.customKey.trim() !== '' 
      ? data.customKey.trim() 
      : generateLicenseKey(cust ? cust.name.substring(0, 4) : 'HERC');

    const newId = Date.now();
    const newLicense: License = {
      id: newId,
      customer_id: data.customerId,
      customer_name: cust ? cust.name : 'Unknown Customer',
      customer_email: cust?.email,
      license_key: key,
      plan: data.plan,
      status: 'active',
      max_activations: data.maxActivations || 1,
      issued_at: now.toISOString().replace('T', ' ').substring(0, 19),
      expires_at: expiresAt,
      last_verified_at: null,
      notes: data.notes || '',
      created_at: now.toISOString().replace('T', ' ').substring(0, 19),
      updated_at: now.toISOString().replace('T', ' ').substring(0, 19),
      activations: []
    };

    setLicenses(prev => [newLicense, ...prev]);

    // Add subscription event
    const event: SubscriptionEvent = {
      id: Date.now(),
      license_id: newId,
      license_key: key,
      event_type: 'issued',
      previous_expires_at: null,
      new_expires_at: expiresAt,
      note: `Issued ${data.plan} license for ${newLicense.customer_name}`,
      created_by: currentUser.username,
      created_at: now.toISOString().replace('T', ' ').substring(0, 19),
    };
    setSubscriptionEvents(prev => [event, ...prev]);

    addAuditLog('LICENSE_ISSUED', `Issued license ${key} to customer #${data.customerId}`, newId);
    return newLicense;
  };

  const renewLicense = (licenseId: number, additionalDays: number, note?: string) => {
    const lic = licenses.find(l => l.id === licenseId);
    if (!lic) return;

    const baseDate = lic.expires_at ? new Date(lic.expires_at) : new Date();
    // If already expired in the past, renew from today
    const startFrom = baseDate.getTime() < Date.now() ? new Date() : baseDate;
    startFrom.setDate(startFrom.getDate() + additionalDays);
    const newExpiry = startFrom.toISOString().replace('T', ' ').substring(0, 19);

    setLicenses(prev => prev.map(l => {
      if (l.id === licenseId) {
        return {
          ...l,
          status: 'active',
          expires_at: newExpiry,
          updated_at: new Date().toISOString().replace('T', ' ').substring(0, 19)
        };
      }
      return l;
    }));

    const event: SubscriptionEvent = {
      id: Date.now(),
      license_id: licenseId,
      license_key: lic.license_key,
      event_type: 'renewed',
      previous_expires_at: lic.expires_at,
      new_expires_at: newExpiry,
      note: note || `Renewed for ${additionalDays} days by ${currentUser.username}`,
      created_by: currentUser.username,
      created_at: new Date().toISOString().replace('T', ' ').substring(0, 19),
    };
    setSubscriptionEvents(prev => [event, ...prev]);

    addAuditLog('LICENSE_RENEWED', `Extended license ${lic.license_key} by ${additionalDays} days`, licenseId);
  };

  const suspendLicense = (licenseId: number, reason: string) => {
    const lic = licenses.find(l => l.id === licenseId);
    if (!lic) return;

    setLicenses(prev => prev.map(l => l.id === licenseId ? { ...l, status: 'suspended' } : l));

    const event: SubscriptionEvent = {
      id: Date.now(),
      license_id: licenseId,
      license_key: lic.license_key,
      event_type: 'suspended',
      previous_expires_at: lic.expires_at,
      new_expires_at: lic.expires_at,
      note: `Suspended: ${reason}`,
      created_by: currentUser.username,
      created_at: new Date().toISOString().replace('T', ' ').substring(0, 19),
    };
    setSubscriptionEvents(prev => [event, ...prev]);
    addAuditLog('LICENSE_SUSPENDED', `Suspended license ${lic.license_key}: ${reason}`, licenseId);
  };

  const reactivateLicense = (licenseId: number, note?: string) => {
    const lic = licenses.find(l => l.id === licenseId);
    if (!lic) return;

    setLicenses(prev => prev.map(l => l.id === licenseId ? { ...l, status: 'active' } : l));

    const event: SubscriptionEvent = {
      id: Date.now(),
      license_id: licenseId,
      license_key: lic.license_key,
      event_type: 'reactivated',
      previous_expires_at: lic.expires_at,
      new_expires_at: lic.expires_at,
      note: note || 'Reactivated by admin',
      created_by: currentUser.username,
      created_at: new Date().toISOString().replace('T', ' ').substring(0, 19),
    };
    setSubscriptionEvents(prev => [event, ...prev]);
    addAuditLog('LICENSE_REACTIVATED', `Reactivated license ${lic.license_key}`, licenseId);
  };

  const revokeLicense = (licenseId: number, reason: string) => {
    const lic = licenses.find(l => l.id === licenseId);
    if (!lic) return;

    setLicenses(prev => prev.map(l => l.id === licenseId ? { ...l, status: 'revoked' } : l));

    const event: SubscriptionEvent = {
      id: Date.now(),
      license_id: licenseId,
      license_key: lic.license_key,
      event_type: 'revoked',
      previous_expires_at: lic.expires_at,
      new_expires_at: lic.expires_at,
      note: `Revoked permanently: ${reason}`,
      created_by: currentUser.username,
      created_at: new Date().toISOString().replace('T', ' ').substring(0, 19),
    };
    setSubscriptionEvents(prev => [event, ...prev]);
    addAuditLog('LICENSE_REVOKED', `Revoked license ${lic.license_key}: ${reason}`, licenseId);
  };

  const deleteLicense = (licenseId: number) => {
    const lic = licenses.find(l => l.id === licenseId);
    if (!lic) return;

    setLicenses(prev => prev.filter(l => l.id !== licenseId));
    addAuditLog('LICENSE_DELETED', `Deleted license ${lic.license_key} permanently`, licenseId);
  };

  const deactivateDevice = (licenseId: number, hwid: string) => {
    setLicenses(prev => prev.map(l => {
      if (l.id === licenseId) {
        return {
          ...l,
          activations: l.activations.filter(a => a.hwid !== hwid)
        };
      }
      return l;
    }));
    addAuditLog('DEVICE_DEACTIVATED', `Deactivated HWID ${hwid} from license #${licenseId}`, licenseId);
  };

  // Customer Management
  const addCustomer = (data: { name: string; email?: string; phone?: string; notes?: string }) => {
    const newCust: Customer = {
      id: Date.now(),
      name: data.name,
      email: data.email || '',
      phone: data.phone || '',
      notes: data.notes || '',
      created_at: new Date().toISOString().replace('T', ' ').substring(0, 19),
      active_licenses_count: 0
    };
    setCustomers(prev => [newCust, ...prev]);
    addAuditLog('CUSTOMER_CREATED', `Created customer "${data.name}"`, newCust.id);
    return newCust;
  };

  const editCustomer = (id: number, data: { name: string; email?: string; phone?: string; notes?: string }) => {
    setCustomers(prev => prev.map(c => c.id === id ? { ...c, ...data } : c));
    setLicenses(prev => prev.map(l => l.customer_id === id ? { ...l, customer_name: data.name, customer_email: data.email } : l));
    addAuditLog('CUSTOMER_UPDATED', `Updated customer #${id}`, id);
  };

  const deleteCustomer = (id: number) => {
    setCustomers(prev => prev.filter(c => c.id !== id));
    setLicenses(prev => prev.filter(l => l.customer_id !== id));
    addAuditLog('CUSTOMER_DELETED', `Deleted customer #${id} and associated licenses`, id);
  };

  // Password Recovery Workflow
  const approveRecoveryRequest = (id: number, adminNote: string, validityHours = 4) => {
    const token = `REC-${Math.random().toString(36).substring(2, 6).toUpperCase()}-${Math.random().toString(36).substring(2, 6).toUpperCase()}-AUTH`;
    const exp = new Date(Date.now() + validityHours * 3600 * 1000).toISOString().replace('T', ' ').substring(0, 19);

    setRecoveryRequests(prev => prev.map(r => {
      if (r.id === id) {
        return {
          ...r,
          status: 'approved',
          admin_note: adminNote,
          token_raw: token,
          token_hash: 'sha256_mock_' + Math.random().toString(36).substring(2, 12),
          token_expires_at: exp,
          reviewed_by: currentUser.username,
          reviewed_at: new Date().toISOString().replace('T', ' ').substring(0, 19),
          updated_at: new Date().toISOString().replace('T', ' ').substring(0, 19)
        };
      }
      return r;
    }));

    addAuditLog('RECOVERY_APPROVED', `Approved recovery request #${id} with authorization token`, id);
    return { token, expiresAt: exp };
  };

  const rejectRecoveryRequest = (id: number, adminNote: string) => {
    setRecoveryRequests(prev => prev.map(r => {
      if (r.id === id) {
        return {
          ...r,
          status: 'rejected',
          admin_note: adminNote,
          reviewed_by: currentUser.username,
          reviewed_at: new Date().toISOString().replace('T', ' ').substring(0, 19),
          updated_at: new Date().toISOString().replace('T', ' ').substring(0, 19)
        };
      }
      return r;
    }));
    addAuditLog('RECOVERY_REJECTED', `Rejected recovery request #${id}: ${adminNote}`, id);
  };

  // Admin User Management
  const addAdminUser = (data: { username: string; email?: string; role: Role } | string, roleParam?: Role) => {
    const colors = ['#3fa9f5', '#3fbb6d', '#e0a83f', '#a855f7', '#ec4899', '#06b6d4'];
    const color = colors[Math.floor(Math.random() * colors.length)];
    const username = typeof data === 'string' ? data : data.username;
    const role = typeof data === 'string' ? (roleParam || 'admin') : data.role;
    const email = typeof data === 'string' ? `${username.toLowerCase()}@herculepos.iq` : data.email;

    const newUser: AdminUser = {
      id: Date.now(),
      username,
      email,
      role,
      is_active: true,
      must_change_password: true,
      totp_enabled: false,
      mfa_enabled: false,
      created_at: new Date().toISOString().replace('T', ' ').substring(0, 19),
      avatar_color: color,
    };
    setUsers(prev => [...prev, newUser]);
    addAuditLog('ADMIN_CREATED', `Created administrator account "${username}" with role [${role}]`, newUser.id);
    return newUser;
  };

  const updateAdminRole = (id: number, role: Role) => {
    setUsers(prev => prev.map(u => u.id === id ? { ...u, role } : u));
    addAuditLog('ADMIN_ROLE_CHANGED', `Changed role of user #${id} to ${role}`, id);
  };

  const toggleAdminActive = (id: number) => {
    setUsers(prev => prev.map(u => u.id === id ? { ...u, is_active: !u.is_active } : u));
    addAuditLog('ADMIN_STATUS_TOGGLED', `Toggled active state for admin #${id}`, id);
  };

  const toggleMfa = (id: number, enabled: boolean) => {
    setUsers(prev => prev.map(u => u.id === id ? { ...u, totp_enabled: enabled, mfa_enabled: enabled } : u));
    if (currentUser.id === id) {
      setCurrentUser(prev => ({ ...prev, totp_enabled: enabled, mfa_enabled: enabled }));
    }
    addAuditLog('ADMIN_MFA_TOGGLED', `Updated MFA status to ${enabled ? 'enabled' : 'disabled'} for user #${id}`, id);
  };

  const resetAdminMfa = (id: number) => {
    setUsers(prev => prev.map(u => u.id === id ? { ...u, totp_enabled: false, totp_secret: undefined, recovery_codes: [] } : u));
    addAuditLog('ADMIN_MFA_RESET', `Reset 2FA credentials for user #${id}`, id);
  };

  const deleteAdminUser = (id: number) => {
    setUsers(prev => prev.filter(u => u.id !== id));
    addAuditLog('ADMIN_DELETED', `Deleted admin user #${id}`, id);
  };

  // Verification Simulator
  const simulateVerify = (licenseKey: string, hwid: string, ip = '127.0.0.1') => {
    const lic = licenses.find(l => l.license_key.trim().toUpperCase() === licenseKey.trim().toUpperCase());
    const nowStr = new Date().toISOString().replace('T', ' ').substring(0, 19);

    if (!lic) {
      const log: VerificationLog = {
        id: Date.now(),
        license_key: licenseKey,
        hwid,
        result: 'invalid_key',
        ip_address: ip,
        created_at: nowStr,
      };
      setVerifications(prev => [log, ...prev]);
      return { success: false, result: 'invalid_key' as VerificationResult, message: 'License key does not exist in registry.' };
    }

    if (lic.status === 'suspended') {
      const log: VerificationLog = {
        id: Date.now(),
        license_id: lic.id,
        license_key: lic.license_key,
        hwid,
        result: 'suspended',
        ip_address: ip,
        created_at: nowStr,
      };
      setVerifications(prev => [log, ...prev]);
      return { success: false, result: 'suspended' as VerificationResult, message: 'License is currently suspended by administration.' };
    }

    if (lic.status === 'revoked') {
      const log: VerificationLog = {
        id: Date.now(),
        license_id: lic.id,
        license_key: lic.license_key,
        hwid,
        result: 'revoked',
        ip_address: ip,
        created_at: nowStr,
      };
      setVerifications(prev => [log, ...prev]);
      return { success: false, result: 'revoked' as VerificationResult, message: 'License has been permanently revoked.' };
    }

    if (lic.expires_at && new Date(lic.expires_at).getTime() < Date.now()) {
      const log: VerificationLog = {
        id: Date.now(),
        license_id: lic.id,
        license_key: lic.license_key,
        hwid,
        result: 'expired',
        ip_address: ip,
        created_at: nowStr,
      };
      setVerifications(prev => [log, ...prev]);
      return { success: false, result: 'expired' as VerificationResult, message: 'License validity period has expired.' };
    }

    // Check device match
    const existingActivation = lic.activations.find(a => a.hwid === hwid);
    if (!existingActivation && lic.activations.length >= lic.max_activations) {
      const log: VerificationLog = {
        id: Date.now(),
        license_id: lic.id,
        license_key: lic.license_key,
        hwid,
        result: 'activation_limit',
        ip_address: ip,
        created_at: nowStr,
      };
      setVerifications(prev => [log, ...prev]);
      return { success: false, result: 'activation_limit' as VerificationResult, message: `Activation limit reached (${lic.activations.length}/${lic.max_activations}).` };
    }

    // If new HWID and under limit, automatically register device activation
    if (!existingActivation) {
      const newActivation: LicenseActivation = {
        id: Date.now(),
        license_id: lic.id,
        hwid,
        device_name: `POS Device (${hwid.substring(0, 8)})`,
        is_active: true,
        activated_at: nowStr,
        last_seen_at: nowStr,
        ip_address: ip,
      };
      setLicenses(prev => prev.map(l => l.id === lic.id ? { ...l, activations: [...l.activations, newActivation], last_verified_at: nowStr } : l));
    } else {
      setLicenses(prev => prev.map(l => l.id === lic.id ? {
        ...l,
        last_verified_at: nowStr,
        activations: l.activations.map(a => a.hwid === hwid ? { ...a, last_seen_at: nowStr, ip_address: ip } : a)
      } : l));
    }

    const log: VerificationLog = {
      id: Date.now(),
      license_id: lic.id,
      license_key: lic.license_key,
      hwid,
      device_name: existingActivation ? existingActivation.device_name : `POS Device (${hwid.substring(0, 8)})`,
      result: 'ok',
      ip_address: ip,
      created_at: nowStr,
    };
    setVerifications(prev => [log, ...prev]);

    // Simulated RSA-2048 SHA-256 signature payload
    const mockSig = btoa(`SIGNED_${lic.license_key}_${hwid}_${Date.now()}`).substring(0, 64);

    return {
      success: true,
      result: 'ok' as VerificationResult,
      message: 'License and device hardware verification succeeded.',
      signature: mockSig,
      expiresAt: lic.expires_at,
      plan: lic.plan,
    };
  };

  // Notifications
  const markNotificationAsRead = (id: string) => {
    setNotifications(prev => prev.map(n => n.id === id ? { ...n, read: true } : n));
  };
  const markAllNotificationsAsRead = () => {
    setNotifications(prev => prev.map(n => ({ ...n, read: true })));
  };
  const clearNotifications = () => {
    setNotifications([]);
  };

  // CSV Exporters with formula injection protection
  const sanitizeCsvCell = (val: string | number | null | undefined): string => {
    if (val === null || val === undefined) return '""';
    let s = String(val);
    // Formula injection mitigation: if string starts with =, +, -, @, \t, \r, prepend apostrophe
    if (/^[=+\-@\t\r]/.test(s)) {
      s = "'" + s;
    }
    // Escape internal quotes
    return `"${s.replace(/"/g, '""')}"`;
  };

  const exportLicensesCsv = () => {
    const headers = ['ID', 'Customer', 'License Key', 'Plan', 'Status', 'Active Devices', 'Max Activations', 'Issued At', 'Expires At', 'Last Verified At', 'Notes'];
    const rows = licenses.map(l => [
      l.id,
      l.customer_name,
      l.license_key,
      l.plan,
      l.status,
      l.activations.length,
      l.max_activations,
      l.issued_at,
      l.expires_at || 'Lifetime',
      l.last_verified_at || 'Never',
      l.notes || ''
    ]);

    const csvContent = [headers.map(sanitizeCsvCell).join(','), ...rows.map(r => r.map(sanitizeCsvCell).join(','))].join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `hercule_licenses_export_${new Date().toISOString().substring(0, 10)}.csv`;
    link.click();
    URL.revokeObjectURL(url);
    addAuditLog('CSV_EXPORTED', 'Exported all licenses to CSV');
  };

  const exportAuditLogCsv = () => {
    const headers = ['ID', 'Actor', 'Action', 'Target ID', 'Details', 'IP Address', 'Timestamp'];
    const rows = auditLogs.map(a => [
      a.id,
      a.actor_username,
      a.action,
      a.target_id || '',
      a.details || '',
      a.ip_address,
      a.created_at
    ]);

    const csvContent = [headers.map(sanitizeCsvCell).join(','), ...rows.map(r => r.map(sanitizeCsvCell).join(','))].join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `hercule_audit_log_${new Date().toISOString().substring(0, 10)}.csv`;
    link.click();
    URL.revokeObjectURL(url);
  };

  return (
    <AppContext.Provider
      value={{
        currentUser,
        setCurrentUser,
        users,
        customers,
        licenses,
        verifications,
        recoveryRequests,
        subscriptionEvents,
        auditLogs,
        notifications,
        soundEnabled,
        setSoundEnabled,
        selectedLicenseId,
        setSelectedLicenseId,
        issueLicense,
        renewLicense,
        suspendLicense,
        reactivateLicense,
        revokeLicense,
        deleteLicense,
        deactivateDevice,
        addCustomer,
        editCustomer,
        deleteCustomer,
        approveRecoveryRequest,
        rejectRecoveryRequest,
        addAdminUser,
        updateAdminRole,
        toggleAdminActive,
        toggleMfa,
        resetAdminMfa,
        deleteAdminUser,
        adminUsers: users,
        simulateVerify,
        markNotificationAsRead,
        markAllNotificationsAsRead,
        clearNotifications,
        exportLicensesCsv,
        exportAuditLogCsv,
        pushPermission,
        requestPushPermission,
        triggerSimulatedAlert,
        fastNotifyMode,
        setFastNotifyMode,
        toasts,
        dismissToast,
        testInstantNotification,
        addNotification,
      }}
    >
      {children}
    </AppContext.Provider>
  );
};

export const useApp = () => {
  const context = useContext(AppContext);
  if (!context) {
    throw new Error('useApp must be used within an AppProvider');
  }
  return context;
};
