import React, { useEffect, useMemo, useRef, useState } from "react";
import { getShiftCompliance } from "../services/CashupService";

const DEFAULT_SHIFT_END_TIMES = {
  morning: "14:00",
  afternoon: "22:00",
  evening: "22:00",
  night: "06:00",
};

const minutesBetween = (targetDate, nowDate = new Date()) => {
  if (!targetDate) return null;

  const target = new Date(targetDate).getTime();
  const now = new Date(nowDate).getTime();

  if (Number.isNaN(target) || Number.isNaN(now)) return null;

  return Math.ceil((target - now) / 60000);
};

const secondsBetween = (targetDate, nowDate = new Date()) => {
  if (!targetDate) return null;

  const target = new Date(targetDate).getTime();
  const now = new Date(nowDate).getTime();

  if (Number.isNaN(target) || Number.isNaN(now)) return null;

  return Math.floor((target - now) / 1000);
};

const formatDateTime = (value) => {
  if (!value) return "-";

  const date = new Date(value);

  if (Number.isNaN(date.getTime())) return String(value);

  return date.toLocaleString("en-GB", {
    day: "2-digit",
    month: "short",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  });
};

const formatTimeOnly = (value) => {
  if (!value) return "-";

  const date = new Date(value);

  if (!Number.isNaN(date.getTime())) {
    return date.toLocaleTimeString("en-GB", {
      hour: "2-digit",
      minute: "2-digit",
      second: "2-digit",
      hour12: false,
    });
  }

  const raw = String(value);
  const match = raw.match(/^(\d{1,2}):(\d{2})(?::(\d{2}))?/);

  if (!match) return raw;

  return `${match[1].padStart(2, "0")}:${match[2]}:${match[3] || "00"}`;
};

const formatShiftName = (value) => {
  if (!value) return "No active shift";

  return String(value)
    .replaceAll("_", " ")
    .replace(/\b\w/g, (char) => char.toUpperCase());
};

const formatCountdown = (seconds) => {
  if (seconds === null || seconds === undefined) return "";

  const absolute = Math.abs(Number(seconds || 0));
  const hours = Math.floor(absolute / 3600);
  const minutes = Math.floor((absolute % 3600) / 60);
  const secs = absolute % 60;

  const clock = `${String(hours).padStart(2, "0")}:${String(minutes).padStart(
    2,
    "0"
  )}:${String(secs).padStart(2, "0")}`;

  if (seconds < 0) return `Overdue ${clock}`;

  return clock;
};

const normalizeShiftName = (activeShift) =>
  String(activeShift?.shift_name || "").toLowerCase().replaceAll(" ", "_");

const getShiftConfig = (activeShift, compliance) => {
  return (
    activeShift?.shift_configuration ||
    activeShift?.shiftConfiguration ||
    activeShift?.configuration ||
    compliance?.shift_configuration ||
    compliance?.shiftConfiguration ||
    compliance?.configuration ||
    null
  );
};

const firstValue = (...values) => {
  for (const value of values) {
    if (value !== null && value !== undefined && value !== "") return value;
  }

  return null;
};

const getShiftEndTime = (activeShift, compliance) => {
  const config = getShiftConfig(activeShift, compliance);
  const name = normalizeShiftName(activeShift);

  /*
   * Best case: backend sends an exact datetime.
   */
  const exactEnd = firstValue(
    activeShift?.shift_end_at,
    activeShift?.expected_end_at,
    activeShift?.scheduled_end_at,
    activeShift?.end_at,
    activeShift?.configuration_end_at,
    activeShift?.shift_configuration?.end_at,
    activeShift?.shiftConfiguration?.end_at
  );

  if (exactEnd && String(exactEnd).includes("T")) return exactEnd;
  if (exactEnd && String(exactEnd).match(/^\d{4}-\d{2}-\d{2}/)) return exactEnd;

  /*
   * Config-based time. Support many backend naming conventions.
   */
  if (name === "morning") {
    return firstValue(
      config?.morning_shift_end,
      config?.morning_end,
      config?.morning_end_time,
      activeShift?.morning_shift_end
    );
  }

  if (name === "afternoon" || name === "evening") {
    return firstValue(
      config?.afternoon_shift_end,
      config?.afternoon_end,
      config?.afternoon_end_time,
      config?.evening_shift_end,
      activeShift?.afternoon_shift_end
    );
  }

  if (name === "night") {
    return firstValue(
      config?.night_shift_end,
      config?.night_end,
      config?.night_end_time,
      activeShift?.night_shift_end
    );
  }

  return firstValue(
    config?.shift_end,
    config?.end_time,
    activeShift?.shift_end,
    activeShift?.end_time,
    DEFAULT_SHIFT_END_TIMES[name]
  );
};

