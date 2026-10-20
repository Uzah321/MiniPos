// src/backOffice/pages/reports/EnterpriseTaxReportModal.jsx

import React, { useEffect, useMemo, useState } from "react";
import {
  AlertTriangle,
  BadgeCheck,
  ChevronLeft,
  ChevronRight,
  Download,
  FileText,
  Filter,
  ReceiptText,
  RefreshCw,
  Search,
  ShieldCheck,
  X,
} from "lucide-react";
import FullScreenModal from "../../../components/FullScreenModal";
import HospitalityReportScopePanel, {
  useHospitalityReportLookups,
} from "../../../components/HospitalityReportScopePanel";
import ManagementReportNavigator, {
  MANAGEMENT_REPORT_KEYS,
} from "../../../components/ManagementReportNavigator";
import ReportGenerationPrompt from "../../../components/ReportGenerationPrompt";
import ReportOutputChooser from "../../../components/ReportOutputChooser";
import ReportViewShell from "../../../components/ReportViewShell";
import {
  getEnterpriseTaxSummary,
  getEnterpriseTaxByTax,
  getEnterpriseTaxDetails,
  getZimraReceiptRegister,
  getZimraReceiptDetail,
  downloadEnterpriseTaxReport,
} from "../../../services/TaxReportService";

const today = new Date().toISOString().slice(0, 10);

const defaultFilters = {
  date_from: today,
  date_to: today,
  branch_id: "",
  currency_code: "",
  tax_code: "",
  obliged_to_zimra: "",
  is_inclusive: "",
  receipt_type: "",
  status: "",
  sale_type: "",
  q: "",
  per_page: 50,
};

const TAX_REPORT_VIEWS = [
  { key: "overview", label: "Overview", description: "Tax totals, currency mix and control indicators" },
  { key: "tax", label: "Tax Summary", description: "Taxable values and tax collected by code" },
  { key: "lines", label: "Tax Lines", description: "Transaction-level tax detail" },
  { key: "zimra", label: "ZIMRA Register", description: "Fiscal receipt and FDMS audit register" },
];

const cx = (...classes) => classes.filter(Boolean).join(" ");

const safeArray = (value) => (Array.isArray(value) ? value : []);

const unwrapPaginatorRows = (payload) => {
  if (Array.isArray(payload)) return payload;
  if (Array.isArray(payload?.data)) return payload.data;
  if (Array.isArray(payload?.data?.data)) return payload.data.data;
  return [];
};

const apiRows = (response) => unwrapPaginatorRows(response?.data);

const getPaginator = (response) => {
  const d = response?.data;
  if (d && typeof d === "object" && Array.isArray(d.data)) return d;
  return null;
};

const toNumber = (value) => {
  const n = Number(value);
  return Number.isFinite(n) ? n : 0;
};

const money = (value, currency = "") => {
  const n = toNumber(value);
  const formatted = n.toLocaleString(undefined, {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });

  return `${currency ? `${currency} ` : ""}${formatted}`;
};

const formatDate = (value) => {
  if (!value) return "-";
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? "-" : date.toLocaleString();
};

const boolText = (value) => {
  if (value === true || value === 1 || value === "1") return "Yes";
  if (value === false || value === 0 || value === "0") return "No";
  return value ?? "-";
};

const err = (error) =>
  error?.response?.data?.message ||
  error?.response?.data?.error ||
  error?.message ||
  "Request failed.";

const parseJson = (value) => {
  if (!value) return {};
  if (typeof value === "object") return value;

  try {
    return JSON.parse(value);
  } catch {
    return {};
  }
};

