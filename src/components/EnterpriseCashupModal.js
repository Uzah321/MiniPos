import React, { useEffect, useMemo, useState } from "react";
import { X, RefreshCw, Keyboard } from "lucide-react";
import {
  cashupShift,
  endShift,
  getActiveShiftSummary,
  recordCashDrawerMovement,
} from "../services/CashupService";
import { getCurrencies } from "../services/CurrencyService";
import { canViewCashupFinancialAmounts } from "../utils/CashupPermissions";
import ManagerApprovalModal from "./ManagerApprovalModal";

const money = (value, currency = "") => `${currency ? `${currency} ` : ""}${Number(value || 0).toFixed(2)}`;
const code = (value, fallback = "USD") => String(value || fallback).trim().toUpperCase();

const dateOnly = (value) => {
  if (!value) return "";

  const text = String(value).trim();

  const match = text.match(/^\d{4}-\d{2}-\d{2}/);
  if (match) return match[0];

  const parsed = new Date(text);
  if (Number.isNaN(parsed.getTime())) return "";

  return parsed.toISOString().slice(0, 10);
};

const DRAWER_LABELS = [
  "cash drawer",
  "drawer",
  "drawer float",
  "opening float",
  "float",
  "manual drawer count",
];

const DRAWER_CODES = [
  "cash_drawer",
  "drawer",
  "drawer_float",
  "opening_float",
  "float",
  "manual_drawer_count",
];

const isDrawerRow = (row) => {
  const paymentName = String(row?.payment_name || "").trim().toLowerCase();
  const moneyCode = String(row?.money_code || "").trim().toLowerCase();

  return DRAWER_LABELS.includes(paymentName) || DRAWER_CODES.includes(moneyCode);
};

const CASH_TOKEN_PATTERN = /(^|[\s_-])cash($|[\s_-])/i;

export const isCashPayment = (row) => {
  if (isDrawerRow(row)) return false;

  const paymentName = String(row?.payment_name || "").trim();
  const moneyCode = String(row?.money_code || "").trim();

  return CASH_TOKEN_PATTERN.test(paymentName) || CASH_TOKEN_PATTERN.test(moneyCode);
};

const getErrorMessage = (error, fallback) => {
  const data = error?.response?.data;
  if (data?.errors) return Object.values(data.errors).flat().join("\n");
  return data?.message || data?.error || error?.message || fallback;
};

// A blind cashup must not echo backend text because validation and success
// messages can contain the expected amount or calculated variance. The server
// is still responsible for authorisation and redaction; this is a UI privacy
// guard for older endpoints and validation responses.
export const cashupFeedbackMessage = ({ message, fallback, canViewFinancials }) => (
  canViewFinancials ? (message || fallback) : fallback
);

const normalizeCurrenciesResponse = (response) => {
  const payload = response?.data || response || {};
  const rows = payload?.data?.data || payload?.data || payload?.currencies || payload || [];

  if (!Array.isArray(rows)) return [];

  return rows
    .filter((row) => row?.code)
    .map((row) => ({
      id: row.id,
      code: code(row.code),
      name: row.name || row.code,
      is_base_currency: row.is_base_currency,
    }));
};

const uniqueCodes = (rows) => Array.from(new Set(rows.map((row) => code(row)).filter(Boolean)));

const getBohEnabled = (summary) => {
  return Boolean(summary?.settings?.boh_enabled ?? summary?.cashup_mode?.boh_enabled ?? false);
};

const getFastFoodEnabled = (summary) => {
  return Boolean(summary?.settings?.fast_food_mode_enabled ?? summary?.cashup_mode?.fast_food_mode_enabled ?? false);
};

const getHospitalityTradingDate = (summary) => {
  return dateOnly(
    summary?.trading_date ||
      summary?.current_trading_date ||
      summary?.current_business_date ||
      summary?.cashup_mode?.trading_date ||
      summary?.settings?.trading_date ||
      summary?.settings?.current_trading_date ||
      ""
  );
};

const getCashupBracketDate = (summary) => {
  return getHospitalityTradingDate(summary);
};

const getCashupBracketLabel = () => "Trading Date";

const getCashupBracketHelp = () => "Day End controls the trading date.";

const getPaymentCurrencies = (summary) => {
  const payments = Array.isArray(summary?.payments) ? summary.payments : [];
  return uniqueCodes(payments.map((row) => row.currency_code));
};

const getCashCurrencies = (summary, currencies = []) => {
  const payments = Array.isArray(summary?.payments) ? summary.payments : [];
  const cashCodes = uniqueCodes(payments.filter(isCashPayment).map((row) => row.currency_code));

  if (cashCodes.length > 0) return cashCodes;

  const baseCurrency = currencies.find((currency) => currency.is_base_currency)?.code;
  return [code(baseCurrency || currencies[0]?.code || "USD")];
};

const defaultCurrency = (currencies, summary) => {
  const cashCodes = getCashCurrencies(summary, currencies);
  return code(cashCodes[0] || currencies[0]?.code || "USD");
};

const buildPaymentRows = (summary) => {
  const payments = Array.isArray(summary?.payments) ? summary.payments : [];
  const allowed = canViewCashupFinancialAmounts(summary);
  const map = new Map();

  payments
    .filter((row) => !isDrawerRow(row))
    .forEach((row) => {
      const currency = code(row.currency_code);
      const isCash = isCashPayment(row);
      const key = `${isCash ? "CASH" : code(row.money_code || row.payment_name || "OTHER", "OTHER")}|${currency}`;
      const existing = map.get(key) || {
        payment_name: isCash ? "CASH" : row.payment_name || row.money_code || "OTHER",
        money_code: isCash ? "cash" : row.money_code || null,
        currency_code: currency,
        expected_amount: 0,
        counted_amount: 0,
        payment_count: 0,
      };

      existing.expected_amount += allowed ? Number(row.amount || 0) : 0;
      existing.counted_amount += allowed && !isCash ? Number(row.amount || 0) : 0;
      existing.payment_count += allowed ? Number(row.payment_count || 0) : 0;
      map.set(key, existing);
    });

  return Array.from(map.values()).map((row) => ({
    ...row,
    expected_amount: Number(row.expected_amount.toFixed(4)),
    counted_amount: Number(row.counted_amount.toFixed(4)),
  }));
};

const fallbackDenominations = (currencyCode) => [100, 50, 20, 10, 5, 2, 1, 0.5, 0.25, 0.1, 0.05, 0.01].map((value, index) => ({
  currency_code: currencyCode,
  value,
  denomination_value: value,
  quantity: 0,
  line_total: 0,
  type: value >= 1 ? "note" : "coin",
  sort_order: index + 1,
}));

