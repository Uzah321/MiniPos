// src/backOffice/pages/products/StockTransferModal.jsx

import React, { useEffect, useMemo, useState } from "react";
import ReusableMessageModal from "../../../components/ReusableMessageModal";
import { requestEnterpriseConfirmation } from "../../../components/EnterpriseConfirmHost";
import { getAuthorisedCompany } from "../../../services/AuthenticationService";
import { getInventoryWarehouses } from "../../../services/EnterpriseInventoryService";
import {
  approveStockTransfer,
  cancelStockTransfer,
  createStockTransfer,
  dispatchStockTransfer,
  exportStockTransferReport,
  getStockTransfer,
  getStockTransferAgingReport,
  getStockTransferBranchReport,
  getStockTransferDashboard,
  getStockTransferLineReport,
  getStockTransferReport,
  getStockTransfers,
  receiveStockTransfer,
  rejectStockTransfer,
  searchTransferProducts,
  updateStockTransfer,
} from "../../../services/StockTransferService";

const today = () => new Date().toISOString().slice(0, 10);

const money = (value, currency = "") => {
  const amount = Number(value || 0).toFixed(2);
  return currency ? `${currency} ${amount}` : amount;
};

const qty = (value) => Number(value || 0).toFixed(4);

const rowsFrom = (payload) => {
  if (Array.isArray(payload?.data?.data)) return payload.data.data;
  if (Array.isArray(payload?.data)) return payload.data;
  return [];
};

const metaFrom = (payload) => {
  const data = payload?.data || {};
  return {
    current_page: Number(data.current_page || 1),
    last_page: Number(data.last_page || 1),
    total: Number(data.total || 0),
  };
};

const branchLabel = (branch) => {
  if (!branch) return "";
  const name = branch.branch_name || branch.name || "Unnamed Branch";
  const code = branch.branch_code || branch.code || "";
  return code ? `${name} (${code})` : name;
};

const canEditRequest = (status) => !status || ["draft", "requested"].includes(status);
const canApprove = (status) => status === "requested";
const canDispatch = (status) => status === "approved";
const canReceive = (status) => ["dispatched", "partially_received"].includes(status);
const canCancel = (status) => ["draft", "requested", "approved"].includes(status);

const flowSteps = [
  {
    key: "requested",
    label: "Requested",
    description: "Request is created by source/destination team and product quantities are captured.",
  },
  {
    key: "approved",
    label: "Approved",
    description: "Manager checks the request and confirms approved quantities.",
  },
  {
    key: "dispatched",
    label: "Dispatched",
    description: "Source branch releases stock. Transfer-out movements are created.",
  },
  {
    key: "received",
    label: "Received",
    description: "Destination branch receives stock. Transfer-in movements and variances are recorded.",
  },
];

const flowIndex = (status) => {
  if (status === "requested") return 0;
  if (status === "approved") return 1;
  if (status === "dispatched" || status === "partially_received") return 2;
  if (status === "received") return 3;
  return -1;
};

const Button = ({ children, variant = "default", className = "", ...props }) => {
  const styles = {
    default: "border-[#ACB8B8] bg-white text-[#003636] hover:bg-[#F6F8FA]",
    primary: "border-[#003636] bg-[#003637] text-white hover:bg-[#004444]",
    success: "border-[#069495] bg-[#E8F7F5] text-[#006869] hover:bg-[#D7F0EC]",
    danger: "border-[#B42318] bg-[#B42318] text-white hover:bg-[#8F1D14]",
    warning: "border-[#B54708] bg-[#FFFAEB] text-[#B54708] hover:bg-[#FEF0C7]",
  };

  return (
    <button
      type="button"
      {...props}
      className={`h-9 border px-4 text-[12px] font-semibold disabled:cursor-not-allowed disabled:opacity-50 ${styles[variant]} ${className}`}
    >
      {children}
    </button>
  );
};

const Field = ({ label, hint, children }) => (
  <label className="flex flex-col gap-1">
    <span className="text-[11px] font-semibold uppercase tracking-wide text-[#637877]">
      {label}
    </span>
    {children}
    {hint && <span className="text-[11px] text-[#637877]">{hint}</span>}
  </label>
);

const StatusPill = ({ status }) => {
  const text = status || "-";
  const styles = {
    draft: "border-[#D7DFDF] bg-[#F6F8FA] text-[#637877]",
    requested: "border-[#A4E5E4] bg-[#F1F8F8] text-[#057B7B]",
    approved: "border-[#ABEFC6] bg-[#ECFDF3] text-[#067647]",
    dispatched: "border-[#FEDF89] bg-[#FFFAEB] text-[#B54708]",
    partially_received: "border-[#FDA29B] bg-[#FEF3F2] text-[#B42318]",
    received: "border-[#00BCBC] bg-[#E8F7F5] text-[#006869]",
    cancelled: "border-[#D7DFDF] bg-white text-[#637877]",
    rejected: "border-[#FDA29B] bg-[#FEF3F2] text-[#B42318]",
  };

  return (
    <span
      className={`inline-flex min-w-[116px] justify-center border px-2 py-1 text-[11px] font-semibold ${
        styles[text] || styles.draft
      }`}
    >
      {text}
    </span>
  );
};

const StatCard = ({ label, value, currency, danger, success }) => (
  <div className="border border-[#D7DFDF] bg-white p-3">
    <div className="text-[11px] font-semibold uppercase tracking-wide text-[#637877]">
      {label}
    </div>
    <div
      className={`mt-1 text-[18px] font-black ${
        danger ? "text-[#B42318]" : success ? "text-[#067647]" : "text-[#003636]"
      }`}
    >
      {currency ? money(value, currency) : value}
    </div>
  </div>
);

