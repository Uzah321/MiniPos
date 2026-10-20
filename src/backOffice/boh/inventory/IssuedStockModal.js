import React, { useCallback, useEffect, useMemo, useState } from "react";
import { Download, FileText, RefreshCw, Search, X } from "lucide-react";

import EnterpriseFormModal from "../../../components/EnterpriseFormModal";
import FullScreenModal from "../../../components/FullScreenModal";
import {
  downloadIssuedStockPdf,
  getIssuedStock,
  getIssuedStockDetail,
} from "../../../services/IssuedStockService";

const localDate = (date = new Date()) => {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  return `${year}-${month}-${day}`;
};

const initialFilters = () => {
  const now = new Date();
  return {
    date_from: localDate(new Date(now.getFullYear(), now.getMonth(), 1)),
    date_to: localDate(now),
    search: "",
    issue_destination: "",
  };
};

const getBranchId = (user) =>
  user?.selectedBranchId ||
  user?.selectedBranch?.id ||
  user?.selected_branch_id ||
  user?.branch_id ||
  user?.branch?.id ||
  "";

const getRows = (response) => {
  if (Array.isArray(response)) return response;
  if (Array.isArray(response?.data)) return response.data;
  if (Array.isArray(response?.data?.data)) return response.data.data;
  return [];
};

const getMeta = (response) => response?.meta || response?.data?.meta || {};
const getDetail = (response) => response?.data?.data || response?.data || response || null;

const errorMessage = (error, fallback) =>
  error?.response?.data?.message || error?.response?.data?.error || error?.message || fallback;

const destinationLabel = (value) => {
  const labels = {
    front: "Front / Selling Stock",
    department: "Department Use",
    expense: "Operating Expense",
    production: "Production Use",
    wastage: "Wastage / Spoilage",
  };
  return labels[String(value || "").toLowerCase()] || value || "-";
};

