import React, { useState } from 'react';
import { useApp } from '../context/AppContext';
import {
  KeyRound,
  Laptop,
  Clock,
  Key,
  ShieldCheck,
  AlertCircle,
  Plus,
  ArrowUpRight,
  RefreshCw,
  Sparkles,
  FileCheck,
  Zap,
  TrendingUp,
  CheckCircle2,
  XCircle,
  Clock4
} from 'lucide-react';
import {
  ResponsiveContainer,
  PieChart,
  Pie,
  Cell,
  Tooltip,
  Legend,
  BarChart,
  Bar,
  XAxis,
  YAxis,
  CartesianGrid
} from 'recharts';

interface DashboardViewProps {
  onNavigateTab: (tab: string, id?: number | string) => void;
  onOpenIssueLicenseModal: () => void;
  onOpenAddCustomerModal: () => void;
}

export const DashboardView: React.FC<DashboardViewProps> = ({
  onNavigateTab,
  onOpenIssueLicenseModal,
  onOpenAddCustomerModal,
}) => {
  const { licenses, verifications, recoveryRequests, customers, simulateVerify } = useApp();
  const [liveSimulating, setLiveSimulating] = useState(false);

  // Calculations
  const activeLicenses = licenses.filter(l => l.status === 'active');
  const totalActivations = licenses.reduce((sum, l) => sum + l.activations.length, 0);
  const totalMaxActivations = licenses.reduce((sum, l) => sum + l.max_activations, 0);
  const pendingRecoveries = recoveryRequests.filter(r => r.status === 'pending');

  const expiringLicenses = licenses.filter(l => {
    if (!l.expires_at || l.status !== 'active') return false;
    const diffDays = (new Date(l.expires_at).getTime() - Date.now()) / (1000 * 3600 * 24);
    return diffDays > 0 && diffDays <= 30;
  });

  // Chart Data: Plans
  const planCounts: Record<string, number> = {
    trial: 0,
    monthly: 0,
    semi_annual: 0,
    annual: 0,
    custom: 0,
    lifetime: 0,
  };
  licenses.forEach(l => {
    if (planCounts[l.plan] !== undefined) {
      planCounts[l.plan]++;
    }
  });

  const planChartData = [
    { name: 'Annual', value: planCounts.annual, color: '#3fa9f5' },
    { name: 'Monthly', value: planCounts.monthly, color: '#38bdf8' },
    { name: 'Lifetime', value: planCounts.lifetime, color: '#3fbb6d' },
    { name: 'Semi-Annual', value: planCounts.semi_annual, color: '#a855f7' },
    { name: 'Trial', value: planCounts.trial, color: '#e0a83f' },
    { name: 'Custom', value: planCounts.custom, color: '#ec4899' },
  ].filter(item => item.value > 0);

  // Verification Outcomes
  const verifOutcomeCounts: Record<string, number> = {
    ok: 0,
    invalid_key: 0,
    expired: 0,
    suspended: 0,
    revoked: 0,
    activation_limit: 0,
  };
  verifications.forEach(v => {
    if (verifOutcomeCounts[v.result] !== undefined) {
      verifOutcomeCounts[v.result]++;
    }
  });

  const verificationChartData = [
    { name: 'Valid (OK)', count: verifOutcomeCounts.ok, fill: '#3fbb6d' },
    { name: 'Expired', count: verifOutcomeCounts.expired, fill: '#e0a83f' },
    { name: 'Suspended', count: verifOutcomeCounts.suspended, fill: '#f97316' },
    { name: 'Invalid Key', count: verifOutcomeCounts.invalid_key, fill: '#f0575d' },
    { name: 'Limit Reached', count: verifOutcomeCounts.activation_limit, fill: '#ec4899' },
  ];

  // Trigger random simulated POS check
  const handleTriggerSimulatedCheck = () => {
    setLiveSimulating(true);
    const randomLic = licenses[Math.floor(Math.random() * licenses.length)];
    const randomHwid = randomLic.activations.length > 0 
      ? randomLic.activations[0].hwid 
      : `HWID-POS-SIM-${Math.random().toString(36).substring(2, 6).toUpperCase()}`;
    
    simulateVerify(randomLic.license_key, randomHwid, '185.192.68.88');

    setTimeout(() => {
      setLiveSimulating(false);
    }, 600);
  };

  return (
    <div className="space-y-6 animate-in fade-in duration-150">
      
      {/* Top Banner: Greeting & Quick Actions */}
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 p-5 rounded-2xl bg-gradient-to-r from-[#162130] via-[#141b26] to-[#121822] border border-[#243042] shadow-sm">
        <div>
          <div className="flex items-center gap-2">
            <h1 className="text-xl sm:text-2xl font-extrabold text-white tracking-tight">Hercule Authority Dashboard</h1>
            <span className="inline-flex items-center gap-1 text-[11px] font-semibold px-2 py-0.5 rounded-full bg-sky-500/10 text-sky-400 border border-sky-500/20">
              <Zap className="w-3 h-3" /> Live Server
            </span>
          </div>
          <p className="text-xs sm:text-sm text-slate-400 mt-1 max-w-2xl">
            Real-time device license activation, periodic cryptographic validation, and terminal password recovery.
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2.5">
          <button
            id="dash-issue-license-btn"
            onClick={onOpenIssueLicenseModal}
            className="flex items-center gap-2 px-3.5 py-2.5 rounded-xl bg-sky-500 hover:bg-sky-400 text-slate-950 font-bold text-xs sm:text-sm shadow-md shadow-sky-500/20 transition-all hover:scale-[1.02] active:scale-[0.98]"
          >
            <Plus className="w-4 h-4 stroke-[2.5]" />
            <span>Issue License</span>
          </button>
          <button
            id="dash-add-customer-btn"
            onClick={onOpenAddCustomerModal}
            className="flex items-center gap-2 px-3.5 py-2.5 rounded-xl bg-[#1c2636] hover:bg-[#223044] text-slate-200 border border-[#2d3c52] font-semibold text-xs sm:text-sm transition-all"
          >
            <Plus className="w-4 h-4 text-slate-400" />
            <span>New Customer</span>
          </button>
        </div>
      </div>

      {/* Critical Alert Bar (if expiring licenses or pending recovery) */}
      {pendingRecoveries.length > 0 && (
        <div className="flex items-center justify-between gap-3 p-4 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-200">
          <div className="flex items-center gap-3">
            <div className="p-2 rounded-lg bg-rose-500/20 text-rose-300">
              <AlertCircle className="w-5 h-5" />
            </div>
            <div>
              <p className="text-xs sm:text-sm font-bold text-rose-100">
                {pendingRecoveries.length} Pending Password Recovery {pendingRecoveries.length === 1 ? 'Request' : 'Requests'}
              </p>
              <p className="text-xs text-rose-300/80">
                Offline store terminals are waiting for admin authorization tokens to unlock supervisor access.
              </p>
            </div>
          </div>
          <button
            id="dash-review-recovery-btn"
            onClick={() => onNavigateTab('recovery')}
            className="px-3 py-1.5 rounded-lg bg-rose-500 hover:bg-rose-400 text-slate-950 font-bold text-xs shrink-0 transition-colors"
          >
            Review Requests
          </button>
        </div>
      )}

      {/* 4 Stat Cards */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-3.5 sm:gap-4">
        
        {/* Card 1: Active Licenses */}
        <div 
          onClick={() => onNavigateTab('licenses')}
          className="p-4 sm:p-5 rounded-2xl bg-[#131a24] border border-[#243042] hover:border-sky-500/40 transition-all cursor-pointer group"
        >
          <div className="flex items-center justify-between text-slate-400">
            <span className="text-xs font-semibold uppercase tracking-wider">Active Licenses</span>
            <div className="p-2 rounded-xl bg-sky-500/10 text-sky-400 group-hover:scale-110 transition-transform">
              <KeyRound className="w-4 h-4" />
            </div>
          </div>
          <div className="mt-3 flex items-baseline gap-2">
            <span className="text-2xl sm:text-3xl font-extrabold text-white">{activeLicenses.length}</span>
            <span className="text-xs text-slate-400">/ {licenses.length} total</span>
          </div>
          <div className="mt-2.5 flex items-center justify-between text-[11px] text-slate-400">
            <span>{licenses.filter(l => l.plan === 'lifetime').length} Lifetime contracts</span>
            <ArrowUpRight className="w-3.5 h-3.5 text-sky-400 opacity-0 group-hover:opacity-100 transition-opacity" />
          </div>
        </div>

        {/* Card 2: Bound Devices */}
        <div 
          onClick={() => onNavigateTab('licenses')}
          className="p-4 sm:p-5 rounded-2xl bg-[#131a24] border border-[#243042] hover:border-emerald-500/40 transition-all cursor-pointer group"
        >
          <div className="flex items-center justify-between text-slate-400">
            <span className="text-xs font-semibold uppercase tracking-wider">Bound Devices</span>
            <div className="p-2 rounded-xl bg-emerald-500/10 text-emerald-400 group-hover:scale-110 transition-transform">
              <Laptop className="w-4 h-4" />
            </div>
          </div>
          <div className="mt-3 flex items-baseline gap-2">
            <span className="text-2xl sm:text-3xl font-extrabold text-emerald-400">{totalActivations}</span>
            <span className="text-xs text-slate-400">/ {totalMaxActivations} slots</span>
          </div>
          <div className="mt-2.5 flex items-center justify-between text-[11px] text-slate-400">
            <span>{Math.round((totalActivations / (totalMaxActivations || 1)) * 100)}% capacity utilized</span>
            <ArrowUpRight className="w-3.5 h-3.5 text-emerald-400 opacity-0 group-hover:opacity-100 transition-opacity" />
          </div>
        </div>

        {/* Card 3: Expiring Soon */}
        <div 
          onClick={() => onNavigateTab('licenses')}
          className="p-4 sm:p-5 rounded-2xl bg-[#131a24] border border-[#243042] hover:border-amber-500/40 transition-all cursor-pointer group"
        >
          <div className="flex items-center justify-between text-slate-400">
            <span className="text-xs font-semibold uppercase tracking-wider">Expiring in 30d</span>
            <div className="p-2 rounded-xl bg-amber-500/10 text-amber-400 group-hover:scale-110 transition-transform">
              <Clock className="w-4 h-4" />
            </div>
          </div>
          <div className="mt-3 flex items-baseline gap-2">
            <span className={`text-2xl sm:text-3xl font-extrabold ${expiringLicenses.length > 0 ? 'text-amber-400' : 'text-white'}`}>
              {expiringLicenses.length}
            </span>
            <span className="text-xs text-slate-400">subscriptions</span>
          </div>
          <div className="mt-2.5 flex items-center justify-between text-[11px] text-slate-400">
            <span>Actionable renewals</span>
            <ArrowUpRight className="w-3.5 h-3.5 text-amber-400 opacity-0 group-hover:opacity-100 transition-opacity" />
          </div>
        </div>

        {/* Card 4: Registered Customers */}
        <div 
          onClick={() => onNavigateTab('customers')}
          className="p-4 sm:p-5 rounded-2xl bg-[#131a24] border border-[#243042] hover:border-indigo-500/40 transition-all cursor-pointer group"
        >
          <div className="flex items-center justify-between text-slate-400">
            <span className="text-xs font-semibold uppercase tracking-wider">Customers</span>
            <div className="p-2 rounded-xl bg-indigo-500/10 text-indigo-400 group-hover:scale-110 transition-transform">
              <ShieldCheck className="w-4 h-4" />
            </div>
          </div>
          <div className="mt-3 flex items-baseline gap-2">
            <span className="text-2xl sm:text-3xl font-extrabold text-white">{customers.length}</span>
            <span className="text-xs text-slate-400">merchant chains</span>
          </div>
          <div className="mt-2.5 flex items-center justify-between text-[11px] text-slate-400">
            <span>POS terminals active</span>
            <ArrowUpRight className="w-3.5 h-3.5 text-indigo-400 opacity-0 group-hover:opacity-100 transition-opacity" />
          </div>
        </div>

      </div>

      {/* Analytics & Distribution Grid */}
      <div className="grid grid-cols-1 lg:grid-cols-12 gap-5">
        
        {/* Plan Breakdown Pie Chart (5 Cols) */}
        <div className="lg:col-span-5 p-5 rounded-2xl bg-[#131a24] border border-[#243042] flex flex-col justify-between">
          <div>
            <div className="flex items-center justify-between mb-4">
              <div>
                <h3 className="text-sm font-bold text-white">License Plan Distribution</h3>
                <p className="text-xs text-slate-400">Active contracts breakdown by plan tier</p>
              </div>
            </div>

            <div className="h-56 w-full relative">
              <ResponsiveContainer width="100%" height="100%">
                <PieChart>
                  <Pie
                    data={planChartData}
                    cx="50%"
                    cy="50%"
                    innerRadius={55}
                    outerRadius={80}
                    paddingAngle={4}
                    dataKey="value"
                  >
                    {planChartData.map((entry, index) => (
                      <Cell key={`cell-${index}`} fill={entry.color} stroke="#131a24" strokeWidth={2} />
                    ))}
                  </Pie>
                  <Tooltip 
                    contentStyle={{ backgroundColor: '#1a2332', borderColor: '#2d3c52', borderRadius: '10px', fontSize: '12px' }}
                    itemStyle={{ color: '#fff' }}
                  />
                </PieChart>
              </ResponsiveContainer>
              <div className="absolute inset-0 flex flex-col items-center justify-center pointer-events-none">
                <span className="text-2xl font-black text-white">{licenses.length}</span>
                <span className="text-[10px] text-slate-400 uppercase tracking-widest font-semibold">Licenses</span>
              </div>
            </div>
          </div>

          <div className="grid grid-cols-3 gap-2 pt-3 border-t border-[#243042]/70 text-xs">
            {planChartData.map((p) => (
              <div key={p.name} className="flex items-center gap-1.5">
                <span className="w-2.5 h-2.5 rounded-full shrink-0" style={{ backgroundColor: p.color }} />
                <span className="text-slate-300 truncate">{p.name}: <strong className="text-white">{p.value}</strong></span>
              </div>
            ))}
          </div>
        </div>

        {/* Verification Traffic Bar Chart (7 Cols) */}
        <div className="lg:col-span-7 p-5 rounded-2xl bg-[#131a24] border border-[#243042] flex flex-col justify-between">
          <div>
            <div className="flex items-center justify-between mb-2">
              <div>
                <h3 className="text-sm font-bold text-white">Validation Log Results</h3>
                <p className="text-xs text-slate-400">Periodic client ping & authorization check statistics</p>
              </div>
              <button
                id="simulate-verify-btn"
                onClick={handleTriggerSimulatedCheck}
                disabled={liveSimulating}
                className="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg bg-[#1a2434] hover:bg-[#233147] border border-[#2a3a50] text-sky-400 font-semibold text-xs transition-colors"
                title="Simulate a real-time POS check"
              >
                <RefreshCw className={`w-3.5 h-3.5 ${liveSimulating ? 'animate-spin' : ''}`} />
                <span>Simulate POS Ping</span>
              </button>
            </div>

            <div className="h-56 w-full pt-2">
              <ResponsiveContainer width="100%" height="100%">
                <BarChart data={verificationChartData} margin={{ top: 10, right: 10, left: -20, bottom: 0 }}>
                  <CartesianGrid strokeDasharray="3 3" stroke="#243042" vertical={false} />
                  <XAxis dataKey="name" stroke="#8b98a8" fontSize={11} tickLine={false} />
                  <YAxis stroke="#8b98a8" fontSize={11} allowDecimals={false} tickLine={false} />
                  <Tooltip 
                    cursor={{ fill: 'rgba(255,255,255,0.03)' }}
                    contentStyle={{ backgroundColor: '#1a2332', borderColor: '#2d3c52', borderRadius: '10px', fontSize: '12px' }}
                    itemStyle={{ color: '#fff' }}
                  />
                  <Bar dataKey="count" radius={[6, 6, 0, 0]} />
                </BarChart>
              </ResponsiveContainer>
            </div>
          </div>

          <div className="flex items-center justify-between text-xs text-slate-400 pt-3 border-t border-[#243042]/70">
            <span>Latest 24 Hours: <strong className="text-emerald-400 font-bold">{verifOutcomeCounts.ok} authorized</strong> pings</span>
            <button
              onClick={() => onNavigateTab('api-tester')}
              className="text-sky-400 hover:text-sky-300 font-medium inline-flex items-center gap-1"
            >
              Open API Playground &rarr;
            </button>
          </div>
        </div>

      </div>

      {/* Real-time Verification Stream Table */}
      <div className="p-5 rounded-2xl bg-[#131a24] border border-[#243042]">
        <div className="flex items-center justify-between mb-4">
          <div className="flex items-center gap-2.5">
            <div className="w-2.5 h-2.5 rounded-full bg-emerald-400 animate-ping" />
            <h3 className="text-sm font-bold text-white">Live Verification Stream</h3>
            <span className="text-xs text-slate-400 hidden sm:inline">&bull; Real-time cryptographic validations</span>
          </div>
          <button
            onClick={() => onNavigateTab('licenses')}
            className="text-xs text-sky-400 hover:text-sky-300 font-semibold"
          >
            View all licenses &rarr;
          </button>
        </div>

        <div className="overflow-x-auto">
          <table className="w-full text-left text-xs">
            <thead>
              <tr className="border-b border-[#243042] text-slate-400 font-semibold uppercase tracking-wider text-[11px]">
                <th className="pb-3 px-2">Timestamp</th>
                <th className="pb-3 px-2">License Key</th>
                <th className="pb-3 px-2">Device / HWID</th>
                <th className="pb-3 px-2">Client IP</th>
                <th className="pb-3 px-2 text-right">Result</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-[#1e293b]">
              {verifications.slice(0, 5).map((v) => {
                const isOk = v.result === 'ok';
                return (
                  <tr key={v.id} className="hover:bg-[#161f2c] transition-colors group">
                    <td className="py-3 px-2 text-slate-400 whitespace-nowrap font-mono text-[11px]">
                      {v.created_at}
                    </td>
                    <td className="py-3 px-2 font-mono-key font-medium text-slate-200">
                      {v.license_key}
                    </td>
                    <td className="py-3 px-2 text-slate-300 max-w-[200px] truncate">
                      <span className="font-semibold text-slate-200">{v.device_name || 'POS Terminal'}</span>
                      {v.hwid && <span className="block text-[10px] text-slate-400 font-mono truncate">{v.hwid}</span>}
                    </td>
                    <td className="py-3 px-2 font-mono text-[11px] text-slate-400 whitespace-nowrap">
                      {v.ip_address}
                    </td>
                    <td className="py-3 px-2 text-right whitespace-nowrap">
                      {isOk ? (
                        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-emerald-500/15 text-emerald-400 border border-emerald-500/30">
                          <CheckCircle2 className="w-3 h-3" /> OK
                        </span>
                      ) : (
                        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-rose-500/15 text-rose-400 border border-rose-500/30">
                          <XCircle className="w-3 h-3" /> {v.result}
                        </span>
                      )}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </div>

    </div>
  );
};