const getDefaultShiftEndTime = (activeShift) => {
  const name = normalizeShiftName(activeShift);

  return DEFAULT_SHIFT_END_TIMES[name] || null;
};

const buildShiftEndDate = (activeShift, compliance, nowDate = new Date()) => {
  if (!activeShift) return null;

  const rawEnd = getShiftEndTime(activeShift, compliance) || getDefaultShiftEndTime(activeShift);

  if (!rawEnd) return null;

  const exact = new Date(rawEnd);

  if (!Number.isNaN(exact.getTime()) && String(rawEnd).match(/^\d{4}-\d{2}-\d{2}|T/)) {
    return exact;
  }

  const opened = activeShift?.opened_at ? new Date(activeShift.opened_at) : null;
  const baseDate =
    opened && !Number.isNaN(opened.getTime())
      ? new Date(opened)
      : new Date(nowDate);

  const [hhRaw, mmRaw, ssRaw] = String(rawEnd).slice(0, 8).split(":");
  const hh = Number(hhRaw);
  const mm = Number(mmRaw || 0);
  const ss = Number(ssRaw || 0);

  if (Number.isNaN(hh) || Number.isNaN(mm)) return null;

  const target = new Date(
    baseDate.getFullYear(),
    baseDate.getMonth(),
    baseDate.getDate(),
    hh,
    mm,
    ss || 0
  );

  const name = normalizeShiftName(activeShift);

  /*
   * Night shift commonly ends the following day.
   */
  if (name === "night" && opened && target.getTime() <= opened.getTime()) {
    target.setDate(target.getDate() + 1);
  }

  /*
   * If the opened time is after the configured end time, this shift belongs to
   * a next-day end window.
   */
  if (opened && target.getTime() <= opened.getTime()) {
    target.setDate(target.getDate() + 1);
  }

  return target;
};

const getServerNow = (compliance, tick) => {
  if (!compliance?.server_time) return new Date(tick);

  const server = new Date(compliance.server_time.replace(" ", "T"));
  const loadedAt = compliance.__loadedAt ? new Date(compliance.__loadedAt) : new Date();

  if (Number.isNaN(server.getTime()) || Number.isNaN(loadedAt.getTime())) {
    return new Date(tick);
  }

  const elapsed = Date.now() - loadedAt.getTime();

  return new Date(server.getTime() + elapsed);
};

