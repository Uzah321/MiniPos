import fs from "fs";
import path from "path";

const read = (relativePath) =>
  fs.readFileSync(path.join(__dirname, relativePath), "utf8");

describe("Hospitality Front Office trading-day workflow", () => {
  const frontOffice = read("FrontOfficeLayout.js");
  const tradingDayService = read("../services/TradingDayEndEnterpriseService.js");
  const adminLayout = read("../backOffice/AdminLayOut.js");
  const bohAdminLayout = read("../backOffice/BohAdminLayout.js");
  const dayEndGuard = read("../backOffice/boh/dayend/DayEndEnterpriseGuardModal.js");

  test("shows trading and system dates plus Day End in the hospitality-only POS", () => {
    expect(frontOffice).toContain('aria-label="Hospitality trading and system dates"');
    expect(frontOffice).toContain(">Trading Date</span>");
    expect(frontOffice).toContain(">System Date</span>");
    expect(frontOffice).toContain('aria-label="Current Hospitality dates"');
    expect(frontOffice).toContain("Authoritative Trading / Business Date");
    expect(frontOffice).toContain("System / Current Date");
    expect(frontOffice).toContain('<DateCard label="Shop"');
    expect(frontOffice).toContain(
      '{dayEndAllowed && <PosTile icon={CalendarCheck2} label="Day End" onClick={openDayEnd} />}',
    );
    expect(frontOffice).toContain("{dayEndAllowed && (");
    expect(frontOffice).toContain("<DayEndRunModal");
    expect(frontOffice).toContain("const [selling, setSelling] = useState(false);");
    expect(frontOffice).toContain("if (workspaceLoading) return;");
    expect(frontOffice).toContain("setSelling(false);");
    expect(frontOffice).not.toContain("resolvedModeRef");
    expect(frontOffice).not.toContain("isHospitality");
    expect(frontOffice).toContain("user: workspaceUser");
    expect(frontOffice).toContain("workspaceUser?.permissions ?? user?.permissions");
    expect(frontOffice).toContain("capabilities?.permissions");
    expect(frontOffice).toContain("capabilities?.general_procedure");
    expect(frontOffice).toContain("!workspaceLoading && canAccessDayEnd(dayEndPermissionUser, capabilities)");
    expect(frontOffice).toContain("tradingDay?.sale_allowed ?? tradingDay?.can_sell");
    expect(frontOffice).toContain("hospitalitySaleAllowed !== false");
    expect(frontOffice).toContain("dayEndRun.status !== \"running\"");
    expect(frontOffice).not.toContain("New orders are unavailable.");
    expect(frontOffice).not.toContain('bg-[#FFF7D6]');
    expect(frontOffice).toContain("Front Office attention required.");
    expect(frontOffice).toContain('aria-label={`Trading date availability: ${tradingDateAvailability.label}. ${tradingDateAvailability.title}`}');
    expect(frontOffice).toContain('label: "Checking trading date"');
    expect(frontOffice).toContain('label: "Orders locked"');
    expect(frontOffice).toContain('label: "Orders ready"');
    expect(frontOffice).toContain('tradingDateAvailability.pulse ? "animate-pulse" : ""');
    expect(frontOffice).toContain("tradingDay?.block_reason");
    expect(frontOffice).toContain("tradingDay?.sale_block_reason");
    expect(frontOffice).toContain("disabled={!canStartSale}");
    expect(frontOffice).not.toContain("if (isHospitality && !canStartSale) setSelling(false)");
  });

  test("uses an app confirmation and right-to-left staged progress", () => {
    expect(frontOffice).toContain('role="dialog"');
    expect(frontOffice).toContain('aria-labelledby="foh-day-end-title"');
    expect(frontOffice).toContain('role="progressbar"');
    expect(frontOffice).toContain("absolute inset-y-0 right-0");
    expect(frontOffice).toContain("fallbackStage(nextPercent)");
    expect(frontOffice).toContain("progress.stage_label");
    expect(frontOffice).not.toContain("window.confirm");
  });

  test("runs and polls Day End without a till payload", () => {
    expect(frontOffice).toContain("getCurrentTradingDay(");
    expect(frontOffice).toContain('window.addEventListener("salexa_workspace_changed", refreshSyncedTradingDay)');
    expect(frontOffice).toContain('window.removeEventListener("salexa_workspace_changed", refreshSyncedTradingDay)');
    expect(frontOffice).toContain('window.addEventListener("focus", refreshWhenAvailable)');
    expect(frontOffice).toContain('window.addEventListener("online", refreshWhenAvailable)');
    expect(frontOffice).toContain('document.addEventListener("visibilitychange", refreshWhenVisible)');
    expect(frontOffice).toContain("const tradingDayRequestIdRef = useRef(0)");
    expect(frontOffice).toContain("requestId !== tradingDayRequestIdRef.current");
    expect(frontOffice).toContain("getDayEndPreflight(");
    expect(frontOffice).toContain("branch?.id ? { branch_id: branch.id } : {}");
    expect(frontOffice).toContain("getTradingDayEndProgress(");
    expect(frontOffice).toContain(": runTradingDayEnd({");
    expect(frontOffice).toContain("run_key: runKey");
    expect(frontOffice).toContain("expected_trading_date: activeTradingDate");
    expect(frontOffice).toContain("let progressPromise = settleProgress(startDayEndProgress(runKey, token));");
    expect(frontOffice).toContain('["pending", "running"].includes(requestStatus)');
    expect(frontOffice).toContain("const progressOutcome = await progressPromise;");
    expect(frontOffice).toContain('progressOutcome.status === "failed"');
    const requestStart = frontOffice.indexOf(": runTradingDayEnd({");
    const requestEnd = frontOffice.indexOf("});", requestStart);
    expect(frontOffice.slice(requestStart, requestEnd)).not.toContain("till");
    expect(tradingDayService).toContain("/day-end/progress/");
    expect(tradingDayService).toContain("encodeURIComponent(runKey)");
  });

  test("loads and displays branch-scoped Day End readiness before enabling Run", () => {
    expect(frontOffice).toContain("const [dayEndPreflight, setDayEndPreflight] = useState(null)");
    expect(frontOffice).toContain("loadDayEndPreflight();");
    expect(frontOffice).toContain("dayEndReadiness?.can_run_day_end !== false");
    expect(frontOffice).toContain("dayEndOperationalReadiness?.can_run_day_end !== false");
    expect(frontOffice).toContain('aria-label="Shop Day End readiness"');
    expect(frontOffice).toContain('label="Front Stock"');
    expect(frontOffice).toContain('label="Open Shifts"');
    expect(frontOffice).toContain('label="Uncashed Sales"');
    expect(frontOffice).toContain("blocking_front_stock_closings");
    expect(frontOffice).toContain("blocking_open_shifts");
    expect(frontOffice).toContain("uncashed_sale_count");
    expect(frontOffice).toContain("disabled={!canRun || loadingDate}");
    expect(frontOffice).toContain("This operation is shop-scoped");
  });

  test("offers an audited, reasoned branch catch-up without rewriting lagging sales", () => {
    expect(frontOffice).toContain("resolveCatchUp");
    expect(frontOffice).toContain("catchUp.available");
    expect(frontOffice).toContain("Catch Up to Today");
    expect(frontOffice).toContain('role="alertdialog"');
    expect(frontOffice).toContain("Accumulated sales will stay recorded on their authoritative lagging trading date");
    expect(frontOffice).toContain("Type CATCH UP to confirm");
    expect(frontOffice).toContain('String(catchUpConfirmation).trim().toUpperCase() !== "CATCH UP"');
    expect(frontOffice).toContain("catchUpTradingDayEnd({");
    expect(frontOffice).toContain('confirmation_token: "CATCH_UP_TO_SYSTEM_DATE"');
    expect(frontOffice).toContain("expected_trading_date: activeTradingDate");
    expect(frontOffice).toContain("expected_system_date: catchUpContract.targetSystemDate");
    expect(frontOffice).toContain("reason: String(reason).trim()");
    expect(tradingDayService).toContain("/day-end/catch-up");
    expect(tradingDayService).toContain("export const catchUpTradingDayEnd");
  });

  test("all active Day End callers send the confirmed date and never require a till", () => {
    const frontRequestStart = frontOffice.indexOf(": runTradingDayEnd({");
    const frontRequestEnd = frontOffice.indexOf("});", frontRequestStart);
    const frontRequest = frontOffice.slice(frontRequestStart, frontRequestEnd);
    expect(frontRequest).toContain("expected_trading_date: activeTradingDate");
    expect(frontRequest).not.toContain("till");

    const guardRequestStart = dayEndGuard.indexOf("const res = await runTradingDayEnd({");
    const guardRequestEnd = dayEndGuard.indexOf("});", guardRequestStart);
    const guardRequest = dayEndGuard.slice(guardRequestStart, guardRequestEnd);
    expect(guardRequest).toContain("payload?.expected_trading_date");
    expect(guardRequest).toContain("payload?.open_trading_day?.trading_date");
    expect(guardRequest).not.toContain("till");

    const bohRequestStart = bohAdminLayout.lastIndexOf(
      "const result = await runTradingDayEnd({",
    );
    const bohRequestEnd = bohAdminLayout.indexOf("});", bohRequestStart);
    const bohRequest = bohAdminLayout.slice(bohRequestStart, bohRequestEnd);
    expect(bohRequest).toContain("expected_trading_date: currentTradingDay");
    expect(bohRequest).not.toContain("till");
  });

  test("back-office Day End blocks advancement until shifts and cashups are complete", () => {
    expect(dayEndGuard).toContain("operational_readiness");
    expect(dayEndGuard).toContain("blocking_open_shifts");
    expect(dayEndGuard).toContain("uncashed_sale_count");
    expect(dayEndGuard).toContain("const dayEndBlocked =");
    expect(dayEndGuard).toContain("Shift &amp; Cashup Readiness");
    expect(dayEndGuard).toContain("Branch: {branchLabel}");
    expect(dayEndGuard).toContain("disabled={saving || loading || !payload || dayEndBlocked}");
  });

  test("switches polling to the backend-authoritative key for a resumed 202 run", () => {
    expect(frontOffice).toContain("const activeRunKey = authoritativeRunKey(requestResult, runKey);");
    expect(frontOffice).toContain("payload?.progress_url");
    expect(frontOffice).toContain("if (activeRunKey !== runKey)");
    expect(frontOffice).toContain("stopDayEndTimers();");
    expect(frontOffice).toContain("startDayEndProgress(activeRunKey, token)");
    expect(frontOffice).toContain("dayEndProgressKey.current !== runKey");
    expect(frontOffice).toContain("runKey: activeRunKey");
  });

  test("removes only General Procedure Month End and preserves strict mode navigation", () => {
    const hospitalityStart = adminLayout.indexOf("const hospitalityOperationalNavigation");
    const inventoryStart = adminLayout.indexOf('name: "Inventory"', hospitalityStart);
    const generalProcedure = adminLayout.slice(hospitalityStart, inventoryStart);

    expect(generalProcedure).toContain('name: "Run Day End"');
    expect(generalProcedure).not.toContain('name: "Monthend"');
    expect(generalProcedure).not.toContain('modal: "monthend-management"');
    expect(adminLayout).toContain('name: "Back Stock Month-End Stock Take"');
    expect(adminLayout).toContain("...hospitalityOperationalNavigation");
    expect(adminLayout).not.toContain("retailOperationalNavigation");
  });
});
