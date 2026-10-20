import React, { useEffect, useMemo, useState } from "react";

import FullScreenModal from "../../../components/FullScreenModal";
import ReusableMessageModal from "../../../components/ReusableMessageModal";

import { getAuthorisedCompany } from "../../../services/AuthenticationService";
import { downloadStockAreaRegister, getBackStock, getFrontStock } from "../../../services/BohStockService";

const getPaginatorRows = (response) => {
  const payload = response?.data ?? response;

  if (Array.isArray(payload)) return payload;
  if (Array.isArray(payload?.data?.data)) return payload.data.data;
  if (Array.isArray(payload?.data)) return payload.data;

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

const getProductName = (row) => row?.product?.name || row?.product_name || row?.name || "-";
const getProductCode = (row) => row?.product?.code || row?.product?.sku || row?.product?.barcode || row?.product_code || "-";
const getCategoryName = (row) => row?.product?.category?.name || row?.category?.name || "-";
const getUomName = (row) => {
  const uom = row?.product?.uom || row?.uom;
  if (!uom) return "-";
  return `${uom.name || "UOM"}${uom.code ? ` (${uom.code})` : ""}`;
};
const getBranchName = (branch) => branch?.branch_name || branch?.name || "Unnamed Branch";
const getRowBranchName = (row) => row?.branch?.branch_name || row?.branch?.name || row?.branch_name || "-";
const getProductType = (row) => row?.product?.product_type || row?.product_type || "-";

const getRowCost = (row) => {
  if (row?.cost) return row.cost;
  if (row?.product_cost) return row.product_cost;

  const costs = Array.isArray(row?.product?.costs) ? row.product.costs : [];

  if (costs.length) {
    const branchCost = costs.find((cost) => String(cost.branch_id) === String(row.branch_id));
    return branchCost || costs[0];
  }

  return null;
};

const getAverageCost = (row) => row?.average_cost ?? getRowCost(row)?.average_cost ?? null;
const getLastCost = (row) => row?.last_cost ?? getRowCost(row)?.last_cost ?? null;
const getTotalValue = (row) => row?.total_value ?? getRowCost(row)?.total_value ?? null;
const getCurrency = (row) => row?.currency || getRowCost(row)?.currency || row?.product?.currency || "";

const getApiMessage = (source, fallback = "Request failed.") => {
  const responseData = source?.response?.data;

  if (responseData?.errors && typeof responseData.errors === "object") {
    return Object.values(responseData.errors).flat().join("\n");
  }

  if (responseData?.message) return responseData.message;
  if (responseData?.error) return responseData.error;
  if (source?.data?.message) return source.data.message;
  if (source?.message && !source?.isAxiosError) return source.message;

  return fallback;
};

function StockAreaRegisterModal({
  open,
  onClose,
  currentUser,
  area = "front",
  title = "FRONT STOCK",
  subtitle = "SERVENEST HOSPITALITY ENTERPRISE",
}) {
  const [rows, setRows] = useState([]);
  const [branches, setBranches] = useState([]);
  const [loading, setLoading] = useState(false);
  const [search, setSearch] = useState("");
  const [branchId, setBranchId] = useState("");
  const [typeFilter, setTypeFilter] = useState("");
  const [selectedId, setSelectedId] = useState(null);
  const [messageModal, setMessageModal] = useState({
    open: false,
    type: "success",
    title: "",
    message: "",
  });

  const stockGetter = area === "back" ? getBackStock : getFrontStock;
  const areaLabel = area === "back" ? "Back Stock" : "Front Stock";
  const areaShort = area === "back" ? "BOH" : "FRONT";
  const areaTone = area === "back" ? "#7C3AED" : "#027A48";

  const showMessage = ({ type = "success", title, message }) => {
    setMessageModal({ open: true, type, title, message });
  };

  const closeMessageModal = () => {
    setMessageModal((prev) => ({ ...prev, open: false }));
  };

  const getLoggedInBranchId = () => currentUser?.branch?.id || currentUser?.branch_id || currentUser?.branchId || "";

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
    if (!branchId && loggedBranchId) {
      setBranchId(loggedBranchId);
    }
  };

  const loadStock = async () => {
    try {
      setLoading(true);

      const params = {
        per_page: 100,
      };

      if (search.trim()) params.q = search.trim();
      if (branchId) params.branch_id = branchId;
      if (typeFilter) params.product_type = typeFilter;

      const response = await stockGetter(params);
      setRows(getPaginatorRows(response));
    } catch (error) {
      showMessage({
        type: "error",
        title: "Load Failed",
        message: getApiMessage(error, `Could not load ${areaLabel.toLowerCase()}.`),
      });
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
    if (!open) return;

    const timer = window.setTimeout(() => {
      loadStock();
    }, 250);

    return () => window.clearTimeout(timer);
  }, [open, search, branchId, typeFilter]);

  const filteredRows = useMemo(() => {
    const q = search.trim().toLowerCase();

    return rows.filter((row) => {
      const productName = getProductName(row).toLowerCase();
      const productCode = getProductCode(row).toLowerCase();
      const categoryName = getCategoryName(row).toLowerCase();
      const branchName = getRowBranchName(row).toLowerCase();
      const productType = getProductType(row).toLowerCase();

      const matchesSearch =
        !q ||
        productName.includes(q) ||
        productCode.includes(q) ||
        categoryName.includes(q) ||
        branchName.includes(q) ||
        productType.includes(q);

      const matchesType = !typeFilter || getProductType(row) === typeFilter;

      return matchesSearch && matchesType;
    });
  }, [rows, search, typeFilter]);

  const selectedRow = useMemo(() => rows.find((row) => String(row.id) === String(selectedId)) || null, [rows, selectedId]);

  const totals = useMemo(() => {
    return rows.reduce(
      (sum, row) => ({
        quantity_on_hand: sum.quantity_on_hand + Number(row.quantity_on_hand || 0),
        reserved_quantity: sum.reserved_quantity + Number(row.reserved_quantity || 0),
        available_quantity: sum.available_quantity + Number(row.available_quantity || 0),
      }),
      { quantity_on_hand: 0, reserved_quantity: 0, available_quantity: 0 }
    );
  }, [rows]);

  const lowStockCount = useMemo(() => {
    return rows.filter((row) => {
      const reorder = Number(row?.product?.reorder_level ?? row?.reorder_level ?? 0);
      const available = Number(row?.available_quantity || 0);
      return reorder > 0 && available <= reorder;
    }).length;
  }, [rows]);

  const downloadRegister = async (format) => {
    try {
      setLoading(true);
      await downloadStockAreaRegister(area, format, {
        q: search.trim(),
        branch_id: branchId,
        product_type: typeFilter,
      });
    } catch (error) {
      showMessage({
        type: "error",
        title: `${format === "pdf" ? "PDF" : "Excel"} Export Failed`,
        message: getApiMessage(error),
      });
    } finally {
      setLoading(false);
    }
  };

  const downloadExcel = () => downloadRegister("xlsx");
  const downloadPdf = () => downloadRegister("pdf");

  return (
    <>
      <FullScreenModal open={open} onClose={onClose} title={title} subtitle={subtitle}>
        <div className="stock-area-page h-[calc(100vh-86px)] min-h-0 w-full max-w-full overflow-hidden bg-[#D8DFDF] text-[#1C2C2B]">
          <div className="grid h-full min-h-0 grid-rows-[auto_auto_minmax(0,1fr)] gap-2 p-2">
            <div className="grid shrink-0 grid-cols-1 gap-2 xl:grid-cols-[300px_minmax(0,1fr)_300px]">
              <StockDeskPanel title={`${areaLabel} Card`}>
                <div className="grid grid-cols-[110px_1fr] items-center gap-1 text-[11px]">
                  <StockDeskLabel>Total Lines</StockDeskLabel>
                  <StockDeskField value={rows.length} disabled />
                  <StockDeskLabel>On Hand</StockDeskLabel>
                  <StockDeskField value={quantity(totals.quantity_on_hand)} disabled />
                  <StockDeskLabel>Reserved</StockDeskLabel>
                  <StockDeskField value={quantity(totals.reserved_quantity)} disabled />
                  <StockDeskLabel>Available</StockDeskLabel>
                  <StockDeskField value={quantity(totals.available_quantity)} disabled />
                  <StockDeskLabel>Low Stock</StockDeskLabel>
                  <StockDeskField value={lowStockCount} disabled />
                </div>
              </StockDeskPanel>

              <StockDeskPanel title={`${areaLabel} Search`}>
                <div className="grid grid-cols-[120px_1fr] items-center gap-1 text-[11px]">
                  <StockDeskLabel>Search</StockDeskLabel>
                  <input type="text" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search product, code, category, branch..." className="stock-desk-input" />

                  <StockDeskLabel>Branch</StockDeskLabel>
                  <select value={branchId} onChange={(event) => setBranchId(event.target.value)} className="stock-desk-input">
                    <option value="">All Branches</option>
                    {branches.map((branch) => <option key={branch.id} value={branch.id}>{getBranchName(branch)}</option>)}
                  </select>
                </div>
              </StockDeskPanel>

              <StockDeskPanel title="Stock Details">
                <div className="grid grid-cols-[90px_1fr] items-center gap-1 text-[11px]">
                  <StockDeskLabel>Area</StockDeskLabel>
                  <StockDeskField value={areaLabel} disabled />

                  <StockDeskLabel>Type</StockDeskLabel>
                  <select value={typeFilter} onChange={(event) => setTypeFilter(event.target.value)} className="stock-desk-input">
                    <option value="">All Types</option>
                    <option value="raw_material">Raw Material</option>
                    <option value="semi_finished">Semi Finished</option>
                    <option value="packaging">Packaging</option>
                    <option value="consumable">Consumable</option>
                    <option value="finished_good">Finished Good</option>
                    <option value="simple">Simple</option>
                  </select>

                  <StockDeskLabel>Selected</StockDeskLabel>
                  <StockDeskField value={selectedRow ? getProductName(selectedRow) : "None"} disabled />
                </div>
              </StockDeskPanel>
            </div>

            <div className="flex shrink-0 flex-wrap items-center justify-between gap-2 border border-[#B9C3C3] bg-[#EEF2F2] px-2 py-1">
              <div className="flex flex-wrap items-center gap-1">
                <StockDeskTab active>{areaLabel} Register</StockDeskTab>
                <span className="border border-[#CBD6D6] bg-white px-3 py-[5px] text-[11px] font-semibold text-[#465757]">
                  {loading ? "Loading..." : `${filteredRows.length} rows`}
                </span>
              </div>

              <div className="flex flex-wrap items-center gap-1">
                <StockDeskTool onClick={loadStock} disabled={loading}>{loading ? "Loading..." : "Refresh"}</StockDeskTool>
                <StockDeskTool onClick={downloadExcel}>Excel</StockDeskTool>
                <StockDeskTool onClick={downloadPdf}>PDF</StockDeskTool>
                <StockDeskTool onClick={() => { setSearch(""); setBranchId(getLoggedInBranchId()); setTypeFilter(""); }}>Clear</StockDeskTool>
              </div>
            </div>

            <div className="min-h-0 max-w-full overflow-auto border border-[#AFB9B9] bg-white stock-grid-scroll">
              <table className="sx-enterprise-table min-w-[1880px]">
                <thead>
                  <tr>
                    <th>Line</th>
                    <th>Product</th>
                    <th>Code</th>
                    <th>SKU</th>
                    <th>Barcode</th>
                    <th>Category</th>
                    <th>UOM</th>
                    <th>Branch</th>
                    <th>Type</th>
                    <th>Area</th>
                    <th className="text-right">On Hand</th>
                    <th className="text-right">Reserved</th>
                    <th className="text-right">Available</th>
                    <th className="text-right">Average Cost</th>
                    <th className="text-right">Last Cost</th>
                    <th className="text-right">Stock Value</th>
                    <th>Status</th>
                    <th className="w-12 text-center" aria-label="Actions">⋮</th>
                  </tr>
                </thead>
                <tbody>
                  {filteredRows.map((row, index) => {
                    const selected = String(selectedId) === String(row.id);
                    const available = Number(row.available_quantity || 0);
                    const reorder = Number(row?.product?.reorder_level ?? row?.reorder_level ?? 0);
                    const isLow = reorder > 0 && available <= reorder;
                    const status = row?.product?.is_active === false ? "Inactive" : isLow ? "Low Stock" : "Active";

                    return (
                      <tr key={row.id || `${row.product_id}-${row.branch_id}-${index}`} className={selected ? "sx-row-selected" : ""}>
                        <td>{index + 1}</td>
                        <td><strong>{getProductName(row)}</strong></td>
                        <td>{row?.product?.code || "-"}</td>
                        <td>{row?.product?.sku || "-"}</td>
                        <td>{row?.product?.barcode || "-"}</td>
                        <td>{getCategoryName(row)}</td>
                        <td>{getUomName(row)}</td>
                        <td>{getRowBranchName(row)}</td>
                        <td>{String(getProductType(row)).replaceAll("_", " ")}</td>
                        <td>{areaShort}</td>
                        <td className="text-right">{quantity(row.quantity_on_hand)}</td>
                        <td className="text-right">{quantity(row.reserved_quantity)}</td>
                        <td className="text-right">{quantity(row.available_quantity)}</td>
                        <td className="text-right">{money(getAverageCost(row), getCurrency(row))}</td>
                        <td className="text-right">{money(getLastCost(row), getCurrency(row))}</td>
                        <td className="text-right">{money(getTotalValue(row), getCurrency(row))}</td>
                        <td>{status}</td>
                        <td className="text-center">
                          <StockRowActions
                            label={`Actions for ${getProductName(row)}`}
                            actions={[{ label: "View stock details", onClick: () => setSelectedId(row.id) }]}
                          />
                        </td>
                      </tr>
                    );
                  })}
                  {!loading && filteredRows.length === 0 && (
                    <tr><td colSpan="18" className="py-8 text-center text-[#627474]">No {areaLabel.toLowerCase()} found.</td></tr>
                  )}
                  {loading && (
                    <tr><td colSpan="18" className="py-8 text-center text-[#627474]">Loading {areaLabel.toLowerCase()}...</td></tr>
                  )}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </FullScreenModal>

      <ReusableMessageModal open={messageModal.open} type={messageModal.type} title={messageModal.title} message={messageModal.message} onClose={closeMessageModal} />
      <StockAreaInlineStyles areaTone={areaTone} />
    </>
  );
}

