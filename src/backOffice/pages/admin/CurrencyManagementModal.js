// src/backOffice/pages/admin/CurrencyManagementModal.jsx

import React, { useEffect, useState } from "react";
import FullScreenModal from "../../../components/FullScreenModal";
import { requestEnterpriseConfirmation } from "../../../components/EnterpriseConfirmHost";
import ReusableMessageModal from "../../../components/ReusableMessageModal";
import EnterpriseFormModal from "../../../components/EnterpriseFormModal";

import {
  getCurrencies,
  getCurrency,
  createCurrency,
  updateCurrency,
  deleteCurrency,
  addCurrencyRate,
} from "../../../services/CurrencyService";

const emptyForm = {
  id: null,
  code: "",
  name: "",
  country: "",
  rate: "",
  is_active: true,
  is_base_currency: false,
};

const CurrencyManagementModal = ({ open, onClose }) => {
  const [currencies, setCurrencies] = useState([]);
  const [selectedCurrency, setSelectedCurrency] = useState(null);
  const [formData, setFormData] = useState(emptyForm);
  const [formOpen, setFormOpen] = useState(false);

  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [baseFilter, setBaseFilter] = useState("");
  const [perPage, setPerPage] = useState(50);
  const [page, setPage] = useState(1);
  const [pagination, setPagination] = useState(null);

  const [rateModalOpen, setRateModalOpen] = useState(false);
  const [newRate, setNewRate] = useState("");

  const [loading, setLoading] = useState(false);
  const [loadingDetail, setLoadingDetail] = useState(false);
  const [saving, setSaving] = useState(false);
  const [savingRate, setSavingRate] = useState(false);
  const [deletingId, setDeletingId] = useState(null);

  const [messageModal, setMessageModal] = useState({
    open: false,
    type: "success",
    title: "",
    message: "",
  });

  const isEditing = !!formData.id;

  const getApiMessage = (source, fallback = "Request failed.") => {
    const responseData = source?.response?.data;

    if (responseData?.data && typeof responseData.data === "object") {
      const values = Object.values(responseData.data).flat();
      if (values.length > 0) return values.join("\n");
    }

    if (responseData?.errors && typeof responseData.errors === "object") {
      return Object.values(responseData.errors).flat().join("\n");
    }

    if (responseData?.error) return responseData.error;
    if (responseData?.message) return responseData.message;

    if (source?.data?.error) return source.data.error;
    if (source?.data?.message) return source.data.message;

    if (source?.message && !source?.isAxiosError) return source.message;

    return fallback;
  };

  const showMessage = ({ type = "success", title = "", message = "" }) => {
    setMessageModal({
      open: true,
      type,
      title,
      message,
    });
  };

  const closeMessageModal = () => {
    setMessageModal((prev) => ({
      ...prev,
      open: false,
    }));
  };

  const normalizeCurrencies = (response) => {
    const payload = response?.data;

    if (Array.isArray(payload)) {
      return {
        rows: payload,
        pagination: null,
      };
    }

    if (Array.isArray(payload?.data)) {
      return {
        rows: payload.data,
        pagination: {
          current_page: payload.current_page,
          last_page: payload.last_page,
          per_page: payload.per_page,
          total: payload.total,
          from: payload.from,
          to: payload.to,
        },
      };
    }

    return {
      rows: [],
      pagination: null,
    };
  };

  const normalizeDetail = (response) => {
    return response?.data || response?.currency || response || null;
  };

  const safeText = (value, fallback = "-") => {
    if (value === null || value === undefined || value === "") return fallback;

    if (typeof value === "object") {
      if (value?.rate !== undefined && value?.rate !== null) {
        return Number(value.rate).toFixed(6);
      }

      if (value?.name) return String(value.name);
      if (value?.code) return String(value.code);

      return fallback;
    }

    return String(value);
  };

  const getLatestRateValue = (currency) => {
    if (!currency) return null;

    const candidates = [
      currency.latestRate?.rate,
      currency.latest_rate?.rate,
      currency.latest_rate,
      currency.latestRate,
      currency.latestRateValue,
      currency.latest_rate_value,
      currency.rate,
    ];

    for (const candidate of candidates) {
      if (candidate === null || candidate === undefined || candidate === "") {
        continue;
      }

      if (typeof candidate === "object") {
        if (
          candidate?.rate !== null &&
          candidate?.rate !== undefined &&
          candidate?.rate !== ""
        ) {
          return candidate.rate;
        }

        continue;
      }

      return candidate;
    }

    return null;
  };

  const getLatestRate = (currency) => {
    const rate = getLatestRateValue(currency);

    if (rate === null || rate === undefined || rate === "") return "-";

    const numberValue = Number(rate);

    if (Number.isNaN(numberValue)) return String(rate);

    return numberValue.toFixed(6);
  };

  const getBranchName = (currency) => {
    return (
      currency?.branch?.branch_name ||
      currency?.branch?.name ||
      "Company / Current Branch"
    );
  };

  const loadCurrencies = async (nextPage = page) => {
    try {
      setLoading(true);

      const params = {
        page: nextPage,
        per_page: perPage,
      };

      if (search.trim()) params.q = search.trim();
      if (statusFilter !== "") params.is_active = statusFilter;
      if (baseFilter !== "") params.is_base_currency = baseFilter;

      const response = await getCurrencies(params);
      const normalized = normalizeCurrencies(response);

      setCurrencies(normalized.rows);
      setPagination(normalized.pagination);
      setPage(nextPage);
    } catch (error) {
      showMessage({
        type: "error",
        title: "Load Failed",
        message: getApiMessage(error),
      });
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (!open) return;

    setSearch("");
    setStatusFilter("");
    setBaseFilter("");
    setPerPage(50);
    setPage(1);
    setSelectedCurrency(null);
    setFormData(emptyForm);
    loadCurrencies(1);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  useEffect(() => {
    if (!open) return;

    const timer = setTimeout(() => {
      loadCurrencies(1);
    }, 350);

    return () => clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search, statusFilter, baseFilter, perPage]);

  const handleChange = (e) => {
    const { name, value, type, checked } = e.target;

    setFormData((prev) => ({
      ...prev,
      [name]: type === "checkbox" ? checked : value,
    }));
  };

  const clearForm = () => {
    setFormData(emptyForm);
    setSelectedCurrency(null);
  };

  const handleSelectCurrency = async (currency) => {
    if (!currency?.id) return;

    try {
      setLoadingDetail(true);

      const response = await getCurrency(currency.id);
      const detail = normalizeDetail(response);

      setSelectedCurrency(detail);

      setFormData({
        id: detail?.id || null,
        code: detail?.code || "",
        name: detail?.name || "",
        country: detail?.country || "",
        rate: "",
        is_active: detail?.is_active ?? true,
        is_base_currency: detail?.is_base_currency ?? false,
      });
      setFormOpen(true);
    } catch (error) {
      showMessage({
        type: "error",
        title: "Currency Detail Failed",
        message: getApiMessage(error),
      });
    } finally {
      setLoadingDetail(false);
    }
  };

  const validateForm = () => {
    if (!formData.code.trim()) {
      showMessage({
        type: "error",
        title: "Validation Error",
        message: "Currency code is required.",
      });
      return false;
    }

    if (formData.code.trim().length > 3) {
      showMessage({
        type: "error",
        title: "Validation Error",
        message: "Currency code must not exceed 3 characters.",
      });
      return false;
    }

    if (!formData.name.trim()) {
      showMessage({
        type: "error",
        title: "Validation Error",
        message: "Currency name is required.",
      });
      return false;
    }

    if (!isEditing && (!formData.rate || Number(formData.rate) <= 0)) {
      showMessage({
        type: "error",
        title: "Validation Error",
        message: "Rate is required and must be greater than 0.",
      });
      return false;
    }

    if (isEditing && formData.rate && Number(formData.rate) <= 0) {
      showMessage({
        type: "error",
        title: "Validation Error",
        message: "Rate must be greater than 0.",
      });
      return false;
    }

    return true;
  };

  const buildPayload = () => {
    const payload = {
      code: formData.code.trim().toUpperCase(),
      name: formData.name.trim(),
      country: formData.country.trim() || null,
      is_active: !!formData.is_active,
      is_base_currency: !!formData.is_base_currency,
    };

    if (!isEditing || formData.rate) {
      payload.rate = Number(formData.rate);
    }

    return payload;
  };

  const handleSubmit = async (e) => {
    e.preventDefault();

    if (!validateForm()) return;

    try {
      setSaving(true);

      const payload = buildPayload();

      const response = isEditing
        ? await updateCurrency(formData.id, payload)
        : await createCurrency(payload);

      showMessage({
        type: "success",
        title: isEditing ? "Currency Updated" : "Currency Created",
        message: response?.message || "Currency saved successfully.",
      });

      clearForm();
      setFormOpen(false);
      await loadCurrencies(page);
    } catch (error) {
      showMessage({
        type: "error",
        title: isEditing ? "Update Failed" : "Create Failed",
        message: getApiMessage(error),
      });
    } finally {
      setSaving(false);
    }
  };

  const handleDelete = async (currency) => {
    if (!currency?.id) return;

    if (currency.is_base_currency) {
      showMessage({
        type: "error",
        title: "Delete Blocked",
        message: "Base currency cannot be deleted.",
      });
      return;
    }

    const ok = await requestEnterpriseConfirmation({
      title: "Delete Currency",
      message: `Delete currency ${currency.code}?`,
      confirmLabel: "Delete currency",
    });
    if (!ok) return;

    try {
      setDeletingId(currency.id);

      const response = await deleteCurrency(currency.id);

      showMessage({
        type: "success",
        title: "Currency Deleted",
        message: response?.message || "Currency deleted successfully.",
      });

      if (formData.id === currency.id) clearForm();

      await loadCurrencies(page);
    } catch (error) {
      showMessage({
        type: "error",
        title: "Delete Failed",
        message: getApiMessage(error),
      });
    } finally {
      setDeletingId(null);
    }
  };

  const openRateModal = (currency) => {
    setSelectedCurrency(currency);
    setNewRate("");
    setRateModalOpen(true);
  };

  const closeRateModal = () => {
    setRateModalOpen(false);
    setNewRate("");
  };

  const handleAddRate = async () => {
    if (!selectedCurrency?.id) {
      showMessage({
        type: "error",
        title: "Validation Error",
        message: "Select a currency first.",
      });
      return;
    }

    if (!newRate || Number(newRate) <= 0) {
      showMessage({
        type: "error",
        title: "Validation Error",
        message: "Rate must be greater than 0.",
      });
      return;
    }

    try {
      setSavingRate(true);

      const response = await addCurrencyRate(selectedCurrency.id, {
        rate: Number(newRate),
      });

      showMessage({
        type: "success",
        title: "Rate Added",
        message: response?.message || "Currency rate added successfully.",
      });

      closeRateModal();
      await loadCurrencies(page);

      if (formData.id === selectedCurrency.id) {
        await handleSelectCurrency(selectedCurrency);
      }
    } catch (error) {
      showMessage({
        type: "error",
        title: "Rate Failed",
        message: getApiMessage(error),
      });
    } finally {
      setSavingRate(false);
    }
  };

  const formatDate = (value) => {
    if (!value) return "-";

    try {
      return new Date(value).toLocaleString("en-GB", {
        year: "numeric",
        month: "short",
        day: "2-digit",
        hour: "2-digit",
        minute: "2-digit",
      });
    } catch {
      return value;
    }
  };

  return (
    <>
      <FullScreenModal
        open={open}
        onClose={onClose}
        title="CURRENCY MANAGEMENT"
        subtitle="SERVENEST HOSPITALITY ENTERPRISE"
      >
        <div className="h-[calc(100vh-86px)] min-h-0 bg-[#DFE5E5] overflow-hidden">
          <div className="h-full">
            <EnterpriseFormModal open={formOpen} title={isEditing ? "Edit Currency" : "Add Currency"} onClose={() => { if (!saving) { clearForm(); setFormOpen(false); } }} busy={saving} width="620px">
            <section className="bg-white border border-[#ACB8B8] min-h-0 flex flex-col overflow-hidden">
              <div className="h-9 shrink-0 bg-[#004142] border-b border-[#00BCBC] px-3 flex items-center justify-between">
                <h2 className="text-[11px] font-black uppercase tracking-wide text-white">
                  {isEditing ? "Edit Currency" : "Create Currency"}
                </h2>

                <span className="text-[9px] font-black text-[#00BCBC]">
                  {isEditing ? "EDITING" : "NEW"}
                </span>
              </div>

              <form
                onSubmit={handleSubmit}
                className="p-2 overflow-y-auto space-y-2"
              >
                <Field label="Currency Code">
                  <input
                    type="text"
                    name="code"
                    value={formData.code}
                    onChange={handleChange}
                    maxLength={3}
                    className="input-small uppercase"
                    placeholder="USD"
                  />
                </Field>

                <Field label="Currency Name">
                  <input
                    type="text"
                    name="name"
                    value={formData.name}
                    onChange={handleChange}
                    className="input-small"
                    placeholder="US Dollar"
                  />
                </Field>

                <Field label="Country">
                  <input
                    type="text"
                    name="country"
                    value={formData.country}
                    onChange={handleChange}
                    className="input-small"
                    placeholder="United States"
                  />
                </Field>

                <Field label={isEditing ? "New Rate Optional" : "Rate"}>
                  <input
                    type="number"
                    step="0.000001"
                    name="rate"
                    value={formData.rate}
                    onChange={handleChange}
                    className="input-small"
                    placeholder="1.000000"
                  />
                </Field>

                <div className="grid grid-cols-1 gap-2 pt-1">
                  <label className="h-[1.85rem] border border-[#ACB8B8] bg-[#F8FAFC] px-2 flex items-center gap-2 text-[9px] font-black uppercase">
                    <input
                      type="checkbox"
                      name="is_active"
                      checked={!!formData.is_active}
                      onChange={handleChange}
                    />
                    Active
                  </label>

                  <label className="h-[1.85rem] border border-[#ACB8B8] bg-[#F8FAFC] px-2 flex items-center gap-2 text-[9px] font-black uppercase">
                    <input
                      type="checkbox"
                      name="is_base_currency"
                      checked={!!formData.is_base_currency}
                      onChange={handleChange}
                    />
                    Base Currency
                  </label>
                </div>

                <div className="grid grid-cols-2 gap-2 pt-2">
                  <button
                    type="submit"
                    disabled={saving}
                    className="h-8 bg-[#069495] text-white text-[10px] font-black uppercase disabled:opacity-60"
                  >
                    {saving ? "Saving..." : isEditing ? "Update" : "Save"}
                  </button>

                  <button
                    type="button"
                    onClick={clearForm}
                    className="h-8 bg-[#E4E8E8] border border-[#ACB8B8] text-[#003636] text-[10px] font-black uppercase"
                  >
                    Clear
                  </button>
                </div>

                {selectedCurrency && (
                  <div className="border border-[#ACB8B8] bg-[#EAF4F3] p-2 mt-3">
                    <div className="text-[9px] font-black uppercase text-[#005A5A] mb-2">
                      Selected Currency
                    </div>

                    <TextInfo label="Code" value={selectedCurrency.code} />
                    <TextInfo label="Name" value={selectedCurrency.name} />
                    <TextInfo
                      label="Latest Rate"
                      value={getLatestRate(selectedCurrency)}
                    />
                    <TextInfo
                      label="Branch"
                      value={getBranchName(selectedCurrency)}
                    />
                  </div>
                )}
              </form>
            </section>
            </EnterpriseFormModal>

            <section className="bg-white border border-[#ACB8B8] min-h-0 flex flex-col overflow-hidden">
              <div className="h-9 shrink-0 bg-[#004142] border-b border-[#00BCBC] px-3 flex items-center justify-between">
                <h2 className="text-[11px] font-black uppercase tracking-wide text-white">
                  Currency Register
                </h2>

                <div className="flex items-center gap-2">
                  <button type="button" onClick={() => { clearForm(); setFormOpen(true); }} className="h-7 bg-[#069494] px-3 text-[9px] font-bold text-white">Add Currency</button>
                  <button
                    type="button"
                    onClick={() => loadCurrencies(1)}
                    disabled={loading}
                    className="h-7 px-3 bg-[#069495] text-white text-[9px] font-black uppercase disabled:opacity-60"
                  >
                    {loading ? "Loading" : "Refresh"}
                  </button>

                  <input
                    type="text"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    placeholder="Search code, name, country..."
                    className="h-7 w-[250px] border border-white/20 bg-[#003637] px-2 text-[10px] text-white outline-none placeholder:text-white/50"
                  />
                </div>
              </div>

              <div className="shrink-0 border-b border-[#D7DFDE] bg-[#F8FAFC] p-2 grid grid-cols-1 md:grid-cols-3 gap-2">
                <select
                  value={statusFilter}
                  onChange={(e) => setStatusFilter(e.target.value)}
                  className="input-small"
                >
                  <option value="">All Status</option>
                  <option value="true">Active</option>
                  <option value="false">Inactive</option>
                </select>

                <select
                  value={baseFilter}
                  onChange={(e) => setBaseFilter(e.target.value)}
                  className="input-small"
                >
                  <option value="">All Currency Types</option>
                  <option value="true">Base Currency</option>
                  <option value="false">Non Base Currency</option>
                </select>

                <select
                  value={perPage}
                  onChange={(e) => setPerPage(Number(e.target.value))}
                  className="input-small"
                >
                  <option value={25}>25 Rows</option>
                  <option value={50}>50 Rows</option>
                  <option value={100}>100 Rows</option>
                  <option value={200}>200 Rows</option>
                </select>
              </div>

              <div className="flex-1 min-h-0 overflow-auto">
                <table className="w-full border-collapse min-w-[1100px]">
                  <thead className="sticky top-0 z-10">
                    <tr className="bg-[#F3F6F8] border-b border-[#D7DFDE]">
                      {[
                        "Code",
                        "Name",
                        "Country",
                        "Latest Rate",
                        "Base",
                        "Status",
                        "Branch",
                        "Created",
                        "Actions",
                      ].map((header) => (
                        <th
                          key={header}
                          className="text-left px-3 py-2 text-[10px] font-black uppercase"
                        >
                          {header}
                        </th>
                      ))}
                    </tr>
                  </thead>

                  <tbody>
                    {loading ? (
                      <tr>
                        <td
                          colSpan={9}
                          className="px-3 py-8 text-center text-[11px] text-[#637877]"
                        >
                          Loading currencies...
                        </td>
                      </tr>
                    ) : currencies.length === 0 ? (
                      <tr>
                        <td
                          colSpan={9}
                          className="px-3 py-8 text-center text-[11px] text-[#637877]"
                        >
                          No currencies found.
                        </td>
                      </tr>
                    ) : (
                      currencies.map((currency) => (
                        <tr
                          key={currency.id}
                          className={`border-b border-[#EFF2F2] hover:bg-[#F8FAFC] ${
                            formData.id === currency.id ? "bg-[#E8F7F5]" : ""
                          }`}
                        >
                          <td className="px-3 py-2 text-[10px] font-black">
                            {safeText(currency.code)}
                          </td>

                          <td className="px-3 py-2 text-[10px] font-bold">
                            {safeText(currency.name)}
                          </td>

                          <td className="px-3 py-2 text-[10px]">
                            {safeText(currency.country)}
                          </td>

                          <td className="px-3 py-2 text-[10px] font-black text-[#005A5A]">
                            {getLatestRate(currency)}
                          </td>

                          <td className="px-3 py-2 text-[10px]">
                            <span
                              className={`px-2 py-1 text-[9px] font-black uppercase ${
                                currency.is_base_currency
                                  ? "bg-[#E8F7F5] text-[#005A5A]"
                                  : "bg-[#E4E8E8] text-[#324444]"
                              }`}
                            >
                              {currency.is_base_currency ? "Base" : "No"}
                            </span>
                          </td>

                          <td className="px-3 py-2 text-[10px]">
                            <span
                              className={`px-2 py-1 text-[9px] font-black uppercase ${
                                currency.is_active
                                  ? "bg-[#DCFCE7] text-[#166534]"
                                  : "bg-red-100 text-red-700"
                              }`}
                            >
                              {currency.is_active ? "Active" : "Inactive"}
                            </span>
                          </td>

                          <td className="px-3 py-2 text-[10px]">
                            {safeText(getBranchName(currency))}
                          </td>

                          <td className="px-3 py-2 text-[10px]">
                            {formatDate(currency.created_at)}
                          </td>

                          <td className="px-3 py-2">
                            <div className="flex gap-1">
                              <button
                                type="button"
                                onClick={() => handleSelectCurrency(currency)}
                                disabled={loadingDetail}
                                className="h-7 px-2 bg-[#004142] text-white text-[9px] font-black uppercase disabled:opacity-60"
                              >
                                Edit
                              </button>

                              <button
                                type="button"
                                onClick={() => openRateModal(currency)}
                                className="h-7 px-2 bg-[#069495] text-white text-[9px] font-black uppercase"
                              >
                                Rate
                              </button>

                              <button
                                type="button"
                                disabled={
                                  deletingId === currency.id ||
                                  currency.is_base_currency
                                }
                                onClick={() => handleDelete(currency)}
                                className="h-7 px-2 bg-red-700 text-white text-[9px] font-black uppercase disabled:opacity-50"
                              >
                                {deletingId === currency.id
                                  ? "Deleting"
                                  : "Delete"}
                              </button>
                            </div>
                          </td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
              </div>

              {pagination && (
                <div className="h-9 shrink-0 border-t border-[#D7DFDE] bg-[#F8FAFC] px-3 flex items-center justify-between">
                  <button
                    type="button"
                    disabled={page <= 1 || loading}
                    onClick={() => loadCurrencies(page - 1)}
                    className="h-7 px-3 bg-[#E4E8E8] border border-[#ACB8B8] text-[9px] font-black uppercase disabled:opacity-50"
                  >
                    Prev
                  </button>

                  <div className="text-[9px] font-black text-[#324444] uppercase">
                    Showing {pagination.from || 0}-{pagination.to || 0} of{" "}
                    {pagination.total || 0} / Page{" "}
                    {pagination.current_page || page} of{" "}
                    {pagination.last_page || 1}
                  </div>

                  <button
                    type="button"
                    disabled={page >= (pagination.last_page || 1) || loading}
                    onClick={() => loadCurrencies(page + 1)}
                    className="h-7 px-3 bg-[#E4E8E8] border border-[#ACB8B8] text-[9px] font-black uppercase disabled:opacity-50"
                  >
                    Next
                  </button>
                </div>
              )}
            </section>
          </div>
        </div>

        <AppInlineStyles />
      </FullScreenModal>

      <EnterpriseFormModal
        open={rateModalOpen}
        title="Add Currency Rate"
        subtitle={`${safeText(selectedCurrency?.code)} - ${safeText(selectedCurrency?.name)}`}
        onClose={closeRateModal}
        busy={savingRate}
      >
            <div className="flex h-full min-h-0 flex-col">
            <div className="min-h-0 flex-1 space-y-3 overflow-y-auto p-1">
              <TextInfo
                label="Current Latest Rate"
                value={getLatestRate(selectedCurrency)}
              />

              <Field label="New Rate">
                <input
                  type="number"
                  step="0.000001"
                  value={newRate}
                  onChange={(e) => setNewRate(e.target.value)}
                  className="input-small"
                  placeholder="1.000000"
                />
              </Field>

            </div>
              <div className="flex justify-end gap-2 border-t border-[#D7DFDE] p-3">
                <button
                  type="button"
                  onClick={closeRateModal}
                  className="h-8 px-4 bg-[#E4E8E8] border border-[#ACB8B8] text-[10px] font-black uppercase"
                >
                  Cancel
                </button>

                <button
                  type="button"
                  disabled={savingRate}
                  onClick={handleAddRate}
                  className="h-8 px-4 bg-[#069495] text-white text-[10px] font-black uppercase disabled:opacity-60"
                >
                  {savingRate ? "Saving..." : "Save Rate"}
                </button>
              </div>
            </div>
      </EnterpriseFormModal>

      <ReusableMessageModal
        open={messageModal.open}
        type={messageModal.type}
        title={messageModal.title}
        message={messageModal.message}
        onClose={closeMessageModal}
      />
    </>
  );
};

const Field = ({ label, children }) => {
  return (
    <div>
      <label className="block text-[9px] font-black uppercase text-[#324444] mb-0.5">
        {label}
      </label>
      {children}
    </div>
  );
};

const TextInfo = ({ label, value }) => {
  const displayValue =
    value === null || value === undefined || value === ""
      ? "-"
      : typeof value === "object"
      ? value?.rate !== undefined
        ? Number(value.rate).toFixed(6)
        : value?.name || value?.code || "-"
      : String(value);

  return (
    <div className="mb-1">
      <div className="text-[8px] font-black uppercase text-[#637877]">
        {label}
      </div>
      <div className="text-[10px] font-bold text-[#003636] break-words">
        {displayValue}
      </div>
    </div>
  );
};

const AppInlineStyles = () => (
  <style>
    {`
      .input-small {
        width: 100%;
        height: 1.85rem;
        border: 1px solid #ACB8B8;
        padding: 0 0.45rem;
        font-size: 10px;
        outline: none;
        background: white;
        border-radius: 0;
      }

      .input-small:focus {
        border-color: #00BCBC;
        box-shadow: inset 0 0 0 1px #00BCBC;
      }

      button,
      input,
      select,
      textarea {
        border-radius: 0 !important;
      }
    `}
  </style>
);

export default CurrencyManagementModal;