const FlowStepper = ({ status }) => {
  const currentIndex = flowIndex(status);

  return (
    <div className="border border-[#D7DFDF] bg-white p-3">
      <div className="mb-2 flex items-center justify-between">
        <div className="text-[12px] font-bold uppercase tracking-wide text-[#003636]">Transfer Progress</div>
        <StatusPill status={status || "draft"} />
      </div>

      <div className="grid gap-2 md:grid-cols-4">
        {flowSteps.map((step, index) => {
          const done = currentIndex >= index;
          const active = currentIndex === index;

          return (
            <div
              key={step.key}
              className={`border p-3 ${
                done
                  ? "border-[#00BCBC] bg-[#E8F7F5]"
                  : active
                  ? "border-[#A4E5E4] bg-[#F1F8F8]"
                  : "border-[#D7DFDF] bg-[#F6F8FA]"
              }`}
            >
              <div className="flex items-center gap-2">
                <span
                  className={`flex h-5 w-5 items-center justify-center border text-[11px] font-bold ${
                    done
                      ? "border-[#006869] bg-[#006868] text-white"
                      : "border-[#ACB8B8] bg-white text-[#637877]"
                  }`}
                >
                  {done ? "✓" : index + 1}
                </span>
                <span className="text-[12px] font-bold text-[#003636]">{step.label}</span>
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
};

const StageChecklist = ({ stage, checks, setChecks, disabled }) => {
  const items = {
    approve: [
      ["quantities", "I checked requested quantities against source stock."],
      ["branches", "I confirmed from/to branches are correct."],
      ["value", "I reviewed cost and selling value exposure."],
    ],
    dispatch: [
      ["physical", "Physical stock has been picked and packed."],
      ["approved", "Dispatch quantities do not exceed approved quantities."],
      ["movement", "I understand this will reduce source branch stock."],
    ],
    receive: [
      ["counted", "Received quantities were counted at destination branch."],
      ["variance", "Any shortage/overage has been captured as variance."],
      ["movement", "I understand this will increase destination branch stock."],
    ],
  };

  const stageItems = items[stage] || [];

  return (
    <div className="border border-[#D7DFDF] bg-[#F6F8FA] p-3">
      <div className="mb-2 text-[12px] font-bold uppercase tracking-wide text-[#003636]">
        {stage} checklist
      </div>

      <div className="space-y-2">
        {stageItems.map(([key, label]) => (
          <label key={key} className="flex items-start gap-2 text-[12px] text-[#324443]">
            <input
              type="checkbox"
              checked={Boolean(checks?.[stage]?.[key])}
              disabled={disabled}
              onChange={(event) =>
                setChecks((current) => ({
                  ...current,
                  [stage]: {
                    ...(current[stage] || {}),
                    [key]: event.target.checked,
                  },
                }))
              }
              className="mt-0.5"
            />
            <span>{label}</span>
          </label>
        ))}
      </div>
    </div>
  );
};

const allStageChecked = (checks, stage) => {
  const expected = {
    approve: ["quantities", "branches", "value"],
    dispatch: ["physical", "approved", "movement"],
    receive: ["counted", "variance", "movement"],
  }[stage] || [];

  return expected.every((key) => Boolean(checks?.[stage]?.[key]));
};

const emptyForm = () => ({
  transfer_date: today(),
  from_location_type: "warehouse",
  from_branch_id: "",
  from_warehouse_id: "",
  to_location_type: "branch",
  to_branch_id: "",
  to_warehouse_id: "",
  request_notes: "",
  lines: [],
});

export default function StockTransferModal({ open = true, onClose }) {
  const [activePanel, setActivePanel] = useState("process");
  const [branches, setBranches] = useState([]);
  const [warehouses, setWarehouses] = useState([]);
  const [requestModalOpen, setRequestModalOpen] = useState(false);
  const [detailModalOpen, setDetailModalOpen] = useState(false);
  const [form, setForm] = useState(emptyForm());
  const [productSearch, setProductSearch] = useState("");
  const [productResults, setProductResults] = useState([]);

  const [transfers, setTransfers] = useState(null);
  const [selectedTransfer, setSelectedTransfer] = useState(null);
  const [selectedLines, setSelectedLines] = useState([]);

  const [stageChecks, setStageChecks] = useState({
    approve: {},
    dispatch: {},
    receive: {},
  });

  const [dateFrom, setDateFrom] = useState(today());
  const [dateTo, setDateTo] = useState(today());
  const [status, setStatus] = useState("");
  const [fromBranchFilter, setFromBranchFilter] = useState("");
  const [toBranchFilter, setToBranchFilter] = useState("");
  const [q, setQ] = useState("");

  const [dashboard, setDashboard] = useState(null);
  const [transferReport, setTransferReport] = useState(null);
  const [lineReport, setLineReport] = useState(null);
  const [branchReport, setBranchReport] = useState(null);
  const [agingReport, setAgingReport] = useState(null);

  const [loading, setLoading] = useState(false);
  const [branchLoading, setBranchLoading] = useState(false);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");
  const [messageModal, setMessageModal] = useState({ open: false, type: "success", title: "", message: "" });

  const showActionMessage = (type, title, modalMessage) => {
    setMessageModal({ open: true, type, title, message: modalMessage });
  };

  const transferRows = useMemo(() => rowsFrom(transfers), [transfers]);
  const transferMeta = useMemo(() => metaFrom(transfers), [transfers]);
  const transferReportRows = useMemo(() => rowsFrom(transferReport), [transferReport]);
  const transferReportMeta = useMemo(() => metaFrom(transferReport), [transferReport]);
  const lineReportRows = useMemo(() => rowsFrom(lineReport), [lineReport]);
  const lineReportMeta = useMemo(() => metaFrom(lineReport), [lineReport]);
  const agingRows = useMemo(() => rowsFrom(agingReport), [agingReport]);
  const agingMeta = useMemo(() => metaFrom(agingReport), [agingReport]);
  const summary = dashboard?.data?.summary || {};

  const reportFilters = (page = 1) => ({
    date_from: dateFrom,
    date_to: dateTo,
    status,
    from_branch_id: fromBranchFilter,
    to_branch_id: toBranchFilter,
    q,
    page,
    per_page: 50,
  });

  const loadBranches = async () => {
    try {
      setBranchLoading(true);
      setError("");

      const res = await getAuthorisedCompany();
      const info =
        res?.company ||
        res?.schoolInfo ||
        res?.data?.companyInfo ||
        res?.data?.schoolInfo ||
        res?.data ||
        null;
      const apiBranches = res?.company?.branches || res?.data?.branches || info?.branches || [];

      setBranches(Array.isArray(apiBranches) ? apiBranches : []);
    } catch (err) {
      setError(err?.response?.data?.message || "Failed to load authorised branches.");
    } finally {
      setBranchLoading(false);
    }
  };

  const loadWarehouses = async () => {
    try {
      const rows = await getInventoryWarehouses();
      setWarehouses(Array.isArray(rows) ? rows.filter((row) => row.is_active !== false) : []);
    } catch (err) {
      setError(err?.response?.data?.message || "Failed to load warehouses.");
    }
  };

  const loadTransfers = async (page = 1) => {
    try {
      setLoading(true);
      setError("");

      const res = await getStockTransfers({
        date_from: dateFrom,
        date_to: dateTo,
        status,
        from_branch_id: fromBranchFilter,
        to_branch_id: toBranchFilter,
        q,
        page,
        per_page: 25,
      });

      setTransfers(res);
    } catch (err) {
      setError(err?.response?.data?.message || "Failed to load transfers.");
    } finally {
      setLoading(false);
    }
  };

  const loadReports = async (page = 1) => {
    try {
      setLoading(true);
      setError("");

      const filters = reportFilters(page);

      const [dash, transfersRes, linesRes, branchRes, agingRes] = await Promise.all([
        getStockTransferDashboard(filters),
        getStockTransferReport(filters),
        getStockTransferLineReport(filters),
        getStockTransferBranchReport(filters),
        getStockTransferAgingReport(filters),
      ]);

      setDashboard(dash);
      setTransferReport(transfersRes);
      setLineReport(linesRes);
      setBranchReport(branchRes);
      setAgingReport(agingRes);
    } catch (err) {
      setError(err?.response?.data?.message || "Failed to load transfer reports.");
    } finally {
      setLoading(false);
    }
  };

  const openTransfer = async (transfer) => {
    try {
      setLoading(true);
      setError("");
      setMessage("");

      const res = await getStockTransfer(transfer.id);
      const transferData = res?.data?.transfer || transfer;
      const linesData = res?.data?.lines || [];

      setSelectedTransfer(transferData);
      setSelectedLines(linesData);
      setStageChecks({ approve: {}, dispatch: {}, receive: {} });

      setForm({
        transfer_date: transferData?.transfer_date || today(),
        from_location_type: transferData?.from_location_type || (transferData?.from_warehouse_id ? "warehouse" : "branch"),
        from_branch_id: transferData?.from_branch_id || "",
        from_warehouse_id: transferData?.from_warehouse_id || "",
        to_location_type: transferData?.to_location_type || (transferData?.to_warehouse_id ? "warehouse" : "branch"),
        to_branch_id: transferData?.to_branch_id || "",
        to_warehouse_id: transferData?.to_warehouse_id || "",
        request_notes: transferData?.request_notes || "",
        lines: linesData.map((line) => ({
          product_id: line.product_id,
          product_name: line.product_name,
          code: line.code,
          sku: line.sku,
          quantity_on_hand: line.quantity_on_hand,
          quantity_requested: line.quantity_requested,
          line_notes: line.line_notes || "",
        })),
      });
      setDetailModalOpen(true);
    } catch (err) {
      setError(err?.response?.data?.message || "Failed to open transfer.");
    } finally {
      setLoading(false);
    }
  };

  const searchProducts = async () => {
    if (!form.from_branch_id || (form.from_location_type === "warehouse" && !form.from_warehouse_id)) {
      setError("Select the source stock location first.");
      return;
    }

    try {
      setLoading(true);
      setError("");

      const res = await searchTransferProducts({
        branch_id: form.from_branch_id,
        source_type: form.from_location_type,
        warehouse_id: form.from_location_type === "warehouse" ? form.from_warehouse_id : undefined,
        q: productSearch,
        limit: 50,
      });

      setProductResults(res?.data || []);
    } catch (err) {
      setError(err?.response?.data?.message || "Failed to search products.");
    } finally {
      setLoading(false);
    }
  };

  const addProduct = (product) => {
    if (form.lines.some((line) => line.product_id === product.id)) return;

    setForm((current) => ({
      ...current,
      lines: [
        ...current.lines,
        {
          product_id: product.id,
          product_name: product.name,
          code: product.code,
          sku: product.sku,
          quantity_on_hand: product.quantity_on_hand,
          quantity_requested: 1,
          line_notes: "",
        },
      ],
    }));
  };

  const updateLine = (index, key, value) => {
    setForm((current) => ({
      ...current,
      lines: current.lines.map((line, lineIndex) =>
        lineIndex === index ? { ...line, [key]: value } : line
      ),
    }));
  };

  const removeLine = (index) => {
    setForm((current) => ({
      ...current,
      lines: current.lines.filter((_, lineIndex) => lineIndex !== index),
    }));
  };

  const resetForm = () => {
    setForm(emptyForm());
    setSelectedTransfer(null);
    setSelectedLines([]);
    setProductResults([]);
    setProductSearch("");
    setStageChecks({ approve: {}, dispatch: {}, receive: {} });
    setError("");
    setMessage("");
  };

  const startNewTransfer = () => {
    resetForm();
    setRequestModalOpen(true);
  };

  const selectEndpointWarehouse = (side, warehouseId) => {
    const warehouse = warehouses.find((row) => String(row.id) === String(warehouseId));
    setForm((current) => ({
      ...current,
      [`${side}_warehouse_id`]: warehouseId,
      [`${side}_branch_id`]: warehouse?.branch_id || "",
      ...(side === "from" ? { lines: [] } : {}),
    }));
    if (side === "from") {
      setProductResults([]);
      setProductSearch("");
    }
    setError("");
  };

  const saveTransfer = async () => {
    if (form.from_location_type === "warehouse" && !form.from_warehouse_id) {
      setError("Select the source warehouse.");
      return;
    }
    if (form.from_location_type === "branch" && !form.from_branch_id) {
      setError("Select the source branch.");
      return;
    }
    if (form.to_location_type === "warehouse" && !form.to_warehouse_id) {
      setError("Select the destination warehouse.");
      return;
    }
    if (form.to_location_type === "branch" && !form.to_branch_id) {
      setError("Select the destination branch.");
      return;
    }
    if (!form.from_branch_id) {
      setError("Select the stock accounting branch for the company-wide source warehouse.");
      return;
    }
    if (!form.to_branch_id) {
      setError("Select the stock accounting branch for the company-wide destination warehouse.");
      return;
    }

    if (form.from_location_type === form.to_location_type && form.from_branch_id === form.to_branch_id && (form.from_location_type === "branch" || form.from_warehouse_id === form.to_warehouse_id)) {
      setError("Source and destination cannot be the same stock location.");
      return;
    }

    if (!form.lines.length) {
      setError("Add at least one product line.");
      return;
    }

    const invalidLine = form.lines.find((line) => Number(line.quantity_requested || 0) <= 0);
    if (invalidLine) {
      setError("All transfer lines must have request quantity greater than zero.");
      return;
    }

    try {
      setLoading(true);
      setError("");
      setMessage("");

      const payload = {
        ...form,
        lines: form.lines.map((line) => ({
          product_id: line.product_id,
          quantity_requested: Number(line.quantity_requested || 0),
          line_notes: line.line_notes || "",
        })),
      };

      const res =
        selectedTransfer?.id && canEditRequest(selectedTransfer.status)
          ? await updateStockTransfer(selectedTransfer.id, payload)
          : await createStockTransfer(payload);

      setMessage(res?.message || "Stock transfer saved.");

      const transfer = res?.data?.transfer;
      if (transfer) {
        setRequestModalOpen(false);
        await openTransfer(transfer);
      }

      await loadTransfers();
      await loadReports();
    } catch (err) {
      setError(err?.response?.data?.message || err?.response?.data?.error || "Failed to save transfer.");
    } finally {
      setLoading(false);
    }
  };

  const runAction = async (action) => {
    if (!selectedTransfer?.id) {
      showActionMessage("error", "Transfer Action", "Select a transfer first.");
      return;
    }

    if (action === "approve" && !allStageChecked(stageChecks, "approve")) {
      showActionMessage("error", "Approval Required", "Complete all approval checklist items first.");
      return;
    }

    if (action === "dispatch" && !allStageChecked(stageChecks, "dispatch")) {
      showActionMessage("error", "Dispatch Required", "Complete all dispatch checklist items first.");
      return;
    }

    if (action === "receive" && !allStageChecked(stageChecks, "receive")) {
      showActionMessage("error", "Receiving Required", "Complete all receiving checklist items first.");
      return;
    }

    try {
      setLoading(true);
      setError("");
      setMessage("");

      let res;

      if (action === "approve") {
        res = await approveStockTransfer(selectedTransfer.id, {
          approval_notes: "Approved from interbranch transfer module.",
          lines: selectedLines.map((line) => ({
            product_id: line.product_id,
            quantity_approved: Number(line.quantity_approved || line.quantity_requested || 0),
          })),
          checks: stageChecks.approve,
        });
      }

      if (action === "reject") {
        res = await rejectStockTransfer(selectedTransfer.id, {
          rejection_notes: "Rejected from interbranch transfer module.",
        });
      }

      if (action === "dispatch") {
        res = await dispatchStockTransfer(selectedTransfer.id, {
          dispatch_notes: "Dispatched from interbranch transfer module.",
          lines: selectedLines.map((line) => ({
            product_id: line.product_id,
            quantity_dispatched: Number(
              line.quantity_dispatched || line.quantity_approved || line.quantity_requested || 0
            ),
          })),
          checks: stageChecks.dispatch,
        });
      }

      if (action === "receive") {
        res = await receiveStockTransfer(selectedTransfer.id, {
          receiving_notes: "Received from interbranch transfer module.",
          lines: selectedLines.map((line) => ({
            product_id: line.product_id,
            quantity_received: Number(line.quantity_received || line.quantity_dispatched || 0),
          })),
          checks: stageChecks.receive,
        });
      }

      if (action === "cancel") {
        const ok = await requestEnterpriseConfirmation({
          title: "Cancel Stock Transfer",
          message: "Cancel this stock transfer?",
          confirmLabel: "Cancel transfer",
        });
        if (!ok) return;

        res = await cancelStockTransfer(selectedTransfer.id, {
          cancel_notes: "Cancelled from interbranch transfer module.",
        });
      }

      const successMessage = res?.message || "Transfer updated.";
      setMessage(successMessage);
      showActionMessage("success", "Transfer Updated", successMessage);

      if (res?.data?.transfer) {
        await openTransfer(res.data.transfer);
      } else {
        await openTransfer(selectedTransfer);
      }

      await loadTransfers();
      await loadReports();
    } catch (err) {
      const actionError = err?.response?.data?.message || err?.response?.data?.error || "Transfer action failed.";
      setError("");
      showActionMessage("error", "Transfer Action Failed", actionError);
    } finally {
      setLoading(false);
    }
  };

  const exportReport = async (type) => {
    try {
      setLoading(true);
      setError("");

      await exportStockTransferReport({
        ...reportFilters(1),
        type,
        format: "xlsx",
      });

      setMessage("Report downloaded.");
    } catch (err) {
      setError(err?.response?.data?.message || "Failed to export report.");
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (open) {
      loadBranches();
      loadWarehouses();
      loadTransfers();
      loadReports();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  if (!open) return null;

  return (
    <div className="fixed inset-0 z-[80] bg-white">
      <div className="flex h-screen flex-col bg-[#F6F8FA] text-[#003636]">
        <div className="flex h-14 items-center justify-between border-b-2 border-[#00BCBC] bg-[#004142] px-4 text-white">
          <div>
            <div className="text-[16px] font-black uppercase tracking-wide">
              Warehouse Transfers
            </div>
            <div className="text-[11px] font-semibold text-white/70">
              Request · Approve · Dispatch · Receive
            </div>
          </div>

          <button
            type="button"
            onClick={onClose}
            className="h-8 border border-white/30 px-3 text-[12px] font-bold hover:bg-white/10"
          >
            Close
          </button>
        </div>

        {(error || message) && (
          <div
            className={`border-b px-4 py-2 text-[12px] font-semibold ${
              error
                ? "border-[#FDA29B] bg-[#FEF3F2] text-[#B42318]"
                : "border-[#ABEFC6] bg-[#ECFDF3] text-[#067647]"
            }`}
          >
            {error || message}
          </div>
        )}

        <div className="flex border-b border-[#D7DFDF] bg-white">
          {[
            ["process", "Enterprise Flow"],
            ["transfer-report", "Transfer Report"],
            ["line-report", "Product Lines"],
            ["branch-report", "Branch Flow"],
            ["aging-report", "Pending / Aging"],
          ].map(([key, label]) => (
            <button
              key={key}
              type="button"
              onClick={() => setActivePanel(key)}
              className={`h-10 border-r border-[#D7DFDF] px-4 text-[12px] font-semibold ${
                activePanel === key ? "bg-[#E8F7F5] text-[#006869]" : "bg-white text-[#003636]"
              }`}
            >
              {label}
            </button>
          ))}
        </div>

        <div className="relative min-h-0 flex-1 overflow-hidden">
          {requestModalOpen && <div className="fixed inset-0 z-[100] flex items-center justify-center bg-[#003637]/55 p-4">
          <div className="flex max-h-[92vh] w-full max-w-6xl flex-col overflow-hidden border border-[#ACB8B8] bg-white shadow-2xl">
            <div className="flex h-12 shrink-0 items-center justify-between bg-[#004142] px-4 text-white"><div><b className="text-[14px]">{selectedTransfer?.id ? `Edit ${selectedTransfer.transfer_no}` : "New Warehouse Transfer"}</b></div><button type="button" onClick={() => { setRequestModalOpen(false); setError(""); }} className="border border-white/30 px-3 py-1 text-[11px] font-bold">Close</button></div>
          <aside className="min-h-0 overflow-auto bg-white p-4">
            <div className="space-y-3">
              {error && <div className="border border-[#FDA29B] bg-[#FEF3F2] px-3 py-2 text-[12px] font-semibold text-[#B42318]">{error}</div>}
              <div className="border border-[#D7DFDF] bg-[#F6F8FA] p-3">
                <div className="mb-2 text-[12px] font-bold uppercase tracking-wide">
                  Transfer Header
                </div>

                <Field label="Transfer Date">
                  <input
                    type="date"
                    value={form.transfer_date}
                    onChange={(e) => setForm({ ...form, transfer_date: e.target.value })}
                    disabled={selectedTransfer?.id && !canEditRequest(selectedTransfer.status)}
                    className="h-9 border border-[#ACB8B8] px-2 text-[12px] disabled:bg-[#E4E8E8]"
                  />
                </Field>

                <div className="mt-2 grid gap-3 sm:grid-cols-2">
                  <div className="border border-[#B9C5C5] bg-white p-3">
                    <Field label="Transfer From">
                      <select value={form.from_location_type} disabled={selectedTransfer?.id && !canEditRequest(selectedTransfer.status)} onChange={(e) => { setForm({ ...form, from_location_type: e.target.value, from_branch_id: "", from_warehouse_id: "", lines: [] }); setProductResults([]); setError(""); }} className="h-9 border border-[#ACB8B8] px-2 text-[12px] disabled:bg-[#E4E8E8]"><option value="warehouse">Warehouse</option><option value="branch">Branch selling stock</option></select>
                    </Field>

                    <div className="mt-2">
                      {form.from_location_type === "warehouse" ? (
                        <Field label="Source Warehouse" hint="Choose the warehouse directly. Its branch is assigned automatically.">
                          <select value={form.from_warehouse_id} disabled={selectedTransfer?.id && !canEditRequest(selectedTransfer.status)} onChange={(e) => selectEndpointWarehouse("from", e.target.value)} className="h-9 border border-[#ACB8B8] px-2 text-[12px] disabled:bg-[#E4E8E8]">
                            <option value="">Select source warehouse</option>
                            {warehouses.map((warehouse) => <option key={warehouse.id} value={warehouse.id}>{warehouse.name}{warehouse.code ? ` (${warehouse.code})` : ""}{warehouse.branch_name ? ` · ${warehouse.branch_name}` : " · Company-wide"}</option>)}
                          </select>
                        </Field>
                      ) : (
                        <Field label="Source Branch">
                          <select value={form.from_branch_id} disabled={selectedTransfer?.id && !canEditRequest(selectedTransfer.status)} onChange={(e) => { setForm({ ...form, from_branch_id: e.target.value, lines: [] }); setProductResults([]); setProductSearch(""); setError(""); }} className="h-9 border border-[#ACB8B8] px-2 text-[12px] disabled:bg-[#E4E8E8]">
                            <option value="">Select source branch</option>
                            {branches.map((branch) => <option key={branch.id} value={branch.id}>{branchLabel(branch)}</option>)}
                          </select>
                        </Field>
                      )}
                    </div>

                    {form.from_location_type === "warehouse" && form.from_warehouse_id && !warehouses.find((warehouse) => String(warehouse.id) === String(form.from_warehouse_id))?.branch_id && (
                      <div className="mt-2"><Field label="Stock Accounting Branch" hint="Required only because this warehouse is company-wide."><select value={form.from_branch_id} onChange={(e) => setForm({ ...form, from_branch_id: e.target.value, lines: [] })} className="h-9 border border-[#ACB8B8] px-2 text-[12px]"><option value="">Select branch</option>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branchLabel(branch)}</option>)}</select></Field></div>
                    )}
                  </div>

                  <div className="border border-[#B9C5C5] bg-white p-3">
                    <Field label="Transfer To">
                      <select value={form.to_location_type} disabled={selectedTransfer?.id && !canEditRequest(selectedTransfer.status)} onChange={(e) => { setForm({ ...form, to_location_type: e.target.value, to_branch_id: "", to_warehouse_id: "" }); setError(""); }} className="h-9 border border-[#ACB8B8] px-2 text-[12px] disabled:bg-[#E4E8E8]"><option value="branch">Branch selling stock</option><option value="warehouse">Warehouse</option></select>
                    </Field>

                    <div className="mt-2">
                      {form.to_location_type === "warehouse" ? (
                        <Field label="Destination Warehouse" hint="Choose the warehouse directly. Its branch is assigned automatically.">
                          <select value={form.to_warehouse_id} disabled={selectedTransfer?.id && !canEditRequest(selectedTransfer.status)} onChange={(e) => selectEndpointWarehouse("to", e.target.value)} className="h-9 border border-[#ACB8B8] px-2 text-[12px] disabled:bg-[#E4E8E8]">
                            <option value="">Select destination warehouse</option>
                            {warehouses.filter((warehouse) => form.from_location_type !== "warehouse" || String(warehouse.id) !== String(form.from_warehouse_id)).map((warehouse) => <option key={warehouse.id} value={warehouse.id}>{warehouse.name}{warehouse.code ? ` (${warehouse.code})` : ""}{warehouse.branch_name ? ` · ${warehouse.branch_name}` : " · Company-wide"}</option>)}
                          </select>
                        </Field>
                      ) : (
                        <Field label="Destination Branch">
                          <select value={form.to_branch_id} disabled={selectedTransfer?.id && !canEditRequest(selectedTransfer.status)} onChange={(e) => { setForm({ ...form, to_branch_id: e.target.value }); setError(""); }} className="h-9 border border-[#ACB8B8] px-2 text-[12px] disabled:bg-[#E4E8E8]">
                            <option value="">Select destination branch</option>
                            {branches.map((branch) => <option key={branch.id} value={branch.id}>{branchLabel(branch)}</option>)}
                          </select>
                        </Field>
                      )}
                    </div>

                    {form.to_location_type === "warehouse" && form.to_warehouse_id && !warehouses.find((warehouse) => String(warehouse.id) === String(form.to_warehouse_id))?.branch_id && (
                      <div className="mt-2"><Field label="Stock Accounting Branch" hint="Required only because this warehouse is company-wide."><select value={form.to_branch_id} onChange={(e) => setForm({ ...form, to_branch_id: e.target.value })} className="h-9 border border-[#ACB8B8] px-2 text-[12px]"><option value="">Select branch</option>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branchLabel(branch)}</option>)}</select></Field></div>
                    )}
                  </div>
                </div>

                <div className="mt-2">
                  <Field label="Request Notes">
                    <textarea
                      value={form.request_notes}
                      disabled={selectedTransfer?.id && !canEditRequest(selectedTransfer.status)}
                      onChange={(e) => setForm({ ...form, request_notes: e.target.value })}
                      rows={2}
                      className="w-full border border-[#ACB8B8] px-3 py-2 text-[12px] disabled:bg-[#E4E8E8]"
                    />
                  </Field>
                </div>
              </div>

              {canEditRequest(selectedTransfer?.status) && (
                <div className="border border-[#D7DFDF] bg-white p-3">
                  <div className="mb-2 text-[12px] font-bold uppercase tracking-wide">
                    Add Products
                  </div>

                  <div className="grid gap-2 sm:grid-cols-[1fr_auto]">
                    <input
                      value={productSearch}
                      onChange={(e) => setProductSearch(e.target.value)}
                      onKeyDown={(e) => {
                        if (e.key === "Enter") {
                          e.preventDefault();
                          searchProducts();
                        }
                      }}
                      placeholder="Search product name, SKU, code or barcode..."
                      className="h-9 border border-[#ACB8B8] px-3 text-[12px]"
                    />
                    <Button onClick={searchProducts} disabled={loading || !form.from_branch_id || (form.from_location_type === "warehouse" && !form.from_warehouse_id)}>
                      Search
                    </Button>
                  </div>

                  {(!form.from_branch_id || (form.from_location_type === "warehouse" && !form.from_warehouse_id)) && (
                    <div className="mt-2 border border-[#FEDF89] bg-[#FFFAEB] px-3 py-2 text-[11px] text-[#B54708]">
                      Select the source location before adding products.
                    </div>
                  )}

                  <div className="mt-2 max-h-[220px] overflow-auto border border-[#D7DFDF]">
                    {productResults.map((product) => (
                      <button
                        key={product.id}
                        type="button"
                        onClick={() => addProduct(product)}
                        className="flex w-full items-center justify-between border-b border-[#D7DFDF] px-3 py-2 text-left text-[12px] hover:bg-[#F6F8FA]"
                      >
                        <span>
                          <span className="block font-semibold text-[#003636]">
                            {product.name}
                          </span>
                          <span className="text-[#637877]">
                            {product.sku || product.code || product.barcode || "-"} · Stock {qty(product.quantity_on_hand)}
                          </span>
                        </span>
                        <span className="font-semibold text-[#006869]">Add</span>
                      </button>
                    ))}

                    {productResults.length === 0 && (
                      <div className="px-3 py-6 text-center text-[12px] text-[#637877]">
                        Search products from the source branch.
                      </div>
                    )}
                  </div>
                </div>
              )}

              <TransferLinesEntry
                lines={form.lines}
                updateLine={updateLine}
                removeLine={removeLine}
                editable={canEditRequest(selectedTransfer?.status)}
              />

              <Button
                onClick={saveTransfer}
                disabled={loading || (selectedTransfer?.id && !canEditRequest(selectedTransfer.status))}
                variant="success"
                className="w-full"
              >
                Save / Submit Transfer Request
              </Button>
            </div>
          </aside>
          </div>
          </div>}

          <main className="h-full min-h-0 overflow-auto p-4">
            <div className="mb-3 grid gap-2 border border-[#D7DFDF] bg-white p-3 md:grid-cols-[140px_140px_150px_1fr_1fr_1.3fr_auto_auto]">
              <input type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} className="h-9 border border-[#ACB8B8] px-2 text-[11px]" title="From date" />
              <input type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} className="h-9 border border-[#ACB8B8] px-2 text-[11px]" title="To date" />
              <select value={status} onChange={(e) => setStatus(e.target.value)} className="h-9 border border-[#ACB8B8] px-2 text-[11px]"><option value="">All statuses</option><option value="requested">Requested</option><option value="approved">Approved</option><option value="dispatched">Dispatched</option><option value="partially_received">Partial</option><option value="received">Received</option><option value="cancelled">Cancelled</option><option value="rejected">Rejected</option></select>
              <select value={fromBranchFilter} onChange={(e) => setFromBranchFilter(e.target.value)} className="h-9 border border-[#ACB8B8] px-2 text-[11px]"><option value="">All source branches</option>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branchLabel(branch)}</option>)}</select>
              <select value={toBranchFilter} onChange={(e) => setToBranchFilter(e.target.value)} className="h-9 border border-[#ACB8B8] px-2 text-[11px]"><option value="">All destinations</option>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branchLabel(branch)}</option>)}</select>
              <input value={q} onChange={(e) => setQ(e.target.value)} onKeyDown={(e) => { if (e.key === "Enter") { loadTransfers(); loadReports(); } }} placeholder="Transfer number or branch" className="h-9 border border-[#ACB8B8] px-3 text-[11px]" />
              <Button onClick={() => { loadTransfers(); loadReports(); }} disabled={loading || branchLoading}>Filter</Button>
              <Button onClick={startNewTransfer} disabled={loading} variant="primary">New Transfer</Button>
            </div>
            <div className="mb-3 grid gap-2 md:grid-cols-3 xl:grid-cols-6">
              <StatCard label="Transfers" value={summary?.transfer_count || 0} />
              <StatCard label="Requested Qty" value={qty(summary?.requested_quantity)} />
              <StatCard label="Approved Qty" value={qty(summary?.approved_quantity)} />
              <StatCard label="Dispatched Qty" value={qty(summary?.dispatched_quantity)} />
              <StatCard label="Received Qty" value={qty(summary?.received_quantity)} />
              <StatCard label="Cost Value" value={summary?.cost_value || 0} currency={summary?.cost_currency || ""} />
            </div>

            {activePanel === "process" && (
              <div className="space-y-3">
                <TransfersTable
                  transfers={transferRows}
                  meta={transferMeta}
                  loading={loading}
                  onOpen={openTransfer}
                  onPrev={() => loadTransfers(transferMeta.current_page - 1)}
                  onNext={() => loadTransfers(transferMeta.current_page + 1)}
                />
              </div>
            )}

            {activePanel === "transfer-report" && (
              <ReportTable
                title="Transfer Report"
                rows={transferReportRows}
                meta={transferReportMeta}
                onPrev={() => loadReports(transferReportMeta.current_page - 1)}
                onNext={() => loadReports(transferReportMeta.current_page + 1)}
                onExport={() => exportReport("transfers")}
                columns={[
                  ["transfer_no", "Transfer No"],
                  ["transfer_date", "Date"],
                  ["from_branch_name", "From"],
                  ["to_branch_name", "To"],
                  ["status", "Status", "status"],
                  ["total_quantity_requested", "Requested", "qty"],
                  ["total_quantity_approved", "Approved", "qty"],
                  ["total_quantity_dispatched", "Dispatched", "qty"],
                  ["total_quantity_received", "Received", "qty"],
                  ["total_quantity_variance", "Variance", "qty"],
                  ["total_cost_value", "Cost", "money_cost"],
                  ["total_selling_value", "Selling", "money_sell"],
                ]}
              />
            )}

            {activePanel === "line-report" && (
              <ReportTable
                title="Product Line Report"
                rows={lineReportRows}
                meta={lineReportMeta}
                onPrev={() => loadReports(lineReportMeta.current_page - 1)}
                onNext={() => loadReports(lineReportMeta.current_page + 1)}
                onExport={() => exportReport("lines")}
                columns={[
                  ["transfer_no", "Transfer No"],
                  ["transfer_date", "Date"],
                  ["from_branch_name", "From"],
                  ["to_branch_name", "To"],
                  ["product_name", "Product"],
                  ["sku", "SKU"],
                  ["quantity_requested", "Requested", "qty"],
                  ["quantity_approved", "Approved", "qty"],
                  ["quantity_dispatched", "Dispatched", "qty"],
                  ["quantity_received", "Received", "qty"],
                  ["quantity_variance", "Variance", "qty"],
                  ["cost_value", "Cost", "money_cost"],
                  ["selling_value", "Selling", "money_sell"],
                ]}
              />
            )}

            {activePanel === "branch-report" && (
              <ReportTable
                title="Branch Flow Report"
                rows={branchReport?.data || []}
                onExport={() => exportReport("branch")}
                columns={[
                  ["from_branch_name", "From Branch"],
                  ["to_branch_name", "To Branch"],
                  ["transfer_count", "Transfers"],
                  ["requested_quantity", "Requested", "qty"],
                  ["approved_quantity", "Approved", "qty"],
                  ["dispatched_quantity", "Dispatched", "qty"],
                  ["received_quantity", "Received", "qty"],
                  ["variance_quantity", "Variance", "qty"],
                  ["cost_value", "Cost", "money_cost"],
                  ["selling_value", "Selling", "money_sell"],
                ]}
              />
            )}

            {activePanel === "aging-report" && (
              <ReportTable
                title="Pending / Aging Report"
                rows={agingRows}
                meta={agingMeta}
                onPrev={() => loadReports(agingMeta.current_page - 1)}
                onNext={() => loadReports(agingMeta.current_page + 1)}
                onExport={() => exportReport("aging")}
                columns={[
                  ["transfer_no", "Transfer No"],
                  ["transfer_date", "Date"],
                  ["age_days", "Age Days"],
                  ["from_branch_name", "From"],
                  ["to_branch_name", "To"],
                  ["status", "Status", "status"],
                  ["total_quantity_approved", "Approved", "qty"],
                  ["total_quantity_dispatched", "Dispatched", "qty"],
                  ["total_quantity_received", "Received", "qty"],
                  ["total_cost_value", "Cost", "money_cost"],
                ]}
              />
            )}
          </main>
          {detailModalOpen && selectedTransfer?.id && <div className="fixed inset-0 z-[100] flex items-center justify-center bg-[#003637]/55 p-4">
            <div className="flex max-h-[92vh] w-full max-w-7xl flex-col overflow-hidden border border-[#ACB8B8] bg-white shadow-2xl">
              <div className="flex h-12 shrink-0 items-center justify-between bg-[#004142] px-4 text-white"><div><b className="text-[14px]">{selectedTransfer.transfer_no}</b><span className="ml-3 text-[11px] text-white/75">{selectedTransfer.from_warehouse_name || selectedTransfer.from_branch_name} → {selectedTransfer.to_warehouse_name || selectedTransfer.to_branch_name}</span></div><div className="flex gap-2">{canEditRequest(selectedTransfer.status) && <button type="button" onClick={() => { setDetailModalOpen(false); setRequestModalOpen(true); }} className="border border-white/40 px-3 py-1 text-[11px] font-bold">Edit Request</button>}<button type="button" onClick={() => setDetailModalOpen(false)} className="border border-white/40 px-3 py-1 text-[11px] font-bold">Close</button></div></div>
              <div className="min-h-0 overflow-auto bg-[#F6F8FA] p-3"><div className="space-y-3"><FlowStepper status={selectedTransfer.status} /><SelectedTransferPanel transfer={selectedTransfer} lines={selectedLines} setLines={setSelectedLines} loading={loading} runAction={runAction} stageChecks={stageChecks} setStageChecks={setStageChecks} /></div></div>
            </div>
          </div>}
          <ReusableMessageModal
            open={messageModal.open}
            type={messageModal.type}
            title={messageModal.title}
            message={messageModal.message}
            onClose={() => setMessageModal((current) => ({ ...current, open: false }))}
          />
        </div>
      </div>
    </div>
  );
}