export default function EnterpriseTaxReportModal({
  open,
  onClose,
  businessModeLabel = "Hospitality",
  onNavigateReport,
}) {
  const [activeTab, setActiveTab] = useState("overview");
  const [filters, setFilters] = useState(defaultFilters);
  const [notice, setNotice] = useState("");
  const [loading, setLoading] = useState(false);
  const [exporting, setExporting] = useState(false);
  const [generated, setGenerated] = useState(false);
  const [appliedParams, setAppliedParams] = useState(null);
  const [chooserOpen, setChooserOpen] = useState(false);
  const [chooserMode, setChooserMode] = useState("generate");

  const [summary, setSummary] = useState(null);
  const [byTax, setByTax] = useState([]);
  const [details, setDetails] = useState([]);
  const [detailsPaginator, setDetailsPaginator] = useState(null);
  const [zimraReceipts, setZimraReceipts] = useState([]);
  const [zimraPaginator, setZimraPaginator] = useState(null);
  const [detail, setDetail] = useState(null);
  const lookups = useHospitalityReportLookups(open, filters);

  const params = useMemo(() => {
    const clean = {};

    Object.entries(filters).forEach(([key, value]) => {
      if (value !== "" && value !== null && value !== undefined) {
        clean[key] = value;
      }
    });

    return clean;
  }, [filters]);

  useEffect(() => {
    if (!open) return;
    setGenerated(false);
    setSummary(null);
    setByTax([]);
    setDetails([]);
    setDetailsPaginator(null);
    setZimraReceipts([]);
    setZimraPaginator(null);
    setDetail(null);
    setNotice("");
    setAppliedParams(null);
    setChooserOpen(false);
  }, [open]);

  if (!open) return null;

  const show = (message) => {
    setNotice(message);
    window.setTimeout(() => setNotice(""), 4500);
  };

  const clearResults = () => {
    setGenerated(false);
    setAppliedParams(null);
    setSummary(null);
    setByTax([]);
    setDetails([]);
    setDetailsPaginator(null);
    setZimraReceipts([]);
    setZimraPaginator(null);
    setDetail(null);
  };

  const update = (key, value) => {
    clearResults();
    setFilters((prev) => ({ ...prev, [key]: value }));
  };

  const resetFilters = () => {
    clearResults();
    setFilters(defaultFilters);
  };

  async function loadAll(extraParams = {}, sourceParams = params) {
    const snapshot = { ...sourceParams, ...extraParams };
    try {
      setLoading(true);

      const [summaryRes, byTaxRes, detailsRes, zimraRes] = await Promise.all([
        getEnterpriseTaxSummary(snapshot),
        getEnterpriseTaxByTax(snapshot),
        getEnterpriseTaxDetails(snapshot),
        getZimraReceiptRegister(snapshot),
      ]);

      setSummary(summaryRes?.data || null);
      setByTax(safeArray(byTaxRes?.data?.rows));
      setDetails(apiRows(detailsRes));
      setDetailsPaginator(getPaginator(detailsRes));
      setZimraReceipts(apiRows(zimraRes));
      setZimraPaginator(getPaginator(zimraRes));
      setAppliedParams(snapshot);
      setGenerated(true);
      setChooserOpen(false);
      return true;
    } catch (error) {
      clearResults();
      setChooserOpen(false);
      show(err(error));
      return false;
    } finally {
      setLoading(false);
    }
  }

  async function loadPage(page) {
    if (!page || loading) return;
    await loadAll({ page }, appliedParams || params);
  }

  async function openReceipt(id) {
    try {
      setLoading(true);
      const response = await getZimraReceiptDetail(id);
      setDetail(response?.data || null);
    } catch (error) {
      setChooserOpen(false);
      show(err(error));
    } finally {
      setLoading(false);
    }
  }

  const download = async (report, format = "xlsx") => {
    try {
      setExporting(true);
      await downloadEnterpriseTaxReport(report, format, appliedParams || params);
      setChooserOpen(false);
    } catch (error) {
      show(err(error));
    } finally {
      setExporting(false);
    }
  };

  const totals = summary?.totals || {};
  const byCurrency = safeArray(summary?.by_currency || summary?.currency_totals);
  const openChooser = (mode = "generate") => {
    setChooserMode(mode);
    setChooserOpen(true);
  };

  return (
    <FullScreenModal
      open={open}
      onClose={onClose}
      title="Enterprise Tax Report"
      subtitle="Tax analytics, ZIMRA receipt register and FDMS audit detail"
    >
      <div className="relative h-full min-h-0 overflow-hidden bg-[#F3F5F8] text-[#1C2C2B]">
        {notice && (
          <div className="absolute left-1/2 top-4 z-50 -translate-x-1/2 border border-[#004343] bg-[#004344] px-4 py-2 text-[12px] font-medium text-white shadow-xl">
            {notice}
          </div>
        )}

        <div className="flex h-full min-h-0">
          <ManagementReportNavigator
            activeKey={MANAGEMENT_REPORT_KEYS.TAX}
            onNavigate={onNavigateReport}
            businessModeLabel={businessModeLabel}
            groups={[
              {
                title: "Tax report views",
                items: TAX_REPORT_VIEWS,
                activeKey: activeTab,
                onChange: setActiveTab,
              },
            ]}
          />
          <div className="min-h-0 min-w-0 flex-1 overflow-hidden p-3">
            {!generated ? (
            <div className="h-full overflow-y-auto">
            <Filters
              filters={filters}
              setFilters={(next) => {
                clearResults();
                setFilters(next);
              }}
              update={update}
              resetFilters={resetFilters}
              loading={loading}
              loadAll={() => openChooser("generate")}
              lookups={lookups}
              businessModeLabel={businessModeLabel}
            />

            <div className="mt-3 min-h-0">
              <ReportGenerationPrompt
                title="Configure Tax Reports"
                message="Choose the reporting scope and tax filters, then select Generate to choose View, PDF or Excel."
              />
            </div>
            </div>
            ) : (
            <ReportViewShell
              title={TAX_REPORT_VIEWS.find((item) => item.key === activeTab)?.label || "Tax Report"}
              subtitle={`${appliedParams?.date_from || ""} to ${appliedParams?.date_to || ""}`}
              onBackToFilters={() => setGenerated(false)}
              busy={loading || exporting}
              actions={(
                <>
                  <button
                    type="button"
                    onClick={() => loadAll({ page: 1 }, appliedParams || params)}
                    disabled={loading || exporting}
                    className="inline-flex h-8 items-center gap-1 border border-[#ABB2B2] bg-white px-3 text-[11px] font-semibold text-[#324443] disabled:opacity-60"
                  >
                    <RefreshCw className={`h-3.5 w-3.5 ${loading ? "animate-spin" : ""}`} /> Refresh
                  </button>
                  <button
                    type="button"
                    onClick={() => openChooser("download")}
                    disabled={loading || exporting}
                    className="inline-flex h-8 items-center gap-1 border border-[#027A48] bg-white px-3 text-[11px] font-semibold text-[#027A48] disabled:opacity-60"
                  >
                    <Download className="h-3.5 w-3.5" /> Download
                  </button>
                </>
              )}
            >
            <div className="h-full min-h-0 p-3">
            {activeTab === "overview" ? (
              <Overview
                totals={totals}
                byTax={byTax}
                byCurrency={byCurrency}
                setActiveTab={setActiveTab}
              />
            ) : activeTab === "tax" ? (
              <TaxSummary
                rows={byTax}
              />
            ) : activeTab === "lines" ? (
              <TaxLines
                rows={details}
                paginator={detailsPaginator}
                loadPage={loadPage}
              />
            ) : (
              <ZimraRegister
                rows={zimraReceipts}
                paginator={zimraPaginator}
                loadPage={loadPage}
                openDetail={openReceipt}
              />
            )}
            </div>
            </ReportViewShell>
            )}
          </div>
        </div>

        {detail && <ReceiptDetail detail={detail} onClose={() => setDetail(null)} />}
      </div>
      <ReportOutputChooser
        open={chooserOpen}
        reportTitle={TAX_REPORT_VIEWS.find((item) => item.key === activeTab)?.label || "Tax Report"}
        description={chooserMode === "download" ? "Choose a backend-generated download format." : "View the report or download it directly from the backend."}
        busy={loading || exporting}
        onClose={() => setChooserOpen(false)}
        onView={chooserMode === "generate" ? () => loadAll({ page: 1 }, params) : undefined}
        onDownloadPdf={() => download(activeTab, "pdf")}
        onDownloadExcel={() => download(activeTab, "xlsx")}
        outputs={chooserMode === "download" ? { view: false } : undefined}
      />
    </FullScreenModal>
  );
}

