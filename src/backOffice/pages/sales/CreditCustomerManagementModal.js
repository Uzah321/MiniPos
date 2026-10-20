// src/backOffice/pages/sales/CreditCustomerManagementModal.jsx

import React, { useEffect, useMemo, useState } from "react";
import {
  Download,
  FileText,
  Plus,
  Printer,
  RefreshCw,
  Search,
  X,
} from "lucide-react";
import FullScreenModal from "../../../components/FullScreenModal";
import EnterpriseFormModal from "../../../components/EnterpriseFormModal";
import { getCurrencies, convertCurrency } from "../../../services/CurrencyService";
import { getPaymentTypes } from "../../../services/PaymentTypeService";
import {
  approveCreditCustomer,
  blockCreditCustomer,
  captureCreditCustomerPayment,
  downloadCreditCustomerPaymentReceipt,
  downloadCreditCustomerStatement,
  getCreditCustomerStatement,
  getCreditCustomers,
  getCreditCustomerReport,
  reverseCreditCustomerPayment,
  saveCreditCustomer,
} from "../../../services/CreditCustomerService";

const emptyForm = {
  account_number: "",
  name: "",
  customer_name: "",
  phone: "",
  email: "",
  address: "",
  tax_number: "",
  credit_limit: "",
  payment_terms_days: "30",
  opening_balance: "",
  currency_code: "",
  status: "pending",
  is_active: true,
  notes: "",
};

const emptyPayment = {
  amount: "",
  currency_code: "",
  payment_type_id: "",
  payment_method: "",
  reference_no: "",
  notes: "",
};

const newClientPaymentId = () => {
  if (typeof crypto !== "undefined" && crypto.randomUUID) return crypto.randomUUID();
  return `bo-account-${Date.now()}-${Math.random().toString(16).slice(2)}`;
};

const tabs = [
  ["setup", "Customer"],
  ["limits", "Credit Terms"],
  ["statement", "Sales & Ledger"],
  ["payment", "Record Payment"],
];

const getErrorMessage = (error, fallback) => {
  const data = error?.response?.data;

  if (data?.errors && typeof data.errors === "object") {
    return Object.values(data.errors).flat().join("\n");
  }

  return data?.message || data?.error || error?.message || fallback;
};

const normalizeRows = (response) => {
  const payload = response?.data ?? response;

  if (Array.isArray(payload)) return payload;
  if (Array.isArray(payload?.data)) return payload.data;
  if (Array.isArray(payload?.data?.data)) return payload.data.data;
  if (Array.isArray(payload?.rows)) return payload.rows;
  if (Array.isArray(payload?.items)) return payload.items;
  if (Array.isArray(payload?.customers)) return payload.customers;
  if (Array.isArray(payload?.payment_types)) return payload.payment_types;

  return [];
};

const getCurrencyCode = (currency) =>
  currency?.code || currency?.currency_code || currency?.currencyCode || "";

const getCurrencyName = (currency) =>
  currency?.name || currency?.currency_name || currency?.currencyName || getCurrencyCode(currency);

const getBaseCurrency = (currencies) => {
  return (
    currencies.find((currency) => boolValue(currency?.is_base_currency)) ||
    currencies.find((currency) => boolValue(currency?.is_active)) ||
    currencies[0] ||
    null
  );
};

const getCurrencyRows = (response) => {
  const rows = normalizeRows(response);

  return rows.filter((currency) => currency?.is_active !== false);
};

const getConvertedAmount = (response) => {
  return Number(
    response?.data?.converted_amount ??
      response?.converted_amount ??
      response?.amount ??
      response?.data?.amount ??
      0
  );
};

const numberOrNull = (value) => {
  if (value === "" || value === null || value === undefined) return null;
  return Number(value || 0);
};

const boolValue = (value) =>
  value === true ||
  value === 1 ||
  value === "1" ||
  String(value || "").toLowerCase() === "true";

const money = (value, currency = "USD") =>
  `${currency || "USD"} ${Number(value || 0).toFixed(2)}`;

const customerName = (row) =>
  row?.customer_name || row?.name || row?.company_name || row?.account_name || "-";

const balanceValue = (row) =>
  Number(
    row?.balance ??
      row?.current_balance ??
      row?.outstanding_balance ??
      row?.credit_balance ??
      row?.balance_amount ??
      0
  );

const creditLimitValue = (row) =>
  Number(row?.credit_limit ?? row?.limit_amount ?? row?.approved_limit ?? 0);

const availableCredit = (row) =>
  Number(
    row?.available_credit ??
      row?.credit_available ??
      creditLimitValue(row) - balanceValue(row)
  );

const rowStatus = (row) => String(row?.status || (row?.is_active === false ? "blocked" : "active")).toLowerCase();

const isApproved = (row) => ["approved", "active"].includes(rowStatus(row));
const isBlocked = (row) => ["blocked", "suspended", "inactive"].includes(rowStatus(row)) || row?.is_active === false;
const isSettlementOnly = (row) => ["blocked", "suspended"].includes(rowStatus(row)) && row?.is_active !== false;
const isPaymentUnavailable = (row) => row?.is_active === false || ["pending", "inactive"].includes(rowStatus(row));

const isActivePaymentType = (row) =>
  row?.is_active !== false && row?.is_active !== 0 && row?.is_active !== "0";

const paymentTypeName = (row) =>
  row?.name || row?.payment_name || row?.description || row?.money_code || "Payment";


const getStatementPayload = (response) => response?.data ?? response ?? {};

const isPaymentStatementRow = (row) => {
  const type = String(row?.transaction_type || row?.entry_type || row?.type || "").toLowerCase();
  return (
    type.includes("payment") ||
    type.includes("receipt") ||
    Boolean(row?.payment_id) ||
    Number(row?.credit_amount ?? row?.credit ?? 0) > 0
  );
};

const isPaymentReversalRow = (row = {}) => {
  const type = String(row?.transaction_type || row?.entry_type || row?.type || "").toLowerCase();
  const status = String(row?.status || row?.payment_status || row?.payment?.status || "").toLowerCase();

  return (
    type.includes("reversal") ||
    type.includes("reverse") ||
    status === "reversed" ||
    row?.metadata?.reversed === true ||
    row?.metadata?.source === "credit_customer_payment_reversal"
  );
};

const getPaymentIdFromStatementRow = (row = {}) =>
  row.payment_id ||
  row.credit_customer_payment_id ||
  row.payment?.id ||
  row.metadata?.payment_id ||
  row.metadata?.original_payment_id ||
  null;

const isSaleStatementRow = (row) => {
  const type = String(row?.transaction_type || row?.entry_type || row?.type || "").toLowerCase();
  return (
    type.includes("sale") ||
    Boolean(row?.sales_transaction_id) ||
    Boolean(row?.sale) ||
    Boolean(row?.sales_transaction) ||
    getStatementItems(row).length > 0
  );
};

const isCancelledStatementRow = (row = {}) => {
  const sale = row.sale || row.sales_transaction || {};
  const metadata = typeof sale.metadata === "string" ? (() => { try { return JSON.parse(sale.metadata || "{}"); } catch { return {}; } })() : sale.metadata || {};
  return (
    row.is_cancelled === true ||
    String(row.allocation_status || "").toLowerCase() === "cancelled" ||
    ["cancelled", "voided"].includes(String(row.display_status || "").toLowerCase()) ||
    ["cancelled", "voided"].includes(String(sale.status || "").toLowerCase()) ||
    metadata.cancelled === true ||
    metadata.credit_sale_cancelled === true ||
    ["sale_void", "sale_cancel", "void", "reversal"].includes(String(row.entry_type || row.transaction_type || "").toLowerCase())
  );
};

const effectiveDebitValue = (row = {}) => {
  if (row.effective_debit_amount !== undefined && row.effective_debit_amount !== null) return Number(row.effective_debit_amount || 0);
  return isCancelledStatementRow(row) ? 0 : Number(row.debit_amount ?? row.debit ?? 0);
};

const effectiveCreditValue = (row = {}) => {
  if (row.effective_credit_amount !== undefined && row.effective_credit_amount !== null) return Number(row.effective_credit_amount || 0);
  return isCancelledStatementRow(row) ? 0 : Number(row.credit_amount ?? row.credit ?? 0);
};



