import React, { useEffect, useMemo, useState } from "react";
import { Download, RefreshCw } from "lucide-react";

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
  downloadSalesReportExport,
  getProfitAndLossReport,
} from "../../../services/SalesReportService";

const today = () => new Date().toISOString().slice(0, 10);
const pad = (value) => String(value).padStart(2, "0");

export const monthPeriod = (monthValue, currentDate = today()) => {
  const [year, month] = String(monthValue || currentDate.slice(0, 7)).split("-").map(Number);
  const safeYear = Number.isFinite(year) ? year : Number(currentDate.slice(0, 4));
  const safeMonth = Number.isFinite(month) && month >= 1 && month <= 12 ? month : Number(currentDate.slice(5, 7));
  const monthKey = `${safeYear}-${pad(safeMonth)}`;
  const lastDay = new Date(safeYear, safeMonth, 0).getDate();
  return {
    month: monthKey,
    date_from: `${monthKey}-01`,
    date_to: monthKey === currentDate.slice(0, 7) ? currentDate : `${monthKey}-${pad(lastDay)}`,
  };
};

const defaultFilters = () => {
  const period = monthPeriod(today().slice(0, 7));
  return {
    date_from: period.date_from,
    date_to: period.date_to,
    branch_id: "",
    till_id: "",
    cashier_id: "",
    shift_session_id: "",
    currency_code: "",
    currency_codes: [],
    business_mode: "fast_food_boh",
    per_page: 1,
    page: 1,
  };
};

const payloadOf = (response) => response?.data?.data || response?.data || response || {};
const number = (value) => Number(value || 0);
const money = (value, currency = "USD") => `${currency || "USD"} ${number(value).toFixed(2)}`;
const percent = (value) => `${number(value).toFixed(2)}%`;

export const identifyComparisonPeriods = (periods = []) => {
  const normalized = Array.isArray(periods) ? periods : [];
  const matches = (row, pattern) => pattern.test(
    `${row?.key || ""} ${row?.label || ""}`.toLowerCase().replace(/[_-]+/g, " ")
  );
  const current = normalized.find((row) => matches(row, /\b(current|selected|this)\b/))
    || normalized[normalized.length - 1]
    || null;
  const prior = normalized.find((row) => row !== current && matches(row, /\b(previous|prior|last)\b/))
    || normalized.find((row) => row !== current)
    || null;

  return { current, prior };
};