function Filters({ filters, setFilters, update, resetFilters, loading, loadAll, lookups, businessModeLabel }) {
  const setScopeFilters = (next) => {
    setFilters((current) => {
      const currencyCodes = Array.isArray(next.currency_codes)
        ? next.currency_codes.filter(Boolean)
        : [];
      const currencyCode = currencyCodes.length
        ? currencyCodes.find((code) => code !== current.currency_code) || currencyCodes[currencyCodes.length - 1]
        : next.currency_code ?? current.currency_code;

      return {
        ...current,
        branch_id: next.branch_id || "",
        date_from: next.date_from ?? current.date_from,
        date_to: next.date_to ?? current.date_to,
        currency_code: currencyCode || "",
      };
    });
  };

  return (
    <div className="border border-[#CFD6D6] bg-white shadow-sm">
      <HospitalityReportScopePanel
        filters={filters}
        setFilters={setScopeFilters}
        lookups={lookups}
        onApply={loadAll}
        loading={loading}
        businessModeLabel={businessModeLabel}
        supported={{
          branch_id: true,
          date_from: true,
          date_to: true,
          till_id: false,
          cashier_id: false,
          shift_session_id: false,
          currency_codes: true,
        }}
        actions={(
          <button
            type="button"
            onClick={resetFilters}
            className="h-8 border border-[#CFD6D6] bg-white px-3 text-[12px] font-medium text-[#465757]"
          >
            Reset
          </button>
        )}
      />

      <div className="flex items-center justify-between gap-3 px-3 pt-3">
        <div className="inline-flex items-center gap-2 text-[13px] font-semibold text-[#0C1B1B]">
          <Filter className="h-4 w-4" />
          Advanced tax criteria
        </div>
      </div>

      <div className="grid gap-2 p-3 xl:grid-cols-6">
        <Field label="Tax Code">
          <input
            value={filters.tax_code}
            onChange={(event) => update("tax_code", event.target.value.toUpperCase())}
            placeholder="VAT"
            className="input"
          />
        </Field>

        <Field label="ZIMRA Tax">
          <select
            value={filters.obliged_to_zimra}
            onChange={(event) => update("obliged_to_zimra", event.target.value)}
            className="input"
          >
            <option value="">All</option>
            <option value="1">ZIMRA only</option>
            <option value="0">Non-ZIMRA</option>
          </select>
        </Field>

        <Field label="Tax Mode">
          <select
            value={filters.is_inclusive}
            onChange={(event) => update("is_inclusive", event.target.value)}
            className="input"
          >
            <option value="">All</option>
            <option value="1">Inclusive</option>
            <option value="0">Exclusive</option>
          </select>
        </Field>

        <Field label="Receipt Type">
          <select
            value={filters.receipt_type}
            onChange={(event) => update("receipt_type", event.target.value)}
            className="input"
          >
            <option value="">All</option>
            <option value="FiscalInvoice">FiscalInvoice</option>
            <option value="CreditNote">CreditNote</option>
            <option value="DebitNote">DebitNote</option>
          </select>
        </Field>

        <Field label="Status">
          <select
            value={filters.status}
            onChange={(event) => update("status", event.target.value)}
            className="input"
          >
            <option value="">All</option>
            <option value="submitted">Submitted</option>
            <option value="pending">Pending</option>
            <option value="failed">Failed</option>
            <option value="completed">Completed Sale</option>
            <option value="cancelled">Cancelled Sale</option>
          </select>
        </Field>

        <Field label="Search">
          <div className="relative">
            <Search className="pointer-events-none absolute left-2 top-2.5 h-3.5 w-3.5 text-[#96A5A4]" />
            <input
              value={filters.q}
              onChange={(event) => update("q", event.target.value)}
              placeholder="Invoice, product..."
              className="input pl-7"
            />
          </div>
        </Field>

      </div>

      <style>{`
        .input {
          height: 32px;
          width: 100%;
          border: 1px solid #CFD6D6;
          background: white;
          padding: 0 10px;
          font-size: 12px;
          color: #0C1B1B;
          outline: none;
        }
        .input:focus {
          border-color: #004343;
          box-shadow: 0 0 0 2px rgba(0,67,67,0.08);
        }
      `}</style>
    </div>
  );
}

