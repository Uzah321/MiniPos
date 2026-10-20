import React, { useEffect, useMemo, useRef, useState } from "react";
import {
  AlertTriangle,
  Banknote,
  CheckCircle2,
  Download,
  Printer,
  RefreshCw,
  Search,
  UserRound,
  X,
} from "lucide-react";

import {
  captureCreditCustomerPosPayment,
  downloadCreditCustomerPaymentReceipt,
  getCreditCustomerPosAccount,
  getCreditCustomersForPos,
} from "../../../../services/CreditCustomerService";
import { convertCurrency } from "../../../../services/CurrencyService";

const normalizeCode = (value) => String(value || "").trim().toUpperCase();
const normalizeKey = (value) => String(value || "").trim().toLowerCase().replaceAll(" ", "_").replaceAll("-", "_");
const amount = (value) => Number(value || 0);
const money = (value, currency = "USD") => `${normalizeCode(currency) || "USD"} ${amount(value).toFixed(2)}`;

const customerName = (row) => row?.name || row?.customer_name || row?.trading_name || "Account customer";
const accountNumber = (row) => row?.account_number || row?.credit_account_number || row?.customer_code || "-";
const accountCurrency = (row) => normalizeCode(row?.currency_code || row?.account_currency || row?.default_currency_code || "USD");
const accountBalance = (payload, customer) => amount(
  payload?.closing_balance ??
  payload?.statement?.closing_balance ??
  customer?.current_balance ??
  customer?.balance ??
  0
);

const getErrorMessage = (error, fallback = "Request failed.") => {
  const data = error?.response?.data;
  if (data?.errors && typeof data.errors === "object") {
    return Object.values(data.errors).flat().join("\n");
  }
  return error?.displayMessage || data?.error || data?.message || error?.message || fallback;
};

const normalizeRows = (payload) => {
  if (Array.isArray(payload)) return payload;
  if (Array.isArray(payload?.rows)) return payload.rows;
  if (Array.isArray(payload?.statement?.rows)) return payload.statement.rows;
  if (Array.isArray(payload?.data?.rows)) return payload.data.rows;
  if (Array.isArray(payload?.data)) return payload.data;
  return [];
};

const normalizeCustomers = (payload) => {
  if (Array.isArray(payload)) return payload;
  if (Array.isArray(payload?.data)) return payload.data;
  if (Array.isArray(payload?.data?.data)) return payload.data.data;
  return [];
};

const isCustomerAccountTender = (row) => {
  const values = [row?.name, row?.type, row?.money_code].map(normalizeKey);
  return values.some((value) =>
    ["customer_credit", "credit_customer", "credit_account", "account"].includes(value) ||
    value.includes("customer_credit") ||
    value.includes("credit_customer") ||
    value.includes("credit_account")
  );
};

const isActivePaymentType = (row) => row && row.is_active !== false && row.active !== false && !isCustomerAccountTender(row);
const paymentTypeName = (row) => row?.name || row?.payment_name || row?.money_code || "Payment";
const customerAccountStatus = (row) => normalizeKey(row?.status || row?.account_status || "approved");
const isSettlementOnlyAccount = (row) => ["blocked", "suspended"].includes(customerAccountStatus(row));

const currencyRows = (rows) => (Array.isArray(rows) ? rows : [])
  .filter((row) => row?.is_active !== false)
  .map((row) => ({ ...row, code: normalizeCode(row?.code || row?.currency_code) }))
  .filter((row) => row.code);

const convertedAmount = (payload) => amount(
  payload?.data?.converted_amount ??
  payload?.converted_amount ??
  payload?.data?.amount ??
  payload?.amount ??
  0
);

const newClientPaymentId = () => {
  if (typeof crypto !== "undefined" && crypto.randomUUID) return crypto.randomUUID();
  return `pos-account-${Date.now()}-${Math.random().toString(16).slice(2)}`;
};

const rowDate = (row) => {
  const value = row?.transaction_date || row?.document_date || row?.created_at || row?.date;
  if (!value) return "-";
  const parsed = new Date(value);
  return Number.isNaN(parsed.getTime()) ? String(value) : parsed.toLocaleString();
};

const entryLabel = (row) => {
  const type = normalizeKey(row?.transaction_type || row?.entry_type || row?.type);
  if (type === "sale") return "Credit sale";
  if (type === "payment") {
    const source = normalizeKey(row?.metadata?.source);
    if (source.includes("change")) return "Change credited";
    return "Account payment";
  }
  if (type.includes("reversal")) return "Reversal";
  return String(row?.transaction_type || row?.entry_type || row?.type || "Account entry").replaceAll("_", " ");
};

