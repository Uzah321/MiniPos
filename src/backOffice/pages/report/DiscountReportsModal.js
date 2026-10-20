import React, { useEffect, useMemo, useState } from "react";
import { Download, RefreshCw } from "lucide-react";
import {
  exportDiscountReport,
  getDiscountDashboard,
  getDiscountReport,
} from "../../../services/DiscountService";
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

const money = (value) => Number(value || 0).toFixed(2);

const getErrorMessage = (error, fallback) => {
  const data = error?.response?.data;
  if (data?.errors) return Object.values(data.errors).flat().join("\n");
  return data?.message || data?.error || error?.message || fallback;
};

const normalizeRows = (response) => {
  const payload = response?.data ?? response;

  if (Array.isArray(payload)) return payload;
  if (Array.isArray(payload?.data)) return payload.data;
  if (Array.isArray(payload?.data?.data)) return payload.data.data;
  if (Array.isArray(payload?.rows)) return payload.rows;
  if (Array.isArray(payload?.items)) return payload.items;

  return [];
};

const normalizeDashboard = (response) => {
  const payload = response?.data ?? response;
  return payload?.data ?? payload ?? null;
};

const getReceiptNumber = (row) =>
  row?.receipt_number ||
  row?.receipt_no ||
  row?.invoice_number ||
  row?.invoice_no ||
  row?.transaction_number ||
  row?.transaction_no ||
  row?.sale_number ||
  row?.reference_number ||
  row?.reference ||
  row?.sales_transaction_id ||
  "-";

const getCurrency = (rows, summary) => {
  if (rows?.[0]?.currency) return rows[0].currency;
  if (summary?.currency) return summary.currency;
  return "";
};