function Overview({ totals, byTax, byCurrency, setActiveTab }) {
  return (
    <div className="grid h-full min-h-0 gap-3 xl:grid-cols-[minmax(0,1fr)_420px]">
      <div className="grid min-h-0 grid-rows-[auto_minmax(0,1fr)] gap-3">
        <div className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-5">
          <Metric
            title="Receipts"
            value={totals.receipt_count || totals.sales_count || 0}
            hint="Receipts with tax activity"
            icon={ReceiptText}
          />
          <Metric
            title="Total Sales"
            value={money(totals.total_amount)}
            hint="Sales transaction total"
            icon={FileText}
          />
          <Metric
            title="Total Tax"
            value={money(totals.tax_amount)}
            hint="Receipt line tax total"
            icon={ShieldCheck}
            tone="success"
          />
          <Metric
            title="Credit Notes"
            value={totals.credit_note_count || 0}
            hint={money(totals.credit_note_total)}
            icon={AlertTriangle}
            tone="warning"
          />
          <Metric
            title="Discounts"
            value={money(totals.discount_amount)}
            hint="Discount impact"
            icon={BadgeCheck}
          />
        </div>

        <div className="grid min-h-0 gap-3 xl:grid-cols-[minmax(0,1fr)_360px]">
          <TaxSummary rows={byTax} compact />

          <Panel title="Currency Breakdown">
            {byCurrency.length ? (
              byCurrency.map((row, index) => (
                <div key={`${row.currency || row.currency_code || "currency"}-${index}`} className="border-b border-[#E9EDED] py-3 last:border-b-0">
                  <div className="flex items-center justify-between">
                    <span className="text-[13px] font-semibold text-[#0C1B1B]">
                      {row.currency || row.currency_code || "-"}
                    </span>
                    <span className="text-[13px] font-semibold text-[#067647]">
                      {money(row.tax_amount, row.currency || row.currency_code)}
                    </span>
                  </div>
                  <div className="mt-1 text-[12px] text-[#627474]">
                    Sales {money(row.sales_amount_with_tax, row.currency || row.currency_code)} / Taxable{" "}
                    {money(row.taxable_amount, row.currency || row.currency_code)}
                  </div>
                </div>
              ))
            ) : (
              <EmptyState label="Currency totals will appear once summary endpoint returns by_currency." />
            )}
          </Panel>
        </div>
      </div>

      <div className="grid min-h-0 grid-rows-[auto_auto_minmax(0,1fr)] gap-3">
        <Panel title="Compliance Snapshot">
          <Info label="Tax Lines" value={totals.tax_line_count || 0} />
          <Info label="ZIMRA Tax" value={money(totals.zimra_tax_amount)} strong />
          <Info label="Inclusive Tax" value={money(totals.inclusive_tax_amount)} />
          <Info label="Exclusive Tax" value={money(totals.exclusive_tax_amount)} />
          <Info label="Credit Note Tax" value={money(totals.credit_note_tax)} danger={toNumber(totals.credit_note_tax) < 0} />
        </Panel>

        <Panel title="Audit Rules">
          <Rule done text="Report uses receipt_lines and receipt_line_taxes." />
          <Rule done text="Credit notes appear as negative receipts." />
          <Rule done text="Inclusive tax remains inside sales amount." />
          <Rule done text="Only obliged_to_zimra taxes are FDMS taxes." />
        </Panel>

        <Panel title="Quick Actions">
          <button
            type="button"
            onClick={() => setActiveTab("lines")}
            className="mb-2 flex h-10 w-full items-center justify-between border border-[#CFD6D6] bg-white px-3 text-[12px] font-medium text-[#324443] hover:bg-[#F8FAFC]"
          >
            View detailed tax lines
            <ChevronRight className="h-4 w-4" />
          </button>

          <button
            type="button"
            onClick={() => setActiveTab("zimra")}
            className="mb-2 flex h-10 w-full items-center justify-between border border-[#CFD6D6] bg-white px-3 text-[12px] font-medium text-[#324443] hover:bg-[#F8FAFC]"
          >
            Open ZIMRA receipt register
            <ChevronRight className="h-4 w-4" />
          </button>

        </Panel>
      </div>
    </div>
  );
}