export default function MonthlyProfitComparisonReport({
  open,
  onClose,
  onNavigateReport,
}) {
  const [filters, setFilters] = useState(defaultFilters);
  const [comparisonMonth, setComparisonMonth] = useState(today().slice(0, 7));
  const [payload, setPayload] = useState(null);
  const [loading, setLoading] = useState(false);
  const [generated, setGenerated] = useState(false);
  const [error, setError] = useState("");
  const [appliedFilters, setAppliedFilters] = useState(null);
  const [chooserOpen, setChooserOpen] = useState(false);
  const [chooserMode, setChooserMode] = useState("generate");
  const [exporting, setExporting] = useState(false);
  const lookups = useHospitalityReportLookups(open, filters);

  useEffect(() => {
    if (!open) return;
    const next = defaultFilters();
    setFilters(next);
    setComparisonMonth(today().slice(0, 7));
    setPayload(null);
    setGenerated(false);
    setError("");
    setAppliedFilters(null);
    setChooserOpen(false);
  }, [open]);

  const updateFilters = (next) => {
    setGenerated(false);
    setPayload(null);
    setAppliedFilters(null);
    setFilters(next);
  };

  const updateMonth = async (value) => {
    const { month, ...period } = monthPeriod(value);
    const next = { ...filters, ...period, page: 1 };
    setComparisonMonth(month);
    updateFilters(next);
    await lookups?.loadLookups?.(next);
  };

  const load = async (nextFilters = filters) => {
    const snapshot = { ...nextFilters, currency_codes: [...(nextFilters.currency_codes || [])] };
    try {
      setLoading(true);
      setError("");
      const response = await getProfitAndLossReport(snapshot);
      setPayload(payloadOf(response));
      setAppliedFilters(snapshot);
      setGenerated(true);
      setChooserOpen(false);
      return true;
    } catch (requestError) {
      setPayload(null);
      setAppliedFilters(null);
      setGenerated(false);
      setChooserOpen(false);
      setError(
        requestError?.response?.data?.message ||
          requestError?.message ||
          "Failed to load monthly profit comparison."
      );
      return false;
    } finally {
      setLoading(false);
    }
  };

  const download = async (format) => {
    try {
      setExporting(true);
      setError("");
      await downloadSalesReportExport("profit-loss", format, appliedFilters || filters);
      setChooserOpen(false);
    } catch (requestError) {
      setChooserOpen(false);
      setError(
        requestError?.response?.data?.message ||
          requestError?.message ||
          "The backend could not generate the comparison report."
      );
    } finally {
      setExporting(false);
    }
  };

  const comparison = payload?.monthly_profit_comparison || {};
  const periods = useMemo(
    () => (Array.isArray(payload?.monthly_profit_comparison?.periods)
      ? payload.monthly_profit_comparison.periods
      : []),
    [payload]
  );
  const { current: currentPeriod, prior: priorPeriod } = useMemo(
    () => identifyComparisonPeriods(periods),
    [periods]
  );
  const chartMaximum = useMemo(
    () => Math.max(
      1,
      ...periods.flatMap((row) => [
        Math.abs(number(row.sales_value)),
        Math.abs(number(row.cost_value)),
        Math.abs(number(row.gross_profit)),
      ])
    ),
    [periods]
  );

  if (!open) return null;

  const openChooser = (mode = "generate") => {
    setChooserMode(mode);
    setChooserOpen(true);
  };

  return (
    <FullScreenModal
      open={open}
      onClose={onClose}
      title="MONTHLY PROFIT COMPARISON"
      subtitle="Hospitality current-month versus prior-month profit performance"
    >
      <div className="flex h-full min-h-0 overflow-hidden bg-[#EFF3F3] text-[#122424]">
        <ManagementReportNavigator
          activeKey={MANAGEMENT_REPORT_KEYS.MONTHLY_PROFIT_COMPARISON}
          onNavigate={onNavigateReport}
          businessModeLabel="Hospitality"
        />

        <div className="min-h-0 min-w-0 flex-1 overflow-hidden">
          {!generated ? (
          <div className="h-full overflow-y-auto">
          <HospitalityReportScopePanel
            filters={filters}
            setFilters={updateFilters}
            lookups={lookups}
            onApply={() => openChooser("generate")}
            loading={loading}
            businessModeLabel="Hospitality"
            supported={{
              branch_id: true,
              date_from: false,
              date_to: false,
              till_id: true,
              cashier_id: true,
              shift_session_id: true,
              currency_codes: true,
            }}
          >
            <label className="block max-w-[480px] bg-white p-3">
              <span className="mb-1 block text-[11px] font-semibold text-[#637877]">Comparison month</span>
              <input
                aria-label="Comparison month"
                type="month"
                max={today().slice(0, 7)}
                value={comparisonMonth}
                onChange={(event) => updateMonth(event.target.value)}
                className="h-[38px] w-full border border-[#B9C0C0] bg-white px-3 text-[12px] outline-none focus:border-[#069495]"
              />
            </label>
          </HospitalityReportScopePanel>

          <div className="p-3">
            {error && (
              <div className="mb-3 border border-[#FDA29B] bg-[#FEF3F2] px-3 py-2 text-[12px] text-[#B42318]">
                {error}
              </div>
            )}

            <ReportGenerationPrompt
              title="Configure Monthly Profit Comparison"
              message="Choose the comparison month, branch and operational scope, then select Generate."
            />
          </div>
          </div>
          ) : (
          <ReportViewShell
            title="Monthly Profit Comparison"
            subtitle={`${appliedFilters?.date_from || ""} to ${appliedFilters?.date_to || ""}`}
            onBackToFilters={() => setGenerated(false)}
            busy={loading || exporting}
            actions={(
              <>
                <button
                  type="button"
                  onClick={() => load(appliedFilters)}
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
          <div className="p-3">
            {error && (
              <div className="mb-3 border border-[#FDA29B] bg-[#FEF3F2] px-3 py-2 text-[12px] text-[#B42318]">
                {error}
              </div>
            )}

            {loading ? (
              <div className="border border-[#CBD6D6] bg-white p-10 text-center text-[12px] text-[#637877]">
                Loading current and prior month profit figures...
              </div>
            ) : periods.length < 2 ? (
              <div className="border border-[#F2C94C] bg-[#FFF8E1] p-4 text-[12px] text-[#7A5200]">
                The backend did not return both monthly comparison periods for this scope.
              </div>
            ) : (
              <>
                <div className="grid gap-2 md:grid-cols-2 xl:grid-cols-4">
                  <Metric label="Gross Profit Change" value={money(comparison.gross_profit_change, currentPeriod?.currency_code || priorPeriod?.currency_code)} tone={number(comparison.gross_profit_change) >= 0 ? "good" : "bad"} />
                  <Metric label="Gross Profit Change %" value={percent(comparison.gross_profit_change_percent)} tone={number(comparison.gross_profit_change_percent) >= 0 ? "good" : "bad"} />
                  <Metric label="Current Month Profit" value={money(currentPeriod?.gross_profit, currentPeriod?.currency_code)} tone={number(currentPeriod?.gross_profit) >= 0 ? "good" : "bad"} />
                  <Metric label="Prior Month Profit" value={money(priorPeriod?.gross_profit, priorPeriod?.currency_code)} />
                </div>

                <section className="mt-3 border border-[#CBD6D6] bg-white">
                  <div className="border-b border-[#CBD6D6] bg-[#F8FAFC] px-4 py-3">
                    <div className="text-[13px] font-semibold text-[#003636]">Month-on-Month Profit Visual</div>
                    <div className="mt-0.5 text-[11px] text-[#637877]">Sales, cost of sales and gross profit use backend P&amp;L figures.</div>
                  </div>
                  <div className="grid gap-4 p-4 xl:grid-cols-2">
                    {periods.map((row) => (
                      <PeriodChart key={row.key || row.date_from} row={row} maximum={chartMaximum} />
                    ))}
                  </div>
                </section>

                <section className="mt-3 overflow-auto border border-[#CBD6D6] bg-white">
                  <table className="sx-enterprise-table w-full min-w-[920px] border-collapse text-[11px]">
                    <thead className="bg-[#0E4D4D] text-white">
                      <tr>{["Period", "Date From", "Date To", "Sales", "Cost of Sales", "Gross Profit", "Net Profit After Cashouts", "Gross Margin"].map((label) => <th key={label} className="px-3 py-2 text-left text-[10px] uppercase">{label}</th>)}</tr>
                    </thead>
                    <tbody>
                      {periods.map((row) => (
                        <tr key={`table-${row.key || row.date_from}`} className="border-b border-[#E2E9E9] odd:bg-white even:bg-[#F8FAFC]">
                          <td className="px-3 py-2 font-semibold">{row.label || row.key}</td>
                          <td className="px-3 py-2">{row.date_from}</td>
                          <td className="px-3 py-2">{row.date_to}</td>
                          <td className="px-3 py-2">{money(row.sales_value, row.currency_code)}</td>
                          <td className="px-3 py-2">{money(row.cost_value, row.currency_code)}</td>
                          <td className={`px-3 py-2 font-semibold ${number(row.gross_profit) < 0 ? "text-[#B42318]" : "text-[#027A48]"}`}>{money(row.gross_profit, row.currency_code)}</td>
                          <td className="px-3 py-2">{money(row.net_profit_after_cashouts, row.currency_code)}</td>
                          <td className="px-3 py-2">{percent(row.gross_margin_percent)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </section>
              </>
            )}
          </div>
          </ReportViewShell>
          )}
        </div>
      </div>

      <ReportOutputChooser
        open={chooserOpen}
        reportTitle="Monthly Profit Comparison"
        description={chooserMode === "download" ? "Choose a backend-generated download format." : "View the comparison or download it directly from the backend."}
        busy={loading || exporting}
        onClose={() => setChooserOpen(false)}
        onView={chooserMode === "generate" ? () => load(filters) : undefined}
        onDownloadPdf={() => download("pdf")}
        onDownloadExcel={() => download("excel")}
        outputs={chooserMode === "download" ? { view: false } : undefined}
      />
    </FullScreenModal>
  );
}

function Metric({ label, value, tone = "neutral" }) {
  const toneClass = tone === "good" ? "text-[#027A48]" : tone === "bad" ? "text-[#B42318]" : "text-[#003636]";
  return <div className="border border-[#CBD6D6] bg-white p-3"><div className="text-[10px] font-semibold uppercase text-[#637877]">{label}</div><div className={`mt-1 text-[18px] font-semibold ${toneClass}`}>{value}</div></div>;
}

function PeriodChart({ row, maximum }) {
  const bars = [
    { key: "sales", label: "Sales", value: number(row.sales_value), color: "bg-[#069494]" },
    { key: "cost", label: "Cost of Sales", value: number(row.cost_value), color: "bg-[#F79009]" },
    { key: "profit", label: "Gross Profit", value: number(row.gross_profit), color: number(row.gross_profit) >= 0 ? "bg-[#12B76A]" : "bg-[#F04438]" },
  ];

  return (
    <div className="border border-[#D7DFDF] bg-white p-3">
      <div className="text-[13px] font-semibold text-[#003636]">{row.label || row.key}</div>
      <div className="mb-3 mt-0.5 text-[10px] text-[#637877]">{row.date_from} - {row.date_to}</div>
      <div className="space-y-3">
        {bars.map((bar) => (
          <div key={bar.key}>
            <div className="mb-1 flex items-center justify-between gap-3 text-[11px]"><span>{bar.label}</span><strong>{money(bar.value, row.currency_code)}</strong></div>
            <div className="h-4 border border-[#D7DFDF] bg-[#F8FAFC]"><div className={`h-full ${bar.color}`} style={{ width: `${Math.max(bar.value !== 0 ? 3 : 0, (Math.abs(bar.value) / maximum) * 100)}%` }} /></div>
          </div>
        ))}
      </div>
    </div>
  );
}