export default function EnterpriseShiftBanner({
  onOpenCashup,
  onShiftWarning,
  onShiftEnded,
  onShiftGraceExpired,
}) {
  const [compliance, setCompliance] = useState(null);
  const [error, setError] = useState("");
  const [tick, setTick] = useState(Date.now());
  const lastStateRef = useRef("");

  const load = async () => {
    try {
      const res = await getShiftCompliance();
      const data = res?.data || res;

      setCompliance({
        ...data,
        __loadedAt: new Date().toISOString(),
      });
      setError("");
    } catch (err) {
      setError(err?.response?.data?.message || "Shift status unavailable.");
    }
  };

  useEffect(() => {
    load();

    const refreshTimer = window.setInterval(load, 60000);
    const tickTimer = window.setInterval(() => setTick(Date.now()), 1000);

    return () => {
      window.clearInterval(refreshTimer);
      window.clearInterval(tickTimer);
    };
  }, []);

  const activeShift = compliance?.active_shift;
  const unclosed = compliance?.unclosed_shifts || [];
  const policy = compliance?.policy || {};
  const show = policy.show_shift_banner_in_admin !== false;

  const now = useMemo(() => getServerNow(compliance, tick), [compliance, tick]);

  const shiftEndDate = useMemo(() => {
    return buildShiftEndDate(activeShift, compliance, now);
  }, [activeShift, compliance, now]);

  const secondsToEnd = useMemo(() => {
    if (!shiftEndDate) return null;

    return secondsBetween(shiftEndDate, now);
  }, [shiftEndDate, now]);

  const minutesToEnd = useMemo(() => {
    if (!shiftEndDate) return null;

    return minutesBetween(shiftEndDate, now);
  }, [shiftEndDate, now]);

  const warningMinutes = Number(policy.shift_warning_before_end_minutes || 15);
  const graceMinutes = Number(policy.shift_grace_minutes_after_end || 0);

  const isInWarning =
    minutesToEnd !== null && minutesToEnd > 0 && minutesToEnd <= warningMinutes;
  const isEnded = secondsToEnd !== null && secondsToEnd <= 0;
  const isGraceExpired =
    secondsToEnd !== null && secondsToEnd < -(graceMinutes * 60);
  const hasUnclosed = unclosed.length > 0;
  const isNoShift = !activeShift;
  const mustCashup = compliance?.must_cashup_before_new_shift || policy.require_cashup_before_new_shift;
  const canOpenNewShift = compliance?.can_open_new_shift !== false;
  const canContinueCurrentShift = compliance?.can_continue_current_shift !== false;

  useEffect(() => {
    if (!activeShift || secondsToEnd === null) return;

    let nextState = "healthy";

    if (isGraceExpired) nextState = "grace_expired";
    else if (isEnded) nextState = "ended";
    else if (isInWarning) nextState = "warning";

    if (lastStateRef.current === nextState) return;

    lastStateRef.current = nextState;

    const payload = {
      compliance,
      activeShift,
      secondsToEnd,
      minutesToEnd,
      shiftEndDate,
    };

    if (nextState === "warning") {
      window.dispatchEvent(new CustomEvent("salexa_shift_warning", { detail: payload }));
      onShiftWarning?.(payload);
    }

    if (nextState === "ended") {
      window.dispatchEvent(new CustomEvent("salexa_shift_ended", { detail: payload }));
      onShiftEnded?.(payload);
    }

    if (nextState === "grace_expired") {
      window.dispatchEvent(new CustomEvent("salexa_shift_grace_expired", { detail: payload }));
      onShiftGraceExpired?.(payload);
    }
  }, [
    activeShift,
    compliance,
    isEnded,
    isGraceExpired,
    isInWarning,
    minutesToEnd,
    onShiftEnded,
    onShiftGraceExpired,
    onShiftWarning,
    secondsToEnd,
    shiftEndDate,
  ]);

  if (!show) return null;

  if (error) {
    return (
      <div className="shift-banner shift-banner-error">
        <div className="shift-banner-main">
          <div className="shift-banner-left">
            <div className="shift-banner-icon">!</div>

            <div>
              <div className="shift-banner-title">Shift Status Unavailable</div>
              <div className="shift-banner-subtitle">{error}</div>
            </div>
          </div>

          <button
            type="button"
            onClick={load}
            className="shift-banner-button shift-banner-button-warning"
          >
            Retry
          </button>
        </div>

        <ShiftBannerStyles />
      </div>
    );
  }

  if (!compliance) return null;

  const tone =
    isGraceExpired || isEnded || hasUnclosed || isInWarning || isNoShift
      ? "warning"
      : "success";

  const blockedText = !canOpenNewShift && mustCashup
    ? "New shift is blocked until cashup is completed."
    : !canContinueCurrentShift
    ? "Current shift cannot continue under company policy."
    : "";

  const subtitle = (() => {
    if (isNoShift) return "No active shift is currently open.";
    if (isGraceExpired) return `Shift grace period expired. Cashup should be completed now. ${blockedText}`;
    if (isEnded) return `Shift ended. Cashup / end shift is required. ${blockedText}`;
    if (isInWarning) return `Shift is ending soon. Prepare cashup and drawer handover. ${blockedText}`;
    if (hasUnclosed) {
      return `${unclosed.length} open shift${unclosed.length === 1 ? "" : "s"} require cashup. ${blockedText}`;
    }
    return "Shift compliance is currently healthy.";
  })();

  return (
    <div
      className={`shift-banner ${
        tone === "warning" ? "shift-banner-warning" : "shift-banner-success"
      }`}
    >
      <div className="shift-banner-main">
        <div className="shift-banner-left">
          <div
            className={`shift-banner-icon ${
              tone === "warning"
                ? "shift-banner-icon-warning"
                : "shift-banner-icon-success"
            }`}
          >
            {tone === "warning" ? "!" : "✓"}
          </div>

          <div className="shift-banner-text">
            <div className="shift-banner-title">
              <span>Shift:</span>{" "}
              <strong>{formatShiftName(activeShift?.shift_name)}</strong>

              {activeShift?.opened_at && (
                <>
                  <span className="shift-banner-dot">•</span>
                  <span>Opened {formatDateTime(activeShift.opened_at)}</span>
                </>
              )}

              {shiftEndDate && (
                <>
                  <span className="shift-banner-dot">•</span>
                  <span>Ends {formatTimeOnly(shiftEndDate)}</span>
                </>
              )}

    {secondsToEnd !== null && (isInWarning || isEnded) && (
  <>
    <span className="shift-banner-dot">•</span>
    <strong className="shift-banner-danger-text">
      {isEnded ? "Shift ended" : "Countdown"} {formatCountdown(secondsToEnd)}
    </strong>
  </>
)}

          {shiftEndDate && (
  <>
    <span className="shift-banner-dot">•</span>
    <span>Ends {formatTimeOnly(shiftEndDate)}</span>
  </>
)}
            </div>

            <div className="shift-banner-subtitle">{subtitle}</div>
          </div>
        </div>

        <div className="shift-banner-actions">
          {policy.auto_shift_logout && isInWarning && (
            <span className="shift-banner-pill">
              Scheduled shift ending soon
            </span>
          )}

          {isEnded && (
            <span className="shift-banner-pill shift-banner-pill-danger">
              End shift required
            </span>
          )}

          <button
            type="button"
            onClick={onOpenCashup}
            className={`shift-banner-button ${
              tone === "warning"
                ? "shift-banner-button-warning"
                : "shift-banner-button-success"
            }`}
          >
            Cashup / End Shift
          </button>
        </div>
      </div>

      {hasUnclosed && (
        <div className="shift-banner-unclosed">
          {unclosed.slice(0, 4).map((shift) => (
            <div key={shift.id} className="shift-banner-chip">
              <span className="shift-banner-chip-name">
                {shift.cashier_name || shift.pos_user_id || "Cashier"}
              </span>

              <span>{formatShiftName(shift.shift_name)}</span>

              <span>Opened {formatDateTime(shift.opened_at)}</span>
            </div>
          ))}

          {unclosed.length > 4 && (
            <div className="shift-banner-chip">
              +{unclosed.length - 4} more
            </div>
          )}
        </div>
      )}

      <ShiftBannerStyles />
    </div>
  );
}