export default function DiscountReportsModal({
  open,
  onClose,
  businessModeLabel = "Hospitality",
  onNavigateReport,
}) {
  const today = new Date().toISOString().slice(0, 10);

  const [filters, setFilters] = useState({
    date_from: today,
    date_to: today,
    branch_id: "",
  });

  const [dashboard, setDashboard] = useState(null);
  const [rows, setRows] = useState([]);
  const [notice, setNotice] = useState("");
  const [loading, setLoading] = useState(false);
  const [exporting, setExporting] = useState("");
  const [generated, setGenerated] = useState(false);
  const [appliedFilters, setAppliedFilters] = useState(null);
  const [chooserOpen, setChooserOpen] = useState(false);
  const [chooserMode, setChooserMode] = useState("generate");
  const lookups = useHospitalityReportLookups(open, filters);

  useEffect(() => {
    if (!open) return;
    setDashboard(null);
    setRows([]);
    setNotice("");
    setGenerated(false);
    setAppliedFilters(null);
    setChooserOpen(false);
  }, [open]);

  const summary = useMemo(() => dashboard?.summary || {}, [dashboard]);
  const byType = dashboard?.by_type || [];
  const byCashier = dashboard?.by_cashier || [];
  const byDay = dashboard?.by_day || [];
  const currency = getCurrency(rows, summary);

  const totals = useMemo(() => {
    const gross = Number(summary.gross_amount ?? rows.reduce((sum, row) => sum + Number(row.gross_amount || 0), 0));
    const discount = Number(summary.total_discount_amount ?? rows.reduce((sum, row) => sum + Number(row.discount_amount || 0), 0));
    const net = Number(summary.net_amount ?? rows.reduce((sum, row) => sum + Number(row.net_amount || 0), 0));

    return {
      discount_count: summary.discount_count ?? rows.length,
      gross_amount: gross,
      total_discount_amount: discount,
      net_amount: net,
      manager_approved_count: summary.manager_approved_count ?? rows.filter((row) => row.manager_approval_id).length,
    };
  }, [summary, rows]);

  if (!open) return null;

  const clearResults = () => {
    setDashboard(null);
    setRows([]);
    setAppliedFilters(null);
    setGenerated(false);
  };

  const load = async (nextFilters = filters) => {
    const snapshot = { ...nextFilters };
    try {
      setLoading(true);
      setNotice("");

      const [dash, report] = await Promise.all([
        getDiscountDashboard(snapshot),
        getDiscountReport({ ...snapshot, per_page: 100 }),
      ]);

      setDashboard(normalizeDashboard(dash));
      setRows(normalizeRows(report));
      setAppliedFilters(snapshot);
      setGenerated(true);
      setChooserOpen(false);
      return true;
    } catch (err) {
      clearResults();
      setChooserOpen(false);
      setNotice(getErrorMessage(err, "Failed to load discount reports."));
      return false;
    } finally {
      setLoading(false);
    }
  };

  const exportReport = async (format) => {
    try {
      setExporting(format);
      setNotice("");
      await exportDiscountReport(appliedFilters || filters, format);
      setChooserOpen(false);
    } catch (err) {
      setChooserOpen(false);
      setNotice(getErrorMessage(err, "Failed to export report."));
    } finally {
      setExporting("");
    }
  };

  const openChooser = (mode = "generate") => {
    setChooserMode(mode);
    setChooserOpen(true);
  };

  return (
    <FullScreenModal
      open={open}
      onClose={onClose}
      title="Discount Report"
      subtitle="SERVENEST ENTERPRISE"
    >
      <div className="flex h-full min-h-0 bg-white">
        <ManagementReportNavigator
          activeKey={MANAGEMENT_REPORT_KEYS.DISCOUNTS}
          onNavigate={onNavigateReport}
          businessModeLabel={businessModeLabel}
        />
        <div className="flex min-h-0 min-w-0 flex-1 flex-col bg-white">
        {!generated ? (
        <div className="min-h-0 flex-1 overflow-y-auto">
        {notice && <div className="whitespace-pre-line border-b border-[#FEDF89] bg-[#FFFAEB] px-4 py-2 text-[12px] font-bold text-[#B54708]">{notice}</div>}
        <HospitalityReportScopePanel
          filters={filters}
          setFilters={(next) => {
            clearResults();
            setFilters({
              date_from: next.date_from,
              date_to: next.date_to,
              branch_id: next.branch_id || "",
            });
          }}
          lookups={lookups}
          onApply={() => openChooser("generate")}
          loading={loading}
          businessModeLabel={businessModeLabel}
          supported={{
            branch_id: true,
            date_from: true,
            date_to: true,
            till_id: false,
            cashier_id: false,
            shift_session_id: false,
            currency_codes: false,
          }}
        />

        <ReportGenerationPrompt
          title="Configure Discount Report"
          message="Choose the branch and reporting period, then select Generate to choose View, PDF or Excel."
        />
        </div>
        ) : (
        <ReportViewShell
          title="Discount Report"
          subtitle={`${appliedFilters?.date_from || ""} to ${appliedFilters?.date_to || ""}`}
          onBackToFilters={() => setGenerated(false)}
          busy={loading || Boolean(exporting)}
          actions={(
            <>
              <button
                type="button"
                onClick={() => load(appliedFilters)}
                disabled={loading || Boolean(exporting)}
                className="inline-flex h-8 items-center gap-1 border border-[#ABB2B2] bg-white px-3 text-[11px] font-semibold text-[#324443] disabled:opacity-60"
              >
                <RefreshCw className={`h-3.5 w-3.5 ${loading ? "animate-spin" : ""}`} /> Refresh
              </button>
              <button
                type="button"
                onClick={() => openChooser("download")}
                disabled={loading || Boolean(exporting)}
                className="inline-flex h-8 items-center gap-1 border border-[#027A48] bg-white px-3 text-[11px] font-semibold text-[#027A48] disabled:opacity-60"
              >
                <Download className="h-3.5 w-3.5" /> Download
              </button>
            </>
          )}
        >
        {notice && <div className="whitespace-pre-line border-b border-[#FEDF89] bg-[#FFFAEB] px-4 py-2 text-[12px] font-bold text-[#B54708]">{notice}</div>}
        <div className="grid shrink-0 grid-cols-2 gap-3 border-b border-[#D7DFDF] p-4 md:grid-cols-5">
          <Metric label="Discounts" value={totals.discount_count || 0} />
          <Metric label={`Gross ${currency}`} value={money(totals.gross_amount)} />
          <Metric label={`Discount ${currency}`} value={money(totals.total_discount_amount)} danger />
          <Metric label={`Net ${currency}`} value={money(totals.net_amount)} />
          <Metric label="Manager Approved" value={totals.manager_approved_count || 0} />
        </div>

        <div className="grid shrink-0 gap-3 border-b border-[#D7DFDF] bg-[#FBFCFE] p-4 xl:grid-cols-3">
          <MiniSummaryTable
            title="By Discount"
            empty="No discount summary."
            columns={["Discount", "Count", "Amount"]}
            rows={byType.map((row) => ({
              key: `${row.discount_code || ""}-${row.currency || ""}`,
              values: [
                row.discount_name || row.discount_code || "-",
                row.count || 0,
                money(row.discount_amount),
              ],
            }))}
          />

          <MiniSummaryTable
            title="By Cashier"
            empty="No cashier summary."
            columns={["Cashier", "Count", "Amount"]}
            rows={byCashier.map((row) => ({
              key: `${row.cashier_id || ""}-${row.currency || ""}`,
              values: [
                row.cashier_name || "-",
                row.count || 0,
                money(row.discount_amount),
              ],
            }))}
          />

          <MiniSummaryTable
            title="By Day"
            empty="No daily summary."
            columns={["Date", "Count", "Amount"]}
            rows={byDay.map((row) => ({
              key: `${row.report_date || ""}-${row.currency || ""}`,
              values: [
                row.report_date || "-",
                row.count || 0,
                money(row.discount_amount),
              ],
            }))}
          />
        </div>

        <div className="min-h-0 flex-1 overflow-auto p-4">
          <table className="w-full min-w-[1180px] border-collapse text-[12px]">
            <thead className="sticky top-0 z-10">
              <tr className="bg-[#F1F5F5] text-left text-[10px] uppercase text-[#637877]">
                <th className="border border-[#D7DFDF] px-2 py-2">Date</th>
                <th className="border border-[#D7DFDF] px-2 py-2">Receipt</th>
                <th className="border border-[#D7DFDF] px-2 py-2">Discount Name</th>
                <th className="border border-[#D7DFDF] px-2 py-2">Discount Code</th>
                <th className="border border-[#D7DFDF] px-2 py-2">Scope</th>
                <th className="border border-[#D7DFDF] px-2 py-2">Basis</th>
                <th className="border border-[#D7DFDF] px-2 py-2 text-right">Gross</th>
                <th className="border border-[#D7DFDF] px-2 py-2 text-right">Discount</th>
                <th className="border border-[#D7DFDF] px-2 py-2 text-right">Net</th>
                <th className="border border-[#D7DFDF] px-2 py-2">Currency</th>
                <th className="border border-[#D7DFDF] px-2 py-2">Cashier</th>
                <th className="border border-[#D7DFDF] px-2 py-2">Approved</th>
              </tr>
            </thead>

            <tbody>
              {rows.map((row, index) => (
                <tr key={row.id || `${row.sales_transaction_id}-${index}`} className="hover:bg-[#F8FAFC]">
                  <td className="border border-[#D7DFDF] px-2 py-2">
                    {String(row.created_at || "").slice(0, 19)}
                  </td>

                  <td className="border border-[#D7DFDF] px-2 py-2 font-bold">
                    {getReceiptNumber(row)}
                  </td>

                  <td className="border border-[#D7DFDF] px-2 py-2 font-black">
                    {row.discount_name || "-"}
                  </td>

                  <td className="border border-[#D7DFDF] px-2 py-2 text-[#637877]">
                    {row.discount_code || "-"}
                  </td>

                  <td className="border border-[#D7DFDF] px-2 py-2 uppercase">
                    {row.scope || "-"}
                  </td>

                  <td className="border border-[#D7DFDF] px-2 py-2 uppercase">
                    {String(row.discount_basis || "-").replaceAll("_", " ")}
                  </td>

                  <td className="border border-[#D7DFDF] px-2 py-2 text-right">
                    {money(row.gross_amount)}
                  </td>

                  <td className="border border-[#D7DFDF] px-2 py-2 text-right font-black text-[#B42318]">
                    {money(row.discount_amount)}
                  </td>

                  <td className="border border-[#D7DFDF] px-2 py-2 text-right">
                    {money(row.net_amount)}
                  </td>

                  <td className="border border-[#D7DFDF] px-2 py-2">
                    {row.currency || "-"}
                  </td>

                  <td className="border border-[#D7DFDF] px-2 py-2">
                    {row.cashier_name || "-"}
                  </td>

                  <td className="border border-[#D7DFDF] px-2 py-2">
                    {row.approved_by_name || "-"}
                  </td>
                </tr>
              ))}

              {!rows.length && (
                <tr>
                  <td colSpan={11} className="border border-[#D7DFDF] px-3 py-8 text-center font-black text-[#637877]">
                    {loading ? "Loading discount transactions..." : "No discount transactions found."}
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
        </ReportViewShell>
        )}
        </div>
      </div>
      <ReportOutputChooser
        open={chooserOpen}
        reportTitle="Discount Report"
        description={chooserMode === "download" ? "Choose a backend-generated download format." : "View the report or download it directly from the backend."}
        busy={loading || Boolean(exporting)}
        onClose={() => setChooserOpen(false)}
        onView={chooserMode === "generate" ? () => load(filters) : undefined}
        onDownloadPdf={() => exportReport("pdf")}
        onDownloadExcel={() => exportReport("xlsx")}
        outputs={chooserMode === "download" ? { view: false } : undefined}
      />
    </FullScreenModal>
  );
}

function MiniSummaryTable({ title, columns, rows, empty }) {
  return (
    <div className="border border-[#D7DFDF] bg-white">
      <div className="border-b border-[#D7DFDF] bg-[#F8FAFC] px-3 py-2 text-[11px] font-black uppercase text-[#003636]">
        {title}
      </div>

      <table className="w-full border-collapse text-[11px]">
        <thead>
          <tr className="text-left text-[9px] uppercase text-[#637877]">
            {columns.map((column) => (
              <th key={column} className="border-b border-[#D7DFDF] px-2 py-2">
                {column}
              </th>
            ))}
          </tr>
        </thead>

        <tbody>
          {rows.slice(0, 5).map((row) => (
            <tr key={row.key}>
              {row.values.map((value, index) => (
                <td key={`${row.key}-${index}`} className={`border-b border-[#EEF2F2] px-2 py-2 ${index > 0 ? "text-right" : ""}`}>
                  {value}
                </td>
              ))}
            </tr>
          ))}

          {!rows.length && (
            <tr>
              <td colSpan={columns.length} className="px-2 py-4 text-center font-bold text-[#637877]">
                {empty}
              </td>
            </tr>
          )}
        </tbody>
      </table>
    </div>
  );
}

function Metric({ label, value, danger = false }) {
  return (
    <div className="border border-[#D7DFDF] bg-white p-3">
      <div className="text-[10px] font-black uppercase text-[#637877]">{label}</div>
      <div className={`mt-1 text-[18px] font-black ${danger ? "text-[#B42318]" : "text-[#003636]"}`}>
        {value}
      </div>
    </div>
  );
}
