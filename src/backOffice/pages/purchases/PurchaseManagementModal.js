// src/backOffice/pages/purchases/PurchaseManagementModal.jsx

import React, { useEffect, useMemo, useRef, useState } from "react";
import { createPortal } from "react-dom";
import {
  Download,
  Eye,
  FileText,
  MoreVertical,
  PackageCheck,
  Plus,
  RefreshCw,
  RotateCcw,
  Save,
  Search,
  Send,
  X,
  ReceiptText,
} from "lucide-react";
import FullScreenModal from "../../../components/FullScreenModal";
import EnterpriseFormModal from "../../../components/EnterpriseFormModal";
import ReceiveStockModal from "./ReceiveStockModal";
import { getAuthorisedCompany } from "../../../services/AuthenticationService";
import { getCurrencies } from "../../../services/CurrencyService";
import { getProducts, getSuppliers } from "../../../services/ProductService";
import { getInventoryWarehouses } from "../../../services/EnterpriseInventoryService";
import {
  getPurchaseOrders,
  getPurchaseOrder,
  savePurchaseOrder,
  receivePurchaseOrder,
  getPurchaseReceipts,
  getPurchaseReceipt,
  getPurchaseReturns,
  getPurchaseReturn,
  savePurchaseReturn,
  getSupplierInvoices,
  getSupplierInvoice,
  saveSupplierInvoice,
  getSupplierPayments,
  saveSupplierPayment,
  reverseSupplierPayment,
  getPurchasingOverview,
  downloadPurchaseDocument,
  fetchPurchaseDocumentPdf,
  downloadPurchaseRegister,
} from "../../../services/PurchaseService";

const today = () => new Date().toISOString().slice(0, 10);

const emptyPaymentForm = () => ({ branch_id: "", supplier_id: "", payment_date: today(), payment_method: "bank_transfer", currency_code: "USD", exchange_rate: 1, amount: "", reference: "", notes: "", allocations: [{ supplier_invoice_id: "", amount: "" }] });

const number = (value, decimals = 2) => Number(value || 0).toFixed(decimals);

const money = (value, currency = "USD") =>
  `${currency || "USD"} ${number(value, 2)}`;

const getPayload = (response) => response?.data || response || {};

const rowsOf = (response) => {
  const payload = getPayload(response);

  if (Array.isArray(payload)) return payload;
  if (Array.isArray(payload?.data)) return payload.data;
  if (Array.isArray(payload?.data?.data)) return payload.data.data;

  return [];
};

const metaOf = (response) => {
  const payload = getPayload(response);
  const data = payload?.data || payload;

  return {
    current_page: Number(data?.current_page || 1),
    last_page: Number(data?.last_page || 1),
    total: Number(data?.total || rowsOf(response).length || 0),
    per_page: Number(data?.per_page || 25),
  };
};

const errorDetails = (error, fallback = "Request failed.") => {
  const data = error?.response?.data;
  const fields = {};

  if (data?.errors && typeof data.errors === "object") {
    Object.entries(data.errors).forEach(([field, messages]) => {
      fields[field] = (Array.isArray(messages) ? messages : [messages])
        .filter(Boolean)
        .map(String)
        .join(" ");
    });
  }

  const fieldMessage = Object.values(fields).filter(Boolean).join("\n");
  return {
    fields,
    message: fieldMessage || data?.message || data?.error || error?.message || fallback,
    title: data?.message || (Object.keys(fields).length ? "Validation error." : fallback),
  };
};

const errorMessage = (error, fallback) => errorDetails(error, fallback).message;

const formatDate = (value) => {
  if (!value) return "-";

  const date = new Date(value);

  if (Number.isNaN(date.getTime())) return String(value).slice(0, 10);

  return date.toLocaleDateString("en-ZW", {
    year: "numeric",
    month: "short",
    day: "2-digit",
  });
};

const getBranchName = (branch) =>
  branch?.branch_name ||
  branch?.name ||
  branch?.branch_code ||
  branch?.code ||
  "Unnamed Branch";

const getSupplierName = (supplier) =>
  supplier?.name ||
  supplier?.supplier_name ||
  supplier?.company_name ||
  supplier?.code ||
  "Supplier";

const getProductName = (product) =>
  product?.name || product?.product_name || "Product";

const getCurrencyCode = (currency) =>
  currency?.code || currency?.currency_code || "";

const isFinishedGoodProduct = (product) =>
  String(
    product?.product_type ||
      product?.type ||
      product?.productType ||
      product?.product_type_code ||
      product?.category_type ||
      ""
  )
    .trim()
    .toLowerCase()
    .replaceAll(" ", "_")
    .replaceAll("-", "_") === "finished_good";

const purchasableProductsOnly = (products = []) =>
  rowsOf(products).filter((product) => !isFinishedGoodProduct(product));

const getDocumentNumber = (row) =>
  row?.po_number ||
  row?.grn_number ||
  row?.return_number ||
  row?.invoice_number ||
  "-";

const getDocumentDate = (row) =>
  row?.order_date || row?.received_date || row?.return_date || row?.invoice_date;

const getReceiptSourceLabel = (row) => {
  const source = String(row?.source || (row?.purchase_order_id ? "purchase_order" : "quick_receive"))
    .trim()
    .toLowerCase();

  if (source === "quick_receive" || source === "quick") return "Quick Receive";
  if (source === "purchase_order" || source === "po_receive") return "Purchase Order";

  return source ? source.replaceAll("_", " ").replace(/\b\w/g, (character) => character.toUpperCase()) : "Goods Receipt";
};

const getLineOrderRelation = (line) =>
  line?.orderLine ||
  line?.order_line ||
  line?.purchase_order_line ||
  line?.purchaseOrderLine ||
  null;

const getLineReceiptRelation = (line) =>
  line?.receiptLine ||
  line?.receipt_line ||
  line?.purchase_receipt_line ||
  line?.purchaseReceiptLine ||
  null;

const getLineProductTitle = (line) =>
  line?.product_name ||
  line?.description ||
  line?.product?.name ||
  line?.product?.product_name ||
  "Product";

const getLineCode = (line) =>
  line?.product_code || line?.product?.code || line?.sku || line?.product?.sku || "-";

const getLineOrderedQuantity = (line, type) => {
  const orderLine = getLineOrderRelation(line);

  if (type === "order") {
    return Number(line?.ordered_quantity || 0);
  }

  if (orderLine) {
    return Number(orderLine?.ordered_quantity || 0);
  }

  if (type === "receipt") {
    return Number(line?.quantity || 0);
  }

  if (type === "invoice") {
    return Number(line?.quantity || 0);
  }

  return Number(line?.ordered_quantity || 0);
};

const getLineReceivedQuantity = (line, type) => {
  const receiptLine = getLineReceiptRelation(line);

  if (type === "receipt") {
    return Number(line?.quantity || 0);
  }

  if (type === "order") {
    return Number(line?.received_quantity || 0);
  }

  if (receiptLine) {
    return Number(receiptLine?.quantity || 0);
  }

  if (type === "invoice") {
    return Number(line?.quantity || 0);
  }

  return Number(line?.received_quantity || 0);
};

const getLineReturnedQuantity = (line, type) => {
  if (type === "return") {
    return Number(line?.quantity || 0);
  }

  return Number(line?.returned_quantity || 0);
};

const getLineMainQuantity = (line, type) => {
  if (type === "order") {
    return Number(line?.ordered_quantity || 0);
  }

  if (type === "receipt") {
    return Number(line?.quantity || 0);
  }

  if (type === "return") {
    return Number(line?.quantity || 0);
  }

  if (type === "invoice") {
    return Number(line?.quantity || 0);
  }

  return Number(line?.quantity || line?.ordered_quantity || 0);
};

const getLineTotal = (line, type) => {
  if (line?.line_total !== null && line?.line_total !== undefined) {
    return Number(line.line_total || 0);
  }

  return (
    Number(getLineMainQuantity(line, type) || 0) * Number(line?.unit_cost || 0) -
    Number(line?.discount_amount || 0) +
    Number(line?.tax_amount || 0)
  );
};

const getOrderPendingQuantity = (order) => {
  if (!order) return 0;

  const lines = Array.isArray(order?.lines) ? order.lines : [];

  if (!lines.length) {
    const ordered = Number(order?.ordered_quantity || order?.total_ordered_quantity || 0);
    const received = Number(order?.received_quantity || order?.total_received_quantity || 0);

    return Math.max(ordered - received, 0);
  }

  return lines.reduce((sum, line) => {
    const ordered = Number(line?.ordered_quantity || line?.quantity_ordered || line?.quantity || 0);
    const received = Number(line?.received_quantity || line?.quantity_received || 0);

    return sum + Math.max(ordered - received, 0);
  }, 0);
};

const orderCanReceive = (order) => {
  const status = String(order?.status || '').toLowerCase();

  return !['received', 'closed', 'cancelled', 'void'].includes(status);
};

const buildReceiveRowsFromOrder = (order) => {
  const lines = Array.isArray(order?.lines) ? order.lines : [];

  return lines
    .map((line) => {
      const ordered = Number(line?.ordered_quantity || line?.quantity_ordered || line?.quantity || 0);
      const alreadyReceived = Number(line?.received_quantity || line?.quantity_received || 0);
      const pending = Math.max(ordered - alreadyReceived, 0);

      return {
        source_line: line,
        purchase_order_line_id: line.id,
        product_id: line.product_id,
        supplier_id: line.supplier_id || order.supplier_id,
        product_name: getLineProductTitle(line),
        product_code: getLineCode(line),
        ordered_quantity: ordered,
        received_quantity: alreadyReceived,
        pending_quantity: pending,
        quantity: pending,
        unit_cost: Number(line.unit_cost || 0),
        currency_code: line.currency_code || order.currency_code,
        discount_amount: 0,
        tax_amount: Number(line.tax_amount || 0),
        lot_number: "",
        manufactured_at: "",
        expires_at: "",
        notes: line.notes || '',
      };
    })
    .filter((line) => line.pending_quantity > 0);
};

const emptyLine = () => ({
  product_id: "",
  supplier_id: "",
  product_name: "",
  product_code: "",
  sku: "",
  ordered_quantity: 1,
  quantity: 1,
  unit_cost: 0,
  currency_code: "USD",
  discount_amount: 0,
  tax_amount: 0,
  notes: "",
  description: "",
  purchase_order_line_id: "",
  purchase_receipt_line_id: "",
  purchase_receipt_id: "",
});

const emptyPoForm = () => ({
  id: null,
  branch_id: "",
  supplier_id: "",
  po_number: "",
  order_date: today(),
  expected_date: "",
  currency_code: "USD",
  exchange_rate: 1,
  shipping_total: 0,
  status: "approved",
  notes: "",
  lines: [emptyLine()],
});

const emptyReturnForm = () => ({
  branch_id: "",
  supplier_id: "",
  purchase_order_id: "",
  purchase_receipt_id: "",
  return_date: today(),
  currency_code: "USD",
  reason: "",
  notes: "",
  lines: [emptyLine()],
});

const emptyInvoiceForm = () => ({
  branch_id: "",
  supplier_id: "",
  purchase_order_id: "",
  purchase_receipt_id: "",
  invoice_number: "",
  invoice_date: today(),
  due_date: "",
  currency_code: "USD",
  status: "posted",
  notes: "",
  lines: [emptyLine()],
});

