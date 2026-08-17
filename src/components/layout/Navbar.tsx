import React, { useState } from 'react';
import { useApp } from '../../context/AppContext';
import { 
  Shield, 
  Bell, 
  Volume2, 
  VolumeX, 
  Lock, 
  ChevronDown, 
  UserCheck, 
  Menu, 
  X, 
  Smartphone, 
  Zap, 
  CheckCircle2, 
  AlertTriangle 
} from 'lucide-react';

interface NavbarProps {
  activeTab: string;
  setActiveTab: (tab: string) => void;
  onOpenNotifications: () => void;
  mobileMenuOpen: boolean;
  setMobileMenuOpen: (open: boolean) => void;
}

export const Navbar: React.FC<NavbarProps> = ({
  activeTab,
  setActiveTab,
  onOpenNotifications,
  mobileMenuOpen,
  setMobileMenuOpen,
}) => {
  const { 
    currentUser, 
    setCurrentUser, 
    users, 
    notifications, 
    soundEnabled, 
    setSoundEnabled,
    pushPermission,
    requestPushPermission,
    testInstantNotification
  } = useApp();
  
  const [userDropdownOpen, setUserDropdownOpen] = useState(false);

  const unreadCount = notifications.filter(n => !n.read).length;

  const roleColors: Record<string, string> = {
    owner: 'bg-sky-500/15 text-sky-400 border-sky-500/30',
    support: 'bg-emerald-500/15 text-emerald-400 border-emerald-500/30',
    read_only: 'bg-amber-500/15 text-amber-400 border-amber-500/30',
  };

  return (
    <header className="sticky top-0 z-40 w-full bg-[#111722]/95 backdrop-blur-md border-b border-[#243042] px-3 sm:px-6 py-2.5 transition-colors shrink-0">
      <div className="flex items-center justify-between gap-3 max-w-7xl mx-auto">
        
        {/* Left: Mobile Drawer Trigger & Brand */}
        <div className="flex items-center gap-2.5 sm:gap-3">
          <button
            id="mobile-menu-btn"
            onClick={() => setMobileMenuOpen(!mobileMenuOpen)}
            className="lg:hidden p-2 rounded-xl text-slate-300 hover:text-white bg-[#161f2c] border border-[#243042] hover:bg-[#1a2332] transition-colors focus:outline-none focus:ring-2 focus:ring-sky-500"
            aria-label="Toggle navigation menu"
          >
            {mobileMenuOpen ? <X className="w-5 h-5" /> : <Menu className="w-5 h-5" />}
          </button>

          <div 
            onClick={() => setActiveTab('dashboard')}
            className="flex items-center gap-2.5 cursor-pointer select-none group"
          >
            <div className="w-9 h-9 rounded-xl bg-gradient-to-br from-sky-500 to-blue-600 p-0.5 shadow-lg shadow-sky-500/20 flex items-center justify-center">
              <div className="w-full h-full bg-[#111722] rounded-[10px] flex items-center justify-center group-hover:bg-transparent transition-colors">
                <Shield className="w-4 h-4 text-sky-400 group-hover:text-white transition-colors" />
              </div>
            </div>
            <div>
              <div className="flex items-center gap-2">
                <span className="font-extrabold text-base tracking-tight text-white">HERCULE</span>
                <span className="text-[10px] font-bold px-1.5 py-0.2 rounded bg-sky-500/10 text-sky-400 border border-sky-500/20">v8.3</span>
              </div>
              <p className="text-[10px] text-slate-400 font-medium hidden sm:block">Authority & POS Licensing</p>
            </div>
          </div>
        </div>

        {/* Center: System Status & Quick Push Trigger */}
        <div className="hidden md:flex items-center gap-2.5 px-3 py-1 rounded-full bg-[#161f2c] border border-[#243042] text-xs text-slate-300">
          <span className="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
          <span className="font-medium text-slate-200">Server 8.3 &bull; RSA-2048 Signer</span>
          <span className="text-slate-500">|</span>
          {pushPermission === 'granted' ? (
            <span className="text-emerald-400 font-medium flex items-center gap-1">
              <Smartphone className="w-3 h-3" /> Push Ready
            </span>
          ) : (
            <button 
              onClick={requestPushPermission}
              className="text-sky-400 hover:text-sky-300 font-semibold underline flex items-center gap-1 cursor-pointer"
            >
              <Smartphone className="w-3 h-3" /> Enable Push
            </button>
          )}
        </div>

        {/* Right: Actions & User Account */}
        <div className="flex items-center gap-1.5 sm:gap-2.5">
          
          {/* Quick Push Test button on Desktop & Mobile */}
          <button
            id="navbar-quick-test-notify"
            onClick={() => testInstantNotification('⚡ Instant Terminal Alert', 'POS Station #03 verified RSA cryptographic token successfully.')}
            className="hidden sm:flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg bg-sky-500/10 hover:bg-sky-500/20 border border-sky-500/30 text-sky-300 hover:text-white transition-all text-xs font-semibold"
            title="Fire instant push notification with sound & vibration (<50ms)"
          >
            <Zap className="w-3.5 h-3.5 text-amber-400 animate-pulse" />
            <span>Fast Test</span>
          </button>

          {/* Sound alert toggle */}
          <button
            id="sound-toggle-btn"
            onClick={() => setSoundEnabled(!soundEnabled)}
            className={`p-2 rounded-lg transition-colors border ${
              soundEnabled 
                ? 'bg-[#161f2c] text-sky-400 border-sky-500/30 hover:bg-sky-500/10' 
                : 'bg-[#161f2c] text-slate-500 border-[#243042] hover:text-slate-300'
            }`}
            title={soundEnabled ? 'Audio alerts enabled' : 'Audio alerts muted'}
            aria-label="Toggle sound alerts"
          >
            {soundEnabled ? <Volume2 className="w-4 h-4" /> : <VolumeX className="w-4 h-4" />}
          </button>

          {/* Notifications Button with unread badge */}
          <button
            id="notifications-btn"
            onClick={onOpenNotifications}
            className="relative p-2 rounded-lg bg-[#161f2c] border border-[#243042] text-slate-300 hover:text-white hover:bg-[#1a2332] transition-colors"
            title="Open Notifications Drawer"
            aria-label="View notifications"
          >
            <Bell className="w-4 h-4" />
            {unreadCount > 0 && (
              <span className="absolute -top-1 -right-1 flex h-4 min-w-4 px-1 items-center justify-center rounded-full bg-rose-500 text-[10px] font-bold text-white shadow-sm ring-2 ring-[#111722] animate-pulse">
                {unreadCount}
              </span>
            )}
          </button>

          {/* Current User Account & Switcher */}
          <div className="relative">
            <button
              id="user-menu-btn"
              onClick={() => setUserDropdownOpen(!userDropdownOpen)}
              className="flex items-center gap-2 p-1 sm:px-2.5 sm:py-1.5 rounded-lg bg-[#161f2c] border border-[#243042] hover:border-slate-600 transition-all text-left"
            >
              <div 
                className="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold text-slate-900 shadow-inner"
                style={{ backgroundColor: currentUser.avatar_color || '#3fa9f5' }}
              >
                {currentUser.username.charAt(0).toUpperCase()}
              </div>
              <div className="hidden sm:block">
                <div className="text-xs font-semibold text-white leading-tight flex items-center gap-1.5">
                  <span>{currentUser.username}</span>
                  <ChevronDown className="w-3 h-3 text-slate-400" />
                </div>
                <span className={`inline-block text-[10px] font-medium uppercase px-1 rounded border ${roleColors[currentUser.role]}`}>
                  {currentUser.role}
                </span>
              </div>
            </button>

            {/* Dropdown Menu */}
            {userDropdownOpen && (
              <>
                <div 
                  className="fixed inset-0 z-40" 
                  onClick={() => setUserDropdownOpen(false)}
                />
                <div className="absolute right-0 mt-2 w-64 rounded-xl bg-[#161f2c] border border-[#2a374a] shadow-2xl z-50 py-2 divide-y divide-[#243042] animate-in fade-in zoom-in-95 duration-100">
                  <div className="px-3.5 py-2">
                    <p className="text-xs text-slate-400 font-medium">Logged in as</p>
                    <p className="text-sm font-bold text-white">{currentUser.username}</p>
                    <div className="mt-1 flex items-center gap-2">
                      <span className={`text-[10px] font-semibold uppercase px-1.5 py-0.5 rounded border ${roleColors[currentUser.role]}`}>
                        Role: {currentUser.role}
                      </span>
                      {currentUser.totp_enabled && (
                        <span className="text-[10px] text-emerald-400 flex items-center gap-0.5 font-medium">
                          <Lock className="w-3 h-3" /> 2FA Active
                        </span>
                      )}
                    </div>
                  </div>

                  <div className="py-1">
                    <button
                      onClick={() => {
                        setActiveTab('mfa');
                        setUserDropdownOpen(false);
                      }}
                      className="w-full flex items-center gap-2.5 px-3.5 py-2 text-xs text-slate-300 hover:text-white hover:bg-[#1f2b3d] transition-colors"
                    >
                      <Lock className="w-3.5 h-3.5 text-sky-400" />
                      <span>Two-Factor (TOTP) & Security</span>
                    </button>
                    <button
                      onClick={() => {
                        requestPushPermission();
                        setUserDropdownOpen(false);
                      }}
                      className="w-full flex items-center gap-2.5 px-3.5 py-2 text-xs text-slate-300 hover:text-white hover:bg-[#1f2b3d] transition-colors"
                    >
                      <Smartphone className="w-3.5 h-3.5 text-sky-400" />
                      <span>Request Mobile Push Permission</span>
                    </button>
                  </div>

                  {/* Switch admin user profile */}
                  <div className="py-1">
                    <div className="px-3.5 py-1 text-[10px] font-bold uppercase tracking-wider text-slate-400">
                      Switch Active Account
                    </div>
                    {users.map((u) => (
                      <button
                        key={u.id}
                        onClick={() => {
                          setCurrentUser(u);
                          setUserDropdownOpen(false);
                        }}
                        className={`w-full flex items-center justify-between px-3.5 py-2 text-xs transition-colors ${
                          u.id === currentUser.id
                            ? 'bg-sky-500/10 text-sky-400 font-semibold'
                            : 'text-slate-300 hover:bg-[#1f2b3d] hover:text-white'
                        }`}
                      >
                        <div className="flex items-center gap-2">
                          <div
                            className="w-5 h-5 rounded-full flex items-center justify-center text-[10px] font-bold text-slate-900"
                            style={{ backgroundColor: u.avatar_color || '#3fa9f5' }}
                          >
                            {u.username.charAt(0).toUpperCase()}
                          </div>
                          <span>{u.username}</span>
                        </div>
                        <span className="text-[10px] text-slate-400 capitalize">{u.role}</span>
                      </button>
                    ))}
                  </div>
                </div>
              </>
            )}
          </div>
        </div>
      </div>
    </header>
  );
};
