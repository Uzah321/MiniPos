// src/backOffice/pages/admin/PaymentTypeManagementModal.jsx

import React, { useEffect, useMemo, useState } from "react";
import FullScreenModal from "../../../components/FullScreenModal";
import { requestEnterpriseConfirmation } from "../../../components/EnterpriseConfirmHost";
import ReusableMessageModal from "../../../components/ReusableMessageModal";
import EnterpriseFormModal from "../../../components/EnterpriseFormModal";

import {
  getPaymentTypes,
  getPaymentType,
  createPaymentType,
  updatePaymentType,
  deletePaymentType,
} from "../../../services/PaymentTypeService";

const emptyForm = {
  id: null,
  name: "",
  money_code: "",
  type: "",
  supportsMultiCurrency: false,
  requiresAmountTendered: false,
  default_currency_code: "",
  integration_status: false,
  integration_number: "",
  is_active: true,
};

const paymentTypeOptions = [
  { value: "", label: "Select Type" },
  { value: "cash", label: "Cash" },
  { value: "card", label: "Card" },
  { value: "mobile_money", label: "Mobile Money" },
  { value: "bank_transfer", label: "Bank Transfer" },
  { value: "voucher", label: "Voucher" },
  { value: "credit", label: "Credit" },
  { value: "other", label: "Other" },
];

