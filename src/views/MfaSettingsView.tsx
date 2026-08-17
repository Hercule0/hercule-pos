import React, { useState } from 'react';
import { useApp } from '../context/AppContext';
import {
  ShieldCheck,
  KeyRound,
  QrCode,
  Check,
  Copy,
  AlertTriangle,
  Lock,
  Smartphone,
  Download,
  CheckCircle2,
  RefreshCw
} from 'lucide-react';

export const MfaSettingsView: React.FC = () => {
  const { currentUser, toggleMfa } = useApp();

  const [totpCode, setTotpCode] = useState('');
  const [copiedSecret, setCopiedSecret] = useState(false);
  const [copiedCodes, setCopiedCodes] = useState(false);
  const [setupStep, setSetupStep] = useState<number>(1);
  const [testSuccess, setTestSuccess] = useState(false);

  const sampleSecret = 'JBSWY3DPEHPK3PXP';
  const backupCodes = [
    '8832-1920', '4410-9281', '7731-0029', '1109-4482',
    '3392-8172', '6620-1928', '9912-3841', '5519-7281'
  ];

  const handleCopySecret = () => {
    navigator.clipboard.writeText(sampleSecret);
    setCopiedSecret(true);
    setTimeout(() => setCopiedSecret(false), 2000);
  };

  const handleCopyCodes = () => {
    navigator.clipboard.writeText(backupCodes.join('\n'));
    setCopiedCodes(true);
    setTimeout(() => setCopiedCodes(false), 2000);
  };

  const handleVerifyTotp = (e: React.FormEvent) => {
    e.preventDefault();
    if (totpCode.length >= 6) {
      setTestSuccess(true);
      if (!currentUser.mfa_enabled) {
        toggleMfa(currentUser.id, true);
      }
      setTimeout(() => setTestSuccess(false), 3000);
    }
  };

  return (
    <div className="space-y-5 animate-in fade-in duration-150 max-w-4xl mx-auto">
      
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-xl sm:text-2xl font-extrabold text-white tracking-tight">
            MFA & Multi-Factor Security
          </h1>
          <p className="text-xs sm:text-sm text-slate-400 mt-0.5">
            Hardware or TOTP authenticator app enforcement for administrative operations.
          </p>
        </div>

        <div className="flex items-center gap-2">
          <span className={`px-3 py-1.5 rounded-xl text-xs font-bold flex items-center gap-1.5 ${
            currentUser.mfa_enabled
              ? 'bg-emerald-500/15 text-emerald-400 border border-emerald-500/30'
              : 'bg-amber-500/15 text-amber-300 border border-amber-500/30'
          }`}>
            <ShieldCheck className="w-4 h-4" />
            <span>{currentUser.mfa_enabled ? 'MFA Enabled' : 'MFA Disabled'}</span>
          </span>
        </div>
      </div>

      {/* Main Container */}
      <div className="p-6 rounded-2xl bg-[#131a24] border border-[#243042] space-y-6">
        
        {/* Toggle switch */}
        <div className="flex items-center justify-between p-4 rounded-xl bg-[#182230] border border-[#243042]">
          <div className="flex items-center gap-3">
            <div className="p-2.5 rounded-xl bg-sky-500/10 text-sky-400">
              <Smartphone className="w-5 h-5" />
            </div>
            <div>
              <h3 className="text-sm font-bold text-white">Authenticator App (TOTP)</h3>
              <p className="text-xs text-slate-400">
                Use Google Authenticator, Authy, or 1Password to generate one-time 6-digit codes.
              </p>
            </div>
          </div>

          <button
            onClick={() => toggleMfa(currentUser.id, !currentUser.mfa_enabled)}
            className={`px-4 py-2 rounded-xl text-xs font-bold transition-all ${
              currentUser.mfa_enabled
                ? 'bg-rose-500/20 text-rose-300 border border-rose-500/30 hover:bg-rose-500/30'
                : 'bg-emerald-500 text-slate-950 font-bold hover:bg-emerald-400 shadow-md'
            }`}
          >
            {currentUser.mfa_enabled ? 'Disable MFA' : 'Enable MFA'}
          </button>
        </div>

        {/* Step-by-step TOTP setup */}
        <div className="grid grid-cols-1 md:grid-cols-2 gap-6 pt-2">
          
          {/* QR Code Column */}
          <div className="space-y-4">
            <h4 className="text-xs font-bold uppercase tracking-wider text-slate-300">
              1. Scan QR Code in Authenticator
            </h4>
            
            <div className="p-6 rounded-xl bg-white flex flex-col items-center justify-center text-center text-slate-950 w-52 h-52 mx-auto shadow-lg">
              {/* Stylized QR placeholder vector */}
              <div className="w-40 h-40 border-4 border-slate-900 p-2 flex flex-col justify-between">
                <div className="flex justify-between">
                  <div className="w-8 h-8 bg-slate-900" />
                  <div className="w-8 h-8 bg-slate-900" />
                </div>
                <div className="text-center font-mono text-[10px] font-bold">
                  HERCULE AUTH
                </div>
                <div className="flex justify-between">
                  <div className="w-8 h-8 bg-slate-900" />
                  <div className="w-3 h-3 bg-slate-900 self-center" />
                </div>
              </div>
            </div>

            <div className="text-center space-y-1">
              <span className="text-xs text-slate-400">Manual Entry Secret Key:</span>
              <div className="flex items-center justify-center gap-2">
                <span className="font-mono text-xs font-bold text-sky-400 bg-[#182230] px-2.5 py-1 rounded-lg border border-[#243042]">
                  {sampleSecret}
                </span>
                <button
                  onClick={handleCopySecret}
                  className="p-1 rounded text-slate-400 hover:text-white"
                  title="Copy secret"
                >
                  {copiedSecret ? <Check className="w-3.5 h-3.5 text-emerald-400" /> : <Copy className="w-3.5 h-3.5" />}
                </button>
              </div>
            </div>
          </div>

          {/* Verification Code Column */}
          <div className="space-y-4 flex flex-col justify-between">
            <div className="space-y-4">
              <h4 className="text-xs font-bold uppercase tracking-wider text-slate-300">
                2. Test Verification Code
              </h4>
              <p className="text-xs text-slate-400 leading-relaxed">
                Enter the 6-digit verification code shown on your authenticator device to verify proper synchronization.
              </p>

              <form onSubmit={handleVerifyTotp} className="space-y-3">
                <div>
                  <label className="block text-xs font-semibold text-slate-300 mb-1">
                    6-Digit One-Time Password (OTP)
                  </label>
                  <input
                    type="text"
                    maxLength={6}
                    placeholder="123456"
                    value={totpCode}
                    onChange={(e) => setTotpCode(e.target.value.replace(/\D/g, ''))}
                    className="w-full px-4 py-2.5 rounded-xl bg-[#182230] border border-[#2d3c52] font-mono text-lg tracking-widest text-center text-emerald-400 font-bold focus:outline-none focus:border-sky-500"
                  />
                </div>

                <button
                  type="submit"
                  disabled={totpCode.length < 6}
                  className="w-full py-2.5 rounded-xl bg-sky-500 hover:bg-sky-400 disabled:opacity-50 text-slate-950 font-bold text-xs sm:text-sm shadow-md transition-all flex items-center justify-center gap-2"
                >
                  <CheckCircle2 className="w-4 h-4" />
                  <span>Verify Code & Synchronize</span>
                </button>

                {testSuccess && (
                  <div className="p-3 rounded-xl bg-emerald-500/15 border border-emerald-500/30 text-emerald-300 text-xs text-center font-bold flex items-center justify-center gap-2 animate-in fade-in">
                    <Check className="w-4 h-4" />
                    <span>TOTP Verification Successful!</span>
                  </div>
                )}
              </form>
            </div>

            {/* Emergency Recovery Codes */}
            <div className="p-4 rounded-xl bg-[#182230] border border-[#243042] space-y-2">
              <div className="flex items-center justify-between">
                <span className="text-xs font-bold text-white">Emergency Backup Codes</span>
                <button
                  onClick={handleCopyCodes}
                  className="text-xs text-sky-400 hover:text-sky-300 flex items-center gap-1 font-semibold"
                >
                  {copiedCodes ? <Check className="w-3.5 h-3.5 text-emerald-400" /> : <Copy className="w-3.5 h-3.5" />}
                  <span>{copiedCodes ? 'Copied' : 'Copy All'}</span>
                </button>
              </div>
              <div className="grid grid-cols-2 gap-1.5 font-mono text-[11px] text-slate-400">
                {backupCodes.map((code, idx) => (
                  <span key={idx} className="bg-[#111722] px-2 py-0.5 rounded text-center border border-[#243042]/50">
                    {code}
                  </span>
                ))}
              </div>
            </div>

          </div>

        </div>

      </div>

    </div>
  );
};
