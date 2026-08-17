import React, { useState } from 'react';
import { useApp } from '../context/AppContext';
import { AdminUser, AdminRole } from '../types';
import {
  UserCheck,
  Plus,
  ShieldCheck,
  Mail,
  Clock,
  Trash2,
  Lock,
  Key,
  CheckCircle2,
  XCircle,
  AlertTriangle
} from 'lucide-react';

export const AdminUsersView: React.FC = () => {
  const { adminUsers, currentUser, addAdminUser, deleteAdminUser } = useApp();

  const [isAddModalOpen, setIsAddModalOpen] = useState(false);
  const [username, setUsername] = useState('');
  const [email, setEmail] = useState('');
  const [role, setRole] = useState<AdminRole>('admin');
  const [password, setPassword] = useState('');

  const isOwner = currentUser.role === 'owner';

  const handleAddSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!username.trim() || !email.trim()) return;
    addAdminUser({
      username: username.trim(),
      email: email.trim(),
      role,
    });
    setIsAddModalOpen(false);
    setUsername('');
    setEmail('');
    setPassword('');
  };

  return (
    <div className="space-y-5 animate-in fade-in duration-150">
      
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-xl sm:text-2xl font-extrabold text-white tracking-tight">Admin & Operator Accounts</h1>
          <p className="text-xs sm:text-sm text-slate-400 mt-0.5">
            Manage authority personnel, roles, and administrative access privileges.
          </p>
        </div>

        {isOwner && (
          <button
            id="add-admin-btn"
            onClick={() => setIsAddModalOpen(true)}
            className="flex items-center gap-2 px-4 py-2.5 rounded-xl bg-sky-500 hover:bg-sky-400 text-slate-950 font-bold text-xs sm:text-sm shadow-md shadow-sky-500/20 transition-all self-start sm:self-auto hover:scale-[1.02] active:scale-[0.98]"
          >
            <Plus className="w-4 h-4 stroke-[2.5]" />
            <span>Add Admin Account</span>
          </button>
        )}
      </div>

      {/* Admin Grid */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        {adminUsers.map((user) => {
          const isCurrentUser = user.id === currentUser.id;
          return (
            <div
              key={user.id}
              className="p-5 rounded-2xl bg-[#131a24] border border-[#243042] flex flex-col justify-between space-y-4 hover:border-sky-500/40 transition-all shadow-sm"
            >
              <div className="space-y-3">
                <div className="flex items-start justify-between gap-2">
                  <div className="flex items-center gap-3">
                    <div className={`w-10 h-10 rounded-xl flex items-center justify-center font-bold text-sm text-white ${
                      user.role === 'owner'
                        ? 'bg-gradient-to-br from-indigo-500 to-purple-600 shadow-md shadow-purple-500/20'
                        : 'bg-gradient-to-br from-sky-500 to-blue-600'
                    }`}>
                      {user.username.charAt(0).toUpperCase()}
                    </div>
                    <div>
                      <div className="flex items-center gap-2">
                        <h3 className="text-sm font-bold text-white">{user.username}</h3>
                        {isCurrentUser && (
                          <span className="text-[10px] font-bold px-1.5 py-0.2 rounded bg-sky-500/20 text-sky-300">
                            You
                          </span>
                        )}
                      </div>
                      <span className={`inline-block text-[10px] font-bold uppercase px-2 py-0.5 rounded-full mt-1 ${
                        user.role === 'owner'
                          ? 'bg-purple-500/20 text-purple-300 border border-purple-500/30'
                          : 'bg-sky-500/10 text-sky-400 border border-sky-500/20'
                      }`}>
                        {user.role}
                      </span>
                    </div>
                  </div>

                  {isOwner && !isCurrentUser && user.role !== 'owner' && (
                    <button
                      onClick={() => deleteAdminUser(user.id)}
                      className="p-1.5 rounded-lg text-slate-400 hover:text-rose-400 hover:bg-rose-500/10 transition-colors"
                      title="Remove Admin"
                    >
                      <Trash2 className="w-4 h-4" />
                    </button>
                  )}
                </div>

                <div className="space-y-1.5 text-xs text-slate-300 pt-1">
                  <div className="flex items-center gap-2 text-slate-400">
                    <Mail className="w-3.5 h-3.5" />
                    <span className="truncate">{user.email}</span>
                  </div>
                  <div className="flex items-center gap-2 text-slate-400">
                    <Clock className="w-3.5 h-3.5" />
                    <span>Last Login: {user.last_login_at || 'Never'}</span>
                  </div>
                </div>
              </div>

              <div className="pt-3 border-t border-[#243042] flex items-center justify-between text-xs">
                <div className="flex items-center gap-1.5">
                  <ShieldCheck className={`w-4 h-4 ${user.mfa_enabled ? 'text-emerald-400' : 'text-slate-400'}`} />
                  <span className={user.mfa_enabled ? 'text-emerald-400 font-semibold' : 'text-slate-400'}>
                    {user.mfa_enabled ? 'MFA Protected' : 'MFA Inactive'}
                  </span>
                </div>
                <span className="text-[10px] text-slate-400">ID #{user.id}</span>
              </div>
            </div>
          );
        })}
      </div>

      {/* MODAL: Add Admin */}
      {isAddModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
          <div className="w-full max-w-md rounded-2xl bg-[#131a24] border border-[#2a384c] shadow-2xl p-6 space-y-4 animate-in fade-in zoom-in-95 duration-150">
            <div className="flex items-center justify-between border-b border-[#243042] pb-3">
              <h3 className="text-base font-bold text-white">Create Admin Account</h3>
              <button
                onClick={() => setIsAddModalOpen(false)}
                className="p-1 rounded-lg text-slate-400 hover:text-white"
              >
                <XCircle className="w-5 h-5" />
              </button>
            </div>

            <form onSubmit={handleAddSubmit} className="space-y-3.5">
              <div>
                <label className="block text-xs font-semibold text-slate-300 mb-1">Username *</label>
                <input
                  type="text"
                  required
                  placeholder="e.g. operator_ali"
                  value={username}
                  onChange={(e) => setUsername(e.target.value)}
                  className="w-full px-3.5 py-2 rounded-xl bg-[#182230] border border-[#2d3c52] text-xs sm:text-sm text-white"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-300 mb-1">Email Address *</label>
                <input
                  type="email"
                  required
                  placeholder="e.g. ali@herculepos.iq"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  className="w-full px-3.5 py-2 rounded-xl bg-[#182230] border border-[#2d3c52] text-xs sm:text-sm text-white"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-300 mb-1">Assigned Role</label>
                <select
                  value={role}
                  onChange={(e) => setRole(e.target.value as AdminRole)}
                  className="w-full px-3.5 py-2 rounded-xl bg-[#182230] border border-[#2d3c52] text-xs sm:text-sm text-white"
                >
                  <option value="admin">Admin (Issue & Manage Licenses)</option>
                  <option value="owner">Owner (Full Authority & User Control)</option>
                </select>
              </div>

              <div className="flex items-center justify-end gap-3 pt-3 border-t border-[#243042]">
                <button
                  type="button"
                  onClick={() => setIsAddModalOpen(false)}
                  className="px-4 py-2 rounded-xl bg-[#182230] text-slate-300 text-xs font-semibold"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  className="px-5 py-2 rounded-xl bg-sky-500 hover:bg-sky-400 text-slate-950 font-bold text-xs sm:text-sm shadow-md"
                >
                  Create Account
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

    </div>
  );
};