const paymentIdFromRow = (row) =>
  row?.payment_id ||
  row?.credit_customer_payment_id ||
  row?.payment?.id ||
  row?.metadata?.payment_id ||
  null;

export default function PosCustomerAccountModal({
  open,
  onClose,
  currencies = [],
  paymentTypes = [],
  onPaymentPosted,
}) {
  const searchRef = useRef(null);
  const [query, setQuery] = useState("");
  const [customers, setCustomers] = useState([]);
  const [selectedId, setSelectedId] = useState("");
  const [accountData, setAccountData] = useState(null);
  const [loadingCustomers, setLoadingCustomers] = useState(false);
  const [loadingAccount, setLoadingAccount] = useState(false);
  const [saving, setSaving] = useState(false);
  const [notice, setNotice] = useState("");
  const [error, setError] = useState("");
  const [paymentAmount, setPaymentAmount] = useState("");
  const [paymentTypeId, setPaymentTypeId] = useState("");
  const [paymentCurrency, setPaymentCurrency] = useState("");
  const [reference, setReference] = useState("");
  const [notes, setNotes] = useState("");
  const [clientPaymentId, setClientPaymentId] = useState(newClientPaymentId);
  const [conversion, setConversion] = useState(null);
  const [converting, setConverting] = useState(false);
  const [pendingExcess, setPendingExcess] = useState(null);
  const [lastPaymentReceipt, setLastPaymentReceipt] = useState(null);
  const [paymentReceiptBusy, setPaymentReceiptBusy] = useState("");

  const allowedPaymentTypes = useMemo(
    () => (Array.isArray(paymentTypes) ? paymentTypes : []).filter(isActivePaymentType),
    [paymentTypes]
  );
  const availableCurrencies = useMemo(() => currencyRows(currencies), [currencies]);
  const selectedListCustomer = useMemo(
    () => customers.find((row) => String(row?.id) === String(selectedId)) || null,
    [customers, selectedId]
  );
  const customer = accountData?.customer || accountData?.account || selectedListCustomer;
  const settlementOnly = isSettlementOnlyAccount(customer);
  const currency = accountCurrency(customer);
  const balance = accountBalance(accountData, customer);
  const due = Math.max(0, balance);
  const existingCredit = Math.max(0, -balance);
  const limit = amount(customer?.credit_limit ?? customer?.limit_amount);
  const available = amount(accountData?.available_credit ?? customer?.available_credit ?? (limit - balance));
  const rows = normalizeRows(accountData);
  const enteredAmount = amount(paymentAmount);
  const paymentInAccountCurrency = normalizeCode(paymentCurrency) === currency
    ? enteredAmount
    : amount(conversion?.amount);
  const projectedApplied = Math.min(due, paymentInAccountCurrency);
  const projectedCredit = Math.max(0, paymentInAccountCurrency - due) + existingCredit;

  const loadCustomers = async (search = "", { preserveSelection = false } = {}) => {
    try {
      setLoadingCustomers(true);
      setError("");
      const response = await getCreditCustomersForPos({
        purpose: "account_payment",
        q: String(search || "").trim() || undefined,
        limit: 300,
      });
      const next = normalizeCustomers(response);
      setCustomers(next);
      setSelectedId((current) => {
        // A restricted account disappears from the eligible lookup as soon as
        // its debt is fully settled. Keep that completed account selected for
        // the post-payment confirmation so its docket can still be printed.
        // A later cashier-driven search or selection uses the normal path and
        // clears the prior account/payment entry safely.
        if (preserveSelection && current) return current;
        if (current && next.some((row) => String(row?.id) === String(current))) return current;
        return next[0]?.id || "";
      });
    } catch (requestError) {
      setCustomers([]);
      setError(getErrorMessage(requestError, "Customer accounts could not be loaded."));
    } finally {
      setLoadingCustomers(false);
    }
  };

  const loadAccount = async (id = selectedId) => {
    if (!id) {
      setAccountData(null);
      return null;
    }
    try {
      setLoadingAccount(true);
      setError("");
      const response = await getCreditCustomerPosAccount(id);
      setAccountData(response || null);
      return response;
    } catch (requestError) {
      setAccountData(null);
      setError(getErrorMessage(requestError, "Account history could not be loaded."));
      return null;
    } finally {
      setLoadingAccount(false);
    }
  };

  useEffect(() => {
    if (!open) return;
    setQuery("");
    setSelectedId("");
    setAccountData(null);
    setPaymentAmount("");
    setReference("");
    setNotes("");
    setNotice("");
    setError("");
    setPendingExcess(null);
    setLastPaymentReceipt(null);
    setClientPaymentId(newClientPaymentId());
    setPaymentTypeId(allowedPaymentTypes[0]?.id || "");
    setPaymentCurrency(availableCurrencies.find((row) => row?.is_base_currency)?.code || availableCurrencies[0]?.code || "");
    window.setTimeout(() => searchRef.current?.focus(), 60);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  useEffect(() => {
    if (!open) return undefined;
    const timer = window.setTimeout(() => loadCustomers(query), 280);
    return () => window.clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, query]);

  useEffect(() => {
    if (!open || !selectedId) return;
    // An entered amount belongs to one account only. Clear it when the cashier
    // changes customer so a pending payment cannot be posted to the wrong ledger.
    setPaymentAmount("");
    setReference("");
    setNotes("");
    setConversion(null);
    setPendingExcess(null);
    setLastPaymentReceipt(null);
    setClientPaymentId(newClientPaymentId());
    loadAccount(selectedId);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, selectedId]);

  useEffect(() => {
    if (!open || paymentCurrency || !currency) return;
    setPaymentCurrency(currency);
  }, [open, currency, paymentCurrency]);

  useEffect(() => {
    if (!open) return undefined;
    const from = normalizeCode(paymentCurrency);
    const to = currency;
    const value = amount(paymentAmount);
    if (!from || !to || value <= 0 || from === to) {
      setConversion(from === to && value > 0 ? { amount: value, from, to } : null);
      setConverting(false);
      return undefined;
    }

    let active = true;
    const timer = window.setTimeout(async () => {
      try {
        setConverting(true);
        const response = await convertCurrency({ from, to, amount: value });
        if (active) setConversion({ amount: convertedAmount(response), from, to });
      } catch (requestError) {
        if (active) {
          setConversion(null);
          setError(getErrorMessage(requestError, "Payment currency could not be converted."));
        }
      } finally {
        if (active) setConverting(false);
      }
    }, 250);

    return () => {
      active = false;
      window.clearTimeout(timer);
    };
  }, [open, paymentAmount, paymentCurrency, currency]);

  if (!open) return null;

  const resetPaymentEntry = () => {
    setPaymentAmount("");
    setReference("");
    setNotes("");
    setConversion(null);
    setPendingExcess(null);
    setClientPaymentId(newClientPaymentId());
  };

  const deliverPaymentReceipt = async (customerId, paymentId, delivery = "open") => {
    if (!customerId || !paymentId) {
      setError("The payment confirmation is not available for this account entry.");
      return;
    }

    const busyKey = `${paymentId}:${delivery}`;
    try {
      setPaymentReceiptBusy(busyKey);
      setError("");
      await downloadCreditCustomerPaymentReceipt(
        customerId,
        paymentId,
        { paper: "80mm" },
        delivery
      );
    } catch (requestError) {
      setError(getErrorMessage(requestError, "Payment confirmation could not be opened."));
    } finally {
      setPaymentReceiptBusy("");
    }
  };

  const postPayment = async (payload) => {
    try {
      setSaving(true);
      setError("");
      setNotice("");
      const response = await captureCreditCustomerPosPayment(selectedId, payload);
      const responseData = response?.data || response || {};
      const responseAccount = response?.account || {};
      const responseStatement = response?.statement || responseData?.statement;
      const responseCustomer = response?.customer || responseData?.customer;
      const applied = amount(
        responseAccount?.applied_to_debt ??
        responseData?.amount_applied_to_debt ??
        responseData?.applied_to_existing_debt ??
        responseData?.payment?.metadata?.applied_to_existing_debt ??
        projectedApplied
      );
      const newCredit = amount(
        responseAccount?.credit_created ??
        responseData?.new_account_credit ??
        responseData?.overpayment_credit_created ??
        responseData?.account_credit_created ??
        responseData?.payment?.metadata?.overpayment_credit_created ??
        Math.max(0, paymentInAccountCurrency - due)
      );

      if (responseStatement) {
        setAccountData({
          ...responseStatement,
          customer: responseStatement.customer || responseCustomer || customer,
        });
      } else {
        await loadAccount(selectedId);
      }

      const paymentNumber = responseData?.payment_number || responseData?.reference || "-";
      const paymentId = response?.payment_receipt?.payment_id || responseData?.id || null;
      const replayPrefix = response?.idempotent_replay ? "This retry matched an already-posted payment; no duplicate was created. " : "";
      const balanceAfter = amount(responseAccount?.balance_after ?? responseCustomer?.current_balance);
      setLastPaymentReceipt({
        replay: Boolean(response?.idempotent_replay),
        customerId: selectedId,
        paymentId,
        paymentNumber,
        reference: responseData?.reference || payload?.reference || "-",
        received: amount(responseAccount?.amount_received ?? payload?.amount),
        paymentCurrency: normalizeCode(responseAccount?.payment_currency_code || payload?.currency_code),
        accountAmount: amount(responseAccount?.account_amount ?? paymentInAccountCurrency),
        accountCurrency: normalizeCode(responseAccount?.currency_code || currency),
        applied,
        creditCreated: newCredit,
        balanceAfter,
        creditBalanceAfter: Math.max(0, -balanceAfter),
      });
      setNotice(`${replayPrefix}${newCredit > 0
        ? `Payment ${paymentNumber} posted. ${money(applied, currency)} cleared debt and ${money(newCredit, currency)} became customer account credit.`
        : `Payment ${paymentNumber} posted. ${money(applied, currency)} was applied to the customer account.`}`);
      resetPaymentEntry();
      await loadCustomers(query, { preserveSelection: true });
      onPaymentPosted?.({ response, customer: responseCustomer || customer });
    } catch (requestError) {
      const data = requestError?.response?.data || {};
      if (
        payload?.confirm_overpayment !== true &&
        !settlementOnly &&
        (data?.requires_overpayment_confirmation === true || data?.code === "ACCOUNT_CREDIT_CONFIRMATION_REQUIRED")
      ) {
        setError("");
        setPendingExcess({
          payload,
          excess: Math.max(0, paymentInAccountCurrency - due),
          applied: Math.min(due, paymentInAccountCurrency),
          accountAmount: paymentInAccountCurrency,
        });
      } else {
        setError(getErrorMessage(requestError, "Account payment failed."));
      }
    } finally {
      setSaving(false);
    }
  };

  const submitPayment = () => {
    if (!selectedId) return setError("Select a customer account first.");
    if (!paymentTypeId) return setError("Select the payment method actually received.");
    if (!normalizeCode(paymentCurrency)) return setError("Select the payment currency.");
    if (enteredAmount <= 0) return setError("Enter a payment amount greater than zero.");
    if (normalizeCode(paymentCurrency) !== currency && (converting || paymentInAccountCurrency <= 0)) {
      return setError("Wait for a valid account-currency conversion before posting the payment.");
    }

    const payload = {
      payment_type_id: paymentTypeId,
      amount: enteredAmount,
      currency_code: normalizeCode(paymentCurrency),
      client_payment_id: clientPaymentId,
      confirm_overpayment: false,
      reference: String(reference || "").trim() || null,
      notes: String(notes || "").trim() || null,
    };

    const excess = Math.max(0, paymentInAccountCurrency - due);
    if (settlementOnly && excess > 0.0001) {
      setError(
        `${customerAccountStatus(customer) === "suspended" ? "Suspended" : "Blocked"} accounts may only settle their outstanding balance. Reduce the account value to ${money(due, currency)} or less.`
      );
      return undefined;
    }

    if (excess > 0.0001) {
      setPendingExcess({ payload, excess, applied: projectedApplied, accountAmount: paymentInAccountCurrency });
      return undefined;
    }
    return postPayment(payload);
  };

  return (
    <div className="fixed inset-0 z-[110] flex items-center justify-center bg-[#003637]/65 p-2 text-[#1C2C2B]" role="presentation">
      <div className="flex h-[calc(100dvh-16px)] w-full max-w-[1440px] flex-col overflow-hidden border border-[#00BCBC] bg-white shadow-2xl" role="dialog" aria-modal="true" aria-label="Customer account payment">
        <header className="grid min-h-[54px] shrink-0 grid-cols-[1fr_auto] border-b border-[#00BCBC] bg-[#004142] text-white">
          <div className="flex items-center gap-3 px-3 py-2">
            <div className="flex h-9 w-9 items-center justify-center border border-white/20 bg-white/10"><Banknote className="h-5 w-5" /></div>
            <div>
              <div className="text-[14px] font-black uppercase tracking-[0.1em]">Customer Account Payment</div>
              <div className="text-[9px] font-semibold uppercase tracking-[0.14em] text-white/70">Receive a bill payment / create prepaid credit / no sale and no stock movement</div>
            </div>
          </div>
          <button type="button" onClick={onClose} disabled={saving} className="flex w-[60px] items-center justify-center border-l border-white/20 bg-[#B42318] hover:bg-[#9E1F16] disabled:opacity-50" title="Close customer account"><X className="h-5 w-5" /></button>
        </header>

        {(error || notice) && (
          <div className={`shrink-0 whitespace-pre-line border-b px-3 py-2 text-[11px] font-semibold ${error ? "border-[#FDA29B] bg-[#FEF3F2] text-[#B42318]" : "border-[#6CE9A6] bg-[#ECFDF3] text-[#067647]"}`}>
            {error || notice}
          </div>
        )}

        <div className="grid min-h-0 flex-1 lg:grid-cols-[330px_minmax(0,1fr)]">
          <aside className="flex min-h-0 flex-col border-r border-[#ACB8B8] bg-[#F5F7FA]">
            <div className="shrink-0 border-b border-[#CFD9D8] p-2">
              <label className="flex h-10 items-center gap-2 border border-[#ACB8B8] bg-white px-2">
                <Search className="h-4 w-4 text-[#627474]" />
                <input ref={searchRef} value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Name, account, phone" className="min-w-0 flex-1 bg-transparent text-[12px] font-semibold outline-none" />
                {loadingCustomers && <RefreshCw className="h-4 w-4 animate-spin text-[#069495]" />}
              </label>
            </div>
            <div className="min-h-0 flex-1 overflow-y-auto p-2">
              {customers.length === 0 ? (
                <div className="border border-[#CFD9D8] bg-white p-5 text-center text-[11px] text-[#627474]">{loadingCustomers ? "Loading customer accounts..." : "No customer account matches this search."}</div>
              ) : customers.map((row) => {
                const active = String(row.id) === String(selectedId);
                const rowBalance = amount(row.current_balance ?? row.balance);
                const rowCurrency = accountCurrency(row);
                return (
                  <button key={row.id} type="button" onClick={() => setSelectedId(row.id)} className={`mb-1.5 w-full border p-2 text-left ${active ? "border-[#069495] bg-[#E8F7F5]" : "border-[#CFD9D8] bg-white hover:border-[#069495]"}`}>
                    <div className="flex items-start justify-between gap-2">
                      <div className="min-w-0"><div className="truncate text-[12px] font-black text-[#004141]">{customerName(row)}</div><div className="mt-0.5 truncate text-[9px] font-semibold uppercase text-[#627474]">{accountNumber(row)} · {row.phone || "No phone"}</div></div>
                      <UserRound className="h-4 w-4 shrink-0 text-[#069495]" />
                    </div>
                    <div className={`mt-2 text-[11px] font-black ${rowBalance > 0 ? "text-[#B54708]" : "text-[#067647]"}`}>{rowBalance > 0 ? `Due ${money(rowBalance, rowCurrency)}` : `Credit ${money(Math.abs(rowBalance), rowCurrency)}`}</div>
                  </button>
                );
              })}
            </div>
          </aside>

          <main className="grid min-h-0 grid-rows-[auto_minmax(0,1fr)] bg-[#EEF1F1]">
            <section className="shrink-0 border-b border-[#ACB8B8] bg-white p-2">
              {customer ? (
                <>
                  <div className="flex flex-wrap items-start justify-between gap-2">
                    <div><div className="text-[16px] font-black text-[#004141]">{customerName(customer)}</div><div className="text-[10px] font-semibold uppercase text-[#627474]">Account {accountNumber(customer)} · {currency}</div></div>
                    <button type="button" onClick={() => loadAccount()} disabled={loadingAccount} className="flex h-8 items-center gap-1 border border-[#ACB8B8] bg-white px-3 text-[10px] font-black uppercase text-[#324443] disabled:opacity-50"><RefreshCw className={`h-3.5 w-3.5 ${loadingAccount ? "animate-spin" : ""}`} /> Refresh</button>
                  </div>
                  <div className="mt-2 grid grid-cols-2 gap-1.5 md:grid-cols-4">
                    <AccountMetric label="Amount Due" value={money(due, currency)} danger={due > 0} />
                    <AccountMetric label="Prepaid Credit" value={money(existingCredit, currency)} good={existingCredit > 0} />
                    <AccountMetric label="Credit Limit" value={money(limit, currency)} />
                    <AccountMetric label="Available" value={money(available, currency)} good={available >= 0} />
                  </div>
                </>
              ) : <div className="p-3 text-center text-[11px] text-[#627474]">Select a customer account.</div>}
            </section>

            <div className="grid min-h-0 gap-2 overflow-hidden p-2 xl:grid-cols-[390px_minmax(0,1fr)]">
              <section className="min-h-0 overflow-y-auto border border-[#ACB8B8] bg-white">
                <div className="border-b border-[#CFD9D8] bg-[#F5F7FA] px-3 py-2"><div className="text-[11px] font-black uppercase text-[#004141]">Receive Money</div><div className="text-[9px] text-[#627474]">This is an account collection, not a product sale. It creates no stock movement.</div></div>
                <div className="space-y-2 p-3">
                  {lastPaymentReceipt && (
                    <div className="border border-[#6CE9A6] bg-[#ECFDF3] p-2 text-[10px]">
                      <div className="mb-1 flex items-center gap-1 font-black uppercase text-[#067647]"><CheckCircle2 className="h-4 w-4" />{lastPaymentReceipt.replay ? "Original Payment Recovered" : "Payment Posted"}</div>
                      <Breakdown label="Tendered" value={money(lastPaymentReceipt.received, lastPaymentReceipt.paymentCurrency)} />
                      <Breakdown label="Account value" value={money(lastPaymentReceipt.accountAmount, lastPaymentReceipt.accountCurrency)} />
                      <Breakdown label="Applied to debt" value={money(lastPaymentReceipt.applied, lastPaymentReceipt.accountCurrency)} />
                      <Breakdown label="Change returned" value={money(0, lastPaymentReceipt.paymentCurrency)} />
                      <Breakdown label="New account credit" value={money(lastPaymentReceipt.creditCreated, lastPaymentReceipt.accountCurrency)} good={lastPaymentReceipt.creditCreated > 0} />
                      <Breakdown label="Resulting balance" value={money(lastPaymentReceipt.balanceAfter, lastPaymentReceipt.accountCurrency)} />
                      <Breakdown label="Resulting prepaid credit" value={money(lastPaymentReceipt.creditBalanceAfter, lastPaymentReceipt.accountCurrency)} good={lastPaymentReceipt.creditBalanceAfter > 0} />
                      <Breakdown label="Payment / Reference" value={`${lastPaymentReceipt.paymentNumber} / ${lastPaymentReceipt.reference}`} />
                      {lastPaymentReceipt.paymentId && (
                        <div className="mt-2 flex justify-end gap-2 border-t border-[#A6F4C5] pt-2">
                          <button type="button" disabled={Boolean(paymentReceiptBusy)} onClick={() => deliverPaymentReceipt(lastPaymentReceipt.customerId, lastPaymentReceipt.paymentId, "open")} className="flex h-8 items-center gap-1 border border-[#00883F] bg-white px-3 text-[9px] font-black uppercase text-[#067647] disabled:opacity-50"><Printer className="h-3.5 w-3.5" />{paymentReceiptBusy === `${lastPaymentReceipt.paymentId}:open` ? "Opening..." : "Print 80mm"}</button>
                          <button type="button" disabled={Boolean(paymentReceiptBusy)} onClick={() => deliverPaymentReceipt(lastPaymentReceipt.customerId, lastPaymentReceipt.paymentId, "download")} className="flex h-8 items-center gap-1 border border-[#00883F] bg-[#00883F] px-3 text-[9px] font-black uppercase text-white disabled:opacity-50"><Download className="h-3.5 w-3.5" />{paymentReceiptBusy === `${lastPaymentReceipt.paymentId}:download` ? "Downloading..." : "Download"}</button>
                        </div>
                      )}
                    </div>
                  )}
                  <div className="grid grid-cols-2 gap-2">
                    <Field label="Amount Received"><input type="number" min="0" step="0.01" value={paymentAmount} onChange={(event) => { setPaymentAmount(event.target.value); setError(""); }} className="h-10 w-full border border-[#ACB8B8] bg-white px-2 text-right text-[16px] font-black outline-none focus:border-[#069495]" placeholder="0.00" /></Field>
                    <Field label="Currency"><select value={paymentCurrency} onChange={(event) => setPaymentCurrency(event.target.value)} className="h-10 w-full border border-[#ACB8B8] bg-white px-2 text-[11px] font-black outline-none focus:border-[#069495]">{availableCurrencies.map((row) => <option key={row.id || row.code} value={row.code}>{row.code}</option>)}</select></Field>
                  </div>
                  <button
                    type="button"
                    disabled={due <= 0 || saving}
                    onClick={() => {
                      setPaymentCurrency(currency);
                      setPaymentAmount(due.toFixed(2));
                      setError("");
                    }}
                    className="h-8 w-full border border-[#069495] bg-[#E8F7F5] text-[10px] font-black uppercase text-[#005A5A] disabled:cursor-not-allowed disabled:border-[#CFD6D6] disabled:bg-[#F2F4F7] disabled:text-[#96A5A4]"
                  >
                    Pay Full Due · {money(due, currency)}
                  </button>
                  <Field label="Payment Method"><select value={paymentTypeId} onChange={(event) => setPaymentTypeId(event.target.value)} className="h-10 w-full border border-[#ACB8B8] bg-white px-2 text-[11px] font-black outline-none focus:border-[#069495]"><option value="">Select actual tender</option>{allowedPaymentTypes.map((row) => <option key={row.id} value={row.id}>{paymentTypeName(row)}</option>)}</select></Field>
                  <Field label="Reference"><input value={reference} onChange={(event) => setReference(event.target.value)} className="h-10 w-full border border-[#ACB8B8] bg-white px-2 text-[11px] font-semibold outline-none focus:border-[#069495]" placeholder="Optional receipt, bank or mobile reference" /></Field>
                  <Field label="Notes"><textarea value={notes} onChange={(event) => setNotes(event.target.value)} rows={2} className="w-full border border-[#ACB8B8] px-2 py-2 text-[11px] font-semibold outline-none focus:border-[#069495]" placeholder="Optional account payment note" /></Field>

                  {settlementOnly && (
                    <div className="flex gap-2 border border-[#FEDF89] bg-[#FFFAEB] p-2 text-[10px] font-semibold text-[#B54708]"><AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" /><span>This {customerAccountStatus(customer)} account is settlement-only. Receive no more than the outstanding {money(due, currency)}; prepaid credit cannot be created.</span></div>
                  )}

                  <div className="border border-[#CFD9D8] bg-[#F8FAFC] p-2 text-[10px]">
                    <Breakdown label="Received" value={money(enteredAmount, paymentCurrency || currency)} />
                    <Breakdown label={`Account value${normalizeCode(paymentCurrency) !== currency ? " after conversion" : ""}`} value={converting ? "Converting..." : money(paymentInAccountCurrency, currency)} />
                    <Breakdown label="Applied to debt" value={money(projectedApplied, currency)} />
                    <Breakdown label="Account credit after payment" value={money(projectedCredit, currency)} good={projectedCredit > 0} />
                  </div>

                  {!settlementOnly && paymentInAccountCurrency > due && enteredAmount > 0 && (
                    <div className="flex gap-2 border border-[#FEDF89] bg-[#FFFAEB] p-2 text-[10px] font-semibold text-[#B54708]"><AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" /><span>The payment exceeds the bill. The excess will only become prepaid customer credit after a separate confirmation.</span></div>
                  )}

                  <button type="button" onClick={submitPayment} disabled={saving || loadingAccount || !customer} className="flex h-11 w-full items-center justify-center gap-2 border border-[#00883F] bg-[#00A651] text-[12px] font-black uppercase text-white disabled:cursor-not-allowed disabled:opacity-50">{saving ? <RefreshCw className="h-4 w-4 animate-spin" /> : <Banknote className="h-4 w-4" />}{saving ? "Posting Payment..." : "Post Account Payment"}</button>
                  <div className="text-[9px] leading-relaxed text-[#627474]">Payments allocate to the oldest outstanding credit invoices first. The client payment ID remains unchanged while this attempt is pending, preventing a retry from silently posting the same payment twice.</div>
                </div>
              </section>

              <section className="flex min-h-0 flex-col border border-[#ACB8B8] bg-white">
                <div className="flex h-10 shrink-0 items-center justify-between border-b border-[#CFD9D8] bg-[#F5F7FA] px-2"><div><div className="text-[11px] font-black uppercase text-[#004141]">Account History</div><div className="text-[8px] uppercase text-[#627474]">Sales create debit · payments and credited change create credit</div></div><div className="text-[10px] font-black text-[#627474]">{rows.length} entries</div></div>
                <div className="min-h-0 flex-1 overflow-auto">
                  {loadingAccount ? <div className="flex h-full items-center justify-center gap-2 text-[11px] text-[#627474]"><RefreshCw className="h-4 w-4 animate-spin" /> Loading account history...</div> : rows.length === 0 ? <div className="p-8 text-center text-[11px] text-[#627474]">No account activity yet.</div> : (
                    <table className="w-full min-w-[760px] border-collapse text-[10px]">
                      <thead className="sticky top-0 bg-[#0E4D4D] text-white"><tr><HistoryHead>Date</HistoryHead><HistoryHead>Activity</HistoryHead><HistoryHead>Document</HistoryHead><HistoryHead>Details</HistoryHead><HistoryHead right>Debit</HistoryHead><HistoryHead right>Credit</HistoryHead><HistoryHead right>Balance</HistoryHead><HistoryHead>Confirmation</HistoryHead></tr></thead>
                      <tbody>{[...rows].reverse().map((row, index) => {
                        const rowCurrency = normalizeCode(row?.currency_code || currency);
                        const rowPaymentId = paymentIdFromRow(row);
                        return <tr key={row.id || `${row.document_number}-${index}`} className="border-b border-[#E4E8E8] hover:bg-[#F8FAFC]"><HistoryCell>{rowDate(row)}</HistoryCell><HistoryCell strong>{entryLabel(row)}</HistoryCell><HistoryCell>{row.document_number || row.reference_no || row.receipt_number || "-"}</HistoryCell><HistoryCell>{row.description || row.notes || row.item_summary || "-"}</HistoryCell><HistoryCell right>{money(row.effective_debit_amount ?? row.debit_amount ?? row.debit, rowCurrency)}</HistoryCell><HistoryCell right good={amount(row.effective_credit_amount ?? row.credit_amount ?? row.credit) > 0}>{money(row.effective_credit_amount ?? row.credit_amount ?? row.credit, rowCurrency)}</HistoryCell><HistoryCell right strong>{money(row.balance_after ?? row.balance ?? row.running_balance, rowCurrency)}</HistoryCell><HistoryCell>{rowPaymentId ? <div className="flex gap-1"><button type="button" disabled={Boolean(paymentReceiptBusy)} onClick={() => deliverPaymentReceipt(selectedId, rowPaymentId, "open")} className="border border-[#069495] bg-[#E8F7F5] px-2 py-1 text-[8px] font-black uppercase text-[#005A5A] disabled:opacity-50">Print</button><button type="button" disabled={Boolean(paymentReceiptBusy)} onClick={() => deliverPaymentReceipt(selectedId, rowPaymentId, "download")} className="border border-[#ACB8B8] bg-white px-2 py-1 text-[8px] font-black uppercase text-[#324443] disabled:opacity-50">Download</button></div> : <span className="text-[#96A5A4]">-</span>}</HistoryCell></tr>;
                      })}</tbody>
                    </table>
                  )}
                </div>
              </section>
            </div>
          </main>
        </div>

        {pendingExcess && (
          <div className="absolute inset-0 z-[120] flex items-center justify-center bg-[#003637]/70 p-4">
            <div className="sx-large-modal-window border border-[#D9A400] bg-white shadow-2xl" role="dialog" aria-modal="true" aria-label="Confirm excess as account credit">
              <div className="flex items-center gap-2 border-b border-[#D9A400] bg-[#5E4300] px-3 py-3 text-white"><AlertTriangle className="h-5 w-5" /><div><div className="text-[13px] font-black uppercase">Confirm Excess as Account Credit</div><div className="text-[9px] uppercase text-white/70">This credit can be used against future bills</div></div></div>
              <div className="min-h-0 flex-1 space-y-2 overflow-y-auto p-4 text-[11px]"><Breakdown label="Payment value" value={money(pendingExcess.accountAmount, currency)} /><Breakdown label="Applied to current debt" value={money(pendingExcess.applied, currency)} /><Breakdown label="New prepaid account credit" value={money(pendingExcess.excess, currency)} good /><div className="border border-[#FEDF89] bg-[#FFFAEB] p-3 font-semibold text-[#B54708]">The excess becomes prepaid account credit.</div></div>
              <div className="flex justify-end gap-2 border-t border-[#CFD9D8] bg-[#F5F7FA] p-3"><button type="button" onClick={() => setPendingExcess(null)} disabled={saving} className="h-9 border border-[#ACB8B8] bg-white px-4 text-[10px] font-black uppercase">Cancel</button><button type="button" onClick={() => postPayment({ ...pendingExcess.payload, confirm_overpayment: true })} disabled={saving} className="flex h-9 items-center gap-2 border border-[#00883F] bg-[#00A651] px-5 text-[10px] font-black uppercase text-white disabled:opacity-50">{saving ? <RefreshCw className="h-4 w-4 animate-spin" /> : <CheckCircle2 className="h-4 w-4" />} Confirm & Credit Excess</button></div>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}

const Field = ({ label, children }) => <label className="block"><div className="mb-1 text-[9px] font-black uppercase text-[#627474]">{label}</div>{children}</label>;
const AccountMetric = ({ label, value, danger = false, good = false }) => <div className="border border-[#CFD9D8] bg-[#F8FAFC] p-2"><div className="text-[8px] font-black uppercase text-[#627474]">{label}</div><div className={`mt-1 text-[14px] font-black ${danger ? "text-[#B42318]" : good ? "text-[#067647]" : "text-[#004141]"}`}>{value}</div></div>;
const Breakdown = ({ label, value, good = false }) => <div className="flex items-center justify-between gap-3 border-b border-[#E4E8E8] py-1 last:border-b-0"><span className="text-[#627474]">{label}</span><strong className={good ? "text-[#067647]" : "text-[#004141]"}>{value}</strong></div>;
const HistoryHead = ({ children, right = false }) => <th className={`border-r border-white/10 px-2 py-2 font-black uppercase last:border-r-0 ${right ? "text-right" : "text-left"}`}>{children}</th>;
const HistoryCell = ({ children, right = false, strong = false, good = false }) => <td className={`border-r border-[#E4E8E8] px-2 py-2 align-top last:border-r-0 ${right ? "text-right" : "text-left"} ${strong ? "font-black" : "font-semibold"} ${good ? "text-[#067647]" : "text-[#324443]"}`}>{children}</td>;