const buildDenominationRowsForCurrency = (summary, currencyCode) => {
  const target = code(currencyCode);
  const template = Array.isArray(summary?.denomination_template) ? summary.denomination_template : [];
  const rows = template
    .filter((row) => code(row.currency_code) === target)
    .filter((row) => Number(row.value ?? row.denomination_value ?? 0) > 0);
  const source = rows.length ? rows : fallbackDenominations(target);
  const canViewFinancials = canViewCashupFinancialAmounts(summary);

  return source
    .map((row, index) => {
      const value = Number(row.value ?? row.denomination_value ?? 0);
      // Never pre-fill a blind cash count from a server-provided quantity.
      // The cashier must enter the physical count independently.
      const quantity = canViewFinancials ? Number(row.quantity || 0) : 0;
      return {
        currency_code: code(row.currency_code, target),
        value,
        denomination_value: value,
        quantity,
        line_total: Number((value * quantity).toFixed(4)),
        type: row.type || row.denomination_type || (value >= 1 ? "note" : "coin"),
        sort_order: Number(row.sort_order || index + 1),
      };
    })
    .filter((row) => row.value > 0)
    .sort((a, b) => b.value - a.value);
};

const buildDenominationRows = (summary, currencyCodes) => {
  return currencyCodes.flatMap((currencyCode) => buildDenominationRowsForCurrency(summary, currencyCode));
};

const denominationTotal = (rows) => rows.reduce((sum, row) => sum + Number(row.value || 0) * Number(row.quantity || 0), 0);

const denominationTotalsByCurrency = (rows) => {
  return rows.reduce((acc, row) => {
    const currency = code(row.currency_code);
    acc[currency] = Number(((acc[currency] || 0) + Number(row.value || 0) * Number(row.quantity || 0)).toFixed(4));
    return acc;
  }, {});
};

const applyDenominationsToCash = (payments, totalsByCurrency) => {
  const currencies = Object.keys(totalsByCurrency || {});
  const foundCurrencies = new Set();

  const nextRows = payments.map((row) => {
    if (isCashPayment(row)) {
      const currency = code(row.currency_code);
      foundCurrencies.add(currency);
      return {
        ...row,
        payment_name: "CASH",
        money_code: "cash",
        counted_amount: Number((totalsByCurrency[currency] || 0).toFixed(4)),
      };
    }

    return row;
  });

  currencies.forEach((currency) => {
    if (foundCurrencies.has(currency)) return;

    nextRows.push({
      payment_name: "CASH",
      money_code: "cash",
      currency_code: currency,
      expected_amount: 0,
      counted_amount: Number((totalsByCurrency[currency] || 0).toFixed(4)),
      payment_count: 0,
    });
  });

  return nextRows;
};

const totalsByCurrencyFromPayments = (payments) => {
  return payments.reduce((acc, row) => {
    const currency = code(row.currency_code);
    const existing = acc[currency] || {
      currency_code: currency,
      expected: 0,
      counted: 0,
      variance: 0,
    };

    existing.expected += Number(row.expected_amount || 0);
    existing.counted += Number(row.counted_amount || 0);
    existing.variance = existing.counted - existing.expected;
    acc[currency] = existing;
    return acc;
  }, {});
};

const cashTotalsByCurrency = (payments, denominationRows) => {
  const denomTotals = denominationTotalsByCurrency(denominationRows);
  const rows = {};

  payments.filter(isCashPayment).forEach((row) => {
    const currency = code(row.currency_code);
    rows[currency] = {
      currency_code: currency,
      expected: Number(row.expected_amount || 0),
      counted: Number(denomTotals[currency] || row.counted_amount || 0),
      variance: Number(denomTotals[currency] || row.counted_amount || 0) - Number(row.expected_amount || 0),
    };
  });

  Object.keys(denomTotals).forEach((currency) => {
    if (!rows[currency]) {
      rows[currency] = {
        currency_code: currency,
        expected: 0,
        counted: denomTotals[currency],
        variance: denomTotals[currency],
      };
    }
  });

  return rows;
};

const extractCashupPrintPayload = (response) => {
  const payload = response?.data || response || {};
  const data = payload?.data || payload || {};

  return data?.cashup_print
    || payload?.cashup_print
    || data?.print?.cashup_print
    || payload?.print?.cashup_print
    || null;
};

const printCashupReport = (printPayload) => {
  const html = printPayload?.html;

  if (!html) return false;

  const printWindow = window.open("", "cashup_print", "width=420,height=720,scrollbars=yes");

  if (!printWindow) return false;

  printWindow.document.open();
  printWindow.document.write(html);
  printWindow.document.close();
  printWindow.focus();

  window.setTimeout(() => {
    try {
      printWindow.print();
    } catch (error) {
      // Browser print errors should not fail the completed cashup.
    }
  }, 350);

  return true;
};

