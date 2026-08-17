import React, { useState } from 'react';
import { AppProvider, useApp } from './context/AppContext';
import { Navbar } from './components/layout/Navbar';
import { Sidebar } from './components/layout/Sidebar';
import { MobileNav } from './components/layout/MobileNav';
import { NotificationDrawer } from './components/layout/NotificationDrawer';
import { ToastNotificationContainer } from './components/layout/ToastNotificationContainer';
import { Smartphone, Zap, Bell, CheckCircle2, X } from 'lucide-react';

// Views
import { DashboardView } from './views/DashboardView';
import { LicensesView } from './views/LicensesView';
import { LicenseDetailModal } from './views/LicenseDetailModal';
import { CustomersView } from './views/CustomersView';
import { RecoveryRequestsView } from './views/RecoveryRequestsView';
import { ApiPlaygroundView } from './views/ApiPlaygroundView';
import { AdminUsersView } from './views/AdminUsersView';
import { MfaSettingsView } from './views/MfaSettingsView';
import { HealthView } from './views/HealthView';

const MainLayout: React.FC = () => {
  const { pushPermission, requestPushPermission, testInstantNotification } = useApp();
  const [activeTab, setActiveTab] = useState<string>('dashboard');
  const [mobileMenuOpen, setMobileMenuOpen] = useState<boolean>(false);
  const [notificationDrawerOpen, setNotificationDrawerOpen] = useState<boolean>(false);
  const [mobileBannerDismissed, setMobileBannerDismissed] = useState<boolean>(false);
  
  // Modal states
  const [selectedLicenseDetailId, setSelectedLicenseDetailId] = useState<number | null>(null);
  const [isIssueLicenseModalOpen, setIsIssueLicenseModalOpen] = useState<boolean>(false);
  const [preselectedCustomerId, setPreselectedCustomerId] = useState<number | undefined>(undefined);

  const handleNavigateTab = (tab: string, id?: number | string) => {
    setActiveTab(tab);
    if (tab === 'licenses' && id) {
      setSelectedLicenseDetailId(Number(id));
    }
  };

  const handleIssueForCustomer = (customerId: number) => {
    setPreselectedCustomerId(customerId);
    setActiveTab('licenses');
    setIsIssueLicenseModalOpen(true);
  };

  return (
    <div className="h-screen w-screen bg-[#0b0f15] text-slate-100 flex flex-col font-sans selection:bg-sky-500 selection:text-slate-950 overflow-hidden">
      
      {/* Top Navigation Bar (Fixed top) */}
      <Navbar
        activeTab={activeTab}
        setActiveTab={setActiveTab}
        onOpenNotifications={() => setNotificationDrawerOpen(true)}
        mobileMenuOpen={mobileMenuOpen}
        setMobileMenuOpen={setMobileMenuOpen}
      />

      {/* Main Container: Sidebar (Fixed/Sticky) + Main Scrollable Content */}
      <div className="flex-1 flex overflow-hidden relative">
        
        {/* Sidebar: Fixed and stays pinned during scrolling on desktop, slide-over drawer on mobile */}
        <Sidebar
          activeTab={activeTab}
          setActiveTab={setActiveTab}
          mobileMenuOpen={mobileMenuOpen}
          setMobileMenuOpen={setMobileMenuOpen}
        />

        {/* Dynamic View Area: Independently Scrollable */}
        <main className="flex-1 overflow-y-auto overflow-x-hidden p-3 sm:p-5 lg:p-7 pb-28 lg:pb-8 max-w-7xl mx-auto w-full">
          
          {/* Mobile Fast Push Alert Banner if not enabled yet */}
          {pushPermission !== 'granted' && !mobileBannerDismissed && (
            <div className="mb-4 p-3 sm:p-3.5 rounded-2xl bg-gradient-to-r from-sky-950/80 via-[#131f30] to-blue-950/80 border border-sky-500/40 shadow-lg shadow-sky-950/20 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 animate-in fade-in slide-in-from-top-2 duration-200">
              <div className="flex items-center gap-3">
                <div className="p-2 rounded-xl bg-sky-500/20 text-sky-400 border border-sky-500/30 shrink-0">
                  <Smartphone className="w-5 h-5 animate-pulse" />
                </div>
                <div>
                  <h4 className="text-xs sm:text-sm font-bold text-white flex items-center gap-1.5">
                    <span>Instant Phone Push Notifications</span>
                    <span className="text-[10px] bg-sky-500/30 text-sky-300 font-semibold px-1.5 py-0.2 rounded border border-sky-500/30">Fast</span>
                  </h4>
                  <p className="text-[11px] sm:text-xs text-slate-300 mt-0.5">
                    Receive immediate lock screen & sound push alerts for POS terminal activations and password recovery requests.
                  </p>
                </div>
              </div>

              <div className="flex items-center gap-2 w-full sm:w-auto justify-end">
                <button
                  id="enable-push-banner-btn"
                  onClick={requestPushPermission}
                  className="flex-1 sm:flex-none px-3.5 py-1.5 rounded-xl bg-sky-500 hover:bg-sky-400 text-slate-950 font-bold text-xs shadow-md shadow-sky-500/25 transition-all cursor-pointer flex items-center justify-center gap-1.5"
                >
                  <Bell className="w-3.5 h-3.5" />
                  <span>Allow Push Alerts</span>
                </button>
                <button
                  onClick={() => setMobileBannerDismissed(true)}
                  className="p-1.5 rounded-lg text-slate-400 hover:text-white hover:bg-white/10 transition-colors"
                  aria-label="Dismiss banner"
                >
                  <X className="w-4 h-4" />
                </button>
              </div>
            </div>
          )}

          {activeTab === 'dashboard' && (
            <DashboardView
              onNavigateTab={handleNavigateTab}
              onOpenIssueLicenseModal={() => {
                setPreselectedCustomerId(undefined);
                setIsIssueLicenseModalOpen(true);
              }}
              onOpenAddCustomerModal={() => {
                setActiveTab('customers');
              }}
            />
          )}

          {activeTab === 'licenses' && (
            <LicensesView
              onOpenDetailModal={(id) => setSelectedLicenseDetailId(id)}
              isIssueModalOpen={isIssueLicenseModalOpen}
              setIsIssueModalOpen={setIsIssueLicenseModalOpen}
              preselectedCustomerId={preselectedCustomerId}
            />
          )}

          {activeTab === 'customers' && (
            <CustomersView
              onIssueLicenseForCustomer={handleIssueForCustomer}
              onNavigateToLicensesWithFilter={(customerId) => {
                setActiveTab('licenses');
              }}
            />
          )}

          {activeTab === 'recovery' && <RecoveryRequestsView />}
          {activeTab === 'api-tester' && <ApiPlaygroundView />}
          {activeTab === 'admins' && <AdminUsersView />}
          {activeTab === 'mfa' && <MfaSettingsView />}
          {activeTab === 'health' && <HealthView />}
        </main>
      </div>

      {/* Mobile Navigation Bar (Fixed bottom for phone/touch) */}
      <MobileNav
        activeTab={activeTab}
        setActiveTab={setActiveTab}
        onOpenMoreMenu={() => setMobileMenuOpen(true)}
      />

      {/* Instant Floating Toast Stack */}
      <ToastNotificationContainer
        onNavigateTab={handleNavigateTab}
      />

      {/* Notification Flyout Drawer */}
      <NotificationDrawer
        isOpen={notificationDrawerOpen}
        onClose={() => setNotificationDrawerOpen(false)}
        onNavigateTab={handleNavigateTab}
      />

      {/* License Telemetry & Detail Modal */}
      {selectedLicenseDetailId && (
        <LicenseDetailModal
          licenseId={selectedLicenseDetailId}
          onClose={() => setSelectedLicenseDetailId(null)}
        />
      )}

    </div>
  );
};

export function App() {
  return (
    <AppProvider>
      <MainLayout />
    </AppProvider>
  );
}

export default App;
