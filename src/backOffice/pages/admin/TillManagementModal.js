// src/backOffice/pages/admin/TillManagementModal.jsx

import React, { useEffect, useMemo, useRef, useState } from "react";
import FullScreenModal from "../../../components/FullScreenModal";
import EnterpriseFilterBar from "../../../components/EnterpriseFilterBar";
import EnterpriseFormModal from "../../../components/EnterpriseFormModal";
import EnterpriseRowActionMenu from "../../../components/EnterpriseRowActionMenu";
import EnterpriseConfirmModal from "../../../components/EnterpriseConfirmModal";
import ReusableMessageModal from "../../../components/ReusableMessageModal";
import {
  BACKEND_MODES,
  getBackendMode,
} from "../../../config/DeploymentConfig";

import { getAuthorisedCompany } from "../../../services/AuthenticationService";

import {
  getTills,
  getTill,
  createTill,
  updateTill,
  deleteTill,
  createStandaloneTillActivationCode,
  unregisterTill,
  generateTillCode,
} from "../../../services/TillService";

const emptyForm = {
  id: null,
  branch_id: "",
  till_code: "",
  till_name: "",
  device_label: "",
  is_active: true,
};

const TillManagementModal = ({ open, onClose, currentUser = null }) => {
  const backendMode = getBackendMode();
  const standaloneActivationAllowed = backendMode === BACKEND_MODES.STANDALONE;
  const activationActionAllowed = standaloneActivationAllowed;
  const [branches, setBranches] = useState([]);
  const [tills, setTills] = useState([]);
  const [selectedTill, setSelectedTill] = useState(null);

  const [formData, setFormData] = useState(emptyForm);
  const [tillFormOpen, setTillFormOpen] = useState(false);
  const [activationModalOpen, setActivationModalOpen] = useState(false);
  const [standaloneActivation, setStandaloneActivation] = useState(null);
  const [activationCopyState, setActivationCopyState] = useState("");
  const activationRequestRef = useRef(0);

  const [search, setSearch] = useState("");
  const [branchFilter, setBranchFilter] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [registeredFilter, setRegisteredFilter] = useState("");
  const [perPage, setPerPage] = useState(50);
  const [page, setPage] = useState(1);
  const [pagination, setPagination] = useState(null);

  const [loading, setLoading] = useState(false);
  const [loadingBranches, setLoadingBranches] = useState(false);
  const [loadingDetail, setLoadingDetail] = useState(false);
  const [saving, setSaving] = useState(false);
  const [activatingId, setActivatingId] = useState(null);
  const [unregisteringId, setUnregisteringId] = useState(null);
  const [deletingId, setDeletingId] = useState(null);
  const [generatingCode, setGeneratingCode] = useState(false);
  const [confirmAction, setConfirmAction] = useState(null);

  const [activateOptions, setActivateOptions] = useState({
    device_label: "",
  });

  const [messageModal, setMessageModal] = useState({
    open: false,
    type: "success",
    title: "",
    message: "",
  });

  const isEditing = !!formData.id;

  const getApiMessage = (source, fallback = "Request failed.") => {
    const responseData = source?.response?.data;

    if (responseData?.errors && typeof responseData.errors === "object") {
      return Object.values(responseData.errors).flat().join("\n");
    }

    if (responseData?.data && typeof responseData.data === "object") {
      const values = Object.values(responseData.data).flat();
      if (values.length > 0) return values.join("\n");
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

  const normalizeList = (response) => {
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
    return response?.data || response?.till || response || null;
  };

  const safeText = (value, fallback = "-") => {
    if (value === null || value === undefined || value === "") return fallback;

    if (typeof value === "object") {
      if (value?.branch_name) return String(value.branch_name);
      if (value?.name) return String(value.name);
      if (value?.till_name) return String(value.till_name);
      return fallback;
    }

    return String(value);
  };

  const getBranchName = (branch) => {
    return branch?.branch_name || branch?.name || "Unnamed Branch";
  };

  const getTillBranchName = (till) => {
    return (
      till?.branch?.branch_name ||
      till?.branch?.name ||
      branches.find((branch) => branch.id === till?.branch_id)?.branch_name ||
      branches.find((branch) => branch.id === till?.branch_id)?.name ||
      "-"
    );
  };

  const getLoggedInBranchId = () => {
    return (
      currentUser?.branch?.id ||
      currentUser?.branch_id ||
      currentUser?.branchId ||
      ""
    );
  };

  const getDefaultBranchId = () => {
    return getLoggedInBranchId() || branches?.[0]?.id || "";
  };

  const loadBranches = async () => {
    try {
      setLoadingBranches(true);

      const response = await getAuthorisedCompany();

      const rows =
        response?.company?.branches ||
        response?.data?.branches ||
        response?.data?.company?.branches ||
        [];

      setBranches(rows);
    } catch (error) {
      showMessage({
        type: "error",
        title: "Branches Failed",
        message: getApiMessage(error),
      });
    } finally {
      setLoadingBranches(false);
    }
  };

  const loadTills = async (nextPage = page) => {
    try {
      setLoading(true);

      const params = {
        page: nextPage,
        per_page: perPage,
      };

      if (search.trim()) params.q = search.trim();
      if (branchFilter) params.branch_id = branchFilter;
      if (statusFilter !== "") params.is_active = statusFilter;
      if (registeredFilter !== "") params.is_registered = registeredFilter;

      const response = await getTills(params);
      const normalized = normalizeList(response);

      setTills(normalized.rows);
      setPagination(normalized.pagination);
      setPage(nextPage);
    } catch (error) {
      showMessage({
        type: "error",
        title: "Tills Failed",
        message: getApiMessage(error),
      });
    } finally {
      setLoading(false);
    }
  };

  const resetForm = () => {
    const defaultBranchId = getDefaultBranchId();

    setFormData({
      ...emptyForm,
      branch_id: defaultBranchId,
    });

    setSelectedTill(null);

    setActivateOptions({
      device_label: "",
    });
  };

  const closeTillForm = () => {
    setTillFormOpen(false);
    resetForm();
  };

  const openCreateTill = () => {
    resetForm();
    setTillFormOpen(true);
  };

  const openEditTill = async (till) => {
    await handleSelectTill(till, false);
    setTillFormOpen(true);
  };

  const openActivateDialog = async (till) => {
    if (!activationActionAllowed) {
      showMessage({
        type: "info",
        title: "Use Deployment & Sync",
        message: "Select the paired branch POS server and this till in Deployment & Sync, then generate a one-time terminal activation code.",
      });
      return;
    }
    setStandaloneActivation(null);
    setActivationCopyState("");
    await handleSelectTill(till, false);
    setActivationModalOpen(true);
  };

  const closeActivationDialog = () => {
    activationRequestRef.current += 1;
    setActivationModalOpen(false);
    setStandaloneActivation(null);
    setActivationCopyState("");
    setSelectedTill(null);
    setActivateOptions({
      device_label: "",
    });
  };

  useEffect(() => {
    if (!open) return;

    setSearch("");
    setBranchFilter("");
    setStatusFilter("");
    setRegisteredFilter("");
    setPerPage(50);
    setPage(1);
    setPagination(null);

    loadBranches();
    loadTills(1);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  useEffect(() => {
    if (open) return;
    activationRequestRef.current += 1;
    setActivationModalOpen(false);
    setStandaloneActivation(null);
    setActivationCopyState("");
    setActivatingId(null);
  }, [open]);

  useEffect(() => {
    if (!open) return;

    if (!formData.branch_id && branches.length > 0) {
      setFormData((prev) => ({
        ...prev,
        branch_id: getDefaultBranchId(),
      }));
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [branches]);

  useEffect(() => {
    if (!open) return;

    const timer = setTimeout(() => {
      loadTills(1);
    }, 350);

    return () => clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search, branchFilter, statusFilter, registeredFilter, perPage]);

  const activeCount = useMemo(() => {
    return tills.filter((item) => item.is_active).length;
  }, [tills]);

  const registeredCount = useMemo(() => {
    return tills.filter((item) => item.is_registered).length;
  }, [tills]);

  const handleChange = (e) => {
    const { name, value, type, checked } = e.target;

    setFormData((prev) => ({
      ...prev,
      [name]: type === "checkbox" ? checked : value,
    }));
  };

  const handleActivateOptionChange = (e) => {
    const { name, value, type, checked } = e.target;

    setActivateOptions((prev) => ({
      ...prev,
      [name]: type === "checkbox" ? checked : value,
    }));
  };

  const handleGenerateCode = async () => {
    if (!formData.branch_id) {
      showMessage({
        type: "error",
        title: "Validation Error",
        message: "Select branch first.",
      });
      return;
    }

    try {
      setGeneratingCode(true);

      const response = await generateTillCode(formData.branch_id);
      const generatedCode = response?.data?.till_code || response?.till_code || "";

      if (!generatedCode) {
        showMessage({
          type: "error",
          title: "Generate Failed",
          message: "The server did not return till code.",
        });
        return;
      }

      setFormData((prev) => ({
        ...prev,
        till_code: generatedCode,
      }));
    } catch (error) {
      showMessage({
        type: "error",
        title: "Generate Failed",
        message: getApiMessage(error),
      });
    } finally {
      setGeneratingCode(false);
    }
  };

  const handleSelectTill = async (till, setAsSelectedOnly = true) => {
    if (!till?.id) return;

    try {
      setLoadingDetail(true);

      const response = await getTill(till.id);
      const detail = normalizeDetail(response);

      setSelectedTill(detail);

      setFormData({
        id: detail?.id || null,
        branch_id: detail?.branch_id || "",
        till_code: detail?.till_code || "",
        till_name: detail?.till_name || "",
        device_label: detail?.device_label || "",
        is_active: detail?.is_active ?? true,
      });

      setActivateOptions({
        device_label: detail?.device_label || "",
      });

      if (setAsSelectedOnly) {
        setActivationModalOpen(false);
      }
    } catch (error) {
      showMessage({
        type: "error",
        title: "Till Detail Failed",
        message: getApiMessage(error),
      });
    } finally {
      setLoadingDetail(false);
    }
  };

  const validateForm = () => {
    if (!formData.branch_id) {
      showMessage({
        type: "error",
        title: "Validation Error",
        message: "Branch is required.",
      });
      return false;
    }

    if (!formData.till_code.trim()) {
      showMessage({
        type: "error",
        title: "Validation Error",
        message: "Till code is required.",
      });
      return false;
    }

    if (!formData.till_name.trim()) {
      showMessage({
        type: "error",
        title: "Validation Error",
        message: "Till name is required.",
      });
      return false;
    }

    return true;
  };

  const buildPayload = () => ({
    branch_id: formData.branch_id,
    till_code: formData.till_code.trim().toUpperCase(),
    till_name: formData.till_name.trim(),
    device_label: formData.device_label.trim() || null,
    is_active: !!formData.is_active,
  });

  const handleSubmit = async (e) => {
    if (e?.preventDefault) e.preventDefault();

    if (!validateForm()) return;

    try {
      setSaving(true);

      const payload = buildPayload();

      const response = isEditing
        ? await updateTill(formData.id, payload)
        : await createTill(payload);

      showMessage({
        type: "success",
        title: isEditing ? "Till Updated" : "Till Created",
        message: response?.message || "Till saved successfully.",
      });

      setTillFormOpen(false);
      resetForm();
      await loadTills(page);
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

  const handleActivateTill = async (till) => {
    if (!till?.id) return;
    if (!activationActionAllowed) {
      showMessage({
        type: "info",
        title: "Use Deployment & Sync",
        message: "Use Deployment & Sync to issue a terminal activation code for an edge POS server.",
      });
      return;
    }

    const requestId = activationRequestRef.current + 1;
    activationRequestRef.current = requestId;
    try {
      setActivatingId(till.id);
      setStandaloneActivation(null);
      setActivationCopyState("");

      const deviceLabel = String(
        activateOptions.device_label || till.device_label || "",
      ).trim();
      const response = await createStandaloneTillActivationCode(till.id, {
        device_label: deviceLabel || null,
      });
      if (activationRequestRef.current !== requestId) return;
      const data = response?.data || response || {};
      const terminalActivationCode = String(data.terminal_activation_code || "").trim();
      if (!terminalActivationCode) {
        throw new Error("The standalone server did not return a terminal activation code.");
      }

      setStandaloneActivation({
        terminalActivationCode,
        expiresAt: data.expires_at || null,
      });
      await loadTills(page);
    } catch (error) {
      if (activationRequestRef.current !== requestId) return;
      showMessage({
        type: "error",
        title: "Activation Code Failed",
        message: getApiMessage(error),
      });
    } finally {
      if (activationRequestRef.current === requestId) setActivatingId(null);
    }
  };

  const handleCopyStandaloneActivation = async () => {
    const code = standaloneActivation?.terminalActivationCode || "";
    if (!code) return;

    try {
      if (!window.navigator.clipboard?.writeText) {
        throw new Error("Clipboard access is unavailable. Select and copy the code manually.");
      }
      await window.navigator.clipboard.writeText(code);
      setActivationCopyState("copied");
    } catch (error) {
      setActivationCopyState("failed");
      showMessage({
        type: "error",
        title: "Copy Failed",
        message: getApiMessage(error, "Select and copy the activation code manually."),
      });
    }
  };

  const handleUnregisterTill = async (till) => {
    if (!till?.id) return;

    try {
      setUnregisteringId(till.id);

      const response = await unregisterTill(till.id);

      showMessage({
        type: "success",
        title: "Till Unregistered",
        message: response?.message || "Till unregistered successfully.",
      });

      await loadTills(page);
    } catch (error) {
      showMessage({
        type: "error",
        title: "Unregister Failed",
        message: getApiMessage(error),
      });
    } finally {
      setUnregisteringId(null);
      setConfirmAction(null);
    }
  };

  const handleDeleteTill = async (till) => {
    if (!till?.id) return;

    try {
      setDeletingId(till.id);

      const response = await deleteTill(till.id);

      showMessage({
        type: "success",
        title: "Till Deleted",
        message: response?.message || "Till deleted successfully.",
      });

      if (formData.id === till.id) resetForm();

      await loadTills(page);
    } catch (error) {
      showMessage({
        type: "error",
        title: "Delete Failed",
        message: getApiMessage(error),
      });
    } finally {
      setDeletingId(null);
      setConfirmAction(null);
    }
  };

  const formatDateTime = (value) => {
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
        title="TILL MANAGEMENT"
        subtitle=""
      >
        <div className="flex h-full min-h-0 flex-col border border-[#CAD5D5] bg-white">
          <EnterpriseFilterBar
            searchValue={search}
            onSearchChange={setSearch}
            onSearch={() => loadTills(1)}
            searchLabel="Search tills"
            actions={(
              <>
                <button type="button" onClick={() => loadTills(page)} disabled={loading} className="tm-btn-secondary">
                  {loading ? "Loading" : "Refresh"}
                </button>
                <button type="button" onClick={openCreateTill} className="tm-btn-primary">
                  Add Till
                </button>
              </>
            )}
          >
            <FilterField label="Shop">
              <select
                value={branchFilter}
                onChange={(event) => setBranchFilter(event.target.value)}
                className="tm-input"
              >
                <option value="">All shops</option>
                {branches.map((branch) => (
                  <option key={branch.id} value={branch.id}>
                    {getBranchName(branch)}
                  </option>
                ))}
              </select>
            </FilterField>
            <FilterField label="Status">
              <select
                value={statusFilter}
                onChange={(event) => setStatusFilter(event.target.value)}
                className="tm-input"
              >
                <option value="">All statuses</option>
                <option value="true">Active</option>
                <option value="false">Inactive</option>
              </select>
            </FilterField>
            <FilterField label="Registration">
              <select
                value={registeredFilter}
                onChange={(event) => setRegisteredFilter(event.target.value)}
                className="tm-input"
              >
                <option value="">All devices</option>
                <option value="true">Registered</option>
                <option value="false">Not Registered</option>
              </select>
            </FilterField>
            <FilterField label="Rows">
              <select
                value={perPage}
                onChange={(event) => setPerPage(Number(event.target.value))}
                className="tm-input"
              >
                <option value={25}>25</option>
                <option value={50}>50</option>
                <option value={100}>100</option>
              </select>
            </FilterField>
          </EnterpriseFilterBar>

          <TillTable
            rows={tills}
            loading={loading}
            activatingId={activatingId}
            unregisteringId={unregisteringId}
            deletingId={deletingId}
            loadingDetail={loadingDetail}
            activationActionAllowed={activationActionAllowed}
            standaloneActivationAllowed={standaloneActivationAllowed}
            onEdit={openEditTill}
            onActivate={openActivateDialog}
            onUnregister={(till) => setConfirmAction({ type: "unregister", till })}
            onDelete={(till) => setConfirmAction({ type: "delete", till })}
            getTillBranchName={getTillBranchName}
            formatDateTime={formatDateTime}
            safeText={safeText}
          />

          <footer className="flex min-h-11 shrink-0 items-center justify-between gap-3 border-t border-[#CBD6D6] bg-[#F8FAFC] px-3 text-[11px] text-[#637877]">
            <span>{pagination?.total ?? tills.length} tills · {activeCount} active · {registeredCount} registered</span>
            {pagination && (
              <div className="flex items-center gap-2">
                <button type="button" disabled={page <= 1 || loading} onClick={() => loadTills(page - 1)} className="tm-btn-secondary">Previous</button>
                <span>{pagination.from || 0}-{pagination.to || 0}</span>
                <button type="button" disabled={page >= (pagination.last_page || 1) || loading} onClick={() => loadTills(page + 1)} className="tm-btn-secondary">Next</button>
              </div>
            )}
          </footer>
        </div>

        <AppInlineStyles />
      </FullScreenModal>

      {tillFormOpen && (
        <TillFormModal
          isEditing={isEditing}
          formData={formData}
          branches={branches}
          loadingBranches={loadingBranches}
          saving={saving}
          generatingCode={generatingCode}
          handleChange={handleChange}
          handleSubmit={handleSubmit}
          handleGenerateCode={handleGenerateCode}
          onClose={closeTillForm}
          getBranchName={getBranchName}
        />
      )}

      {activationModalOpen && selectedTill && (
        <TillActivationModal
          till={selectedTill}
          standaloneActivation={standaloneActivation}
          activationCopyState={activationCopyState}
          activateOptions={activateOptions}
          activating={activatingId === selectedTill.id}
          handleActivateOptionChange={handleActivateOptionChange}
          onActivate={() => handleActivateTill(selectedTill)}
          onCopyActivation={handleCopyStandaloneActivation}
          onClose={closeActivationDialog}
          getTillBranchName={getTillBranchName}
          formatDateTime={formatDateTime}
        />
      )}

      <EnterpriseConfirmModal
        open={Boolean(confirmAction)}
        title={confirmAction?.type === "unregister" ? "Unregister Till" : "Delete Till"}
        message={confirmAction?.type === "unregister"
          ? `Unregister ${confirmAction?.till?.till_code || "this till"} from its current device?`
          : `Delete till ${confirmAction?.till?.till_code || ""}?`}
        confirmLabel={confirmAction?.type === "unregister" ? "Unregister till" : "Delete till"}
        busy={Boolean(unregisteringId || deletingId)}
        onClose={() => !(unregisteringId || deletingId) && setConfirmAction(null)}
        onConfirm={() => {
          if (!confirmAction?.till) return;
          if (confirmAction.type === "unregister") handleUnregisterTill(confirmAction.till);
          else handleDeleteTill(confirmAction.till);
        }}
      />

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

const MonitorIcon = ({ className = "h-8 w-8" }) => (
  <svg viewBox="0 0 24 24" className={className} fill="none" aria-hidden="true">
    <path
      d="M4 5h16v10H4V5Z"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinejoin="round"
    />
    <path
      d="M9 19h6M12 15v4"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
    />
  </svg>
);

const TillTable = ({
  rows,
  loading,
  activatingId,
  unregisteringId,
  deletingId,
  loadingDetail,
  activationActionAllowed,
  standaloneActivationAllowed,
  onEdit,
  onActivate,
  onUnregister,
  onDelete,
  getTillBranchName,
  formatDateTime,
  safeText,
}) => {
  return (
    <div className="min-h-0 flex-1 overflow-auto bg-white">
      <table className="w-full min-w-[920px]">
        <thead className="sticky top-0 z-10">
          <tr>
            <th>Till</th>
            <th>Code</th>
            <th>Shop</th>
            <th>Device</th>
            <th>Status</th>
            <th>Registered</th>
            <th>Last Seen</th>
            <th className="w-[54px] text-center">More</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((till) => {
            const activating = activatingId === till.id;
            const unregistering = unregisteringId === till.id;
            const deleting = deletingId === till.id;
            return (
              <tr key={till.id}>
                <td className="font-semibold text-[#1C2C2C]">{safeText(till.till_name)}</td>
                <td>{safeText(till.till_code)}</td>
                <td>{getTillBranchName(till)}</td>
                <td>{safeText(till.device_label)}</td>
                <td>{till.is_active ? "Active" : "Inactive"}</td>
                <td>{till.is_registered ? "Yes" : "No"}</td>
                <td>{formatDateTime(till.last_seen_at)}</td>
                <td className="text-center">
                  <EnterpriseRowActionMenu
                    label={`More actions for ${safeText(till.till_name)}`}
                    actions={[
                      { label: "Edit till", disabled: loadingDetail, onClick: () => onEdit(till) },
                      activationActionAllowed ? {
                        label: standaloneActivationAllowed ? "Create activation code" : "Activate till",
                        disabled: activating || !till.is_active,
                        onClick: () => onActivate(till),
                      } : null,
                      {
                        label: unregistering ? "Unregistering…" : "Unregister device",
                        disabled: unregistering || !till.is_registered,
                        onClick: () => onUnregister(till),
                      },
                      {
                        label: deleting ? "Deleting…" : "Delete till",
                        danger: true,
                        disabled: deleting,
                        onClick: () => onDelete(till),
                      },
                    ]}
                  />
                </td>
              </tr>
            );
          })}
          {!loading && rows.length === 0 && (
            <tr><td colSpan={8} className="py-14 text-center text-[#637877]">No tills found.</td></tr>
          )}
          {loading && (
            <tr><td colSpan={8} className="py-14 text-center text-[#637877]">Loading…</td></tr>
          )}
        </tbody>
      </table>
    </div>
  );
};

const TillFormModal = ({
  isEditing,
  formData,
  branches,
  loadingBranches,
  saving,
  generatingCode,
  handleChange,
  handleSubmit,
  handleGenerateCode,
  onClose,
  getBranchName,
}) => (
  <EnterpriseFormModal
    open
    title={isEditing ? "Edit Till" : "Add Till"}
    subtitle=""
    onClose={onClose}
    busy={saving}
    width="1180px"
  >
    <form
      onSubmit={handleSubmit}
      className="flex min-h-full flex-col"
    >
      <div className="min-h-0 flex-1">
        <div className="grid grid-cols-12 gap-3">
          <Field label="Branch" span="6">
            <select
              name="branch_id"
              value={formData.branch_id}
              onChange={handleChange}
              className="tm-input bg-white"
              disabled={loadingBranches}
            >
              <option value="">
                {loadingBranches ? "Loading..." : "Select Branch"}
              </option>

              {branches.map((branch) => (
                <option key={branch.id} value={branch.id}>
                  {getBranchName(branch)}
                </option>
              ))}
            </select>
          </Field>

          <Field label="Till Code" span="6">
            <div className="flex">
              <input
                type="text"
                name="till_code"
                value={formData.till_code}
                onChange={handleChange}
                className="tm-input uppercase"
              />

              <button
                type="button"
                onClick={handleGenerateCode}
                disabled={generatingCode || !formData.branch_id}
                className="h-9 border border-[#004141] bg-[#004142] px-3 text-[10px] font-medium uppercase text-white disabled:opacity-60"
              >
                {generatingCode ? "..." : "Gen"}
              </button>
            </div>
          </Field>

          <Field label="Till Name" span="6">
            <input
              type="text"
              name="till_name"
              value={formData.till_name}
              onChange={handleChange}
              className="tm-input"
            />
          </Field>

          <Field label="Device Label" span="6">
            <input
              type="text"
              name="device_label"
              value={formData.device_label}
              onChange={handleChange}
              className="tm-input"
            />
          </Field>

          <div className="col-span-12 md:col-span-3">
            <CheckBox
              name="is_active"
              checked={formData.is_active}
              onChange={handleChange}
              label="Active"
            />
          </div>
        </div>
      </div>

      <DialogFooter>
        <button type="button" onClick={onClose} className="tm-btn-secondary">
          Cancel
        </button>
        <button type="submit" disabled={saving} className="tm-btn-primary">
          {saving ? "Saving" : isEditing ? "Update" : "Save"}
        </button>
      </DialogFooter>
    </form>
  </EnterpriseFormModal>
);

const TillActivationModal = ({
  till,
  standaloneActivation,
  activationCopyState,
  activateOptions,
  activating,
  handleActivateOptionChange,
  onActivate,
  onCopyActivation,
  onClose,
  getTillBranchName,
  formatDateTime,
}) => (
  <EnterpriseFormModal
    open
    title="Create Front Office Activation Code"
    subtitle=""
    onClose={onClose}
    busy={activating}
    width="1180px"
  >
    <div className="flex min-h-full flex-col">
      <div className="min-h-0 flex-1">
        <div className="mb-4 flex items-center gap-3 border border-[#D8E1E0] bg-[#F8FAFC] p-3">
          <div className="flex h-12 w-12 items-center justify-center border border-[#BAC8C8] bg-white text-[#004141]">
            <MonitorIcon />
          </div>

          <div>
            <div className="text-[13px] font-medium text-[#091B1B]">
              {till.till_code} - {till.till_name}
            </div>
            <div className="mt-1 text-[11px] text-[#637877]">
              {getTillBranchName(till)} · {till.is_registered ? "Registered" : "Not registered"}
            </div>
          </div>
        </div>

        <div className="mb-4 border border-[#BEDAD9] bg-[#F1F7F7] p-3 text-[10px] leading-4 text-[#1A5151]">
          Generate a short-lived, one-time code for the separate Front Office browser. The till credential is created only when Front Office redeems the code; it is never saved in this Back Office browser. Generating a replacement invalidates the previous unused code.
        </div>

        <div className="grid grid-cols-12 gap-3">
          <Field label="Device Label" span="12">
            <input
              type="text"
              name="device_label"
              value={activateOptions.device_label}
              onChange={handleActivateOptionChange}
              className="tm-input"
            />
          </Field>

          <div className="col-span-12 grid grid-cols-2 gap-2">
            <CardLine label="Last Seen" value={formatDateTime(till.last_seen_at)} />
            <CardLine label="Current Device" value={till.device_label || "-"} />
          </div>

          {standaloneActivation?.terminalActivationCode && (
            <div className="col-span-12 border border-[#86EFAC] bg-[#F0FDF4] p-3" role="status">
              <div className="text-[10px] font-semibold uppercase tracking-wide text-[#166534]">
                One-time code — shown only here
              </div>
              <div className="mt-2 flex gap-2">
                <input
                  aria-label="One-time terminal activation code"
                  readOnly
                  value={standaloneActivation.terminalActivationCode}
                  className="tm-input min-w-0 flex-1 font-mono font-semibold uppercase"
                />
                <button
                  type="button"
                  onClick={onCopyActivation}
                  className="h-9 border border-[#008A8A] bg-white px-3 text-[10px] font-semibold uppercase text-[#006969]"
                >
                  {activationCopyState === "copied" ? "Copied" : "Copy"}
                </button>
              </div>
              <div className="mt-2 text-[10px] text-[#166534]">
                Expires: <strong>{formatDateTime(standaloneActivation.expiresAt)}</strong>
              </div>
              {activationCopyState === "failed" && (
                <div className="mt-1 text-[10px] text-[#B42318]">Select the code and copy it manually.</div>
              )}
            </div>
          )}
        </div>
      </div>

      <DialogFooter>
        <button type="button" onClick={onClose} className="tm-btn-secondary">
          {standaloneActivation?.terminalActivationCode ? "Close" : "Cancel"}
        </button>
        <button type="button" onClick={onActivate} disabled={activating} className="tm-btn-primary">
          {activating
            ? "Generating"
            : standaloneActivation?.terminalActivationCode
              ? "Generate New Code"
              : "Generate Code"}
        </button>
      </DialogFooter>
    </div>
  </EnterpriseFormModal>
);

const DialogFooter = ({ children }) => (
  <div className="flex h-12 shrink-0 items-center justify-end gap-2 border-t border-[#D8E1E0] bg-[#F8FAFC] px-4">
    {children}
  </div>
);

const Field = ({ label, span = "3", children }) => {
  const spanClass = {
    3: "col-span-12 md:col-span-3",
    6: "col-span-12 md:col-span-6",
    12: "col-span-12",
  }[span] || "col-span-12 md:col-span-3";

  return (
    <label className={spanClass}>
      <div className="mb-1 text-[11px] font-medium uppercase tracking-wide text-[#324444]">
        {label}
      </div>
      {children}
    </label>
  );
};

const FilterField = ({ label, children }) => (
  <label className="grid min-w-[150px] gap-1 text-[10px] font-semibold text-[#455655]">
    <span>{label}</span>
    {children}
  </label>
);

const CheckBox = ({ name, checked, onChange, label }) => (
  <label className="flex h-9 items-center gap-2 border border-[#D8E1E0] bg-white px-2 text-[12px] text-[#324444]">
    <input
      type="checkbox"
      name={name}
      checked={checked}
      onChange={onChange}
      className="h-3.5 w-3.5"
    />
    {label}
  </label>
);

const CardLine = ({ label, value }) => (
  <div className="min-w-0">
    <div className="text-[9px] font-medium uppercase tracking-wide text-[#637877]">
      {label}
    </div>
    <div className="mt-0.5 truncate text-[11px] text-[#091B1B]" title={value}>
      {value || "-"}
    </div>
  </div>
);

const AppInlineStyles = () => (
  <style>
    {`
      .tm-input {
        height: 36px;
        width: 100%;
        border: 1px solid #ACB8B8;
        background: #ffffff;
        padding: 0 8px;
        font-size: 12px;
        outline: none;
      }

      .tm-input:focus {
        border-color: #00BCBC;
      }

      .tm-btn-primary,
      .tm-btn-secondary {
        height: 36px;
        padding: 0 16px;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.04em;
      }

      .tm-btn-primary {
        border: 1px solid #008A8A;
        background: #00BCBC;
        color: #004141;
      }

      .tm-btn-secondary {
        border: 1px solid #ACB8B8;
        background: #ffffff;
        color: #004141;
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

export default TillManagementModal;