export default function EnterpriseCashupModal({ open, onClose, closeShiftDefault = false }) {
  const [summary, setSummary] = useState(null);
  const [currencies, setCurrencies] = useState([]);
  const [denominationCurrency, setDenominationCurrency] = useState("USD");
  const [activeStep, setActiveStep] = useState("header");
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [closeShift, setCloseShift] = useState(closeShiftDefault);
  const [header, setHeader] = useState({
    trading_date: "",
    cashup_currency_code: "USD",
    cashup_period_label: "",
  });
  const [denominations, setDenominations] = useState([]);
  const [payments, setPayments] = useState([]);
  const [notes, setNotes] = useState("");
  const [selectedShiftId, setSelectedShiftId] = useState("");
  const [onBehalfReason, setOnBehalfReason] = useState("");
  const [movement, setMovement] = useState({
    movement_type: "cash_out",
    amount: "",
    currency_code: "USD",
    reason: "",
    notes: "",
    reference_no: "",
  });
  const [managerApprovalOpen, setManagerApprovalOpen] = useState(false);
  const [pendingApproval, setPendingApproval] = useState(null);
  const [keyboardTarget, setKeyboardTarget] = useState(null);

  const closeKeyboard = () => setKeyboardTarget(null);

  const canViewExpected = canViewCashupFinancialAmounts(summary);
  const availableShifts = summary?.available_shifts || [];
  const selectedShift = summary?.selected_shift || availableShifts.find((row) => String(row.id) === String(selectedShiftId));
  const isOnBehalf = Boolean(selectedShift && selectedShift.is_own_shift === false);

  const paymentCurrencyCodes = useMemo(() => getPaymentCurrencies(summary), [summary]);
  const cashCurrencyCodes = useMemo(() => {
    const codes = getCashCurrencies(summary, currencies);
    const denomCodes = uniqueCodes(denominations.map((row) => row.currency_code));
    return uniqueCodes([...codes, ...denomCodes]);
  }, [summary, currencies, denominations]);

  const allCurrencyCodes = useMemo(() => {
    return uniqueCodes([
      ...paymentCurrencyCodes,
      ...cashCurrencyCodes,
      ...currencies.map((currency) => currency.code),
    ]);
  }, [paymentCurrencyCodes, cashCurrencyCodes, currencies]);

  const selectedDenominations = useMemo(() => {
    return denominations.filter((row) => code(row.currency_code) === code(denominationCurrency));
  }, [denominations, denominationCurrency]);

  const totalDenominations = useMemo(() => denominationTotal(denominations), [denominations]);
  const denominationByCurrency = useMemo(() => denominationTotalsByCurrency(denominations), [denominations]);
  const totalByCurrency = useMemo(() => totalsByCurrencyFromPayments(payments), [payments]);
  const cashByCurrency = useMemo(() => cashTotalsByCurrency(payments, denominations), [payments, denominations]);

  const payloadCurrencyCode = useMemo(() => {
    const codes = uniqueCodes(payments.map((row) => row.currency_code));
    return codes.length > 1 ? "MULTI" : code(codes[0] || header.cashup_currency_code || "USD");
  }, [payments, header.cashup_currency_code]);

  const openDenominationKeyboard = (currencyCode, value) => {
    const rowIndex = denominations.findIndex((row) =>
      code(row.currency_code) === code(currencyCode) && Number(row.value) === Number(value)
    );

    if (rowIndex < 0) return;

    const row = denominations[rowIndex];
    setKeyboardTarget({
      type: "denomination",
      index: rowIndex,
      title: `${row.currency_code} ${Number(row.value || 0).toFixed(2)} Quantity`,
      value: String(row.quantity || ""),
      mode: "integer",
    });
  };

  const openPaymentKeyboard = (index) => {
    const row = payments[index];
    if (isCashPayment(row)) return;
    setKeyboardTarget({
      type: "payment",
      index,
      title: `${row.payment_name} ${row.currency_code} Counted Amount`,
      value: String(row.counted_amount || ""),
      mode: "money",
    });
  };

  const openMovementAmountKeyboard = () => {
    setKeyboardTarget({
      type: "movement_amount",
      index: null,
      title: `Cash Movement Amount ${movement.currency_code}`,
      value: String(movement.amount || ""),
      mode: "money",
    });
  };

  const applyKeyboardValue = (value) => {
    if (!keyboardTarget) return;

    if (keyboardTarget.type === "denomination") updateDenominationByIndex(keyboardTarget.index, value);
    if (keyboardTarget.type === "payment") updatePayment(keyboardTarget.index, Number(value || 0));
    if (keyboardTarget.type === "movement_amount") setMovement((prev) => ({ ...prev, amount: value }));

    closeKeyboard();
  };

  const load = async (shiftSessionId = selectedShiftId) => {
    try {
      setLoading(true);
      setError("");
      setNotice("");

      const [summaryResponse, currencyResponse] = await Promise.all([
        getActiveShiftSummary(shiftSessionId ? { shift_session_id: shiftSessionId } : {}),
        // Cashup eligibility comes from the shift summary. Currency management
        // is an optional catalogue permission, so a cashier without
        // `view_currency` must still be able to count the currencies already
        // present in the shift/denomination template.
        getCurrencies().catch(() => ({ data: [] })),
      ]);

      const nextSummary = summaryResponse?.data || summaryResponse;
      const nextCurrencies = normalizeCurrenciesResponse(currencyResponse);
      const nextCashCurrencyCodes = getCashCurrencies(nextSummary, nextCurrencies);
      const nextCurrency = defaultCurrency(nextCurrencies, nextSummary);
      const nextDenominations = buildDenominationRows(nextSummary, nextCashCurrencyCodes);
      const nextPayments = applyDenominationsToCash(
        buildPaymentRows(nextSummary),
        denominationTotalsByCurrency(nextDenominations)
      );
      const nextPayloadCurrency = uniqueCodes(nextPayments.map((row) => row.currency_code)).length > 1
        ? "MULTI"
        : nextCurrency;
      const nextBracketDate = getCashupBracketDate(nextSummary);

      setSummary(nextSummary);
      setSelectedShiftId(String(nextSummary?.selected_shift?.id || nextSummary?.shift?.shift_session_id || shiftSessionId || ""));
      setCurrencies(nextCurrencies);
      setDenominationCurrency(nextCurrency);
      setHeader((prev) => ({
        ...prev,
        cashup_currency_code: nextPayloadCurrency,
        trading_date: nextBracketDate,
      }));
      setMovement((prev) => ({ ...prev, currency_code: nextCurrency }));
      setDenominations(nextDenominations);
      setPayments(nextPayments);
    } catch (err) {
      setError(cashupFeedbackMessage({
        message: getErrorMessage(err, "Failed to load active shift summary."),
        fallback: "Failed to load active shift summary.",
        canViewFinancials: canViewCashupFinancialAmounts(summary),
      }));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (!open) return;
    setActiveStep("header");
    closeKeyboard();
    setCloseShift(closeShiftDefault);
    setSelectedShiftId("");
    setOnBehalfReason("");
    load("");
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, closeShiftDefault]);

  useEffect(() => {
    setPayments((rows) => applyDenominationsToCash(rows, denominationByCurrency));
  }, [denominationByCurrency]);

  useEffect(() => {
    setHeader((prev) => ({ ...prev, cashup_currency_code: payloadCurrencyCode }));
  }, [payloadCurrencyCode]);

  const selectDenominationCurrency = (currencyCode) => {
    const nextCurrency = code(currencyCode);
    setDenominationCurrency(nextCurrency);
    setMovement((prev) => ({ ...prev, currency_code: nextCurrency }));

    setDenominations((rows) => {
      const exists = rows.some((row) => code(row.currency_code) === nextCurrency);
      if (exists) return rows;
      return [...rows, ...buildDenominationRowsForCurrency(summary, nextCurrency)];
    });
  };

  const selectShift = async (shiftSessionId) => {
    setSelectedShiftId(shiftSessionId);
    setOnBehalfReason("");
    setActiveStep("header");
    await load(shiftSessionId);
  };

  const updateDenominationByIndex = (index, quantity) => {
    const nextQuantity = Math.max(0, Number(quantity || 0));
    setDenominations((rows) => rows.map((row, rowIndex) => rowIndex === index ? {
      ...row,
      quantity: nextQuantity,
      line_total: Number((Number(row.value || 0) * nextQuantity).toFixed(4)),
    } : row));
  };

  const updateDenomination = (currencyCode, value, quantity) => {
    const nextQuantity = Math.max(0, Number(quantity || 0));
    setDenominations((rows) => rows.map((row) => {
      if (code(row.currency_code) === code(currencyCode) && Number(row.value) === Number(value)) {
        return {
          ...row,
          quantity: nextQuantity,
          line_total: Number((Number(row.value || 0) * nextQuantity).toFixed(4)),
        };
      }
      return row;
    }));
  };

  const updatePayment = (index, amount) => {
    setPayments((rows) => rows.map((row, rowIndex) => rowIndex === index ? {
      ...row,
      counted_amount: Number(amount || 0),
    } : row));
  };

  const saveMovement = async () => {
    if (!movement.reason) {
      setError("Cash movement reason is required.");
      return;
    }

    try {
      setLoading(true);
      setError("");
      const response = await recordCashDrawerMovement({
        ...movement,
        shift_session_id: selectedShiftId || undefined,
        amount: Number(movement.amount || 0),
        currency_code: code(movement.currency_code),
      });

      // The movement endpoint already returns an updated shift summary. Merge only
      // that server state instead of calling load(), which rebuilds denominations
      // and payment rows and would erase the cashier's in-progress count.
      const updatedSummary = response?.summary || response?.data?.summary;
      if (updatedSummary) {
        setSummary((current) => current ? {
          ...current,
          ...updatedSummary,
          permissions: { ...(current.permissions || {}), ...(updatedSummary.permissions || {}) },
          settings: { ...(current.settings || {}), ...(updatedSummary.settings || {}) },
          policy: { ...(current.policy || {}), ...(updatedSummary.policy || {}) },
          available_shifts: current.available_shifts || [],
          selected_shift: current.selected_shift,
          actor: current.actor,
          denomination_template: current.denomination_template || [],
        } : updatedSummary);
      }

      setNotice(cashupFeedbackMessage({
        message: response?.message,
        fallback: "Cash movement saved.",
        canViewFinancials: canViewExpected,
      }));
      setMovement((current) => ({
        movement_type: current.movement_type,
        amount: "",
        currency_code: current.currency_code || denominationCurrency || "USD",
        reason: "",
        notes: "",
        reference_no: "",
      }));
    } catch (err) {
      setError(cashupFeedbackMessage({
        message: getErrorMessage(err, "Failed to save cash movement."),
        fallback: "Failed to save cash movement.",
        canViewFinancials: canViewExpected,
      }));
    } finally {
      setLoading(false);
    }
  };

  const buildPayload = (approval = null) => {
    const nonDrawerPayments = payments.filter((row) => !isDrawerRow(row));

    const payload = {
      cashup_currency_code: payloadCurrencyCode,
      cashup_period_label: header.cashup_period_label,
      denominations: denominations.map((row) => ({
        currency_code: code(row.currency_code),
        value: Number(Number(row.value || 0).toFixed(4)),
        quantity: Number(row.quantity || 0),
        type: row.type || "note",
      })),
      counted_cash: Number(Number(totalDenominations || 0).toFixed(4)),
      counted_payments: nonDrawerPayments
        .filter((row) => !isCashPayment(row))
        .map((row) => ({
          payment_name: row.payment_name,
          money_code: row.money_code || null,
          currency_code: code(row.currency_code),
          amount: Number(Number(row.counted_amount || 0).toFixed(4)),
        })),
      notes,
      close_shift: closeShift,
      shift_session_id: selectedShiftId || undefined,
      force_close_reason: isOnBehalf ? onBehalfReason.trim() : undefined,
      manager_approval: approval,
      trading_date: header.trading_date,
    };

    return payload;
  };

  const submit = async (approval = null) => {
    if (!header.trading_date) {
      setError("Trading date is required in Hospitality mode.");
      setActiveStep("header");
      return;
    }

    if (isOnBehalf && !onBehalfReason.trim()) {
      setError("Enter a supervisor reason for cashing up on behalf of this cashier.");
      setActiveStep("review");
      return;
    }

    try {
      setLoading(true);
      setError("");
      const payload = buildPayload(approval);
      const response = closeShift ? await endShift(payload) : await cashupShift(payload);
      // Cashup print HTML can contain expected and variance figures. Only a
      // user with the financial visibility permission may open that document.
      const printPayload = canViewExpected ? extractCashupPrintPayload(response) : null;
      const printed = canViewExpected && printCashupReport(printPayload);
      const successFallback = closeShift ? "Cashup saved and shift closed." : "Cashup saved.";
      const successMessage = cashupFeedbackMessage({
        message: response?.message,
        fallback: successFallback,
        canViewFinancials: canViewExpected,
      });
      setNotice(printed
        ? `${successMessage} Cashup report sent to printer.`
        : successMessage);
      if (closeShift) {
        window.setTimeout(() => onClose?.(), printed ? 700 : 0);
      } else {
        await load(selectedShiftId);
      }
    } catch (err) {
      setError(cashupFeedbackMessage({
        message: getErrorMessage(err, "Cashup failed."),
        fallback: "Cashup failed. Review the counted amounts and try again.",
        canViewFinancials: canViewExpected,
      }));
    } finally {
      setLoading(false);
    }
  };

  if (!open) return null;

  return (
    <div className="fixed inset-0 z-[210] bg-black/55 p-3">
      <div className="flex h-full min-h-0 flex-col border border-[#AAB5B5] bg-white text-[#1C2C2B] shadow-2xl">
        <div className="flex h-11 shrink-0 items-center justify-between border-b border-[#00BCBC] bg-[#004142] px-3 text-white">
          <div>
            <div className="text-[13px] font-semibold uppercase">Enterprise Cashup</div>
            <div className="text-[10px] font-medium uppercase text-white/65">Trading date, multi-currency count and shift closure</div>
          </div>
          <button type="button" onClick={onClose} className="flex h-8 w-8 items-center justify-center border border-white/20 bg-white/10 hover:bg-white/20">
            <X className="h-4 w-4" />
          </button>
        </div>

        {(error || notice) && (
          <div className={`shrink-0 whitespace-pre-line border-b px-3 py-2 text-[11px] font-semibold ${error ? "border-[#FDA29B] bg-[#FEF3F2] text-[#B42318]" : "border-[#ABEFC6] bg-[#ECFDF3] text-[#067647]"}`}>
            {error || notice}
          </div>
        )}
        {loading && <div className="shrink-0 border-b border-[#CFD9D8] bg-[#F5F7FA] px-3 py-2 text-[11px] font-semibold uppercase text-[#006869]">Processing...</div>}

        <div className="flex min-h-0 flex-1 flex-col bg-[#F5F7FA]">
          <div className="shrink-0 border-b border-[#CFD9D8] bg-white p-3">
            <div className="grid gap-3 xl:grid-cols-[minmax(280px,1.2fr)_repeat(4,minmax(130px,0.55fr))]">
              <label className="border border-[#CFD9D8] bg-[#F8FAFC] p-2">
                <span className="block text-[9px] font-semibold uppercase text-[#627474]">Cashier Shift</span>
                <select value={selectedShiftId} onChange={(event) => selectShift(event.target.value)} disabled={!summary?.permissions?.can_cashup_on_behalf || availableShifts.length < 2} className="mt-1 h-9 w-full border border-[#AAB5B5] bg-white px-2 text-[12px] font-semibold">
                  {availableShifts.map((shift) => <option key={shift.id} value={shift.id}>{shift.cashier_name} · {shift.shift_name || "Shift"} · {String(shift.opened_at || "").replace("T", " ").slice(0, 16)}</option>)}
                </select>
                <span className={`mt-1 block text-[10px] font-semibold ${isOnBehalf ? "text-[#B54708]" : "text-[#067647]"}`}>{isOnBehalf ? `Supervisor cashup by ${summary?.actor?.name || "current user"}` : "Own cashier shift"}</span>
              </label>
              <CashupKpi label="Mode" value="Hospitality" />
              <CashupKpi label={getCashupBracketLabel()} value={header.trading_date || "-"} />
              <CashupKpi label="Currencies" value={payloadCurrencyCode} />
              <CashupKpi label="Transactions" value={summary?.totals?.transaction_count ?? 0} />
            </div>
            <div className="mt-3 grid grid-cols-5 gap-2">
              <TopStep active={activeStep === "header"} number="1" title="Shift" onClick={() => setActiveStep("header")} />
              <TopStep active={activeStep === "denominations"} number="2" title="Count Cash" onClick={() => setActiveStep("denominations")} />
              <TopStep active={activeStep === "payments"} number="3" title="Other Payments" onClick={() => setActiveStep("payments")} />
              <TopStep active={activeStep === "movements"} number="4" title="Movements" onClick={() => setActiveStep("movements")} />
              <TopStep active={activeStep === "review"} number="5" title="Review" onClick={() => setActiveStep("review")} />
            </div>
          </div>

          <main className="min-h-0 flex-1 overflow-auto bg-[#FBFCFE] p-4">
            {activeStep === "header" && (
              <HeaderStep
                summary={summary}
                currencies={currencies}
                header={header}
                setHeader={setHeader}
                payloadCurrencyCode={payloadCurrencyCode}
                cashCurrencyCodes={cashCurrencyCodes}
                onContinue={() => setActiveStep("denominations")}
                loading={loading}
                onRefresh={load}
              />
            )}
            {activeStep === "denominations" && (
              <DenominationsStep
                denominationCurrency={denominationCurrency}
                currencyCodes={allCurrencyCodes}
                cashCurrencyCodes={cashCurrencyCodes}
                rows={selectedDenominations}
                totalsByCurrency={denominationByCurrency}
                selectDenominationCurrency={selectDenominationCurrency}
                updateDenomination={updateDenomination}
                openKeyboard={openDenominationKeyboard}
              />
            )}
            {activeStep === "payments" && (
              <PaymentsStep
                rows={payments}
                canViewExpected={canViewExpected}
                totalsByCurrency={totalByCurrency}
                updatePayment={updatePayment}
                openKeyboard={openPaymentKeyboard}
              />
            )}
            {activeStep === "movements" && (
              <MovementsStep
                movement={movement}
                setMovement={setMovement}
                currencies={currencies}
                currencyCodes={allCurrencyCodes}
                saveMovement={saveMovement}
                loading={loading}
                rowsOut={summary?.cash_movements?.cash_out_rows || []}
                rowsIn={summary?.cash_movements?.cash_in_rows || []}
                canViewAmounts={canViewExpected}
                openAmountKeyboard={openMovementAmountKeyboard}
              />
            )}
            {activeStep === "review" && (
              <ReviewStep
                header={header}
                closeShift={closeShift}
                setCloseShift={setCloseShift}
                notes={notes}
                setNotes={setNotes}
                cashByCurrency={cashByCurrency}
                totalsByCurrency={totalByCurrency}
                denominationByCurrency={denominationByCurrency}
                payloadCurrencyCode={payloadCurrencyCode}
                canViewExpected={canViewExpected}
                loading={loading}
                submit={submit}
                isOnBehalf={isOnBehalf}
                selectedShift={selectedShift}
                actorName={summary?.actor?.name}
                onBehalfReason={onBehalfReason}
                setOnBehalfReason={setOnBehalfReason}
              />
            )}
          </main>
        </div>
      </div>

      <FloatingNumericKeyboard
        open={Boolean(keyboardTarget)}
        title={keyboardTarget?.title}
        value={keyboardTarget?.value || ""}
        mode={keyboardTarget?.mode || "money"}
        onClose={closeKeyboard}
        onApply={applyKeyboardValue}
      />

      <ManagerApprovalModal
        open={managerApprovalOpen}
        onClose={() => { setManagerApprovalOpen(false); setPendingApproval(null); }}
        onApproved={(approval) => submit(approval)}
        title={pendingApproval?.title}
        description={pendingApproval?.description}
        action={pendingApproval?.action}
        permission={pendingApproval?.permission}
        reason={pendingApproval?.reason}
        referenceType={pendingApproval?.referenceType}
        referenceId={pendingApproval?.referenceId}
        metadata={pendingApproval?.metadata || {}}
      />
    </div>
  );
}

