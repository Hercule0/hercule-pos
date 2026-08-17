import React, { useState } from 'react';
import { useApp } from '../context/AppContext';
import {
  X,
  KeyRound,
  Laptop,
  Clock,
  CheckCircle2,
  AlertTriangle,
  XCircle,
  Copy,
  Check,
  Calendar,
  Trash2,
  RefreshCw,
  FileCheck,
  Activity,
  History,
  HardDrive,
  ShieldCheck,
  Zap,
  Globe
} from 'lucide-react';

interface LicenseDetailModalProps {
  licenseId: number | null;
  onClose: () => void;
}

export const LicenseDetailModal: React.FC<LicenseDetailModalProps> = ({
  licenseId,
  onClose,
}) => {
  const {
    licenses,
    subscriptionEvents,
    verifications,
    deactivateDevice,
    simulateVerify,
    renewLicense,
  } = useApp();

  const [copied, setCopied] = useState(false);
  const [testHwid, setTestHwid] = useState('');
  const [testResult, setTestResult] = useState<unknown>(null);
  const [testLoading, setTestLoading] = useState(false);

  if (!licenseId) return null;

  const license = licenses.find(l => l.id === licenseId);
  if (!license) return null;

  const events = subscriptionEvents.filter(e => e.license_id === license.id);
  const logs = verifications.filter(v => v.license_id === license.id || v.license_key === license.license_key);

  const handleCopy = () => {
    navigator.clipboard.writeText(license.license_key);
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  };

  const handleRunVerificationTest = (hwidToUse?: string) => {
    const hwid = hwidToUse || testHwid || `HWID-WIN11-TEST-${Math.random().toString(36).substring(2, 6).toUpperCase()}`;
    setTestLoading(true);
    setTimeout(() => {
      const res = simulateVerify(license.license_key, hwid, '185.192.68.12');
      setTestResult({ ...res, hwidTested: hwid });
      setTestLoading(false);
    }, 300);
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-4 bg-black/75 backdrop-blur-sm overflow-y-auto">
      <div className="w-full max-w-4xl rounded-2xl bg-[#131a24] border border-[#2a384c] shadow-2xl overflow-hidden flex flex-col max-h-[92vh] animate-in fade-in zoom-in-95 duration-150 my-auto">
        
        {/* Modal Top Header */}
        <div className="p-4 sm:p-5 border-b border-[#243042] flex items-center justify-between bg-[#101620]">
          <div className="flex items-center gap-3">
            <div className="p-2.5 rounded-xl bg-sky-500/10 text-sky-400 border border-sky-500/20">
              <KeyRound className="w-5 h-5" />
            </div>
            <div>
              <div className="flex items-center gap-2">
                <span className="font-mono-key text-base sm:text-lg font-bold text-white tracking-wide">
                  {license.license_key}
                </span>
                <button
                  onClick={handleCopy}
                  className="p-1.5 rounded-lg text-slate-400 hover:text-white hover:bg-[#1f2b3d] transition-colors"
                  title="Copy Key"
                >
                  {copied ? <Check className="w-4 h-4 text-emerald-400" /> : <Copy className="w-4 h-4" />}
                </button>
              </div>
              <p className="text-xs text-slate-400">
                Customer: <strong className="text-slate-200">{license.customer_name}</strong>
              </p>
            </div>
          </div>

          <button
            onClick={onClose}
            className="p-2 rounded-xl text-slate-400 hover:text-white hover:bg-[#1f2b3d] transition-colors"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        {/* Scrollable Modal Body */}
        <div className="flex-1 overflow-y-auto p-4 sm:p-6 space-y-6">
          
          {/* Metadata Grid */}
          <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 p-4 rounded-xl bg-[#182230] border border-[#243042]">
            <div>
              <p className="text-[11px] font-semibold uppercase text-slate-400">Plan</p>
              <p className="text-sm font-bold text-sky-400 capitalize mt-0.5">{license.plan.replace('_', ' ')}</p>
            </div>
            <div>
              <p className="text-[11px] font-semibold uppercase text-slate-400">Status</p>
              <p className="text-sm font-bold text-emerald-400 capitalize mt-0.5">{license.status}</p>
            </div>
            <div>
              <p className="text-[11px] font-semibold uppercase text-slate-400">Activations</p>
              <p className="text-sm font-bold text-white mt-0.5">{license.activations.length} / {license.max_activations} Devices</p>
            </div>
            <div>
              <p className="text-[11px] font-semibold uppercase text-slate-400">Expires</p>
              <p className="text-sm font-bold text-amber-300 mt-0.5">{license.expires_at ? license.expires_at.substring(0, 10) : 'Lifetime'}</p>
            </div>
          </div>

          {/* Bound Hardware Activations Table */}
          <div className="space-y-3">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-2">
                <Laptop className="w-4 h-4 text-emerald-400" />
                <h4 className="text-sm font-bold text-white">Bound Hardware Devices (HWID Slots)</h4>
              </div>
              <span className="text-xs text-slate-400">
                {license.activations.length} active of {license.max_activations} allocated
              </span>
            </div>

            {license.activations.length === 0 ? (
              <div className="p-6 rounded-xl bg-[#161f2c] border border-[#243042] text-center text-slate-400 text-xs">
                No devices have activated this license yet. Run a validation check below or on the POS terminal.
              </div>
            ) : (
              <div className="rounded-xl bg-[#161f2c] border border-[#243042] overflow-hidden">
                <div className="divide-y divide-[#243042]">
                  {license.activations.map((a) => (
                    <div key={a.id} className="p-3.5 flex flex-col sm:flex-row sm:items-center justify-between gap-2.5">
                      <div className="space-y-0.5">
                        <div className="flex items-center gap-2">
                          <span className="w-2 h-2 rounded-full bg-emerald-400" />
                          <span className="text-xs font-bold text-white">{a.device_name}</span>
                        </div>
                        <p className="text-[11px] font-mono text-slate-400 select-all">{a.hwid}</p>
                        <div className="flex items-center gap-3 text-[10px] text-slate-400 pt-0.5">
                          <span>Activated: {a.activated_at.substring(0, 10)}</span>
                          <span>Last Seen: {a.last_seen_at}</span>
                          <span>IP: {a.ip_address}</span>
                        </div>
                      </div>

                      <div className="flex items-center gap-2 self-end sm:self-center">
                        <button
                          onClick={() => handleRunVerificationTest(a.hwid)}
                          className="px-2.5 py-1 rounded-lg bg-[#1f2b3d] hover:bg-[#283850] text-sky-400 text-[11px] font-semibold"
                        >
                          Ping Test
                        </button>
                        <button
                          onClick={() => deactivateDevice(license.id, a.hwid)}
                          className="px-2.5 py-1 rounded-lg bg-rose-500/10 hover:bg-rose-500/20 text-rose-400 border border-rose-500/30 text-[11px] font-semibold transition-colors flex items-center gap-1"
                        >
                          <Trash2 className="w-3 h-3" />
                          <span>Deactivate</span>
                        </button>
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            )}
          </div>

          {/* Quick Cryptographic Verification Tester */}
          <div className="p-4 rounded-xl bg-[#161f2c] border border-[#243042] space-y-3">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-2">
                <ShieldCheck className="w-4 h-4 text-sky-400" />
                <h4 className="text-sm font-bold text-white">Live Cryptographic Validation Simulator</h4>
              </div>
              <span className="text-[11px] text-sky-400 font-mono">POST /public/api/validate.php</span>
            </div>

            <div className="flex flex-col sm:flex-row gap-2">
              <input
                type="text"
                placeholder="Hardware ID (HWID) or leave blank for sample device"
                value={testHwid}
                onChange={(e) => setTestHwid(e.target.value)}
                className="flex-1 px-3 py-2 rounded-xl bg-[#111722] border border-[#2d3c52] text-xs text-white placeholder-slate-400 font-mono"
              />
              <button
                onClick={() => handleRunVerificationTest()}
                disabled={testLoading}
                className="px-4 py-2 rounded-xl bg-sky-500 hover:bg-sky-400 text-slate-950 font-bold text-xs shrink-0 flex items-center justify-center gap-1.5 transition-colors"
              >
                <RefreshCw className={`w-3.5 h-3.5 ${testLoading ? 'animate-spin' : ''}`} />
                <span>Validate HWID</span>
              </button>
            </div>

            {testResult !== null && (
              <div className="p-3 rounded-lg bg-[#111722] border border-[#243042] text-xs font-mono space-y-1.5 animate-in fade-in">
                <div className="flex items-center justify-between">
                  <span className="font-bold text-slate-300">Validation Response:</span>
                  {(testResult as { success: boolean }).success ? (
                    <span className="text-emerald-400 font-bold flex items-center gap-1">
                      <CheckCircle2 className="w-3.5 h-3.5" /> 200 OK (AUTHORIZED)
                    </span>
                  ) : (
                    <span className="text-rose-400 font-bold flex items-center gap-1">
                      <XCircle className="w-3.5 h-3.5" /> FAILED ({(testResult as { result: string }).result})
                    </span>
                  )}
                </div>
                <p className="text-slate-400">Message: {(testResult as { message: string }).message}</p>
                {(testResult as { signature?: string }).signature && (
                  <p className="text-sky-300 truncate text-[11px]">
                    RSA-2048 Sig: {(testResult as { signature?: string }).signature}...
                  </p>
                )}
              </div>
            )}
          </div>

          {/* Subscription Events & Audit History */}
          <div className="space-y-3">
            <div className="flex items-center gap-2">
              <History className="w-4 h-4 text-purple-400" />
              <h4 className="text-sm font-bold text-white">Subscription Lifecycle History</h4>
            </div>

            <div className="rounded-xl bg-[#161f2c] border border-[#243042] p-4 divide-y divide-[#243042] space-y-2 text-xs">
              {events.length === 0 ? (
                <p className="text-slate-400">No lifecycle events recorded for this license.</p>
              ) : (
                events.map((e) => (
                  <div key={e.id} className="pt-2 first:pt-0 flex items-start justify-between gap-2">
                    <div>
                      <span className="font-bold text-white uppercase text-[10px] px-1.5 py-0.5 rounded bg-purple-500/20 text-purple-300">
                        {e.event_type}
                      </span>
                      <p className="text-slate-300 mt-1">{e.note}</p>
                    </div>
                    <span className="text-[10px] text-slate-400 shrink-0">{e.created_at}</span>
                  </div>
                ))
              )}
            </div>
          </div>

        </div>

        {/* Modal Footer */}
        <div className="p-4 border-t border-[#243042] bg-[#101620] flex items-center justify-between">
          <span className="text-xs text-slate-400">
            Phase 4/6 Schema &bull; MySQL & SQLite Safe
          </span>
          <button
            onClick={onClose}
            className="px-4 py-2 rounded-xl bg-sky-500 hover:bg-sky-400 text-slate-950 font-bold text-xs transition-colors"
          >
            Done
          </button>
        </div>

      </div>
    </div>
  );
};
