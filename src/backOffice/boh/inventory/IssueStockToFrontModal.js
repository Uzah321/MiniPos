import React, { useEffect, useMemo, useState } from "react";

import EnterpriseFormModal from "../../../components/EnterpriseFormModal";
import FullScreenModal from "../../../components/FullScreenModal";
import ReusableMessageModal from "../../../components/ReusableMessageModal";

import { getAuthorisedCompany } from "../../../services/AuthenticationService";
import { getBackStock, issueBackStockEnterprise } from "../../../services/BohStockService";

const ISSUE_TYPES = [
  {
    value: "front",
    label: "Issue to Front / Selling Stock",
    usage: "turnover",
    destination: "front",
    description: "Moves turnover stock from Back / BOH into Front / Selling stock.",
  },
  {
    value: "department",
    label: "Issue to Department Use",
    usage: "any",
    destination: "department",
    description: "Consumes stock from Back / BOH for a department such as Kitchen, Admin or Housekeeping.",
  },
  {
    value: "expense",
    label: "Issue to Operating Expense",
    usage: "any",
    destination: "expense",
    description: "Consumes non-turnover stock from Back / BOH for P&L expense reporting.",
  },
  {
    value: "production",
    label: "Issue to Production Use",
    usage: "any",
    destination: "production",
    description: "Consumes stock from Back / BOH for production or processing use.",
  },
  {
    value: "wastage",
    label: "Issue to Wastage / Spoilage",
    usage: "any",
    destination: "wastage",
    description: "Writes off stock from Back / BOH as wastage, damage or spoilage.",
  },
];

const destinationLabel = (value) => {
  const map = {
    front: "Front / Selling Stock",
    department: "Department Use",
    expense: "Operating Expense",
    production: "Production Use",
    wastage: "Wastage / Spoilage",
  };

  return map[value] || value || "-";
};

const emptyHeader = {
  issue_name: "",
  issue_type: "front",
  department_name: "",
  cost_center: "",
  requested_by: "",
  issue_purpose: "",
  notes: "",
};

const getCurrentUserName = (user) => {
  const source = user?.user || user?.data?.user || user || {};
  const joinedName = [source.first_name, source.last_name].filter(Boolean).join(" ").trim();

  return (
    source.name ||
    source.full_name ||
    source.display_name ||
    joinedName ||
    source.username ||
    source.email ||
    "Current user"
  );
};

const headerForCurrentUser = (user) => ({
  ...emptyHeader,
  requested_by: getCurrentUserName(user),
});

const createIssueIdempotencyKey = () => {
  if (typeof window !== "undefined" && window.crypto?.randomUUID) return window.crypto.randomUUID();
  return "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(/[xy]/g, (token) => {
    const random = Math.floor(Math.random() * 16);
    return (token === "x" ? random : (random & 0x3) | 0x8).toString(16);
  });
};

const getApiMessage = (source, fallback = "Request failed.") => {
  const responseData = source?.response?.data;

  if (responseData?.errors && typeof responseData.errors === "object") {
    return Object.values(responseData.errors).flat().join("\n");
  }

  if (responseData?.data && typeof responseData.data === "object") {
    const values = Object.values(responseData.data).flat();
    if (values.length > 0) return values.join("\n");
  }

  if (responseData?.message) return responseData.message;
  if (responseData?.error) return responseData.error;
  if (source?.data?.message) return source.data.message;
  if (source?.message && !source?.isAxiosError) return source.message;

  return fallback;
};

const getRows = (response) => {
  const payload = response?.data ?? response;

  if (Array.isArray(payload)) return payload;
  if (Array.isArray(payload?.data?.data)) return payload.data.data;
  if (Array.isArray(payload?.data)) return payload.data;
  if (Array.isArray(response?.data?.data)) return response.data.data;

  return [];
};

const quantity = (value) => {
  if (value === null || value === undefined || value === "") return "0.0000";
  return Number(value || 0).toFixed(4);
};

const money = (value, currency = "") => {
  if (value === null || value === undefined || value === "") return "-";
  return `${currency ? `${currency} ` : ""}${Number(value || 0).toFixed(4)}`;
};

const normalizeType = (value) =>
  String(value || "")
    .trim()
    .toLowerCase()
    .replace(/[\s-]+/g, "_");

const getProductName = (row) => row?.product?.name || row?.product_name || row?.name || "-";
const getProductCode = (row) => row?.product?.code || row?.product?.sku || row?.product?.barcode || row?.product_code || "-";
const getProductType = (row) => normalizeType(row?.product?.product_type || row?.product_type || "-");
const getCategoryName = (row) => row?.product?.category?.name || row?.category?.name || "-";
const getUomName = (row) => {
  const uom = row?.product?.uom || row?.uom || row?.base_uom;
  if (!uom) return row?.product?.uom_name || row?.uom_name || "-";
  return `${uom.name || "UOM"}${uom.code ? ` (${uom.code})` : ""}`;
};
const getBranchName = (branch) => branch?.branch_name || branch?.name || "Unnamed Branch";
const getRowBranchName = (row) => row?.branch?.branch_name || row?.branch?.name || row?.branch_name || "-";
const getCurrency = (row) => row?.currency || row?.cost?.currency || row?.product_cost?.currency || "";
const getAverageCost = (row) => row?.average_cost ?? row?.cost?.average_cost ?? row?.product_cost?.average_cost ?? null;
const getLastCost = (row) => row?.last_cost ?? row?.cost?.last_cost ?? row?.product_cost?.last_cost ?? null;

