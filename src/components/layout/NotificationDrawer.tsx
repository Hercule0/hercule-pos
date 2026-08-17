import React from 'react';
import { useApp } from '../../context/AppContext';
import {
  X,
  Bell,
  CheckCheck,
  Trash2,
  Key,
  Clock,
  ShieldAlert,
  Laptop,
  ArrowRight,
  Smartphone,
  Zap,
  CheckCircle2
} from 'lucide-react';

interface NotificationDrawerProps {
  isOpen: boolean;
  onClose: () => void;
  onNavigateTab: (tab: string, id?: number | string) => void;
}

export const NotificationDrawer: React.FC<NotificationDrawerProps> = ({
  isOpen,
  onClose,
  onNavigateTab,
}) => {
  const { 
    notifications, 
    markNotificationAsRead, 
    markAllNotificationsAsRead, 
    clearNotifications,
    pushPermission,
    requestPushPermission,
    testInstantNotification,
    fastNotifyMode,
    setFastNotifyMode
  } = useApp();

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-50 overflow-hidden">
      <div 
        className="absolute inset-0 bg-black/70 backdrop-blur-sm transition-opacity"
        onClick={onClose}
      />
      <div className="fixed inset-y-0 right-0 max-w-full flex pl-6 sm:pl-10">
        <div className="w-screen max-w-md bg-[#131a24] border-l border-[#243042] shadow-2xl flex flex-col">
          
          {/* Header */}
          <div className="p-4 border-b border-[#243042] flex items-center justify-between bg-[#111722]">
            <div className="flex items-center gap-2.5">
              <div className="p-2 rounded-lg bg-sky-500/10 text-sky-400 border border-sky-500/20">
                <Bell className="w-4 h-4" />
              </div>
              <div>
                <h2 className="text-sm font-bold text-white">Notifications & Alerts</h2>
                <p className="text-xs text-slate-400">
                  {notifications.filter(n => !n.read).length} unread events
                </p>
              </div>
            </div>

            <div className="flex items-center gap-1">
              {notifications.length > 0 && (
                <>
                  <button
                    id="mark-all-read-btn"
                    onClick={markAllNotificationsAsRead}
                    className="p-1.5 rounded-lg text-slate-400 hover:text-white hover:bg-[#1f2b3d] transition-colors"
                    title="Mark all as read"
                  >
                    <CheckCheck className="w-4 h-4" />
                  </button>
                  <button
                    id="clear-all-notifs-btn"
                    onClick={clearNotifications}
                    className="p-1.5 rounded-lg text-slate-400 hover:text-rose-400 hover:bg-rose-500/10 transition-colors"
                    title="Clear all notifications"
                  >
                    <Trash2 className="w-4 h-4" />
                  </button>
                </>
              )}
              <button
                id="close-notifs-btn"
                onClick={onClose}
                className="p-1.5 rounded-lg text-slate-400 hover:text-white hover:bg-[#1f2b3d] transition-colors ml-1"
                aria-label="Close notification drawer"
              >
                <X className="w-5 h-5" />
              </button>
            </div>
          </div>

          {/* Quick Phone Push Controls Bar */}
          <div className="p-3 bg-[#0d131c] border-b border-[#243042] space-y-2.5">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-1.5 text-xs font-semibold text-slate-200">
                <Smartphone className="w-3.5 h-3.5 text-sky-400" />
                <span>Phone Push Alert Status</span>
              </div>
              {pushPermission === 'granted' ? (
                <span className="inline-flex items-center gap-1 text-[10px] font-bold text-emerald-400 bg-emerald-500/15 border border-emerald-500/30 px-2 py-0.5 rounded-full">
                  <CheckCircle2 className="w-3 h-3" /> Active
                </span>
              ) : (
                <button
                  onClick={requestPushPermission}
                  className="text-[10px] font-bold text-sky-300 hover:text-white bg-sky-500/20 border border-sky-500/40 px-2 py-0.5 rounded-full transition-colors"
                >
                  Enable Now &rarr;
                </button>
              )}
            </div>

            <div className="grid grid-cols-2 gap-2">
              <button
                onClick={() => testInstantNotification('⚡ Fast Test Notification', 'Direct alert sent to device shade and browser with sound & vibration.')}
                className="flex items-center justify-center gap-1.5 p-2 rounded-xl bg-[#161f2c] border border-[#243042] hover:border-sky-500/40 text-xs font-medium text-slate-300 hover:text-white transition-colors"
              >
                <Zap className="w-3.5 h-3.5 text-amber-400" />
                <span>Test Alert</span>
              </button>

              <button
                onClick={() => setFastNotifyMode(!fastNotifyMode)}
                className={`flex items-center justify-center gap-1.5 p-2 rounded-xl border text-xs font-medium transition-colors ${
                  fastNotifyMode 
                    ? 'bg-amber-500/20 border-amber-500/40 text-amber-300' 
                    : 'bg-[#161f2c] border-[#243042] text-slate-400 hover:text-slate-200'
                }`}
              >
                <Bell className="w-3.5 h-3.5 text-slate-400" />
                <span>Auto-Stream: {fastNotifyMode ? 'ON' : 'OFF'}</span>
              </button>
            </div>
          </div>

          {/* List of Notifications */}
          <div className="flex-1 overflow-y-auto p-4 space-y-3">
            {notifications.length === 0 ? (
              <div className="h-full flex flex-col items-center justify-center text-center p-8 text-slate-400">
                <Bell className="w-12 h-12 stroke-1 mb-3 text-slate-400" />
                <p className="text-sm font-medium text-slate-300">All caught up!</p>
                <p className="text-xs text-slate-400 mt-1 max-w-xs">
                  No active warnings or pending terminal recovery requests.
                </p>
                <button
                  onClick={() => testInstantNotification()}
                  className="mt-4 px-3 py-1.5 rounded-lg bg-sky-500/20 border border-sky-500/30 text-sky-300 hover:text-white text-xs font-semibold transition-colors"
                >
                  Generate Test Alert
                </button>
              </div>
            ) : (
              notifications.map((n) => {
                const getIcon = () => {
                  switch (n.type) {
                    case 'recovery':
                      return <Key className="w-4 h-4 text-rose-400" />;
                    case 'expiry':
                      return <Clock className="w-4 h-4 text-amber-400" />;
                    case 'activation':
                      return <Laptop className="w-4 h-4 text-emerald-400" />;
                    default:
                      return <ShieldAlert className="w-4 h-4 text-sky-400" />;
                  }
                };

                return (
                  <div
                    key={n.id}
                    className={`p-3.5 rounded-xl border transition-all ${
                      n.read
                        ? 'bg-[#161f2c]/50 border-[#243042]/60 text-slate-400'
                        : 'bg-[#1a2433] border-sky-500/30 text-slate-200 shadow-sm'
                    }`}
                  >
                    <div className="flex items-start gap-3">
                      <div className="mt-0.5 p-1.5 rounded-lg bg-[#111722] border border-[#243042] shrink-0">
                        {getIcon()}
                      </div>
                      <div className="flex-1 min-w-0">
                        <div className="flex items-center justify-between gap-2">
                          <h3 className={`text-xs font-semibold truncate ${n.read ? 'text-slate-300' : 'text-white font-bold'}`}>
                            {n.title}
                          </h3>
                          <span className="text-[10px] text-slate-400 shrink-0">{n.timestamp.substring(11, 16)}</span>
                        </div>
                        <p className="text-xs text-slate-300 mt-1 leading-relaxed">{n.message}</p>
                        
                        <div className="mt-2.5 flex items-center justify-between">
                          {n.linkTab ? (
                            <button
                              onClick={() => {
                                markNotificationAsRead(n.id);
                                onNavigateTab(n.linkTab!, n.linkId);
                                onClose();
                              }}
                              className="inline-flex items-center gap-1 text-[11px] font-semibold text-sky-400 hover:text-sky-300 transition-colors"
                            >
                              <span>View Details</span>
                              <ArrowRight className="w-3 h-3" />
                            </button>
                          ) : <div />}

                          {!n.read && (
                            <button
                              onClick={() => markNotificationAsRead(n.id)}
                              className="text-[10px] text-slate-400 hover:text-slate-200 px-1.5 py-0.5 rounded hover:bg-white/5"
                            >
                              Mark read
                            </button>
                          )}
                        </div>
                      </div>
                    </div>
                  </div>
                );
              })
            )}
          </div>
        </div>
      </div>
    </div>
  );
};
