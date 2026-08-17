import React, { useState } from 'react';
import { useApp } from '../context/AppContext';
import { PasswordRecoveryRequest, RecoveryStatus } from '../types';
import {
  Key,
  ShieldCheck,
  CheckCircle2,
  XCircle,
  Clock,
  AlertTriangle,
  FileText,
  Copy,
  Check,
  Laptop,
  UserCheck,
  Lock,
  ArrowRight,
  Info
} from 'lucide-react';

export const RecoveryRequestsView: React.FC = () => {
  const { recoveryRequests, approveRecoveryRequest, rejectRecoveryRequest, licenses } = useApp();

  const [statusFilter, setStatusFilter] = useState<string>('pending');
  const [copiedToken, setCopiedToken] = useState<string | null>(null);

  // Modals
  const [approveModalReq, setApproveModalReq] = useState<PasswordRecoveryRequest | null>(null);
  const [adminNote, setAdminNote] = useState('');
  const [validityHours, setValidityHours] = useState(4);
  const [generatedToken, setGeneratedToken] = useState<{ token: string; expiresAt: string } | null>(null);

  const [rejectModalReq, setRejectModalReq] = useState<PasswordRecoveryRequest | null>(null);
  const [rejectReason, setRejectReason] = useState('');

  const filteredRequests = recoveryRequests.filter(r => {
    if (statusFilter === 'all') return true;
    return r.status === statusFilter;
  });

  const handleCopy = (t: string) => {
    navigator.clipboard.writeText(t);
    setCopiedToken(t);
    setTimeout(() => setCopiedToken(null), 2000);
  };

  const handleExecuteApprove = (e: React.FormEvent) => {
    e.preventDefault();
    if (!approveModalReq) return;
    const res = approveRecoveryRequest(approveModalReq.id, adminNote, validityHours);
    setGeneratedToken(res);
  };

  const handleExecuteReject = (e: React.FormEvent) => {
    e.preventDefault();
    if (!rejectModalReq) return;
    rejectRecoveryRequest(rejectModalReq.id, rejectReason);
    setRejectModalReq(null);
    setRejectReason('');
  };

  const getStatusBadge = (status: RecoveryStatus) => {
    switch (status) {
      case 'pending':
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-bold uppercase bg-rose-500/15 text-rose-400 border border-rose-500/30 animate-pulse">
            <Clock className="w-3 h-3" /> Pending Review
          </span>
        );
      case 'approved':
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-bold uppercase bg-emerald-500/15 text-emerald-400 border border-emerald-500/30">
            <CheckCircle2 className="w-3 h-3" /> Authorized
          </span>
        );
      case 'completed':
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-bold uppercase bg-sky-500/15 text-sky-400 border border-sky-500/30">
            <ShieldCheck className="w-3 h-3" /> Token Consumed
          </span>
        );
      case 'rejected':
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-bold uppercase bg-slate-700 text-slate-400 border border-slate-600">
            <XCircle className="w-3 h-3" /> Rejected
          </span>
        );
      case 'expired':
        return (
          <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-bold uppercase bg-amber-500/15 text-amber-300 border border-amber-500/30">
            <Clock className="w-3 h-3" /> Expired
          </span>
        );
    }
  };

  return (
    <div className="space-y-5 animate-in fade-in duration-150">
      
      {/* Top Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-xl sm:text-2xl font-extrabold text-white tracking-tight">
            Terminal Password Recovery
          </h1>
          <p className="text-xs sm:text-sm text-slate-400 mt-0.5">
            Cryptographically authorize offline store terminal admin/manager password resets.
          </p>
        </div>

        <div className="flex items-center gap-2 text-xs text-slate-400 bg-[#131a24] px-3 py-2 rounded-xl border border-[#243042]">
          <Lock className="w-4 h-4 text-emerald-400" />
          <span>Device-Bound &bull; Single-Use SHA-256</span>
        </div>
      </div>

      {/* Security Architecture Note */}
      <div className="p-4 rounded-2xl bg-[#131a24] border border-[#243042] flex items-start gap-3">
        <div className="p-2 rounded-xl bg-sky-500/10 text-sky-400 shrink-0">
          <Info className="w-4 h-4" />
        </div>
        <div className="text-xs text-slate-300 leading-relaxed">
          <strong className="text-white">Offline-First Architecture Security:</strong> Store accounts live in the local POS terminal SQLite database. When terminal credentials are lost, this server validates device hardware locks (HWID) and issues a short-lived authorization token so the client can safely reinitialize local master credentials.
        </div>
      </div>

      {/* Status Filter Tabs */}
      <div className="flex items-center gap-1.5 overflow-x-auto pb-1 text-xs">
        {[
          { id: 'pending', label: 'Pending Review', count: recoveryRequests.filter(r => r.status === 'pending').length },
          { id: 'approved', label: 'Approved / Active', count: recoveryRequests.filter(r => r.status === 'approved').length },
          { id: 'completed', label: 'Completed', count: recoveryRequests.filter(r => r.status === 'completed').length },
          { id: 'rejected', label: 'Rejected', count: recoveryRequests.filter(r => r.status === 'rejected').length },
          { id: 'all', label: 'All Requests', count: recoveryRequests.length },
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

      {/* Requests List */}
      <div className="space-y-3.5">
        {filteredRequests.length === 0 ? (
          <div className="p-12 text-center rounded-2xl bg-[#131a24] border border-[#243042] text-slate-400">
            <Key className="w-12 h-12 stroke-1 mx-auto mb-3 text-slate-400" />
            <h2 className="text-base font-bold text-slate-200">No requests in this view</h2>
            <p className="text-xs text-slate-400 mt-1 max-w-sm mx-auto">
              There are no recovery tickets matching the selected status filter.
            </p>
          </div>
        ) : (
          filteredRequests.map((req) => {
            const isPending = req.status === 'pending';
            const matchedLicense = licenses.find(l => l.license_key === req.license_key);
            const isHwidKnown = matchedLicense?.activations.some(a => a.hwid === req.hwid);

            return (
              <div
                key={req.id}
                className="p-5 rounded-2xl bg-[#131a24] border border-[#243042] space-y-4 hover:border-sky-500/30 transition-all shadow-sm"
              >
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-[#243042]/70 pb-3">
                  <div className="flex items-center gap-3">
                    <div className="p-2 rounded-xl bg-purple-500/10 text-purple-400 border border-purple-500/20">
                      <Key className="w-5 h-5" />
                    </div>
                    <div>
                      <div className="flex items-center gap-2">
                        <h3 className="text-sm font-bold text-white">Ticket #{req.id}: {req.customer_name}</h3>
                        <span className="text-xs text-slate-400">for user &ldquo;<strong className="text-sky-300">{req.requested_username}</strong>&rdquo;</span>
                      </div>
                      <p className="text-xs font-mono text-sky-400 mt-0.5">License: {req.license_key}</p>
                    </div>
                  </div>

                  <div>{getStatusBadge(req.status)}</div>
                </div>

                {/* Details Grid */}
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs">
                  <div className="p-3 rounded-xl bg-[#182230] border border-[#243042]/70 space-y-1">
                    <span className="text-[10px] uppercase font-bold text-slate-400">Requesting Hardware ID</span>
                    <p className="font-mono text-slate-200 break-all">{req.hwid}</p>
                    <span className={`inline-block text-[10px] font-semibold ${isHwidKnown ? 'text-emerald-400' : 'text-rose-400'}`}>
                      {isHwidKnown ? '✓ Matches Registered Terminal' : '⚠ Unknown / Unregistered HWID'}
                    </span>
                  </div>

                  <div className="p-3 rounded-xl bg-[#182230] border border-[#243042]/70 space-y-1">
                    <span className="text-[10px] uppercase font-bold text-slate-400">Timeline & Review</span>
                    <p className="text-slate-300">Created: {req.created_at}</p>
                    {req.reviewed_by && (
                      <p className="text-slate-400 text-[11px]">
                        Reviewed by <strong className="text-white">{req.reviewed_by}</strong> at {req.reviewed_at}
                      </p>
                    )}
                  </div>

                  <div className="p-3 rounded-xl bg-[#182230] border border-[#243042]/70 space-y-1">
                    <span className="text-[10px] uppercase font-bold text-slate-400">Authorization Token</span>
                    {req.token_raw ? (
                      <div className="flex items-center justify-between gap-1">
                        <span className="font-mono text-emerald-400 font-bold select-all">{req.token_raw}</span>
                        <button
                          onClick={() => handleCopy(req.token_raw!)}
                          className="p-1 rounded text-slate-400 hover:text-white"
                        >
                          {copiedToken === req.token_raw ? <Check className="w-3.5 h-3.5 text-emerald-400" /> : <Copy className="w-3.5 h-3.5" />}
                        </button>
                      </div>
                    ) : req.token_hash ? (
                      <p className="font-mono text-[10px] text-slate-400 truncate">SHA256: {req.token_hash}</p>
                    ) : (
                      <p className="text-slate-400 italic">No token generated yet</p>
                    )}
                    {req.token_expires_at && (
                      <p className="text-[10px] text-amber-300">Valid until: {req.token_expires_at}</p>
                    )}
                  </div>
                </div>

                {req.admin_note && (
                  <div className="text-xs text-slate-300 bg-[#161f2c] p-2.5 rounded-xl border border-[#243042]">
                    <strong className="text-slate-400">Admin Note:</strong> {req.admin_note}
                  </div>
                )}

                {/* Actions for Pending Requests */}
                {isPending && (
                  <div className="flex items-center justify-end gap-2.5 pt-2 border-t border-[#243042]/70">
                    <button
                      id={`reject-req-btn-${req.id}`}
                      onClick={() => {
                        setRejectModalReq(req);
                        setRejectReason('');
                      }}
                      className="px-3.5 py-2 rounded-xl bg-rose-500/10 hover:bg-rose-500/20 text-rose-400 border border-rose-500/30 text-xs font-semibold transition-colors"
                    >
                      Reject Request
                    </button>
                    <button
                      id={`approve-req-btn-${req.id}`}
                      onClick={() => {
                        setApproveModalReq(req);
                        setAdminNote('');
                        setValidityHours(4);
                        setGeneratedToken(null);
                      }}
                      className="px-4 py-2 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 text-xs font-bold shadow-md transition-all"
                    >
                      Authorize & Issue Token
                    </button>
                  </div>
                )}

              </div>
            );
          })
        )}
      </div>

      {/* MODAL: Approve & Generate Authorization Token */}
      {approveModalReq && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
          <div className="w-full max-w-md rounded-2xl bg-[#131a24] border border-[#2a384c] shadow-2xl p-6 space-y-4 animate-in fade-in zoom-in-95 duration-150">
            <div className="flex items-center justify-between border-b border-[#243042] pb-3">
              <h3 className="text-base font-bold text-white">Authorize Password Reset</h3>
              <button
                onClick={() => setApproveModalReq(null)}
                className="p-1 rounded-lg text-slate-400 hover:text-white"
              >
                <XCircle className="w-5 h-5" />
              </button>
            </div>

            {!generatedToken ? (
              <form onSubmit={handleExecuteApprove} className="space-y-4">
                <div className="p-3 rounded-xl bg-[#182230] border border-[#243042] space-y-1 text-xs">
                  <p className="text-white font-semibold">{approveModalReq.customer_name}</p>
                  <p className="text-slate-400">Account: <strong>{approveModalReq.requested_username}</strong></p>
                  <p className="font-mono text-slate-400 truncate">HWID: {approveModalReq.hwid}</p>
                </div>

                <div>
                  <label className="block text-xs font-semibold text-slate-300 mb-1">
                    Token Validity Window
                  </label>
                  <div className="grid grid-cols-3 gap-2">
                    {[
                      { hours: 1, label: '1 Hour' },
                      { hours: 4, label: '4 Hours' },
                      { hours: 24, label: '24 Hours' },
                    ].map((h) => (
                      <button
                        key={h.hours}
                        type="button"
                        onClick={() => setValidityHours(h.hours)}
                        className={`p-2 rounded-xl border text-xs font-bold transition-colors ${
                          validityHours === h.hours
                            ? 'bg-emerald-500/20 border-emerald-500 text-emerald-300'
                            : 'bg-[#182230] border-[#243042] text-slate-400'
                        }`}
                      >
                        {h.label}
                      </button>
                    ))}
                  </div>
                </div>

                <div>
                  <label className="block text-xs font-semibold text-slate-300 mb-1">
                    Audit Confirmation Note *
                  </label>
                  <textarea
                    required
                    rows={2}
                    placeholder="e.g. Phone verification with store owner Mr. Tariq"
                    value={adminNote}
                    onChange={(e) => setAdminNote(e.target.value)}
                    className="w-full px-3.5 py-2 rounded-xl bg-[#182230] border border-[#2d3c52] text-xs text-white"
                  />
                </div>

                <div className="flex items-center justify-end gap-3 pt-2">
                  <button
                    type="button"
                    onClick={() => setApproveModalReq(null)}
                    className="px-4 py-2 rounded-xl bg-[#182230] text-slate-300 text-xs font-semibold"
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    className="px-5 py-2 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-bold text-xs shadow-md"
                  >
                    Generate Single-Use Token
                  </button>
                </div>
              </form>
            ) : (
              <div className="space-y-4 text-center">
                <div className="p-3 rounded-full bg-emerald-500/10 text-emerald-400 w-12 h-12 mx-auto flex items-center justify-center">
                  <CheckCircle2 className="w-6 h-6" />
                </div>
                <div>
                  <h4 className="text-sm font-bold text-white">Authorization Token Generated!</h4>
                  <p className="text-xs text-slate-400 mt-1">
                    Share this one-time token with the store operator or let the POS terminal claim it automatically.
                  </p>
                </div>

                <div className="p-4 rounded-xl bg-[#111722] border border-emerald-500/40 space-y-2">
                  <span className="text-[10px] uppercase font-bold text-slate-400">Single-Use Token</span>
                  <div className="flex items-center justify-center gap-2">
                    <span className="font-mono text-lg font-black text-emerald-400 tracking-wider select-all">
                      {generatedToken.token}
                    </span>
                    <button
                      onClick={() => handleCopy(generatedToken.token)}
                      className="p-1.5 rounded-lg bg-[#182230] text-slate-300 hover:text-white"
                    >
                      {copiedToken === generatedToken.token ? <Check className="w-4 h-4 text-emerald-400" /> : <Copy className="w-4 h-4" />}
                    </button>
                  </div>
                  <p className="text-[11px] text-slate-400">Expires at: {generatedToken.expiresAt}</p>
                </div>

                <button
                  onClick={() => setApproveModalReq(null)}
                  className="w-full py-2.5 rounded-xl bg-sky-500 hover:bg-sky-400 text-slate-950 font-bold text-xs"
                >
                  Done
                </button>
              </div>
            )}
          </div>
        </div>
      )}

      {/* MODAL: Reject Recovery Request */}
      {rejectModalReq && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
          <div className="w-full max-w-md rounded-2xl bg-[#131a24] border border-[#2a384c] shadow-2xl p-6 space-y-4 animate-in fade-in zoom-in-95 duration-150">
            <h3 className="text-base font-bold text-white">Reject Recovery Request</h3>
            <p className="text-xs text-slate-300">
              Provide a security justification for rejecting ticket #{rejectModalReq.id}.
            </p>

            <form onSubmit={handleExecuteReject} className="space-y-4">
              <textarea
                required
                rows={3}
                placeholder="e.g. Unrecognized hardware ID or caller could not verify identity"
                value={rejectReason}
                onChange={(e) => setRejectReason(e.target.value)}
                className="w-full px-3.5 py-2 rounded-xl bg-[#182230] border border-[#2d3c52] text-xs text-white"
              />

              <div className="flex items-center justify-end gap-3">
                <button
                  type="button"
                  onClick={() => setRejectModalReq(null)}
                  className="px-4 py-2 rounded-xl bg-[#182230] text-slate-300 text-xs font-semibold"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  className="px-4 py-2 rounded-xl bg-rose-500 hover:bg-rose-400 text-slate-950 font-bold text-xs"
                >
                  Confirm Rejection
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

    </div>
  );
};