const formatDateTime = (value) => {
  if (!value) return "-";
  const parsed = new Date(value);
  if (Number.isNaN(parsed.getTime())) return String(value);
  return parsed.toLocaleString("en-GB", {
    day: "2-digit",
    month: "short",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
};

const quantity = (value) => Number(value || 0).toFixed(4);
const money = (value, currency = "USD") => `${currency || "USD"} ${Number(value || 0).toFixed(2)}`;

const safeFilename = (value) =>
  String(value || "issued-stock")
    .trim()
    .replace(/[^a-z0-9_-]+/gi, "-")
    .replace(/^-+|-+$/g, "") || "issued-stock";

export default function IssuedStockModal({ open, onClose, currentUser }) {
  const [filters, setFilters] = useState(initialFilters);
  const [appliedFilters, setAppliedFilters] = useState(initialFilters);
  const [rows, setRows] = useState([]);
  const [meta, setMeta] = useState({});
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(false);
  const [notice, setNotice] = useState("");
  const [selectedIssueId, setSelectedIssueId] = useState("");
  const [detail, setDetail] = useState(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [downloading, setDownloading] = useState(false);

  const branchId = getBranchId(currentUser);

  const loadRegister = useCallback(
    async (nextFilters, nextPage) => {
      try {
        setLoading(true);
        setNotice("");
        const response = await getIssuedStock({
          ...nextFilters,
          ...(branchId ? { branch_id: branchId } : {}),
          page: nextPage,
          per_page: 25,
        });
        setRows(getRows(response));
        setMeta(getMeta(response));
      } catch (error) {
        setRows([]);
        setMeta({});
        setNotice(errorMessage(error, "Could not load issued stock."));
      } finally {
        setLoading(false);
      }
    },
    [branchId]
  );

  useEffect(() => {
    if (!open) return;
    const defaults = initialFilters();
    setFilters(defaults);
    setAppliedFilters(defaults);
    setPage(1);
    setRows([]);
    setMeta({});
    setNotice("");
    setSelectedIssueId("");
    setDetail(null);
    loadRegister(defaults, 1);
  }, [open, loadRegister]);

  const applyFilters = async (event) => {
    event?.preventDefault();
    if (filters.date_from && filters.date_to && filters.date_from > filters.date_to) {
      setNotice("The From date cannot be later than the To date.");
      return;
    }

    const nextFilters = { ...filters, search: filters.search.trim() };
    setAppliedFilters(nextFilters);
    setPage(1);
    await loadRegister(nextFilters, 1);
  };

  const changePage = async (nextPage) => {
    if (nextPage < 1 || nextPage > Number(meta.last_page || 1) || nextPage === page) return;
    setPage(nextPage);
    await loadRegister(appliedFilters, nextPage);
  };

  const openDetail = async (row) => {
    const issueId = row?.issue_id || row?.id;
    if (!issueId) return;

    setSelectedIssueId(issueId);
    setDetail(null);
    setDetailLoading(true);
    setNotice("");
    try {
      const response = await getIssuedStockDetail(issueId, branchId ? { branch_id: branchId } : {});
      setDetail(getDetail(response));
    } catch (error) {
      setNotice(errorMessage(error, "Could not load this stock issue."));
      setSelectedIssueId("");
    } finally {
      setDetailLoading(false);
    }
  };

  const closeDetail = () => {
    if (downloading) return;
    setSelectedIssueId("");
    setDetail(null);
  };

  const downloadPdf = async () => {
    if (!selectedIssueId) return;
    try {
      setDownloading(true);
      setNotice("");
      const issueNumber = detail?.issue_number || detail?.issue_id || selectedIssueId;
      await downloadIssuedStockPdf(
        selectedIssueId,
        branchId ? { branch_id: branchId } : {},
        `${safeFilename(issueNumber)}.pdf`
      );
    } catch (error) {
      setNotice(errorMessage(error, "Could not download the issued stock PDF."));
    } finally {
      setDownloading(false);
    }
  };

  const summary = meta?.summary || {};
  const totalRecords = Number(meta?.total ?? rows.length ?? 0);
  const totalPages = Number(meta?.last_page || 1);
  const currentPage = Number(meta?.current_page || page || 1);
  const detailLines = Array.isArray(detail?.lines) ? detail.lines : [];

  const resultLabel = useMemo(() => {
    if (loading) return "Loading issued stock...";
    if (totalRecords === 1) return "1 issued stock document";
    return `${totalRecords} issued stock documents`;
  }, [loading, totalRecords]);

  return (
    <>
      <FullScreenModal open={open} onClose={onClose} title="ISSUED STOCK" subtitle="Search and reopen posted stock issue documents">
        <div className="relative flex h-full min-h-0 flex-col bg-[#F3F7F6] text-[11px] text-[#122424]">
          {notice && (
            <div role="alert" className="absolute left-1/2 top-3 z-50 flex max-w-[680px] -translate-x-1/2 items-center gap-3 border border-[#B42318] bg-white px-4 py-2 text-[#B42318] shadow-xl">
              <span className="min-w-0 flex-1">{notice}</span>
              <button type="button" aria-label="Dismiss message" onClick={() => setNotice("")}><X className="h-4 w-4" /></button>
            </div>
          )}

          <form onSubmit={applyFilters} className="shrink-0 border-b border-[#CBD6D6] bg-white p-3">
            <div className="grid items-end gap-2 lg:grid-cols-[160px_160px_220px_minmax(260px,1fr)_auto]">
              <FilterField label="From date">
                <input type="date" value={filters.date_from} onChange={(event) => setFilters((current) => ({ ...current, date_from: event.target.value }))} className="issued-filter" />
              </FilterField>
              <FilterField label="To date">
                <input type="date" value={filters.date_to} onChange={(event) => setFilters((current) => ({ ...current, date_to: event.target.value }))} className="issued-filter" />
              </FilterField>
              <FilterField label="Where issued">
                <select value={filters.issue_destination} onChange={(event) => setFilters((current) => ({ ...current, issue_destination: event.target.value }))} className="issued-filter">
                  <option value="">All destinations</option>
                  <option value="front">Front / Selling Stock</option>
                  <option value="department">Department Use</option>
                  <option value="expense">Operating Expense</option>
                  <option value="production">Production Use</option>
                  <option value="wastage">Wastage / Spoilage</option>
                </select>
              </FilterField>
              <FilterField label="Issue number, header or requester">
                <div className="flex h-9 border border-[#BAC5C5] bg-white focus-within:border-[#069494]">
                  <span className="flex w-9 items-center justify-center border-r border-[#E2E9E9]"><Search className="h-4 w-4 text-[#637877]" /></span>
                  <input value={filters.search} onChange={(event) => setFilters((current) => ({ ...current, search: event.target.value }))} placeholder="Search issued stock" className="min-w-0 flex-1 px-2 outline-none" />
                </div>
              </FilterField>
              <button type="submit" disabled={loading} className="h-9 border border-[#008585] bg-[#069494] px-5 font-semibold text-white disabled:opacity-50">
                {loading ? "Loading..." : "Apply Filters"}
              </button>
            </div>
          </form>

          <div className="flex shrink-0 flex-wrap items-center justify-between gap-2 border-b border-[#CBD6D6] bg-[#F8FAFC] px-3 py-2">
            <div>
              <div className="font-semibold text-[#0C1B1B]">{resultLabel}</div>
              <div className="text-[10px] text-[#627474]">Click an issue number or anywhere on a row to view its header and issued items.</div>
            </div>
            <div className="flex items-center gap-2">
              {summary?.total_quantity !== undefined && <RegisterStat label="Quantity" value={quantity(summary.total_quantity)} />}
              {summary?.total_value !== undefined && <RegisterStat label="Value" value={money(summary.total_value, summary.currency_code)} />}
              <button type="button" onClick={() => loadRegister(appliedFilters, page)} disabled={loading} className="inline-flex h-8 items-center gap-1 border border-[#AEB9B9] bg-white px-3 font-semibold disabled:opacity-50">
                <RefreshCw className={`h-3.5 w-3.5 ${loading ? "animate-spin" : ""}`} /> Refresh
              </button>
            </div>
          </div>

          <div className="min-h-0 flex-1 overflow-auto p-3">
            <div className="min-h-full border border-[#CBD6D6] bg-white">
              <table className="sx-enterprise-table w-full min-w-[1180px]">
                <thead>
                  <tr>
                    <th>Issue Number</th>
                    <th>Date</th>
                    <th>Issue Header</th>
                    <th>Where Issued</th>
                    <th>Requested By</th>
                    <th className="text-right">Lines</th>
                    <th className="text-right">Quantity</th>
                    <th className="text-right">Value</th>
                  </tr>
                </thead>
                <tbody>
                  {loading ? (
                    <tr><td colSpan="8" className="py-14 text-center text-[#627474]">Loading issued stock...</td></tr>
                  ) : rows.length === 0 ? (
                    <tr><td colSpan="8" className="py-14 text-center text-[#627474]">No issued stock was found for this date range.</td></tr>
                  ) : rows.map((row) => (
                    <tr
                      key={row.issue_id || row.id}
                      role="button"
                      tabIndex={0}
                      aria-label={`View stock issue ${row.issue_number || row.issue_id}`}
                      onClick={() => openDetail(row)}
                      onKeyDown={(event) => {
                        if (event.key === "Enter" || event.key === " ") {
                          event.preventDefault();
                          openDetail(row);
                        }
                      }}
                      className="cursor-pointer focus:outline focus:outline-2 focus:outline-[#069494] hover:bg-[#F1F8F8]"
                    >
                      <td>
                        <button type="button" onClick={(event) => { event.stopPropagation(); openDetail(row); }} className="font-bold text-[#008585] underline underline-offset-2">
                          {row.issue_number || row.issue_id || "View issue"}
                        </button>
                      </td>
                      <td className="whitespace-nowrap">{formatDateTime(row.posted_at || row.created_at)}</td>
                      <td>
                        <div className="font-semibold text-[#0C1B1B]">{row.issue_name || "Stock Issue"}</div>
                        <div className="mt-0.5 max-w-[360px] truncate text-[10px] text-[#627474]">{row.issue_purpose || row.notes || "No purpose recorded"}</div>
                      </td>
                      <td>
                        <div className="font-medium">{destinationLabel(row.issue_destination)}</div>
                        <div className="text-[10px] text-[#627474]">{row.department_name || row.branch_name || "-"}</div>
                      </td>
                      <td>{row.requested_by || row.created_by_name || "-"}</td>
                      <td className="text-right tabular-nums">{row.line_count || 0}</td>
                      <td className="text-right tabular-nums">{quantity(row.total_quantity)}</td>
                      <td className="text-right font-semibold tabular-nums">{money(row.total_value, row.currency_code)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>

          <div className="flex h-11 shrink-0 items-center justify-between border-t border-[#CBD6D6] bg-white px-3">
            <span className="text-[#627474]">Page {currentPage} of {totalPages}</span>
            <div className="flex gap-1">
              <button type="button" onClick={() => changePage(currentPage - 1)} disabled={loading || currentPage <= 1} className="h-7 border border-[#AEB9B9] bg-white px-3 font-semibold disabled:opacity-40">Previous</button>
              <button type="button" onClick={() => changePage(currentPage + 1)} disabled={loading || currentPage >= totalPages} className="h-7 border border-[#AEB9B9] bg-white px-3 font-semibold disabled:opacity-40">Next</button>
            </div>
          </div>
        </div>
      </FullScreenModal>

      <EnterpriseFormModal
        open={Boolean(selectedIssueId)}
        title={detail?.issue_number ? `Issued Stock ${detail.issue_number}` : "Issued Stock Details"}
        subtitle="Posted issue header and stock lines"
        onClose={closeDetail}
        busy={downloading}
        width="1120px"
      >
        <div className="max-h-[78vh] overflow-auto bg-[#F3F7F6] p-3 text-[11px] text-[#122424]">
          {detailLoading ? (
            <div className="flex h-48 items-center justify-center text-[#627474]"><RefreshCw className="mr-2 h-5 w-5 animate-spin" /> Loading issue details...</div>
          ) : detail ? (
            <>
              <div className="mb-3 grid gap-2 border border-[#CBD6D6] bg-white p-3 md:grid-cols-2 lg:grid-cols-4">
                <DetailValue label="Issue Header" value={detail.issue_name || "Stock Issue"} />
                <DetailValue label="Posted" value={formatDateTime(detail.posted_at || detail.created_at)} />
                <DetailValue label="Where Issued" value={destinationLabel(detail.issue_destination)} />
                <DetailValue label="Branch" value={detail.branch_name || "-"} />
                <DetailValue label="Requested By" value={detail.requested_by || detail.created_by_name || "-"} />
                <DetailValue label="Department" value={detail.department_name || "-"} />
                <DetailValue label="Cost Center" value={detail.cost_center || "-"} />
                <DetailValue label="Purpose" value={detail.issue_purpose || "-"} />
              </div>

              {detail.notes && <div className="mb-3 border border-[#CBD6D6] bg-white p-3"><div className="text-[9px] font-semibold uppercase text-[#627474]">Header Notes</div><div className="mt-1 whitespace-pre-wrap">{detail.notes}</div></div>}

              <div className="overflow-auto border border-[#CBD6D6] bg-white">
                <table className="sx-enterprise-table w-full min-w-[900px]">
                  <thead><tr><th>Product</th><th>Code / SKU</th><th>Stock Class</th><th>From</th><th>To</th><th className="text-right">Quantity</th><th>UOM</th><th className="text-right">Unit Cost</th><th className="text-right">Line Value</th></tr></thead>
                  <tbody>
                    {detailLines.length === 0 ? (
                      <tr><td colSpan="9" className="py-10 text-center text-[#627474]">No issue lines are available.</td></tr>
                    ) : detailLines.map((line) => (
                      <tr key={line.movement_id || `${line.product_id}-${line.product_code}`}>
                        <td className="font-semibold">{line.product_name || "-"}</td>
                        <td><div>{line.product_code || "-"}</div><div className="text-[10px] text-[#627474]">{line.product_sku || "-"}</div></td>
                        <td>{String(line.stock_usage_type || "-").replaceAll("_", " ")}</td>
                        <td>{line.from_stock_area || "Back"}</td>
                        <td>{destinationLabel(line.to_stock_area || line.issue_destination)}</td>
                        <td className="text-right tabular-nums">{quantity(line.issued_quantity || line.quantity)}</td>
                        <td>{line.uom_code || "EACH"}</td>
                        <td className="text-right tabular-nums">{money(line.unit_cost, line.currency_code)}</td>
                        <td className="text-right font-semibold tabular-nums">{money(line.total_cost, line.currency_code)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              <div className="mt-3 flex flex-wrap items-center justify-between gap-2 border border-[#CBD6D6] bg-white p-3">
                <div>
                  <div className="font-semibold">{detailLines.length} issued line{detailLines.length === 1 ? "" : "s"}</div>
                  <div className="text-[10px] text-[#627474]">Total {quantity(detail.total_quantity)} · {money(detail.total_value, detail.currency_code)}</div>
                </div>
                <button type="button" onClick={downloadPdf} disabled={downloading} className="inline-flex h-9 items-center gap-2 border border-[#008585] bg-[#069494] px-4 font-semibold text-white disabled:opacity-50">
                  <Download className="h-4 w-4" /> {downloading ? "Preparing PDF..." : "Download PDF"}
                </button>
              </div>
            </>
          ) : (
            <div className="flex h-48 flex-col items-center justify-center text-[#627474]"><FileText className="mb-2 h-7 w-7" />Issue details are unavailable.</div>
          )}
        </div>
      </EnterpriseFormModal>

      <style>{`
        .issued-filter { height: 2.25rem; width: 100%; border: 1px solid #BAC5C5; background: white; padding: 0 .55rem; outline: none; }
        .issued-filter:focus { border-color: #069494; box-shadow: inset 0 0 0 1px #069494; }
      `}</style>
    </>
  );
}

const FilterField = ({ label, children }) => <label><span className="mb-1 block text-[9px] font-semibold uppercase tracking-wide text-[#637877]">{label}</span>{children}</label>;

const RegisterStat = ({ label, value }) => (
  <div className="border border-[#CBD6D6] bg-white px-3 py-1">
    <span className="mr-2 text-[9px] uppercase text-[#627474]">{label}</span>
    <strong className="tabular-nums">{value}</strong>
  </div>
);

const DetailValue = ({ label, value }) => (
  <div className="min-w-0 border-b border-[#EEF2F2] pb-2">
    <div className="text-[9px] font-semibold uppercase text-[#627474]">{label}</div>
    <div className="mt-1 break-words font-semibold text-[#0C1B1B]">{value}</div>
  </div>
);