function HeaderStep({
  summary,
  currencies,
  header,
  setHeader,
  payloadCurrencyCode,
  cashCurrencyCodes,
  onContinue,
  loading,
  onRefresh,
}) {
  return (
    <div className="space-y-3">
      <PanelHeader
        title="Cashup Header"
        subtitle="Uses the Day End trading date."
        right={(
          <button type="button" onClick={onRefresh} disabled={loading} className="inline-flex h-8 items-center gap-1 border border-[#AAB5B5] bg-white px-3 text-[10px] font-semibold uppercase">
            <RefreshCw className={`h-3.5 w-3.5 ${loading ? "animate-spin" : ""}`} /> Refresh
          </button>
        )}
      />
      <div className="grid gap-3 lg:grid-cols-2">
        <Panel title="Business Mode">
          <div className="grid gap-2 sm:grid-cols-2">
            <ReadOnlyBox label="Mode" value="Hospitality" />
            <ReadOnlyBox label="Back of House" value={getBohEnabled(summary) ? "Yes" : "No"} />
            <ReadOnlyBox label="Hospitality Service" value={getFastFoodEnabled(summary) ? "Yes" : "No"} />
            <ReadOnlyBox label="Report Date" value="Trading Date" />
          </div>
        </Panel>
        <Panel title="Cashup Bracket">
          <div className="grid gap-2 sm:grid-cols-2">
            <Field label="Trading Date">
              <input
                type="date"
                value={header.trading_date}
                readOnly
                disabled
                className="h-9 w-full border border-[#AAB5B5] bg-white px-2 text-[12px] outline-none focus:border-[#069495] disabled:bg-[#EEF2F2]"
              />
            </Field>
            <ReadOnlyBox label="Cashup Currency" value={payloadCurrencyCode} />
          </div>
          <div className="mb-2 grid gap-2 sm:grid-cols-2">
            <ReadOnlyBox label="Cash Count Currencies" value={cashCurrencyCodes.join(", ") || "-"} />
            <ReadOnlyBox label="Bracket Rule" value={getCashupBracketHelp()} />
          </div>
          <Field label="Period Label">
            <input
              value={header.cashup_period_label}
              onChange={(event) => setHeader((prev) => ({ ...prev, cashup_period_label: event.target.value }))}
              placeholder="Optional"
              className="h-9 w-full border border-[#AAB5B5] bg-white px-2 text-[12px] outline-none focus:border-[#069495]"
            />
          </Field>
          <button type="button" onClick={onContinue} className="mt-3 h-9 w-full border border-[#069495] bg-[#069495] text-[11px] font-semibold uppercase text-white">
            Continue to Count
          </button>
        </Panel>
      </div>
    </div>
  );
}