export function FrontStockRegisterModal(props) {
  return (
    <StockAreaRegisterModal
      {...props}
      area="front"
      title="FRONT STOCK REGISTER"
      subtitle="Shop floor / selling stock"
    />
  );
}

export function BackStockRegisterModal(props) {
  return (
    <StockAreaRegisterModal
      {...props}
      area="back"
      title="BACK STOCK REGISTER"
      subtitle="BOH / stores stock"
    />
  );
}

export default StockAreaRegisterModal;

const StockDeskPanel = ({ title, children }) => (
  <div className="border border-[#AFB9B9] bg-white">
    <div className="border-b border-[#D8DFDF] bg-[#EEF2F2] px-2 py-1 text-[11px] font-semibold text-[#324443]">{title}</div>
    <div className="p-2">{children}</div>
  </div>
);

const StockDeskLabel = ({ children }) => <div className="text-[11px] text-[#465757]">{children}</div>;
const StockDeskField = ({ value, disabled = false }) => <input value={value ?? ""} disabled={disabled} readOnly className="stock-desk-field" />;
const StockDeskTab = ({ active, children }) => <button type="button" className={`h-7 border px-4 text-[11px] font-semibold ${active ? "border-[#7C8888] bg-white text-[#0C1B1B]" : "border-transparent bg-transparent text-[#465757]"}`}>{children}</button>;
const StockDeskTool = ({ children, onClick, disabled = false }) => <button type="button" disabled={disabled} onClick={onClick} className="h-7 border border-[#B9C3C3] bg-white px-3 text-[11px] font-semibold text-[#324443] disabled:opacity-50 hover:bg-[#F8FAFC]">{children}</button>;