const isNonTurnoverStock = (row) => {
  const usage = normalizeType(row?.product?.stock_usage_type || row?.stock_usage_type || "");
  if (usage === "non_turnover") return true;
  if (usage === "turnover") return false;

  return ["consumable", "consumables"].includes(getProductType(row));
};

const selectedIssueType = (header) => ISSUE_TYPES.find((item) => item.value === header.issue_type) || ISSUE_TYPES[0];

function IssueStockToFrontModal({ open, onClose, currentUser }) {
  const [rows, setRows] = useState([]);
  const [branches, setBranches] = useState([]);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [search, setSearch] = useState("");
  const [branchId, setBranchId] = useState("");
  const [typeFilter, setTypeFilter] = useState("");
  const [header, setHeader] = useState(emptyHeader);
  const [headerStarted, setHeaderStarted] = useState(false);
  const [lineQty, setLineQty] = useState({});
  const [selectedId, setSelectedId] = useState(null);
  const [lineEditorRow, setLineEditorRow] = useState(null);
  const [lineEditorQuantity, setLineEditorQuantity] = useState("");
  const [reviewOpen, setReviewOpen] = useState(false);
  const [idempotencyKey, setIdempotencyKey] = useState(createIssueIdempotencyKey);
  const [messageModal, setMessageModal] = useState({
    open: false,
    type: "success",
    title: "",
    message: "",
  });

  const showMessage = ({ type = "success", title, message }) => {
    setMessageModal({ open: true, type, title, message });
  };

  const closeMessageModal = () => {
    setMessageModal((prev) => ({ ...prev, open: false }));
  };

  const getLoggedInBranchId = () => currentUser?.branch?.id || currentUser?.branch_id || currentUser?.branchId || "";

  useEffect(() => {
    if (!open) return;

    setHeader(headerForCurrentUser(currentUser));
    setHeaderStarted(false);
    setLineQty({});
    setSelectedId(null);
    setLineEditorRow(null);
    setLineEditorQuantity("");
    setReviewOpen(false);
    setSearch("");
    setTypeFilter("");
    setIdempotencyKey(createIssueIdempotencyKey());
  }, [open, currentUser]);

  const loadBranches = async () => {
    const response = await getAuthorisedCompany();

    const company = response?.company || response?.data?.company || null;
    const companyBranches =
      response?.company?.branches ||
      response?.data?.branches ||
      response?.data?.company?.branches ||
      company?.branches ||
      [];

    setBranches(companyBranches);

    const loggedBranchId = getLoggedInBranchId();
    if (!branchId && loggedBranchId) setBranchId(loggedBranchId);
  };

  const loadBackStock = async () => {
    try {
      setLoading(true);

      const params = { per_page: 200 };
      if (search.trim()) params.q = search.trim();
      if (branchId) params.branch_id = branchId;
      if (typeFilter) params.product_type = typeFilter;

      const response = await getBackStock(params);
      setRows(getRows(response));
    } catch (error) {
      showMessage({ type: "error", title: "Load Failed", message: getApiMessage(error, "Could not load back stock.") });
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (!open) return;

    loadBranches().catch((error) => {
      showMessage({ type: "error", title: "Branch Load Failed", message: getApiMessage(error) });
    });
  }, [open]);

  useEffect(() => {
    if (!open || !headerStarted) return;

    const timer = window.setTimeout(() => {
      loadBackStock();
    }, 250);

    return () => window.clearTimeout(timer);
  }, [open, headerStarted, search, branchId, typeFilter]);

  const issueType = selectedIssueType(header);

  const updateHeader = (patch) => {
    setHeader((prev) => ({ ...prev, ...patch }));
  };

  const eligibleRows = useMemo(() => {
    return rows.filter((row) => {
      const nonTurnover = isNonTurnoverStock(row);
      if (issueType.usage === "turnover" && nonTurnover) return false;
      if (issueType.usage === "non_turnover" && !nonTurnover) return false;
      return true;
    });
  }, [rows, issueType.usage]);

  const filteredRows = useMemo(() => {
    const q = search.trim().toLowerCase();

    return eligibleRows.filter((row) => {
      const productName = getProductName(row).toLowerCase();
      const productCode = getProductCode(row).toLowerCase();
      const categoryName = getCategoryName(row).toLowerCase();
      const branchName = getRowBranchName(row).toLowerCase();
      const productType = getProductType(row).toLowerCase();
      const uomName = getUomName(row).toLowerCase();

      const matchesSearch =
        !q ||
        productName.includes(q) ||
        productCode.includes(q) ||
        categoryName.includes(q) ||
        branchName.includes(q) ||
        productType.includes(q) ||
        uomName.includes(q);

      const matchesType = !typeFilter || productType === typeFilter;

      return matchesSearch && matchesType;
    });
  }, [eligibleRows, search, typeFilter]);

  const selectedRows = useMemo(() => {
    return eligibleRows.filter((row) => Number(lineQty[row.product_id] || 0) > 0);
  }, [eligibleRows, lineQty]);

  const totals = useMemo(() => {
    return eligibleRows.reduce(
      (sum, row) => {
        const selectedQty = Number(lineQty[row.product_id] || 0);
        const unitCost = Number(getAverageCost(row) || getLastCost(row) || 0);
        const value = selectedQty * unitCost;
        const availableQty = Number(row.available_quantity || 0);
        const availableValue = availableQty * unitCost;
        const nonTurnover = isNonTurnoverStock(row);

        return {
          available_quantity: sum.available_quantity + availableQty,
          available_value: sum.available_value + availableValue,
          selected_quantity: sum.selected_quantity + selectedQty,
          selected_value: sum.selected_value + value,
          selected_lines: sum.selected_lines + (selectedQty > 0 ? 1 : 0),
          turnover_lines: sum.turnover_lines + (!nonTurnover && selectedQty > 0 ? 1 : 0),
          non_turnover_lines: sum.non_turnover_lines + (nonTurnover && selectedQty > 0 ? 1 : 0),
        };
      },
      {
        available_quantity: 0,
        available_value: 0,
        selected_quantity: 0,
        selected_value: 0,
        selected_lines: 0,
        turnover_lines: 0,
        non_turnover_lines: 0,
      }
    );
  }, [eligibleRows, lineQty]);

  const setRowIssueQty = (row, value) => {
    const available = Number(row.available_quantity || 0);
    const numeric = value === "" ? "" : Math.max(0, Math.min(Number(value || 0), available));

    setLineQty((prev) => ({ ...prev, [row.product_id]: numeric }));
  };

  const openLineEditor = (row) => {
    setSelectedId(row.id);
    setLineEditorRow(row);
    setLineEditorQuantity(lineQty[row.product_id] ?? "");
  };

  const closeLineEditor = () => {
    setLineEditorRow(null);
    setLineEditorQuantity("");
  };

  const saveLineQuantity = () => {
    if (!lineEditorRow) return;

    const requested = Number(lineEditorQuantity || 0);
    const available = Number(lineEditorRow.available_quantity || 0);

    if (!Number.isFinite(requested) || requested < 0 || requested > available) {
      showMessage({
        type: "error",
        title: "Invalid Quantity",
        message: `Enter a quantity between 0 and ${quantity(available)} for ${getProductName(lineEditorRow)}.`,
      });
      return;
    }

    setRowIssueQty(lineEditorRow, requested);
    closeLineEditor();
  };

  const clearSelection = () => {
    setLineQty({});
    setSelectedId(null);
    closeLineEditor();
    setReviewOpen(false);
  };

  const resetAll = () => {
    setHeader(headerForCurrentUser(currentUser));
    setHeaderStarted(false);
    clearSelection();
    setSearch("");
    setTypeFilter("");
    setIdempotencyKey(createIssueIdempotencyKey());
  };

  const validateHeaderOnly = () => {
    if (!branchId) {
      showMessage({ type: "error", title: "Branch Required", message: "Select the branch you are issuing stock from." });
      return false;
    }

    if (!header.issue_name.trim()) {
      showMessage({ type: "error", title: "Issue Header Required", message: "Enter an issue name. This becomes the report header." });
      return false;
    }

    if (["department", "expense"].includes(issueType.destination) && !header.department_name.trim()) {
      showMessage({ type: "error", title: "Department Required", message: "Enter the department for this issue." });
      return false;
    }

    if (!header.issue_purpose.trim()) {
      showMessage({ type: "error", title: "Purpose Required", message: "Enter the purpose/reason for this stock issue." });
      return false;
    }

    return true;
  };

  const handleStartIssue = async () => {
    if (!validateHeaderOnly()) return;

    setHeaderStarted(true);
    await loadBackStock();
  };

  const handleChangeHeader = () => {
    setHeaderStarted(false);
    clearSelection();
  };

  const validateHeader = () => {
    if (!branchId) {
      showMessage({ type: "error", title: "Branch Required", message: "Select the branch you are issuing stock from." });
      return false;
    }

    if (!header.issue_name.trim()) {
      showMessage({ type: "error", title: "Issue Header Required", message: "Enter an issue name. This becomes the report header." });
      return false;
    }

    if (["department", "expense"].includes(issueType.destination) && !header.department_name.trim()) {
      showMessage({ type: "error", title: "Department Required", message: "Enter the department for this issue." });
      return false;
    }

    if (!header.issue_purpose.trim()) {
      showMessage({ type: "error", title: "Purpose Required", message: "Enter the purpose/reason for this stock issue." });
      return false;
    }

    return true;
  };

  const validateSelectedRows = () => {
    if (!validateHeader()) return false;

    if (selectedRows.length === 0) {
      showMessage({ type: "error", title: "No Items Selected", message: "Enter at least one issue quantity greater than zero." });
      return false;
    }

    for (const row of selectedRows) {
      const qty = Number(lineQty[row.product_id] || 0);
      const available = Number(row.available_quantity || 0);

      if (qty > available) {
        showMessage({ type: "error", title: "Invalid Quantity", message: `${getProductName(row)} cannot exceed available back stock.` });
        return false;
      }

      if (isNonTurnoverStock(row) && issueType.destination === "front") {
        showMessage({
          type: "error",
          title: "Wrong Issue Type",
          message: `${getProductName(row)} is non-turnover stock. Use Department, Operating Expense, Production or Wastage issue type.`,
        });
        return false;
      }
    }

    return true;
  };

  const buildPayload = () => ({
    idempotency_key: idempotencyKey,
    branch_id: branchId,
    issue_name: header.issue_name.trim(),
    issue_type: header.issue_type,
    issue_destination: issueType.destination,
    department_name: header.department_name || null,
    cost_center: header.cost_center || null,
    issue_purpose: header.issue_purpose || null,
    notes: header.notes || null,
    items: selectedRows.map((row) => ({
      product_id: row.product_id,
      quantity: Number(lineQty[row.product_id] || 0),
    })),
  });

  const handleOpenReview = () => {
    if (!validateSelectedRows()) return;
    setReviewOpen(true);
  };

  const handleSubmit = async () => {
    if (!validateSelectedRows()) return;

    try {
      setSaving(true);

      const response = await issueBackStockEnterprise(buildPayload());

      showMessage({
        type: "success",
        title: "Stock Issue Posted",
        message: getApiMessage(response, "Enterprise stock issue posted successfully."),
      });

      resetAll();
      await loadBackStock();
    } catch (error) {
      showMessage({ type: "error", title: "Issue Failed", message: getApiMessage(error, "Could not issue stock.") });
    } finally {
      setSaving(false);
    }
  };

  const selectedBranchName = branches.find((branch) => String(branch.id) === String(branchId));

  return (
    <>
      {open && !headerStarted && (
        <IssueHeaderStartModal
          header={header}
          issueType={issueType}
          branchId={branchId}
          branches={branches}
          loading={loading}
          onClose={onClose}
          onStart={handleStartIssue}
          onHeaderChange={updateHeader}
          onBranchChange={setBranchId}
        />
      )}

      {headerStarted && (
      <FullScreenModal open={open} onClose={onClose} title="ENTERPRISE STOCK ISSUE" subtitle="Header driven stock issue with clean reporting">
        <div className="issue-stock-page h-[calc(100vh-86px)] min-h-0 overflow-hidden bg-[#D8DFDF] text-[#1C2C2B]">
          <div className="grid h-full min-h-0 grid-rows-[auto_auto_minmax(0,1fr)] gap-2 p-2">
            <div className="flex shrink-0 flex-wrap items-center justify-between gap-2 border border-[#AFB9B9] bg-white px-3 py-2">
              <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                  <strong className="truncate text-[13px] text-[#0C1B1B]">{header.issue_name}</strong>
                  <span className="text-[10px] text-[#627474]">{selectedBranchName ? getBranchName(selectedBranchName) : "No branch"}</span>
                  <span className="border border-[#A4E5E4] bg-[#F1F8F8] px-2 py-1 text-[10px] font-semibold text-[#057B7B]">{destinationLabel(issueType.destination)}</span>
                </div>
                <div className="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-[10px] text-[#465757]">
                  <span><strong>Requested by:</strong> {header.requested_by}</span>
                  <span><strong>Purpose:</strong> {header.issue_purpose}</span>
                  {header.department_name && <span><strong>Department:</strong> {header.department_name}</span>}
                </div>
              </div>
              <div className="flex flex-wrap items-center gap-1">
                <CompactTotal label="Lines" value={totals.selected_lines} />
                <CompactTotal label="Quantity" value={quantity(totals.selected_quantity)} />
                <CompactTotal label="Value" value={money(totals.selected_value)} />
                <button type="button" onClick={handleChangeHeader} disabled={saving} className="h-8 border border-[#B9C3C3] bg-white px-3 text-[11px] font-semibold text-[#324443] hover:bg-[#F8FAFC] disabled:opacity-50">Edit Header</button>
              </div>
            </div>

            <div className="flex shrink-0 flex-wrap items-center justify-between gap-2 border border-[#B9C3C3] bg-[#EEF2F2] px-2 py-1">
              <div className="text-[11px] font-semibold text-[#324443]">Click a stock row to enter the issue quantity</div>

              <div className="flex flex-wrap items-center gap-1">
                <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search stock item..." className="issue-toolbar-input" />
                <select value={typeFilter} onChange={(e) => setTypeFilter(e.target.value)} className="issue-toolbar-select">
                  <option value="">All Types</option>
                  <option value="raw_material">Raw Material</option>
                  <option value="semi_finished">Semi Finished</option>
                  <option value="packaging">Packaging</option>
                  <option value="consumable">Consumable</option>
                  <option value="finished_good">Finished Good</option>
                  <option value="simple">Simple</option>
                </select>
                <IssueTool onClick={loadBackStock} disabled={loading || saving}>{loading ? "Loading..." : "Refresh"}</IssueTool>
                <IssueTool onClick={clearSelection} disabled={saving}>Clear Qty</IssueTool>
                <IssueTool onClick={handleOpenReview} disabled={saving || selectedRows.length === 0} green>Review Issue</IssueTool>
              </div>
            </div>

            <div className="grid min-h-0 grid-cols-1 gap-2 xl:grid-cols-[minmax(0,1fr)_320px]">
              <div className="min-h-0 overflow-auto border border-[#AFB9B9] bg-white">
                <table className="sx-enterprise-table min-w-[1050px]">
                  <thead>
                    <tr>
                      <th className="w-12 text-center">Line</th>
                      <th>Product</th>
                      <th>Code / SKU</th>
                      <th>Category</th>
                      <th>UOM</th>
                      <th>Stock Class</th>
                      <th className="text-right">Available</th>
                      <th className="text-right">Unit Cost</th>
                      <th className="text-right">Issue Qty</th>
                      <th className="text-right">Value</th>
                    </tr>
                  </thead>
                  <tbody>
                    {loading ? (
                      <tr><td colSpan="10" className="py-8 text-center text-[#627474]">Loading back stock...</td></tr>
                    ) : filteredRows.length === 0 ? (
                      <tr><td colSpan="10" className="py-8 text-center text-[#627474]">No back stock found for selected issue type.</td></tr>
                    ) : filteredRows.map((row, index) => {
                      const selected = String(selectedId) === String(row.id);
                      const qtyValue = lineQty[row.product_id] ?? "";
                      const available = Number(row.available_quantity || 0);
                      const unitCost = Number(getAverageCost(row) || getLastCost(row) || 0);
                      const lineValue = Number(qtyValue || 0) * unitCost;
                      const nonTurnover = isNonTurnoverStock(row);

                      return (
                        <tr
                          key={row.id || `${row.product_id}-${row.branch_id}-${index}`}
                          role="button"
                          tabIndex={available > 0 && !saving ? 0 : -1}
                          aria-label={`Set issue quantity for ${getProductName(row)}`}
                          aria-disabled={available <= 0 || saving}
                          onClick={() => available > 0 && !saving && openLineEditor(row)}
                          onKeyDown={(event) => {
                            if ((event.key === "Enter" || event.key === " ") && available > 0 && !saving) {
                              event.preventDefault();
                              openLineEditor(row);
                            }
                          }}
                          className={`cursor-pointer focus:outline focus:outline-2 focus:outline-[#069494] ${available <= 0 ? "cursor-not-allowed opacity-60" : "hover:bg-[#F1F8F8]"} ${selected ? "sx-row-selected" : ""}`}
                        >
                          <td className="text-center text-[#627474]">{index + 1}</td>
                          <td><strong>{getProductName(row)}</strong><div className="max-w-[300px] truncate text-[10px] font-normal text-[#627474]">{row?.product?.description || row?.description || ""}</div></td>
                          <td><div>{row?.product?.code || row?.product_code || "-"}</div><div className="text-[10px] text-[#627474]">{row?.product?.sku || row?.product_sku || "-"}</div></td>
                          <td>{getCategoryName(row)}</td>
                          <td>{getUomName(row)}</td>
                          <td>{nonTurnover ? "Non-Turnover" : "Turnover"}</td>
                          <td className={available <= 0 ? "text-right font-semibold text-[#B42318]" : "text-right"}>{quantity(available)}</td>
                          <td className="text-right">{money(unitCost, getCurrency(row))}</td>
                          <td className="text-right font-semibold text-[#008585]">{qtyValue === "" ? "Click to set" : quantity(qtyValue)}</td>
                          <td className="text-right">{money(lineValue, getCurrency(row))}</td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>

              <div className="min-h-0 overflow-hidden border border-[#AFB9B9] bg-white">
                <div className="border-b border-[#D8DFDF] bg-[#EEF2F2] px-2 py-1 text-[11px] font-semibold text-[#324443]">Issue Basket</div>
                <div className="flex h-[calc(100%-29px)] min-h-0 flex-col p-2">
                  <div className="mb-2 grid grid-cols-3 border border-[#D8DFDF] bg-[#F8FAFC] text-center">
                    <CompactTotal label="Items" value={selectedRows.length} borderless />
                    <CompactTotal label="Quantity" value={quantity(totals.selected_quantity)} borderless />
                    <CompactTotal label="Value" value={money(totals.selected_value)} borderless />
                  </div>

                  <div className="mt-2 min-h-0 flex-1 overflow-auto border border-[#E4EAEA] bg-white">
                    {selectedRows.length === 0 ? (
                      <div className="flex h-28 items-center justify-center text-[12px] text-[#627474]">No items selected.</div>
                    ) : (
                      <table className="sx-enterprise-table min-w-[480px]">
                        <thead><tr><th>Product</th><th className="text-right">Available</th><th className="text-right">Issue Qty</th><th className="text-right">Value</th></tr></thead>
                        <tbody>
                          {selectedRows.map((row) => (
                            <tr key={`selected-${row.product_id}`}>
                              <td><strong>{getProductName(row)}</strong></td>
                              <td className="text-right">{quantity(row.available_quantity)}</td>
                              <td className="text-right">{quantity(lineQty[row.product_id])}</td>
                              <td className="text-right">{money(Number(lineQty[row.product_id] || 0) * Number(getAverageCost(row) || getLastCost(row) || 0), getCurrency(row))}</td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    )}
                  </div>

                  <button type="button" disabled={saving || selectedRows.length === 0} onClick={handleOpenReview} className="mt-2 h-9 border border-[#057B7C] bg-[#069495] px-3 text-[12px] font-semibold uppercase text-white disabled:opacity-50">
                    Review and Post Issue
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </FullScreenModal>
      )}

      <EnterpriseFormModal
        open={Boolean(lineEditorRow)}
        title={Number(lineQty[lineEditorRow?.product_id] || 0) > 0 ? "Edit Issue Quantity" : "Add Stock Item to Issue"}
        subtitle="Set the quantity to issue from Back Stock"
        onClose={() => !saving && closeLineEditor()}
        busy={saving}
        width="560px"
      >
        <form onSubmit={(event) => { event.preventDefault(); saveLineQuantity(); }} className="space-y-3 p-4">
          <div className="border border-[#CCD7D6] bg-[#F8FAFC] p-3">
            <div className="text-[13px] font-bold text-[#0C1B1B]">{getProductName(lineEditorRow)}</div>
            <div className="mt-2 grid grid-cols-2 gap-2">
              <IssueInfo label="Stock Class" value={lineEditorRow && isNonTurnoverStock(lineEditorRow) ? "Non-Turnover" : "Turnover"} />
              <IssueInfo label="Destination" value={destinationLabel(issueType.destination)} />
              <IssueInfo label="Available in Back Stock" value={quantity(lineEditorRow?.available_quantity)} />
              <IssueInfo label="Unit" value={getUomName(lineEditorRow)} />
            </div>
          </div>
          <Field label="Issue Quantity">
            <input
              type="number"
              min="0"
              max={Number(lineEditorRow?.available_quantity || 0)}
              step="0.0001"
              value={lineEditorQuantity}
              onChange={(event) => setLineEditorQuantity(event.target.value)}
              className="issue-input h-9"
              autoFocus
            />
          </Field>
          <div className="flex flex-wrap items-center justify-between gap-2 border-t border-[#DAE2E2] pt-3">
            <button type="button" onClick={() => setLineEditorQuantity(lineEditorRow?.available_quantity || 0)} className="h-8 border border-[#069494] bg-white px-3 text-[11px] font-bold text-[#008585]">Use Full Available</button>
            <div className="flex gap-2">
              <button type="button" onClick={closeLineEditor} className="h-8 border border-[#ACB8B8] bg-white px-4 text-[11px] font-bold">Cancel</button>
              <button type="submit" className="h-8 border border-[#057B7C] bg-[#069495] px-4 text-[11px] font-bold text-white">Save Quantity</button>
            </div>
          </div>
        </form>
      </EnterpriseFormModal>

      {reviewOpen && (
        <div className="issue-review-backdrop">
          <div className="issue-review-modal">
            <div className="issue-review-header">
              <div>
                <h2>Review Stock Issue</h2>
                <p>{header.issue_name} | {issueType.label}</p>
              </div>
              <button type="button" onClick={() => setReviewOpen(false)} disabled={saving}>x</button>
            </div>
            <div className="issue-review-body">
              <div className="issue-review-summary">
                <ReviewBox label="Branch" value={selectedBranchName ? getBranchName(selectedBranchName) : "-"} />
                <ReviewBox label="Department" value={header.department_name || "-"} />
                <ReviewBox label="Cost Center" value={header.cost_center || "-"} />
                <ReviewBox label="Purpose" value={header.issue_purpose || "-"} />
                <ReviewBox label="Lines" value={selectedRows.length} />
                <ReviewBox label="Issue Value" value={money(totals.selected_value)} />
              </div>
              <div className="issue-review-table overflow-auto">
                <table className="sx-enterprise-table">
                  <thead><tr><th>Stock Item</th><th className="text-right">Qty</th><th className="text-right">Value</th></tr></thead>
                  <tbody>
                    {selectedRows.map((row) => (
                      <tr key={`review-${row.product_id}`}>
                        <td>{getProductName(row)}</td>
                        <td className="text-right">{quantity(lineQty[row.product_id])}</td>
                        <td className="text-right">{money(Number(lineQty[row.product_id] || 0) * Number(getAverageCost(row) || getLastCost(row) || 0), getCurrency(row))}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
            <div className="issue-review-footer">
              <button type="button" onClick={() => setReviewOpen(false)} disabled={saving} className="issue-cancel-button">Back</button>
              <button type="button" onClick={handleSubmit} disabled={saving} className="issue-post-button">{saving ? "Posting..." : "Post Stock Issue"}</button>
            </div>
          </div>
        </div>
      )}

      <ReusableMessageModal open={messageModal.open} type={messageModal.type} title={messageModal.title} message={messageModal.message} onClose={closeMessageModal} />
      <IssueInlineStyles />
    </>
  );
}

export default IssueStockToFrontModal;

const IssueHeaderStartModal = ({
  header,
  issueType,
  branchId,
  branches,
  loading,
  onClose,
  onStart,
  onHeaderChange,
  onBranchChange,
}) => (
  <div className="issue-start-backdrop">
    <div className="issue-start-modal">
      <div className="issue-start-header">
        <div>
          <span>Enterprise Stock Issue</span>
          <h2>New stock issue</h2>
          <p>Add the issue details, then select stock items.</p>
        </div>
        <button type="button" onClick={onClose}>x</button>
      </div>

      <div className="issue-start-body">
        <div className="issue-start-form">
            <div className="issue-start-section-title">Issue Document Header</div>
            <div className="issue-start-fields">
              <Field label="Issue Name" span="8">
                <input value={header.issue_name} onChange={(e) => onHeaderChange({ issue_name: e.target.value })} placeholder="e.g. Kitchen cleaning materials issue" className="issue-input issue-start-input" autoFocus />
              </Field>

              <Field label="Issue Type" span="4">
                <select value={header.issue_type} onChange={(e) => onHeaderChange({ issue_type: e.target.value })} className="issue-input issue-start-input">
                  {ISSUE_TYPES.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}
                </select>
              </Field>

              <Field label="Branch" span="6">
                <select value={branchId} onChange={(e) => onBranchChange(e.target.value)} className="issue-input issue-start-input">
                  <option value="">Select Branch</option>
                  {branches.map((branch) => <option key={branch.id} value={branch.id}>{getBranchName(branch)}</option>)}
                </select>
              </Field>

              <Field label="Department" span="6">
                <input value={header.department_name} onChange={(e) => onHeaderChange({ department_name: e.target.value })} placeholder="Kitchen / Admin / Housekeeping" className="issue-input issue-start-input" />
              </Field>

              <Field label="Cost Center" span="6">
                <input value={header.cost_center} onChange={(e) => onHeaderChange({ cost_center: e.target.value })} placeholder="Optional cost center" className="issue-input issue-start-input" />
              </Field>

              <Field label="Requested By" span="6">
                <input value={header.requested_by} readOnly aria-readonly="true" title="Requester is the signed-in user" className="issue-input issue-start-input issue-readonly-input" />
              </Field>

              <Field label="Purpose" span="6">
                <input value={header.issue_purpose} onChange={(e) => onHeaderChange({ issue_purpose: e.target.value })} placeholder="Reason for issue" className="issue-input issue-start-input" />
              </Field>

              <Field label="Header Notes" span="12">
                <textarea value={header.notes} onChange={(e) => onHeaderChange({ notes: e.target.value })} placeholder="Optional notes to print on the stock issue report header" className="issue-start-notes" />
              </Field>

              <div className="issue-span-12 flex flex-wrap items-center gap-x-4 gap-y-1 border border-[#A4E5E4] bg-[#F1F8F8] px-3 py-2 text-[10px] text-[#057B7B]">
                <strong>{issueType.label}</strong>
                <span>Destination: {destinationLabel(issueType.destination)}</span>
                <span>{issueType.description}</span>
              </div>
            </div>
          </div>
      </div>

      <div className="issue-start-footer">
        <button type="button" onClick={onClose} className="issue-cancel-button">Cancel</button>
        <button type="button" onClick={onStart} disabled={loading} className="issue-post-button">{loading ? "Loading..." : "Continue to Stock Items"}</button>
      </div>
    </div>
    <IssueInlineStyles />
  </div>
);

const Field = ({ label, children, span = "12" }) => <div className={`issue-field-wrap issue-span-${span}`}><label>{label}</label>{children}</div>;
const ReviewBox = ({ label, value }) => <div className="issue-review-box"><span>{label}</span><strong>{value}</strong></div>;
const IssueTool = ({ children, onClick, disabled = false, green = false }) => <button type="button" disabled={disabled} onClick={onClick} className={`h-7 border px-3 text-[11px] font-semibold disabled:opacity-50 ${green ? "border-[#057B7C] bg-[#069495] text-white" : "border-[#B9C3C3] bg-white text-[#324443] hover:bg-[#F8FAFC]"}`}>{children}</button>;
const CompactTotal = ({ label, value, borderless = false }) => (
  <div className={`${borderless ? "px-2 py-2" : "border border-[#CFD6D6] bg-[#F8FAFC] px-2 py-1"} min-w-0`}>
    <span className="mr-1 text-[9px] uppercase text-[#627474]">{label}</span>
    <strong className="text-[10px] tabular-nums text-[#0C1B1B]">{value}</strong>
  </div>
);
const IssueInfo = ({ label, value }) => <div className="flex justify-between gap-2 border-b border-[#EFF2F2] py-[2px]"><span className="text-[10px] uppercase text-[#637877]">{label}</span><span className="text-right text-[10px] font-semibold text-[#003636]">{value}</span></div>;

const IssueInlineStyles = () => (
  <style>{`
    .issue-stock-page button,.issue-stock-page input,.issue-stock-page select,.issue-stock-page textarea { border-radius: 0 !important; }
    .issue-input,.issue-toolbar-input,.issue-toolbar-select { width: 100%; height: 1.75rem; border: 1px solid #B9C3C3; background: #ffffff; padding: 0 0.5rem; font-size: 11px; outline: none; color: #1C2C2B; }
    .issue-toolbar-input { width: 240px; }
    .issue-toolbar-select { width: 150px; }
    .issue-input:focus,.issue-toolbar-input:focus,.issue-toolbar-select:focus { border-color: #069495; box-shadow: inset 0 0 0 1px #069495; }
    .issue-readonly-input { background:#EEF2F2; color:#324443; cursor:not-allowed; }
    .issue-field-wrap label { display:block; font-size:10px; color:#637877; text-transform:uppercase; margin-bottom:3px; }
    .issue-span-3 { grid-column: span 3 / span 3; } .issue-span-4 { grid-column: span 4 / span 4; } .issue-span-6 { grid-column: span 6 / span 6; } .issue-span-8 { grid-column: span 8 / span 8; } .issue-span-12 { grid-column: span 12 / span 12; }
    .issue-review-box span { display:block; font-size:9px; text-transform:uppercase; color:#627474; }
    .issue-review-box strong { display:block; margin-top:3px; font-size:11px; color:#0C1B1B; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .issue-review-backdrop { position:fixed; inset:0; z-index:100000; background:rgba(0,52,52,.62); display:flex; align-items:center; justify-content:center; padding:14px; }
    .issue-review-modal { width:min(980px,96vw); max-height:90vh; background:#fff; border:1px solid #9BA5A5; display:flex; flex-direction:column; box-shadow:0 20px 60px rgba(0,0,0,.35); }
    .issue-review-header { min-height:54px; background:#004142; color:white; padding:10px 12px; display:flex; align-items:center; justify-content:space-between; }
    .issue-review-header h2 { margin:0; font-size:15px; font-weight:700; } .issue-review-header p { margin:3px 0 0; font-size:11px; color:rgba(255,255,255,.78); }
    .issue-review-header button { width:32px; height:32px; border:1px solid rgba(255,255,255,.35); background:transparent; color:white; font-size:18px; }
    .issue-review-body { padding:10px; overflow:auto; background:#EEF2F2; }
    .issue-review-summary { display:grid; grid-template-columns:repeat(6,minmax(0,1fr)); gap:6px; margin-bottom:8px; }
    .issue-review-box { border:1px solid #CBD6D6; background:white; padding:8px; min-height:50px; }
    .issue-review-table { border:1px solid #CBD6D6; background:white; }
    .issue-review-row { display:grid; grid-template-columns:minmax(0,1fr) 140px 160px; border-bottom:1px solid #E4EAEA; font-size:11px; }
    .issue-review-row span { padding:7px; border-right:1px solid #E4EAEA; }
    .issue-review-row-head { background:#F8FAFC; font-weight:700; text-transform:uppercase; color:#465757; font-size:10px; }
    .issue-review-footer { min-height:48px; border-top:1px solid #CBD6D6; background:#F8FAFC; display:flex; justify-content:flex-end; gap:8px; padding:8px 10px; }
    .issue-cancel-button,.issue-post-button { height:32px; border:1px solid #93A6A6; padding:0 14px; font-size:11px; font-weight:700; text-transform:uppercase; }
    .issue-cancel-button { background:white; color:#324444; } .issue-post-button { background:#069495; border-color:#057B7C; color:white; }

    .issue-start-backdrop { position: fixed; inset: 0; z-index: 100000; background: rgba(0,52,52,.62); display: flex; align-items: center; justify-content: center; padding: 16px; }
    .issue-start-modal { width: min(900px, 96vw); max-height: 92vh; background: #ffffff; border: 1px solid #9BA5A5; display: flex; flex-direction: column; box-shadow: 0 22px 65px rgba(0,0,0,.38); }
    .issue-start-header { min-height: 74px; background: #004142; color: #ffffff; border-bottom: 3px solid #069495; padding: 13px 16px; display: flex; align-items: center; justify-content: space-between; gap: 16px; }
    .issue-start-header span { display: block; font-size: 10px; font-weight: 800; letter-spacing: .22em; text-transform: uppercase; color: #93F5F4; }
    .issue-start-header h2 { margin: 3px 0 0; font-size: 19px; font-weight: 800; }
    .issue-start-header p { margin: 4px 0 0; font-size: 12px; color: rgba(255,255,255,.78); }
    .issue-start-header button { width: 34px; height: 34px; border: 1px solid rgba(255,255,255,.35); background: transparent; color: #ffffff; font-size: 18px; }
    .issue-start-body { min-height: 0; overflow: auto; background: #EEF2F2; padding: 12px; }
    .issue-start-form { border: 1px solid #AFB9B9; background: #ffffff; }
    .issue-start-section-title { border-bottom: 1px solid #D8DFDF; background: #F8FAFC; padding: 8px 10px; font-size: 11px; font-weight: 800; text-transform: uppercase; color: #324443; letter-spacing: .08em; }
    .issue-start-fields { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 10px; padding: 10px; }
    .issue-start-input { height: 34px; font-size: 12px; }
    .issue-start-notes { min-height: 62px; width: 100%; border: 1px solid #B9C3C3; padding: .55rem; font-size: 12px; outline: none; resize: vertical; }
    .issue-start-notes:focus { border-color: #069495; box-shadow: inset 0 0 0 1px #069495; }
    .issue-start-footer { min-height: 52px; border-top: 1px solid #CBD6D6; background: #F8FAFC; display: flex; align-items: center; justify-content: flex-end; gap: 8px; padding: 9px 12px; }

    @media (max-width: 900px) { .issue-span-3,.issue-span-4,.issue-span-6,.issue-span-8 { grid-column: span 12 / span 12; } .issue-review-summary { grid-template-columns:repeat(2,minmax(0,1fr)); } }
  `}</style>
);