function DenominationsStep({
  denominationCurrency,
  currencyCodes,
  cashCurrencyCodes,
  rows,
  totalsByCurrency,
  selectDenominationCurrency,
  updateDenomination,
  openKeyboard,
}) {
  return (
    <div className="space-y-3">
      <PanelHeader
        title="Denomination Count"
        subtitle="Count every cash currency. USD and ZWG stay separate."
        right={(
          <select value={denominationCurrency} onChange={(event) => selectDenominationCurrency(event.target.value)} className="h-8 border border-[#AAB5B5] bg-white px-2 text-[11px] font-semibold">
            {currencyCodes.map((currencyCode) => <option key={currencyCode} value={currencyCode}>{currencyCode}</option>)}
          </select>
        )}
      />

      <div className="flex flex-wrap gap-2">
        {currencyCodes.map((currencyCode) => {
          const active = code(denominationCurrency) === code(currencyCode);
          const cashCurrency = cashCurrencyCodes.includes(code(currencyCode));
          return (
            <button
              key={currencyCode}
              type="button"
              onClick={() => selectDenominationCurrency(currencyCode)}
              className={`border px-3 py-2 text-[11px] font-semibold ${active ? "border-[#069495] bg-[#E8F7F5] text-[#005A5A]" : "border-[#CFD9D8] bg-white text-[#324443]"}`}
            >
              {currencyCode} {cashCurrency ? "Cash" : ""} · {money(totalsByCurrency[currencyCode] || 0, currencyCode)}
            </button>
          );
        })}
      </div>

      <div className="grid gap-3 xl:grid-cols-[1fr_300px]">
        <DataPanel title={`Notes and Coins ${denominationCurrency}`}>
          <table className="w-full border-collapse text-left text-[11px]">
            <thead className="bg-[#0E4D4D] text-white">
              <tr><Th>Denomination</Th><Th right>Qty</Th><Th right>Total</Th></tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                <tr key={`${row.currency_code}-${row.value}`} className="border-b border-[#CFD9D8]">
                  <Td strong>{money(row.value, row.currency_code)}<div className="text-[9px] uppercase text-[#627474]">{row.type}</div></Td>
                  <Td right>
                    <div className="inline-flex items-center justify-end gap-1">
                      <input
                        type="number"
                        aria-label={`${row.currency_code} ${Number(row.value || 0).toFixed(2)} denomination quantity`}
                        min="0"
                        step="1"
                        value={row.quantity || ""}
                        onChange={(event) => updateDenomination(row.currency_code, row.value, event.target.value)}
                        className="h-8 w-24 border border-[#AAB5B5] px-2 text-right font-semibold"
                      />
                      <button type="button" onClick={() => openKeyboard(row.currency_code, row.value)} className="inline-flex h-8 w-8 items-center justify-center border border-[#069495] bg-[#E8F7F5] text-[#005A5A]" title="Open keyboard">
                        <Keyboard className="h-4 w-4" />
                      </button>
                    </div>
                  </Td>
                  <Td right strong>{money(row.line_total, row.currency_code)}</Td>
                </tr>
              ))}
            </tbody>
          </table>
        </DataPanel>
        <Panel title="Cash Totals">
          <div className="space-y-2">
            {Object.keys(totalsByCurrency).length === 0 ? (
              <div className="bg-[#004142] p-4 text-white">
                <div className="text-[10px] uppercase text-white/65">Physical Cash Count</div>
                <div className="mt-1 text-[26px] font-semibold">0.00</div>
              </div>
            ) : Object.entries(totalsByCurrency).map(([currencyCode, amount]) => (
              <div key={currencyCode} className="bg-[#004142] p-3 text-white">
                <div className="text-[10px] uppercase text-white/65">{currencyCode} Cash Count</div>
                <div className="mt-1 text-[22px] font-semibold">{money(amount, currencyCode)}</div>
              </div>
            ))}
            {Object.keys(totalsByCurrency).length > 1 && <div className="border border-[#B2DFDB] bg-[#F0FDFA] p-2 text-[10px] font-semibold text-[#006869]">Cash totals stay separated by currency.</div>}
          </div>
        </Panel>
      </div>
    </div>
  );
}