function TransferLinesEntry({ lines, updateLine, removeLine, editable }) {
  return (
    <div className="border border-[#D7DFDF] bg-white">
      <div className="border-b border-[#D7DFDF] bg-[#F6F8FA] px-3 py-2 text-[12px] font-bold uppercase tracking-wide">
        Request Lines
      </div>

      <div className="overflow-auto">
        <table className="min-w-[950px] w-full border-collapse text-left text-[12px]">
          <thead className="bg-[#003637] text-white">
            <tr>
              <th className="px-3 py-2 font-semibold">Product</th>
              <th className="px-3 py-2 font-semibold">SKU</th>
              <th className="px-3 py-2 text-right font-semibold">Source Stock</th>
              <th className="px-3 py-2 text-right font-semibold">Request Qty</th>
              <th className="px-3 py-2 font-semibold">Notes</th>
              <th className="px-3 py-2 text-right font-semibold">Action</th>
            </tr>
          </thead>
          <tbody>
            {lines.map((line, index) => (
              <tr key={line.product_id} className="border-b border-[#D7DFDF]">
                <td className="px-3 py-2">{line.product_name}</td>
                <td className="px-3 py-2">{line.sku || line.code || "-"}</td>
                <td className="px-3 py-2 text-right">{qty(line.quantity_on_hand)}</td>
                <td className="px-3 py-2 text-right">
                  <input
                    type="number"
                    min="0"
                    step="0.0001"
                    value={line.quantity_requested}
                    disabled={!editable}
                    onChange={(e) => updateLine(index, "quantity_requested", e.target.value)}
                    className="h-8 w-28 border border-[#ACB8B8] px-2 text-right text-[12px] disabled:bg-[#E4E8E8]"
                  />
                </td>
                <td className="px-3 py-2">
                  <input
                    value={line.line_notes || ""}
                    disabled={!editable}
                    onChange={(e) => updateLine(index, "line_notes", e.target.value)}
                    className="h-8 w-full border border-[#ACB8B8] px-2 text-[12px] disabled:bg-[#E4E8E8]"
                  />
                </td>
                <td className="px-3 py-2 text-right">
                  <Button disabled={!editable} onClick={() => removeLine(index)} variant="danger">
                    Remove
                  </Button>
                </td>
              </tr>
            ))}

            {lines.length === 0 && (
              <tr>
                <td colSpan={6} className="px-3 py-8 text-center text-[#637877]">
                  Search and add products from the left panel.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function TransfersTable({ transfers, meta, loading, onOpen, onPrev, onNext }) {
  return (
    <div className="border border-[#D7DFDF] bg-white">
      <div className="border-b border-[#D7DFDF] bg-[#F6F8FA] px-3 py-2 text-[12px] font-bold uppercase tracking-wide">
        Transfer Worklist
      </div>

      <div className="overflow-auto">
        <table className="min-w-[1350px] w-full border-collapse text-left text-[12px]">
          <thead className="bg-[#003637] text-white">
            <tr>
              <th className="px-3 py-2 font-semibold">Transfer No</th>
              <th className="px-3 py-2 font-semibold">Date</th>
              <th className="px-3 py-2 font-semibold">From</th>
              <th className="px-3 py-2 font-semibold">To</th>
              <th className="px-3 py-2 font-semibold">Status</th>
              <th className="px-3 py-2 text-right font-semibold">Requested</th>
              <th className="px-3 py-2 text-right font-semibold">Approved</th>
              <th className="px-3 py-2 text-right font-semibold">Dispatched</th>
              <th className="px-3 py-2 text-right font-semibold">Received</th>
              <th className="px-3 py-2 text-right font-semibold">Cost</th>
              <th className="px-3 py-2 text-right font-semibold">Actions</th>
            </tr>
          </thead>
          <tbody>
            {transfers.map((transfer) => (
              <tr key={transfer.id} className="border-b border-[#D7DFDF] hover:bg-[#F6F8FA]">
                <td className="px-3 py-2 text-[#003636]">{transfer.transfer_no}</td>
                <td className="px-3 py-2">{transfer.transfer_date}</td>
                <td className="px-3 py-2"><b>{transfer.from_warehouse_name || "Unspecified warehouse"}</b><small className="block text-[#637877]">{transfer.from_branch_name}</small></td>
                <td className="px-3 py-2"><b>{transfer.to_warehouse_name || "Unspecified warehouse"}</b><small className="block text-[#637877]">{transfer.to_branch_name}</small></td>
                <td className="px-3 py-2"><StatusPill status={transfer.status} /></td>
                <td className="px-3 py-2 text-right">{qty(transfer.total_quantity_requested)}</td>
                <td className="px-3 py-2 text-right">{qty(transfer.total_quantity_approved)}</td>
                <td className="px-3 py-2 text-right">{qty(transfer.total_quantity_dispatched)}</td>
                <td className="px-3 py-2 text-right">{qty(transfer.total_quantity_received)}</td>
                <td className="px-3 py-2 text-right">{money(transfer.total_cost_value, transfer.cost_currency)}</td>
                <td className="px-3 py-2 text-right">
                  <Button onClick={() => onOpen(transfer)} disabled={loading}>
                    View
                  </Button>
                </td>
              </tr>
            ))}

            {transfers.length === 0 && (
              <tr>
                <td colSpan={11} className="px-3 py-8 text-center text-[#637877]">
                  No transfers found.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>

      {meta && (
        <div className="flex items-center justify-between border-t border-[#D7DFDF] px-3 py-2 text-[12px] font-semibold text-[#637877]">
          <span>Page {meta.current_page} of {meta.last_page} · {meta.total} records</span>
          <div className="flex gap-2">
            <Button disabled={meta.current_page <= 1} onClick={onPrev}>Previous</Button>
            <Button disabled={meta.current_page >= meta.last_page} onClick={onNext}>Next</Button>
          </div>
        </div>
      )}
    </div>
  );
}

function SelectedTransferPanel({
  transfer,
  lines,
  setLines,
  loading,
  runAction,
  stageChecks,
  setStageChecks,
}) {
  const updateLine = (index, key, value) => {
    setLines((current) =>
      current.map((line, lineIndex) =>
        lineIndex === index ? { ...line, [key]: value } : line
      )
    );
  };

  const totals = lines.reduce(
    (acc, line) => {
      acc.requested += Number(line.quantity_requested || 0);
      acc.approved += Number(line.quantity_approved || line.quantity_requested || 0);
      acc.dispatched += Number(line.quantity_dispatched || line.quantity_approved || line.quantity_requested || 0);
      acc.received += Number(line.quantity_received || line.quantity_dispatched || 0);
      acc.cost += Number(line.cost_value || 0);
      acc.selling += Number(line.selling_value || 0);
      return acc;
    },
    { requested: 0, approved: 0, dispatched: 0, received: 0, cost: 0, selling: 0 }
  );

  return (
    <div className="border border-[#D7DFDF] bg-white">
      <div className="border-b border-[#D7DFDF] bg-[#F6F8FA] px-3 py-2">
        <div className="flex items-center justify-between gap-3">
          <div>
            <div className="text-[12px] font-bold uppercase tracking-wide">Selected Transfer Flow</div>
            <div className="text-[11px] font-semibold text-[#637877]">
              {transfer?.transfer_no || "Select a transfer"} · {transfer?.from_warehouse_name || transfer?.from_branch_name || "-"} → {transfer?.to_warehouse_name || transfer?.to_branch_name || "-"}
            </div>
          </div>
          <StatusPill status={transfer?.status || "draft"} />
        </div>
      </div>

      {transfer?.id && (
        <div className="grid gap-3 border-b border-[#D7DFDF] p-3 xl:grid-cols-3">
          <StageChecklist
            stage="approve"
            checks={stageChecks}
            setChecks={setStageChecks}
            disabled={loading || !canApprove(transfer.status)}
          />
          <StageChecklist
            stage="dispatch"
            checks={stageChecks}
            setChecks={setStageChecks}
            disabled={loading || !canDispatch(transfer.status)}
          />
          <StageChecklist
            stage="receive"
            checks={stageChecks}
            setChecks={setStageChecks}
            disabled={loading || !canReceive(transfer.status)}
          />
        </div>
      )}

      <div className="grid gap-2 border-b border-[#D7DFDF] bg-white p-3 md:grid-cols-3 xl:grid-cols-6">
        <StatCard label="Requested" value={qty(totals.requested)} />
        <StatCard label="Approved" value={qty(totals.approved)} />
        <StatCard label="Dispatched" value={qty(totals.dispatched)} />
        <StatCard label="Received" value={qty(totals.received)} />
        <StatCard label="Cost Value" value={money(totals.cost, lines?.[0]?.cost_currency || transfer?.cost_currency || "")} />
        <StatCard label="Selling Value" value={money(totals.selling, lines?.[0]?.selling_currency || transfer?.selling_currency || "")} />
      </div>

      <div className="flex flex-wrap gap-2 border-b border-[#D7DFDF] p-3">
        <Button
          onClick={() => runAction("approve")}
          disabled={!transfer?.id || loading || !canApprove(transfer.status) || !allStageChecked(stageChecks, "approve")}
          variant="success"
        >
          Approve Checked Transfer
        </Button>
        <Button
          onClick={() => runAction("reject")}
          disabled={!transfer?.id || loading || !canApprove(transfer.status)}
          variant="danger"
        >
          Reject
        </Button>
        <Button
          onClick={() => runAction("dispatch")}
          disabled={!transfer?.id || loading || !canDispatch(transfer.status) || !allStageChecked(stageChecks, "dispatch")}
          variant="warning"
        >
          Dispatch Checked Stock
        </Button>
        <Button
          onClick={() => runAction("receive")}
          disabled={!transfer?.id || loading || !canReceive(transfer.status) || !allStageChecked(stageChecks, "receive")}
          variant="primary"
        >
          Receive Checked Stock
        </Button>
        <Button
          onClick={() => runAction("cancel")}
          disabled={!transfer?.id || loading || !canCancel(transfer.status)}
          variant="danger"
        >
          Cancel
        </Button>
      </div>

      <div className="overflow-auto">
        <table className="min-w-[1600px] w-full border-collapse text-left text-[12px]">
          <thead className="bg-[#003637] text-white">
            <tr>
              <th className="px-3 py-2 font-semibold">Product</th>
              <th className="px-3 py-2 font-semibold">SKU</th>
              <th className="px-3 py-2 text-right font-semibold">Requested</th>
              <th className="px-3 py-2 text-right font-semibold">Approved</th>
              <th className="px-3 py-2 text-right font-semibold">Dispatch</th>
              <th className="px-3 py-2 text-right font-semibold">Receive</th>
              <th className="px-3 py-2 text-right font-semibold">Variance</th>
              <th className="px-3 py-2 text-right font-semibold">Cost</th>
              <th className="px-3 py-2 text-right font-semibold">Selling</th>
            </tr>
          </thead>
          <tbody>
            {lines.map((line, index) => (
              <tr key={line.id || line.product_id} className="border-b border-[#D7DFDF] hover:bg-[#F6F8FA]">
                <td className="px-3 py-2">{line.product_name}</td>
                <td className="px-3 py-2">{line.sku || line.code || "-"}</td>
                <td className="px-3 py-2 text-right">{qty(line.quantity_requested)}</td>
                <td className="px-3 py-2 text-right">
                  <input
                    type="number"
                    value={line.quantity_approved ?? line.quantity_requested}
                    disabled={!canApprove(transfer?.status)}
                    onChange={(e) => updateLine(index, "quantity_approved", e.target.value)}
                    className="h-8 w-24 border border-[#ACB8B8] px-2 text-right text-[12px] disabled:bg-[#E4E8E8]"
                  />
                </td>
                <td className="px-3 py-2 text-right">
                  <input
                    type="number"
                    value={line.quantity_dispatched ?? line.quantity_approved ?? line.quantity_requested}
                    disabled={!canDispatch(transfer?.status)}
                    onChange={(e) => updateLine(index, "quantity_dispatched", e.target.value)}
                    className="h-8 w-24 border border-[#ACB8B8] px-2 text-right text-[12px] disabled:bg-[#E4E8E8]"
                  />
                </td>
                <td className="px-3 py-2 text-right">
                  <input
                    type="number"
                    value={line.quantity_received ?? line.quantity_dispatched ?? 0}
                    disabled={!canReceive(transfer?.status)}
                    onChange={(e) => updateLine(index, "quantity_received", e.target.value)}
                    className="h-8 w-24 border border-[#ACB8B8] px-2 text-right text-[12px] disabled:bg-[#E4E8E8]"
                  />
                </td>
                <td className="px-3 py-2 text-right">{qty(line.quantity_variance)}</td>
                <td className="px-3 py-2 text-right">{money(line.cost_value, line.cost_currency)}</td>
                <td className="px-3 py-2 text-right">{money(line.selling_value, line.selling_currency)}</td>
              </tr>
            ))}

            {lines.length === 0 && (
              <tr>
                <td colSpan={9} className="px-3 py-8 text-center text-[#637877]">
                  Select a transfer to see its lines.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function ReportTable({ title, rows = [], columns = [], meta, onPrev, onNext, onExport }) {
  const renderValue = (row, key, type) => {
    if (type === "qty") return qty(row[key]);
    if (type === "money_cost") return money(row[key], row.cost_currency);
    if (type === "money_sell") return money(row[key], row.selling_currency);
    if (type === "status") return <StatusPill status={row[key]} />;
    return row[key] ?? "-";
  };

  return (
    <div className="border border-[#D7DFDF] bg-white">
      <div className="flex items-center justify-between border-b border-[#D7DFDF] bg-[#F6F8FA] px-3 py-2">
        <div className="text-[12px] font-bold uppercase tracking-wide">{title}</div>
        <Button onClick={onExport}>Download Excel</Button>
      </div>

      <div className="overflow-auto">
        <table className="min-w-[1400px] w-full border-collapse text-left text-[12px]">
          <thead className="bg-[#003637] text-white">
            <tr>
              {columns.map(([key, label]) => (
                <th key={key} className="px-3 py-2 font-semibold">
                  {label}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {rows.map((row, index) => (
              <tr key={row.id || row.transfer_no || row.product_id || index} className="border-b border-[#D7DFDF] hover:bg-[#F6F8FA]">
                {columns.map(([key, , type]) => (
                  <td
                    key={key}
                    className={`px-3 py-2 ${type?.startsWith("money") || type === "qty" ? "text-right" : ""}`}
                  >
                    {renderValue(row, key, type)}
                  </td>
                ))}
              </tr>
            ))}

            {rows.length === 0 && (
              <tr>
                <td colSpan={columns.length} className="px-3 py-10 text-center text-[#637877]">
                  No report records found.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>

      {meta && (
        <div className="flex items-center justify-between border-t border-[#D7DFDF] px-3 py-2 text-[12px] font-semibold text-[#637877]">
          <span>
            Page {meta.current_page} of {meta.last_page} · {meta.total} records
          </span>
          <div className="flex gap-2">
            <Button disabled={meta.current_page <= 1} onClick={onPrev}>
              Previous
            </Button>
            <Button disabled={meta.current_page >= meta.last_page} onClick={onNext}>
              Next
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}