function TaxSummary({ rows, compact = false }) {
  const columns = [
    {
      key: "tax_code",
      label: "Tax Code",
      render: (row) => <span className="font-semibold text-[#0C1B1B]">{row.tax_code || "-"}</span>,
    },
    {
      key: "tax_percent",
      label: "Rate",
      render: (row) => `${toNumber(row.tax_percent).toFixed(2)}%`,
    },
    {
      key: "currency_code",
      label: "Currency",
      render: (row) => row.currency_code || row.currency || "-",
    },
    {
      key: "is_inclusive",
      label: "Mode",
      render: (row) => <Chip tone={row.is_inclusive ? "blue" : "gray"}>{row.is_inclusive ? "Inclusive" : "Exclusive"}</Chip>,
    },
    {
      key: "obliged_to_zimra",
      label: "ZIMRA",
      render: (row) => <Chip tone={row.obliged_to_zimra ? "green" : "gray"}>{boolText(row.obliged_to_zimra)}</Chip>,
    },
    { key: "receipt_count", label: "Receipts", align: "right" },
    { key: "line_count", label: "Lines", align: "right" },
    {
      key: "sales_amount_with_tax",
      label: "Sales With Tax",
      align: "right",
      render: (row) => money(row.sales_amount_with_tax, row.currency_code || row.currency),
    },
    {
      key: "tax_amount",
      label: "Tax Amount",
      align: "right",
      render: (row) => <span className="font-semibold text-[#067647]">{money(row.tax_amount, row.currency_code || row.currency)}</span>,
    },
  ];

  return (
    <DataPanel
      title="Tax Summary"
      subtitle="Grouped by tax code, rate, currency, ZIMRA obligation and tax mode."
      rows={rows}
      columns={columns}
      compact={compact}
      minWidth="980px"
    />
  );
}

function TaxLines({ rows, paginator, loadPage }) {
  const columns = [
    { key: "created_at", label: "Date", render: (row) => formatDate(row.created_at) },
    { key: "branch_name", label: "Branch" },
    {
      key: "invoice_number",
      label: "Receipt",
      render: (row) => <span className="font-semibold text-[#0C1B1B]">{row.invoice_number || "-"}</span>,
    },
    { key: "sale_type", label: "Type", render: (row) => <Chip tone={row.sale_type === "sale_cancel" ? "red" : "green"}>{row.sale_type}</Chip> },
    { key: "product_name", label: "Product" },
    { key: "quantity", label: "Qty", align: "right", render: (row) => toNumber(row.quantity).toLocaleString() },
    { key: "unit_price", label: "Unit", align: "right", render: (row) => money(row.unit_price, row.currency_code) },
    { key: "line_discount", label: "Discount", align: "right", render: (row) => money(row.line_discount, row.currency_code) },
    { key: "tax_code", label: "Tax" },
    { key: "tax_percent", label: "Rate", align: "right", render: (row) => `${toNumber(row.tax_percent).toFixed(2)}%` },
    { key: "is_inclusive", label: "Mode", render: (row) => <Chip tone={row.is_inclusive ? "blue" : "gray"}>{row.is_inclusive ? "Inclusive" : "Exclusive"}</Chip> },
    { key: "tax_amount", label: "Tax Amount", align: "right", render: (row) => money(row.tax_amount, row.currency_code) },
    { key: "sales_amount_with_tax", label: "Sales With Tax", align: "right", render: (row) => money(row.sales_amount_with_tax, row.currency_code) },
    { key: "cashier_name", label: "Cashier" },
  ];

  return (
    <DataPanel
      title="Tax Line Details"
      subtitle="Transaction-level tax audit trail linked to receipt_lines and receipt_line_taxes."
      rows={rows}
      columns={columns}
      minWidth="1550px"
      paginator={paginator}
      loadPage={loadPage}
    />
  );
}

