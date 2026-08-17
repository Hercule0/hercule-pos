import React, { useState } from 'react';
import { useApp } from '../context/AppContext';
import { Customer } from '../types';
import {
  Users,
  Search,
  Plus,
  Mail,
  Phone,
  KeyRound,
  FileEdit,
  Trash2,
  Calendar,
  Building,
  CheckCircle2,
  XCircle,
  ExternalLink
} from 'lucide-react';

interface CustomersViewProps {
  onIssueLicenseForCustomer: (customerId: number) => void;
  onNavigateToLicensesWithFilter: (customerId: number) => void;
}

export const CustomersView: React.FC<CustomersViewProps> = ({
  onIssueLicenseForCustomer,
  onNavigateToLicensesWithFilter,
}) => {
  const { customers, licenses, addCustomer, editCustomer, deleteCustomer } = useApp();
  const [searchTerm, setSearchTerm] = useState('');
  
  // Modal states
  const [isAddModalOpen, setIsAddModalOpen] = useState(false);
  const [editingCustomer, setEditingCustomer] = useState<Customer | null>(null);
  const [deleteConfirmCust, setDeleteConfirmCust] = useState<Customer | null>(null);

  // Form states
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [phone, setPhone] = useState('');
  const [notes, setNotes] = useState('');

  const filteredCustomers = customers.filter(c => 
    c.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
    (c.email && c.email.toLowerCase().includes(searchTerm.toLowerCase())) ||
    (c.phone && c.phone.includes(searchTerm)) ||
    (c.notes && c.notes.toLowerCase().includes(searchTerm.toLowerCase()))
  );

  const handleOpenAdd = () => {
    setName('');
    setEmail('');
    setPhone('');
    setNotes('');
    setIsAddModalOpen(true);
  };

  const handleOpenEdit = (c: Customer, e?: React.MouseEvent) => {
    if (e) e.stopPropagation();
    setEditingCustomer(c);
    setName(c.name);
    setEmail(c.email || '');
    setPhone(c.phone || '');
    setNotes(c.notes || '');
  };

  const handleSaveAdd = (e: React.FormEvent) => {
    e.preventDefault();
    if (!name.trim()) return;
    addCustomer({ name: name.trim(), email: email.trim(), phone: phone.trim(), notes: notes.trim() });
    setIsAddModalOpen(false);
  };

  const handleSaveEdit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!editingCustomer || !name.trim()) return;
    editCustomer(editingCustomer.id, {
      name: name.trim(),
      email: email.trim(),
      phone: phone.trim(),
      notes: notes.trim(),
    });
    setEditingCustomer(null);
  };

  return (
    <div className="space-y-5 animate-in fade-in duration-150">
      
      {/* Top Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-xl sm:text-2xl font-extrabold text-white tracking-tight">Customer Directory</h1>
          <p className="text-xs sm:text-sm text-slate-400 mt-0.5">
            Registered POS merchant chains, supermarkets, retail outlets, and cafe accounts.
          </p>
        </div>

        <button
          id="add-new-customer-btn"
          onClick={handleOpenAdd}
          className="flex items-center gap-2 px-4 py-2.5 rounded-xl bg-sky-500 hover:bg-sky-400 text-slate-950 font-bold text-xs sm:text-sm shadow-md shadow-sky-500/20 transition-all self-start sm:self-auto hover:scale-[1.02] active:scale-[0.98]"
        >
          <Plus className="w-4 h-4 stroke-[2.5]" />
          <span>Add Customer</span>
        </button>
      </div>

      {/* Search Input */}
      <div className="p-4 rounded-2xl bg-[#131a24] border border-[#243042]">
        <div className="relative">
          <Search className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
          <input
            type="text"
            placeholder="Search customers by company name, email, phone number, or notes..."
            value={searchTerm}
            onChange={(e) => setSearchTerm(e.target.value)}
            className="w-full pl-10 pr-4 py-2.5 rounded-xl bg-[#182230] border border-[#2d3c52] text-xs sm:text-sm text-white placeholder-slate-400 focus:outline-none focus:border-sky-500 transition-colors"
          />
        </div>
      </div>

      {/* Customers Grid */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        {filteredCustomers.map((cust) => {
          const custLicenses = licenses.filter(l => l.customer_id === cust.id);
          const activeCount = custLicenses.filter(l => l.status === 'active').length;
          const totalDevices = custLicenses.reduce((acc, l) => acc + l.activations.length, 0);

          return (
            <div
              key={cust.id}
              className="p-5 rounded-2xl bg-[#131a24] border border-[#243042] hover:border-sky-500/40 transition-all flex flex-col justify-between space-y-4 group shadow-sm"
            >
              <div className="space-y-3">
                <div className="flex items-start justify-between gap-2">
                  <div className="flex items-center gap-2.5">
                    <div className="w-9 h-9 rounded-xl bg-gradient-to-br from-indigo-500 to-sky-600 flex items-center justify-center text-white font-bold text-sm shadow-md">
                      {cust.name.charAt(0).toUpperCase()}
                    </div>
                    <div>
                      <h3 className="text-sm font-bold text-white group-hover:text-sky-300 transition-colors line-clamp-1">
                        {cust.name}
                      </h3>
                      <p className="text-[11px] text-slate-400">ID #{cust.id} &bull; Added {cust.created_at.substring(0, 10)}</p>
                    </div>
                  </div>

                  <div className="flex items-center gap-1">
                    <button
                      onClick={(e) => handleOpenEdit(cust, e)}
                      className="p-1.5 rounded-lg text-slate-400 hover:text-white hover:bg-[#1f2b3d] transition-colors"
                      title="Edit Customer"
                    >
                      <FileEdit className="w-4 h-4" />
                    </button>
                    <button
                      onClick={(e) => {
                        e.stopPropagation();
                        setDeleteConfirmCust(cust);
                      }}
                      className="p-1.5 rounded-lg text-slate-400 hover:text-rose-400 hover:bg-rose-500/10 transition-colors"
                      title="Delete Customer"
                    >
                      <Trash2 className="w-4 h-4" />
                    </button>
                  </div>
                </div>

                {/* Contact information */}
                <div className="space-y-1.5 text-xs text-slate-300 pt-1">
                  {cust.email && (
                    <div className="flex items-center gap-2 text-slate-400">
                      <Mail className="w-3.5 h-3.5 text-slate-400 shrink-0" />
                      <span className="truncate">{cust.email}</span>
                    </div>
                  )}
                  {cust.phone && (
                    <div className="flex items-center gap-2 text-slate-400">
                      <Phone className="w-3.5 h-3.5 text-slate-400 shrink-0" />
                      <span>{cust.phone}</span>
                    </div>
                  )}
                  {cust.notes && (
                    <p className="text-[11px] text-slate-400 italic bg-[#182230] p-2 rounded-lg border border-[#243042]/50 line-clamp-2">
                      &ldquo;{cust.notes}&rdquo;
                    </p>
                  )}
                </div>
              </div>

              {/* License stats footer & actions */}
              <div className="pt-3 border-t border-[#243042]/80 flex items-center justify-between gap-2 text-xs">
                <div className="space-y-0.5">
                  <p className="text-[11px] text-slate-400">
                    <strong className="text-white font-bold">{activeCount}</strong> active {activeCount === 1 ? 'license' : 'licenses'}
                  </p>
                  <p className="text-[10px] text-emerald-400 font-medium">
                    {totalDevices} bound POS {totalDevices === 1 ? 'device' : 'devices'}
                  </p>
                </div>

                <div className="flex items-center gap-1.5">
                  <button
                    onClick={() => onIssueLicenseForCustomer(cust.id)}
                    className="px-2.5 py-1.5 rounded-lg bg-sky-500/15 hover:bg-sky-500/25 text-sky-400 border border-sky-500/30 font-semibold text-xs transition-colors flex items-center gap-1"
                    title="Issue new key for this merchant"
                  >
                    <Plus className="w-3.5 h-3.5" />
                    <span>Issue Key</span>
                  </button>
                </div>
              </div>
            </div>
          );
        })}
      </div>

      {/* MODAL: Add / Edit Customer */}
      {(isAddModalOpen || editingCustomer) && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
          <div className="w-full max-w-md rounded-2xl bg-[#131a24] border border-[#2a384c] shadow-2xl p-6 space-y-4 animate-in fade-in zoom-in-95 duration-150">
            <div className="flex items-center justify-between border-b border-[#243042] pb-3">
              <h3 className="text-base font-bold text-white">
                {editingCustomer ? 'Edit Customer Details' : 'Add New Customer'}
              </h3>
              <button
                onClick={() => {
                  setIsAddModalOpen(false);
                  setEditingCustomer(null);
                }}
                className="p-1 rounded-lg text-slate-400 hover:text-white"
              >
                <XCircle className="w-5 h-5" />
              </button>
            </div>

            <form onSubmit={editingCustomer ? handleSaveEdit : handleSaveAdd} className="space-y-3.5">
              <div>
                <label className="block text-xs font-semibold text-slate-300 mb-1">
                  Customer / Business Name *
                </label>
                <input
                  type="text"
                  required
                  placeholder="e.g. Babylon Supermarket LLC"
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  className="w-full px-3.5 py-2 rounded-xl bg-[#182230] border border-[#2d3c52] text-xs sm:text-sm text-white"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-300 mb-1">
                  Email Address
                </label>
                <input
                  type="email"
                  placeholder="e.g. admin@merchant.iq"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  className="w-full px-3.5 py-2 rounded-xl bg-[#182230] border border-[#2d3c52] text-xs sm:text-sm text-white"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-300 mb-1">
                  Phone Number
                </label>
                <input
                  type="tel"
                  placeholder="e.g. +964 770 123 4567"
                  value={phone}
                  onChange={(e) => setPhone(e.target.value)}
                  className="w-full px-3.5 py-2 rounded-xl bg-[#182230] border border-[#2d3c52] text-xs sm:text-sm text-white"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-300 mb-1">
                  Internal Notes
                </label>
                <textarea
                  rows={3}
                  placeholder="Branch location, contract specifics, hardware model details..."
                  value={notes}
                  onChange={(e) => setNotes(e.target.value)}
                  className="w-full px-3.5 py-2 rounded-xl bg-[#182230] border border-[#2d3c52] text-xs text-white"
                />
              </div>

              <div className="flex items-center justify-end gap-3 pt-3 border-t border-[#243042]">
                <button
                  type="button"
                  onClick={() => {
                    setIsAddModalOpen(false);
                    setEditingCustomer(null);
                  }}
                  className="px-4 py-2 rounded-xl bg-[#182230] text-slate-300 text-xs font-semibold"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  className="px-5 py-2 rounded-xl bg-sky-500 hover:bg-sky-400 text-slate-950 font-bold text-xs sm:text-sm shadow-md"
                >
                  {editingCustomer ? 'Save Changes' : 'Create Customer'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* CONFIRM DELETE CUSTOMER */}
      {deleteConfirmCust && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
          <div className="w-full max-w-sm rounded-2xl bg-[#131a24] border border-[#2a384c] p-5 space-y-4 animate-in fade-in zoom-in-95 duration-150">
            <h4 className="text-sm font-bold text-white">Delete Customer Record?</h4>
            <p className="text-xs text-slate-300">
              Are you sure you want to permanently delete <strong>{deleteConfirmCust.name}</strong> and all associated licenses?
            </p>
            <div className="flex items-center justify-end gap-2.5 pt-2">
              <button
                onClick={() => setDeleteConfirmCust(null)}
                className="px-3.5 py-2 rounded-xl bg-[#182230] text-slate-300 text-xs font-semibold"
              >
                Cancel
              </button>
              <button
                onClick={() => {
                  deleteCustomer(deleteConfirmCust.id);
                  setDeleteConfirmCust(null);
                }}
                className="px-4 py-2 rounded-xl bg-rose-500 hover:bg-rose-400 text-slate-950 font-bold text-xs shadow-md"
              >
                Delete
              </button>
            </div>
          </div>
        </div>
      )}

    </div>
  );
};
