import React, { useState, useMemo } from 'react';
import { useApp, generateLicenseKey } from '../context/AppContext';
import { License, LicensePlan, LicenseStatus } from '../types';
import {
  Search,
  Filter,
  Plus,
  KeyRound,
  Laptop,
  Clock,
  CheckCircle2,
  AlertTriangle,
  XCircle,
  MoreVertical,
  Copy,
  Check,
  Calendar,
  FileSpreadsheet,
  RefreshCw,
  Trash2,
  PauseCircle,
  PlayCircle,
  ShieldAlert,
  ArrowUpDown,
  ExternalLink
} from 'lucide-react';

interface LicensesViewProps {
  onOpenDetailModal: (licenseId: number) => void;
  isIssueModalOpen: boolean;
  setIsIssueModalOpen: (open: boolean) => void;
  preselectedCustomerId?: number;
}

export const LicensesView: React.FC<LicensesViewProps> = ({
  onOpenDetailModal,
  isIssueModalOpen,
  setIsIssueModalOpen,
  preselectedCustomerId,
}) => {
  const {
    licenses,
    customers,
    currentUser,
    issueLicense,
    renewLicense,
    suspendLicense,
    reactivateLicense,
    revokeLicense,
    deleteLicense,
    exportLicensesCsv
  } = useApp();

  const [searchTerm, setSearchTerm] = useState('');
  const [statusFilter, setStatusFilter] = useState<string>('all');
  const [planFilter, setPlanFilter] = useState<string>('all');
  const [copiedKey, setCopiedKey] = useState<string | null>(null);

  // Renew Modal State
  const [renewModalLicense, setRenewModalLicense] = useState<License | null>(null);
  const [renewDays, setRenewDays] = useState<number>(365);
  const [renewNote, setRenewNote] = useState<string>('');

  // Suspend/Revoke Confirm State
  const [actionConfirm, setActionConfirm] = useState<{
    type: 'suspend' | 'revoke' | 'delete' | 'reactivate';
    license: License;
    reason: string;
  } | null>(null);

  // Issue License Form State
  const [issueCustomerId, setIssueCustomerId] = useState<number>(
    preselectedCustomerId || (customers[0]?.id || 1)
  );
  const [issuePlan, setIssuePlan] = useState<LicensePlan>('annual');
  const [issueMaxActivations, setIssueMaxActivations] = useState<number>(1);
  const [issueCustomKey, setIssueCustomKey] = useState<string>('');
  const [issueCustomDays, setIssueCustomDays] = useState<number>(90);
  const [issueNotes, setIssueNotes] = useState<string>('');

  // Copy helper with feedback
  const handleCopyKey = (key: string, e?: React.MouseEvent) => {
    if (e) e.stopPropagation();
    navigator.clipboard.writeText(key);
    setCopiedKey(key);
    setTimeout(() => setCopiedKey(null), 2000);
  };

  // Filtered and sorted licenses
  const filteredLicenses = useMemo(() => {
    return licenses.filter((l) => {
      // Search matches key, customer name, email, notes
      const matchesSearch =
        searchTerm === '' ||
        l.license_key.toLowerCase().includes(searchTerm.toLowerCase()) ||
        l.customer_name.toLowerCase().includes(searchTerm.toLowerCase()) ||
        (l.customer_email && l.customer_email.toLowerCase().includes(searchTerm.toLowerCase())) ||
        (l.notes && l.notes.toLowerCase().includes(searchTerm.toLowerCase()));

      // Status filter
      let matchesStatus = true;
      if (statusFilter === 'active') matchesStatus = l.status === 'active';
      else if (statusFilter === 'expiring') {
        if (!l.expires_at || l.status !== 'active') matchesStatus = false;
        else {
          const diffDays = (new Date(l.expires_at).getTime() - Date.now()) / (1000 * 3600 * 24);
          matchesStatus = diffDays > 0 && diffDays <= 30;
        }
      } else if (statusFilter === 'suspended') matchesStatus = l.status === 'suspended';
      else if (statusFilter === 'revoked') matchesStatus = l.status === 'revoked';
      else if (statusFilter === 'expired') matchesStatus = l.status === 'expired';
      else if (statusFilter === 'lifetime') matchesStatus = l.plan === 'lifetime';

      // Plan filter
      const matchesPlan = planFilter === 'all' || l.plan === planFilter;

      return matchesSearch && matchesStatus && matchesPlan;
    });
  }, [licenses, searchTerm, statusFilter, planFilter]);

  // Handle Issue License Submit
  const handleIssueSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    issueLicense({
      customerId: Number(issueCustomerId),
      plan: issuePlan,
      maxActivations: Number(issueMaxActivations),
      notes: issueNotes,
      customKey: issueCustomKey.trim() !== '' ? issueCustomKey.trim() : undefined,
      customExpiryDays: issuePlan === 'custom' ? Number(issueCustomDays) : undefined,
    });
    setIsIssueModalOpen(false);
    // Reset form
    setIssueCustomKey('');
    setIssueNotes('');
  };

  // Status Badge UI helper
  const renderStatusBadge = (status: LicenseStatus, expiresAt: string | null) => {
    let isExpiringSoon = false;
    if (expiresAt && status === 'active') {
      const diffDays = (new Date(expiresAt).getTime() - Date.now()) / (1000 * 3600 * 24);
      if (diffDays > 0 && diffDays <= 14) isExpiringSoon = true;
    }

    if (isExpiringSoon) {
      return (
        <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-bold uppercase bg-amber-500/15 text-amber-300 border border-amber-500/30">
          <Clock className="w-3 h-3" /> Expiring Soon
        </span>
      );
    }

    switch (status) {
      case 'active':
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-bold uppercase bg-emerald-500/15 text-emerald-400 border border-emerald-500/30">
            <CheckCircle2 className="w-3 h-3" /> Active
          </span>
        );
      case 'suspended':
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-bold uppercase bg-orange-500/15 text-orange-400 border border-orange-500/30">
            <PauseCircle className="w-3 h-3" /> Suspended
          </span>
        );
      case 'revoked':
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-bold uppercase bg-rose-500/15 text-rose-400 border border-rose-500/30">
            <XCircle className="w-3 h-3" /> Revoked
          </span>
        );
      case 'expired':
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-bold uppercase bg-slate-700/60 text-slate-400 border border-slate-600/40">
            <Clock className="w-3 h-3" /> Expired
          </span>
        );
    }
  };

  const getPlanPillColor = (plan: LicensePlan) => {
    switch (plan) {
      case 'lifetime':
        return 'bg-emerald-500/10 text-emerald-300 border-emerald-500/20';
      case 'annual':
        return 'bg-sky-500/10 text-sky-300 border-sky-500/20';
      case 'monthly':
        return 'bg-blue-500/10 text-blue-300 border-blue-500/20';
      case 'semi_annual':
        return 'bg-purple-500/10 text-purple-300 border-purple-500/20';
      case 'trial':
        return 'bg-amber-500/10 text-amber-300 border-amber-500/20';
      default:
        return 'bg-slate-700 text-slate-300 border-slate-600';
    }
  };

  return (
    <div className="space-y-5 animate-in fade-in duration-150">
      
      {/* Header & Main Actions */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-xl sm:text-2xl font-extrabold text-white tracking-tight">License Management</h1>
          <p className="text-xs sm:text-sm text-slate-400 mt-0.5">
            Issue, renew, and enforce device-bound cryptographic licenses for POS stores.
          </p>
        </div>

        <div className="flex items-center gap-2.5">
          <button
            id="export-licenses-btn"
            onClick={exportLicensesCsv}
            className="flex items-center gap-2 px-3 py-2 rounded-xl bg-[#161f2c] hover:bg-[#1f2b3d] border border-[#243042] text-slate-200 font-semibold text-xs transition-colors"
            title="Export CSV with formula injection protection"
          >
            <FileSpreadsheet className="w-4 h-4 text-emerald-400" />
            <span className="hidden sm:inline">Export CSV</span>
          </button>

          <button
            id="open-issue-license-modal-btn"
            onClick={() => setIsIssueModalOpen(true)}
            className="flex items-center gap-2 px-4 py-2 rounded-xl bg-sky-500 hover:bg-sky-400 text-slate-950 font-bold text-xs sm:text-sm shadow-md shadow-sky-500/20 transition-all hover:scale-[1.02] active:scale-[0.98]"
          >
            <Plus className="w-4 h-4 stroke-[2.5]" />
            <span>Issue License</span>
          </button>
        </div>
      </div>

      {/* Filter Tabs & Search Controls */}
      <div className="p-4 rounded-2xl bg-[#131a24] border border-[#243042] space-y-3.5">
        
        {/* Quick status tabs */}
        <div className="flex items-center gap-1.5 overflow-x-auto pb-1 scrollbar-none text-xs">
          {[
            { id: 'all', label: 'All Licenses', count: licenses.length },
            { id: 'active', label: 'Active', count: licenses.filter(l => l.status === 'active').length },
            { id: 'expiring', label: 'Expiring Soon', count: licenses.filter(l => {
              if (!l.expires_at || l.status !== 'active') return false;
              const d = (new Date(l.expires_at).getTime() - Date.now()) / (1000 * 3600 * 24);
              return d > 0 && d <= 30;
            }).length },
            { id: 'lifetime', label: 'Lifetime', count: licenses.filter(l => l.plan === 'lifetime').length },
            { id: 'suspended', label: 'Suspended', count: licenses.filter(l => l.status === 'suspended').length },
            { id: 'revoked', label: 'Revoked', count: licenses.filter(l => l.status === 'revoked').length },
            { id: 'expired', label: 'Expired', count: licenses.filter(l => l.status === 'expired').length },
          ].map((tab) => (
            <button
              key={tab.id}
              onClick={() => setStatusFilter(tab.id)}
              className={`px-3 py-1.5 rounded-xl font-semibold whitespace-nowrap transition-colors flex items-center gap-1.5 ${
                statusFilter === tab.id
                  ? 'bg-sky-500 text-slate-950 shadow-sm'
                  : 'bg-[#182230] text-slate-300 hover:bg-[#202d40] hover:text-white'
              }`}
            >
              <span>{tab.label}</span>
              <span className={`text-[10px] px-1.5 py-0.2 rounded-full font-bold ${
                statusFilter === tab.id ? 'bg-slate-950/20 text-slate-950' : 'bg-[#111722] text-slate-400'
              }`}>
                {tab.count}
              </span>
            </button>
          ))}
        </div>

        {/* Search & Plan Dropdown */}
        <div className="flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
          <div className="relative flex-1">
            <Search className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
            <input
              type="text"
              placeholder="Search by license key, customer name, email, or notes..."
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              className="w-full pl-10 pr-4 py-2 rounded-xl bg-[#182230] border border-[#2d3c52] text-xs sm:text-sm text-white placeholder-slate-400 focus:outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500 transition-colors"
            />
          </div>

          <div className="flex items-center gap-2">
            <select
              value={planFilter}
              onChange={(e) => setPlanFilter(e.target.value)}
              className="px-3 py-2 rounded-xl bg-[#182230] border border-[#2d3c52] text-xs sm:text-sm text-slate-200 focus:outline-none focus:border-sky-500"
            >
              <option value="all">All Plans</option>
              <option value="annual">Annual (365d)</option>
              <option value="monthly">Monthly (30d)</option>
              <option value="semi_annual">Semi-Annual (180d)</option>
              <option value="lifetime">Lifetime (Perpetual)</option>
              <option value="trial">Trial (21d)</option>
              <option value="custom">Custom Duration</option>
            </select>

            {(searchTerm || statusFilter !== 'all' || planFilter !== 'all') && (
              <button
                onClick={() => {
                  setSearchTerm('');
                  setStatusFilter('all');
                  setPlanFilter('all');
                }}
                className="px-3 py-2 text-xs font-semibold text-rose-400 hover:bg-rose-500/10 rounded-xl transition-colors"
              >
                Reset Filters
              </button>
            )}
          </div>
        </div>

      </div>

      {/* Licenses View: Desktop Table + Mobile Cards */}
      <div className="rounded-2xl bg-[#131a24] border border-[#243042] overflow-hidden shadow-sm">
        
        {filteredLicenses.length === 0 ? (
          <div className="p-12 text-center text-slate-400">
            <KeyRound className="w-12 h-12 stroke-1 mx-auto mb-3 text-slate-400" />
            <h2 className="text-base font-bold text-slate-200">No licenses found</h2>
            <p className="text-xs text-slate-400 mt-1 max-w-sm mx-auto">
              No license matches your search query or filter selection. Try adjusting filters or issue a new license.
            </p>
          </div>
        ) : (
          <>
            {/* Desktop Table (Hidden on small mobile) */}
            <div className="hidden md:block overflow-x-auto">
              <table className="w-full text-left text-xs">
                <thead>
                  <tr className="border-b border-[#243042] bg-[#0f151e]/80 text-slate-400 font-semibold uppercase tracking-wider text-[11px]">
                    <th className="py-3 px-4">License Key</th>
                    <th className="py-3 px-4">Customer</th>
                    <th className="py-3 px-4">Plan</th>
                    <th className="py-3 px-4">Status</th>
                    <th className="py-3 px-4">Devices</th>
                    <th className="py-3 px-4">Expires</th>
                    <th className="py-3 px-4 text-right">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-[#1e293b]">
                  {filteredLicenses.map((l) => (
                    <tr 
                      key={l.id} 
                      onClick={() => onOpenDetailModal(l.id)}
                      className="hover:bg-[#182230]/70 transition-colors cursor-pointer group"
                    >
                      {/* Key Column */}
                      <td className="py-3 px-4">
                        <div className="flex items-center gap-2">
                          <span className="font-mono-key text-xs font-bold text-sky-400 group-hover:text-sky-300">
                            {l.license_key}
                          </span>
                          <button
                            onClick={(e) => handleCopyKey(l.license_key, e)}
                            className="p-1 rounded text-slate-400 hover:text-white hover:bg-[#243042] transition-colors"
                            title="Copy License Key"
                          >
                            {copiedKey === l.license_key ? (
                              <Check className="w-3.5 h-3.5 text-emerald-400" />
                            ) : (
                              <Copy className="w-3.5 h-3.5" />
                            )}
                          </button>
                        </div>
                        {l.notes && (
                          <p className="text-[10px] text-slate-400 truncate max-w-xs mt-0.5">{l.notes}</p>
                        )}
                      </td>

                      {/* Customer Column */}
                      <td className="py-3 px-4">
                        <p className="font-semibold text-slate-200 truncate max-w-[180px]">{l.customer_name}</p>
                        {l.customer_email && (
                          <p className="text-[11px] text-slate-400 truncate max-w-[180px]">{l.customer_email}</p>
                        )}
                      </td>

                      {/* Plan Column */}
                      <td className="py-3 px-4">
                        <span className={`inline-block px-2.5 py-0.5 rounded-full text-[11px] font-semibold uppercase border ${getPlanPillColor(l.plan)}`}>
                          {l.plan.replace('_', ' ')}
                        </span>
                      </td>

                      {/* Status Column */}
                      <td className="py-3 px-4">
                        {renderStatusBadge(l.status, l.expires_at)}
                      </td>

                      {/* Activations Column */}
                      <td className="py-3 px-4">
                        <div className="flex items-center gap-1.5">
                          <Laptop className="w-3.5 h-3.5 text-slate-400" />
                          <span className={`font-semibold ${l.activations.length >= l.max_activations ? 'text-amber-400' : 'text-slate-300'}`}>
                            {l.activations.length} / {l.max_activations}
                          </span>
                        </div>
                      </td>

                      {/* Expiry Column */}
                      <td className="py-3 px-4 text-slate-300 whitespace-nowrap">
                        {l.expires_at ? (
                          <div>
                            <p className="font-medium text-slate-200">{l.expires_at.substring(0, 10)}</p>
                            <p className="text-[10px] text-slate-400">
                              {Math.ceil((new Date(l.expires_at).getTime() - Date.now()) / (1000 * 3600 * 24)) > 0
                                ? `${Math.ceil((new Date(l.expires_at).getTime() - Date.now()) / (1000 * 3600 * 24))} days left`
                                : 'Expired'}
                            </p>
                          </div>
                        ) : (
                          <span className="text-emerald-400 font-semibold text-[11px]">Lifetime &infin;</span>
                        )}
                      </td>

                      {/* Actions Column */}
                      <td className="py-3 px-4 text-right whitespace-nowrap" onClick={(e) => e.stopPropagation()}>
                        <div className="flex items-center justify-end gap-1.5">
                          <button
                            onClick={() => onOpenDetailModal(l.id)}
                            className="px-2.5 py-1 rounded-lg bg-[#1a2332] hover:bg-[#243042] text-sky-400 text-xs font-semibold transition-colors"
                          >
                            Details
                          </button>
                          
                          {l.status !== 'revoked' && (
                            <button
                              onClick={() => {
                                setRenewModalLicense(l);
                                setRenewDays(365);
                              }}
                              className="px-2.5 py-1 rounded-lg bg-[#1a2332] hover:bg-[#243042] text-emerald-400 text-xs font-semibold transition-colors"
                              title="Renew license"
                            >
                              Renew
                            </button>
                          )}

                          {l.status === 'active' && (
                            <button
                              onClick={() => setActionConfirm({ type: 'suspend', license: l, reason: 'Manual suspension' })}
                              className="p-1 rounded-lg text-slate-400 hover:text-amber-400 hover:bg-[#243042] transition-colors"
                              title="Suspend License"
                            >
                              <PauseCircle className="w-4 h-4" />
                            </button>
                          )}

                          {l.status === 'suspended' && (
                            <button
                              onClick={() => reactivateLicense(l.id)}
                              className="p-1 rounded-lg text-slate-400 hover:text-emerald-400 hover:bg-[#243042] transition-colors"
                              title="Reactivate License"
                            >
                              <PlayCircle className="w-4 h-4" />
                            </button>
                          )}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            {/* Mobile Card Layout (Visible on phone) */}
            <div className="md:hidden divide-y divide-[#243042]">
              {filteredLicenses.map((l) => (
                <div
                  key={l.id}
                  onClick={() => onOpenDetailModal(l.id)}
                  className="p-4 space-y-3 hover:bg-[#182230]/50 transition-colors"
                >
                  <div className="flex items-start justify-between gap-2">
                    <div>
                      <div className="flex items-center gap-2">
                        <span className="font-mono-key text-sm font-bold text-sky-400">
                          {l.license_key}
                        </span>
                        <button
                          onClick={(e) => handleCopyKey(l.license_key, e)}
                          className="p-1 rounded text-slate-400 hover:text-white"
                        >
                          {copiedKey === l.license_key ? (
                            <Check className="w-3.5 h-3.5 text-emerald-400" />
                          ) : (
                            <Copy className="w-3.5 h-3.5" />
                          )}
                        </button>
                      </div>
                      <p className="text-xs font-semibold text-white mt-1">{l.customer_name}</p>
                    </div>

                    <div>{renderStatusBadge(l.status, l.expires_at)}</div>
                  </div>

                  <div className="flex items-center justify-between text-xs text-slate-400 pt-1">
                    <span className={`px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase border ${getPlanPillColor(l.plan)}`}>
                      {l.plan}
                    </span>
                    <span className="flex items-center gap-1 font-medium">
                      <Laptop className="w-3.5 h-3.5" />
                      {l.activations.length}/{l.max_activations} Devices
                    </span>
                    <span>
                      {l.expires_at ? `Exp: ${l.expires_at.substring(0, 10)}` : 'Lifetime'}
                    </span>
                  </div>

                  <div className="flex items-center justify-between pt-2 border-t border-[#1e293b]/70" onClick={(e) => e.stopPropagation()}>
                    <button
                      onClick={() => onOpenDetailModal(l.id)}
                      className="text-xs font-semibold text-sky-400 hover:text-sky-300"
                    >
                      View Telemetry &rarr;
                    </button>

                    <div className="flex items-center gap-2">
                      <button
                        onClick={() => {
                          setRenewModalLicense(l);
                          setRenewDays(365);
                        }}
                        className="px-2.5 py-1 rounded-lg bg-[#1a2332] text-emerald-400 text-xs font-semibold"
                      >
                        Renew
                      </button>
                      {l.status === 'active' ? (
                        <button
                          onClick={() => setActionConfirm({ type: 'suspend', license: l, reason: 'Suspended by admin' })}
                          className="px-2 py-1 rounded-lg bg-[#1a2332] text-amber-400 text-xs font-semibold"
                        >
                          Suspend
                        </button>
                      ) : l.status === 'suspended' ? (
                        <button
                          onClick={() => reactivateLicense(l.id)}
                          className="px-2 py-1 rounded-lg bg-[#1a2332] text-emerald-400 text-xs font-semibold"
                        >
                          Reactivate
                        </button>
                      ) : null}
                    </div>
                  </div>
                </div>
              ))}
            </div>
          </>
        )}
      </div>

      {/* MODAL: Issue New License */}
      {isIssueModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm overflow-y-auto">
          <div className="w-full max-w-lg rounded-2xl bg-[#131a24] border border-[#2a384c] shadow-2xl p-6 space-y-5 animate-in fade-in zoom-in-95 duration-150 my-8">
            <div className="flex items-center justify-between border-b border-[#243042] pb-3.5">
              <div className="flex items-center gap-2.5">
                <div className="p-2 rounded-xl bg-sky-500/10 text-sky-400">
                  <KeyRound className="w-5 h-5" />
                </div>
                <div>
                  <h3 className="text-base font-bold text-white">Issue POS License Key</h3>
                  <p className="text-xs text-slate-400">Cryptographically verifiable offline token</p>
                </div>
              </div>
              <button
                onClick={() => setIsIssueModalOpen(false)}
                className="p-1.5 rounded-lg text-slate-400 hover:text-white hover:bg-[#1f2b3d]"
              >
                <XCircle className="w-5 h-5" />
              </button>
            </div>

            <form onSubmit={handleIssueSubmit} className="space-y-4">
              
              {/* Customer Select */}
              <div>
                <label className="block text-xs font-semibold text-slate-300 mb-1.5">
                  Select Customer / Merchant *
                </label>
                <select
                  value={issueCustomerId}
                  onChange={(e) => setIssueCustomerId(Number(e.target.value))}
                  required
                  className="w-full px-3.5 py-2.5 rounded-xl bg-[#182230] border border-[#2d3c52] text-xs sm:text-sm text-white focus:outline-none focus:border-sky-500"
                >
                  {customers.map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.name} {c.email ? `(${c.email})` : ''}
                    </option>
                  ))}
                </select>
              </div>

              {/* Plan Choice */}
              <div>
                <label className="block text-xs font-semibold text-slate-300 mb-1.5">
                  Subscription Plan Tier *
                </label>
                <div className="grid grid-cols-3 gap-2">
                  {[
                    { id: 'annual', label: 'Annual', sub: '365 Days' },
                    { id: 'monthly', label: 'Monthly', sub: '30 Days' },
                    { id: 'lifetime', label: 'Lifetime', sub: 'Perpetual' },
                    { id: 'semi_annual', label: 'Semi-Annual', sub: '180 Days' },
                    { id: 'trial', label: 'Trial', sub: '21 Days' },
                    { id: 'custom', label: 'Custom', sub: 'Manual Days' },
                  ].map((p) => (
                    <button
                      key={p.id}
                      type="button"
                      onClick={() => setIssuePlan(p.id as LicensePlan)}
                      className={`p-2.5 rounded-xl border text-left transition-all ${
                        issuePlan === p.id
                          ? 'bg-sky-500/15 border-sky-500 text-sky-300 font-bold shadow-sm'
                          : 'bg-[#182230] border-[#243042] text-slate-400 hover:bg-[#1d293b]'
                      }`}
                    >
                      <p className="text-xs font-bold text-white">{p.label}</p>
                      <p className="text-[10px] text-slate-400">{p.sub}</p>
                    </button>
                  ))}
                </div>
              </div>

              {/* Custom Days Input if Custom chosen */}
              {issuePlan === 'custom' && (
                <div>
                  <label className="block text-xs font-semibold text-slate-300 mb-1.5">
                    Validity Duration (Days) *
                  </label>
                  <input
                    type="number"
                    min="1"
                    max="3650"
                    value={issueCustomDays}
                    onChange={(e) => setIssueCustomDays(Number(e.target.value))}
                    required
                    className="w-full px-3.5 py-2 rounded-xl bg-[#182230] border border-[#2d3c52] text-xs sm:text-sm text-white"
                  />
                </div>
              )}

              {/* Max Devices */}
              <div>
                <label className="block text-xs font-semibold text-slate-300 mb-1.5">
                  Max Concurrent Device Activations (HWID slots) *
                </label>
                <div className="flex items-center gap-3">
                  {[1, 2, 3, 4, 5, 10].map((num) => (
                    <button
                      key={num}
                      type="button"
                      onClick={() => setIssueMaxActivations(num)}
                      className={`flex-1 py-2 rounded-xl border text-xs font-bold transition-colors ${
                        issueMaxActivations === num
                          ? 'bg-emerald-500/20 border-emerald-500 text-emerald-300'
                          : 'bg-[#182230] border-[#243042] text-slate-400'
                      }`}
                    >
                      {num} {num === 1 ? 'Slot' : 'Slots'}
                    </button>
                  ))}
                </div>
              </div>

              {/* License Key Generator / Custom Key */}
              <div>
                <div className="flex items-center justify-between mb-1.5">
                  <label className="text-xs font-semibold text-slate-300">
                    License Key Format (XXXX-XXXX-XXXX-XXXX-XXXX)
                  </label>
                  <button
                    type="button"
                    onClick={() => {
                      const cust = customers.find(c => c.id === issueCustomerId);
                      setIssueCustomKey(generateLicenseKey(cust ? cust.name.substring(0, 4) : 'HERC'));
                    }}
                    className="text-[11px] text-sky-400 hover:text-sky-300 flex items-center gap-1 font-semibold"
                  >
                    <RefreshCw className="w-3 h-3" /> Auto-Generate
                  </button>
                </div>
                <input
                  type="text"
                  placeholder="Leave empty for auto-generation (e.g. HERC-892A-44B1-992F-001A)"
                  value={issueCustomKey}
                  onChange={(e) => setIssueCustomKey(e.target.value)}
                  className="w-full px-3.5 py-2.5 rounded-xl bg-[#182230] border border-[#2d3c52] font-mono text-xs sm:text-sm text-sky-300 placeholder-slate-400"
                />
              </div>

              {/* Notes */}
              <div>
                <label className="block text-xs font-semibold text-slate-300 mb-1.5">
                  Admin Internal Notes (Optional)
                </label>
                <textarea
                  rows={2}
                  placeholder="e.g. Contract ref #2026-088, paid via bank transfer"
                  value={issueNotes}
                  onChange={(e) => setIssueNotes(e.target.value)}
                  className="w-full px-3.5 py-2 rounded-xl bg-[#182230] border border-[#2d3c52] text-xs text-white placeholder-slate-400 focus:outline-none"
                />
              </div>

              {/* Submit Buttons */}
              <div className="flex items-center justify-end gap-3 pt-3 border-t border-[#243042]">
                <button
                  type="button"
                  onClick={() => setIsIssueModalOpen(false)}
                  className="px-4 py-2.5 rounded-xl bg-[#182230] hover:bg-[#202d40] text-slate-300 font-semibold text-xs transition-colors"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  className="px-5 py-2.5 rounded-xl bg-sky-500 hover:bg-sky-400 text-slate-950 font-bold text-xs sm:text-sm shadow-md shadow-sky-500/20 transition-all"
                >
                  Issue & Sign License
                </button>
              </div>

            </form>
          </div>
        </div>
      )}

      {/* MODAL: Renew License */}
      {renewModalLicense && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
          <div className="w-full max-w-md rounded-2xl bg-[#131a24] border border-[#2a384c] shadow-2xl p-6 space-y-4 animate-in fade-in zoom-in-95 duration-150">
            <div className="flex items-center justify-between border-b border-[#243042] pb-3">
              <h3 className="text-base font-bold text-white">Renew License Subscription</h3>
              <button
                onClick={() => setRenewModalLicense(null)}
                className="p-1 rounded-lg text-slate-400 hover:text-white"
              >
                <XCircle className="w-5 h-5" />
              </button>
            </div>

            <div className="p-3 rounded-xl bg-[#182230] border border-[#243042] space-y-1">
              <p className="text-xs text-slate-400 font-mono-key text-sky-400 font-bold">
                {renewModalLicense.license_key}
              </p>
              <p className="text-xs text-white font-semibold">{renewModalLicense.customer_name}</p>
              <p className="text-[11px] text-slate-400">
                Current Expiry: {renewModalLicense.expires_at ? renewModalLicense.expires_at.substring(0, 10) : 'Lifetime'}
              </p>
            </div>

            <div>
              <label className="block text-xs font-semibold text-slate-300 mb-1.5">
                Extension Duration Preset
              </label>
              <div className="grid grid-cols-3 gap-2">
                {[
                  { days: 30, label: '+30 Days (Month)' },
                  { days: 180, label: '+180 Days (Half Year)' },
                  { days: 365, label: '+365 Days (1 Year)' },
                ].map((preset) => (
                  <button
                    key={preset.days}
                    type="button"
                    onClick={() => setRenewDays(preset.days)}
                    className={`p-2 rounded-xl border text-xs font-bold text-center transition-colors ${
                      renewDays === preset.days
                        ? 'bg-emerald-500/20 border-emerald-500 text-emerald-300'
                        : 'bg-[#182230] border-[#243042] text-slate-400'
                    }`}
                  >
                    {preset.label}
                  </button>
                ))}
              </div>
            </div>

            <div>
              <label className="block text-xs font-semibold text-slate-300 mb-1.5">
                Extension Note
              </label>
              <input
                type="text"
                placeholder="e.g. Annual renewal payment confirmed"
                value={renewNote}
                onChange={(e) => setRenewNote(e.target.value)}
                className="w-full px-3.5 py-2 rounded-xl bg-[#182230] border border-[#2d3c52] text-xs text-white"
              />
            </div>

            <div className="flex items-center justify-end gap-3 pt-3 border-t border-[#243042]">
              <button
                type="button"
                onClick={() => setRenewModalLicense(null)}
                className="px-4 py-2 rounded-xl bg-[#182230] text-slate-300 text-xs font-semibold"
              >
                Cancel
              </button>
              <button
                type="button"
                onClick={() => {
                  renewLicense(renewModalLicense.id, renewDays, renewNote);
                  setRenewModalLicense(null);
                  setRenewNote('');
                }}
                className="px-4 py-2 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-bold text-xs shadow-md transition-all"
              >
                Confirm Renewal (+{renewDays}d)
              </button>
            </div>
          </div>
        </div>
      )}

      {/* CONFIRMATION ACTION MODAL */}
      {actionConfirm && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
          <div className="w-full max-w-sm rounded-2xl bg-[#131a24] border border-[#2a384c] p-5 space-y-4 animate-in fade-in zoom-in-95 duration-150">
            <div className="flex items-center gap-3">
              <div className="p-2.5 rounded-xl bg-rose-500/10 text-rose-400 border border-rose-500/20">
                <ShieldAlert className="w-5 h-5" />
              </div>
              <div>
                <h4 className="text-sm font-bold text-white capitalize">
                  {actionConfirm.type} License
                </h4>
                <p className="text-xs text-slate-400">Are you sure you want to proceed?</p>
              </div>
            </div>

            <p className="text-xs text-slate-300 bg-[#182230] p-3 rounded-xl border border-[#243042]">
              Key: <span className="font-mono-key text-sky-400 font-semibold">{actionConfirm.license.license_key}</span>
              <br />
              Customer: <strong>{actionConfirm.license.customer_name}</strong>
            </p>

            <div className="flex items-center justify-end gap-2.5 pt-2">
              <button
                onClick={() => setActionConfirm(null)}
                className="px-3.5 py-2 rounded-xl bg-[#182230] text-slate-300 text-xs font-semibold"
              >
                Cancel
              </button>
              <button
                onClick={() => {
                  if (actionConfirm.type === 'suspend') {
                    suspendLicense(actionConfirm.license.id, actionConfirm.reason);
                  } else if (actionConfirm.type === 'revoke') {
                    revokeLicense(actionConfirm.license.id, actionConfirm.reason);
                  } else if (actionConfirm.type === 'delete') {
                    deleteLicense(actionConfirm.license.id);
                  }
                  setActionConfirm(null);
                }}
                className="px-4 py-2 rounded-xl bg-rose-500 hover:bg-rose-400 text-slate-950 font-bold text-xs shadow-md transition-all"
              >
                Confirm {actionConfirm.type}
              </button>
            </div>
          </div>
        </div>
      )}

    </div>
  );
};