function ZimraRegister({ rows, paginator, loadPage, openDetail }) {
  const columns = [
    { key: "created_at", label: "Date", render: (row) => formatDate(row.created_at) },
    { key: "branch_name", label: "Branch" },
    {
      key: "invoice_no",
      label: "Invoice",
      render: (row) => (
        <button
          type="button"
          onClick={() => openDetail(row.id)}
          className="font-semibold text-[#008585] underline-offset-2 hover:underline"
        >
          {row.invoice_no || "-"}
        </button>
      ),
    },
    {
      key: "receipt_type",
      label: "Type",
      render: (row) => <Chip tone={row.receipt_type === "CreditNote" ? "red" : "green"}>{row.receipt_type || "-"}</Chip>,
    },
    { key: "receipt_currency", label: "Currency" },
    { key: "device_id", label: "Device" },
    { key: "fiscal_day_no", label: "Fiscal Day", align: "right" },
    { key: "receipt_counter", label: "Counter", align: "right" },
    { key: "receipt_global_no", label: "Global No", align: "right" },
    { key: "fdms_receipt_id", label: "FDMS ID" },
    { key: "total_amount", label: "Total", align: "right", render: (row) => money(row.total_amount, row.receipt_currency) },
    { key: "tax_amount", label: "Tax", align: "right", render: (row) => money(row.tax_amount, row.receipt_currency) },
    { key: "discount_amount", label: "Discount", align: "right", render: (row) => money(row.discount_amount, row.receipt_currency) },
    { key: "status", label: "Status", render: (row) => <Status status={row.status} /> },
    { key: "cashier_name", label: "Cashier" },
  ];

  return (
    <DataPanel
      title="ZIMRA Receipt Register"
      subtitle="FDMS submitted receipts, CreditNotes, QR data, fiscal day and counters."
      rows={rows}
      columns={columns}
      minWidth="1700px"
      paginator={paginator}
      loadPage={loadPage}
    />
  );
}

function ReceiptDetail({ detail, onClose }) {
  const receipt = detail?.receipt || {};
  const lines = safeArray(detail?.lines);
  const taxes = safeArray(detail?.taxes);
  const counters = safeArray(detail?.counter_events);
  const payload = detail?.payload || parseJson(receipt.payload);
  const response = detail?.response || parseJson(receipt.response);

  return (
    <div className="absolute inset-0 z-[90] flex justify-end bg-[#003434]/45">
      <div className="flex h-full w-full max-w-[880px] flex-col border-l border-[#CFD6D6] bg-white shadow-2xl">
        <div className="flex items-center justify-between border-b border-[#E9EDED] bg-[#F8FAFC] px-4 py-3">
          <div>
            <div className="text-[16px] font-semibold text-[#0C1B1B]">ZIMRA Receipt Detail</div>
            <div className="mt-0.5 text-[12px] text-[#627474]">
              {receipt.invoice_no || receipt.sale_invoice_number || "-"} / {receipt.receipt_type || "-"}
            </div>
          </div>

          <button type="button" onClick={onClose} className="h-9 w-9 border border-[#CFD6D6] bg-white hover:bg-[#F2F4F7]">
            <X className="mx-auto h-4 w-4" />
          </button>
        </div>

        <div className="min-h-0 flex-1 overflow-auto p-4">
          <div className="grid gap-3 md:grid-cols-2">
            <Panel title="Receipt">
              <Info label="Invoice" value={receipt.invoice_no || receipt.sale_invoice_number || "-"} />
              <Info label="Type" value={receipt.receipt_type || "-"} />
              <Info label="Currency" value={receipt.receipt_currency || "-"} />
              <Info label="Status" value={<Status status={receipt.status} />} />
              <Info label="FDMS ID" value={receipt.fdms_receipt_id || "-"} />
              <Info label="Operation" value={receipt.operation_id || "-"} />
              <Info label="Counter" value={receipt.receipt_counter || "-"} />
              <Info label="Global No" value={receipt.receipt_global_no || "-"} />
            </Panel>

            <Panel title="Fiscal Information">
              <Info label="Device ID" value={receipt.device_id || "-"} />
              <Info label="Device Serial" value={receipt.device_serial_no || "-"} />
              <Info label="Fiscal Day" value={receipt.fiscal_day_no || "-"} />
              <Info label="Submitted At" value={formatDate(receipt.submitted_at)} />
              <Info label="QR String" value={receipt.qr_string || receipt.qr_url || "-"} />
              <Info label="Error" value={receipt.error || "-"} danger={!!receipt.error} />
            </Panel>
          </div>

          <MiniTable
            title="Receipt Lines"
            rows={lines}
            columns={["line_no", "line_name", "line_quantity", "line_price", "line_discount", "line_tax", "line_total"]}
          />

          <MiniTable
            title="Tax Breakdown"
            rows={taxes}
            columns={["tax_code", "tax_percent", "is_inclusive", "obliged_to_zimra", "tax_amount", "sales_amount_with_tax"]}
          />

          <MiniTable
            title="Fiscal Counter Events"
            rows={counters}
            columns={[
              "fiscal_counter_type",
              "fiscal_counter_currency",
              "fiscal_counter_tax_id",
              "fiscal_counter_tax_percent",
              "fiscal_counter_money_type",
              "fiscal_counter_value",
            ]}
          />

          <Json title="FDMS Payload" data={payload} />
          <Json title="FDMS Response" data={response} />
        </div>
      </div>
    </div>
  );
}