function PaymentsStep({ rows, canViewExpected, totalsByCurrency, updatePayment, openKeyboard }) {
  return (
    <div className="space-y-3">
      <PanelHeader title="Counted Payments" subtitle="Cash comes from denominations. Other tenders are counted here." />
      <div className="grid gap-3 xl:grid-cols-[1fr_300px]">
        <DataPanel title="Tender Reconciliation">
          <table className={`w-full border-collapse text-left text-[11px] ${canViewExpected ? "min-w-[880px]" : "min-w-[520px]"}`}>
            <thead className="bg-[#0E4D4D] text-white">
              <tr><Th>Payment</Th><Th>Currency</Th>{canViewExpected && <Th right>Expected</Th>}<Th right>Counted</Th>{canViewExpected && <Th right>Variance</Th>}</tr>
            </thead>
            <tbody>
              {rows.map((row, index) => {
                const variance = Number(row.counted_amount || 0) - Number(row.expected_amount || 0);
                const cash = isCashPayment(row);
                return (
                  <tr key={`${row.payment_name}-${row.currency_code}-${index}`} className="border-b border-[#CFD9D8]">
                    <Td strong>{row.payment_name}{cash && <div className="text-[9px] uppercase text-[#069495]">From denominations</div>}</Td>
                    <Td strong>{row.currency_code}</Td>
                    {canViewExpected && <Td right>{money(row.expected_amount, row.currency_code)}</Td>}
                    <Td right>
                      <div className="inline-flex items-center justify-end gap-1">
                        <input
                          type="number"
                          aria-label={`${row.payment_name} ${row.currency_code} counted amount`}
                          value={row.counted_amount}
                          disabled={cash}
                          onChange={(event) => updatePayment(index, event.target.value)}
                          className="h-8 w-28 border border-[#AAB5B5] px-2 text-right font-semibold disabled:bg-[#EEF2F2]"
                        />
                        {!cash && (
                          <button type="button" onClick={() => openKeyboard(index)} className="inline-flex h-8 w-8 items-center justify-center border border-[#069495] bg-[#E8F7F5] text-[#005A5A]" title="Open keyboard">
                            <Keyboard className="h-4 w-4" />
                          </button>
                        )}
                      </div>
                    </Td>
                    {canViewExpected && <Td right strong danger={variance < 0}>{money(variance, row.currency_code)}</Td>}
                  </tr>
                );
              })}
            </tbody>
          </table>
        </DataPanel>
        <Panel title="By Currency">
          {Object.values(totalsByCurrency).map((row) => (
            <div key={row.currency_code} className="mb-2 border border-[#CFD9D8] p-2">
              <SummaryLine label="Currency" value={row.currency_code} />
              {canViewExpected && <SummaryLine label="Expected" value={money(row.expected, row.currency_code)} />}
              <SummaryLine label="Counted" value={money(row.counted, row.currency_code)} />
              {canViewExpected && <SummaryLine label="Variance" value={money(row.variance, row.currency_code)} danger={row.variance < 0} />}
            </div>
          ))}
        </Panel>
      </div>
    </div>
  );
}