const StockRowActions = ({ label, actions = [] }) => {
  const [open, setOpen] = useState(false);

  return (
    <div
      className="action-menu-wrap relative inline-flex"
      onClick={(event) => event.stopPropagation()}
      onBlur={(event) => {
        if (!event.currentTarget.contains(event.relatedTarget)) setOpen(false);
      }}
    >
      <button
        type="button"
        aria-label={label}
        aria-haspopup="menu"
        aria-expanded={open}
        onClick={() => setOpen((current) => !current)}
        className="flex h-7 w-7 items-center justify-center border border-[#B9C3C3] bg-white text-[18px] font-bold leading-none text-[#324443] hover:bg-[#F2F4F7]"
      >
        ⋮
      </button>
      {open && (
        <div role="menu" className="absolute right-0 top-8 z-[90] min-w-[160px] border border-[#96A5A4] bg-white p-1 text-left shadow-xl">
          {actions.map((action) => (
            <button
              key={action.label}
              type="button"
              role="menuitem"
              onClick={() => {
                setOpen(false);
                action.onClick?.();
              }}
              className="block h-8 w-full px-2 text-left text-[10px] font-semibold text-[#324443] hover:bg-[#F2F4F7]"
            >
              {action.label}
            </button>
          ))}
        </div>
      )}
    </div>
  );
};

const StockAreaInlineStyles = ({ areaTone }) => (
  <style>{`
    .stock-area-page button,
    .stock-area-page input,
    .stock-area-page select,
    .stock-area-page textarea {
      border-radius: 0 !important;
    }

    .stock-desk-input,
    .stock-desk-field {
      width: 100%;
      height: 1.75rem;
      border: 1px solid #B9C3C3;
      background: #ffffff;
      padding: 0 0.5rem;
      font-size: 11px;
      outline: none;
      color: #1C2C2B;
    }

    .stock-desk-input:focus {
      border-color: ${areaTone};
      box-shadow: inset 0 0 0 1px ${areaTone};
    }

    .stock-desk-field:disabled {
      background: #EEF2F2;
      opacity: 1;
    }


    .stock-grid-scroll {
      max-width: 100%;
      overflow-x: auto;
      overflow-y: auto;
      padding-bottom: 8px;
    }

    .stock-area-page {
      max-width: 100%;
    }
  `}</style>
);
