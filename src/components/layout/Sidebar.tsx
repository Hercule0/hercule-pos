import React from 'react';
import { useApp } from '../../context/AppContext';
import {
  LayoutDashboard,
  KeyRound,
  Users,
  Key,
  ShieldCheck,
  Terminal,
  Activity,
  UserCheck,
  FileSpreadsheet,
  Bell,
  Zap,
  X,
  Shield,
  Smartphone,
  CheckCircle2,
  AlertCircle
} from 'lucide-react';

interface SidebarProps {
  activeTab: string;
  setActiveTab: (tab: string) => void;
  mobileMenuOpen?: boolean;
  setMobileMenuOpen?: (open: boolean) => void;
}

export const Sidebar: React.FC<SidebarProps> = ({
  activeTab,
  setActiveTab,
  mobileMenuOpen,
  setMobileMenuOpen,
}) => {
  const { 
    recoveryRequests, 
    licenses, 
    currentUser, 
    exportLicensesCsv,
    pushPermission,
    requestPushPermission,
    testInstantNotification,
    fastNotifyMode,
    setFastNotifyMode
  } = useApp();

  const pendingRecoveryCount = recoveryRequests.filter(r => r.status === 'pending').length;
  const expiringLicensesCount = licenses.filter(l => {
    if (!l.expires_at || l.status !== 'active') return false;
    const diff = new Date(l.expires_at).getTime() - Date.now();
    return diff > 0 && diff < 30 * 24 * 3600 * 1000;
  }).length;

  const navItems = [
    {
      id: 'dashboard',
      label: 'Dashboard',
      icon: LayoutDashboard,
      badge: null,
    },
    {
      id: 'licenses',
      label: 'Licenses',
      icon: KeyRound,
      badge: expiringLicensesCount > 0 ? `${expiringLicensesCount} expiring` : null,
      badgeColor: 'bg-amber-500/20 text-amber-300 border-amber-500/30',
    },
    {
      id: 'customers',
      label: 'Customers',
      icon: Users,
      badge: null,
    },
    {
      id: 'recovery',
      label: 'Password Recovery',
      icon: Key,
      badge: pendingRecoveryCount > 0 ? `${pendingRecoveryCount} pending` : null,
      badgeColor: 'bg-rose-500/20 text-rose-300 border-rose-500/30 animate-pulse',
    },
    {
      id: 'api-tester',
      label: 'API & Simulator',
      icon: Terminal,
      badge: 'RSA-2048',
      badgeColor: 'bg-sky-500/10 text-sky-400 border-sky-500/20',
    },
    {
      id: 'admins',
      label: 'Admin Users',
      icon: UserCheck,
      badge: currentUser.role === 'owner' ? 'Owner' : 'Restricted',
      badgeColor: currentUser.role === 'owner' ? 'bg-indigo-500/20 text-indigo-300' : 'bg-slate-700 text-slate-400',
    },
    {
      id: 'mfa',
      label: 'MFA & Security',
      icon: ShieldCheck,
      badge: null,
    },
    {
      id: 'health',
      label: 'System Health',
      icon: Activity,
      badge: 'Healthy',
      badgeColor: 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20',
    },
  ];

  const handleNavClick = (id: string) => {
    setActiveTab(id);
    if (setMobileMenuOpen) {
      setMobileMenuOpen(false);
    }
  };

  return (
    <>
      {/* Mobile Drawer Backdrop Overlay */}
      {mobileMenuOpen && (
        <div
          className="fixed inset-0 bg-black/70 backdrop-blur-sm z-40 lg:hidden transition-opacity duration-300"
          onClick={() => setMobileMenuOpen && setMobileMenuOpen(false)}
        />
      )}

      {/* Sidebar Container: Fixed & Sticky on Desktop (never scrolls up out of view), Slide-over drawer on Mobile */}
      <aside
        className={`fixed inset-y-0 left-0 z-50 w-72 lg:w-64 bg-[#111722] border-r border-[#243042] flex flex-col justify-between transition-transform duration-300 ease-in-out lg:translate-x-0 ${
          mobileMenuOpen ? 'translate-x-0 shadow-2xl' : '-translate-x-full'
        } lg:sticky lg:top-[61px] lg:h-[calc(100vh-61px)] lg:shrink-0 lg:z-30`}
      >
        {/* Mobile Header in Drawer */}
        <div className="lg:hidden flex items-center justify-between p-4 border-b border-[#243042] bg-[#0d131c]">
          <div className="flex items-center gap-2.5">
            <div className="w-8 h-8 rounded-lg bg-sky-500/20 border border-sky-500/30 flex items-center justify-center">
              <Shield className="w-4 h-4 text-sky-400" />
            </div>
            <div>
              <span className="font-extrabold text-sm text-white">HERCULE POS</span>
              <p className="text-[10px] text-slate-400">Authority Control</p>
            </div>
          </div>
          <button
            onClick={() => setMobileMenuOpen && setMobileMenuOpen(false)}
            className="p-1.5 rounded-lg text-slate-400 hover:text-white hover:bg-[#1a2433] transition-colors"
            aria-label="Close menu"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        {/* Scrollable Navigation List */}
        <div className="flex-1 p-3.5 space-y-5 overflow-y-auto">
          
          {/* Main Navigation Section */}
          <div>
            <div className="px-3 pb-2 text-[11px] font-bold uppercase tracking-wider text-slate-400">
              Management Portal
            </div>
            <nav className="space-y-1">
              {navItems.map((item) => {
                const Icon = item.icon;
                const isActive = activeTab === item.id;
                return (
                  <button
                    key={item.id}
                    id={`nav-item-${item.id}`}
                    onClick={() => handleNavClick(item.id)}
                    className={`w-full flex items-center justify-between px-3 py-2.5 rounded-xl text-sm font-medium transition-all ${
                      isActive
                        ? 'bg-sky-500/15 text-sky-400 border border-sky-500/30 shadow-sm shadow-sky-500/5 font-semibold'
                        : 'text-slate-400 hover:text-slate-200 hover:bg-[#161f2c]'
                    }`}
                  >
                    <div className="flex items-center gap-3">
                      <Icon className={`w-4 h-4 ${isActive ? 'text-sky-400' : 'text-slate-400'}`} />
                      <span>{item.label}</span>
                    </div>
                    {item.badge && (
                      <span className={`text-[10px] font-semibold px-1.5 py-0.5 rounded-full border ${item.badgeColor}`}>
                        {item.badge}
                      </span>
                    )}
                  </button>
                );
              })}
            </nav>
          </div>

          {/* Live Mobile Push & Fast Notify Control */}
          <div className="pt-2 border-t border-[#1e293b]">
            <div className="px-3 pb-2 flex items-center justify-between">
              <span className="text-[11px] font-bold uppercase tracking-wider text-slate-400">
                Phone Push & Alerts
              </span>
              <span className="relative flex h-2 w-2">
                <span className={`animate-ping absolute inline-flex h-full w-full rounded-full opacity-75 ${pushPermission === 'granted' ? 'bg-emerald-400' : 'bg-amber-400'}`} />
                <span className={`relative inline-flex rounded-full h-2 w-2 ${pushPermission === 'granted' ? 'bg-emerald-500' : 'bg-amber-500'}`} />
              </span>
            </div>

            <div className="space-y-2 px-1">
              {/* Push Permission Button */}
              {pushPermission !== 'granted' ? (
                <button
                  id="enable-push-sidebar-btn"
                  onClick={requestPushPermission}
                  className="w-full flex items-center justify-between p-2.5 rounded-xl bg-gradient-to-r from-sky-500/20 to-blue-500/10 border border-sky-500/40 text-sky-300 hover:text-white hover:bg-sky-500/25 transition-all text-xs font-semibold"
                >
                  <div className="flex items-center gap-2">
                    <Smartphone className="w-4 h-4 text-sky-400 shrink-0" />
                    <span className="text-left">Enable Phone Push</span>
                  </div>
                  <span className="text-[10px] bg-sky-500 text-slate-950 font-bold px-1.5 py-0.5 rounded">
                    Tap
                  </span>
                </button>
              ) : (
                <div className="flex items-center justify-between p-2 rounded-xl bg-emerald-500/10 border border-emerald-500/25 text-xs text-emerald-300">
                  <div className="flex items-center gap-1.5">
                    <CheckCircle2 className="w-3.5 h-3.5 text-emerald-400 shrink-0" />
                    <span>Phone Push: Active</span>
                  </div>
                  <span className="text-[10px] text-emerald-400 font-mono">200 OK</span>
                </div>
              )}

              {/* Fast Instant Notification Trigger */}
              <button
                id="instant-test-notify-btn"
                onClick={() => testInstantNotification()}
                className="w-full flex items-center justify-between px-3 py-2 rounded-xl text-xs font-medium text-slate-300 bg-[#161f2c] hover:bg-[#1f2b3d] border border-[#243042] hover:border-sky-500/30 transition-all group"
                title="Send instant push notification with sound & vibration"
              >
                <div className="flex items-center gap-2">
                  <Zap className="w-3.5 h-3.5 text-amber-400 group-hover:scale-110 transition-transform" />
                  <span>Test Instant Alert</span>
                </div>
                <span className="text-[10px] text-slate-400 bg-[#111722] px-1.5 py-0.5 rounded border border-[#243042]">
                  &lt;50ms
                </span>
              </button>

              {/* Fast Simulated Alerts Mode Toggle */}
              <button
                id="toggle-fast-mode-btn"
                onClick={() => setFastNotifyMode(!fastNotifyMode)}
                className={`w-full flex items-center justify-between px-3 py-2 rounded-xl text-xs font-medium transition-all border ${
                  fastNotifyMode
                    ? 'bg-amber-500/15 border-amber-500/40 text-amber-300 shadow-sm'
                    : 'bg-[#161f2c] border-[#243042] text-slate-400 hover:text-slate-200'
                }`}
              >
                <div className="flex items-center gap-2">
                  <Bell className={`w-3.5 h-3.5 ${fastNotifyMode ? 'text-amber-400 animate-bounce' : 'text-slate-400'}`} />
                  <span>Auto Alert Stream</span>
                </div>
                <span className={`text-[10px] px-1.5 py-0.5 rounded font-bold ${fastNotifyMode ? 'bg-amber-400 text-slate-950' : 'bg-slate-800 text-slate-400'}`}>
                  {fastNotifyMode ? 'ON' : 'OFF'}
                </span>
              </button>
            </div>
          </div>

          {/* Quick Actions Panel */}
          <div className="pt-2 border-t border-[#1e293b]">
            <div className="px-3 pb-2 text-[11px] font-bold uppercase tracking-wider text-slate-400">
              Data & Export
            </div>
            <div className="space-y-1">
              <button
                id="sidebar-export-csv-btn"
                onClick={exportLicensesCsv}
                className="w-full flex items-center gap-3 px-3 py-2 rounded-xl text-xs font-medium text-slate-400 hover:text-slate-200 hover:bg-[#161f2c] transition-colors"
              >
                <FileSpreadsheet className="w-4 h-4 text-emerald-400" />
                <span>Export Licenses CSV</span>
              </button>
            </div>
          </div>
        </div>

        {/* Footer Info */}
        <div className="p-3.5 border-t border-[#243042] bg-[#0e141e]/70">
          <div className="flex items-center justify-between text-xs text-slate-400">
            <div>
              <p className="font-semibold text-slate-300">Hercule POS License</p>
              <p className="text-[11px] text-slate-400">Phase 6 &bull; Signed v8.3</p>
            </div>
            <div className="flex items-center gap-1.5">
              <span className="w-2 h-2 rounded-full bg-emerald-400" title="System Operational" />
              <span className="text-[10px] text-emerald-400 font-mono">LIVE</span>
            </div>
          </div>
        </div>
      </aside>
    </>
  );
};
