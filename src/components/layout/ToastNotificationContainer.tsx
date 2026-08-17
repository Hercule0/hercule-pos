import React from 'react';
import { useApp } from '../../context/AppContext';
import {
  Key,
  Clock,
  ShieldAlert,
  Laptop,
  X,
  ArrowRight,
  Zap
} from 'lucide-react';

interface ToastNotificationContainerProps {
  onNavigateTab: (tab: string, id?: string | number) => void;
}

export const ToastNotificationContainer: React.FC<ToastNotificationContainerProps> = ({
  onNavigateTab,
}) => {
  const { toasts, dismissToast } = useApp();

  if (!toasts || toasts.length === 0) return null;

  return (
    <div 
      aria-live="polite"
      className="fixed top-16 right-0 left-0 sm:left-auto sm:right-4 z-50 flex flex-col gap-2.5 max-w-md w-full px-3 pointer-events-none transition-all duration-300"
    >
      {toasts.map((toast) => {
        const getIcon = () => {
          switch (toast.type) {
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

        const getBorderBg = () => {
          switch (toast.type) {
            case 'recovery':
              return 'border-rose-500/50 bg-[#17131b]/95 shadow-rose-950/40';
            case 'expiry':
              return 'border-amber-500/50 bg-[#191612]/95 shadow-amber-950/40';
            case 'activation':
              return 'border-emerald-500/50 bg-[#0f1b17]/95 shadow-emerald-950/40';
            default:
              return 'border-sky-500/50 bg-[#111926]/95 shadow-sky-950/40';
          }
        };

        return (
          <div
            key={toast.id}
            id={`toast-${toast.id}`}
            className={`pointer-events-auto rounded-xl border p-3.5 shadow-xl backdrop-blur-md transition-all animate-in fade-in slide-in-from-top-3 duration-200 relative overflow-hidden ${getBorderBg()}`}
          >
            {/* Top row */}
            <div className="flex items-start gap-3">
              <div className="p-2 rounded-lg bg-black/40 border border-white/10 shrink-0">
                {getIcon()}
              </div>

              <div className="flex-1 min-w-0">
                <div className="flex items-center justify-between gap-2">
                  <div className="flex items-center gap-1.5 min-w-0">
                    <Zap className="w-3 h-3 text-sky-400 shrink-0 animate-pulse" />
                    <span className="text-xs font-bold text-white truncate">
                      {toast.title}
                    </span>
                  </div>
                  <span className="text-[10px] text-slate-400 shrink-0">
                    {toast.timestamp.substring(11, 16)}
                  </span>
                </div>

                <p className="text-xs text-slate-300 mt-1 leading-snug break-words">
                  {toast.message}
                </p>

                {/* Quick actions */}
                <div className="mt-2.5 flex items-center justify-between">
                  {toast.linkTab ? (
                    <button
                      onClick={() => {
                        onNavigateTab(toast.linkTab!, toast.linkId);
                        dismissToast(toast.id);
                      }}
                      className="inline-flex items-center gap-1 text-[11px] font-semibold text-sky-400 hover:text-sky-300 transition-colors"
                    >
                      <span>View Details</span>
                      <ArrowRight className="w-3 h-3" />
                    </button>
                  ) : <div />}

                  <button
                    onClick={() => dismissToast(toast.id)}
                    className="text-[11px] text-slate-400 hover:text-slate-200 px-1.5 py-0.5 rounded hover:bg-white/5 transition-colors"
                  >
                    Dismiss
                  </button>
                </div>
              </div>

              <button
                onClick={() => dismissToast(toast.id)}
                className="text-slate-400 hover:text-white p-1 rounded-lg hover:bg-white/10 transition-colors -mr-1 -mt-1"
                aria-label="Close notification toast"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            {/* Bottom linear progress bar timer */}
            <div className="absolute bottom-0 left-0 right-0 h-0.5 bg-white/10">
              <div 
                className="h-full bg-sky-400 animate-[shrink_4.5s_linear_forwards]"
                style={{
                  animation: 'toast-timer 4.5s linear forwards',
                }}
              />
            </div>
          </div>
        );
      })}
    </div>
  );
};