export default function PurchaseManagementModal({
  open,
  onClose,
  initialTab = "orders",
  currentUser,
}) {
  const [activeTab, setActiveTab] = useState(initialTab || "orders");
  const [branches, setBranches] = useState([]);
  const [suppliers, setSuppliers] = useState([]);
  const [currencies, setCurrencies] = useState([]);
  const [products, setProducts] = useState([]);
  const [warehouses, setWarehouses] = useState([]);

  const [loading, setLoading] = useState(false);
  const [notice, setNotice] = useState("");
  const [formValidation, setFormValidation] = useState({ message: "", fields: {} });
  const [receiveValidation, setReceiveValidation] = useState({ message: "", fields: {} });

  const [filters, setFilters] = useState({
    date_from: today(),
    date_to: today(),
    branch_id: "",
    supplier_id: "",
    status: "",
    q: "",
    per_page: 25,
    page: 1,
  });

  const [ordersResponse, setOrdersResponse] = useState(null);
  const [receiptsResponse, setReceiptsResponse] = useState(null);
  const [returnsResponse, setReturnsResponse] = useState(null);
  const [invoicesResponse, setInvoicesResponse] = useState(null);
  const [paymentsResponse, setPaymentsResponse] = useState(null);
  const [purchasingOverview, setPurchasingOverview] = useState({});

  const [poForm, setPoForm] = useState(emptyPoForm());
  const [returnForm, setReturnForm] = useState(emptyReturnForm());
  const [invoiceForm, setInvoiceForm] = useState(emptyInvoiceForm());
  const [paymentForm, setPaymentForm] = useState(emptyPaymentForm());

  const [formModal, setFormModal] = useState(null);
  const [selectedDocument, setSelectedDocument] = useState(null);
  const [openActionId, setOpenActionId] = useState("");
  const [receiveModal, setReceiveModal] = useState(null);
  const [quickReceiveOpen, setQuickReceiveOpen] = useState(false);
  const [pendingPaymentReversal, setPendingPaymentReversal] = useState(null);
  const [paymentReversalReason, setPaymentReversalReason] = useState("");
  const [paymentReversalValidation, setPaymentReversalValidation] = useState("");

  const orders = rowsOf(ordersResponse);
  const receipts = rowsOf(receiptsResponse);
  const returns = rowsOf(returnsResponse);
  const invoices = rowsOf(invoicesResponse);
  const payments = rowsOf(paymentsResponse);

  const normalizedRole = String(currentUser?.role?.name || currentUser?.role_name || "").trim().toLowerCase().replace(/[ -]+/g, "_");
  const normalizedUserType = String(currentUser?.user_type || "").trim().toLowerCase().replace(/[ -]+/g, "_");
  const isSuperAdmin = Boolean(currentUser?.is_super_admin || normalizedRole === "super_admin" || normalizedRole === "super_administrator" || normalizedUserType === "super_admin");
  const permissionNames = new Set((currentUser?.role?.permissions || currentUser?.permissions || []).map((row) => String(row?.name || row?.permission?.name || row || "").trim().toLowerCase()));
  const canAny = (...names) => !currentUser || isSuperAdmin || names.some((name) => permissionNames.has(String(name).toLowerCase()));
  const allowedTabs = [
    canAny("purchase_orders", "view_purchase_orders_page") && "orders",
    canAny("view_purchase_receipts", "receive_stock", "purchase_orders") && "receipts",
    canAny("purchase_returns", "view_purchase_returns_page") && "returns",
    canAny("supplier_invoices", "view_supplier_invoices_page") && "invoices",
    canAny("view_supplier_payments", "supplier_payments", "supplier_invoices") && "payments",
  ].filter(Boolean);

  const baseCurrency =
    currencies.find((row) => row?.is_base_currency)?.code ||
    currencies[0]?.code ||
    currencies[0]?.currency_code ||
    "USD";

  const supplierById = useMemo(
    () => Object.fromEntries((suppliers || []).map((row) => [row.id, row])),
    [suppliers]
  );

  const branchById = useMemo(
    () => Object.fromEntries((branches || []).map((row) => [row.id, row])),
    [branches]
  );

  const productById = useMemo(
    () => Object.fromEntries((products || []).map((row) => [row.id, row])),
    [products]
  );

  const activeRows =
    activeTab === "orders"
      ? orders
      : activeTab === "receipts"
        ? receipts
        : activeTab === "returns"
          ? returns
          : activeTab === "invoices" ? invoices : payments;

  const activeMeta =
    activeTab === "orders"
      ? metaOf(ordersResponse)
      : activeTab === "receipts"
        ? metaOf(receiptsResponse)
        : activeTab === "returns"
          ? metaOf(returnsResponse)
          : activeTab === "invoices" ? metaOf(invoicesResponse) : metaOf(paymentsResponse);

  const activeTitle =
    activeTab === "orders"
      ? "Purchase Orders"
      : activeTab === "receipts"
        ? "Goods Received"
        : activeTab === "returns"
          ? "Purchase Returns"
          : activeTab === "invoices" ? "Supplier Invoices" : "Supplier Payments";

  const activeDocType =
    activeTab === "orders"
      ? "order"
      : activeTab === "receipts"
        ? "receipt"
        : activeTab === "returns"
          ? "return"
          : activeTab === "invoices" ? "invoice" : "payment";

  const canCreateActive = activeTab === "orders"
    ? canAny("purchase_orders")
    : activeTab === "receipts"
      ? canAny("receive_stock", "purchase_orders")
      : activeTab === "returns"
        ? canAny("purchase_returns")
        : activeTab === "invoices"
          ? canAny("supplier_invoices")
          : canAny("supplier_payments");

  const showNotice = (message) => {
    setNotice(message);
    window.setTimeout(() => setNotice(""), 3500);
  };

  const loadLookups = async () => {
    try {
      const [companyRes, suppliersRes, currenciesRes, productsRes, warehousesRes] =
        await Promise.all([
          getAuthorisedCompany(),
          getSuppliers({ per_page: 500, is_active: true }),
          getCurrencies({ per_page: 200, is_active: true }),
          getProducts({ per_page: 500, is_active: true }),
          getInventoryWarehouses(),
        ]);

      const companyPayload = companyRes?.data || companyRes;
      const companyInfo =
        companyPayload?.company ||
        companyPayload?.data?.company ||
        companyPayload?.companyInfo ||
        companyPayload;

      setBranches(companyInfo?.branches || companyPayload?.branches || []);
      setSuppliers(rowsOf(suppliersRes));
      setCurrencies(rowsOf(currenciesRes));
      setProducts(purchasableProductsOnly(productsRes));
      setWarehouses(Array.isArray(warehousesRes) ? warehousesRes.filter((row) => row.is_active !== false) : rowsOf(warehousesRes));
    } catch (err) {
      showNotice(errorMessage(err, "Lookup loading failed."));
    }
  };

  const loadAll = async (nextFilters = filters) => {
    try {
      setLoading(true);
      const requests = [
        ["overview", true, getPurchasingOverview, (response) => setPurchasingOverview(response?.data || response || {})],
        ["orders", canAny("purchase_orders", "view_purchase_orders_page"), getPurchaseOrders, setOrdersResponse],
        ["receipts", canAny("view_purchase_receipts", "receive_stock", "purchase_orders"), getPurchaseReceipts, setReceiptsResponse],
        ["returns", canAny("purchase_returns", "view_purchase_returns_page"), getPurchaseReturns, setReturnsResponse],
        ["invoices", canAny("supplier_invoices", "view_supplier_invoices_page"), getSupplierInvoices, setInvoicesResponse],
        ["payments", canAny("view_supplier_payments", "supplier_payments", "supplier_invoices"), getSupplierPayments, setPaymentsResponse],
      ];
      const enabled = requests.filter(([, allowed]) => allowed);
      const results = await Promise.allSettled(enabled.map(([, , loader]) => loader(nextFilters)));
      results.forEach((result, index) => {
        if (result.status === "fulfilled") enabled[index][3](result.value);
      });
      const failed = results.find((result) => result.status === "rejected");
      if (failed) showNotice(errorMessage(failed.reason, "One permitted purchase register failed to load."));
    } catch (err) {
      showNotice(errorMessage(err, "Purchases failed to load."));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (!open) return;

    setActiveTab(allowedTabs.includes(initialTab) ? initialTab : (allowedTabs[0] || "orders"));
    loadLookups();
    loadAll();
  }, [open]);

  useEffect(() => {
    if (!open) setQuickReceiveOpen(false);
  }, [open]);

  useEffect(() => {
    if (!open) return;

    setPoForm((prev) => ({
      ...prev,
      currency_code: prev.currency_code || baseCurrency,
      lines: prev.lines.map((line) => ({
        ...line,
        currency_code: line.currency_code || baseCurrency,
      })),
    }));

    setReturnForm((prev) => ({
      ...prev,
      currency_code: prev.currency_code || baseCurrency,
      lines: prev.lines.map((line) => ({
        ...line,
        currency_code: line.currency_code || baseCurrency,
      })),
    }));

    setInvoiceForm((prev) => ({
      ...prev,
      currency_code: prev.currency_code || baseCurrency,
      lines: prev.lines.map((line) => ({
        ...line,
        currency_code: line.currency_code || baseCurrency,
      })),
    }));
  }, [baseCurrency, open]);

  const openNewForm = () => {
    setFormValidation({ message: "", fields: {} });
    if (activeTab === "receipts") {
      setQuickReceiveOpen(true);
      return;
    }

    if (activeTab === "orders") {
      setPoForm({ ...emptyPoForm(), currency_code: baseCurrency, status: "approved", exchange_rate: 1, shipping_total: 0 });
      setFormModal("order");
      return;
    }

    if (activeTab === "returns") {
      setReturnForm({ ...emptyReturnForm(), currency_code: baseCurrency });
      setFormModal("return");
      return;
    }

    if (activeTab === "payments") {
      setPaymentForm({ ...emptyPaymentForm(), currency_code: baseCurrency });
      setFormModal("payment");
      return;
    }

    setInvoiceForm({ ...emptyInvoiceForm(), currency_code: baseCurrency });
    setFormModal("invoice");
  };

  const updateLine = (form, setForm, index, key, value) => {
    setForm((prev) => {
      const lines = [...prev.lines];
      const next = { ...lines[index], [key]: value };

      if (key === "product_id") {
        const product = productById[value];

        next.product_name = getProductName(product);
        next.product_code = product?.code || "";
        next.sku = product?.sku || "";
        next.description = getProductName(product);
        next.supplier_id =
          next.supplier_id || product?.supplier_id || prev.supplier_id || "";

        const cost = Array.isArray(product?.costs) ? product.costs[0] : null;

        next.unit_cost = Number(
          cost?.last_cost ||
            cost?.average_cost ||
            product?.cost ||
            next.unit_cost ||
            0
        );

        next.currency_code = cost?.currency || prev.currency_code || baseCurrency;
      }

      lines[index] = next;

      return { ...prev, lines };
    });
  };

  const addLine = (setForm) => {
    setForm((prev) => ({
      ...prev,
      lines: [
        ...prev.lines,
        { ...emptyLine(), currency_code: prev.currency_code || baseCurrency },
      ],
    }));
  };

  const removeLine = (setForm, index) => {
    setForm((prev) => {
      const nextLines = prev.lines.filter((_, rowIndex) => rowIndex !== index);

      return {
        ...prev,
        lines:
          nextLines.length > 0
            ? nextLines
            : [
                {
                  ...emptyLine(),
                  currency_code: prev.currency_code || baseCurrency,
                },
              ],
      };
    });
  };

  const cleanPoPayload = (form) => ({
    ...form,
    status: "approved",
    exchange_rate: 1,
    shipping_total: 0,
    lines: form.lines.map((line) => ({
      ...line,
      supplier_id: line.supplier_id || form.supplier_id || null,
      ordered_quantity: Number(line.ordered_quantity || line.quantity || 0),
      unit_cost: Number(line.unit_cost || 0),
      discount_amount: Number(line.discount_amount || 0),
      tax_amount: Number(line.tax_amount || 0),
      currency_code: line.currency_code || form.currency_code,
    })),
  });

  const cleanQtyPayload = (form) => ({
    ...form,
    lines: form.lines.map((line) => ({
      ...line,
      supplier_id: line.supplier_id || form.supplier_id || null,
      quantity: Number(line.quantity || line.ordered_quantity || 0),
      unit_cost: Number(line.unit_cost || 0),
      discount_amount: Number(line.discount_amount || 0),
      tax_amount: Number(line.tax_amount || 0),
      currency_code: line.currency_code || form.currency_code,
    })),
  });

  const savePo = async () => {
    try {
      setLoading(true);
      setFormValidation({ message: "", fields: {} });

      const res = await savePurchaseOrder(cleanPoPayload(poForm));
      const saved = res?.data || res;

      showNotice(res?.message || "Purchase order saved.");
      setPoForm({ ...emptyPoForm(), currency_code: baseCurrency, status: "approved", exchange_rate: 1, shipping_total: 0 });
      setFormModal(null);
      await loadAll();

      if (saved?.id) {
        await downloadDocument("order", saved, false);
      }
    } catch (err) {
      const details = errorDetails(err, "Purchase order failed.");
      setFormValidation(details);
      showNotice(details.message);
    } finally {
      setLoading(false);
    }
  };

  const receivePo = async (order) => {
    try {
      setLoading(true);
      setOpenActionId("");

      const fullOrder = await fetchFullDocument("order", order);
      const pendingLines = buildReceiveRowsFromOrder(fullOrder);

      if (!pendingLines.length) {
        showNotice("Nothing pending to receive for this order.");
        return;
      }

      setReceiveModal({
        order: fullOrder,
        received_date: today(),
        warehouse_id: "",
        notes: "",
        lines: pendingLines,
      });
      setReceiveValidation({ message: "", fields: {} });
    } catch (err) {
      showNotice(errorMessage(err, "Could not open receive goods screen."));
    } finally {
      setLoading(false);
    }
  };

  const updateReceiveLine = (index, key, value) => {
    setReceiveModal((prev) => {
      if (!prev) return prev;

      const lines = [...prev.lines];
      lines[index] = {
        ...lines[index],
        [key]: value,
      };

      return {
        ...prev,
        lines,
      };
    });
  };

  const confirmReceivePo = async () => {
    if (!receiveModal?.order) return;
    if (!receiveModal.warehouse_id) {
      setReceiveValidation({
        message: "Select the warehouse receiving these products.",
        fields: { warehouse_id: "The receiving warehouse field is required." },
      });
      return;
    }

    const invalidLine = receiveModal.lines.find((line) => {
      const qty = Number(line.quantity || 0);
      return Number.isNaN(qty) || qty < 0;
    });

    if (invalidLine) {
      setReceiveValidation({ message: "Received quantity cannot be negative.", fields: {} });
      return;
    }

    const missingTracking = receiveModal.lines.find((line) => Number(line.quantity || 0) > 0 && (
      (line.source_line?.product?.batch_tracking_enabled && !String(line.lot_number || "").trim()) ||
      (line.source_line?.product?.expiry_tracking_enabled && !line.expires_at)
    ));
    if (missingTracking) {
      setReceiveValidation({ message: `Enter the required lot number and expiry date for ${missingTracking.product_name}.`, fields: {} });
      return;
    }

    const payload = {
      branch_id: receiveModal.order.branch_id,
      purchase_order_id: receiveModal.order.id,
      supplier_id: receiveModal.order.supplier_id,
      received_date: receiveModal.received_date || today(),
      currency_code: receiveModal.order.currency_code,
      notes: receiveModal.notes || null,
      lines: receiveModal.lines
        .map((line) => ({
          purchase_order_line_id: line.purchase_order_line_id,
          product_id: line.product_id,
          supplier_id: line.supplier_id || receiveModal.order.supplier_id,
          quantity: Number(line.quantity || 0),
          unit_cost: Number(line.unit_cost || 0),
          currency_code: line.currency_code || receiveModal.order.currency_code,
          discount_amount: Number(line.discount_amount || 0),
          tax_amount: Number(line.tax_amount || 0),
          lot_number: line.lot_number || null,
          manufactured_at: line.manufactured_at || null,
          expires_at: line.expires_at || null,
          warehouse_id: receiveModal.warehouse_id,
          notes: line.notes || "",
        }))
        .filter((line) => line.quantity > 0),
    };

    if (!payload.lines.length) {
      setReceiveValidation({ message: "Enter at least one received quantity before posting goods received.", fields: {} });
      return;
    }

    try {
      setLoading(true);
      setReceiveValidation({ message: "", fields: {} });

      const res = await receivePurchaseOrder(receiveModal.order.id, payload);
      const saved = res?.data || res;

      showNotice(res?.message || "Goods received.");
      setReceiveModal(null);
      await loadAll();

      if (saved?.id) {
        await downloadDocument("receipt", saved, false);
      }
    } catch (err) {
      const details = errorDetails(err, "Receiving failed.");
      setReceiveValidation(details);
      showNotice(details.message);
    } finally {
      setLoading(false);
    }
  };

  const saveReturn = async () => {
    try {
      setLoading(true);
      setFormValidation({ message: "", fields: {} });

      const res = await savePurchaseReturn(cleanQtyPayload(returnForm));
      const saved = res?.data || res;

      showNotice(res?.message || "Purchase return saved.");
      setReturnForm({ ...emptyReturnForm(), currency_code: baseCurrency });
      setFormModal(null);
      await loadAll();

      if (saved?.id) {
        await downloadDocument("return", saved, false);
      }
    } catch (err) {
      const details = errorDetails(err, "Purchase return failed.");
      setFormValidation(details);
      showNotice(details.message);
    } finally {
      setLoading(false);
    }
  };

  const saveInvoice = async () => {
    if (!String(invoiceForm.invoice_number || "").trim()) {
      setFormValidation({
        message: "The invoice number field is required.",
        fields: { invoice_number: "The invoice number field is required." },
      });
      return;
    }

    try {
      setLoading(true);
      setFormValidation({ message: "", fields: {} });

      const res = await saveSupplierInvoice(cleanQtyPayload(invoiceForm));
      const saved = res?.data || res;

      showNotice(res?.message || "Supplier invoice saved.");
      setInvoiceForm({ ...emptyInvoiceForm(), currency_code: baseCurrency });
      setFormModal(null);
      await loadAll();

      if (saved?.id) {
        await downloadDocument("invoice", saved, false);
      }
    } catch (err) {
      const details = errorDetails(err, "Supplier invoice failed.");
      setFormValidation(details);
      showNotice(details.message);
    } finally {
      setLoading(false);
    }
  };

  const savePayment = async () => {
    try {
      setLoading(true);
      const payload = { ...paymentForm, amount: Number(paymentForm.amount || 0), exchange_rate: Number(paymentForm.exchange_rate || 1), allocations: paymentForm.allocations.map((row) => ({ ...row, amount: Number(row.amount || 0) })) };
      const res = await saveSupplierPayment(payload);
      showNotice(res?.message || "Supplier payment posted.");
      setPaymentForm({ ...emptyPaymentForm(), currency_code: baseCurrency });
      setFormModal(null);
      await loadAll();
    } catch (err) { showNotice(errorMessage(err, "Supplier payment failed.")); }
    finally { setLoading(false); }
  };

  const reversePayment = (payment) => {
    if (!payment?.id) return;
    setPendingPaymentReversal(payment);
    setPaymentReversalReason("");
    setPaymentReversalValidation("");
  };

  const confirmPaymentReversal = async (event) => {
    event?.preventDefault?.();
    if (!pendingPaymentReversal?.id || loading) return;

    const reason = String(paymentReversalReason || "").trim();
    if (!reason) {
      setPaymentReversalValidation("Reason is required.");
      return;
    }

    try {
      setLoading(true);
      setPaymentReversalValidation("");
      const res = await reverseSupplierPayment(pendingPaymentReversal.id, reason);
      showNotice(res?.message || "Payment reversed.");
      setPendingPaymentReversal(null);
      await loadAll();
    } catch (err) {
      setPaymentReversalValidation(errorMessage(err, "Payment reversal failed."));
    } finally {
      setLoading(false);
    }
  };

  const payableInvoices = invoices.filter((invoice) => invoice.supplier_id === paymentForm.supplier_id && invoice.currency_code === paymentForm.currency_code && Number(invoice.balance_due) > 0);

  const fetchFullDocument = async (type, row) => {
    if (!row?.id) return row;

    if (type === "order") {
      const res = await getPurchaseOrder(row.id);
      return res?.data || res;
    }

    if (type === "receipt") {
      const res = await getPurchaseReceipt(row.id);
      return res?.data || res;
    }

    if (type === "return") {
      const res = await getPurchaseReturn(row.id);
      return res?.data || res;
    }

    const res = await getSupplierInvoice(row.id);
    return res?.data || res;
  };

  const viewDocument = async (type, row) => {
    try {
      setLoading(true);
      const full = await fetchFullDocument(type, row);
      setSelectedDocument({ ...full, __type: type });
    } catch (err) {
      showNotice(errorMessage(err, "Document failed to open."));
    } finally {
      setLoading(false);
    }
  };

  const downloadDocument = async (type, row, showToast = true) => {
    try {
      if (!row?.id) throw new Error("This document has not been saved yet.");
      await downloadPurchaseDocument(type, row.id, false);

      if (showToast) showNotice("Backend-generated PDF downloaded.");
    } catch (err) {
      showNotice(errorMessage(err, "PDF download failed."));
    }
  };

  const shareDocument = async (type, row) => {
    try {
      if (!row?.id) throw new Error("This document has not been saved yet.");
      const { blob, fileName, title } = await fetchPurchaseDocumentPdf(type, row.id);
      const file = new File([blob], fileName, { type: "application/pdf" });

      if (navigator.canShare && navigator.canShare({ files: [file] })) {
        await navigator.share({
          title,
          text: `${title} attached.`,
          files: [file],
        });

        showNotice("Document shared.");
        return;
      }

      await downloadPurchaseDocument(type, row.id, false);
      showNotice(
        "Sharing is not supported on this browser. The PDF was downloaded instead."
      );
    } catch (err) {
      showNotice(errorMessage(err, "PDF share failed."));
    }
  };

  const exportActive = async (format = "pdf") => {
    try {
      await downloadPurchaseRegister(activeTab, format, filters);
      showNotice(`${activeTitle} ${format.toUpperCase()} downloaded from the backend.`);
    } catch (err) {
      showNotice(errorMessage(err, `${activeTitle} export failed.`));
    }
  };

  const loadReceiptIntoReturn = async (receiptId) => {
    if (!receiptId) return;

    try {
      const res = await getPurchaseReceipt(receiptId);
      const receipt = res?.data || res;

      setReturnForm((prev) => ({
        ...prev,
        branch_id: receipt.branch_id || prev.branch_id,
        supplier_id: receipt.supplier_id || prev.supplier_id,
        purchase_order_id: receipt.purchase_order_id || prev.purchase_order_id,
        purchase_receipt_id: receipt.id,
        currency_code: receipt.currency_code || prev.currency_code,
        lines:
          Array.isArray(receipt.lines) && receipt.lines.length > 0
            ? receipt.lines.map((line) => ({
                ...emptyLine(),
                product_id: line.product_id || "",
                supplier_id: line.supplier_id || receipt.supplier_id || "",
                purchase_order_line_id: line.purchase_order_line_id || "",
                purchase_receipt_line_id: line.id || "",
                product_name: getLineProductTitle(line),
                product_code: getLineCode(line),
                sku: line.sku || line.product?.sku || "",
                quantity: "",
                unit_cost: Number(line.unit_cost || 0),
                currency_code:
                  line.currency_code || receipt.currency_code || baseCurrency,
                discount_amount: 0,
                tax_amount: Number(line.tax_amount || 0),
                notes: "",
              }))
            : [{ ...emptyLine(), currency_code: receipt.currency_code || baseCurrency }],
      }));
    } catch (err) {
      showNotice(errorMessage(err, "Could not load receipt lines."));
    }
  };

  const loadReceiptIntoInvoice = async (receiptId) => {
    if (!receiptId) return;

    try {
      const res = await getPurchaseReceipt(receiptId);
      const receipt = res?.data || res;

      setInvoiceForm((prev) => ({
        ...prev,
        branch_id: receipt.branch_id || prev.branch_id,
        supplier_id: receipt.supplier_id || prev.supplier_id,
        purchase_order_id: receipt.purchase_order_id || prev.purchase_order_id,
        purchase_receipt_id: receipt.id,
        currency_code: receipt.currency_code || prev.currency_code,
        lines:
          Array.isArray(receipt.lines) && receipt.lines.length > 0
            ? receipt.lines.map((line) => ({
                ...emptyLine(),
                product_id: line.product_id || "",
                supplier_id: line.supplier_id || receipt.supplier_id || "",
                purchase_order_line_id: line.purchase_order_line_id || "",
                purchase_receipt_line_id: line.id || "",
                purchase_receipt_id: receipt.id,
                product_name: getLineProductTitle(line),
                product_code: getLineCode(line),
                sku: line.sku || line.product?.sku || "",
                description: getLineProductTitle(line),
                quantity: Number(line.quantity || 0),
                unit_cost: Number(line.unit_cost || 0),
                currency_code:
                  line.currency_code || receipt.currency_code || baseCurrency,
                discount_amount: Number(line.discount_amount || 0),
                tax_amount: Number(line.tax_amount || 0),
                notes: "",
              }))
            : [{ ...emptyLine(), currency_code: receipt.currency_code || baseCurrency }],
      }));
    } catch (err) {
      showNotice(errorMessage(err, "Could not load receipt lines."));
    }
  };

  if (!open) return null;

  return (
    <FullScreenModal
      open={open}
      onClose={onClose}
      title="PURCHASES"
      subtitle="Purchase Orders · Goods Received · Returns · Supplier Invoices"
    >
      <div className="purchase-root">
        {notice && <div className="purchase-notice">{notice}</div>}

        <div className="purchase-shell">
          <div className="border-b border-[#BBC8C7] bg-[#EBF4F3] px-4 py-2">
            <div className="text-[10px] font-black uppercase tracking-[0.12em] text-[#004F4F]">Purchasing Workflow</div>
            <div className="mt-1 flex flex-wrap items-center gap-1 text-[10px] font-bold text-[#445656]">
              {["1. Plan & Order", "2. Receive & Batch", "3. Match Invoice", "4. Pay Supplier", "5. Return / Close"].map((step, index) => <React.Fragment key={step}><span className="border border-[#BBC9C9] bg-white px-3 py-1">{step}</span>{index < 4 && <span className="text-[#007474]">→</span>}</React.Fragment>)}
            </div>
          </div>
          <div className="grid shrink-0 grid-cols-2 gap-px border-b border-[#BBC8C7] bg-[#BBC8C7] md:grid-cols-4 xl:grid-cols-7">
            {[
              ["Open orders", purchasingOverview?.summary?.open_orders],
              ["Overdue", purchasingOverview?.summary?.overdue_orders],
              ["GRNs today", purchasingOverview?.summary?.goods_received_today],
              ["Received today", money(purchasingOverview?.summary?.goods_received_value_today, baseCurrency)],
              ["Open invoices", purchasingOverview?.summary?.open_supplier_invoices],
              ["Supplier balance", money(purchasingOverview?.summary?.supplier_balance_due, baseCurrency)],
              ["Returns this month", purchasingOverview?.summary?.returns_this_month],
            ].map(([label, value]) => <div key={label} className="bg-white px-3 py-2"><div className="text-[8px] font-black uppercase tracking-[.12em] text-[#627474]">{label}</div><div className="mt-1 truncate text-[14px] font-black text-[#004141]">{value ?? 0}</div></div>)}
          </div>
          <div className="purchase-toolbar">
            <div className="purchase-tabs">
              {allowedTabs.includes("orders") &&
              <TabButton
                active={activeTab === "orders"}
                onClick={() => setActiveTab("orders")}
                icon={<FileText className="h-3.5 w-3.5" />}
              >
                Purchase Orders
              </TabButton>}

              {allowedTabs.includes("receipts") &&
              <TabButton
                active={activeTab === "receipts"}
                onClick={() => setActiveTab("receipts")}
                icon={<PackageCheck className="h-3.5 w-3.5" />}
              >
                Goods Received
              </TabButton>}

              {allowedTabs.includes("returns") &&
              <TabButton
                active={activeTab === "returns"}
                onClick={() => setActiveTab("returns")}
                icon={<RotateCcw className="h-3.5 w-3.5" />}
              >
                Purchase Returns
              </TabButton>}

              {allowedTabs.includes("invoices") &&
              <TabButton
                active={activeTab === "invoices"}
                onClick={() => setActiveTab("invoices")}
                icon={<ReceiptText className="h-3.5 w-3.5" />}
              >
                Supplier Invoices
              </TabButton>}
              {allowedTabs.includes("payments") && <TabButton active={activeTab === "payments"} onClick={() => setActiveTab("payments")} icon={<ReceiptText className="h-3.5 w-3.5" />}>Supplier Payments</TabButton>}
            </div>

            <div className="purchase-actions">
              <button
                type="button"
                onClick={() => loadAll()}
                disabled={loading}
                className="toolbar-btn toolbar-btn-primary"
              >
                <RefreshCw
                  className={`h-3.5 w-3.5 ${loading ? "animate-spin" : ""}`}
                />
                Refresh
              </button>

              <button type="button" onClick={() => exportActive("pdf")} className="toolbar-btn">
                <Download className="h-3.5 w-3.5" />
                List PDF
              </button>

              <button type="button" onClick={() => exportActive("xlsx")} className="toolbar-btn">
                <Download className="h-3.5 w-3.5" />
                List Excel
              </button>

              <button
                type="button"
                onClick={openNewForm}
                disabled={!canCreateActive}
                className="toolbar-btn toolbar-btn-dark"
              >
                {activeTab === "receipts" ? <PackageCheck className="h-3.5 w-3.5" /> : <Plus className="h-3.5 w-3.5" />}
                {activeTab === "receipts" ? "Quick Receive" : "New Document"}
              </button>
            </div>
          </div>

          <Filters
            filters={filters}
            setFilters={setFilters}
            branches={branches}
            suppliers={suppliers}
            onApply={() => loadAll(filters)}
          />

          <div className="purchase-table-area">
            {activeTab === "payments" ? (
              <div className="h-full overflow-auto">
                <table className="w-full text-[11px]">
                  <thead>
                    <tr className="bg-[#094A4A] text-left text-white">
                      <th className="p-2">Payment</th><th>Supplier</th><th>Date</th><th>Method</th>
                      <th>Currency</th><th className="text-right">Amount</th><th>Status</th><th>Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    {payments.map((payment) => (
                      <tr key={payment.id} className="border-b">
                        <td className="p-2 font-bold">{payment.payment_number}</td>
                        <td>{payment.supplier_name}</td><td>{formatDate(payment.payment_date)}</td>
                        <td>{payment.payment_method}</td><td>{payment.currency_code}</td>
                        <td className="text-right">{number(payment.amount)}</td><td className="uppercase">{payment.status}</td>
                        <td>{payment.status === "posted" && canAny("reverse_supplier_payments", "supplier_invoices") && <button type="button" onClick={() => reversePayment(payment)} className="bg-[#B42318] px-2 py-1 text-white">Reverse</button>}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
                {!payments.length && <div className="p-8 text-center text-[#627474]">No supplier payments recorded.</div>}
              </div>
            ) :
            <DocumentTable
              title={activeTitle}
              rows={activeRows}
              meta={activeMeta}
              suppliers={supplierById}
              onView={(row) => viewDocument(activeDocType, row)}
              onReceive={receivePo}
              onDownload={(row) => downloadDocument(activeDocType, row)}
              onShare={(row) => shareDocument(activeDocType, row)}
              openActionId={openActionId}
              setOpenActionId={setOpenActionId}
              type={activeDocType}
              loading={loading}
            />}
          </div>
        </div>

        {formModal === "order" && (
          <PurchaseFormModal
            title="New Purchase Order"
            subtitle="Create a controlled supplier purchasing document."
            mode="order"
            form={poForm}
            setForm={setPoForm}
            branches={branches}
            suppliers={suppliers}
            currencies={currencies}
            products={products}
            updateLine={updateLine}
            addLine={addLine}
            removeLine={removeLine}
            onSave={savePo}
            onClose={() => { setFormModal(null); setFormValidation({ message: "", fields: {} }); }}
            loading={loading}
            validation={formValidation}
            onClearValidation={() => setFormValidation({ message: "", fields: {} })}
          />
        )}

        {formModal === "return" && (
          <PurchaseFormModal
            title="New Purchase Return"
            subtitle="Select a linked receipt to load received items, then enter quantities being returned."
            mode="return"
            form={returnForm}
            setForm={setReturnForm}
            branches={branches}
            suppliers={suppliers}
            currencies={currencies}
            products={products}
            orders={orders}
            receipts={receipts}
            updateLine={updateLine}
            addLine={addLine}
            removeLine={removeLine}
            onSave={saveReturn}
            onClose={() => { setFormModal(null); setFormValidation({ message: "", fields: {} }); }}
            loading={loading}
            onLoadReceipt={loadReceiptIntoReturn}
            validation={formValidation}
            onClearValidation={() => setFormValidation({ message: "", fields: {} })}
          />
        )}

        {formModal === "invoice" && (
          <PurchaseFormModal
            title="New Supplier Invoice"
            subtitle="Select a linked receipt to load goods received and capture invoice totals."
            mode="invoice"
            form={invoiceForm}
            setForm={setInvoiceForm}
            branches={branches}
            suppliers={suppliers}
            currencies={currencies}
            products={products}
            orders={orders}
            receipts={receipts}
            updateLine={updateLine}
            addLine={addLine}
            removeLine={removeLine}
            onSave={saveInvoice}
            onClose={() => { setFormModal(null); setFormValidation({ message: "", fields: {} }); }}
            loading={loading}
            onLoadReceipt={loadReceiptIntoInvoice}
            validation={formValidation}
            onClearValidation={() => setFormValidation({ message: "", fields: {} })}
          />
        )}

        <EnterpriseFormModal
          open={formModal === "payment"}
          title="Post Supplier Payment"
          onClose={() => !loading && setFormModal(null)}
          busy={loading}
        >
          <div className="flex h-full min-h-0 flex-col"><div className="grid min-h-0 flex-1 grid-cols-2 content-start gap-3 overflow-y-auto bg-[#F4F7F7] p-4">
            <select value={paymentForm.branch_id} onChange={(e) => setPaymentForm({ ...paymentForm, branch_id: e.target.value })} className="input-small"><option value="">No branch / Company</option>{branches.map((branch) => <option key={branch.id} value={branch.id}>{getBranchName(branch)}</option>)}</select>
            <select value={paymentForm.supplier_id} onChange={(e) => setPaymentForm({ ...paymentForm, supplier_id: e.target.value, allocations: [{ supplier_invoice_id: "", amount: "" }] })} className="input-small"><option value="">Select supplier</option>{suppliers.map((supplier) => <option key={supplier.id} value={supplier.id}>{getSupplierName(supplier)}</option>)}</select>
            <input type="date" value={paymentForm.payment_date} onChange={(e) => setPaymentForm({ ...paymentForm, payment_date: e.target.value })} className="input-small" />
            <select value={paymentForm.payment_method} onChange={(e) => setPaymentForm({ ...paymentForm, payment_method: e.target.value })} className="input-small"><option value="bank_transfer">Bank transfer</option><option value="cash">Cash</option><option value="card">Card</option><option value="mobile_money">Mobile money</option><option value="cheque">Cheque</option><option value="other">Other</option></select>
            <select value={paymentForm.currency_code} onChange={(e) => setPaymentForm({ ...paymentForm, currency_code: e.target.value, allocations: [{ supplier_invoice_id: "", amount: "" }] })} className="input-small">{currencies.map((currency) => <option key={getCurrencyCode(currency)} value={getCurrencyCode(currency)}>{getCurrencyCode(currency)}</option>)}</select>
            <input type="number" min="0.0001" step="0.0001" value={paymentForm.amount} onChange={(e) => setPaymentForm({ ...paymentForm, amount: e.target.value })} placeholder="Payment amount" className="input-small" />
            <input type="number" min="0.00000001" step="0.00000001" value={paymentForm.exchange_rate} onChange={(e) => setPaymentForm({ ...paymentForm, exchange_rate: e.target.value })} placeholder="Base exchange rate" className="input-small" />
            <input value={paymentForm.reference} onChange={(e) => setPaymentForm({ ...paymentForm, reference: e.target.value })} placeholder="Bank / cheque reference" className="input-small" />
            <div className="col-span-2 border bg-white p-3"><div className="mb-2 flex justify-between"><b>Invoice allocations</b><button type="button" onClick={() => setPaymentForm({ ...paymentForm, allocations: [...paymentForm.allocations, { supplier_invoice_id: "", amount: "" }] })} className="bg-[#007474] px-2 py-1 text-white">Add invoice</button></div>{paymentForm.allocations.map((allocation, index) => <div key={index} className="mb-2 grid grid-cols-[1fr_160px_70px] gap-2"><select value={allocation.supplier_invoice_id} onChange={(e) => { const rows = [...paymentForm.allocations]; rows[index] = { ...rows[index], supplier_invoice_id: e.target.value }; setPaymentForm({ ...paymentForm, allocations: rows }); }} className="input-small"><option value="">Select open invoice</option>{payableInvoices.map((invoice) => <option key={invoice.id} value={invoice.id}>{invoice.invoice_number} · Balance {number(invoice.balance_due)} {invoice.currency_code}</option>)}</select><input type="number" step="0.0001" min="0.0001" value={allocation.amount} onChange={(e) => { const rows = [...paymentForm.allocations]; rows[index] = { ...rows[index], amount: e.target.value }; setPaymentForm({ ...paymentForm, allocations: rows }); }} placeholder="Allocated amount" className="input-small" /><button type="button" onClick={() => setPaymentForm({ ...paymentForm, allocations: paymentForm.allocations.filter((_, rowIndex) => rowIndex !== index) })} className="border text-[#B42318]">Remove</button></div>)}</div>
            <textarea value={paymentForm.notes} onChange={(e) => setPaymentForm({ ...paymentForm, notes: e.target.value })} placeholder="Payment notes" rows="2" className="col-span-2 border p-2 text-[11px]" />
          </div><div className="flex justify-end gap-2 border-t p-3"><button type="button" onClick={() => setFormModal(null)} className="border px-4 py-2">Cancel</button><button type="button" disabled={loading || !paymentForm.supplier_id || !paymentForm.amount} onClick={savePayment} className="bg-[#027A48] px-4 py-2 font-bold text-white disabled:opacity-50">{loading ? "Posting..." : "Post Payment"}</button></div></div>
        </EnterpriseFormModal>

        <EnterpriseFormModal
          open={Boolean(pendingPaymentReversal)}
          title="Reverse Supplier Payment"
          subtitle={pendingPaymentReversal?.payment_number || ""}
          onClose={() => {
            if (loading) return;
            setPendingPaymentReversal(null);
            setPaymentReversalValidation("");
          }}
          busy={loading}
        >
          <form onSubmit={confirmPaymentReversal} className="flex h-full min-h-0 flex-col">
            <div className="min-h-0 flex-1 p-1">
              <label className="block">
                <span className="mb-1 block text-[11px] font-semibold uppercase text-[#637877]">Reason</span>
                <textarea
                  autoFocus
                  required
                  rows={6}
                  value={paymentReversalReason}
                  onChange={(event) => {
                    setPaymentReversalReason(event.target.value);
                    if (paymentReversalValidation) setPaymentReversalValidation("");
                  }}
                  className="w-full border border-[#ACB8B8] bg-white px-3 py-2 text-[13px] outline-none focus:border-[#069494]"
                />
              </label>
              {paymentReversalValidation && (
                <div role="alert" className="mt-3 whitespace-pre-line border border-[#FDA29B] bg-[#FEF3F2] px-3 py-2 text-[12px] font-semibold text-[#B42318]">
                  {paymentReversalValidation}
                </div>
              )}
            </div>
            <div className="flex justify-end gap-2 border-t border-[#CFD9D8] bg-[#F7FAFC] p-3">
              <button type="button" disabled={loading} onClick={() => setPendingPaymentReversal(null)} className="h-9 border border-[#ACB8B8] bg-white px-4 text-[11px] font-semibold text-[#324443] disabled:opacity-50">Cancel</button>
              <button type="submit" disabled={loading || !paymentReversalReason.trim()} className="h-9 bg-[#B42318] px-5 text-[11px] font-semibold text-white disabled:opacity-50">{loading ? "Reversing..." : "Reverse Payment"}</button>
            </div>
          </form>
        </EnterpriseFormModal>

        {selectedDocument && (
          <DocumentDetails
            document={selectedDocument}
            suppliers={supplierById}
            onClose={() => setSelectedDocument(null)}
            onDownload={() =>
              downloadDocument(selectedDocument.__type, selectedDocument)
            }
            onShare={() => shareDocument(selectedDocument.__type, selectedDocument)}
          />
        )}

        {receiveModal && (
          <ReceiveGoodsModal
            form={receiveModal}
            suppliers={supplierById}
            warehouses={warehouses}
            onChange={setReceiveModal}
            onLineChange={updateReceiveLine}
            onClose={() => setReceiveModal(null)}
            onPost={confirmReceivePo}
            loading={loading}
            validation={receiveValidation}
            onClearValidation={() => setReceiveValidation({ message: "", fields: {} })}
          />
        )}

        <ReceiveStockModal
          open={quickReceiveOpen}
          onClose={() => { setQuickReceiveOpen(false); loadAll(); }}
          currentBranchId={filters.branch_id || currentUser?.branch_id || ""}
          currentBranchName={branchById[filters.branch_id || currentUser?.branch_id]?.branch_name || currentUser?.branch?.branch_name || currentUser?.branch?.name || ""}
          onSaved={() => loadAll()}
        />

        <PurchaseStyles />
      </div>
    </FullScreenModal>
  );
}

function TabButton({ active, onClick, children, icon }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={`purchase-tab ${active ? "purchase-tab-active" : ""}`}
    >
      {icon}
      <span>{children}</span>
    </button>
  );
}

function Filters({ filters, setFilters, branches, suppliers, onApply }) {
  const update = (key, value) => {
    setFilters((prev) => ({ ...prev, [key]: value, page: 1 }));
  };

  return (
    <div className="filters-card">
      <div className="filters-grid">
        <Field label="From">
          <input
            type="date"
            value={filters.date_from}
            onChange={(e) => update("date_from", e.target.value)}
            className="input"
          />
        </Field>

        <Field label="To">
          <input
            type="date"
            value={filters.date_to}
            onChange={(e) => update("date_to", e.target.value)}
            className="input"
          />
        </Field>

        <Field label="Branch">
          <select
            value={filters.branch_id}
            onChange={(e) => update("branch_id", e.target.value)}
            className="input"
          >
            <option value="">All Branches</option>
            {branches.map((branch) => (
              <option key={branch.id} value={branch.id}>
                {getBranchName(branch)}
              </option>
            ))}
          </select>
        </Field>

        <Field label="Supplier">
          <select
            value={filters.supplier_id}
            onChange={(e) => update("supplier_id", e.target.value)}
            className="input"
          >
            <option value="">All Suppliers</option>
            {suppliers.map((supplier) => (
              <option key={supplier.id} value={supplier.id}>
                {getSupplierName(supplier)}
              </option>
            ))}
          </select>
        </Field>


        <Field label="Search">
          <input
            value={filters.q}
            onChange={(e) => update("q", e.target.value)}
            className="input"
            placeholder="Number, supplier, product..."
          />
        </Field>

        <button type="button" onClick={onApply} className="filter-apply">
          <Search className="h-3.5 w-3.5" />
          Apply
        </button>
      </div>
    </div>
  );
}

function Field({ label, children, error }) {
  return (
    <label className={`field ${error ? "field-has-error" : ""}`}>
      <div className="label">{label}</div>
      {children}
      {error && <div className="field-error" role="alert">{error}</div>}
    </label>
  );
}

function ValidationBanner({ validation }) {
  if (!validation?.message) return null;

  const fieldMessages = Object.values(validation.fields || {}).filter(Boolean);
  const messages = [...new Set(fieldMessages.length ? fieldMessages : String(validation.message).split("\n").filter(Boolean))];

  return (
    <div className="validation-banner" role="alert" aria-live="assertive">
      <strong>Please correct the following:</strong>
      <ul>{messages.map((message) => <li key={message}>{message}</li>)}</ul>
    </div>
  );
}

function SearchableProductSelect({
  value,
  products = [],
  onChange,
  placeholder = "Search product...",
}) {
  const [open, setOpen] = useState(false);
  const [search, setSearch] = useState("");
  const [menuStyle, setMenuStyle] = useState({});
  const wrapRef = useRef(null);
  const inputRef = useRef(null);
  const menuId = useMemo(
    () => `purchase-product-search-menu-${Math.random().toString(36).slice(2)}`,
    []
  );

  const availableProducts = useMemo(
    () => (products || []).filter((product) => !isFinishedGoodProduct(product)),
    [products]
  );

  const selectedProduct = availableProducts.find(
    (product) => String(product.id) === String(value)
  );

  const filteredProducts = useMemo(() => {
    const q = search.trim().toLowerCase();

    if (!q) return availableProducts.slice(0, 80);

    return availableProducts
      .filter((product) => {
        const name = String(product?.name || product?.product_name || "").toLowerCase();
        const code = String(product?.code || "").toLowerCase();
        const sku = String(product?.sku || "").toLowerCase();
        const barcode = String(product?.barcode || "").toLowerCase();

        return (
          name.includes(q) ||
          code.includes(q) ||
          sku.includes(q) ||
          barcode.includes(q)
        );
      })
      .slice(0, 80);
  }, [availableProducts, search]);

  const updateMenuPosition = () => {
    if (!wrapRef.current) return;

    const rect = wrapRef.current.getBoundingClientRect();
    const menuWidth = Math.max(rect.width, 540);
    const safeWidth = Math.min(menuWidth, window.innerWidth - 16);
    const availableBelow = window.innerHeight - rect.bottom;
    const availableAbove = rect.top;
    const openUp = availableBelow < 320 && availableAbove > availableBelow;

    setMenuStyle({
      position: "fixed",
      left: `${Math.max(8, Math.min(rect.left, window.innerWidth - safeWidth - 8))}px`,
      top: openUp ? "auto" : `${rect.bottom + 4}px`,
      bottom: openUp ? `${window.innerHeight - rect.top + 4}px` : "auto",
      width: `${safeWidth}px`,
      zIndex: 999999,
    });
  };

  const openMenu = () => {
    setOpen(true);
    setSearch("");

    window.setTimeout(() => {
      updateMenuPosition();
      inputRef.current?.focus();
    }, 0);
  };

  useEffect(() => {
    if (!open) return;

    updateMenuPosition();

    const handleClickOutside = (event) => {
      const menu = document.getElementById(menuId);

      if (
        wrapRef.current &&
        !wrapRef.current.contains(event.target) &&
        menu &&
        !menu.contains(event.target)
      ) {
        setOpen(false);
      }
    };

    const handlePositionChange = () => updateMenuPosition();

    document.addEventListener("mousedown", handleClickOutside);
    window.addEventListener("resize", handlePositionChange);
    window.addEventListener("scroll", handlePositionChange, true);

    return () => {
      document.removeEventListener("mousedown", handleClickOutside);
      window.removeEventListener("resize", handlePositionChange);
      window.removeEventListener("scroll", handlePositionChange, true);
    };
  }, [open, menuId]);

  const menu = open ? (
    <div id={menuId} className="search-product-menu" style={menuStyle}>
      <input
        ref={inputRef}
        value={search}
        onChange={(event) => setSearch(event.target.value)}
        className="search-product-input"
        placeholder={placeholder}
      />

      <div className="search-product-list">
        {filteredProducts.length === 0 ? (
          <div className="search-product-empty">No products found.</div>
        ) : (
          filteredProducts.map((product) => (
            <button
              key={product.id}
              type="button"
              onClick={() => {
                onChange(product.id);
                setOpen(false);
              }}
              className={`search-product-option ${
                String(value) === String(product.id)
                  ? "search-product-option-active"
                  : ""
              }`}
            >
              <span>{getProductName(product)}</span>
              <small>
                Code: {product.code || "-"} · SKU: {product.sku || "-"} · Barcode:{" "}
                {product.barcode || "-"}
              </small>
            </button>
          ))
        )}
      </div>
    </div>
  ) : null;

  return (
    <div ref={wrapRef} className="search-product-wrap">
      <button type="button" onClick={openMenu} className="search-product-button">
        <span>
          {selectedProduct
            ? `${selectedProduct.code || selectedProduct.sku || "-"} · ${getProductName(
                selectedProduct
              )}`
            : "Select product"}
        </span>
      </button>

      {open && createPortal(menu, document.body)}
    </div>
  );
}

function PurchaseFormModal({
  title,
  subtitle,
  mode,
  form,
  setForm,
  branches,
  suppliers,
  currencies,
  products,
  orders = [],
  receipts = [],
  updateLine,
  addLine,
  removeLine,
  onSave,
  onClose,
  loading,
  onLoadReceipt,
  validation = { message: "", fields: {} },
  onClearValidation,
}) {
  const qtyKey = mode === "order" ? "ordered_quantity" : "quantity";

  const subtotal = form.lines.reduce(
    (sum, line) => sum + Number(line[qtyKey] || 0) * Number(line.unit_cost || 0),
    0
  );

  const discount = form.lines.reduce(
    (sum, line) => sum + Number(line.discount_amount || 0),
    0
  );

  const tax = form.lines.reduce(
    (sum, line) => sum + Number(line.tax_amount || 0),
    0
  );

  const total = subtotal - discount + tax;

  const setValue = (key, value) => {
    onClearValidation?.();
    setForm((prev) => ({ ...prev, [key]: value }));
  };

  const dateKey =
    mode === "order"
      ? "order_date"
      : mode === "return"
        ? "return_date"
        : "invoice_date";

  return (
    <div className="form-backdrop">
      <div className="form-modal">
        <div className="form-header">
          <div>
            <h2>{title}</h2>
            <p>{subtitle}</p>
          </div>

          <button type="button" onClick={onClose} className="icon-close">
            <X className="h-4 w-4" />
          </button>
        </div>

        <div className="form-body">
          <ValidationBanner validation={validation} />
          <section className="form-section">
            <div className="section-title">Document Details</div>

            <div className="form-grid">
              <Field label="Branch" error={validation.fields?.branch_id}>
                <select
                  value={form.branch_id}
                  onChange={(e) => setValue("branch_id", e.target.value)}
                  className="input"
                >
                  <option value="">Select Branch</option>
                  {branches.map((branch) => (
                    <option key={branch.id} value={branch.id}>
                      {getBranchName(branch)}
                    </option>
                  ))}
                </select>
              </Field>

              <Field label="Supplier" error={validation.fields?.supplier_id}>
                <select
                  value={form.supplier_id}
                  onChange={(e) => setValue("supplier_id", e.target.value)}
                  className="input"
                >
                  <option value="">Select Supplier</option>
                  {suppliers.map((supplier) => (
                    <option key={supplier.id} value={supplier.id}>
                      {getSupplierName(supplier)}
                    </option>
                  ))}
                </select>
              </Field>

              {mode === "order" && (
                <Field label="PO Number" error={validation.fields?.po_number}>
                  <input
                    value={form.po_number || ""}
                    onChange={(e) => setValue("po_number", e.target.value)}
                    className="input"
                    placeholder="Auto generated if blank"
                  />
                </Field>
              )}

              {mode === "invoice" && (
                <Field label="Invoice Number" error={validation.fields?.invoice_number}>
                  <input
                    value={form.invoice_number || ""}
                    onChange={(e) => setValue("invoice_number", e.target.value)}
                    className="input"
                    placeholder="Supplier invoice number"
                    aria-invalid={Boolean(validation.fields?.invoice_number)}
                    autoFocus={Boolean(validation.fields?.invoice_number)}
                  />
                </Field>
              )}

              <Field
                label={
                  mode === "order"
                    ? "Order Date"
                    : mode === "return"
                      ? "Return Date"
                      : "Invoice Date"
                }
                error={validation.fields?.[dateKey]}
              >
                <input
                  type="date"
                  value={form[dateKey] || ""}
                  onChange={(e) => setValue(dateKey, e.target.value)}
                  className="input"
                />
              </Field>

              {mode === "order" && (
                <Field label="Expected Date" error={validation.fields?.expected_date}>
                  <input
                    type="date"
                    value={form.expected_date || ""}
                    onChange={(e) => setValue("expected_date", e.target.value)}
                    className="input"
                  />
                </Field>
              )}

              {mode === "invoice" && (
                <Field label="Due Date" error={validation.fields?.due_date}>
                  <input
                    type="date"
                    value={form.due_date || ""}
                    onChange={(e) => setValue("due_date", e.target.value)}
                    className="input"
                  />
                </Field>
              )}

              <Field label="Currency" error={validation.fields?.currency_code}>
                <select
                  value={form.currency_code}
                  onChange={(e) => setValue("currency_code", e.target.value)}
                  className="input"
                >
                  <option value="">Select Currency</option>
                  {currencies.map((currency) => (
                    <option
                      key={currency.id || getCurrencyCode(currency)}
                      value={getCurrencyCode(currency)}
                    >
                      {getCurrencyCode(currency)}
                    </option>
                  ))}
                </select>
              </Field>

              {(mode === "return" || mode === "invoice") && (
                <Field label="Linked Purchase Order" error={validation.fields?.purchase_order_id}>
                  <select
                    value={form.purchase_order_id || ""}
                    onChange={(e) => setValue("purchase_order_id", e.target.value)}
                    className="input"
                  >
                    <option value="">None</option>
                    {orders.map((order) => (
                      <option key={order.id} value={order.id}>
                        {order.po_number || order.id}
                      </option>
                    ))}
                  </select>
                </Field>
              )}

              {(mode === "return" || mode === "invoice") && (
                <Field label="Linked Receipt" error={validation.fields?.purchase_receipt_id}>
                  <select
                    value={form.purchase_receipt_id || ""}
                    onChange={(e) => {
                      setValue("purchase_receipt_id", e.target.value);
                      onLoadReceipt?.(e.target.value);
                    }}
                    className="input"
                  >
                    <option value="">Select Receipt</option>
                    {receipts.map((receipt) => (
                      <option key={receipt.id} value={receipt.id}>
                        {receipt.grn_number || receipt.receipt_number || receipt.id}
                      </option>
                    ))}
                  </select>
                </Field>
              )}
            </div>
          </section>

          <section className="form-section form-section-lines">
            <div className="section-header">
              <div className="section-title">Product Lines</div>

              <button
                type="button"
                onClick={() => addLine(setForm)}
                className="line-add-btn"
              >
                <Plus className="h-3.5 w-3.5" />
                Add Line
              </button>
            </div>

            <LineEditor
              mode={mode}
              lines={form.lines}
              products={products}
              suppliers={suppliers}
              updateLine={(index, key, value) =>
                updateLine(form, setForm, index, key, value)
              }
              addLine={() => addLine(setForm)}
              removeLine={(index) => removeLine(setForm, index)}
            />
          </section>

          <section className="form-section">
            <div className="section-title">
              {mode === "return" ? "Return Reason / Notes" : "Notes"}
            </div>

            <div className="notes-grid">
              <Field label={mode === "return" ? "Return Reason" : "Notes"}>
                <textarea
                  value={mode === "return" ? form.reason || "" : form.notes || ""}
                  onChange={(e) =>
                    setValue(mode === "return" ? "reason" : "notes", e.target.value)
                  }
                  className="textarea"
                  placeholder={
                    mode === "return"
                      ? "Explain why these goods are being returned..."
                      : "Optional notes..."
                  }
                />
              </Field>

              {mode === "return" && (
                <Field label="Internal Notes">
                  <textarea
                    value={form.notes || ""}
                    onChange={(e) => setValue("notes", e.target.value)}
                    className="textarea"
                    placeholder="Optional internal notes..."
                  />
                </Field>
              )}

              <div className="totals-card">
                <div>
                  <span>Subtotal</span>
                  <strong>{money(subtotal, form.currency_code)}</strong>
                </div>

                <div>
                  <span>Discount</span>
                  <strong>{money(discount, form.currency_code)}</strong>
                </div>

                <div>
                  <span>Tax</span>
                  <strong>{money(tax, form.currency_code)}</strong>
                </div>

                <div className="grand-total">
                  <span>Total</span>
                  <strong>{money(total, form.currency_code)}</strong>
                </div>
              </div>
            </div>
          </section>
        </div>

        <div className="form-footer">
          <button type="button" onClick={onClose} className="modal-btn">
            Cancel
          </button>

          <button
            type="button"
            onClick={onSave}
            disabled={loading}
            className="modal-btn modal-btn-primary"
          >
            <Save className="h-3.5 w-3.5" />
            {loading ? "Saving..." : "Save Document"}
          </button>
        </div>
      </div>
    </div>
  );
}

function LineEditor({
  mode,
  lines,
  products,
  suppliers,
  updateLine,
  addLine,
  removeLine,
}) {
  const qtyKey = mode === "order" ? "ordered_quantity" : "quantity";

  return (
    <div className="line-editor">
      <div className="line-header">
        <div>Product</div>
        <div>Qty</div>
        <div>Unit Cost</div>
        <div>Discount</div>
        <div>Tax</div>
        <div>Currency</div>
        <div>Total</div>
        <div>Notes</div>
        <div>Action</div>
      </div>

      <div className="line-body">
        {lines.map((line, index) => {
          const total =
            Number(line[qtyKey] || 0) * Number(line.unit_cost || 0) -
            Number(line.discount_amount || 0) +
            Number(line.tax_amount || 0);

          return (
            <div key={index} className="line-row">
              <div className="line-cell">
                <SearchableProductSelect
                  value={line.product_id}
                  products={products}
                  onChange={(productId) => updateLine(index, "product_id", productId)}
                />
              </div>

              <div className="line-cell">
                <input
                  type="number"
                  step="0.001"
                  value={line[qtyKey]}
                  onChange={(e) => updateLine(index, qtyKey, e.target.value)}
                  className="input text-right"
                />
              </div>

              <div className="line-cell">
                <input
                  type="number"
                  step="0.01"
                  value={line.unit_cost}
                  onChange={(e) => updateLine(index, "unit_cost", e.target.value)}
                  className="input text-right"
                />
              </div>

              <div className="line-cell">
                <input
                  type="number"
                  step="0.01"
                  value={line.discount_amount}
                  onChange={(e) =>
                    updateLine(index, "discount_amount", e.target.value)
                  }
                  className="input text-right"
                />
              </div>

              <div className="line-cell">
                <input
                  type="number"
                  step="0.01"
                  value={line.tax_amount}
                  onChange={(e) => updateLine(index, "tax_amount", e.target.value)}
                  className="input text-right"
                />
              </div>

              <div className="line-cell">
                <input
                  value={line.currency_code || ""}
                  onChange={(e) => updateLine(index, "currency_code", e.target.value)}
                  className="input"
                />
              </div>

              <div className="line-total">{money(total, line.currency_code)}</div>

              <div className="line-cell">
                <input
                  value={line.notes || ""}
                  onChange={(e) => updateLine(index, "notes", e.target.value)}
                  className="input"
                  placeholder="Notes"
                />
              </div>

              <div className="line-cell">
                <button
                  type="button"
                  onClick={() => removeLine(index)}
                  className="remove-btn"
                >
                  Remove
                </button>
              </div>
            </div>
          );
        })}
      </div>

      <button type="button" onClick={addLine} className="line-add-bottom">
        <Plus className="h-3.5 w-3.5" />
        Add Line
      </button>
    </div>
  );
}

function DocumentTable({
  title,
  rows,
  meta,
  suppliers,
  onView,
  onReceive,
  onDownload,
  onShare,
  openActionId,
  setOpenActionId,
  type,
  loading,
}) {
  const pendingOrders =
    type === "order" ? rows.filter((row) => orderCanReceive(row)).length : 0;
  const columnCount = type === "receipt" ? 9 : 8;

  return (
    <div className="document-card">
      <div className="document-head">
        <div>
          <h3>{title}</h3>
          <span>
            {meta.total} records · page {meta.current_page} of {meta.last_page}
          </span>
        </div>

        {type === "order" && pendingOrders > 0 && (
          <div className="pending-receive-alert">
            {pendingOrders} purchase order(s) pending receive
          </div>
        )}
      </div>

      <div className="document-table-wrap">
        <table className="document-table">
          <thead>
            <tr>
              <th>Number</th>
              <th>Date</th>
              {type === "receipt" && <th>Receipt Source</th>}
              <th>Supplier</th>
              <th>Currency</th>
              <th className="text-right">Subtotal</th>
              <th className="text-right">Tax</th>
              <th className="text-right">Total</th>
              <th className="text-right">Actions</th>
            </tr>
          </thead>

          <tbody>
            {loading ? (
              <tr>
                <td colSpan={columnCount} className="empty-cell">
                  Loading records...
                </td>
              </tr>
            ) : rows.length === 0 ? (
              <tr>
                <td colSpan={columnCount} className="empty-cell">
                  No records found.
                </td>
              </tr>
            ) : (
              rows.map((row) => (
                <tr key={row.id}>
                  <td className="doc-number"><button type="button" onClick={() => onView(row)} className="font-black text-[#007474] underline decoration-dotted underline-offset-2">{getDocumentNumber(row)}</button></td>
                  <td><button type="button" onClick={() => onView(row)} className="text-[#324443] underline decoration-dotted underline-offset-2">{formatDate(getDocumentDate(row))}</button></td>
                  {type === "receipt" && <td><span className={`receipt-source-badge ${String(row?.source || "").toLowerCase() === "quick_receive" ? "receipt-source-quick" : "receipt-source-po"}`}>{getReceiptSourceLabel(row)}</span></td>}
                  <td>{getSupplierName(suppliers[row.supplier_id] || row.supplier)}</td>
                  <td>{row.currency_code || "-"}</td>
                  <td className="text-right">
                    {money(row.subtotal, row.currency_code)}
                  </td>
                  <td className="text-right">
                    {money(row.tax_total, row.currency_code)}
                  </td>
                  <td className="text-right doc-total">
                    {money(row.grand_total, row.currency_code)}
                  </td>
                  <td className="text-right">
                    <ActionMenu
                      open={openActionId === row.id}
                      onToggle={() =>
                        setOpenActionId(openActionId === row.id ? "" : row.id)
                      }
                      onClose={() => setOpenActionId("")}
                      onView={() => onView(row)}
                      onDownload={() => onDownload(row)}
                      onShare={() => onShare(row)}
                      onReceive={
                        type === "order" && orderCanReceive(row)
                          ? () => onReceive(row)
                          : null
                      }
                    />
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function ActionMenu({
  open,
  onToggle,
  onClose,
  onView,
  onDownload,
  onShare,
  onReceive,
}) {
  const ref = useRef(null);

  useEffect(() => {
    const close = (event) => {
      if (ref.current && !ref.current.contains(event.target)) onClose();
    };

    document.addEventListener("mousedown", close);
    return () => document.removeEventListener("mousedown", close);
  }, [onClose]);

  return (
    <div ref={ref} className="action-menu-wrap">
      <button type="button" onClick={onToggle} className="dots-btn">
        <MoreVertical className="h-4 w-4" />
      </button>

      {open && (
        <div className="action-menu">
          <button type="button" onClick={onView}>
            <Eye className="h-3.5 w-3.5" />
            View
          </button>

          <button type="button" onClick={onDownload}>
            <Download className="h-3.5 w-3.5" />
            Download PDF
          </button>

          <button type="button" onClick={onShare}>
            <Send className="h-3.5 w-3.5" />
            Share PDF
          </button>

          {onReceive && (
            <button type="button" onClick={onReceive}>
              <PackageCheck className="h-3.5 w-3.5" />
              Receive Goods
            </button>
          )}
        </div>
      )}
    </div>
  );
}

function StatusPill({ status }) {
  const clean = String(status || "unknown").toLowerCase();

  const cls =
    clean === "received" || clean === "posted" || clean === "paid"
      ? "status-good"
      : clean === "cancelled" || clean === "void"
        ? "status-bad"
        : clean === "draft"
          ? "status-muted"
          : "status-warn";

  return <span className={`status-pill ${cls}`}>{status || "Unknown"}</span>;
}

function ReceiveGoodsModal({
  form,
  suppliers,
  warehouses,
  onChange,
  onLineChange,
  onClose,
  onPost,
  loading,
  validation = { message: "", fields: {} },
  onClearValidation,
}) {
  const order = form.order || {};
  const lines = Array.isArray(form.lines) ? form.lines : [];

  const totalReceivedQty = lines.reduce(
    (sum, line) => sum + Number(line.quantity || 0),
    0
  );

  const totalCost = lines.reduce(
    (sum, line) => sum + Number(line.quantity || 0) * Number(line.unit_cost || 0),
    0
  );

  return (
    <div className="details-backdrop">
      <div className="receive-modal">
        <div className="receive-header">
          <div>
            <h2>Receive Goods</h2>
            <p>
              {getDocumentNumber(order)} · {getSupplierName(suppliers[order.supplier_id] || order.supplier)}
            </p>
          </div>

          <button type="button" onClick={onClose} className="icon-close">
            <X className="h-4 w-4" />
          </button>
        </div>

        <ValidationBanner validation={validation} />

        <div className="receive-summary-strip">
          <SummaryBox label="PO Number" value={getDocumentNumber(order)} />
          <SummaryBox label="Currency" value={order.currency_code || "-"} />
          <SummaryBox label="Total Received Qty" value={number(totalReceivedQty, 3)} />
          <SummaryBox label="Estimated Value" value={money(totalCost, order.currency_code)} />
        </div>

        <div className="receive-controls">
          <Field label="Received Date">
            <input
              type="date"
              value={form.received_date || today()}
              onChange={(event) =>
                onChange((prev) => ({ ...prev, received_date: event.target.value }))
              }
              className="input"
            />
          </Field>

          <Field label="Receive Into Warehouse" error={validation.fields?.warehouse_id}>
            <select value={form.warehouse_id || ""} onChange={(event) => { onClearValidation?.(); onChange((prev) => ({ ...prev, warehouse_id: event.target.value })); }} className="input" aria-invalid={Boolean(validation.fields?.warehouse_id)}>
              <option value="">Select warehouse</option>
              {(warehouses || []).filter((warehouse) => !warehouse.branch_id || String(warehouse.branch_id) === String(order.branch_id)).map((warehouse) => <option key={warehouse.id} value={warehouse.id}>{warehouse.name} ({warehouse.code}){!warehouse.branch_id ? " · Company" : ""}</option>)}
            </select>
          </Field>

          <Field label="Receipt Notes">
            <input
              value={form.notes || ""}
              onChange={(event) =>
                onChange((prev) => ({ ...prev, notes: event.target.value }))
              }
              className="input"
              placeholder="Optional GRN notes"
            />
          </Field>
        </div>

        <div className="receive-lines-wrap">
          <table className="receive-lines-table">
            <thead>
              <tr>
                <th>Product</th>
                <th>Code</th>
                <th className="text-right">Ordered</th>
                <th className="text-right">Already Received</th>
                <th className="text-right">Pending</th>
                <th className="text-right">Received Now</th>
                <th className="text-right">Unit Cost</th>
                <th className="text-right">Line Value</th>
                <th>Lot / Batch</th>
                <th>Manufactured</th>
                <th>Expiry</th>
                <th>Notes</th>
              </tr>
            </thead>

            <tbody>
              {lines.map((line, index) => {
                const qty = Number(line.quantity || 0);
                const lineValue = qty * Number(line.unit_cost || 0);

                return (
                  <tr key={line.purchase_order_line_id || index}>
                    <td className="doc-number">{line.product_name}</td>
                    <td>{line.product_code || "-"}</td>
                    <td className="text-right">{number(line.ordered_quantity, 3)}</td>
                    <td className="text-right">{number(line.received_quantity, 3)}</td>
                    <td className="text-right pending-qty">{number(line.pending_quantity, 3)}</td>
                    <td className="text-right">
                      <input
                        type="number"
                        step="0.001"
                        min="0"
                        value={line.quantity}
                        onChange={(event) =>
                          onLineChange(index, "quantity", event.target.value)
                        }
                        className="input text-right receive-qty-input"
                      />
                    </td>
                    <td className="text-right">
                      <input
                        type="number"
                        step="0.01"
                        min="0"
                        value={line.unit_cost}
                        onChange={(event) =>
                          onLineChange(index, "unit_cost", event.target.value)
                        }
                        className="input text-right receive-cost-input"
                      />
                    </td>
                    <td className="text-right doc-total">
                      {money(lineValue, line.currency_code || order.currency_code)}
                    </td>
                    <td><input value={line.lot_number || ""} onChange={(event) => onLineChange(index, "lot_number", event.target.value)} className="input" placeholder={line.source_line?.product?.batch_tracking_enabled ? "Required" : "Optional"} /></td>
                    <td><input type="date" value={line.manufactured_at || ""} onChange={(event) => onLineChange(index, "manufactured_at", event.target.value)} className="input" /></td>
                    <td><input type="date" min={line.manufactured_at || undefined} value={line.expires_at || ""} onChange={(event) => onLineChange(index, "expires_at", event.target.value)} className="input" /></td>
                    <td>
                      <input
                        value={line.notes || ""}
                        onChange={(event) =>
                          onLineChange(index, "notes", event.target.value)
                        }
                        className="input"
                        placeholder="Optional"
                      />
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>

        <div className="receive-footer">
          <div className="receive-warning">
            Enter the actual quantity received. It can be less or more than the PO pending quantity.
          </div>

          <div className="details-actions">
            <button type="button" onClick={onClose} className="modal-btn">
              Cancel
            </button>
            <button
              type="button"
              onClick={onPost}
              disabled={loading}
              className="modal-btn modal-btn-primary"
            >
              <PackageCheck className="h-3.5 w-3.5" />
              {loading ? "Posting..." : "Post Goods Received"}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}

function DocumentDetails({ document, suppliers, onClose, onDownload, onShare }) {
  const lines = Array.isArray(document?.lines) ? document.lines : [];
  const type = document.__type || "";

  return (
    <div className="details-backdrop">
      <div className="details-modal">
        <div className="details-header">
          <div>
            <h2>{getDocumentNumber(document)}</h2>

            <p>
              {getSupplierName(suppliers[document.supplier_id] || document.supplier)} ·{" "}
              {formatDate(getDocumentDate(document))}
            </p>
          </div>

          <div className="details-actions">
            <button type="button" onClick={onDownload} className="modal-btn">
              <Download className="h-3.5 w-3.5" />
              PDF
            </button>

            <button
              type="button"
              onClick={onShare}
              className="modal-btn modal-btn-primary"
            >
              <Send className="h-3.5 w-3.5" />
              Share
            </button>

            <button type="button" onClick={onClose} className="icon-close">
              <X className="h-4 w-4" />
            </button>
          </div>
        </div>

        <div className="details-summary">
          <SummaryBox label="Currency" value={document.currency_code || "-"} />

          <SummaryBox
            label="Subtotal"
            value={money(document.subtotal, document.currency_code)}
          />

          <SummaryBox
            label="Tax"
            value={money(document.tax_total, document.currency_code)}
          />

          <SummaryBox
            label="Total"
            value={money(document.grand_total, document.currency_code)}
          />
        </div>

        <div className="details-body">
          <table className="details-table">
            <thead>
              <tr>
                <th>Product</th>
                <th className="text-right">Ordered</th>
                <th className="text-right">Received</th>
                <th className="text-right">Returned</th>
                <th className="text-right">Qty</th>
                <th className="text-right">Unit Cost</th>
                <th className="text-right">Tax</th>
                <th className="text-right">Total</th>
              </tr>
            </thead>

            <tbody>
              {lines.length === 0 ? (
                <tr>
                  <td colSpan="8" className="empty-cell">
                    No lines found.
                  </td>
                </tr>
              ) : (
                lines.map((line, index) => (
                  <tr key={line.id || index}>
                    <td>{getLineProductTitle(line)}</td>

                    <td className="text-right">
                      {number(getLineOrderedQuantity(line, type), 3)}
                    </td>

                    <td className="text-right">
                      {number(getLineReceivedQuantity(line, type), 3)}
                    </td>

                    <td className="text-right">
                      {number(getLineReturnedQuantity(line, type), 3)}
                    </td>

                    <td className="text-right">
                      {number(getLineMainQuantity(line, type), 3)}
                    </td>

                    <td className="text-right">
                      {money(line.unit_cost, line.currency_code || document.currency_code)}
                    </td>

                    <td className="text-right">
                      {money(line.tax_amount, line.currency_code || document.currency_code)}
                    </td>

                    <td className="text-right doc-total">
                      {money(
                        getLineTotal(line, type),
                        line.currency_code || document.currency_code
                      )}
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}

function SummaryBox({ label, value }) {
  return (
    <div className="summary-box">
      <span>{label}</span>
      <strong>{value}</strong>
    </div>
  );
}

function PurchaseStyles() {
  return (
    <style>
      {`
        .purchase-root {
          position: relative;
          height: calc(100vh - 86px);
          overflow: hidden;
          background: #E8EDED;
          color: #122424;
          font-weight: 400;
        }

        .purchase-notice {
          position: absolute;
          left: 50%;
          top: 12px;
          z-index: 100;
          transform: translateX(-50%);
          max-width: 760px;
          white-space: pre-line;
          border: 1px solid #004141;
          background: #004142;
          padding: 8px 16px;
          font-size: 12px;
          font-weight: 500;
          color: white;
          box-shadow: 0 16px 35px rgba(9, 27, 27, 0.28);
        }

        .purchase-shell {
          height: 100%;
          min-height: 0;
          display: grid;
          grid-template-rows: auto auto auto auto minmax(0, 1fr);
          gap: 3px;
          padding: 4px;
        }

        .purchase-toolbar,
        .filters-card,
        .document-card {
          border: 1px solid #ACB8B8;
          background: #FFFFFF;
          box-shadow: 0 1px 0 rgba(9, 27, 27, 0.04);
        }

        .purchase-toolbar {
          min-height: 48px;
          display: flex;
          align-items: center;
          justify-content: space-between;
          gap: 6px;
          padding: 8px;
        }

        .purchase-tabs,
        .purchase-actions {
          display: flex;
          flex-wrap: wrap;
          align-items: center;
          gap: 4px;
        }

        .purchase-tabs {
          flex: 1 1 auto;
          flex-wrap: nowrap;
          overflow-x: auto;
        }

        .purchase-tab {
          height: 32px;
          display: inline-flex;
          align-items: center;
          gap: 6px;
          border: 1px solid #ACB8B8;
          background: #FFFFFF;
          padding: 0 9px;
          font-size: 11px;
          white-space: nowrap;
          font-weight: 500;
          letter-spacing: 0.01em;
          color: #003636;
        }

        .purchase-tab-active {
          border-color: #069495;
          background: #E8F7F5;
          color: #006869;
        }

        .toolbar-btn {
          height: 32px;
          display: inline-flex;
          align-items: center;
          gap: 5px;
          border: 1px solid #ACB8B8;
          background: #FFFFFF;
          padding: 0 12px;
          font-size: 12px;
          font-weight: 500;
          letter-spacing: 0.01em;
          color: #003636;
        }

        .toolbar-btn-primary {
          border-color: #057B7C;
          background: #069495;
          color: white;
        }

        .toolbar-btn-dark {
          border-color: #004141;
          background: #004142;
          color: white;
        }

        .filters-card {
          padding: 8px;
        }

        .filters-grid {
          display: grid;
          grid-template-columns: repeat(7, minmax(0, 1fr));
          gap: 8px;
          align-items: end;
        }

        .field {
          display: block;
          min-width: 0;
        }

        .validation-banner {
          flex: 0 0 auto;
          margin: 10px;
          border: 1px solid #FDA29B;
          border-left: 5px solid #D92D20;
          background: #FEF3F2;
          padding: 10px 12px;
          font-size: 12px;
          line-height: 1.45;
          color: #912018;
        }

        .form-body > .validation-banner {
          margin: 0 0 10px;
        }

        .validation-banner strong {
          display: block;
          margin-bottom: 3px;
          color: #7A271A;
        }

        .validation-banner ul {
          margin: 0;
          padding-left: 18px;
        }

        .field-has-error .label,
        .field-error {
          color: #B42318;
        }

        .field-has-error .input,
        .field-has-error .textarea {
          border-color: #D92D20;
          background: #FFF8F7;
          box-shadow: inset 0 0 0 1px #D92D20;
        }

        .field-error {
          margin-top: 4px;
          font-size: 10px;
          font-weight: 600;
          line-height: 1.3;
        }

        .label {
          margin-bottom: 4px;
          font-size: 11px;
          font-weight: 500;
          color: #637877;
        }

        .input {
          height: 34px;
          width: 100%;
          border: 1px solid #ACB8B8;
          background: white;
          padding: 0 8px;
          font-size: 12px;
          font-weight: 400;
          outline: none;
          border-radius: 0;
        }

        .input:focus,
        .textarea:focus {
          border-color: #069495;
          box-shadow: inset 0 0 0 1px #069495;
        }

        .textarea {
          min-height: 90px;
          width: 100%;
          border: 1px solid #ACB8B8;
          background: white;
          padding: 8px;
          font-size: 12px;
          outline: none;
          resize: vertical;
        }

        .filter-apply {
          height: 34px;
          display: inline-flex;
          align-items: center;
          justify-content: center;
          gap: 6px;
          border: 1px solid #004141;
          background: #004142;
          padding: 0 12px;
          font-size: 12px;
          font-weight: 500;
          color: white;
        }

        .purchase-table-area {
          min-height: 0;
          overflow: hidden;
        }

        .document-card {
          height: 100%;
          min-height: 0;
          display: flex;
          flex-direction: column;
        }

        .document-head {
          min-height: 48px;
          display: flex;
          align-items: center;
          justify-content: space-between;
          border-bottom: 1px solid #ACB8B8;
          background: #F8FAFC;
          padding: 8px 12px;
        }

        .document-head h3 {
          margin: 0;
          font-size: 14px;
          font-weight: 500;
          letter-spacing: 0.01em;
          color: #003636;
        }

        .document-head span {
          display: block;
          margin-top: 2px;
          font-size: 11px;
          color: #637877;
        }

        .document-table-wrap {
          flex: 1;
          min-height: 0;
          overflow: auto;
        }

        .document-table,
        .details-table {
          width: 100%;
          min-width: 1180px;
          border-collapse: collapse;
          font-size: 12px;
        }

        .document-table th,
        .details-table th {
          position: sticky;
          top: 0;
          z-index: 2;
          border-bottom: 1px solid #ACB8B8;
          background: #F3F6F8;
          padding: 8px;
          text-align: left;
          font-weight: 500;
          color: #324444;
        }

        .document-table td,
        .details-table td {
          border-bottom: 1px solid #E3E9E9;
          padding: 8px;
          color: #122424;
          vertical-align: middle;
        }

        .document-table tr:hover td,
        .details-table tr:hover td {
          background: #F8FAFC;
        }

        .doc-number,
        .doc-total {
          font-weight: 500;
          letter-spacing: 0.01em;
          color: #003636;
        }

        .empty-cell {
          padding: 48px !important;
          text-align: center;
          color: #637877;
        }

        .action-menu-wrap {
          position: relative;
          display: inline-flex;
          justify-content: flex-end;
        }

        .dots-btn {
          width: 30px;
          height: 30px;
          display: inline-flex;
          align-items: center;
          justify-content: center;
          border: 1px solid #ACB8B8;
          background: #FFFFFF;
          color: #003636;
        }

        .action-menu {
          position: absolute;
          top: 34px;
          right: 0;
          z-index: 30;
          width: 180px;
          border: 1px solid #ACB8B8;
          background: white;
          box-shadow: 0 18px 35px rgba(9, 27, 27, 0.24);
        }

        .action-menu button {
          width: 100%;
          height: 34px;
          display: flex;
          align-items: center;
          gap: 8px;
          border: 0;
          border-bottom: 1px solid #E3E9E9;
          background: white;
          padding: 0 10px;
          font-size: 12px;
          font-weight: 500;
          letter-spacing: 0.01em;
          color: #003636;
          text-align: left;
        }

        .action-menu button:hover {
          background: #F8FAFC;
        }

        .status-pill {
          display: inline-flex;
          align-items: center;
          border: 1px solid #CBD6D6;
          padding: 3px 8px;
          font-size: 11px;
          font-weight: 500;
          text-transform: capitalize;
        }

        .status-good {
          border-color: #ABEFC6;
          background: #ECFDF3;
          color: #067647;
        }

        .status-bad {
          border-color: #FECDCA;
          background: #FEF3F2;
          color: #B42318;
        }

        .status-muted {
          border-color: #CFD6D6;
          background: #F9FAFB;
          color: #465757;
        }

        .status-warn {
          border-color: #FEDF89;
          background: #FFFAEB;
          color: #B54708;
        }

        .receipt-source-badge {
          display: inline-flex;
          align-items: center;
          white-space: nowrap;
          border: 1px solid;
          padding: 3px 7px;
          font-size: 9px;
          font-weight: 700;
          letter-spacing: .04em;
          text-transform: uppercase;
        }

        .receipt-source-quick {
          border-color: #6DDBDB;
          background: #F2F9F9;
          color: #006161;
        }

        .receipt-source-po {
          border-color: #A7F3D0;
          background: #ECFDF5;
          color: #065F46;
        }

        .form-backdrop,
        .details-backdrop {
          position: fixed;
          inset: 0;
          z-index: 100000;
          background: rgba(0, 52, 52, 0.55);
          padding: 12px;
          display: flex;
          align-items: center;
          justify-content: center;
        }

        .form-modal,
        .details-modal {
          width: min(1500px, 98vw);
          height: min(92vh, 900px);
          display: flex;
          flex-direction: column;
          border: 1px solid #ACB8B8;
          background: white;
          box-shadow: 0 20px 55px rgba(9, 27, 27, 0.32);
        }

        .form-header,
        .details-header {
          min-height: 58px;
          display: flex;
          align-items: center;
          justify-content: space-between;
          gap: 12px;
          border-bottom: 1px solid #ACB8B8;
          background: #F8FAFC;
          padding: 10px 14px;
        }

        .form-header h2,
        .details-header h2 {
          margin: 0;
          font-size: 15px;
          font-weight: 500;
          letter-spacing: 0.01em;
          letter-spacing: 0.01em;
          color: #003636;
        }

        .form-header p,
        .details-header p {
          margin: 3px 0 0;
          font-size: 12px;
          color: #637877;
        }

        .details-actions {
          display: flex;
          align-items: center;
          gap: 6px;
        }

        .icon-close {
          height: 34px;
          width: 34px;
          display: inline-flex;
          align-items: center;
          justify-content: center;
          border: 1px solid #ACB8B8;
          background: white;
          color: #003636;
        }

        .form-body {
          flex: 1;
          min-height: 0;
          overflow-y: auto;
          background: #E8EDED;
          padding: 10px;
        }

        .form-section {
          border: 1px solid #ACB8B8;
          background: white;
          margin-bottom: 10px;
        }

        .form-section-lines {
          min-height: 360px;
        }

        .section-title {
          border-bottom: 1px solid #E3E9E9;
          background: #F8FAFC;
          padding: 8px 10px;
          font-size: 13px;
          font-weight: 500;
          letter-spacing: 0.01em;
          color: #003636;
        }

        .section-header {
          display: flex;
          align-items: center;
          justify-content: space-between;
          border-bottom: 1px solid #E3E9E9;
          background: #F8FAFC;
        }

        .section-header .section-title {
          border-bottom: 0;
          background: transparent;
        }

        .line-add-btn {
          height: 30px;
          display: inline-flex;
          align-items: center;
          gap: 5px;
          border: 1px solid #069495;
          background: #E8F7F5;
          margin-right: 8px;
          padding: 0 10px;
          font-size: 12px;
          font-weight: 500;
          color: #006869;
        }

        .form-grid {
          display: grid;
          grid-template-columns: repeat(4, minmax(0, 1fr));
          gap: 10px;
          padding: 10px;
        }

        .notes-grid {
          display: grid;
          grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) 300px;
          gap: 10px;
          padding: 10px;
        }

        .line-editor {
          position: relative;
          overflow-x: auto;
          overflow-y: visible;
          min-height: 220px;
        }

        .line-body {
          position: relative;
          overflow: visible;
        }

        .line-header,
        .line-row {
          display: grid;
          grid-template-columns: minmax(320px, 1.45fr) 96px 118px 108px 108px 96px 140px minmax(180px, 0.9fr) 90px;
          min-width: 1260px;
        }

        .line-header {
          background: #F3F6F8;
          border-bottom: 1px solid #ACB8B8;
        }

        .line-header div {
          padding: 8px;
          font-size: 11px;
          font-weight: 500;
          color: #324444;
        }

        .line-row {
          border-bottom: 1px solid #E3E9E9;
          background: white;
        }

        .line-cell {
          padding: 5px;
          min-width: 0;
        }

        .line-total {
          padding: 12px 8px;
          text-align: right;
          font-size: 12px;
          font-weight: 500;
          letter-spacing: 0.01em;
          color: #003636;
        }

        .remove-btn {
          height: 34px;
          width: 100%;
          border: 1px solid #FDA29B;
          background: #FFF4F3;
          font-size: 11px;
          font-weight: 500;
          color: #B42318;
        }

        .line-add-bottom {
          height: 32px;
          display: inline-flex;
          align-items: center;
          gap: 5px;
          border: 1px solid #ACB8B8;
          background: white;
          margin: 8px;
          padding: 0 12px;
          font-size: 12px;
          font-weight: 500;
          letter-spacing: 0.01em;
          color: #003636;
        }

        .search-product-wrap {
          position: relative;
          width: 100%;
        }

        .search-product-button {
          height: 34px;
          width: 100%;
          border: 1px solid #ACB8B8;
          background: #FFFFFF;
          padding: 0 8px;
          font-size: 12px;
          font-weight: 400;
          color: #122424;
          text-align: left;
          overflow: hidden;
        }

        .search-product-button span {
          display: block;
          overflow: hidden;
          text-overflow: ellipsis;
          white-space: nowrap;
        }

        .search-product-menu {
          border: 1px solid #ACB8B8;
          background: #FFFFFF;
          box-shadow: 0 18px 35px rgba(9, 27, 27, 0.24);
        }

        .search-product-input {
          height: 36px;
          width: 100%;
          border: 0;
          border-bottom: 1px solid #ACB8B8;
          padding: 0 10px;
          font-size: 12px;
          outline: none;
        }

        .search-product-input:focus {
          border-bottom-color: #069495;
        }

        .search-product-list {
          max-height: 260px;
          overflow-y: auto;
        }

        .search-product-empty {
          padding: 14px;
          text-align: center;
          font-size: 12px;
          color: #637877;
        }

        .search-product-option {
          width: 100%;
          display: block;
          border: 0;
          border-bottom: 1px solid #E3E9E9;
          background: #FFFFFF;
          padding: 8px 10px;
          text-align: left;
        }

        .search-product-option:hover {
          background: #F8FAFC;
        }

        .search-product-option-active {
          background: #E8F7F5;
        }

        .search-product-option span {
          display: block;
          font-size: 12px;
          font-weight: 500;
          letter-spacing: 0.01em;
          color: #003636;
        }

        .search-product-option small {
          display: block;
          margin-top: 2px;
          font-size: 10px;
          color: #637877;
        }

        .totals-card {
          border: 1px solid #ACB8B8;
          background: #F8FAFC;
          padding: 8px;
          font-size: 12px;
        }

        .totals-card div {
          display: flex;
          align-items: center;
          justify-content: space-between;
          gap: 8px;
          border-bottom: 1px solid #E3E9E9;
          padding: 6px 0;
        }

        .totals-card div:last-child {
          border-bottom: 0;
        }

        .totals-card span {
          color: #637877;
        }

        .totals-card strong {
          font-weight: 500;
          letter-spacing: 0.01em;
          color: #003636;
        }

        .grand-total {
          font-size: 14px;
        }

        .form-footer {
          min-height: 52px;
          display: flex;
          align-items: center;
          justify-content: flex-end;
          gap: 8px;
          border-top: 1px solid #ACB8B8;
          background: #F8FAFC;
          padding: 8px 14px;
        }

        .modal-btn {
          height: 34px;
          display: inline-flex;
          align-items: center;
          gap: 6px;
          border: 1px solid #ACB8B8;
          background: white;
          padding: 0 16px;
          font-size: 12px;
          font-weight: 500;
          letter-spacing: 0.01em;
          color: #003636;
        }

        .modal-btn-primary {
          border-color: #057B7C;
          background: #069495;
          color: white;
        }

        .details-summary {
          display: grid;
          grid-template-columns: repeat(4, minmax(0, 1fr));
          gap: 8px;
          border-bottom: 1px solid #ACB8B8;
          background: #E8EDED;
          padding: 8px;
        }

        .summary-box {
          border: 1px solid #ACB8B8;
          background: white;
          padding: 8px 10px;
        }

        .summary-box span {
          display: block;
          font-size: 11px;
          color: #637877;
        }

        .summary-box strong {
          display: block;
          margin-top: 3px;
          font-size: 14px;
          font-weight: 500;
          letter-spacing: 0.01em;
          color: #003636;
        }

        .pending-receive-alert {
          border: 1px solid #FEDF89;
          background: #FFFAEB;
          padding: 6px 10px;
          font-size: 12px;
          font-weight: 600;
          color: #B54708;
          text-transform: uppercase;
        }

        .pending-line-badge {
          display: inline-flex;
          margin-left: 6px;
          border: 1px solid #FEDF89;
          background: #FFFAEB;
          padding: 3px 7px;
          font-size: 10px;
          font-weight: 600;
          color: #B54708;
          text-transform: uppercase;
          vertical-align: middle;
        }

        .receive-modal {
          width: min(1500px, 98vw);
          height: min(92vh, 900px);
          display: flex;
          flex-direction: column;
          border: 1px solid #ACB8B8;
          background: white;
          box-shadow: 0 20px 55px rgba(9, 27, 27, 0.32);
        }

        .receive-header {
          min-height: 58px;
          display: flex;
          align-items: center;
          justify-content: space-between;
          gap: 12px;
          border-bottom: 4px solid #069495;
          background: #004142;
          padding: 10px 14px;
          color: white;
        }

        .receive-header h2 {
          margin: 0;
          font-size: 15px;
          font-weight: 600;
          color: white;
          text-transform: uppercase;
        }

        .receive-header p {
          margin: 3px 0 0;
          font-size: 12px;
          color: rgba(255, 255, 255, 0.72);
        }

        .receive-summary-strip {
          display: grid;
          grid-template-columns: repeat(4, minmax(0, 1fr));
          gap: 8px;
          border-bottom: 1px solid #ACB8B8;
          background: #E8EDED;
          padding: 8px;
        }

        .receive-controls {
          display: grid;
          grid-template-columns: 220px minmax(0, 1fr);
          gap: 10px;
          border-bottom: 1px solid #ACB8B8;
          background: #F8FAFC;
          padding: 10px;
        }

        .receive-lines-wrap {
          flex: 1;
          min-height: 0;
          overflow: auto;
          background: #E8EDED;
          padding: 8px;
        }

        .receive-lines-table {
          width: 100%;
          min-width: 1280px;
          border-collapse: collapse;
          background: white;
          font-size: 12px;
        }

        .receive-lines-table th {
          position: sticky;
          top: 0;
          z-index: 2;
          border-bottom: 1px solid #ACB8B8;
          background: #004142;
          padding: 8px;
          text-align: left;
          font-weight: 600;
          color: white;
          text-transform: uppercase;
        }

        .receive-lines-table td {
          border-bottom: 1px solid #E3E9E9;
          padding: 6px;
          color: #122424;
          vertical-align: middle;
        }

        .receive-lines-table tr:hover td {
          background: #F8FAFC;
        }

        .pending-qty {
          font-weight: 600;
          color: #B54708;
        }

        .receive-qty-input {
          border-color: #069495;
          font-weight: 600;
        }

        .receive-cost-input {
          min-width: 110px;
        }

        .receive-footer {
          min-height: 56px;
          display: flex;
          align-items: center;
          justify-content: space-between;
          gap: 10px;
          border-top: 1px solid #ACB8B8;
          background: #F8FAFC;
          padding: 8px 14px;
        }

        .receive-warning {
          border-left: 4px solid #069495;
          padding-left: 10px;
          font-size: 12px;
          font-weight: 600;
          color: #003636;
        }

        .details-body {
          flex: 1;
          min-height: 0;
          overflow: auto;
          padding: 8px;
          background: #DFE5E5;
        }

        .details-table {
          background: white;
        }

        .text-right {
          text-align: right !important;
        }

        button,
        input,
        select,
        textarea {
          border-radius: 0 !important;
        }

        button:disabled,
        input:disabled,
        select:disabled,
        textarea:disabled {
          opacity: 0.6;
          cursor: not-allowed;
        }

        @media (max-width: 1180px) {
          .filters-grid,
          .form-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
          }

          .notes-grid,
          .details-summary {
            grid-template-columns: 1fr;
          }

          .purchase-toolbar {
            align-items: stretch;
            flex-direction: column;
          }
        }

        @media (max-width: 720px) {
          .filters-grid,
          .form-grid {
            grid-template-columns: 1fr;
          }

          .form-backdrop,
          .details-backdrop {
            padding: 4px;
          }

          .form-modal,
          .details-modal {
            width: 100vw;
            height: 100vh;
          }
        }
      `}
    </style>
  );
}