function DataPanel({
  title,
  subtitle,
  rows,
  columns,
  minWidth = "1200px",
  compact = false,
  paginator,
  loadPage,
}) {
  return (
    <div className="grid h-full min-h-0 grid-rows-[auto_minmax(0,1fr)_auto] overflow-hidden border border-[#CFD6D6] bg-white shadow-sm">
      <div className="flex items-center justify-between gap-3 border-b border-[#E9EDED] bg-[#F8FAFC] px-4 py-3">
        <div>
          <div className="text-[15px] font-semibold text-[#0C1B1B]">{title}</div>
          {subtitle && <div className="mt-0.5 text-[12px] text-[#627474]">{subtitle}</div>}
        </div>

      </div>

      <div className="min-h-0 overflow-auto">
        <table className="w-full border-collapse text-[12px]" style={{ minWidth }}>
          <thead className="sticky top-0 z-10">
            <tr className="border-b border-[#E9EDED] bg-white text-left text-[11px] uppercase tracking-wide text-[#627474]">
              {columns.map((column) => (
                <th
                  key={column.key}
                  className={cx("px-3 py-2 font-semibold", column.align === "right" && "text-right")}
                >
                  {column.label}
                </th>
              ))}
            </tr>
          </thead>

          <tbody>
            {rows.map((row, index) => (
              <tr key={row.id || index} className="border-b border-[#F2F4F7] hover:bg-[#F8FAFC]">
                {columns.map((column) => (
                  <td
                    key={column.key}
                    className={cx(
                      "px-3 text-[#324443]",
                      compact ? "py-2" : "py-2.5",
                      column.align === "right" && "text-right"
                    )}
                  >
                    {column.render ? column.render(row) : cell(row[column.key], column.key)}
                  </td>
                ))}
              </tr>
            ))}

            {!rows.length && (
              <tr>
                <td colSpan={columns.length} className="py-14 text-center text-[13px] text-[#627474]">
                  No records found for the selected filters.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>

      <Pagination paginator={paginator} loadPage={loadPage} />
    </div>
  );
}

function Pagination({ paginator, loadPage }) {
  if (!paginator) {
    return <div className="border-t border-[#E9EDED] bg-[#FCFCFD] px-4 py-2 text-[12px] text-[#627474]">Showing current result set.</div>;
  }

  const current = paginator.current_page || 1;
  const last = paginator.last_page || 1;

  return (
    <div className="flex items-center justify-between border-t border-[#E9EDED] bg-[#FCFCFD] px-4 py-2 text-[12px] text-[#627474]">
      <span>
        Showing {paginator.from || 0}-{paginator.to || 0} of {paginator.total || 0}
      </span>

      <div className="flex items-center gap-2">
        <button
          type="button"
          disabled={current <= 1}
          onClick={() => loadPage(current - 1)}
          className="inline-flex h-8 items-center gap-1 border border-[#CFD6D6] bg-white px-3 text-[12px] font-medium disabled:opacity-40"
        >
          <ChevronLeft className="h-3.5 w-3.5" />
          Prev
        </button>

        <span className="font-medium text-[#324443]">
          Page {current} / {last}
        </span>

        <button
          type="button"
          disabled={current >= last}
          onClick={() => loadPage(current + 1)}
          className="inline-flex h-8 items-center gap-1 border border-[#CFD6D6] bg-white px-3 text-[12px] font-medium disabled:opacity-40"
        >
          Next
          <ChevronRight className="h-3.5 w-3.5" />
        </button>
      </div>
    </div>
  );
}

function MiniTable({ title, rows, columns }) {
  return (
    <div className="mt-4 overflow-hidden border border-[#CFD6D6]">
      <div className="border-b border-[#E9EDED] bg-[#F8FAFC] px-3 py-2 text-[13px] font-semibold text-[#0C1B1B]">
        {title}
      </div>

      <div className="overflow-auto">
        <table className="w-full min-w-[760px] text-[12px]">
          <thead>
            <tr className="border-b border-[#E9EDED] bg-white text-left text-[10px] uppercase text-[#627474]">
              {columns.map((column) => (
                <th key={column} className="px-3 py-2 font-semibold">
                  {column.replaceAll("_", " ")}
                </th>
              ))}
            </tr>
          </thead>

          <tbody>
            {rows.map((row, index) => (
              <tr key={row.id || index} className="border-b border-[#F2F4F7]">
                {columns.map((column) => (
                  <td key={column} className="px-3 py-2 text-[#324443]">
                    {cell(row[column], column)}
                  </td>
                ))}
              </tr>
            ))}

            {!rows.length && (
              <tr>
                <td colSpan={columns.length} className="py-8 text-center text-[#627474]">
                  No data
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function Json({ title, data }) {
  return (
    <div className="mt-4 overflow-hidden border border-[#CFD6D6]">
      <div className="border-b border-[#E9EDED] bg-[#F8FAFC] px-3 py-2 text-[13px] font-semibold text-[#0C1B1B]">
        {title}
      </div>
      <pre className="max-h-[320px] overflow-auto bg-[#003434] p-4 text-[11px] leading-relaxed text-white">
        {JSON.stringify(data || {}, null, 2)}
      </pre>
    </div>
  );
}

function Panel({ title, children }) {
  return (
    <div className="min-h-0 overflow-hidden border border-[#CFD6D6] bg-white shadow-sm">
      <div className="border-b border-[#E9EDED] bg-[#F8FAFC] px-4 py-3 text-[14px] font-semibold text-[#0C1B1B]">
        {title}
      </div>
      <div className="h-[calc(100%-45px)] overflow-auto p-4">{children}</div>
    </div>
  );
}

function Metric({ title, value, hint, icon: Icon, tone = "default" }) {
  const toneClass =
    {
      success: "border-[#ABEFC6] bg-[#ECFDF3] text-[#067647]",
      warning: "border-[#FEDF89] bg-[#FFFAEB] text-[#B54708]",
      default: "border-[#CFD6D6] bg-[#F8FAFC] text-[#324443]",
    }[tone] || "border-[#CFD6D6] bg-[#F8FAFC] text-[#324443]";

  return (
    <div className="border border-[#CFD6D6] bg-white p-4 shadow-sm">
      <div className="flex justify-between gap-3">
        <div className="min-w-0">
          <div className="text-[11px] font-semibold uppercase tracking-wide text-[#627474]">{title}</div>
          <div className="mt-2 truncate text-[20px] font-semibold text-[#0C1B1B]">{value}</div>
          {hint && <div className="mt-1 truncate text-[12px] text-[#627474]">{hint}</div>}
        </div>

        <div className={`flex h-10 w-10 shrink-0 items-center justify-center border ${toneClass}`}>
          <Icon className="h-5 w-5" />
        </div>
      </div>
    </div>
  );
}

function Info({ label, value, strong, danger }) {
  return (
    <div className="grid grid-cols-[150px_minmax(0,1fr)] gap-3 border-b border-[#E9EDED] py-2 text-[12px] last:border-b-0">
      <span className="text-[#627474]">{label}</span>
      <span
        className={cx(
          "truncate text-right text-[#324443]",
          strong && "font-semibold text-[#0C1B1B]",
          danger && "font-semibold text-[#B42318]"
        )}
      >
        {value}
      </span>
    </div>
  );
}

function Field({ label, children }) {
  return (
    <label>
      <span className="mb-1 block text-[10px] font-semibold uppercase tracking-wide text-[#627474]">{label}</span>
      {children}
    </label>
  );
}

function Rule({ done, text }) {
  return (
    <div className="flex gap-2 border-b border-[#E9EDED] py-2 text-[12px] text-[#324443] last:border-b-0">
      {done ? (
        <ShieldCheck className="mt-0.5 h-4 w-4 shrink-0 text-[#067647]" />
      ) : (
        <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-[#B54708]" />
      )}
      <span>{text}</span>
    </div>
  );
}

function Status({ status }) {
  const clean = String(status || "").toLowerCase();

  const tone =
    clean === "submitted" || clean === "success"
      ? "green"
      : clean === "failed"
        ? "red"
        : "yellow";

  return <Chip tone={tone}>{status || "-"}</Chip>;
}

function Chip({ children, tone = "gray" }) {
  const cls =
    {
      green: "border-[#ABEFC6] bg-[#ECFDF3] text-[#067647]",
      red: "border-[#FECDCA] bg-[#FEF3F2] text-[#B42318]",
      yellow: "border-[#FEDF89] bg-[#FFFAEB] text-[#B54708]",
      blue: "border-[#A4E5E4] bg-[#F1F8F8] text-[#057B7B]",
      gray: "border-[#CFD6D6] bg-[#F8FAFC] text-[#324443]",
    }[tone] || "border-[#CFD6D6] bg-[#F8FAFC] text-[#324443]";

  return (
    <span className={`inline-flex border px-2 py-1 text-[10px] font-semibold uppercase tracking-wide ${cls}`}>
      {children || "-"}
    </span>
  );
}

function EmptyState({ label }) {
  return <div className="py-8 text-center text-[12px] text-[#627474]">{label}</div>;
}

function cell(value, key) {
  if (value === null || value === undefined) return "-";

  if (key === "metadata") {
    const meta = parseJson(value);
    return Object.keys(meta).length ? JSON.stringify(meta) : "-";
  }

  if (key.includes("created_at") || key.includes("date") || key.includes("submitted_at")) {
    return formatDate(value);
  }

  if (
    key.includes("amount") ||
    key.includes("tax") ||
    key.includes("sales") ||
    key.includes("total") ||
    key.includes("price")
  ) {
    if (!Number.isNaN(Number(value))) return money(value);
  }

  if (value === true || value === false || value === 1 || value === 0 || value === "1" || value === "0") {
    return boolText(value);
  }

  return String(value);
}