const ShiftBannerStyles = () => (
  <style>
    {`
      .shift-banner {
        border-bottom: 1px solid #d1d8d7;
        padding: 7px 12px;
        font-size: 11px;
        color: #091b1b;
      }

      .shift-banner-success {
        background: #ecfdf3;
        border-color: #abefc6;
      }

      .shift-banner-warning {
        background: #fffaeb;
        border-color: #fedf89;
      }

      .shift-banner-error {
        background: #fff7ed;
        border-color: #fdba74;
      }

      .shift-banner-main {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
      }

      .shift-banner-left {
        min-width: 0;
        display: flex;
        align-items: center;
        gap: 9px;
      }

      .shift-banner-icon {
        width: 22px;
        height: 22px;
        flex: 0 0 22px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1px solid currentColor;
        font-size: 12px;
        font-weight: 600;
      }

      .shift-banner-icon-success {
        background: #dcfae6;
        color: #067647;
      }

      .shift-banner-icon-warning {
        background: #fef0c7;
        color: #b54708;
      }

      .shift-banner-text {
        min-width: 0;
      }

      .shift-banner-title {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 5px;
        line-height: 1.3;
        color: #324444;
      }

      .shift-banner-title strong {
        font-weight: 600;
        color: #091b1b;
      }

      .shift-banner-subtitle {
        margin-top: 2px;
        color: #637877;
        line-height: 1.25;
      }

      .shift-banner-dot {
        color: #93a6a6;
      }

      .shift-banner-danger-text {
        color: #b54708 !important;
      }

      .shift-banner-actions {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-shrink: 0;
      }

      .shift-banner-pill {
        min-height: 24px;
        display: inline-flex;
        align-items: center;
        border: 1px solid #fedf89;
        background: #fff7ed;
        color: #b54708;
        padding: 0 8px;
        font-size: 9px;
        font-weight: 700;
        text-transform: uppercase;
        white-space: nowrap;
      }

      .shift-banner-pill-danger {
        border-color: #fda29b;
        background: #fef3f2;
        color: #b42318;
      }

      .shift-banner-button {
        height: 28px;
        border: 1px solid transparent;
        padding: 0 12px;
        font-size: 10px;
        font-weight: 500;
        text-transform: uppercase;
        white-space: nowrap;
      }

      .shift-banner-button-success {
        background: #067647;
        border-color: #067647;
        color: #ffffff;
      }

      .shift-banner-button-success:hover {
        background: #05603a;
      }

      .shift-banner-button-warning {
        background: #b54708;
        border-color: #b54708;
        color: #ffffff;
      }

      .shift-banner-button-warning:hover {
        background: #93370d;
      }

      .shift-banner-unclosed {
        margin-top: 6px;
        padding-left: 31px;
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
      }

      .shift-banner-chip {
        min-height: 24px;
        border: 1px solid #fedf89;
        background: #ffffff;
        padding: 4px 7px;
        display: inline-flex;
        align-items: center;
        gap: 7px;
        color: #7a2e0e;
        font-size: 10px;
      }

      .shift-banner-chip-name {
        font-weight: 600;
        color: #091b1b;
      }

      .shift-banner button,
      .shift-banner div,
      .shift-banner span {
        border-radius: 0 !important;
      }

      .shift-banner button:disabled {
        opacity: 0.6;
        cursor: not-allowed;
      }

      @media (max-width: 900px) {
        .shift-banner-main {
          align-items: flex-start;
          flex-direction: column;
        }

        .shift-banner-actions {
          flex-wrap: wrap;
          justify-content: flex-start;
        }

        .shift-banner-button {
          align-self: flex-start;
        }

        .shift-banner-unclosed {
          padding-left: 0;
        }
      }
    `}
  </style>
);
