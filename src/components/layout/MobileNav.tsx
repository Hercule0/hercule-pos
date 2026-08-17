import React from 'react';
import { useApp } from '../../context/AppContext';
import {
  LayoutDashboard,
  KeyRound,
  Users,
  Key,
  MoreHorizontal
} from 'lucide-react';

interface MobileNavProps {
  activeTab: string;
  setActiveTab: (tab: string) => void;
  onOpenMoreMenu: () => void;
}

export const MobileNav: React.FC<MobileNavProps> = ({
  activeTab,
  setActiveTab,
  onOpenMoreMenu,
}) => {
  const { recoveryRequests } = useApp();
  const pendingRecoveryCount = recoveryRequests.filter(r => r.status === 'pending').length;

  const items = [
    { id: 'dashboard', label: 'Dashboard', icon: LayoutDashboard },
    { id: 'licenses', label: 'Licenses', icon: KeyRound },
    { id: 'customers', label: 'Customers', icon: Users },
    { 
      id: 'recovery', 
      label: 'Recovery', 
      icon: Key, 
      badge: pendingRecoveryCount > 0 ? pendingRecoveryCount : null 
    },
  ];

  return (
    <nav 
      aria-label="Mobile Navigation"
      className="lg:hidden fixed bottom-0 left-0 right-0 z-40 bg-[#111722]/95 backdrop-blur-lg border-t border-[#243042] px-2 py-1.5 pb-safe shadow-[0_-8px_30px_rgba(0,0,0,0.4)]"
    >
      <div className="grid grid-cols-5 gap-1 items-center">
        {items.map((item) => {
          const Icon = item.icon;
          const isActive = activeTab === item.id;
          return (
            <button
              key={item.id}
              id={`mobile-nav-${item.id}`}
              onClick={() => setActiveTab(item.id)}
              className={`relative flex flex-col items-center justify-center min-h-[50px] py-1 px-1 rounded-xl transition-all ${
                isActive
                  ? 'text-sky-400 font-bold bg-sky-500/10'
                  : 'text-slate-400 hover:text-slate-200'
              }`}
            >
              <div className="relative">
                <Icon className={`w-5 h-5 ${isActive ? 'text-sky-400 scale-105' : 'text-slate-400'}`} />
                {item.badge && (
                  <span className="absolute -top-1.5 -right-2.5 flex h-4 min-w-4 px-1 items-center justify-center rounded-full bg-rose-500 text-[10px] font-bold text-white ring-2 ring-[#111722]">
                    {item.badge}
                  </span>
                )}
              </div>
              <span className="text-[11px] tracking-tight mt-1">{item.label}</span>
            </button>
          );
        })}

        {/* More / Menu trigger */}
        <button
          id="mobile-nav-more"
          onClick={onOpenMoreMenu}
          className={`flex flex-col items-center justify-center min-h-[50px] py-1 px-1 rounded-xl transition-all ${
            ['api-tester', 'admins', 'mfa', 'health'].includes(activeTab)
              ? 'text-sky-400 font-bold bg-sky-500/10'
              : 'text-slate-400 hover:text-slate-200'
          }`}
        >
          <MoreHorizontal className="w-5 h-5 text-slate-400" />
          <span className="text-[11px] tracking-tight mt-1">Tools</span>
        </button>
      </div>
    </nav>
  );
};
