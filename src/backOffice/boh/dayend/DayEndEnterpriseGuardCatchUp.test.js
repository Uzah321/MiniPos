import fs from "fs";
import path from "path";

const read = (relativePath) =>
  fs.readFileSync(path.join(__dirname, relativePath), "utf8");

describe("Back Office branch trading-date catch-up guard", () => {
  const guard = read("DayEndEnterpriseGuardModal.js");
  const adminLayout = read("../../AdminLayOut.js");
  const service = read("../../../services/TradingDayEndEnterpriseService.js");

  test("shows the recovery only when branch preflight declares it available", () => {
    expect(guard).toContain("const catchUp = useMemo(() => resolveCatchUp(payload), [payload]);");
    expect(guard).toContain("{catchUp.available && (");
    expect(guard).toContain("Catch Up to Today");
    expect(guard).toContain('aria-label="Branch trading-date catch-up summary"');
    expect(guard).toContain("Source date");
    expect(guard).toContain("Target date");
    expect(guard).toContain("Skipped range");
    expect(guard).toContain("Skipped days");
  });

  test("uses the existing branch readiness blockers before catch-up", () => {
    expect(guard).toContain("if (blockingRows.length > 0)");
    expect(guard).toContain("if (blockingOpenShifts.length > 0)");
    expect(guard).toContain("if (uncashedSaleCount > 0)");
    expect(guard).toContain("if (dayEndBlocked)");
    expect(guard).toContain("disabled={saving || loading || dayEndBlocked}");
  });

  test("requires an audit reason and typed confirmation without rewriting sales", () => {
    expect(guard).toContain("String(catchUpReason).trim().length < 5");
    expect(guard).toContain('String(catchUpConfirmation).trim().toUpperCase() !== "CATCH UP"');
    expect(guard).toContain("Accumulated sales will stay recorded on the authoritative source trading date");
    expect(guard).toContain("Their historical business dates will not be rewritten");
  });

  test("submits the branch-scoped audited backend contract and refreshes", () => {
    const requestStart = guard.indexOf("const res = await catchUpTradingDayEnd({");
    const requestEnd = guard.indexOf("});", requestStart);
    const request = guard.slice(requestStart, requestEnd);

    expect(request).toContain("branch_id: branchId");
    expect(request).toContain("run_key: newRunKey()");
    expect(request).toContain("expected_trading_date: catchUp.sourceTradingDate");
    expect(request).toContain("expected_system_date: catchUp.targetSystemDate");
    expect(request).toContain("reason: cleanReason");
    expect(request).toContain('confirmation_token: "CATCH_UP_TO_SYSTEM_DATE"');
    expect(guard.slice(requestEnd)).toContain("await load();");
    expect(service).toContain("export const catchUpTradingDayEnd");
    expect(service).toContain("/day-end/catch-up");
  });

  test("passes the active Back Office branch into the guard and preflight", () => {
    expect(adminLayout).toContain("const activeBranchId =");
    expect(adminLayout).toContain("branchId={activeBranchId}");
    expect(guard).toContain("...(branchId ? { branch_id: branchId } : {})");
    expect(guard).toContain("const res = await forceCloseFrontStockDays({");
    expect(guard).toContain("const res = await runTradingDayEnd({");
    expect(guard).toContain("const res = await rollbackTradingDayEndLock({");
  });

  test("does not present stock-closing backfill as a completed Day End", () => {
    expect(guard).toContain("Backfill Missing Stock Closings");
    expect(guard).toContain("Backfill Missing Stock");
    expect(guard).toContain("Backfill Selected Closings");
    expect(guard).toContain("does not close or advance the branch business date");
    expect(guard).toContain("Business date remains:");
    expect(guard).toContain("Only a successful Run Day End or Catch Up to Today advances this branch");
    expect(guard).not.toContain(">\n              Force Close\n");
    expect(guard).not.toContain("Force Close Selected");
  });
});
