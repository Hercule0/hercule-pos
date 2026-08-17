import React, { useState } from 'react';
import { useApp } from '../context/AppContext';
import { PasswordRecoveryRequest } from '../types';
import {
  Terminal,
  Play,
  Copy,
  Check,
  Code2,
  ShieldCheck,
  Cpu,
  RefreshCw,
  FileJson,
  KeyRound,
  CheckCircle2,
  XCircle,
  Clock
} from 'lucide-react';

export const ApiPlaygroundView: React.FC = () => {
  const { licenses, simulateVerify, recoveryRequests } = useApp();

  const [selectedEndpoint, setSelectedEndpoint] = useState<string>('activate');
  const [licenseKey, setLicenseKey] = useState<string>(licenses[0]?.license_key || '');
  const [hwid, setHwid] = useState<string>('HWID-WIN11-8F3A-C021-99E4');
  const [deviceName, setDeviceName] = useState<string>('POS Terminal 01 (Main)');
  const [username, setUsername] = useState<string>('store_manager');
  const [recoveryToken, setRecoveryToken] = useState<string>('');

  const [responseOutput, setResponseOutput] = useState<string | null>(null);
  const [statusCode, setStatusCode] = useState<number | null>(null);
  const [copiedCurl, setCopiedCurl] = useState(false);
  const [loading, setLoading] = useState(false);

  // Generate curl command
  const getCurlCommand = () => {
    let payload = {};
    let url = `/public/api/${selectedEndpoint}.php`;

    if (selectedEndpoint === 'activate') {
      payload = { license_key: licenseKey, hwid, device_name: deviceName };
    } else if (selectedEndpoint === 'validate') {
      payload = { license_key: licenseKey, hwid };
    } else if (selectedEndpoint === 'check_update') {
      payload = { license_key: licenseKey };
    } else if (selectedEndpoint === 'recovery_request') {
      payload = { license_key: licenseKey, hwid, username };
    } else if (selectedEndpoint === 'recovery_claim') {
      payload = { license_key: licenseKey, hwid };
    }

    return `curl -X POST "https://license.herculepos.iq${url}" \\
  -H "Content-Type: application/json" \\
  -H "Accept: application/json" \\
  -d '${JSON.stringify(payload, null, 2)}'`;
  };

  const handleCopyCurl = () => {
    navigator.clipboard.writeText(getCurlCommand());
    setCopiedCurl(true);
    setTimeout(() => setCopiedCurl(false), 2000);
  };

  const handleExecuteRequest = () => {
    setLoading(true);

    setTimeout(() => {
      if (selectedEndpoint === 'validate' || selectedEndpoint === 'activate') {
        const res = simulateVerify(licenseKey, hwid, '185.192.68.12');
        if (res.success) {
          setStatusCode(200);
          setResponseOutput(
            JSON.stringify(
              {
                ok: true,
                status: 'active',
                license_key: licenseKey,
                plan: res.plan,
                hwid_bound: hwid,
                expires_at: res.expiresAt,
                signature: res.signature,
                algorithm: 'RSA-SHA256',
                timestamp: new Date().toISOString(),
              },
              null,
              2
            )
          );
        } else {
          setStatusCode(403);
          setResponseOutput(
            JSON.stringify(
              {
                ok: false,
                error: res.result,
                message: res.message,
                timestamp: new Date().toISOString(),
              },
              null,
              2
            )
          );
        }
      } else if (selectedEndpoint === 'check_update') {
        setStatusCode(200);
        setResponseOutput(
          JSON.stringify(
            {
              has_update: false,
              forced_validation_required: false,
              server_time: new Date().toISOString(),
            },
            null,
            2
          )
        );
      } else if (selectedEndpoint === 'recovery_request') {
        setStatusCode(200);
        setResponseOutput(
          JSON.stringify(
            {
              ok: true,
              request_id: Date.now(),
              status: 'pending',
              message: 'Password recovery request registered. Awaiting owner authorization.',
            },
            null,
            2
          )
        );
      } else if (selectedEndpoint === 'recovery_claim') {
        const match = recoveryRequests.find(r => r.license_key === licenseKey && r.status === 'approved');
        if (match && match.token_raw) {
          setStatusCode(200);
          setResponseOutput(
            JSON.stringify(
              {
                ok: true,
                status: 'approved',
                authorization_token: match.token_raw,
                expires_at: match.token_expires_at,
              },
              null,
              2
            )
          );
        } else {
          setStatusCode(404);
          setResponseOutput(
            JSON.stringify(
              {
                ok: false,
                status: 'not_found_or_consumed',
                message: 'No pending approved token available for this HWID.',
              },
              null,
              2
            )
          );
        }
      }

      setLoading(false);
    }, 250);
  };

  return (
    <div className="space-y-5 animate-in fade-in duration-150">
      
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-xl sm:text-2xl font-extrabold text-white tracking-tight">API & Simulator Sandbox</h1>
          <p className="text-xs sm:text-sm text-slate-400 mt-0.5">
            Interactive client testbed for POS desktop terminals & RSA-2048 cryptographic validation.
          </p>
        </div>

        <div className="flex items-center gap-2 text-xs font-mono text-sky-400 bg-[#131a24] px-3 py-2 rounded-xl border border-[#243042]">
          <Code2 className="w-4 h-4" />
          <span>JSON Body &le; 16 KB &bull; Strict SSL</span>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-12 gap-5">
        
        {/* Left: Endpoint Selector & Request Builder (6 Cols) */}
        <div className="lg:col-span-6 p-5 rounded-2xl bg-[#131a24] border border-[#243042] space-y-4">
          <div>
            <h3 className="text-sm font-bold text-white mb-2">Select Endpoint</h3>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
              {[
                { id: 'activate', name: 'POST /api/activate', desc: 'First-time HWID lock' },
                { id: 'validate', name: 'POST /api/validate', desc: 'Periodic signed validation' },
                { id: 'check_update', name: 'POST /api/check_update', desc: 'Lightweight ping' },
                { id: 'recovery_request', name: 'POST /api/recovery_request', desc: 'Request terminal PIN reset' },
                { id: 'recovery_claim', name: 'POST /api/recovery_claim', desc: 'Claim single-use token' },
              ].map((ep) => (
                <button
                  key={ep.id}
                  onClick={() => setSelectedEndpoint(ep.id)}
                  className={`p-3 rounded-xl border text-left transition-all ${
                    selectedEndpoint === ep.id
                      ? 'bg-sky-500/15 border-sky-500 text-sky-300 font-bold'
                      : 'bg-[#182230] border-[#243042] text-slate-400 hover:bg-[#1f2b3d]'
                  }`}
                >
                  <p className="text-xs font-bold text-white font-mono">{ep.name}</p>
                  <p className="text-[10px] text-slate-400 mt-0.5">{ep.desc}</p>
                </button>
              ))}
            </div>
          </div>

          {/* Form Fields */}
          <div className="space-y-3 pt-2 border-t border-[#243042]">
            <div>
              <div className="flex items-center justify-between mb-1">
                <label className="text-xs font-semibold text-slate-300">License Key</label>
                <button
                  onClick={() => setLicenseKey(licenses[0]?.license_key || '')}
                  className="text-[10px] text-sky-400 hover:text-sky-300"
                >
                  Use sample key
                </button>
              </div>
              <input
                type="text"
                value={licenseKey}
                onChange={(e) => setLicenseKey(e.target.value)}
                className="w-full px-3.5 py-2 rounded-xl bg-[#182230] border border-[#2d3c52] font-mono text-xs sm:text-sm text-sky-300"
              />
            </div>

            <div>
              <label className="block text-xs font-semibold text-slate-300 mb-1">Hardware ID (HWID)</label>
              <input
                type="text"
                value={hwid}
                onChange={(e) => setHwid(e.target.value)}
                className="w-full px-3.5 py-2 rounded-xl bg-[#182230] border border-[#2d3c52] font-mono text-xs sm:text-sm text-slate-200"
              />
            </div>

            {selectedEndpoint === 'activate' && (
              <div>
                <label className="block text-xs font-semibold text-slate-300 mb-1">Device Nickname</label>
                <input
                  type="text"
                  value={deviceName}
                  onChange={(e) => setDeviceName(e.target.value)}
                  className="w-full px-3.5 py-2 rounded-xl bg-[#182230] border border-[#2d3c52] text-xs text-white"
                />
              </div>
            )}

            {selectedEndpoint === 'recovery_request' && (
              <div>
                <label className="block text-xs font-semibold text-slate-300 mb-1">Target Account Username</label>
                <input
                  type="text"
                  value={username}
                  onChange={(e) => setUsername(e.target.value)}
                  className="w-full px-3.5 py-2 rounded-xl bg-[#182230] border border-[#2d3c52] text-xs text-white"
                />
              </div>
            )}

            <button
              onClick={handleExecuteRequest}
              disabled={loading}
              className="w-full py-2.5 rounded-xl bg-sky-500 hover:bg-sky-400 text-slate-950 font-bold text-xs sm:text-sm shadow-md shadow-sky-500/20 flex items-center justify-center gap-2 transition-all mt-3"
            >
              <Play className={`w-4 h-4 fill-current ${loading ? 'animate-spin' : ''}`} />
              <span>Send Request to Server</span>
            </button>
          </div>
        </div>

        {/* Right: cURL & Output Viewer (6 Cols) */}
        <div className="lg:col-span-6 space-y-4">
          
          {/* cURL Snippet Box */}
          <div className="p-4 rounded-2xl bg-[#131a24] border border-[#243042] space-y-2">
            <div className="flex items-center justify-between">
              <span className="text-xs font-bold text-slate-300 uppercase tracking-wider">cURL Command</span>
              <button
                onClick={handleCopyCurl}
                className="flex items-center gap-1 text-xs text-sky-400 hover:text-sky-300 font-semibold"
              >
                {copiedCurl ? <Check className="w-3.5 h-3.5 text-emerald-400" /> : <Copy className="w-3.5 h-3.5" />}
                <span>{copiedCurl ? 'Copied!' : 'Copy cURL'}</span>
              </button>
            </div>
            <pre className="p-3 rounded-xl bg-[#0e141e] border border-[#243042] text-[11px] font-mono text-slate-300 overflow-x-auto whitespace-pre">
              {getCurlCommand()}
            </pre>
          </div>

          {/* Response Payload Box */}
          <div className="p-4 rounded-2xl bg-[#131a24] border border-[#243042] space-y-2">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-2">
                <span className="text-xs font-bold text-slate-300 uppercase tracking-wider">Server Response</span>
                {statusCode && (
                  <span className={`text-[10px] font-bold font-mono px-2 py-0.5 rounded ${
                    statusCode === 200 ? 'bg-emerald-500/20 text-emerald-300' : 'bg-rose-500/20 text-rose-300'
                  }`}>
                    HTTP {statusCode}
                  </span>
                )}
              </div>
            </div>

            {responseOutput ? (
              <pre className="p-3 rounded-xl bg-[#0e141e] border border-[#243042] text-[11px] font-mono text-emerald-400 overflow-x-auto whitespace-pre max-h-64">
                {responseOutput}
              </pre>
            ) : (
              <div className="p-8 text-center text-slate-400 text-xs italic bg-[#0e141e] rounded-xl border border-[#243042]">
                Click &ldquo;Send Request to Server&rdquo; to test live verification.
              </div>
            )}
          </div>

        </div>

      </div>

    </div>
  );
};