export default function CreditCustomerManagementModal({ open, onClose }) {
  const [rows, setRows] = useState([]);
  const [currencies, setCurrencies] = useState([]);
  const [paymentTypes, setPaymentTypes] = useState([]);
  const [report, setReport] = useState(null);
  const [loading, setLoading] = useState(false);
  const [notice, setNotice] = useState("");
  const [query, setQuery] = useState("");
  const [statusFilter, setStatusFilter] = useState("all");
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState(null);
  const [pendingBlock, setPendingBlock] = useState(null);
  const [blockReason, setBlockReason] = useState("Credit account blocked");
  const [blockValidation, setBlockValidation] = useState("");

  const filteredRows = useMemo(() => {
    const search = query.trim().toLowerCase();

    return rows.filter((row) => {
      const status = rowStatus(row);
      const matchesSearch =
        !search ||
        String(row.account_number || "").toLowerCase().includes(search) ||
        String(customerName(row)).toLowerCase().includes(search) ||
        String(row.phone || "").toLowerCase().includes(search) ||
        String(row.email || "").toLowerCase().includes(search) ||
        String(row.tax_number || "").toLowerCase().includes(search);

      const matchesStatus =
        statusFilter === "all" ||
        (statusFilter === "active" && isApproved(row) && !isBlocked(row)) ||
        (statusFilter === "pending" && status === "pending") ||
        (statusFilter === "blocked" && isBlocked(row)) ||
        (statusFilter === "over_limit" && availableCredit(row) < 0);

      return matchesSearch && matchesStatus;
    });
  }, [rows, query, statusFilter]);

  const stats = useMemo(() => {
    const active = rows.filter((row) => isApproved(row) && !isBlocked(row)).length;
    const pending = rows.filter((row) => rowStatus(row) === "pending").length;
    const blocked = rows.filter(isBlocked).length;
    const overLimit = rows.filter((row) => availableCredit(row) < 0).length;

    return {
      total: rows.length,
      active,
      pending,
      blocked,
      overLimit,
    };
  }, [rows]);

  const currencyStats = useMemo(() => {
    if (Array.isArray(report?.summary_by_currency) && report.summary_by_currency.length) {
      return report.summary_by_currency.map((item) => ({
        currency: item.currency_code || "N/A",
        customers: Number(item.customer_count || 0),
        due: Number(item.amount_due || 0),
        credit: Number(item.customer_credit || 0),
        available: Number(item.available_credit || 0),
      }));
    }
    const map = new Map();
    rows.forEach((row) => {
      const currency = String(row.currency_code || row.default_currency_code || "N/A").toUpperCase();
      const item = map.get(currency) || { currency, customers: 0, due: 0, credit: 0, available: 0 };
      const balance = balanceValue(row);
      item.customers += 1;
      item.due += Math.max(0, balance);
      item.credit += Math.max(0, -balance);
      item.available += availableCredit(row);
      map.set(currency, item);
    });
    return Array.from(map.values()).sort((a, b) => a.currency.localeCompare(b.currency));
  }, [rows, report]);

  const load = async () => {
    try {
      setLoading(true);
      setNotice("");

      const [customersRes, reportRes, currenciesRes, paymentTypesRes] = await Promise.all([
        getCreditCustomers({ per_page: 250 }),
        getCreditCustomerReport().catch(() => null),
        getCurrencies({ per_page: 100, is_active: true }).catch(() => []),
        getPaymentTypes({ per_page: 100, is_active: true }).catch(() => []),
      ]);

      setRows(normalizeRows(customersRes));
      setReport(reportRes?.data || reportRes || null);
      setCurrencies(getCurrencyRows(currenciesRes));
      setPaymentTypes(normalizeRows(paymentTypesRes).filter(isActivePaymentType));
    } catch (error) {
      setNotice(getErrorMessage(error, "Load failed."));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (open) load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  const openCreate = () => {
    setEditing(null);
    setFormOpen(true);
  };

  const openEdit = (row) => {
    setEditing(row);
    setFormOpen(true);
  };

  const approve = async (row) => {
    if (!row?.id) return;

    try {
      setLoading(true);
      setNotice("");
      const response = await approveCreditCustomer(row.id);
      setNotice(response?.message || "Customer approved.");
      await load();
    } catch (error) {
      setNotice(getErrorMessage(error, "Approval failed."));
    } finally {
      setLoading(false);
    }
  };

  const block = async (row) => {
    if (!row?.id) return;

    setPendingBlock(row);
    setBlockReason("Credit account blocked");
    setBlockValidation("");
  };

  const confirmBlock = async (event) => {
    event?.preventDefault?.();
    if (!pendingBlock?.id || loading) return;

    const reason = String(blockReason || "").trim();
    if (!reason) {
      setBlockValidation("Reason is required.");
      return;
    }

    try {
      setLoading(true);
      setNotice("");
      setBlockValidation("");
      const response = await blockCreditCustomer(pendingBlock.id, { reason });
      setNotice(response?.message || "Customer blocked.");
      setPendingBlock(null);
      await load();
    } catch (error) {
      setBlockValidation(getErrorMessage(error, "Block failed."));
    } finally {
      setLoading(false);
    }
  };

  const onSaved = async (message) => {
    setFormOpen(false);
    setEditing(null);
    setNotice(message || "Customer saved.");
    await load();
  };

  if (!open) return null;

  return (
    <FullScreenModal
      open={open}
      onClose={onClose}
      title="CREDIT ACCOUNTS"
      subtitle="Credit sales create debt; account payments reduce that debt"
    >
      <div className="h-[calc(100vh-86px)] min-h-0 overflow-hidden bg-[#EEF1F1] p-2 text-[#1C2C2B]">
        <div className="flex h-full min-h-0 flex-col border border-[#AAB5B5] bg-white">
          <div className="shrink-0 border-b border-[#AAB5B5] bg-[#F5F7FA]">
            <div className="flex h-10 items-center justify-between border-b border-[#CFD9D8] px-2">
              <div>
                <div className="text-[13px] font-semibold text-[#0C1B1B]">
                  Credit accounts
                </div>
                <div className="text-[10px] font-medium uppercase text-[#627474]">
                  Customer limits, credit sales, balances and money received
                </div>
              </div>

              <div className="flex items-center gap-1">
                <SmallAction onClick={load} disabled={loading} icon={RefreshCw} label={loading ? "Loading" : "Refresh"} />
                <SmallAction onClick={openCreate} icon={Plus} label="Add Customer" primary />
              </div>
            </div>

            {notice && (
              <div className="whitespace-pre-line border-b border-[#FEDF89] bg-[#FFFAEB] px-3 py-2 text-[11px] font-semibold text-[#B54708]">
                {notice}
              </div>
            )}

            <div className="grid grid-cols-[minmax(260px,1fr)_170px] gap-2 p-2">
              <label>
                <div className="mb-1 text-[10px] font-semibold uppercase text-[#627474]">
                  Search
                </div>
                <div className="relative">
                  <Search className="absolute left-2 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-[#627474]" />
                  <input
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                    className="h-8 w-full border border-[#AAB5B5] bg-white pl-8 pr-2 text-[11px] outline-none focus:border-[#069495]"
                    placeholder="Account, name, phone..."
                  />
                </div>
              </label>

              <label>
                <div className="mb-1 text-[10px] font-semibold uppercase text-[#627474]">
                  Status
                </div>
                <select
                  value={statusFilter}
                  onChange={(event) => setStatusFilter(event.target.value)}
                  className="h-8 w-full border border-[#AAB5B5] bg-white px-2 text-[11px] outline-none focus:border-[#069495]"
                >
                  <option value="all">All</option>
                  <option value="active">Active</option>
                  <option value="pending">Pending</option>
                  <option value="blocked">Blocked</option>
                  <option value="over_limit">Over Limit</option>
                </select>
              </label>
            </div>

            <div className="grid grid-cols-6 gap-2 border-t border-[#CFD9D8] p-2">
              <Metric label="Total" value={stats.total} />
              <Metric label="Active" value={stats.active} good />
              <Metric label="Pending" value={stats.pending} warning />
              <Metric label="Blocked" value={stats.blocked} danger />
              <Metric label="Currencies" value={currencyStats.length} />
              <Metric label="Over Limit" value={stats.overLimit} danger={stats.overLimit > 0} />
            </div>
            {currencyStats.length > 0 && (
              <div className="flex gap-2 overflow-x-auto border-t border-[#CFD9D8] bg-white p-2">
                {currencyStats.map((item) => (
                  <div key={item.currency} className="min-w-[250px] border border-[#CFD9D8] bg-[#F8FAFC] px-3 py-2">
                    <div className="text-[10px] font-semibold uppercase text-[#004141]">{item.currency} Accounts · {item.customers}</div>
                    <div className="mt-1 flex justify-between gap-3 text-[10px]"><span className="text-[#627474]">Due</span><strong className="text-[#B54708]">{money(item.due, item.currency)}</strong><span className="text-[#627474]">Customer Credit</span><strong className="text-[#067647]">{money(item.credit, item.currency)}</strong></div>
                  </div>
                ))}
              </div>
            )}
          </div>

          <div className="min-h-0 flex-1 overflow-auto">
            <table className="w-full min-w-[1380px] border-collapse text-left text-[11px]">
              <thead className="sticky top-0 z-10 bg-[#0E4D4D] text-white">
                <tr>
                  <GridTh>#</GridTh>
                  <GridTh>Account</GridTh>
                  <GridTh>Customer</GridTh>
                  <GridTh>Phone</GridTh>
                  <GridTh>Email</GridTh>
                  <GridTh align="right">Limit</GridTh>
                  <GridTh align="right">Balance</GridTh>
                  <GridTh align="right">Available</GridTh>
                  <GridTh>Terms</GridTh>
                  <GridTh>Status</GridTh>
                  <GridTh align="right">Actions</GridTh>
                </tr>
              </thead>

              <tbody>
                {filteredRows.length === 0 ? (
                  <tr>
                    <td colSpan={11} className="px-3 py-12 text-center text-[12px] font-semibold text-[#627474]">
                      {loading ? "Loading..." : "No customers found."}
                    </td>
                  </tr>
                ) : (
                  filteredRows.map((row, index) => {
                    const status = rowStatus(row);
                    const currency = row.currency_code || row.default_currency_code || "USD";
                    const available = availableCredit(row);

                    return (
                      <tr key={row.id || `${row.account_number}-${index}`} className="border-b border-[#CFD9D8] bg-white hover:bg-[#F8FAFC]">
                        <GridTd>{index + 1}</GridTd>
                        <GridTd strong>{row.account_number || row.credit_account_number || "-"}</GridTd>
                        <GridTd strong>{customerName(row)}</GridTd>
                        <GridTd>{row.phone || row.mobile || "-"}</GridTd>
                        <GridTd>{row.email || "-"}</GridTd>
                        <GridTd right strong>{money(creditLimitValue(row), currency)}</GridTd>
                        <GridTd right strong>{money(balanceValue(row), currency)}</GridTd>
                        <GridTd right strong>
                          <span className={available < 0 ? "text-[#B42318]" : "text-[#067647]"}>
                            {money(available, currency)}
                          </span>
                        </GridTd>
                        <GridTd>{row.payment_terms_days || row.terms_days || 0} days</GridTd>
                        <GridTd>
                          {isBlocked(row) ? (
                            <Pill tone="danger">Blocked</Pill>
                          ) : status === "pending" ? (
                            <Pill tone="warning">Pending</Pill>
                          ) : (
                            <Pill tone="good">Active</Pill>
                          )}
                        </GridTd>
                        <GridTd right>
                          <RowActionMenu
                            label={`Actions for ${customerName(row)}`}
                            actions={[
                              { label: "Edit", onClick: () => openEdit(row) },
                              { label: "Statement", onClick: () => openEdit({ ...row, __openTab: "statement" }) },
                              {
                                label: "Record Payment",
                                onClick: () => openEdit({ ...row, __openTab: "payment" }),
                                disabled: isPaymentUnavailable(row),
                                title: isPaymentUnavailable(row) ? "Payments are unavailable for pending or inactive accounts" : "",
                                good: true,
                              },
                              !isApproved(row) ? { label: "Approve", onClick: () => approve(row), good: true } : null,
                              !isBlocked(row) ? { label: "Block", onClick: () => block(row), danger: true } : null,
                            ]}
                          />
                        </GridTd>
                      </tr>
                    );
                  })
                )}
              </tbody>
            </table>
          </div>
        </div>

        <CreditCustomerFormModal
          open={formOpen}
          editing={editing}
          currencies={currencies}
          paymentTypes={paymentTypes}
          onClose={() => {
            setFormOpen(false);
            setEditing(null);
          }}
          onSaved={onSaved}
        />

        <EnterpriseFormModal
          open={Boolean(pendingBlock)}
          title="Block Credit Account"
          subtitle={pendingBlock ? customerName(pendingBlock) : ""}
          onClose={() => {
            if (loading) return;
            setPendingBlock(null);
            setBlockValidation("");
          }}
          busy={loading}
        >
          <form onSubmit={confirmBlock} className="flex h-full min-h-0 flex-col">
            <div className="min-h-0 flex-1 p-1">
              <label className="block">
                <span className="mb-1 block text-[11px] font-semibold uppercase text-[#637877]">Reason</span>
                <textarea
                  autoFocus
                  required
                  rows={6}
                  value={blockReason}
                  onChange={(event) => {
                    setBlockReason(event.target.value);
                    if (blockValidation) setBlockValidation("");
                  }}
                  className="w-full border border-[#ACB8B8] bg-white px-3 py-2 text-[13px] outline-none focus:border-[#069494]"
                />
              </label>
              {blockValidation && (
                <div role="alert" className="mt-3 whitespace-pre-line border border-[#FDA29B] bg-[#FEF3F2] px-3 py-2 text-[12px] font-semibold text-[#B42318]">
                  {blockValidation}
                </div>
              )}
            </div>
            <div className="flex justify-end gap-2 border-t border-[#CFD9D8] bg-[#F7FAFC] p-3">
              <button type="button" disabled={loading} onClick={() => setPendingBlock(null)} className="h-9 border border-[#ACB8B8] bg-white px-4 text-[11px] font-semibold text-[#324443] disabled:opacity-50">Cancel</button>
              <button type="submit" disabled={loading || !blockReason.trim()} className="h-9 bg-[#B42318] px-5 text-[11px] font-semibold text-white disabled:opacity-50">{loading ? "Blocking..." : "Block Account"}</button>
            </div>
          </form>
        </EnterpriseFormModal>
      </div>
    </FullScreenModal>
  );
}

function CreditCustomerFormModal({ open, editing, currencies = [], paymentTypes = [], onClose, onSaved }) {
  const [form, setForm] = useState(emptyForm);
  const [payment, setPayment] = useState(emptyPayment);
  const [activeTab, setActiveTab] = useState("setup");
  const [saving, setSaving] = useState(false);
  const [notice, setNotice] = useState("");
  const [statementRows, setStatementRows] = useState([]);
  const [statementData, setStatementData] = useState(null);
  const [statementLoading, setStatementLoading] = useState(false);
  const [paymentConverted, setPaymentConverted] = useState(null);
  const [convertingPayment, setConvertingPayment] = useState(false);
  const [statementReportFormat, setStatementReportFormat] = useState("a4");
  const [reversalModalOpen, setReversalModalOpen] = useState(false);
  const [reversalRow, setReversalRow] = useState(null);
  const [reversalReason, setReversalReason] = useState("Wrong amount");
  const [pendingOverpayment, setPendingOverpayment] = useState(null);
  const [lastPaymentConfirmation, setLastPaymentConfirmation] = useState(null);
  const [paymentReceiptBusy, setPaymentReceiptBusy] = useState("");
  const [clientPaymentId, setClientPaymentId] = useState(newClientPaymentId);

  useEffect(() => {
    if (!open) return;

    const baseCurrency = getCurrencyCode(getBaseCurrency(currencies)) || "USD";

    if (editing) {
      const rowCurrency =
        editing.currency_code ||
        editing.default_currency_code ||
        editing.currency ||
        baseCurrency;

      setForm({
        ...emptyForm,
        ...editing,
        account_number: editing.account_number || editing.credit_account_number || "",
        name: editing.name || editing.customer_name || "",
        customer_name: editing.customer_name || editing.name || "",
        credit_limit: editing.credit_limit ?? editing.limit_amount ?? "",
        payment_terms_days: editing.payment_terms_days ?? editing.terms_days ?? "30",
        opening_balance: editing.opening_balance ?? "",
        currency_code: rowCurrency,
        is_active: editing.is_active !== false,
        status: editing.status || "active",
      });
      setPayment({
        ...emptyPayment,
        currency_code: rowCurrency,
        payment_type_id: paymentTypes[0]?.id || "",
        payment_method: paymentTypeName(paymentTypes[0] || {}),
      });
      setActiveTab(editing.__openTab || "setup");
    } else {
      setForm({
        ...emptyForm,
        currency_code: baseCurrency,
      });
      setPayment({
        ...emptyPayment,
        currency_code: baseCurrency,
        payment_type_id: paymentTypes[0]?.id || "",
        payment_method: paymentTypeName(paymentTypes[0] || {}),
      });
      setActiveTab("setup");
    }

    setPaymentConverted(null);
    setNotice("");
    setStatementRows([]);
    setStatementData(null);
    setReversalModalOpen(false);
    setReversalRow(null);
    setReversalReason("Wrong amount");
    setPendingOverpayment(null);
    setLastPaymentConfirmation(null);
    setPaymentReceiptBusy("");
    setClientPaymentId(newClientPaymentId());
  }, [open, editing, currencies, paymentTypes]);

  useEffect(() => {
    if (!open) return;
    if (!editing?.id) return;
    if (activeTab === "statement" || activeTab === "payment") {
      loadStatement();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, activeTab, editing?.id]);


  useEffect(() => {
    if (!open) return;

    const from = String(payment.currency_code || "").toUpperCase();
    const to = String(form.currency_code || "").toUpperCase();
    const amount = Number(payment.amount || 0);

    if (!from || !to || !amount || from === to) {
      setPaymentConverted(null);
      return;
    }

    let cancelled = false;

    const runConversion = async () => {
      try {
        setConvertingPayment(true);
        const response = await convertCurrency({ from, to, amount });
        if (!cancelled) {
          setPaymentConverted({
            from,
            to,
            amount,
            converted_amount: getConvertedAmount(response),
          });
        }
      } catch {
        if (!cancelled) setPaymentConverted(null);
      } finally {
        if (!cancelled) setConvertingPayment(false);
      }
    };

    runConversion();

    return () => {
      cancelled = true;
    };
  }, [open, payment.amount, payment.currency_code, form.currency_code]);

  if (!open) return null;

  const set = (key, value) => setForm((prev) => ({ ...prev, [key]: value }));
  const setPay = (key, value) => setPayment((prev) => ({ ...prev, [key]: value }));

  const buildPayload = () => ({
    ...form,
    name: form.name || form.customer_name,
    customer_name: form.customer_name || form.name,
    account_number: form.account_number || null,
    credit_limit: numberOrNull(form.credit_limit),
    payment_terms_days: numberOrNull(form.payment_terms_days) || 0,
    opening_balance: numberOrNull(form.opening_balance) || 0,
    currency_code: String(form.currency_code || getCurrencyCode(getBaseCurrency(currencies)) || "USD").toUpperCase(),
    is_active: Boolean(form.is_active),
  });

  const submit = async (event) => {
    event.preventDefault();

    if (!(form.name || form.customer_name || "").trim()) {
      setNotice("Name required.");
      setActiveTab("setup");
      return;
    }

    if (!Number(form.credit_limit || 0) || Number(form.credit_limit || 0) < 0) {
      setNotice("Valid limit required.");
      setActiveTab("limits");
      return;
    }

    if (!String(form.currency_code || "").trim()) {
      setNotice("Currency required.");
      setActiveTab("limits");
      return;
    }

    try {
      setSaving(true);
      setNotice("");
      const response = await saveCreditCustomer(buildPayload(), editing?.id || null);
      onSaved?.(response?.message || (editing ? "Customer updated." : "Customer created."));
    } catch (error) {
      setNotice(getErrorMessage(error, "Save failed."));
    } finally {
      setSaving(false);
    }
  };

  const loadStatement = async () => {
    if (!editing?.id) return null;

    try {
      setStatementLoading(true);
      const response = await getCreditCustomerStatement(editing.id);
      const payload = getStatementPayload(response);
      const rows = normalizeRows(response);
      setStatementData(payload);
      setStatementRows(rows);
      return { payload, rows };
    } catch (error) {
      setNotice(getErrorMessage(error, "Statement failed."));
      return null;
    } finally {
      setStatementLoading(false);
    }
  };

  const exportStatementPdf = async (action = "download") => {
    if (!editing?.id) {
      setNotice("Save or select customer first.");
      return;
    }

    try {
      setStatementLoading(true);
      setNotice("");
      await downloadCreditCustomerStatement(
        editing.id,
        { paper: statementReportFormat },
        action === "print" ? "open" : "download"
      );
    } catch (error) {
      setNotice(getErrorMessage(error, "Statement download failed."));
    } finally {
      setStatementLoading(false);
    }
  };

  const deliverPaymentReceipt = async (paymentId, delivery = "open") => {
    if (!editing?.id || !paymentId) {
      setNotice("The payment confirmation is not available for this entry.");
      return;
    }

    const busyKey = `${paymentId}:${delivery}`;
    try {
      setPaymentReceiptBusy(busyKey);
      setNotice("");
      await downloadCreditCustomerPaymentReceipt(
        editing.id,
        paymentId,
        { paper: "80mm" },
        delivery
      );
    } catch (error) {
      setNotice(getErrorMessage(error, "Payment confirmation could not be opened."));
    } finally {
      setPaymentReceiptBusy("");
    }
  };

  const postPayment = async (payload) => {
    try {
      setSaving(true);
      setNotice("");
      const response = await captureCreditCustomerPayment(editing.id, payload);
      const paymentData = response?.data || response || {};
      const paymentId = response?.payment_receipt?.payment_id || paymentData?.id || paymentData?.payment_id || null;
      setLastPaymentConfirmation(paymentId ? {
        paymentId,
        paymentNumber: paymentData?.payment_number || paymentData?.reference || "Payment",
        amount: Number(paymentData?.amount ?? payload.amount ?? 0),
        currency: paymentData?.currency_code || payload.currency_code,
        replay: Boolean(response?.idempotent_replay),
      } : null);
      setPayment({
        ...emptyPayment,
        currency_code: form.currency_code || "USD",
        payment_type_id: paymentTypes[0]?.id || "",
        payment_method: paymentTypeName(paymentTypes[0] || {}),
      });
      setPaymentConverted(null);
      setPendingOverpayment(null);
      setClientPaymentId(newClientPaymentId());
      setNotice(response?.idempotent_replay
        ? "This retry matched an already-posted payment. The original payment and docket were recovered; no duplicate was created."
        : response?.message || "Payment captured.");
      await loadStatement();
    } catch (error) {
      const data = error?.response?.data || {};
      if (
        payload?.confirm_overpayment !== true &&
        isApproved(liveAccount) &&
        (data?.requires_overpayment_confirmation === true || data?.code === "ACCOUNT_CREDIT_CONFIRMATION_REQUIRED")
      ) {
        setPendingOverpayment({
          payload,
          accountAmount: convertedDeduction,
          applied: Math.min(currentAmountDue, convertedDeduction),
          excess: Math.max(0, convertedDeduction - currentAmountDue),
        });
        setNotice("Confirm that the customer agreed to keep the excess as prepaid account credit.");
      } else {
        setNotice(getErrorMessage(error, "Payment failed."));
      }
    } finally {
      setSaving(false);
    }
  };

  const submitPayment = () => {
    if (!editing?.id) {
      setNotice("Save customer first.");
      return;
    }

    if (isPaymentUnavailable(liveAccount)) {
      setNotice("Payments are unavailable for pending or inactive customer accounts.");
      return;
    }

    if (!Number(payment.amount || 0) || Number(payment.amount || 0) <= 0) {
      setNotice("Enter valid amount.");
      return;
    }

    if (!String(payment.currency_code || "").trim()) {
      setNotice("Payment currency required.");
      return;
    }

    if (!String(payment.payment_type_id || "").trim()) {
      setNotice("Select method.");
      return;
    }

    const selectedPaymentType = paymentTypes.find(
      (type) => String(type.id) === String(payment.payment_type_id)
    );

    if (paymentCurrency !== accountCurrency && (convertingPayment || convertedDeduction <= 0)) {
      setNotice("Wait for a valid account-currency conversion before recording the payment.");
      return;
    }

    const payload = {
      amount: Number(payment.amount || 0),
      currency_code: String(payment.currency_code || form.currency_code || "USD").toUpperCase(),
      payment_type_id: payment.payment_type_id,
      payment_method: paymentTypeName(selectedPaymentType || {}) || payment.payment_method || null,
      money_code: selectedPaymentType?.money_code || null,
      client_payment_id: clientPaymentId,
      confirm_overpayment: false,
      reference: payment.reference_no || null,
      reference_no: payment.reference_no || null,
      notes: payment.notes || null,
    };

    const excess = Math.max(0, convertedDeduction - currentAmountDue);
    if (isSettlementOnly(liveAccount) && excess > 0.0001) {
      setNotice(`This ${rowStatus(liveAccount)} account is settlement-only. Reduce the account value to ${money(currentAmountDue, accountCurrency)} or less.`);
      return;
    }

    if (excess > 0.0001) {
      setPendingOverpayment({
        payload,
        accountAmount: convertedDeduction,
        applied: Math.min(currentAmountDue, convertedDeduction),
        excess,
      });
      return;
    }

    return postPayment(payload);
  };

  const openPaymentReversalModal = (row) => {
    if (!editing?.id) {
      setNotice("Select customer first.");
      return;
    }

    const paymentId = getPaymentIdFromStatementRow(row);

    if (!paymentId) {
      setNotice("Payment ID not found.");
      return;
    }

    if (isPaymentReversalRow(row)) {
      setNotice("Already reversed.");
      return;
    }

    setReversalRow(row);
    setReversalReason("Wrong amount");
    setReversalModalOpen(true);
  };

  const closePaymentReversalModal = () => {
    if (saving) return;
    setReversalModalOpen(false);
    setReversalRow(null);
    setReversalReason("Wrong amount");
  };

  const confirmPaymentReversal = async () => {
    if (!editing?.id) {
      setNotice("Select customer first.");
      return;
    }

    const paymentId = getPaymentIdFromStatementRow(reversalRow);

    if (!paymentId) {
      setNotice("Payment ID not found.");
      return;
    }

    if (!String(reversalReason || "").trim()) {
      setNotice("Reason required.");
      return;
    }

    try {
      setSaving(true);
      setNotice("");
      const response = await reverseCreditCustomerPayment(editing.id, paymentId, {
        reason: String(reversalReason).trim(),
        disposition: "accounting_only",
      });
      setNotice(response?.message || "Payment reversed.");
      setReversalModalOpen(false);
      setReversalRow(null);
      setReversalReason("Wrong amount");
      await loadStatement();
    } catch (error) {
      setNotice(getErrorMessage(error, "Reversal failed."));
    } finally {
      setSaving(false);
    }
  };


  const accountCurrency = String(form.currency_code || getCurrencyCode(getBaseCurrency(currencies)) || "USD").toUpperCase();
  const liveAccount = statementData?.customer || editing || form;
  const balance = Number(statementData?.closing_balance ?? liveAccount?.current_balance ?? balanceValue(liveAccount));
  const limit = creditLimitValue(liveAccount);
  const available = Number(statementData?.available_credit ?? (limit - balance));
  const paymentAmount = Number(payment.amount || 0);
  const paymentCurrency = String(payment.currency_code || accountCurrency || "USD").toUpperCase();
  const convertedDeduction = paymentCurrency === accountCurrency
    ? paymentAmount
    : Number(paymentConverted?.converted_amount || 0);
  const projectedBalance = balance - convertedDeduction;
  const projectedAmountDue = Math.max(0, projectedBalance);
  const projectedCreditBalance = Math.max(0, -projectedBalance);
  const projectedAvailable = limit - projectedBalance;
  const currentAmountDue = Math.max(0, balance);
  const currentCreditBalance = Math.max(0, -balance);
  const paymentUnavailable = isPaymentUnavailable(liveAccount);
  const settlementOnly = isSettlementOnly(liveAccount);

  return (
    <div className="fixed inset-0 z-[240] bg-black/55 p-3">
      <form onSubmit={submit} className="flex h-full min-h-0 flex-col border border-[#AAB5B5] bg-white text-[#1C2C2B] shadow-2xl">
        <div className="flex h-11 shrink-0 items-center justify-between border-b border-[#00BCBC] bg-[#004142] px-3 text-white">
          <div>
            <div className="text-[13px] font-semibold uppercase">
              {editing ? "Edit Customer" : "Add Customer"}
            </div>
            <div className="text-[10px] font-medium uppercase text-white/65">
              Credit sales and payments are kept as separate account entries
            </div>
          </div>

          <button type="button" onClick={onClose} className="flex h-8 w-8 items-center justify-center border border-white/20 bg-white/10 hover:bg-white/20">
            <X className="h-4 w-4" />
          </button>
        </div>

        {notice && (
          <div className="shrink-0 whitespace-pre-line border-b border-[#FEDF89] bg-[#FFFAEB] px-3 py-2 text-[11px] font-semibold text-[#B54708]">
            {notice}
          </div>
        )}

        <div className="grid min-h-0 flex-1 grid-cols-[220px_1fr]">
          <aside className="border-r border-[#CFD9D8] bg-[#F5F7FA] p-2">
            <div className="space-y-1">
              {tabs.map(([key, label]) => (
                <button
                  key={key}
                  type="button"
                  onClick={() => setActiveTab(key)}
                  className={`h-9 w-full border px-3 text-left text-[11px] font-semibold uppercase ${
                    activeTab === key
                      ? "border-[#069495] bg-[#E8F7F5] text-[#005A5A]"
                      : "border-[#CFD9D8] bg-white text-[#324443] hover:border-[#069495]/60"
                  }`}
                >
                  {label}
                </button>
              ))}
            </div>

            <div className="mt-3 border border-[#CFD9D8] bg-white p-2 text-[10px] text-[#627474]">
              <div className="font-semibold uppercase text-[#324443]">Account Summary</div>
              <div className="mt-2 grid gap-1">
                <SummaryLine label="Currency" value={accountCurrency} />
                <SummaryLine label="Limit" value={money(limit, accountCurrency)} />
                <SummaryLine label="Due" value={money(currentAmountDue, accountCurrency)} />
                <SummaryLine label="Credit" value={money(currentCreditBalance, accountCurrency)} />
                <SummaryLine label="Available" value={money(available, accountCurrency)} />
                <SummaryLine label="Terms" value={`${form.payment_terms_days || 0} days`} />
              </div>
            </div>
          </aside>

          <main className="min-h-0 overflow-y-auto bg-[#FBFCFE] p-3">
            {activeTab === "setup" && (
              <Panel title="Customer Setup">
                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                  <Input label="Account" value={form.account_number} onChange={(value) => set("account_number", value.toUpperCase())} />
                  <CurrencySelect
                    label="Currency"
                    value={form.currency_code}
                    currencies={currencies}
                    onChange={(value) => {
                      const next = value.toUpperCase();
                      set("currency_code", next);
                      setPayment((prev) => ({ ...prev, currency_code: prev.currency_code || next }));
                    }}
                  />
                  <Input label="Customer" value={form.name || form.customer_name} onChange={(value) => { set("name", value); set("customer_name", value); }} className="md:col-span-2" />
                  <Select label="Status" value={form.status} onChange={(value) => set("status", value)}>
                    <option value="pending">Pending</option>
                    <option value="active">Active</option>
                    <option value="approved">Approved</option>
                    <option value="blocked">Blocked</option>
                    <option value="suspended">Suspended</option>
                  </Select>
                  <Input label="Phone" value={form.phone} onChange={(value) => set("phone", value)} />
                  <Input label="Email" value={form.email} onChange={(value) => set("email", value)} type="email" />
                  <Input label="Tax No." value={form.tax_number} onChange={(value) => set("tax_number", value)} />
                  <Check label="Active" checked={form.is_active} onChange={(value) => set("is_active", value)} />
                  <label className="block md:col-span-2 xl:col-span-4">
                    <div className="mb-1 text-[10px] font-semibold uppercase text-[#627474]">Address</div>
                    <textarea value={form.address || ""} onChange={(event) => set("address", event.target.value)} rows={2} className="w-full border border-[#AAB5B5] bg-white px-2 py-2 text-[11px] outline-none focus:border-[#069495]" />
                  </label>
                  <label className="block md:col-span-2 xl:col-span-4">
                    <div className="mb-1 text-[10px] font-semibold uppercase text-[#627474]">Notes</div>
                    <textarea value={form.notes || ""} onChange={(event) => set("notes", event.target.value)} rows={3} className="w-full border border-[#AAB5B5] bg-white px-2 py-2 text-[11px] outline-none focus:border-[#069495]" />
                  </label>
                </div>
              </Panel>
            )}

            {activeTab === "limits" && (
              <Panel title="Limits">
                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                  <Input label="Credit Limit" value={form.credit_limit} onChange={(value) => set("credit_limit", value)} type="number" />
                  <Input label="Terms Days" value={form.payment_terms_days} onChange={(value) => set("payment_terms_days", value)} type="number" />
                  <Input label="Opening" value={form.opening_balance} onChange={(value) => set("opening_balance", value)} type="number" />
                  <Metric label="Currency" value={accountCurrency} />
                  <Metric label="Available" value={money(available, accountCurrency)} good={available >= 0} danger={available < 0} />
                </div>
              </Panel>
            )}

            {activeTab === "statement" && (
              <Panel title="Statement">
                <div className="mb-2 flex items-center justify-between">
                  <div className="text-[10px] font-semibold uppercase text-[#627474]">
                    Ledger, invoices and payments
                  </div>
                  <div className="flex flex-wrap items-center gap-1">
                    <select
                      value={statementReportFormat}
                      onChange={(event) => setStatementReportFormat(event.target.value)}
                      className="h-8 border border-[#AAB5B5] bg-white px-2 text-[10px] font-semibold uppercase text-[#324443] outline-none"
                    >
                      <option value="a4">A4</option>
                      <option value="80mm">80mm</option>
                    </select>
                    <SmallAction type="button" onClick={loadStatement} disabled={!editing?.id || statementLoading} icon={RefreshCw} label={statementLoading ? "Loading" : "Refresh"} />
                    <SmallAction type="button" onClick={() => exportStatementPdf("download")} disabled={!editing?.id || statementLoading} icon={Download} label="PDF" primary />
                    <SmallAction type="button" onClick={() => exportStatementPdf("print")} disabled={!editing?.id || statementLoading} icon={FileText} label="Print" />
                  </div>
                </div>
                <StatementTable
                  rows={statementRows}
                  loading={statementLoading}
                  customerId={editing?.id}
                  onPaymentReceipt={deliverPaymentReceipt}
                  paymentReceiptBusy={paymentReceiptBusy}
                />
              </Panel>
            )}

            {activeTab === "payment" && (
              <Panel title="Record Account Payment">
                {!editing?.id ? (
                  <div className="border border-[#FEDF89] bg-[#FFFAEB] p-3 text-[11px] font-semibold text-[#B54708]">
                    Save customer first.
                  </div>
                ) : (
                  <div className="space-y-3">
                    <div className="border-l-4 border-[#00BCBC] bg-[#F0FDFA] px-3 py-2 text-[11px] text-[#324443]">
                      Record money actually received from this customer. Back Office collections update the account ledger but are till-free and do not join a cashier cashup; POS collections captured at a registered till do join that shift's cashup.
                    </div>
                    {paymentUnavailable && (
                      <div className="border border-[#FDA29B] bg-[#FEF3F2] p-3 text-[11px] font-semibold text-[#B42318]">Payments are unavailable while this customer account is pending or inactive.</div>
                    )}
                    {settlementOnly && (
                      <div className="border border-[#FEDF89] bg-[#FFFAEB] p-3 text-[11px] font-semibold text-[#B54708]">This {rowStatus(liveAccount)} account is settlement-only. A payment may clear debt up to {money(currentAmountDue, accountCurrency)}, but cannot create prepaid credit.</div>
                    )}
                    {lastPaymentConfirmation?.paymentId && (
                      <div className="flex flex-wrap items-center justify-between gap-2 border border-[#6CE9A6] bg-[#ECFDF3] p-3 text-[10px] text-[#067647]">
                        <div><div className="font-black uppercase">Payment captured</div><div className="mt-0.5 font-semibold">{lastPaymentConfirmation.paymentNumber} · {money(lastPaymentConfirmation.amount, lastPaymentConfirmation.currency)}</div></div>
                        <div className="flex gap-2"><SmallAction label={paymentReceiptBusy === `${lastPaymentConfirmation.paymentId}:open` ? "Opening" : "Print 80mm"} icon={Printer} disabled={Boolean(paymentReceiptBusy)} onClick={() => deliverPaymentReceipt(lastPaymentConfirmation.paymentId, "open")} /><SmallAction label={paymentReceiptBusy === `${lastPaymentConfirmation.paymentId}:download` ? "Downloading" : "Download"} icon={Download} disabled={Boolean(paymentReceiptBusy)} primary onClick={() => deliverPaymentReceipt(lastPaymentConfirmation.paymentId, "download")} /></div>
                      </div>
                    )}
                    <div className="grid gap-2 md:grid-cols-3 xl:grid-cols-6">
                      <Metric label="Acct Currency" value={accountCurrency} />
                      <Metric label="Due" value={money(currentAmountDue, accountCurrency)} warning={currentAmountDue > 0} />
                      <Metric label="Credit" value={money(currentCreditBalance, accountCurrency)} good={currentCreditBalance > 0} />
                      <Metric label="Payment" value={money(paymentAmount, paymentCurrency)} />
                      <Metric label="Deduction" value={money(convertedDeduction, accountCurrency)} good={convertedDeduction > 0} />
                      <Metric label="Due After" value={money(projectedAmountDue, accountCurrency)} warning={projectedAmountDue > 0} />
                      <Metric label="Credit After" value={money(projectedCreditBalance, accountCurrency)} good={projectedCreditBalance > 0} />
                      <Metric label="Available" value={money(projectedAvailable, accountCurrency)} good />
                    </div>

                    <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                    <Input label="Amount" value={payment.amount} onChange={(value) => setPay("amount", value)} type="number" />
                    <CurrencySelect
                      label="Currency"
                      value={payment.currency_code}
                      currencies={currencies}
                      onChange={(value) => setPay("currency_code", value.toUpperCase())}
                    />
                    <PaymentTypeSelect
                      label="Method"
                      value={payment.payment_type_id}
                      paymentTypes={paymentTypes}
                      onChange={(value) => {
                        const selected = paymentTypes.find((type) => String(type.id) === String(value));
                        setPayment((prev) => ({
                          ...prev,
                          payment_type_id: value,
                          payment_method: paymentTypeName(selected || {}),
                        }));
                      }}
                    />
                    <Input label="Reference" value={payment.reference_no} onChange={(value) => setPay("reference_no", value)} />
                    <button
                      type="button"
                      disabled={saving || paymentUnavailable}
                      onClick={() => submitPayment()}
                      className="mt-[18px] h-8 border border-[#069495] bg-[#069495] px-5 text-[10px] font-semibold uppercase text-white disabled:opacity-60"
                    >
                      {saving ? "Saving..." : "Record Payment"}
                    </button>

                    {(paymentConverted || convertingPayment) && (
                      <div className="md:col-span-2 xl:col-span-5 border border-[#CFD9D8] bg-[#F8FAFC] px-3 py-2 text-[11px] font-semibold text-[#324443]">
                        {convertingPayment
                          ? "Converting..."
                          : `Converted: ${money(paymentConverted?.converted_amount, paymentConverted?.to)} from ${money(paymentConverted?.amount, paymentConverted?.from)}`}
                      </div>
                    )}

                    <label className="block md:col-span-2 xl:col-span-5">
                      <div className="mb-1 text-[10px] font-semibold uppercase text-[#627474]">Notes</div>
                      <textarea value={payment.notes || ""} onChange={(event) => setPay("notes", event.target.value)} rows={3} className="w-full border border-[#AAB5B5] bg-white px-2 py-2 text-[11px] outline-none focus:border-[#069495]" />
                    </label>

                    <div className="md:col-span-2 xl:col-span-5">
                      <div className="mb-2 flex flex-wrap items-center justify-between gap-1">
                        <div className="text-[10px] font-semibold uppercase text-[#627474]">Payments</div>
                        <div className="flex flex-wrap items-center gap-1">
                          <select
                            value={statementReportFormat}
                            onChange={(event) => setStatementReportFormat(event.target.value)}
                            className="h-8 border border-[#AAB5B5] bg-white px-2 text-[10px] font-semibold uppercase text-[#324443] outline-none"
                          >
                            <option value="a4">A4</option>
                            <option value="80mm">80mm</option>
                          </select>
                          <SmallAction type="button" onClick={loadStatement} disabled={statementLoading} icon={RefreshCw} label={statementLoading ? "Loading" : "Refresh"} />
                          <SmallAction type="button" onClick={() => exportStatementPdf("download")} disabled={!editing?.id || statementLoading} icon={Download} label="PDF" primary />
                          <SmallAction type="button" onClick={() => exportStatementPdf("print")} disabled={!editing?.id || statementLoading} icon={FileText} label="Print" />
                        </div>
                      </div>
                      <PaymentStatementTable
                        rows={statementRows}
                        loading={statementLoading}
                        customerId={editing?.id}
                        onReversePayment={openPaymentReversalModal}
                        onPaymentReceipt={deliverPaymentReceipt}
                        paymentReceiptBusy={paymentReceiptBusy}
                        reversing={saving}
                      />
                    </div>
                    </div>
                  </div>
                )}
              </Panel>
            )}
          </main>
        </div>

        <AccountOverpaymentConfirmationModal
          open={Boolean(pendingOverpayment)}
          data={pendingOverpayment}
          accountCurrency={accountCurrency}
          saving={saving}
          onClose={() => !saving && setPendingOverpayment(null)}
          onConfirm={() => pendingOverpayment && postPayment({
            ...pendingOverpayment.payload,
            confirm_overpayment: true,
          })}
        />

        <PaymentReversalModal
          open={reversalModalOpen}
          row={reversalRow}
          reason={reversalReason}
          saving={saving}
          accountCurrency={accountCurrency}
          onReasonChange={setReversalReason}
          onClose={closePaymentReversalModal}
          onConfirm={confirmPaymentReversal}
        />

        <div className="flex h-12 shrink-0 items-center justify-between border-t border-[#CFD9D8] bg-[#F5F7FA] px-3">
          <div className="text-[10px] font-semibold uppercase text-[#627474]">
            {editing ? `Editing: ${editing.account_number || customerName(editing)}` : "New customer"}
          </div>
          <div className="flex gap-2">
            <button type="button" onClick={onClose} className="h-8 border border-[#AAB5B5] bg-white px-4 text-[10px] font-semibold uppercase text-[#324443]">Cancel</button>
            <button type="submit" disabled={saving} className="h-8 border border-[#069495] bg-[#069495] px-5 text-[10px] font-semibold uppercase text-white disabled:opacity-60">
              {saving ? "Saving..." : editing ? "Save Changes" : "Create Customer"}
            </button>
          </div>
        </div>
      </form>
    </div>
  );
}

function AccountOverpaymentConfirmationModal({
  open,
  data,
  accountCurrency,
  saving,
  onClose,
  onConfirm,
}) {
  if (!open || !data) return null;

  return (
    <div className="fixed inset-0 z-[370] flex items-center justify-center bg-black/65 p-4" onClick={(event) => event.stopPropagation()}>
      <div role="dialog" aria-modal="true" aria-labelledby="account-overpayment-title" className="sx-large-modal-window border border-[#D9A400] bg-white text-[#1C2C2B] shadow-2xl">
        <div className="border-b border-[#D9A400] bg-[#5E4300] px-4 py-3 text-white">
          <div id="account-overpayment-title" className="text-[13px] font-semibold uppercase">Confirm prepaid account credit</div>
        </div>
        <div className="min-h-0 flex-1 space-y-1 overflow-y-auto p-4 text-[11px]">
          <SummaryLine label="Payment value" value={money(data.accountAmount, accountCurrency)} />
          <SummaryLine label="Applied to current debt" value={money(data.applied, accountCurrency)} />
          <SummaryLine label="New prepaid credit" value={money(data.excess, accountCurrency)} />
          <div className="mt-3 border border-[#FEDF89] bg-[#FFFAEB] p-3 font-semibold text-[#B54708]">The excess becomes prepaid account credit.</div>
        </div>
        <div className="flex justify-end gap-2 border-t border-[#CFD9D8] bg-[#F5F7FA] p-3">
          <button type="button" onClick={onClose} disabled={saving} className="h-9 border border-[#AAB5B5] bg-white px-4 text-[10px] font-semibold uppercase text-[#324443] disabled:opacity-50">Cancel</button>
          <button type="button" onClick={onConfirm} disabled={saving} className="h-9 border border-[#00883F] bg-[#00A651] px-5 text-[10px] font-semibold uppercase text-white disabled:opacity-50">{saving ? "Posting..." : "Confirm & Credit Excess"}</button>
        </div>
      </div>
    </div>
  );
}

function PaymentReversalModal({
  open,
  row,
  reason,
  saving = false,
  accountCurrency = "USD",
  onReasonChange,
  onClose,
  onConfirm,
}) {
  if (!open || !row) return null;

  const reference = row.reference_no || row.reference || row.document_number || "Payment";
  const method = row.payment_method || row.payment_name || row.money_code || row.entry_type || "Payment";
  const currency = row.currency_code || accountCurrency || "USD";
  const amount = Number(row.credit_amount ?? row.credit ?? row.amount ?? 0);
  const balanceAfter = Number(row.balance ?? row.balance_after ?? row.running_balance ?? 0);

  const confirm = (event) => {
    event?.preventDefault?.();
    event?.stopPropagation?.();
    if (saving || !String(reason || "").trim()) return;
    onConfirm?.();
  };

  return (
    <div
      className="fixed inset-0 z-[360] flex items-center justify-center bg-black/60 p-4"
      onClick={(event) => event.stopPropagation()}
    >
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby="payment-reversal-title"
        className="sx-large-modal-window border border-[#AAB5B5] bg-white text-[#1C2C2B] shadow-2xl"
      >
        <div className="flex h-12 items-center justify-between border-b border-[#FDA29B] bg-[#004142] px-3 text-white">
          <div>
            <div id="payment-reversal-title" className="text-[13px] font-semibold uppercase">Reverse</div>
            <div className="text-[10px] font-medium uppercase text-white/65">
              Audit reversal
            </div>
          </div>

          <button
            type="button"
            onClick={onClose}
            disabled={saving}
            className="flex h-8 w-8 items-center justify-center border border-white/20 bg-white/10 text-[18px] hover:bg-white/20 disabled:opacity-50"
          >
            ×
          </button>
        </div>

        <div className="grid min-h-0 flex-1 gap-3 overflow-y-auto p-3 md:grid-cols-[220px_1fr]">
          <aside className="border border-[#CFD9D8] bg-[#F5F7FA] p-3 text-[11px]">
            <div className="border-b border-[#CFD9D8] pb-2 text-[10px] font-semibold uppercase text-[#627474]">
              Selected Payment
            </div>
            <div className="mt-2 grid gap-1">
              <SummaryLine label="Reference" value={reference} />
              <SummaryLine label="Method" value={method} />
              <SummaryLine label="Amount" value={money(amount, currency)} />
              <SummaryLine label="Balance" value={money(balanceAfter, currency)} />
              <SummaryLine label="Date" value={cleanDate(row.transaction_date || row.document_date || row.created_at || row.date)} />
            </div>
            <div className="mt-3 border border-[#FDA29B] bg-[#FEF3F2] p-2 text-[10px] font-semibold text-[#B42318]">
              Account ledger only. Cashup is unchanged.
            </div>
          </aside>

          <main className="min-w-0">
            <label className="block">
              <div className="mb-1 text-[10px] font-semibold uppercase text-[#627474]">
                Reason
              </div>
              <textarea
                autoFocus
                value={reason || ""}
                onChange={(event) => onReasonChange?.(event.target.value)}
                rows={5}
                className="w-full border border-[#AAB5B5] bg-white px-3 py-2 text-[12px] font-semibold text-[#1C2C2B] outline-none focus:border-[#069495]"
                placeholder="Example: Wrong amount"
              />
            </label>

            <div className="mt-3 border border-[#FEDF89] bg-[#FFFAEB] px-3 py-2 text-[10px] font-semibold text-[#B54708]">
              This corrects the customer account only.
            </div>
          </main>
        </div>

        <div className="flex h-12 items-center justify-between border-t border-[#CFD9D8] bg-[#F5F7FA] px-3">
          <div className="text-[10px] font-semibold uppercase text-[#627474]">
            Reason required.
          </div>
          <div className="flex gap-2">
            <button
              type="button"
              onClick={onClose}
              disabled={saving}
              className="h-8 border border-[#AAB5B5] bg-white px-4 text-[10px] font-semibold uppercase text-[#324443] disabled:opacity-60"
            >
              Cancel
            </button>
            <button
              type="button"
              onClick={confirm}
              disabled={saving || !String(reason || "").trim()}
              className="h-8 border border-[#B42318] bg-[#B42318] px-5 text-[10px] font-semibold uppercase text-white disabled:opacity-60"
            >
              {saving ? "Reversing..." : "Reverse"}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}

function getStatementItems(row) {
  if (Array.isArray(row?.receipt_lines)) return row.receipt_lines;
  if (Array.isArray(row?.line_items)) return row.line_items;
  if (Array.isArray(row?.items)) return row.items;
  return [];
}

function cleanDate(value) {
  if (!value) return "-";
  return String(value).replace("T", " ").slice(0, 19);
}

function ReceiptInfoModal({ row, onClose }) {
  if (!row) return null;

  const sale = row.sale || row.sales_transaction || {};
  const items = getStatementItems(row);
  const currency = row.currency_code || sale.currency_code || "USD";
  const receiptNumber =
    row.receipt_number ||
    row.reference_no ||
    row.document_number ||
    sale.invoice_number ||
    "Receipt";

  const totals = items.reduce(
    (acc, item) => {
      acc.qty += Number(item.quantity ?? item.line_quantity ?? 0);
      acc.subtotal += Number(item.subtotal ?? item.line_subtotal ?? 0);
      acc.discount += Number(item.discount ?? item.line_discount ?? 0);
      acc.tax += Number(item.tax ?? item.line_tax ?? item.tax_total ?? 0);
      acc.total += Number(item.total ?? item.line_total ?? 0);
      return acc;
    },
    { qty: 0, subtotal: 0, discount: 0, tax: 0, total: 0 }
  );

  const saleSubtotal = Number(sale.subtotal_amount ?? row.subtotal_total ?? totals.subtotal ?? 0);
  const saleDiscount = Number(sale.discount_amount ?? row.discount_total ?? totals.discount ?? 0);
  const saleTax = Number(sale.tax_amount ?? row.tax_total ?? totals.tax ?? 0);
  const saleTotal = Number(sale.total_amount ?? row.line_total ?? totals.total ?? row.debit_amount ?? 0);

  return (
    <div className="fixed inset-0 z-[300] flex items-center justify-center bg-black/60 p-3">
      <div className="flex h-[88vh] w-full max-w-6xl flex-col border border-[#AAB5B5] bg-white text-[#1C2C2B] shadow-2xl">
        <div className="flex h-12 shrink-0 items-center justify-between border-b border-[#00BCBC] bg-[#004142] px-3 text-white">
          <div>
            <div className="text-[13px] font-semibold uppercase">
              Receipt / {receiptNumber}
            </div>
            <div className="text-[10px] font-medium uppercase text-white/65">
              Receipt lines and tax
            </div>
          </div>

          <button
            type="button"
            onClick={onClose}
            className="flex h-8 w-8 items-center justify-center border border-white/20 bg-white/10 text-[18px] hover:bg-white/20"
          >
            ×
          </button>
        </div>

        <div className="shrink-0 border-b border-[#CFD9D8] bg-[#F5F7FA] p-3">
          <div className="grid gap-2 md:grid-cols-4 xl:grid-cols-6">
            <Metric label="Receipt" value={receiptNumber} />
            <Metric label="Date" value={cleanDate(sale.transaction_date || row.transaction_date || row.document_date)} />
            <Metric label="Customer" value={sale.customer_name || row.customer_name || "-"} />
            <Metric label="Payment" value={sale.payment_method || row.payment_method || "Credit"} warning />
            <Metric label="PO / Reference" value={row.reference || row.metadata?.po_number || "-"} />
            <Metric label="Status" value={sale.status || row.entry_type || "sale"} good />
          </div>

          <div className="mt-2 grid gap-2 md:grid-cols-5">
            <Metric label="Subtotal" value={money(saleSubtotal, currency)} />
            <Metric label="Discount" value={money(saleDiscount, currency)} warning />
            <Metric label="Tax" value={money(saleTax, currency)} />
            <Metric label="Total" value={money(saleTotal, currency)} good />
            <Metric label="Balance" value={money(row.balance ?? row.balance_after ?? 0, currency)} warning />
          </div>
        </div>

        <div className="min-h-0 flex-1 overflow-auto bg-white">
          <table className="w-full min-w-[1320px] border-collapse text-left text-[11px]">
            <thead className="sticky top-0 z-10 bg-[#0E4D4D] text-white">
              <tr>
                <GridTh>#</GridTh>
                <GridTh>Product</GridTh>
                <GridTh>Code / SKU</GridTh>
                <GridTh align="right">Qty</GridTh>
                <GridTh>UOM</GridTh>
                <GridTh align="right">Unit Price</GridTh>
                <GridTh align="right">Subtotal</GridTh>
                <GridTh align="right">Discount</GridTh>
                <GridTh align="right">Tax</GridTh>
                <GridTh>Tax</GridTh>
                <GridTh align="right">Total</GridTh>
              </tr>
            </thead>

            <tbody>
              {items.length === 0 ? (
                <tr>
                  <td colSpan={11} className="px-3 py-10 text-center text-[#627474]">
                    No receipt lines.
                  </td>
                </tr>
              ) : (
                items.map((item, index) => {
                  const lineCurrency = item.currency_code || currency;
                  const taxes = Array.isArray(item.taxes)
                    ? item.taxes
                    : Array.isArray(item.tax_breakdown)
                    ? item.tax_breakdown
                    : [];

                  return (
                    <tr key={item.id || item.receipt_line_id || index} className="border-b border-[#CFD9D8] hover:bg-[#F8FAFC]">
                      <GridTd>{item.line_no || index + 1}</GridTd>
                      <GridTd strong>
                        <div>{item.product_name || item.line_name || item.name || "Product"}</div>
                        {item.metadata?.product_type && (
                          <div className="mt-1 text-[9px] font-normal uppercase text-[#627474]">
                            Type: {item.metadata.product_type}
                          </div>
                        )}
                      </GridTd>
                      <GridTd>
                        {[item.product_code, item.sku, item.barcode].filter(Boolean).join(" / ") || "-"}
                      </GridTd>
                      <GridTd right>{Number(item.quantity ?? item.line_quantity ?? 0).toFixed(4)}</GridTd>
                      <GridTd>{item.uom_code || item.uom || "-"}</GridTd>
                      <GridTd right>{money(item.unit_price ?? item.line_price ?? 0, lineCurrency)}</GridTd>
                      <GridTd right>{money(item.subtotal ?? item.line_subtotal ?? 0, lineCurrency)}</GridTd>
                      <GridTd right>{money(item.discount ?? item.line_discount ?? 0, lineCurrency)}</GridTd>
                      <GridTd right>{money(item.tax ?? item.line_tax ?? item.tax_total ?? 0, lineCurrency)}</GridTd>
                      <GridTd>
                        {taxes.length > 0 ? (
                          <div className="space-y-1">
                            {taxes.map((tax, taxIndex) => (
                              <div key={tax.id || tax.tax_id || taxIndex} className="border-b border-[#EEF2F2] pb-1 last:border-b-0 last:pb-0">
                                <div className="font-semibold text-[#0C1B1B]">
                                  {tax.tax_code || tax.code || tax.tax_name || tax.name || "Tax"}
                                  {tax.tax_percent || tax.rate ? ` / ${Number(tax.tax_percent ?? tax.rate ?? 0).toFixed(2)}%` : ""}
                                  {tax.is_inclusive ? " inc" : tax.is_inclusive === false ? " exc" : ""}
                                </div>
                                <div className="text-[10px] text-[#627474]">
                                  Taxable: {money(tax.taxable_amount ?? 0, lineCurrency)} | Tax: {money(tax.tax_amount ?? 0, lineCurrency)} | With Tax: {money(tax.sales_amount_with_tax ?? 0, lineCurrency)}
                                </div>
                              </div>
                            ))}
                          </div>
                        ) : (
                          <span className="text-[#96A5A4]">No tax lines</span>
                        )}
                      </GridTd>
                      <GridTd right strong>{money(item.total ?? item.line_total ?? 0, lineCurrency)}</GridTd>
                    </tr>
                  );
                })
              )}
            </tbody>
          </table>
        </div>

        <div className="grid h-12 shrink-0 grid-cols-5 border-t border-[#CFD9D8] bg-[#F5F7FA] text-[11px]">
          <div className="flex items-center px-3 font-semibold uppercase text-[#324443]">Totals</div>
          <div className="flex items-center justify-end px-3 font-semibold">Qty {totals.qty.toFixed(4)}</div>
          <div className="flex items-center justify-end px-3 font-semibold">Subtotal {money(saleSubtotal, currency)}</div>
          <div className="flex items-center justify-end px-3 font-semibold">Tax {money(saleTax, currency)}</div>
          <div className="flex items-center justify-end px-3 text-[13px] font-bold text-[#067C7C]">Total {money(saleTotal, currency)}</div>
        </div>
      </div>
    </div>
  );
}

function StatementTable({ rows, loading, customerId, onPaymentReceipt, paymentReceiptBusy = "" }) {
  const [selectedReceiptRow, setSelectedReceiptRow] = useState(null);

  return (
    <>
      <table className="w-full min-w-[1120px] border-collapse text-left text-[11px]">
        <thead className="bg-[#0E4D4D] text-white">
          <tr>
            <GridTh>Date</GridTh>
            <GridTh>Type</GridTh>
            <GridTh>Reference</GridTh>
            <GridTh>Description</GridTh>
            <GridTh>Items</GridTh>
            <GridTh align="right">Qty</GridTh>
            <GridTh align="right">Debit</GridTh>
            <GridTh align="right">Credit</GridTh>
            <GridTh align="right">Balance</GridTh>
            <GridTh align="right">Actions</GridTh>
          </tr>
        </thead>
        <tbody>
          {rows.length === 0 ? (
            <tr>
              <td colSpan={10} className="px-3 py-10 text-center text-[#627474]">
                {loading ? "Loading..." : "No rows found."}
              </td>
            </tr>
          ) : (
            rows.map((row, index) => {
              const items = getStatementItems(row);
              const qtyTotal = items.reduce(
                (sum, item) => sum + Number(item.quantity ?? item.line_quantity ?? 0),
                0
              );
              const canOpenReceipt = isSaleStatementRow(row);
              const paymentId = getPaymentIdFromStatementRow(row);
              const canOpenPaymentReceipt = Boolean(customerId) && Boolean(paymentId);

              return (
                <tr
                  key={row.id || index}
                  onClick={() => canOpenReceipt && setSelectedReceiptRow(row)}
                  className={`border-b border-[#CFD9D8] hover:bg-[#F8FAFC] ${canOpenReceipt ? "cursor-pointer" : ""}`}
                  title={canOpenReceipt ? "View receipt" : undefined}
                >
                  <GridTd>{row.transaction_date || row.document_date || row.created_at || row.date || "-"}</GridTd>
                  <GridTd strong>
                    <div>{row.transaction_type || row.entry_type || row.type || "-"}</div>
                    {isCancelledStatementRow(row) && <Pill tone="danger">Cancelled</Pill>}
                  </GridTd>
                  <GridTd>{row.reference_no || row.document_number || row.reference || row.receipt_number || "-"}</GridTd>
                  <GridTd>{row.description || row.notes || "-"}</GridTd>
                  <GridTd>
                    {items.length > 0 ? (
                      <div className="space-y-1">
                        <div className="font-semibold text-[#0C1B1B]">
                          {row.item_summary || `${items.length} receipt line${items.length === 1 ? "" : "s"}`}
                        </div>
                        <div className="text-[10px] text-[#627474]">
                          Click to view receipt.
                        </div>
                      </div>
                    ) : (
                      <span className="text-[#96A5A4]">-</span>
                    )}
                  </GridTd>
                  <GridTd right>{Number(row.quantity_total ?? qtyTotal ?? 0).toFixed(4)}</GridTd>
                  <GridTd right>
                    {isCancelledStatementRow(row) ? (
                      <span className="text-[#96A5A4] line-through">{money(row.debit_amount ?? row.debit ?? 0, row.currency_code || "USD")}</span>
                    ) : (
                      money(effectiveDebitValue(row), row.currency_code || "USD")
                    )}
                  </GridTd>
                  <GridTd right>{money(effectiveCreditValue(row), row.currency_code || "USD")}</GridTd>
                  <GridTd right strong>{money(row.balance ?? row.balance_after ?? row.running_balance ?? 0, row.currency_code || "USD")}</GridTd>
                  <GridTd right>
                    {canOpenReceipt || canOpenPaymentReceipt ? <RowActionMenu
                      label="Open transaction actions"
                      actions={canOpenReceipt ? [
                        { label: "View Receipt", onClick: () => setSelectedReceiptRow(row) },
                      ] : [
                        { label: "Print Receipt", onClick: () => onPaymentReceipt?.(paymentId, "open"), disabled: Boolean(paymentReceiptBusy), good: true },
                        { label: "Download Receipt", onClick: () => onPaymentReceipt?.(paymentId, "download"), disabled: Boolean(paymentReceiptBusy) },
                      ]}
                    /> : <span className="text-[#96A5A4]">-</span>}
                  </GridTd>
                </tr>
              );
            })
          )}
        </tbody>
      </table>

      <ReceiptInfoModal
        row={selectedReceiptRow}
        onClose={() => setSelectedReceiptRow(null)}
      />
    </>
  );
}


function PaymentStatementTable({
  rows,
  loading,
  customerId,
  onReversePayment,
  onPaymentReceipt,
  paymentReceiptBusy = "",
  reversing = false,
}) {
  const paymentRows = (rows || []).filter(isPaymentStatementRow);

  return (
    <table className="w-full min-w-[820px] border-collapse text-left text-[11px]">
      <thead className="bg-[#0E4D4D] text-white">
        <tr>
          <GridTh>Date</GridTh>
          <GridTh>Reference</GridTh>
          <GridTh>Method</GridTh>
          <GridTh>Description</GridTh>
          <GridTh align="right">Paid</GridTh>
          <GridTh align="right">Balance</GridTh>
          <GridTh align="right">Actions</GridTh>
        </tr>
      </thead>
      <tbody>
        {paymentRows.length === 0 ? (
          <tr>
            <td colSpan={7} className="px-3 py-8 text-center text-[#627474]">
              {loading ? "Loading..." : "No payments found."}
            </td>
          </tr>
        ) : (
          paymentRows.map((row, index) => {
            const currency = row.currency_code || "USD";
            const paymentId = getPaymentIdFromStatementRow(row);
            const canReverse =
              Boolean(customerId) &&
              Boolean(paymentId) &&
              !isPaymentReversalRow(row) &&
              Number(row.credit_amount ?? row.credit ?? row.amount ?? 0) > 0;
            const canOpenPaymentReceipt = Boolean(customerId) && Boolean(paymentId);

            return (
              <tr key={row.id || row.payment_id || index} className="border-b border-[#CFD9D8] hover:bg-[#F8FAFC]">
                <GridTd>{row.transaction_date || row.document_date || row.created_at || row.date || "-"}</GridTd>
                <GridTd strong>{row.reference_no || row.reference || row.document_number || "-"}</GridTd>
                <GridTd>{row.payment_method || row.payment_name || row.money_code || row.entry_type || "Payment"}</GridTd>
                <GridTd>
                  <div>{row.description || row.notes || "Payment"}</div>
                  {isPaymentReversalRow(row) && (
                    <div className="mt-1">
                      <Pill tone="danger">Reversal</Pill>
                    </div>
                  )}
                </GridTd>
                <GridTd right strong>{money(row.credit_amount ?? row.credit ?? row.amount ?? 0, currency)}</GridTd>
                <GridTd right>{money(row.balance ?? row.balance_after ?? row.running_balance ?? 0, currency)}</GridTd>
                <GridTd right>
                  {canOpenPaymentReceipt || canReverse ? <RowActionMenu
                    label="Open payment actions"
                    actions={[
                      canOpenPaymentReceipt ? { label: "Print Receipt", onClick: () => onPaymentReceipt?.(paymentId, "open"), disabled: Boolean(paymentReceiptBusy), good: true } : null,
                      canOpenPaymentReceipt ? { label: "Download Receipt", onClick: () => onPaymentReceipt?.(paymentId, "download"), disabled: Boolean(paymentReceiptBusy) } : null,
                      canReverse ? { label: reversing ? "Reversing..." : "Reverse Payment", onClick: () => onReversePayment?.(row), disabled: reversing, danger: true } : null,
                    ]}
                  /> : <span className="text-[#96A5A4]">-</span>}
                </GridTd>
              </tr>
            );
          })
        )}
      </tbody>
    </table>
  );
}

function Panel({ title, children }) {
  return (
    <div className="border border-[#CFD9D8] bg-white p-3 shadow-sm">
      <div className="mb-3 border-b border-[#E4E8E8] pb-2 text-[12px] font-semibold uppercase text-[#324443]">
        {title}
      </div>
      {children}
    </div>
  );
}

function Metric({ label, value, good = false, warning = false, danger = false }) {
  const color = danger ? "text-[#B42318]" : good ? "text-[#067C7C]" : warning ? "text-[#B54708]" : "text-[#1C2C2B]";
  return (
    <div className="border border-[#CFD9D8] bg-white px-2 py-2">
      <div className="text-[9px] font-semibold uppercase text-[#627474]">{label}</div>
      <div className={`mt-[1px] text-[15px] font-bold ${color}`}>{value}</div>
    </div>
  );
}

function SummaryLine({ label, value }) {
  return (
    <div className="flex items-center justify-between gap-2 border-b border-[#EEF2F2] py-1 last:border-b-0">
      <span>{label}</span>
      <span className="font-semibold text-[#1C2C2B]">{value}</span>
    </div>
  );
}

function GridTh({ children, align = "left" }) {
  return (
    <th className={`border-r border-white/10 px-2 py-2 text-${align} text-[10px] font-semibold uppercase tracking-wide last:border-r-0`}>
      {children}
    </th>
  );
}

function GridTd({ children, right = false, strong = false }) {
  return (
    <td className={`border-r border-[#CFD9D8] px-2 py-2 align-top last:border-r-0 ${right ? "text-right" : "text-left"} ${strong ? "font-semibold text-[#0C1B1B]" : "text-[#324443]"}`}>
      {children}
    </td>
  );
}

function Pill({ children, tone = "default" }) {
  const style =
    tone === "good"
      ? "border-[#ABEFC6] bg-[#ECFDF3] text-[#067647]"
      : tone === "danger"
      ? "border-[#FDA29B] bg-[#FEF3F2] text-[#B42318]"
      : tone === "warning"
      ? "border-[#FEDF89] bg-[#FFFAEB] text-[#B54708]"
      : "border-[#CFD9D8] bg-[#F8FAFC] text-[#324443]";

  return <span className={`inline-flex border px-2 py-[2px] text-[9px] font-semibold uppercase ${style}`}>{children}</span>;
}

function SmallAction({ label, icon: Icon, onClick, disabled = false, primary = false, type = "button" }) {
  return (
    <button
      type={type}
      onClick={onClick}
      disabled={disabled}
      className={`inline-flex h-8 items-center gap-1 border px-3 text-[10px] font-semibold uppercase disabled:opacity-60 ${
        primary
          ? "border-[#069495] bg-[#069495] text-white"
          : "border-[#AAB5B5] bg-white text-[#324443] hover:bg-[#F8FAFC]"
      }`}
    >
      {Icon && <Icon className="h-3.5 w-3.5" />}
      {label}
    </button>
  );
}

function RowActionMenu({ actions = [], label = "Open row actions", disabled = false }) {
  const visibleActions = actions.filter(Boolean);

  return (
    <details className="action-menu-wrap relative ml-auto w-fit" onClick={(event) => event.stopPropagation()}>
      <summary
        aria-label={label}
        title={label}
        className={`flex h-7 w-7 cursor-pointer list-none items-center justify-center border text-lg font-bold leading-none [&::-webkit-details-marker]:hidden ${disabled ? "cursor-not-allowed border-[#CFD6D6] bg-[#F2F4F7] text-[#96A5A4]" : "border-[#AAB5B5] bg-white text-[#324443] hover:bg-[#F8FAFC]"}`}
        onClick={(event) => {
          if (disabled) event.preventDefault();
        }}
      >
        <span aria-hidden="true">⋮</span>
      </summary>

      {!disabled && visibleActions.length > 0 && (
        <div className="absolute right-0 z-30 mt-1 min-w-[170px] border border-[#AAB5B5] bg-white py-1 text-left shadow-lg">
          {visibleActions.map((action) => (
            <button
              key={action.label}
              type="button"
              title={action.title || action.label}
              disabled={action.disabled}
              onClick={(event) => {
                event.currentTarget.closest("details")?.removeAttribute("open");
                action.onClick();
              }}
              className={`block w-full px-3 py-2 text-left text-[10px] font-semibold uppercase hover:bg-[#F8FAFC] disabled:cursor-not-allowed disabled:text-[#96A5A4] ${action.danger ? "text-[#B42318]" : action.good ? "text-[#067647]" : "text-[#324443]"}`}
            >
              {action.label}
            </button>
          ))}
        </div>
      )}
    </details>
  );
}

function Input({ label, value, onChange, type = "text", className = "" }) {
  return (
    <label className={`block ${className}`}>
      <div className="mb-1 text-[10px] font-semibold uppercase text-[#627474]">{label}</div>
      <input
        type={type}
        value={value ?? ""}
        onChange={(event) => onChange(event.target.value)}
        className="h-8 w-full border border-[#AAB5B5] bg-white px-2 text-[11px] outline-none focus:border-[#069495]"
      />
    </label>
  );
}

function CurrencySelect({ label, value, currencies = [], onChange }) {
  return (
    <label className="block">
      <div className="mb-1 text-[10px] font-semibold uppercase text-[#627474]">{label}</div>
      <select
        value={value ?? ""}
        onChange={(event) => onChange(event.target.value)}
        className="h-8 w-full border border-[#AAB5B5] bg-white px-2 text-[11px] outline-none focus:border-[#069495]"
      >
        <option value="">Currency</option>
        {currencies.length === 0 && <option value="USD">USD</option>}
        {currencies.map((currency) => {
          const code = getCurrencyCode(currency);
          if (!code) return null;

          return (
            <option key={currency.id || code} value={code}>
              {code} - {getCurrencyName(currency)}
            </option>
          );
        })}
      </select>
    </label>
  );
}

function PaymentTypeSelect({ label, value, paymentTypes = [], onChange }) {
  return (
    <label className="block">
      <div className="mb-1 text-[10px] font-semibold uppercase text-[#627474]">{label}</div>
      <select
        value={value ?? ""}
        onChange={(event) => onChange(event.target.value)}
        className="h-8 w-full border border-[#AAB5B5] bg-white px-2 text-[11px] outline-none focus:border-[#069495]"
      >
        <option value="">Select Method</option>
        {paymentTypes.map((type) => (
          <option key={type.id} value={type.id}>
            {paymentTypeName(type)}{type.money_code ? ` / ${type.money_code}` : ""}
          </option>
        ))}
      </select>
    </label>
  );
}

function Select({ label, value, onChange, children }) {
  return (
    <label className="block">
      <div className="mb-1 text-[10px] font-semibold uppercase text-[#627474]">{label}</div>
      <select
        value={value ?? ""}
        onChange={(event) => onChange(event.target.value)}
        className="h-8 w-full border border-[#AAB5B5] bg-white px-2 text-[11px] outline-none focus:border-[#069495]"
      >
        {children}
      </select>
    </label>
  );
}

function Check({ label, checked, onChange }) {
  return (
    <label className="flex min-h-[34px] items-center gap-2 border border-[#CFD9D8] bg-white px-2 py-2 text-[10px] font-semibold uppercase text-[#324443]">
      <input type="checkbox" checked={Boolean(checked)} onChange={(event) => onChange(event.target.checked)} />
      {label}
    </label>
  );
}