const PaymentTypeManagementModal = ({
  open,
  onClose,
  canView = false,
  canCreate = false,
  canUpdate = false,
  canDelete = false,
}) => {
  const [paymentTypes, setPaymentTypes] = useState([]);
  const [selectedPaymentType, setSelectedPaymentType] = useState(null);
  const [formData, setFormData] = useState(emptyForm);
  const [formOpen, setFormOpen] = useState(false);

  const [search, setSearch] = useState("");
  const [typeFilter, setTypeFilter] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [perPage, setPerPage] = useState(50);
  const [page, setPage] = useState(1);
  const [pagination, setPagination] = useState(null);

  const [loading, setLoading] = useState(false);
  const [loadingDetail, setLoadingDetail] = useState(false);
  const [saving, setSaving] = useState(false);
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

  const normalizePaymentTypes = (response) => {
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
    return response?.data || response?.payment_type || response || null;
  };

  const safeText = (value, fallback = "-") => {
    if (value === null || value === undefined || value === "") return fallback;

    if (typeof value === "object") {
      if (value?.name) return String(value.name);
      if (value?.code) return String(value.code);
      if (value?.branch_name) return String(value.branch_name);
      return fallback;
    }

    return String(value);
  };

  const getBranchName = (paymentType) => {
    return (
      paymentType?.branch?.branch_name ||
      paymentType?.branch?.name ||
      "Company / Current Branch"
    );
  };

  const getTypeLabel = (value) => {
    const found = paymentTypeOptions.find((item) => item.value === value);
    return found?.label || safeText(value);
  };

  const loadPaymentTypes = async (nextPage = page) => {
    if (!canView) return;

    try {
      setLoading(true);

      const params = {
        page: nextPage,
        per_page: perPage,
      };

      if (search.trim()) params.q = search.trim();
      if (typeFilter) params.type = typeFilter;
      if (statusFilter !== "") params.is_active = statusFilter;

      const response = await getPaymentTypes(params);
      const normalized = normalizePaymentTypes(response);

      setPaymentTypes(normalized.rows);
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
    if (!open || !canView) return;

    setSearch("");
    setTypeFilter("");
    setStatusFilter("");
    setPerPage(50);
    setPage(1);
    setSelectedPaymentType(null);
    setFormData(emptyForm);

    loadPaymentTypes(1);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, canView]);

  useEffect(() => {
    if (!open || !canView) return;

    const timer = setTimeout(() => {
      loadPaymentTypes(1);
    }, 350);

    return () => clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, canView, search, typeFilter, statusFilter, perPage]);

  const handleChange = (e) => {
    const { name, value, type, checked } = e.target;

    setFormData((prev) => {
      const next = {
        ...prev,
        [name]: type === "checkbox" ? checked : value,
      };

      if (name === "supportsMultiCurrency" && checked === true) {
        next.default_currency_code = "";
      }

      if (name === "integration_status" && checked === false) {
        next.integration_number = "";
      }

      return next;
    });
  };

  const clearForm = () => {
    setFormData(emptyForm);
    setSelectedPaymentType(null);
  };

  const handleSelectPaymentType = async (paymentType) => {
    if (!canUpdate || !paymentType?.id) return;

    try {
      setLoadingDetail(true);

      const response = await getPaymentType(paymentType.id);
      const detail = normalizeDetail(response);

      setSelectedPaymentType(detail);

      setFormData({
        id: detail?.id || null,
        name: detail?.name || "",
        money_code: detail?.money_code || "",
        type: detail?.type || "",
        supportsMultiCurrency: !!detail?.supportsMultiCurrency,
        requiresAmountTendered: !!detail?.requiresAmountTendered,
        default_currency_code: detail?.default_currency_code || "",
        integration_status: !!detail?.integration_status,
        integration_number:
          detail?.integration_number !== null &&
          detail?.integration_number !== undefined
            ? String(detail.integration_number)
            : "",
        is_active: detail?.is_active ?? true,
      });
      setFormOpen(true);
    } catch (error) {
      showMessage({
        type: "error",
        title: "Payment Type Detail Failed",
        message: getApiMessage(error),
      });
    } finally {
      setLoadingDetail(false);
    }
  };

  const validateForm = () => {
    if (!formData.name.trim()) {
      showMessage({
        type: "error",
        title: "Validation Error",
        message: "Payment type name is required.",
      });
      return false;
    }

    if (!formData.money_code.trim()) {
      showMessage({
        type: "error",
        title: "Validation Error",
        message: "Money code is required.",
      });
      return false;
    }

    if (
      !formData.supportsMultiCurrency &&
      !formData.default_currency_code.trim()
    ) {
      showMessage({
        type: "error",
        title: "Validation Error",
        message:
          "Default currency is required when the payment type does not support multi currency.",
      });
      return false;
    }

    if (formData.default_currency_code.trim().length > 0) {
      if (formData.default_currency_code.trim().length !== 3) {
        showMessage({
          type: "error",
          title: "Validation Error",
          message:
            "Default currency code must be exactly 3 characters, for example USD.",
        });
        return false;
      }
    }

    if (
      formData.integration_status &&
      !String(formData.integration_number).trim()
    ) {
      showMessage({
        type: "error",
        title: "Validation Error",
        message: "Integration number is required when integration is active.",
      });
      return false;
    }

    if (
      formData.integration_number !== "" &&
      formData.integration_number !== null &&
      formData.integration_number !== undefined &&
      Number(formData.integration_number) < 0
    ) {
      showMessage({
        type: "error",
        title: "Validation Error",
        message: "Integration number cannot be negative.",
      });
      return false;
    }

    return true;
  };

  const buildPayload = () => ({
    name: formData.name.trim(),
    money_code: formData.money_code.trim().toUpperCase(),
    type: formData.type || null,

    supportsMultiCurrency: !!formData.supportsMultiCurrency,
    requiresAmountTendered: !!formData.requiresAmountTendered,

    default_currency_code: !formData.supportsMultiCurrency
      ? formData.default_currency_code.trim().toUpperCase()
      : formData.default_currency_code.trim()
      ? formData.default_currency_code.trim().toUpperCase()
      : null,

    integration_status: !!formData.integration_status,

    integration_number: formData.integration_status
      ? Number(formData.integration_number)
      : null,

    is_active: !!formData.is_active,
  });

  const handleSubmit = async (e) => {
    e.preventDefault();

    if ((isEditing && !canUpdate) || (!isEditing && !canCreate)) return;

    if (!validateForm()) return;

    try {
      setSaving(true);

      const payload = buildPayload();

      const response = isEditing
        ? await updatePaymentType(formData.id, payload)
        : await createPaymentType(payload);

      showMessage({
        type: "success",
        title: isEditing ? "Payment Type Updated" : "Payment Type Created",
        message: response?.message || "Payment type saved successfully.",
      });

      clearForm();
      setFormOpen(false);
      await loadPaymentTypes(page);
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

  const handleDelete = async (paymentType) => {
    if (!canDelete || !paymentType?.id) return;

    const ok = await requestEnterpriseConfirmation({
      title: "Delete Payment Type",
      message: `Delete payment type ${paymentType.name}?`,
      confirmLabel: "Delete payment type",
    });
    if (!ok) return;

    try {
      setDeletingId(paymentType.id);

      const response = await deletePaymentType(paymentType.id);

      showMessage({
        type: "success",
        title: "Payment Type Deleted",
        message: response?.message || "Payment type deleted successfully.",
      });

      if (formData.id === paymentType.id) clearForm();

      await loadPaymentTypes(page);
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

  const activeCount = useMemo(() => {
    return paymentTypes.filter((item) => item.is_active).length;
  }, [paymentTypes]);

  const inactiveCount = useMemo(() => {
    return paymentTypes.filter((item) => !item.is_active).length;
  }, [paymentTypes]);

  return (
    <>
      <FullScreenModal
        open={open}
        onClose={onClose}
        title="PAYMENT TYPE MANAGEMENT"
        subtitle="SERVENEST HOSPITALITY ENTERPRISE"
      >
        <div className="h-[calc(100vh-86px)] min-h-0 bg-[#DFE5E5] overflow-hidden">
          <div className="h-full">
            <EnterpriseFormModal open={formOpen} title={isEditing ? "Edit Payment Type" : "Add Payment Type"} onClose={() => { if (!saving) { clearForm(); setFormOpen(false); } }} busy={saving} width="680px">
            <section className="bg-white border border-[#ACB8B8] min-h-0 flex flex-col overflow-hidden">
              <div className="h-9 shrink-0 bg-[#004142] border-b border-[#00BCBC] px-3 flex items-center justify-between">
                <h2 className="text-[11px] font-black uppercase tracking-wide text-white">
                  {isEditing ? "Edit Payment Type" : "Create Payment Type"}
                </h2>

                <span className="text-[9px] font-black text-[#00BCBC]">
                  {isEditing ? "EDITING" : "NEW"}
                </span>
              </div>

              <form
                onSubmit={handleSubmit}
                className="p-2 overflow-y-auto space-y-2"
              >
                <Field label="Payment Name">
                  <input
                    type="text"
                    name="name"
                    value={formData.name}
                    onChange={handleChange}
                    className="input-small"
                    placeholder="Cash"
                  />
                </Field>

                <Field label="Money Code">
                  <input
                    type="text"
                    name="money_code"
                    value={formData.money_code}
                    onChange={handleChange}
                    className="input-small uppercase"
                    placeholder="CASH"
                  />
                </Field>

                <Field label="Payment Type">
                  <select
                    name="type"
                    value={formData.type}
                    onChange={handleChange}
                    className="input-small"
                  >
                    {paymentTypeOptions.map((option) => (
                      <option key={option.value || "empty"} value={option.value}>
                        {option.label}
                      </option>
                    ))}
                  </select>
                </Field>

                <Field
                  label={
                    formData.supportsMultiCurrency
                      ? "Default Currency Code Optional"
                      : "Default Currency Code Required"
                  }
                >
                  <input
                    type="text"
                    name="default_currency_code"
                    value={formData.default_currency_code}
                    onChange={handleChange}
                    className={`input-small uppercase ${
                      !formData.supportsMultiCurrency &&
                      !formData.default_currency_code
                        ? "required-input"
                        : ""
                    }`}
                    placeholder="USD"
                    maxLength={3}
                  />

                  {!formData.supportsMultiCurrency && (
                    <div className="text-[8px] mt-1 font-bold text-red-700 uppercase">
                      Required because this payment does not support multi
                      currency.
                    </div>
                  )}
                </Field>

                <Field
                  label={
                    formData.integration_status
                      ? "Integration Number Required"
                      : "Integration Number Optional"
                  }
                >
                  <input
                    type="number"
                    name="integration_number"
                    value={formData.integration_number}
                    onChange={handleChange}
                    disabled={!formData.integration_status}
                    className={`input-small ${
                      formData.integration_status &&
                      !formData.integration_number
                        ? "required-input"
                        : ""
                    }`}
                    placeholder={
                      formData.integration_status
                        ? "Enter integration number"
                        : "Disabled until integration is active"
                    }
                  />

                  {formData.integration_status && (
                    <div className="text-[8px] mt-1 font-bold text-red-700 uppercase">
                      Required because integration is active.
                    </div>
                  )}
                </Field>

                <div className="grid grid-cols-1 gap-2 pt-1">
                  <CheckRow
                    label="Supports Multi Currency"
                    name="supportsMultiCurrency"
                    checked={!!formData.supportsMultiCurrency}
                    onChange={handleChange}
                  />

                  <CheckRow
                    label="Requires Amount Tendered"
                    name="requiresAmountTendered"
                    checked={!!formData.requiresAmountTendered}
                    onChange={handleChange}
                  />

                  <CheckRow
                    label="Integration Active"
                    name="integration_status"
                    checked={!!formData.integration_status}
                    onChange={handleChange}
                  />

                  <CheckRow
                    label="Active"
                    name="is_active"
                    checked={!!formData.is_active}
                    onChange={handleChange}
                  />
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

                {selectedPaymentType && (
                  <div className="border border-[#ACB8B8] bg-[#EAF4F3] p-2 mt-3">
                    <div className="text-[9px] font-black uppercase text-[#005A5A] mb-2">
                      Selected Payment Type
                    </div>

                    <TextInfo label="Name" value={selectedPaymentType.name} />
                    <TextInfo
                      label="Money Code"
                      value={selectedPaymentType.money_code}
                    />
                    <TextInfo
                      label="Type"
                      value={getTypeLabel(selectedPaymentType.type)}
                    />
                    <TextInfo
                      label="Default Currency"
                      value={selectedPaymentType.default_currency_code}
                    />
                    <TextInfo
                      label="Integration No."
                      value={selectedPaymentType.integration_number}
                    />
                    <TextInfo
                      label="Branch"
                      value={getBranchName(selectedPaymentType)}
                    />
                  </div>
                )}
              </form>
            </section>
            </EnterpriseFormModal>

            <section className="bg-white border border-[#ACB8B8] min-h-0 flex flex-col overflow-hidden">
              <div className="h-9 shrink-0 bg-[#004142] border-b border-[#00BCBC] px-3 flex items-center justify-between">
                <h2 className="text-[11px] font-black uppercase tracking-wide text-white">
                  Payment Type Register
                </h2>

                <div className="flex items-center gap-2">
                  {canCreate && (
                    <button type="button" onClick={() => { clearForm(); setFormOpen(true); }} className="h-7 bg-[#069494] px-3 text-[9px] font-bold text-white">Add Payment Type</button>
                  )}
                  <button
                    type="button"
                    onClick={() => loadPaymentTypes(1)}
                    disabled={loading}
                    className="h-7 px-3 bg-[#069495] text-white text-[9px] font-black uppercase disabled:opacity-60"
                  >
                    {loading ? "Loading" : "Refresh"}
                  </button>

                  <input
                    type="text"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    placeholder="Search name, code, type..."
                    className="h-7 w-[250px] border border-white/20 bg-[#003637] px-2 text-[10px] text-white outline-none placeholder:text-white/50"
                  />
                </div>
              </div>

              <div className="shrink-0 border-b border-[#D7DFDE] bg-[#F8FAFC] p-2 grid grid-cols-1 md:grid-cols-3 gap-2">
                <select
                  value={typeFilter}
                  onChange={(e) => setTypeFilter(e.target.value)}
                  className="input-small"
                >
                  {paymentTypeOptions.map((option) => (
                    <option key={option.value || "all"} value={option.value}>
                      {option.value ? option.label : "All Types"}
                    </option>
                  ))}
                </select>

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

              <div className="shrink-0 border-b border-[#D7DFDE] bg-[#EAF4F3] px-3 py-2">
                <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
                  <InfoText
                    label="Total Displayed"
                    value={paymentTypes.length}
                  />
                  <InfoText label="Active" value={activeCount} />
                  <InfoText label="Inactive" value={inactiveCount} />
                  <InfoText
                    label="Total Records"
                    value={pagination?.total ?? paymentTypes.length}
                  />
                </div>
              </div>

              <div className="flex-1 min-h-0 overflow-auto">
                <table className="w-full border-collapse min-w-[1400px]">
                  <thead className="sticky top-0 z-10">
                    <tr className="bg-[#F3F6F8] border-b border-[#D7DFDE]">
                      {[
                        "Name",
                        "Money Code",
                        "Type",
                        "Default Currency",
                        "Multi Currency",
                        "Amount Tendered",
                        "Integration",
                        "Integration No.",
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
                          colSpan={12}
                          className="px-3 py-8 text-center text-[11px] text-[#637877]"
                        >
                          Loading payment types...
                        </td>
                      </tr>
                    ) : paymentTypes.length === 0 ? (
                      <tr>
                        <td
                          colSpan={12}
                          className="px-3 py-8 text-center text-[11px] text-[#637877]"
                        >
                          No payment types found.
                        </td>
                      </tr>
                    ) : (
                      paymentTypes.map((paymentType) => (
                        <tr
                          key={paymentType.id}
                          className={`border-b border-[#EFF2F2] hover:bg-[#F8FAFC] ${
                            formData.id === paymentType.id ? "bg-[#E8F7F5]" : ""
                          }`}
                        >
                          <td className="px-3 py-2 text-[10px] font-black">
                            {safeText(paymentType.name)}
                          </td>

                          <td className="px-3 py-2 text-[10px] font-bold">
                            {safeText(paymentType.money_code)}
                          </td>

                          <td className="px-3 py-2 text-[10px]">
                            {getTypeLabel(paymentType.type)}
                          </td>

                          <td className="px-3 py-2 text-[10px]">
                            {safeText(paymentType.default_currency_code)}
                          </td>

                          <td className="px-3 py-2 text-[10px]">
                            <BooleanBadge
                              value={paymentType.supportsMultiCurrency}
                            />
                          </td>

                          <td className="px-3 py-2 text-[10px]">
                            <BooleanBadge
                              value={paymentType.requiresAmountTendered}
                            />
                          </td>

                          <td className="px-3 py-2 text-[10px]">
                            <BooleanBadge
                              value={paymentType.integration_status}
                            />
                          </td>

                          <td className="px-3 py-2 text-[10px]">
                            {safeText(paymentType.integration_number)}
                          </td>

                          <td className="px-3 py-2 text-[10px]">
                            <StatusBadge active={paymentType.is_active} />
                          </td>

                          <td className="px-3 py-2 text-[10px]">
                            {safeText(getBranchName(paymentType))}
                          </td>

                          <td className="px-3 py-2 text-[10px]">
                            {formatDate(paymentType.created_at)}
                          </td>

                          <td className="px-3 py-2">
                            <div className="flex gap-1">
                              {canUpdate && (
                                <button
                                  type="button"
                                  onClick={() =>
                                    handleSelectPaymentType(paymentType)
                                  }
                                  disabled={loadingDetail}
                                  className="h-7 px-2 bg-[#004142] text-white text-[9px] font-black uppercase disabled:opacity-60"
                                >
                                  Edit
                                </button>
                              )}

                              {canDelete && (
                                <button
                                  type="button"
                                  disabled={deletingId === paymentType.id}
                                  onClick={() => handleDelete(paymentType)}
                                  className="h-7 px-2 bg-red-700 text-white text-[9px] font-black uppercase disabled:opacity-50"
                                >
                                  {deletingId === paymentType.id
                                    ? "Deleting"
                                    : "Delete"}
                                </button>
                              )}
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
                    onClick={() => loadPaymentTypes(page - 1)}
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
                    onClick={() => loadPaymentTypes(page + 1)}
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

const CheckRow = ({ label, name, checked, onChange }) => {
  return (
    <label className="h-[1.85rem] border border-[#ACB8B8] bg-[#F8FAFC] px-2 flex items-center gap-2 text-[9px] font-black uppercase">
      <input
        type="checkbox"
        name={name}
        checked={checked}
        onChange={onChange}
      />
      {label}
    </label>
  );
};

const TextInfo = ({ label, value }) => {
  return (
    <div className="mb-1">
      <div className="text-[8px] font-black uppercase text-[#637877]">
        {label}
      </div>
      <div className="text-[10px] font-bold text-[#003636] break-words">
        {value ?? "-"}
      </div>
    </div>
  );
};

const InfoText = ({ label, value }) => {
  return (
    <div>
      <div className="text-[8px] font-black uppercase text-[#637877]">
        {label}
      </div>
      <div className="text-[10px] font-black text-[#003636]">{value}</div>
    </div>
  );
};

const BooleanBadge = ({ value }) => {
  return (
    <span
      className={`px-2 py-1 text-[9px] font-black uppercase ${
        value ? "bg-[#E8F7F5] text-[#005A5A]" : "bg-[#E4E8E8] text-[#324444]"
      }`}
    >
      {value ? "Yes" : "No"}
    </span>
  );
};

const StatusBadge = ({ active }) => {
  return (
    <span
      className={`px-2 py-1 text-[9px] font-black uppercase ${
        active ? "bg-[#DCFCE7] text-[#166534]" : "bg-red-100 text-red-700"
      }`}
    >
      {active ? "Active" : "Inactive"}
    </span>
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

      .required-input {
        border-color: #dc2626 !important;
        background: #fff1f2 !important;
        box-shadow: inset 0 0 0 1px #dc2626;
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

export default PaymentTypeManagementModal;