function MovementsStep({ movement, setMovement, currencies, currencyCodes, saveMovement, loading, rowsOut, rowsIn, canViewAmounts, openAmountKeyboard }) {
  const movementCurrencies = currencyCodes.length ? currencyCodes : currencies.map((currency) => currency.code);

  return (
    <div className="space-y-3">
      <PanelHeader title="Cash Drawer Movements" subtitle="Cash in/out is separate from payment count and is kept by currency." />
      <div className="grid gap-3 xl:grid-cols-[360px_1fr]">
        <Panel title="New Movement">
          <Field label="Type">
            <select value={movement.movement_type} onChange={(event) => setMovement({ ...movement, movement_type: event.target.value })} className="h-8 w-full border border-[#AAB5B5] px-2 text-[11px]">
              <option value="cash_out">Cash Out</option>
              <option value="bank_drop">Bank Drop</option>
              <option value="paid_out">Paid Out</option>
              <option value="petty_cash">Petty Cash</option>
              <option value="float_out">Float Out</option>
              <option value="cash_in">Cash In</option>
              <option value="float_in">Float In</option>
            </select>
          </Field>
          <div className="grid grid-cols-2 gap-2">
            <Field label="Currency">
              <select value={movement.currency_code} onChange={(event) => setMovement({ ...movement, currency_code: code(event.target.value) })} className="h-8 w-full border border-[#AAB5B5] px-2 text-[11px]">
                {movementCurrencies.map((currencyCode) => <option key={currencyCode} value={currencyCode}>{currencyCode}</option>)}
              </select>
            </Field>
            <Field label="Amount">
              <div className="flex gap-1">
                <input type="number" value={movement.amount} onChange={(event) => setMovement({ ...movement, amount: event.target.value })} className="h-8 min-w-0 flex-1 border border-[#AAB5B5] px-2 text-right text-[11px]" />
                <button type="button" onClick={openAmountKeyboard} className="inline-flex h-8 w-8 shrink-0 items-center justify-center border border-[#069495] bg-[#E8F7F5] text-[#005A5A]" title="Open keyboard"><Keyboard className="h-4 w-4" /></button>
              </div>
            </Field>
          </div>
          <Field label="Reason"><input value={movement.reason} onChange={(event) => setMovement({ ...movement, reason: event.target.value })} className="h-8 w-full border border-[#AAB5B5] px-2 text-[11px]" /></Field>
          <Field label="Reference"><input value={movement.reference_no} onChange={(event) => setMovement({ ...movement, reference_no: event.target.value })} className="h-8 w-full border border-[#AAB5B5] px-2 text-[11px]" /></Field>
          <Field label="Notes"><textarea rows={3} value={movement.notes} onChange={(event) => setMovement({ ...movement, notes: event.target.value })} className="w-full border border-[#AAB5B5] px-2 py-2 text-[11px]" /></Field>
          <button type="button" onClick={saveMovement} disabled={loading} className="mt-2 h-8 w-full border border-[#069495] bg-[#069495] text-[10px] font-semibold uppercase text-white disabled:opacity-60">Save Movement</button>
        </Panel>
        <div className="space-y-3"><MiniMovements title="Cash Outs" rows={rowsOut} canViewAmounts={canViewAmounts} /><MiniMovements title="Cash Ins" rows={rowsIn} canViewAmounts={canViewAmounts} /></div>
      </div>
    </div>
  );
}

function ReviewStep({
  header,
  closeShift,
  setCloseShift,
  notes,
  setNotes,
  cashByCurrency,
  totalsByCurrency,
  denominationByCurrency,
  payloadCurrencyCode,
  canViewExpected,
  loading,
  submit,
  isOnBehalf,
  selectedShift,
  actorName,
  onBehalfReason,
  setOnBehalfReason,
}) {
  return (
    <div className="space-y-3">
      <PanelHeader title="Review and Save" subtitle="Confirm the trading bracket and each currency before saving." />
      <div className="grid gap-3 lg:grid-cols-2">
        <Panel title="Bracket">
          <SummaryLine label="Mode" value="Hospitality" />
          <SummaryLine label="Trading Date" value={header.trading_date} />
          <SummaryLine label="Currency" value={payloadCurrencyCode} />
          <SummaryLine label="Period" value={header.cashup_period_label || "Auto"} />
          <SummaryLine label="Cashier" value={selectedShift?.cashier_name || "-"} />
          <SummaryLine label="Prepared By" value={actorName || "-"} highlight={isOnBehalf} />
        </Panel>
        <Panel title="Cash By Currency">
          {Object.values(cashByCurrency).map((row) => (
            <div key={row.currency_code} className="mb-2 border border-[#CFD9D8] p-2">
              <SummaryLine label="Currency" value={row.currency_code} />
              {canViewExpected && <SummaryLine label="Expected" value={money(row.expected, row.currency_code)} />}
              <SummaryLine label="Denominations" value={money(denominationByCurrency[row.currency_code] || 0, row.currency_code)} highlight />
              <SummaryLine label="Counted" value={money(row.counted, row.currency_code)} />
              {canViewExpected && <SummaryLine label="Variance" value={money(row.variance, row.currency_code)} danger={row.variance < 0} />}
            </div>
          ))}
          {Object.keys(cashByCurrency).length > 1 && <div className="mt-2 border border-[#B2DFDB] bg-[#F0FDFA] p-2 text-[10px] font-semibold text-[#006869]">No currencies are added together.</div>}
        </Panel>
      </div>
      <Panel title="Total By Currency">
        <div className="grid gap-2 lg:grid-cols-2 xl:grid-cols-3">
          {Object.values(totalsByCurrency).map((row) => (
            <div key={row.currency_code} className="border border-[#CFD9D8] p-2">
              <SummaryLine label="Currency" value={row.currency_code} />
              {canViewExpected && <SummaryLine label="Expected" value={money(row.expected, row.currency_code)} />}
              <SummaryLine label="Counted" value={money(row.counted, row.currency_code)} />
              {canViewExpected && <SummaryLine label="Variance" value={money(row.variance, row.currency_code)} danger={row.variance < 0} />}
            </div>
          ))}
        </div>
      </Panel>
      <Panel title="Final Notes">
        {isOnBehalf && (
          <Field label="Supervisor Reason (required)">
            <textarea rows={3} value={onBehalfReason} onChange={(event) => setOnBehalfReason(event.target.value)} placeholder={`Why are you cashing up for ${selectedShift?.cashier_name || "this cashier"}?`} className="mb-3 w-full border border-[#F79009] bg-[#FFFAEB] px-3 py-2 text-[12px] outline-none focus:border-[#B54708]" />
          </Field>
        )}
        <textarea rows={4} value={notes} onChange={(event) => setNotes(event.target.value)} className="w-full border border-[#AAB5B5] px-2 py-2 text-[12px]" />
        <label className="mt-2 flex items-center gap-2 text-[11px] font-semibold"><input type="checkbox" checked={closeShift} onChange={(event) => setCloseShift(event.target.checked)} /> Close shift after saving this cashup</label>
        <button type="button" onClick={() => submit()} disabled={loading} className="mt-3 h-10 w-full border border-[#069495] bg-[#069495] text-[11px] font-semibold uppercase text-white disabled:opacity-60">
          {closeShift ? "Cashup and End Shift" : "Save Cashup Only"}
        </button>
      </Panel>
    </div>
  );
}

