import React, { useEffect, useMemo, useState } from "react";
import FullScreenModal from "../../../components/FullScreenModal";
import {
  catchUpTradingDayEnd,
  forceCloseFrontStockDays,
  getDayEndPreflight,
  rollbackTradingDayEndLock,
  runTradingDayEnd,
} from "../../../services/TradingDayEndEnterpriseService";

const today = () => new Date().toISOString().slice(0, 10);
const n = (value) => Number(value || 0);
const fmt = (value, decimals = 2) => n(value).toFixed(decimals);

const newRunKey = () => {
  if (window.crypto?.randomUUID) return window.crypto.randomUUID();
  return `back-office-catch-up-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
};

const resolveCatchUp = (payload) => {
  const contract = payload?.catch_up || {};

  return {
    available: Boolean(contract.available ?? payload?.catch_up_available),
    lagDays: Number(contract.lag_days ?? payload?.lag_days ?? 0),
    sourceTradingDate:
      contract.source_trading_date ||
      payload?.expected_trading_date ||
      payload?.open_trading_day?.trading_date ||
      "",
    targetSystemDate:
      contract.target_system_date ||
      payload?.expected_system_date ||
      payload?.system_date ||
      "",
    skippedDateFrom: contract.skipped_date_from || "",
    skippedDateTo: contract.skipped_date_to || "",
    skippedDayCount: Number(contract.skipped_day_count ?? 0),
    requiresReason: Boolean(contract.requires_reason ?? true),
  };
};

const getApiMessage = (error, fallback = "Request failed.") => {
  const data = error?.response?.data;

  if (data?.errors && typeof data.errors === "object") {
    return Object.values(data.errors).flat().join("\n");
  }

  return data?.message || data?.error || error?.message || fallback;
};

const DayEndEnterpriseGuardModal = ({
  open,
  onClose,
  onCompleted,
  onOpenIssueStock,
  onOpenFrontStockReconciliation,
  branchId = "",
  branchLabel = "Selected branch",
}) => {
  const [dateTo, setDateTo] = useState(today());
  const [payload, setPayload] = useState(null);
  const [selectedDates, setSelectedDates] = useState([]);
  const [reason, setReason] = useState(
    "Backfilled from the Day End guard using system expected stock because the historical physical closing was missed.",
  );
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [notice, setNotice] = useState("");
  const [confirmAction, setConfirmAction] = useState(null);
  const [catchUpReason, setCatchUpReason] = useState("");
  const [catchUpConfirmation, setCatchUpConfirmation] = useState("");

  const showNotice = (message) => {
    setNotice(message);
    window.setTimeout(() => setNotice(""), 5000);
  };

  const load = async () => {
    try {
      setLoading(true);
      const data = await getDayEndPreflight({
        date_to: dateTo,
        ...(branchId ? { branch_id: branchId } : {}),
      });
      setPayload(data);

      const missing = Array.isArray(data?.missing_front_stock_closings)
        ? data.missing_front_stock_closings.map((row) => row.date)
        : [];

      setSelectedDates((prev) => prev.filter((date) => missing.includes(date)));
    } catch (error) {
      showNotice(getApiMessage(error, "Failed to load Day End preflight."));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (open) load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, branchId]);

  const rows = useMemo(
    () =>
      Array.isArray(payload?.front_stock_closing_calendar)
        ? payload.front_stock_closing_calendar
        : [],
    [payload?.front_stock_closing_calendar],
  );

  const missingRows = rows.filter((row) => !row.is_closed);
  const blockingRows = Array.isArray(payload?.blocking_front_stock_closings)
    ? payload.blocking_front_stock_closings
    : [];
  const operationalReadiness = payload?.operational_readiness || {};
  const frontStockIssueReadiness = payload?.front_stock_issue_readiness || {};
  const blockingFrontStockIssues = Array.isArray(
    payload?.blocking_front_stock_issues ||
      frontStockIssueReadiness?.blocking_front_stock_issues,
  )
    ? payload?.blocking_front_stock_issues ||
      frontStockIssueReadiness?.blocking_front_stock_issues
    : [];
  const blockingOpenShifts = Array.isArray(
    operationalReadiness?.blocking_open_shifts || payload?.blocking_open_shifts,
  )
    ? operationalReadiness?.blocking_open_shifts || payload?.blocking_open_shifts
    : [];
  const uncashedSaleCount = Number(
    operationalReadiness?.uncashed_sale_count ?? payload?.uncashed_sale_count ?? 0,
  );
  const operationalReasons = Array.isArray(operationalReadiness?.blocking_reasons)
    ? operationalReadiness.blocking_reasons
    : [];
  const operationallyBlocked = operationalReadiness?.can_run_day_end === false;
  const dayEndBlocked =
    blockingRows.length > 0 ||
    blockingFrontStockIssues.length > 0 ||
    operationallyBlocked ||
    payload?.can_run_day_end === false;
  const catchUp = useMemo(() => resolveCatchUp(payload), [payload]);

  const totals = useMemo(() => {
    return {
      days: rows.length,
      closed: rows.filter((row) => row.is_closed).length,
      missing: missingRows.length,
      blocking: blockingRows.length,
      forced: rows.filter((row) => String(row.method || "").includes("force")).length,
    };
  }, [rows, missingRows.length, blockingRows.length]);

  const toggleDate = (date) => {
    setSelectedDates((prev) =>
      prev.includes(date) ? prev.filter((item) => item !== date) : [...prev, date],
    );
  };

  const selectAllMissing = () => {
    setSelectedDates(
      missingRows.filter((row) => row.can_force_close !== false).map((row) => row.date),
    );
  };

  const forceCloseSelected = async () => {
    if (selectedDates.length === 0) {
      showNotice("Select at least one missing front stock closing day.");
      return;
    }

    setConfirmAction({
      type: "force-close",
      title: "Backfill Missing Stock Closings",
      message:
        "This creates audited stock-closing records for the selected missed dates using system expected stock. It prepares the checks only and does not close or advance the branch business date.",
    });
  };

  const executeForceCloseSelected = async () => {
    try {
      setSaving(true);
      setConfirmAction(null);
      const res = await forceCloseFrontStockDays({
        dates: selectedDates,
        reason,
        ...(branchId ? { branch_id: branchId } : {}),
      });
      showNotice(
        res?.message ||
          "Missing stock-closing records were backfilled. The branch business date has not changed; run Day End or Catch Up to Today after every blocker is cleared.",
      );
      setSelectedDates([]);
      await load();
      onCompleted?.();
    } catch (error) {
      showNotice(getApiMessage(error, "Failed to backfill the selected stock-closing records."));
    } finally {
      setSaving(false);
    }
  };

  const requestRunDayEnd = () => {
    if (blockingFrontStockIssues.length > 0) {
      showNotice(
        `${blockingFrontStockIssues.length} menu-item ingredient issue(s) are still negative. Issue the listed stock from BOH to FRONT before reconciliation and Day End.`,
      );
      return;
    }

    if (blockingRows.length > 0) {
      showNotice("Day End is blocked. Complete and save the active trading date's Front Stock Reconciliation first.");
      return;
    }

    if (blockingOpenShifts.length > 0) {
      showNotice(
        `${blockingOpenShifts.length} branch shift(s) are still open. Complete cashup and close every shift before Day End.`,
      );
      return;
    }

    if (uncashedSaleCount > 0) {
      showNotice(
        `${uncashedSaleCount} completed transaction(s) are not covered by a completed cashup for this trading date.`,
      );
      return;
    }

    if (dayEndBlocked) {
      showNotice(
        operationalReasons.join("\n") ||
          payload?.enterprise_message ||
          "Day End is not ready. Refresh the preflight and resolve the listed controls.",
      );
      return;
    }

    setConfirmAction({
      type: "run-day-end",
      title: "Run Day End",
      message:
        "This will close the current trading day, generate reconciliation, lock POS during processing, then open the next eligible trading day.",
    });
  };

  const requestCatchUpToToday = () => {
    if (!catchUp.available) {
      showNotice("Catch Up to Today is not available for this branch.");
      return;
    }

    if (blockingFrontStockIssues.length > 0) {
      showNotice(
        "Catch Up is blocked until the listed negative recipe ingredients are issued from BOH to FRONT.",
      );
      return;
    }

    if (blockingRows.length > 0) {
      showNotice(
        "Catch Up is blocked. Complete and save the active trading date's Front Stock Reconciliation first.",
      );
      return;
    }

    if (blockingOpenShifts.length > 0) {
      showNotice(
        `${blockingOpenShifts.length} branch shift(s) are still open. Complete cashup and close every shift before Catch Up.`,
      );
      return;
    }

    if (uncashedSaleCount > 0) {
      showNotice(
        `${uncashedSaleCount} completed transaction(s) are not covered by a completed cashup for this trading date.`,
      );
      return;
    }

    if (dayEndBlocked) {
      showNotice(
        operationalReasons.join("\n") ||
          payload?.enterprise_message ||
          "Catch Up is not ready. Refresh the preflight and resolve the listed controls.",
      );
      return;
    }

    setCatchUpReason("");
    setCatchUpConfirmation("");
    setConfirmAction({
      type: "catch-up",
      title: "Catch Up to Today",
      message:
        "This audited branch-only recovery will finalize the current lagging trading day and open today's system date directly.",
    });
  };

  const executeCatchUpToToday = async () => {
    const cleanReason = String(catchUpReason).trim();
    const cleanConfirmation = String(catchUpConfirmation).trim().toUpperCase();

    if (cleanReason.length < 5) {
      showNotice("Enter a reason of at least 5 characters for this audited Catch Up.");
      return;
    }

    if (cleanConfirmation !== "CATCH UP") {
      showNotice("Type CATCH UP exactly to confirm this branch recovery.");
      return;
    }

    if (!catchUp.sourceTradingDate || !catchUp.targetSystemDate) {
      showNotice("Catch Up dates are incomplete. Refresh the preflight before continuing.");
      return;
    }

    try {
      setSaving(true);
      setConfirmAction(null);
      const res = await catchUpTradingDayEnd({
        ...(branchId ? { branch_id: branchId } : {}),
        run_key: newRunKey(),
        expected_trading_date: catchUp.sourceTradingDate,
        expected_system_date: catchUp.targetSystemDate,
        reason: cleanReason,
        confirmation_token: "CATCH_UP_TO_SYSTEM_DATE",
      });
      showNotice(res?.message || "This branch was caught up to today's system date.");
      setCatchUpReason("");
      setCatchUpConfirmation("");
      await load();
      onCompleted?.();
    } catch (error) {
      const data = error?.response?.data;
      if (data?.data) setPayload((prev) => ({ ...(prev || {}), ...(data.data || {}) }));
      showNotice(getApiMessage(error, "Catch Up to Today failed."));
    } finally {
      setSaving(false);
    }
  };

  const executeRunDayEnd = async () => {
    try {
      setSaving(true);
      setConfirmAction(null);
      const res = await runTradingDayEnd({
        ...(branchId ? { branch_id: branchId } : {}),
        expected_trading_date:
          payload?.expected_trading_date || payload?.open_trading_day?.trading_date,
      });
      showNotice(res?.message || "Day End completed.");
      await load();
      onCompleted?.();
    } catch (error) {
      const data = error?.response?.data;
      if (data?.data) setPayload((prev) => ({ ...(prev || {}), ...(data.data || {}) }));
      showNotice(getApiMessage(error, "Day End failed."));
    } finally {
      setSaving(false);
    }
  };

  const rollbackLock = async () => {
    setConfirmAction({
      type: "release-lock",
      title: "Release Day End Lock",
      message:
        "Use this only when Day End was interrupted. The running lock will be released and the trading day will be restored to open.",
    });
  };

  const executeRollbackLock = async () => {
    try {
      setSaving(true);
      setConfirmAction(null);
      const res = await rollbackTradingDayEndLock({
        ...(branchId ? { branch_id: branchId } : {}),
      });
      showNotice(res?.message || "Day End lock released.");
      await load();
      onCompleted?.();
    } catch (error) {
      showNotice(getApiMessage(error, "Failed to release Day End lock."));
    } finally {
      setSaving(false);
    }
  };

  if (!open) return null;

  return (
    <FullScreenModal
      open={open}
      onClose={onClose}
      title="DAY END"
      subtitle="Plain preflight and recovery screen"
    >
      <div className="relative flex h-[calc(100vh-86px)] flex-col overflow-hidden bg-white text-[#034040]">
        {notice && (
          <div className="absolute left-1/2 top-3 z-50 w-[min(760px,calc(100%-32px))] -translate-x-1/2 whitespace-pre-line border border-[#93A6A6] bg-white px-4 py-3 text-[12px] font-normal text-[#034040] shadow-xl">
            {notice}
          </div>
        )}

        {confirmAction && (
          <div className="absolute inset-0 z-[80] flex items-center justify-center bg-black/40 px-4">
            <div className="sx-large-modal-window sx-large-modal-scroll border border-[#CBD6D6] bg-white shadow-2xl" role="dialog" aria-modal="true" aria-label={confirmAction.title}>
              <div className="border-b border-[#E2E9E9] bg-[#F8FAFC] px-5 py-4">
                <div className="text-[15px] font-medium uppercase tracking-normal text-[#091B1B]">
                  {confirmAction.title}
                </div>
                <div className="mt-1 text-[11px] font-normal uppercase text-[#637877]">
                  Confirm action
                </div>
              </div>
              <div className="whitespace-pre-line px-5 py-5 text-[13px] font-normal text-[#324444]">
                {confirmAction.message}
              </div>
              {confirmAction.type === "force-close" && (
                <div className="mx-5 mb-4 border border-[#F59E0B] bg-[#FFFBEB] p-3 text-[12px] font-normal text-[#92400E]">
                  Selected dates: {selectedDates.join(", ") || "None"}<br />
                  Business date remains: {payload?.open_trading_day?.trading_date || "Unresolved"}
                </div>
              )}
              {confirmAction.type === "catch-up" && (
                <div className="px-5 pb-5">
                  <div className="border border-[#D97706] bg-[#FFFBEB] p-3 text-[12px] leading-5 text-[#92400E]">
                    <div className="font-medium uppercase">Branch-only accounting recovery</div>
                    <div className="mt-1">
                      Branch: {branchLabel}<br />
                      Source trading date: {catchUp.sourceTradingDate || "-"}<br />
                      Target system date: {catchUp.targetSystemDate || "-"}<br />
                      Lag: {catchUp.lagDays} day(s)<br />
                      Skipped range: {catchUp.skippedDateFrom || "-"} to {catchUp.skippedDateTo || "-"} ({catchUp.skippedDayCount} day(s))
                    </div>
                  </div>
                  <div role="alert" className="mt-3 border border-[#EF4444] bg-[#FEF2F2] p-3 text-[12px] leading-5 text-[#991B1B]">
                    Accumulated sales will stay recorded on the authoritative source trading date. Their historical business dates will not be rewritten.
                  </div>
                  <label className="mt-4 block">
                    <span className="mb-1 block text-[10px] font-medium uppercase text-[#637877]">
                      Audit reason {catchUp.requiresReason ? "· Required" : ""}
                    </span>
                    <textarea
                      value={catchUpReason}
                      onChange={(event) => setCatchUpReason(event.target.value)}
                      rows={3}
                      placeholder="Explain why this branch must catch up to today's system date."
                      className="w-full resize-none border border-[#CBD6D6] bg-white p-2 text-[12px] text-[#324444] outline-none focus:border-[#D97706]"
                    />
                    <span className="mt-1 block text-[10px] text-[#637877]">Minimum 5 characters.</span>
                  </label>
                  <label className="mt-3 block">
                    <span className="mb-1 block text-[10px] font-medium uppercase text-[#637877]">
                      Type CATCH UP to confirm
                    </span>
                    <input
                      type="text"
                      value={catchUpConfirmation}
                      onChange={(event) => setCatchUpConfirmation(event.target.value)}
                      autoComplete="off"
                      placeholder="CATCH UP"
                      className="h-10 w-full border border-[#CBD6D6] bg-white px-3 text-[12px] font-medium uppercase text-[#324444] outline-none focus:border-[#D97706]"
                    />
                  </label>
                </div>
              )}
              <div className="flex justify-end gap-2 border-t border-[#E2E9E9] bg-white px-5 py-4">
                <button
                  type="button"
                  onClick={() => setConfirmAction(null)}
                  className="h-10 w-[110px] border border-[#CBD6D6] bg-white text-[11px] font-medium uppercase tracking-normal text-[#324444]"
                >
                  Cancel
                </button>
                <button
                  type="button"
                  disabled={
                    confirmAction.type === "catch-up" &&
                    (String(catchUpReason).trim().length < 5 ||
                      String(catchUpConfirmation).trim().toUpperCase() !== "CATCH UP")
                  }
                  onClick={() => {
                    if (confirmAction.type === "force-close") executeForceCloseSelected();
                    if (confirmAction.type === "run-day-end") executeRunDayEnd();
                    if (confirmAction.type === "release-lock") executeRollbackLock();
                    if (confirmAction.type === "catch-up") executeCatchUpToToday();
                  }}
                  className={`h-10 min-w-[140px] border px-4 text-[11px] font-medium uppercase tracking-normal disabled:opacity-50 ${
                    confirmAction.type === "catch-up"
                      ? "border-[#D97706] bg-[#F59E0B] text-white"
                      : "border-[#008A8A] bg-[#00BCBC] text-[#062D2A]"
                  }`}
                >
                  {confirmAction.type === "catch-up" ? "Catch Up" : "Confirm"}
                </button>
              </div>
            </div>
          </div>
        )}

        <div className="shrink-0 border-b border-[#D9E1E0] bg-white px-4 py-3">
          <div className="flex flex-wrap items-end gap-2">
            <div className="mr-auto min-w-[260px]">
              <div className="text-[16px] font-medium uppercase tracking-normal text-[#091B1B]">
                Day End Guard
              </div>
              <div className="mt-1 text-[12px] font-normal text-[#637877]">
                Branch: {branchLabel} · Trading day: {payload?.open_trading_day?.trading_date || "-"} / {payload?.open_trading_day?.status || "-"}
              </div>
            </div>

            <label className="w-[170px]">
              <div className="mb-1 text-[10px] font-medium uppercase tracking-normal text-[#637877]">
                Check up to
              </div>
              <input
                type="date"
                value={dateTo}
                onChange={(event) => setDateTo(event.target.value)}
                className="h-10 w-full border border-[#CBD6D6] bg-white px-3 text-[12px] font-normal outline-none focus:border-[#00BCBC]"
              />
            </label>

            <button
              type="button"
              onClick={load}
              disabled={loading || saving}
              className="h-10 border border-[#CBD6D6] bg-white px-4 text-[11px] font-medium uppercase tracking-normal text-[#324444] disabled:opacity-60"
            >
              {loading ? "Checking" : "Refresh"}
            </button>
            <button
              type="button"
              onClick={selectAllMissing}
              disabled={missingRows.length === 0 || saving}
              className="h-10 border border-[#CBD6D6] bg-[#F8FAFC] px-4 text-[11px] font-medium uppercase tracking-normal text-[#324444] disabled:opacity-50"
            >
              Select Missing
            </button>
            <button
              type="button"
              onClick={forceCloseSelected}
              disabled={selectedDates.length === 0 || saving}
              className="h-10 border border-[#D97706] bg-[#FFFBEB] px-4 text-[11px] font-medium uppercase tracking-normal text-[#92400E] disabled:opacity-50"
            >
              Backfill Missing Stock
            </button>
            {catchUp.available && (
              <button
                type="button"
                onClick={requestCatchUpToToday}
                disabled={saving || loading || dayEndBlocked}
                className="h-10 border border-[#D97706] bg-[#FFFBEB] px-4 text-[11px] font-medium uppercase tracking-normal text-[#92400E] disabled:opacity-50"
              >
                Catch Up to Today
              </button>
            )}
            <button
              type="button"
              onClick={requestRunDayEnd}
              disabled={saving || loading || !payload || dayEndBlocked}
              className="h-10 border border-[#008A8A] bg-[#00BCBC] px-5 text-[11px] font-medium uppercase tracking-normal text-[#062D2A] disabled:opacity-50"
            >
              Run Day End
            </button>
          </div>
        </div>

        <div className="shrink-0 border-b border-[#E2E9E9] bg-[#F8FAFC] px-4 py-3">
          <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-10">
            <Stat label="Days" value={totals.days} />
            <Stat label="Closed" value={totals.closed} tone="good" />
            <Stat label="Missing" value={totals.missing} tone={totals.missing > 0 ? "bad" : "normal"} />
            <Stat label="Blocking" value={totals.blocking} tone={totals.blocking > 0 ? "bad" : "normal"} />
            <Stat label="Backfilled" value={totals.forced} tone={totals.forced > 0 ? "warn" : "normal"} />
            <Stat label="Open Shifts" value={blockingOpenShifts.length} tone={blockingOpenShifts.length > 0 ? "bad" : "good"} />
            <Stat label="Uncashed Sales" value={uncashedSaleCount} tone={uncashedSaleCount > 0 ? "bad" : "good"} />
            <Stat
              label="Negative Ingredients"
              value={frontStockIssueReadiness?.negative_ingredient_count || 0}
              tone={blockingFrontStockIssues.length > 0 ? "bad" : "good"}
            />
            <Stat
              label="Affected Menu Items"
              value={frontStockIssueReadiness?.affected_menu_item_count || 0}
              tone={blockingFrontStockIssues.length > 0 ? "bad" : "good"}
            />
            <Stat label="Trading Date Lag" value={`${catchUp.lagDays}d`} tone={catchUp.available ? "warn" : "good"} />
          </div>
        </div>

        <div className="min-h-0 flex-1 overflow-hidden p-4">
          <div className="grid h-full min-h-0 gap-4 xl:grid-cols-[minmax(0,1fr)_340px]">
            <section className="flex min-h-0 flex-col border border-[#D9E1E0] bg-white">
              <div className="flex h-11 shrink-0 items-center justify-between border-b border-[#D9E1E0] bg-white px-3">
                <div>
                  <div className="text-[13px] font-medium uppercase tracking-normal text-[#091B1B]">
                    Front Stock Closing Calendar
                  </div>
                  <div className="text-[11px] font-normal text-[#637877]">
                    {selectedDates.length} selected
                  </div>
                </div>
              </div>

              <div className="min-h-0 flex-1 overflow-auto">
                <table className="sx-enterprise-table w-full min-w-[1000px] border-collapse text-[12px]">
                  <thead className="sticky top-0 z-10">
                    <tr className="border-b border-[#CBD6D6] bg-[#F8FAFC] text-[10px] font-medium uppercase tracking-normal text-[#465858]">
                      <th className="w-[52px] px-3 py-2 text-left">Pick</th>
                      <th className="px-3 py-2 text-left">Trading Date</th>
                      <th className="px-3 py-2 text-left">Status</th>
                      <th className="px-3 py-2 text-left">Method</th>
                      <th className="px-3 py-2 text-right">Lines</th>
                      <th className="px-3 py-2 text-right">Variance Lines</th>
                      <th className="px-3 py-2 text-right">Variance Value</th>
                      <th className="px-3 py-2 text-left">Impact</th>
                    </tr>
                  </thead>
                  <tbody>
                    {rows.map((row) => {
                      const selected = selectedDates.includes(row.date);
                      return (
                        <tr
                          key={row.date}
                          className={`border-b border-[#EEF2F2] ${
                            row.blocks_day_end
                              ? "bg-[#FEF2F2]"
                              : selected
                                ? "bg-[#ECFDF5]"
                                : "bg-white hover:bg-[#F8FAFC]"
                          }`}
                        >
                          <td className="px-3 py-2">
                            <input
                              type="checkbox"
                              checked={selected}
                              disabled={
                                row.is_closed || row.can_force_close === false || saving
                              }
                              onChange={() => toggleDate(row.date)}
                            />
                          </td>
                          <td className="px-3 py-2 font-medium text-[#091B1B]">{row.date}</td>
                          <td className="px-3 py-2">
                            <Status value={row.status} closed={row.is_closed} />
                          </td>
                          <td className="px-3 py-2 font-normal text-[#465858]">{row.method || "-"}</td>
                          <td className="px-3 py-2 text-right font-normal tabular-nums text-[#324444]">
                            {row.total_lines || row.line_count || 0}
                          </td>
                          <td className="px-3 py-2 text-right font-normal tabular-nums text-[#324444]">
                            {row.variance_lines || 0}
                          </td>
                          <td className="px-3 py-2 text-right font-normal tabular-nums text-[#324444]">
                            {fmt(row.variance_total_value)}
                          </td>
                          <td className="px-3 py-2">
                            {row.blocks_day_end ? (
                              <span className="inline-flex border border-[#EF4444] bg-white px-2 py-1 text-[10px] font-medium uppercase text-[#B91C1C]">
                                Physical Reconciliation Required
                              </span>
                            ) : row.is_closed ? (
                              <span className="inline-flex border border-[#10B981] bg-white px-2 py-1 text-[10px] font-medium uppercase text-[#047857]">
                                Ready
                              </span>
                            ) : (
                              <span className="inline-flex border border-[#F59E0B] bg-white px-2 py-1 text-[10px] font-medium uppercase text-[#92400E]">
                                Backlog
                              </span>
                            )}
                          </td>
                        </tr>
                      );
                    })}
                    {rows.length === 0 && (
                      <tr>
                        <td colSpan={8} className="px-3 py-12 text-center text-[12px] font-normal text-[#637877]">
                          No calendar rows found.
                        </td>
                      </tr>
                    )}
                  </tbody>
                </table>
              </div>
            </section>

            <aside className="min-h-0 overflow-auto border border-[#D9E1E0] bg-[#F8FAFC] p-3">
              {blockingFrontStockIssues.length > 0 && (
                <div className="mb-3 border border-[#EF4444] bg-[#FEF2F2] p-3">
                  <div className="text-[12px] font-medium uppercase text-[#B91C1C]">
                    Issue Stock Before Reconciliation
                  </div>
                  <div className="mt-1 text-[11px] leading-5 text-[#7F1D1D]">
                    These recipe ingredients are negative. Issue them from BOH to FRONT; the issue will clear the negative balance. Day End cannot run until this list is empty and the Front Stock Reconciliation is saved.
                  </div>
                  <div className="mt-3 max-h-[260px] space-y-2 overflow-auto">
                    {blockingFrontStockIssues.map((row, index) => (
                      <div
                        key={`${row.menu_product_id || "unmapped"}-${row.ingredient_product_id}-${index}`}
                        className="border border-[#FCA5A5] bg-white p-2 text-[11px] text-[#324444]"
                      >
                        <div className="font-medium text-[#091B1B]">
                          {row.menu_product_name || "Unmapped menu item"}
                          {row.menu_product_code ? ` · ${row.menu_product_code}` : ""}
                        </div>
                        <div className="mt-1">
                          Ingredient: {row.ingredient_name || "-"}
                          {row.ingredient_code ? ` · ${row.ingredient_code}` : ""}
                        </div>
                        <div className="mt-1 font-medium text-[#B91C1C]">
                          FRONT {fmt(row.front_available_quantity, 4)} · Issue at least {fmt(row.issue_quantity_required, 4)}
                        </div>
                      </div>
                    ))}
                  </div>
                  <button
                    type="button"
                    onClick={onOpenIssueStock}
                    disabled={!onOpenIssueStock || saving}
                    className="mt-3 h-10 w-full border border-[#B91C1C] bg-[#DC2626] text-[11px] font-medium uppercase text-white disabled:opacity-50"
                  >
                    Open Issue Stock to Front
                  </button>
                </div>
              )}

              {blockingRows.length > 0 && (
                <div className="mb-3 border border-[#D97706] bg-[#FFFBEB] p-3">
                  <div className="text-[12px] font-medium uppercase text-[#92400E]">
                    Front Stock Reconciliation Required
                  </div>
                  <div className="mt-1 text-[11px] leading-5 text-[#78350F]">
                    The active trading date must be physically counted and saved. It cannot be force closed.
                  </div>
                  <button
                    type="button"
                    onClick={onOpenFrontStockReconciliation}
                    disabled={!onOpenFrontStockReconciliation || saving || blockingFrontStockIssues.length > 0}
                    className="mt-3 h-10 w-full border border-[#D97706] bg-[#F59E0B] text-[11px] font-medium uppercase text-white disabled:opacity-50"
                  >
                    Open Front Stock Reconciliation
                  </button>
                </div>
              )}

              <div className="border border-[#D9E1E0] bg-white p-3">
                <div className="text-[12px] font-medium uppercase tracking-normal text-[#091B1B]">
                  Backfill Audit Reason
                </div>
                <textarea
                  value={reason}
                  onChange={(event) => setReason(event.target.value)}
                  rows={6}
                  className="mt-2 w-full border border-[#CBD6D6] bg-white p-2 text-[12px] font-normal text-[#324444] outline-none focus:border-[#00BCBC]"
                />
              </div>

              <div className="mt-3 border border-[#D9E1E0] bg-white p-3">
                <div className="text-[12px] font-medium uppercase tracking-normal text-[#091B1B]">
                  Actions
                </div>
                <div className="mt-3 space-y-2">
                  <button
                    type="button"
                    onClick={forceCloseSelected}
                    disabled={selectedDates.length === 0 || saving}
                    className="h-10 w-full border border-[#D97706] bg-[#FFFBEB] text-[11px] font-medium uppercase tracking-normal text-[#92400E] disabled:opacity-50"
                  >
                    Backfill Selected Closings
                  </button>
                  <button
                    type="button"
                    onClick={requestRunDayEnd}
                    disabled={saving || loading || !payload || dayEndBlocked}
                    className="h-10 w-full border border-[#008A8A] bg-[#00BCBC] text-[11px] font-medium uppercase tracking-normal text-[#062D2A] disabled:opacity-50"
                  >
                    Run Day End
                  </button>
                  {catchUp.available && (
                    <button
                      type="button"
                      onClick={requestCatchUpToToday}
                      disabled={saving || loading || dayEndBlocked}
                      className="h-10 w-full border border-[#D97706] bg-[#FFFBEB] text-[11px] font-medium uppercase tracking-normal text-[#92400E] disabled:opacity-50"
                    >
                      Catch Up to Today
                    </button>
                  )}
                  <button
                    type="button"
                    onClick={rollbackLock}
                    disabled={saving}
                    className="h-10 w-full border border-[#CBD6D6] bg-white text-[11px] font-medium uppercase tracking-normal text-[#324444] disabled:opacity-50"
                  >
                    Release Lock
                  </button>
                </div>
              </div>

              {(catchUp.available || catchUp.lagDays > 0) && (
                <div aria-label="Branch trading-date catch-up summary" className="mt-3 border border-[#D97706] bg-[#FFFBEB] p-3">
                  <div className="text-[12px] font-medium uppercase text-[#92400E]">
                    Trading Date Catch Up
                  </div>
                  <dl className="mt-2 grid grid-cols-[1fr_auto] gap-x-3 gap-y-2 text-[11px] leading-5 text-[#324444]">
                    <dt>Branch</dt><dd className="text-right font-medium">{branchLabel}</dd>
                    <dt>Lag</dt><dd className="text-right font-medium">{catchUp.lagDays} day(s)</dd>
                    <dt>Source date</dt><dd className="text-right font-medium">{catchUp.sourceTradingDate || "-"}</dd>
                    <dt>Target date</dt><dd className="text-right font-medium">{catchUp.targetSystemDate || "-"}</dd>
                    <dt>Skipped range</dt><dd className="text-right font-medium">{catchUp.skippedDateFrom || "-"} to {catchUp.skippedDateTo || "-"}</dd>
                    <dt>Skipped days</dt><dd className="text-right font-medium">{catchUp.skippedDayCount}</dd>
                  </dl>
                  <div className="mt-3 border-t border-[#FCD34D] pt-3 text-[11px] leading-5 text-[#92400E]">
                    This closes only the branch's lagging business day. Accumulated sales remain on the source trading date; other branches are not changed.
                  </div>
                </div>
              )}

              <div className={`mt-3 border p-3 ${dayEndBlocked ? "border-[#EF4444] bg-[#FEF2F2]" : "border-[#10B981] bg-[#ECFDF5]"}`}>
                <div className={`text-[12px] font-medium uppercase ${dayEndBlocked ? "text-[#B91C1C]" : "text-[#047857]"}`}>
                  Shift &amp; Cashup Readiness
                </div>
                <div className="mt-2 grid grid-cols-2 gap-2 text-[11px] font-normal text-[#324444]">
                  <div className="border border-current/20 bg-white px-2 py-2">
                    <div className="text-[10px] uppercase text-[#637877]">Open shifts</div>
                    <div className="mt-1 text-[16px] font-medium tabular-nums">{blockingOpenShifts.length}</div>
                  </div>
                  <div className="border border-current/20 bg-white px-2 py-2">
                    <div className="text-[10px] uppercase text-[#637877]">Uncashed sales</div>
                    <div className="mt-1 text-[16px] font-medium tabular-nums">{uncashedSaleCount}</div>
                  </div>
                </div>

                {operationalReasons.length > 0 && (
                  <div className="mt-2 space-y-1 text-[11px] leading-5 text-[#7F1D1D]">
                    {operationalReasons.map((item) => <div key={item}>{item}</div>)}
                  </div>
                )}

                {blockingOpenShifts.length > 0 && (
                  <div className="mt-2 max-h-[116px] overflow-auto border border-[#FCA5A5] bg-white">
                    {blockingOpenShifts.map((shift) => (
                      <div key={shift.shift_session_id} className="border-b border-[#FEE2E2] px-2 py-2 text-[10px] last:border-0">
                        <div className="font-medium text-[#7F1D1D]">{shift.cashier_name || "Unknown cashier"}</div>
                        <div className="mt-0.5 text-[#637877]">{shift.shift_name || "Shift"} · opened {shift.opened_at || "-"}</div>
                      </div>
                    ))}
                  </div>
                )}
              </div>

              <div className="mt-3 border border-[#F59E0B] bg-[#FFFBEB] p-3 text-[11px] font-normal leading-5 text-[#92400E]">
                Backfill only prepares missing historical stock-closing records; it never changes the business date. Only a successful Run Day End or Catch Up to Today advances this branch.
              </div>
            </aside>
          </div>
        </div>
      </div>
    </FullScreenModal>
  );
};

const Stat = ({ label, value, tone = "normal" }) => {
  const textClass =
    tone === "good"
      ? "text-[#047857]"
      : tone === "warn"
        ? "text-[#92400E]"
        : tone === "bad"
          ? "text-[#B91C1C]"
          : "text-[#091B1B]";

  return (
    <div className="border border-[#D9E1E0] bg-white px-3 py-2">
      <div className="text-[10px] font-medium uppercase tracking-normal text-[#637877]">{label}</div>
      <div className={`mt-1 text-[20px] font-medium leading-none ${textClass}`}>{value}</div>
    </div>
  );
};

const Status = ({ value, closed }) => (
  <span
    className={`inline-flex border px-2 py-1 text-[10px] font-medium uppercase ${
      closed
        ? "border-[#10B981] bg-white text-[#047857]"
        : "border-[#EF4444] bg-white text-[#B91C1C]"
    }`}
  >
    {value || "-"}
  </span>
);

export default DayEndEnterpriseGuardModal;