function FloatingNumericKeyboard({ open, title, value, mode = "money", onClose, onApply }) {
  const [localValue, setLocalValue] = useState("");

  useEffect(() => {
    if (!open) return;
    setLocalValue(String(value || ""));
  }, [open, value]);

  if (!open) return null;

  const append = (digit) => {
    setLocalValue((prev) => {
      if (digit === "." && mode === "integer") return prev;
      if (digit === "." && String(prev).includes(".")) return prev;
      if (String(prev) === "0" && digit !== ".") return digit;
      return `${prev || ""}${digit}`;
    });
  };

  const backspace = () => setLocalValue((prev) => String(prev || "").slice(0, -1));
  const clear = () => setLocalValue("");
  const apply = () => onApply?.(localValue === "" ? "0" : localValue);

  const keys = mode === "integer"
    ? ["7", "8", "9", "4", "5", "6", "1", "2", "3", "0"]
    : ["7", "8", "9", "4", "5", "6", "1", "2", "3", "0", "."];

  return (
    <div className="fixed inset-0 z-[260] flex items-center justify-center bg-black/35 p-3">
      <div className="w-full max-w-[340px] border border-[#AAB5B5] bg-white shadow-2xl">
        <div className="flex items-center justify-between border-b border-[#CFD9D8] bg-[#004142] px-3 py-2 text-white">
          <div>
            <div className="text-[11px] font-semibold uppercase">On-Screen Keyboard</div>
            <div className="text-[10px] font-medium uppercase text-white/65">{title || "Enter amount"}</div>
          </div>
          <button type="button" onClick={onClose} className="flex h-8 w-8 items-center justify-center border border-white/20 bg-white/10 hover:bg-white/20">
            <X className="h-4 w-4" />
          </button>
        </div>

        <div className="border-b border-[#CFD9D8] bg-[#F8FAFC] p-3">
          <input
            value={localValue}
            onChange={(event) => setLocalValue(event.target.value.replace(mode === "integer" ? /[^0-9]/g : /[^0-9.]/g, ""))}
            className="h-11 w-full border border-[#AAB5B5] bg-white px-3 text-right text-[22px] font-semibold text-[#0C1B1B] outline-none focus:border-[#069495]"
            autoFocus
          />
        </div>

        <div className="grid grid-cols-3 gap-2 p-3">
          {keys.map((key) => (
            <button key={key} type="button" onClick={() => append(key)} className="h-12 border border-[#AAB5B5] bg-white text-[18px] font-semibold text-[#0C1B1B] hover:bg-[#F5F7FA]">
              {key}
            </button>
          ))}
          <button type="button" onClick={backspace} className="h-12 border border-[#AAB5B5] bg-[#F5F7FA] text-[12px] font-semibold uppercase text-[#324443]">Back</button>
          <button type="button" onClick={clear} className="h-12 border border-[#FDA29B] bg-[#FEF3F2] text-[12px] font-semibold uppercase text-[#B42318]">Clear</button>
        </div>

        <div className="grid grid-cols-2 gap-2 border-t border-[#CFD9D8] bg-[#F8FAFC] p-3">
          <button type="button" onClick={onClose} className="h-10 border border-[#AAB5B5] bg-white text-[11px] font-semibold uppercase text-[#324443]">Cancel</button>
          <button type="button" onClick={apply} className="h-10 border border-[#069495] bg-[#069495] text-[11px] font-semibold uppercase text-white">Apply</button>
        </div>
      </div>
    </div>
  );
}

function MiniMovements({ title, rows = [], canViewAmounts = false }) {
  return (
    <DataPanel title={title}>
      <table className="w-full border-collapse text-left text-[11px]">
        <thead className="bg-[#0E4D4D] text-white"><tr><Th>Type</Th><Th>Reason</Th>{canViewAmounts && <Th right>Amount</Th>}</tr></thead>
        <tbody>
          {rows.length ? rows.map((row) => (
            <tr key={row.id} className="border-b border-[#CFD9D8]"><Td strong>{row.movement_type}</Td><Td>{row.reason || "-"}</Td>{canViewAmounts && <Td right strong>{money(row.amount, row.currency_code)}</Td>}</tr>
          )) : <tr><td colSpan={canViewAmounts ? 3 : 2} className="px-3 py-8 text-center text-[12px] font-semibold text-[#627474]">No records.</td></tr>}
        </tbody>
      </table>
    </DataPanel>
  );
}

function PanelHeader({ title, subtitle, right }) {
  return (
    <div className="flex flex-col gap-2 border border-[#CFD9D8] bg-white p-2 md:flex-row md:items-center md:justify-between">
      <div><div className="text-[12px] font-semibold uppercase text-[#324443]">{title}</div><div className="mt-1 text-[10px] font-medium uppercase text-[#627474]">{subtitle}</div></div>{right}
    </div>
  );
}

function Panel({ title, children }) {
  return <div className="border border-[#CFD9D8] bg-white p-2 shadow-sm"><div className="mb-2 border-b border-[#E4E8E8] pb-2 text-[11px] font-semibold uppercase text-[#324443]">{title}</div>{children}</div>;
}

function DataPanel({ title, children }) {
  return <div className="overflow-hidden border border-[#CFD9D8] bg-white shadow-sm"><div className="border-b border-[#CFD9D8] bg-[#F5F7FA] px-2 py-2 text-[11px] font-semibold uppercase text-[#324443]">{title}</div><div className="overflow-auto">{children}</div></div>;
}

function Field({ label, children }) {
  return <label className="mb-2 block"><div className="mb-1 text-[10px] font-semibold uppercase text-[#627474]">{label}</div>{children}</label>;
}

function ReadOnlyBox({ label, value }) {
  return <div className="border border-[#CFD9D8] bg-[#F8FAFC] p-2"><div className="text-[9px] font-semibold uppercase text-[#627474]">{label}</div><div className="mt-1 text-[12px] font-bold text-[#0C1B1B]">{value}</div></div>;
}

function SummaryLine({ label, value, danger = false, highlight = false }) {
  return <div className={`flex items-center justify-between gap-2 border-b border-[#EEF2F2] py-1 text-[10px] last:border-b-0 ${highlight ? "bg-[#ECFDF3] px-1" : ""}`}><span className="text-[#627474]">{label}</span><span className={`truncate text-right font-semibold ${danger ? "text-[#B42318]" : highlight ? "text-[#067647]" : "text-[#1C2C2B]"}`}>{value}</span></div>;
}

function TopStep({ active, number, title, onClick }) {
  return <button type="button" onClick={onClick} className={`flex h-10 items-center justify-center gap-2 border text-[10px] font-semibold uppercase ${active ? "border-[#069495] bg-[#069495] text-white" : "border-[#CFD9D8] bg-white text-[#465757] hover:bg-[#F8FAFC]"}`}><span className={`flex h-5 w-5 items-center justify-center rounded-full text-[10px] ${active ? "bg-white text-[#057B7C]" : "bg-[#E4E8E8] text-[#324443]"}`}>{number}</span>{title}</button>;
}

function CashupKpi({ label, value }) {
  return <div className="border border-[#CFD9D8] bg-white p-3"><div className="text-[9px] font-semibold uppercase text-[#627474]">{label}</div><div className="mt-1 truncate text-[16px] font-semibold text-[#004141]">{value}</div></div>;
}

function Th({ children, right = false }) {
  return <th className={`border-r border-white/10 px-2 py-2 text-[10px] font-semibold uppercase ${right ? "text-right" : "text-left"}`}>{children}</th>;
}

function Td({ children, right = false, strong = false, danger = false }) {
  return <td className={`border-r border-[#CFD9D8] px-2 py-2 align-top ${right ? "text-right" : "text-left"} ${strong ? "font-semibold" : ""} ${danger ? "text-[#B42318]" : "text-[#324443]"}`}>{children}</td>;
}
