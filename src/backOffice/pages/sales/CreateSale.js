import React, {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
} from "react";

import {
  AlertTriangle,
  Banknote,
  CreditCard,
  Delete,
  Download,
  FileText,
  GitBranch,
  PackageSearch,
  Printer,
  QrCode,
  ReceiptText,
  RefreshCw,
  Search,
  ShoppingCart,
  Smartphone,
  WalletCards,
} from "lucide-react";

import FullScreenModal from "../../../components/FullScreenModal";
import EnterpriseCashupModal from "../../../components/EnterpriseCashupModal";

import {
  getProducts,
  getProductCategories,
} from "../../../services/ProductService";

import { getPaymentTypes } from "../../../services/PaymentTypeService";
import { getCurrencies, convertCurrency } from "../../../services/CurrencyService";
import { createSale } from "../../../services/SaleService";
import {
  qzPrintPdfUrl,
  qzOpenCashDrawer,
} from "../../../services/QzService";
import { dispatchSalePrints } from "../../../services/FastFoodPrintDispatcher";

import {
  sendCustomerDisplayUpdate,
  clearCustomerDisplay,
  openCustomerDisplayWindow,
} from "../../../services/CustomerDisplayService";

import {
  getActiveShiftSummary,
  cashupShift,
  endShift as endShiftApi,
} from "../../../services/CashupService";
import { canViewCashupFinancialAmounts } from "../../../utils/CashupPermissions";

import {
  getSalesDashboard,
  getSalesReport,
  getPaymentReport,
  getProductSalesReport,
  getCashierSalesReport,
} from "../../../services/SalesReportService";

import { getBarcodeScanSetting, getSalesScreenLayoutSetting } from "../../../services/GeneralSettingsService";
import {
  getPriceLevelOverrideConfig,
  getProductOverridePriceLevels,
  applyPriceLevelOverride,
} from "../../../services/PriceLevelOverrideService";
import TouchKeyboardOverlay from "../../../components/TouchKeyboardOverlay";
import ManagerApprovalModal from "../../../components/ManagerApprovalModal";
import DiscountApplyModal from "../../../components/DiscountApplyModal";
import {
  lookupQuotationByCode,
  markQuotationConverted,
} from "../../../services/QuotationService";
import {
  downloadCreditCustomerPaymentReceipt,
  getCreditCustomersForPos,
} from "../../../services/CreditCustomerService";
import SalesPointFace from "./components/SalesPointFace";
import PosCustomerAccountModal from "./components/PosCustomerAccountModal";
import { getSaleResponsePayload, resolveCreditedSaleChange } from "./SaleResponse";
import FastFoodLinePrepMessageModal from "./components/FastFoodLinePrepMessageModal";
import FastFoodLineModifierModal from "./components/FastFoodLineModifierModal";
import FastFoodLineSplitModal from "./components/FastFoodLineSplitModal";
import HospitalityServiceBar from "./components/HospitalityServiceBar";
import useBusinessMode from "../../../hooks/useBusinessMode";
import { useFrontAuth } from "../../../context/FrontAuthContext";
import { useAdminAuth } from "../../context/AdminAuthContext";
import {
  clearHospitalityTableOrder,
  createHospitalityRequestId,
  createHospitalityTableOrder,
  getHospitalityTableOrder,
  getHospitalityTableOrders,
  getHospitalityTableService,
  hangHospitalityTableOrder,
  hospitalityOrderId,
  hospitalityOrderRevision,
  recallHospitalityTableOrder,
  sendHospitalityTableOrderToKitchen,
  updateHospitalityTableOrder,
} from "../../../services/HospitalityTableOrderService";


const money = (value, currency = "USD") =>
  `${currency || "USD"} ${Number(value || 0).toFixed(2)}`;

const createLineId = (productId) => {
  if (typeof crypto !== "undefined" && crypto.randomUUID) {
    return crypto.randomUUID();
  }

  return `${productId}-${Date.now()}`;
};

// Keep one immutable id for the whole sale attempt. If the API commits the
// receipt but the response is lost, retrying with this id returns that receipt
// instead of deducting stock and crediting customer change a second time.
const createSaleAttemptId = () => `pos-sale-${createLineId("attempt")}`;

const createPaymentRow = ({ paymentTypeId = "", currencyCode = "", amount = "" } = {}) => ({
  id: createLineId(`payment-${paymentTypeId || "row"}`),
  payment_type_id: paymentTypeId || "",
  currency_code: currencyCode || "",
  amount: amount === undefined || amount === null ? "" : String(amount),
});

const getModifierUnitTotal = (line) =>
  (Array.isArray(line?.modifiers) ? line.modifiers : []).reduce(
    (sum, modifier) => sum + Number(modifier?.price || 0) * Number(modifier?.quantity || 1),
    0
  );

const getLineBaseMenuPrice = (line) => Number(
  line?.baseMenuPrice ??
    line?.menuBasePrice ??
    line?.basePrice ??
    line?.price ??
    0
);

const getLineEffectiveUnitPrice = (line) =>
  Number((getLineBaseMenuPrice(line) + getModifierUnitTotal(line)).toFixed(4));

const lineHasFastFoodCustomisation = (line) =>
  (Array.isArray(line?.prep_message_ids) && line.prep_message_ids.length > 0) ||
  (Array.isArray(line?.prepMessageIds) && line.prepMessageIds.length > 0) ||
  Boolean(line?.prep_note || line?.prepNote) ||
  (Array.isArray(line?.modifiers) && line.modifiers.length > 0);

const createSplitLineId = (sourceLineId) => createLineId(`split-${sourceLineId}`);

const cloneJson = (value, fallback = null) => {
  try {
    return JSON.parse(JSON.stringify(value ?? fallback));
  } catch {
    return fallback;
  }
};

const buildLineCustomisationKey = (line = {}) => {
  const prepIds = Array.isArray(line.prepMessageIds)
    ? line.prepMessageIds
    : Array.isArray(line.prep_message_ids)
    ? line.prep_message_ids
    : [];

  const prepMessages = Array.isArray(line.prepMessages)
    ? line.prepMessages
    : Array.isArray(line.prep_messages)
    ? line.prep_messages
    : [];

  const modifiers = Array.isArray(line.modifiers)
    ? line.modifiers.map((modifier) => ({
        id: String(modifier?.modifier_id || modifier?.id || ""),
        quantity: Number(modifier?.quantity || 1),
        price: Number(modifier?.price || 0),
      }))
    : [];

  return JSON.stringify({
    prepIds: prepIds.map(String).sort(),
    prepMessages: prepMessages
      .map((message) => String(message?.id || message?.message || message || ""))
      .sort(),
    prepNote: String(line.prepNote || line.prep_note || "").trim(),
    modifiers: modifiers.sort((a, b) => a.id.localeCompare(b.id)),
  });
};

const sameSaleLineIdentity = (a, b) => {
  return (
    String(a?.productId || a?.product_id || "") ===
      String(b?.productId || b?.product_id || "") &&
    buildLineCustomisationKey(a) === buildLineCustomisationKey(b)
  );
};

const getPaymentRowAmount = (row) => Number(row?.amount || 0);

const sumPaymentRows = (rows = []) =>
  rows.reduce((sum, row) => sum + getPaymentRowAmount(row), 0);

const appendDecimalNumber = (currentValue, nextValue) => {
  if (nextValue === "." && String(currentValue).includes(".")) return currentValue;
  if (currentValue === "0" && nextValue !== ".") return nextValue;

  return `${currentValue || ""}${nextValue}`;
};

const normalizePaginatedData = (response) => {
  if (Array.isArray(response)) return response;
  if (Array.isArray(response?.data)) return response.data;
  if (Array.isArray(response?.data?.data)) return response.data.data;
  if (Array.isArray(response?.currencies)) return response.currencies;
  if (Array.isArray(response?.payment_types)) return response.payment_types;

  return [];
};

const normalizeText = (value) => String(value || "").trim().toLowerCase();

// Hospitality sale screens expose configured menu items only. Stock ingredients,
// packs and legacy retail parent/child products stay in Back Office inventory.
const POS_PRODUCT_TYPES = ["finished_good", "service"];

const normalizeProductType = (type) =>
  String(type || "")
    .trim()
    .toLowerCase()
    .replaceAll(" ", "_")
    .replaceAll("-", "_");

const getProductTypeValue = (product) =>
  product?.product_type ||
  product?.productType ||
  product?.type ||
  product?.type_name ||
  product?.product_type_name ||
  product?.productTypeName ||
  product?.category?.product_type ||
  product?.metadata?.product_type ||
  product?.product_type?.name ||
  product?.type?.name ||
  "";

const productMatchesPosType = (product) => {
  const productType = normalizeProductType(getProductTypeValue(product));

  return POS_PRODUCT_TYPES.includes(productType);
};

const boolValue = (value) => {
  return value === true || value === 1 || value === "1" || value === "true";
};

const isSettingActive = (setting) => {
  if (!setting) return false;

  const active =
    setting.is_active ??
    setting.active ??
    setting.enabled ??
    setting.status ??
    true;

  const value =
    setting.setting_value ??
    setting.value ??
    setting.enabled ??
    setting.is_enabled ??
    false;

  return boolValue(active) && boolValue(value);
};

const getSettingRawValue = (setting, fallback = "") => {
  return (
    setting?.setting_value ??
    setting?.value ??
    setting?.data?.setting_value ??
    setting?.data?.value ??
    fallback
  );
};

const normalizeSalesLayout = (value) => {
  const normalized = String(value || "").trim().toLowerCase();
  return ["standard", "visual"].includes(normalized) ? normalized : null;
};

const getPaymentIcon = (paymentType) => {
  const name = normalizeText(paymentType?.name);
  const type = normalizeText(paymentType?.type);
  const moneyCode = normalizeText(paymentType?.money_code);

  if (name.includes("cash") || moneyCode.includes("cash")) return Banknote;
  if (name.includes("card") || type.includes("card")) return CreditCard;
  if (name.includes("mobile") || name.includes("ecocash")) return Smartphone;
  if (name.includes("voucher")) return ReceiptText;

  return WalletCards;
};

const paymentSupportsMultiCurrency = (paymentType) => {
  return boolValue(
    paymentType?.supportsMultiCurrency ??
      paymentType?.supports_multi_currency ??
      false
  );
};

const paymentRequiresAmountTendered = (paymentType) => {
  return boolValue(
    paymentType?.requiresAmountTendered ??
      paymentType?.requires_amount_tendered ??
      false
  );
};

const getPaymentDefaultCurrency = (paymentType) => {
  return (
    paymentType?.default_currency_code ||
    paymentType?.currency_code ||
    paymentType?.currency?.code ||
    ""
  );
};

const normalizeCode = (value) =>
  String(value || "")
    .trim()
    .toLowerCase()
    .replaceAll(" ", "_")
    .replaceAll("-", "_");

const normalizePermissionName = (value) => normalizeCode(value);

const permissionNamesForUser = (user, workspaceUser = null) => {
  const buckets = [
    workspaceUser?.permissions,
    user?.role?.permissions,
    user?.permissions,
    user?.role_permissions,
    user?.permission_names,
    user?.data?.role?.permissions,
    user?.data?.permissions,
  ];
  const names = new Set();

  buckets.forEach((bucket) => {
    if (Array.isArray(bucket)) {
      bucket.forEach((item) => {
        const name = typeof item === "string"
          ? item
          : item?.name || item?.permission_name || item?.key || item?.slug || item?.permission?.name;
        if (name) names.add(normalizePermissionName(name));
      });
    } else if (bucket && typeof bucket === "object") {
      Object.entries(bucket).forEach(([name, enabled]) => {
        if (boolValue(enabled)) names.add(normalizePermissionName(name));
      });
    }
  });

  return names;
};

const userIsSuperAdmin = (user, workspaceUser = null) => {
  const roleName = normalizeCode(user?.role?.name || user?.role_name || workspaceUser?.role || user?.role);
  const userType = normalizeCode(user?.user_type);
  return boolValue(user?.is_super_admin) || ["super_admin", "super_administrator"].includes(roleName) || userType === "super_admin";
};

const isCustomerCreditPaymentType = (paymentType) => {
  const name = normalizeText(paymentType?.name);
  const moneyCode = normalizeCode(paymentType?.money_code);
  const type = normalizeCode(paymentType?.type);

  return (
    moneyCode === "customer_credit" ||
    moneyCode === "credit_customer" ||
    type === "customer_credit" ||
    name === "customer credit" ||
    name.includes("customer credit") ||
    name.includes("credit customer")
  );
};

const getCreditCustomerName = (customer) =>
  customer?.name ||
  customer?.customer_name ||
  customer?.account_name ||
  customer?.company_name ||
  "Credit Customer";

const getCreditCustomerAccount = (customer) =>
  customer?.account_number ||
  customer?.credit_account_number ||
  customer?.code ||
  customer?.customer_code ||
  "";

const getCreditCustomerCurrency = (customer) =>
  customer?.currency_code || customer?.currency || customer?.account_currency || "";

const getCreditCustomerLimit = (customer) =>
  Number(customer?.credit_limit ?? customer?.limit_amount ?? customer?.account_limit ?? 0);

const getCreditCustomerBalance = (customer) =>
  Number(customer?.current_balance ?? customer?.balance ?? customer?.outstanding_balance ?? 0);

const getCreditCustomerAvailableCredit = (customer) => {
  const explicit =
    customer?.available_credit ??
    customer?.available_balance ??
    customer?.credit_available ??
    customer?.remaining_credit;

  if (explicit !== undefined && explicit !== null && explicit !== "") {
    return Number(explicit || 0);
  }

  return Math.max(getCreditCustomerLimit(customer) - getCreditCustomerBalance(customer), 0);
};

const creditCustomerIsAllowed = (customer) => {
  const status = normalizeCode(customer?.status || customer?.account_status || "approved");
  const active = customer?.is_active ?? customer?.active ?? true;

  return boolValue(active) && !["blocked", "suspended", "inactive"].includes(status);
};

const getCurrencyCode = (currency) =>
  currency?.code || currency?.currency_code || "";

const getCurrencyName = (currency) => {
  return currency?.name || currency?.currency_name || currency?.code || "";
};

const getBaseCurrency = (currencies) => {
  return (
    currencies.find((currency) => boolValue(currency?.is_base_currency)) ||
    currencies.find((currency) => boolValue(currency?.is_active)) ||
    currencies[0] ||
    null
  );
};

const isStandardPrice = (price) => {
  const level = price?.price_level || price?.priceLevel;

  return (
    normalizeText(level?.name) === "standard" ||
    normalizeText(level?.code) === "standard"
  );
};

const getStandardPrice = (product) => {
  const prices = Array.isArray(product?.prices) ? product.prices : [];

  return (
    prices.find(isStandardPrice) ||
    prices.find((price) => boolValue(price?.is_default)) ||
    prices.find((price) => boolValue(price?.is_active)) ||
    prices[0] ||
    null
  );
};

export const sellableTillMenuItems = (items = []) =>
  (Array.isArray(items) ? items : []).filter((product) => (
    product?.is_active !== false && !!getStandardPrice(product) && productMatchesPosType(product)
  ));

const getPriceLevelName = (price) =>
  price?.price_level_name || price?.price_level?.name || price?.priceLevel?.name || "Price Level";

const getPriceLevelCode = (price) =>
  price?.price_level_code || price?.price_level?.code || price?.priceLevel?.code || "";

const getPriceLevelId = (price) =>
  price?.price_level_id || price?.priceLevelId || price?.price_level?.id || price?.priceLevel?.id || null;


const getProductAvailableStock = (product) => {
  const stocks = Array.isArray(product?.stocks) ? product.stocks : [];

  if (stocks.length === 0) return 0;

  return stocks.reduce(
    (sum, stock) =>
      sum + Number(stock.available_quantity ?? stock.quantity_on_hand ?? 0),
    0
  );
};

const productTracksStock = (product) =>
  boolValue(product?.stock_tracking_enabled ?? product?.stockTrackingEnabled ?? false);

const productAllowsNegativeStock = (product) =>
  boolValue(product?.allow_negative_stock ?? product?.allowNegativeStock ?? false);

const getProductQuantityOnHand = (product) => {
  const stocks = Array.isArray(product?.stocks) ? product.stocks : [];

  if (stocks.length === 0) return 0;

  return stocks.reduce(
    (sum, stock) => sum + Number(stock.quantity_on_hand ?? stock.available_quantity ?? 0),
    0
  );
};

const validateProductStockForCart = (product, requestedQty = 1, currentCartQty = 0) => {
  if (!productTracksStock(product) || productAllowsNegativeStock(product)) {
    return { allowed: true, availableStock: getProductAvailableStock(product) };
  }

  const availableStock = getProductAvailableStock(product);
  const quantityOnHand = getProductQuantityOnHand(product);
  const cleanRequestedQty = Number(requestedQty || 0);
  const cleanCurrentCartQty = Number(currentCartQty || 0);
  const requiredQty = cleanCurrentCartQty + cleanRequestedQty;
  const productName = product?.name || "Product";

  if (availableStock <= 0 || quantityOnHand <= 0) {
    return {
      allowed: false,
      availableStock,
      message: `${productName} cannot be added. Negative stock is not allowed and available stock is ${Number(availableStock || 0).toFixed(4)}.`,
    };
  }

  if (requiredQty > availableStock) {
    return {
      allowed: false,
      availableStock,
      message: `${productName} cannot be added. Available stock is ${Number(availableStock || 0).toFixed(4)}, but cart quantity would become ${Number(requiredQty || 0).toFixed(4)}.`,
    };
  }

  return { allowed: true, availableStock };
};

const validateLineStockForQty = (line, nextQty) => {
  const product = line?.rawProduct || line;

  if (!productTracksStock(product) || productAllowsNegativeStock(product)) {
    return { allowed: true, availableStock: Number(line?.availableStock ?? getProductAvailableStock(product) ?? 0) };
  }

  const availableStock = Number(line?.availableStock ?? getProductAvailableStock(product) ?? 0);
  const quantityOnHand = getProductQuantityOnHand(product);
  const requestedQty = Number(nextQty || 0);
  const productName = line?.name || product?.name || "Product";

  if (availableStock <= 0 || quantityOnHand <= 0) {
    return {
      allowed: false,
      availableStock,
      message: `${productName} cannot be sold. Negative stock is not allowed and available stock is ${Number(availableStock || 0).toFixed(4)}.`,
    };
  }

  if (requestedQty > availableStock) {
    return {
      allowed: false,
      availableStock,
      message: `${productName} cannot use quantity ${Number(requestedQty || 0).toFixed(4)}. Available stock is ${Number(availableStock || 0).toFixed(4)}.`,
    };
  }

  return { allowed: true, availableStock };
};

const getProductUnit = (product) => {
  return product?.uom?.code || product?.uom?.name || "EACH";
};

const isDecimalProduct = (product) => {
  const uomName = normalizeText(product?.uom?.name);
  const uomCode = normalizeText(product?.uom?.code);
  const quantityType = normalizeText(product?.uom?.quantity_type);

  return (
    quantityType === "decimal" ||
    uomName === "portion" ||
    uomCode === "portion" ||
    uomName === "kg" ||
    uomCode === "kg" ||
    uomName === "kilogram"
  );
};

const getProductDecimalPlaces = (product) => {
  const decimalPlaces = Number(product?.uom?.decimal_places);

  if (!Number.isNaN(decimalPlaces) && decimalPlaces > 0) {
    return decimalPlaces;
  }

  return isDecimalProduct(product) ? 2 : 0;
};

const getDecimalStep = (decimalPlaces = 2) => {
  return Number((1 / Math.pow(10, decimalPlaces)).toFixed(decimalPlaces));
};

const normalizeQty = (value, allowDecimalQty = false, decimalPlaces = 2) => {
  const numeric = Number(value || 0);

  if (Number.isNaN(numeric) || numeric <= 0) {
    return allowDecimalQty ? getDecimalStep(decimalPlaces) : 1;
  }

  if (allowDecimalQty) {
    return Number(numeric.toFixed(decimalPlaces));
  }

  return Math.max(Math.floor(numeric), 1);
};

const formatQty = (
  qty,
  unit = "EACH",
  allowDecimalQty = false,
  decimalPlaces = 2
) => {
  const cleanQty = allowDecimalQty
    ? Number(qty || 0).toFixed(decimalPlaces)
    : Number(qty || 0).toFixed(0);

  return `${cleanQty} ${unit}`;
};

const getProductBarcodeValues = (product) => {
  const values = [];

  if (product?.barcode) values.push(product.barcode);
  if (product?.sku) values.push(product.sku);
  if (product?.code) values.push(product.code);

  if (Array.isArray(product?.barcodes)) {
    product.barcodes.forEach((item) => {
      if (item?.barcode) values.push(item.barcode);
    });
  }

  return values.filter(Boolean);
};


const getTaxRateValue = (tax) =>
  Number(
    tax?.rate ??
      tax?.tax_rate ??
      tax?.tax_percent ??
      tax?.taxPercent ??
      0
  );

const getProductTaxRows = (product) => {
  const rows = [];

  if (Array.isArray(product?.taxes)) rows.push(...product.taxes);
  if (Array.isArray(product?.product_taxes)) rows.push(...product.product_taxes);
  if (Array.isArray(product?.productTaxes)) rows.push(...product.productTaxes);

  const seen = new Set();

  return rows
    .filter((row) => row && row.is_active !== false && row.deleted_at == null)
    .filter((row) => {
      const key = String(row.id || row.product_tax_id || row.tax_id || row.tax?.id || Math.random());
      if (seen.has(key)) return false;
      seen.add(key);
      return true;
    });
};

const normalizeTaxRow = (row) => {
  const tax = row?.tax || row || {};
  const rate = getTaxRateValue(tax);

  return {
    id: row?.id || row?.product_tax_id || tax?.id || row?.tax_id || null,
    product_tax_id: row?.id || row?.product_tax_id || null,
    tax_id: tax?.id || row?.tax_id || null,
    fiscal_tax_id: tax?.tax_id ?? row?.fdms_tax_id ?? row?.fiscal_tax_id ?? null,
    tax_code: tax?.tax_code || row?.tax_code || tax?.code || row?.code || '',
    tax_name: tax?.name || row?.tax_name || row?.name || 'Tax',
    type: tax?.type || row?.type || 'percentage',
    rate,
    tax_percent: rate,
    is_inclusive: boolValue(row?.is_inclusive),
    obliged_to_zimra: boolValue(tax?.obliged_to_zimra ?? row?.obliged_to_zimra),
    is_active: row?.is_active !== false && tax?.is_active !== false,
  };
};

const getProductTaxesForSale = (product) =>
  getProductTaxRows(product)
    .map(normalizeTaxRow)
    .filter((tax) => tax.is_active && tax.rate >= 0);

const getLineTaxes = (line) => {
  if (Array.isArray(line?.taxes)) return line.taxes.map(normalizeTaxRow);
  if (Array.isArray(line?.productTaxes)) return line.productTaxes.map(normalizeTaxRow);
  if (Array.isArray(line?.product_taxes)) return line.product_taxes.map(normalizeTaxRow);

  return getProductTaxesForSale(line?.rawProduct || {});
};

const getTaxDisplayName = (tax) => {
  const code = tax?.tax_code || tax?.code || '';
  const name = tax?.tax_name || tax?.name || 'Tax';
  const rate = Number(tax?.tax_percent ?? tax?.rate ?? 0);

  return `${code || name}${rate ? ` ${rate.toFixed(2)}%` : ''}${tax?.is_inclusive ? ' inc' : ' exc'}`;
};

const calculateTaxBreakdown = ({ price, qty, discount = 0, taxes = [] }) => {
  const quantity = Number(qty || 0);
  const grossBeforeDiscount = Math.max(Number(price || 0) * quantity, 0);
  const cleanDiscount = Math.min(Math.max(Number(discount || 0), 0), grossBeforeDiscount);
  const grossAfterDiscount = Math.max(grossBeforeDiscount - cleanDiscount, 0);

  const activeTaxes = (taxes || [])
    .map(normalizeTaxRow)
    .filter((tax) => tax.is_active && tax.rate >= 0);

  let inclusivePercentageRate = 0;
  let inclusiveFixedTotal = 0;

  activeTaxes.forEach((tax) => {
    if (!tax.is_inclusive) return;

    if (tax.type === 'fixed') {
      inclusiveFixedTotal += Number(tax.rate || 0) * quantity;
    } else {
      inclusivePercentageRate += Number(tax.rate || 0) / 100;
    }
  });

  const taxableAmount = Math.max(
    inclusivePercentageRate > 0
      ? (grossAfterDiscount - inclusiveFixedTotal) / (1 + inclusivePercentageRate)
      : grossAfterDiscount - inclusiveFixedTotal,
    0
  );

  let inclusiveTaxTotal = 0;
  let exclusiveTaxTotal = 0;

  const taxRows = activeTaxes.map((tax) => {
    const taxAmount = tax.type === 'fixed'
      ? Number(tax.rate || 0) * quantity
      : taxableAmount * (Number(tax.rate || 0) / 100);

    if (tax.is_inclusive) {
      inclusiveTaxTotal += taxAmount;
    } else {
      exclusiveTaxTotal += taxAmount;
    }

    return {
      ...tax,
      taxable_amount: Number(taxableAmount.toFixed(4)),
      tax_amount: Number(taxAmount.toFixed(4)),
      sales_amount_with_tax: Number((taxableAmount + taxAmount).toFixed(4)),
    };
  });

  const taxTotal = inclusiveTaxTotal + exclusiveTaxTotal;
  const total = taxableAmount + inclusiveTaxTotal + exclusiveTaxTotal;

  return {
    gross: Number(grossBeforeDiscount.toFixed(4)),
    discount: Number(cleanDiscount.toFixed(4)),
    taxableAmount: Number(taxableAmount.toFixed(4)),
    inclusiveTax: Number(inclusiveTaxTotal.toFixed(4)),
    exclusiveTax: Number(exclusiveTaxTotal.toFixed(4)),
    tax: Number(taxTotal.toFixed(4)),
    total: Number(total.toFixed(4)),
    taxes: taxRows,
  };
};

const getProductTaxSummary = (product) => {
  const taxes = getProductTaxesForSale(product);
  if (!taxes.length) return 'No Tax';
  return taxes.map(getTaxDisplayName).join(' + ');
};

const firstNonEmpty = (...values) => {
  for (const value of values) {
    if (value !== undefined && value !== null && String(value).trim() !== "") {
      return value;
    }
  }

  return "";
};

const uniqueLines = (...values) => {
  const seen = new Set();

  return values
    .flat()
    .map((value) => String(value || "").trim())
    .filter(Boolean)
    .filter((value) => {
      const key = value.toLowerCase();
      if (seen.has(key)) return false;
      seen.add(key);
      return true;
    })
    .join("\n");
};

const getBackendErrorPayload = (source) => {
  const responseData = source?.response?.data || source?.data || {};
  const nestedData = responseData?.data && typeof responseData.data === "object"
    ? responseData.data
    : {};

  const validationErrors = responseData?.errors && typeof responseData.errors === "object"
    ? Object.values(responseData.errors).flat().filter(Boolean)
    : [];

  return {
    responseData,
    nestedData,
    status: source?.response?.status || responseData?.status || null,
    apiMessage: firstNonEmpty(responseData?.message, nestedData?.message),
    backendError: firstNonEmpty(
      responseData?.error,
      nestedData?.error,
      responseData?.detail,
      nestedData?.detail,
      validationErrors.join("\n"),
      source?.message && !source?.isAxiosError ? source.message : ""
    ),
    code: firstNonEmpty(responseData?.code, nestedData?.code, responseData?.error_code, nestedData?.error_code),
  };
};

const extractSaleErrorDetails = (source, fallback = "Sale failed.") => {
  const payload = getBackendErrorPayload(source);
  const { responseData, nestedData } = payload;

  const fallbackMessage = getApiMessage(source, fallback);
  const displayMessage = uniqueLines(payload.apiMessage, payload.backendError, fallbackMessage) || fallback;
  const parseText = uniqueLines(
    payload.backendError,
    nestedData?.stock_error,
    nestedData?.stock_message,
    payload.apiMessage,
    fallbackMessage
  );

  const structuredIngredient = firstNonEmpty(
    nestedData?.ingredient,
    nestedData?.ingredient_name,
    nestedData?.product_name,
    responseData?.ingredient,
    responseData?.ingredient_name,
    responseData?.product_name
  );
  const structuredRequired = firstNonEmpty(nestedData?.required, nestedData?.required_qty, responseData?.required, responseData?.required_qty);
  const structuredAvailable = firstNonEmpty(nestedData?.available, nestedData?.available_qty, responseData?.available, responseData?.available_qty);
  const structuredArea = firstNonEmpty(nestedData?.stock_area, nestedData?.area, responseData?.stock_area, responseData?.area);

  if (structuredIngredient && structuredRequired !== "" && structuredAvailable !== "") {
    const area = String(structuredArea || "").toUpperCase();

    return {
      type: area === "FRONT" ? "front_stock" : "stock",
      title: area === "FRONT" ? "Front Stock Not Enough" : "Stock Not Enough",
      headline: area === "FRONT"
        ? "This sale cannot be completed because an ingredient has insufficient FRONT stock."
        : "This sale cannot be completed because stock is not enough.",
      area: area || null,
      ingredient: structuredIngredient,
      required: structuredRequired,
      available: structuredAvailable,
      apiMessage: payload.apiMessage,
      backendError: payload.backendError,
      code: payload.code,
      status: payload.status,
      message: displayMessage,
      raw: responseData,
    };
  }

  const stockMatch = parseText.match(
    /Insufficient\s+([A-Z_ ]+)?\s*stock\s+for\s+(?:ingredient\s+)?(.+?)\.\s*Required:\s*([0-9.,-]+),\s*Available:\s*([0-9.,-]+)/i
  );

  if (stockMatch) {
    const area = String(stockMatch[1] || "").trim().toUpperCase();

    return {
      type: area === "FRONT" ? "front_stock" : "stock",
      title: area === "FRONT" ? "Front Stock Not Enough" : "Stock Not Enough",
      headline: area === "FRONT"
        ? "This sale cannot be completed because an ingredient has insufficient FRONT stock."
        : "This sale cannot be completed because stock is not enough.",
      area: area || null,
      ingredient: stockMatch[2],
      required: stockMatch[3],
      available: stockMatch[4],
      apiMessage: payload.apiMessage,
      backendError: payload.backendError,
      code: payload.code,
      status: payload.status,
      message: displayMessage,
      raw: responseData,
    };
  }

  const shiftCutoffReached = String(payload.code || "").toUpperCase() === "SHIFT_SALES_CUTOFF_REACHED";

  if (shiftCutoffReached) {
    return {
      type: "shift_cutoff",
      title: "Shift Selling Window Ended",
      headline: "This shift has reached its configured end and cash-up is now required.",
      apiMessage: payload.apiMessage,
      backendError: payload.backendError,
      code: payload.code,
      status: payload.status,
      message: displayMessage,
      raw: responseData,
    };
  }

  const shiftFailure = /shift/i.test(String(payload.code || ""))
    || /(?:no open shift|shift cashup|shift session)/i.test(parseText);

  if (shiftFailure) {
    return {
      type: "shift",
      title: "Shift Not Open",
      headline: "This cashier does not have an active selling shift.",
      apiMessage: payload.apiMessage,
      backendError: payload.backendError,
      code: payload.code,
      status: payload.status,
      message: displayMessage,
      raw: responseData,
    };
  }

  return {
    type: "sale",
    title: "Sale Failed",
    headline: "The sale could not be completed.",
    apiMessage: payload.apiMessage,
    backendError: payload.backendError,
    code: payload.code,
    status: payload.status,
    message: displayMessage,
    raw: responseData,
  };
};

const getApiMessage = (source, fallback = "Request failed.") => {
  const responseData = source?.response?.data;

  if (source?.displayMessage) return source.displayMessage;

  if (responseData?.errors && typeof responseData.errors === "object") {
    return Object.values(responseData.errors).flat().join("\n");
  }

  if (responseData?.data && typeof responseData.data === "object") {
    const values = Object.values(responseData.data).flat();
    if (values.length > 0) return values.join("\n");
  }

  if (responseData?.error) return responseData.error;
  if (responseData?.message) return responseData.message;
  if (source?.message && !source?.isAxiosError) return source.message;

  return fallback;
};

const getConvertedAmount = (response) => {
  const data = response?.data || response || {};

  return (
    data?.converted_amount ??
    data?.convertedAmount ??
    data?.to_amount ??
    data?.converted ??
    data?.amount ??
    data?.result ??
    0
  );
};

const CreateSaleModal = ({ open, onClose, configuredLayout = null }) => {
  const barcodeInputRef = useRef(null);
  const conversionCacheRef = useRef({});
  const { isHospitality, workspace: workspaceData } = useBusinessMode();
  const { user: frontOfficeUser, updateUser: updateFrontOfficeUser } = useFrontAuth();
  const { user: backOfficeUser } = useAdminAuth();
  const isFrontOfficeWorkspace = window.location.pathname.includes("/front");
  const loggedInUser = isFrontOfficeWorkspace ? frontOfficeUser : backOfficeUser;
  const workspaceUser = workspaceData?.user || null;
  const configuredMenu = workspaceData?.menu || null;
  const configuredMenuItems = Array.isArray(configuredMenu?.items)
    ? configuredMenu.items
    : [];
  const configuredMenuCategories = Array.isArray(configuredMenu?.categories)
    ? configuredMenu.categories
    : [];
  const hasAuthoritativeTillMenu = Boolean(
    isFrontOfficeWorkspace && configuredMenu?.authoritative,
  );
  const canCaptureCreditCustomerPayment = useMemo(() => {
    if (!isFrontOfficeWorkspace) return false;
    if (userIsSuperAdmin(loggedInUser, workspaceUser)) return true;
    return permissionNamesForUser(loggedInUser, workspaceUser).has("capture_credit_customer_payment");
  }, [isFrontOfficeWorkspace, loggedInUser, workspaceUser]);
  const canClearOpenTables = useMemo(() => {
    if (userIsSuperAdmin(loggedInUser, workspaceUser)) return true;
    return permissionNamesForUser(loggedInUser, workspaceUser).has("clear_open_tables");
  }, [loggedInUser, workspaceUser]);
  const operatorName = loggedInUser?.name || loggedInUser?.username || "Operator";
  const activeShift = loggedInUser?.active_shift || loggedInUser?.active_shift_session || null;
  const activeShiftName = String(activeShift?.shift_name || "").trim();
  const activeShiftLabel = activeShiftName
    ? `${activeShiftName.charAt(0).toUpperCase()}${activeShiftName.slice(1)} shift`
    : "No open shift";
  const trustedSalesLayout = normalizeSalesLayout(
    configuredLayout ||
    workspaceData?.operation?.sales_screen_layout ||
    loggedInUser?.sales_screen_layout
  );

  const [products, setProducts] = useState([]);
  const [categories, setCategories] = useState([]);
  const [paymentTypes, setPaymentTypes] = useState([]);
  const [currencies, setCurrencies] = useState([]);
  const [creditCustomers, setCreditCustomers] = useState([]);
  const [accountCustomers, setAccountCustomers] = useState([]);

  const [loadingProducts, setLoadingProducts] = useState(false);
  const [loadingCategories, setLoadingCategories] = useState(false);
  const [loadingBarcodeScanSetting, setLoadingBarcodeScanSetting] = useState(false);
  const [barcodeScanEnabled, setBarcodeScanEnabled] = useState(false);
  const [loadingPayments, setLoadingPayments] = useState(false);
  const [loadingCurrencies, setLoadingCurrencies] = useState(false);
  const [loadingCreditCustomers, setLoadingCreditCustomers] = useState(false);
  const [loadingAccountCustomers, setLoadingAccountCustomers] = useState(false);
  const [convertingCurrency, setConvertingCurrency] = useState(false);
  const [savingSale, setSavingSale] = useState(false);
  const [printingReceipt, setPrintingReceipt] = useState(false);

  const [loadingShiftSummary, setLoadingShiftSummary] = useState(false);
  const [savingCashup, setSavingCashup] = useState(false);
  const [shiftSummary, setShiftSummary] = useState(null);

  const [cashupPayments, setCashupPayments] = useState([]);
  const [cashupNotes, setCashupNotes] = useState("");
  const [endShiftPayments, setEndShiftPayments] = useState([]);
  const [endShiftNotes, setEndShiftNotes] = useState("");

  const [reportsLoading, setReportsLoading] = useState(false);
  const [reportsSnapshot, setReportsSnapshot] = useState(null);

  const [cart, setCart] = useState([]);
  const [clientSaleId, setClientSaleId] = useState(createSaleAttemptId);
  const hospitalityClientRequestIdRef = useRef(createHospitalityRequestId());
  const hospitalityPersistTimerRef = useRef(null);
  const hospitalityPersistedSignatureRef = useRef("");
  const hospitalityPersistenceSuspendedRef = useRef(false);
  // clearSale is declared further down; recall reaches the current one via this ref.
  const clearSaleRef = useRef(null);
  const [hospitalitySettings, setHospitalitySettings] = useState({});
  const [hospitalityTables, setHospitalityTables] = useState([]);
  const [hospitalityOpenOrders, setHospitalityOpenOrders] = useState([]);
  const [hospitalityServiceType, setHospitalityServiceType] = useState("takeaway");
  const [hospitalityAssignmentMode, setHospitalityAssignmentMode] = useState("manual");
  const [hospitalitySelectedTableId, setHospitalitySelectedTableId] = useState("");
  const [hospitalityCurrentOrder, setHospitalityCurrentOrder] = useState(null);
  const [hospitalityBusy, setHospitalityBusy] = useState(false);
  const [hospitalitySaveError, setHospitalitySaveError] = useState("");
  const [selectedLineId, setSelectedLineId] = useState(null);
  const [pendingEditLineId, setPendingEditLineId] = useState(null);
  const [prepMessageModalOpen, setPrepMessageModalOpen] = useState(false);
  const [modifierModalOpen, setModifierModalOpen] = useState(false);
  const [splitLineModalOpen, setSplitLineModalOpen] = useState(false);

  const [rightMode, setRightMode] = useState("browse");
  // Do not mount either configurable sales face until the saved layout is known.
  // Starting with "standard" caused that face to flash before a configured
  // visual layout arrived from the API.
  const [salesLayout, setSalesLayout] = useState(trustedSalesLayout);
  const [loadingSalesLayout, setLoadingSalesLayout] = useState(!trustedSalesLayout);

  const [selectedCategoryId, setSelectedCategoryId] = useState("all");
  const [browseTab, setBrowseTab] = useState("categories");
  const [search, setSearch] = useState("");
  const [barcodeInput, setBarcodeInput] = useState("");

  const [selectedPaymentTypeId, setSelectedPaymentTypeId] = useState("");
  const [saleCurrencyCode, setSaleCurrencyCode] = useState("");
  const [exchangeRateQuote, setExchangeRateQuote] = useState(null);
  const [amountReceived, setAmountReceived] = useState("");
  const [paymentRows, setPaymentRows] = useState([]);
  const [activePaymentRowId, setActivePaymentRowId] = useState("");
  const [splitPaymentModalOpen, setSplitPaymentModalOpen] = useState(false);
  const [selectedCreditCustomerId, setSelectedCreditCustomerId] = useState("");
  const [creditPoNumber, setCreditPoNumber] = useState("");
  const [postChangeToCreditAccount, setPostChangeToCreditAccount] = useState(false);
  const [changeCreditCustomerId, setChangeCreditCustomerId] = useState("");
  const [customerAccountModalOpen, setCustomerAccountModalOpen] = useState(false);
  const [changeCreditReceipt, setChangeCreditReceipt] = useState(null);
  const [changeCreditReceiptBusy, setChangeCreditReceiptBusy] = useState("");

  const [notice, setNotice] = useState("");
  const [saleError, setSaleError] = useState(null);
  const [saleComplete, setSaleComplete] = useState(false);
  const [lastSaleResponse, setLastSaleResponse] = useState(null);

  const [quoteCode, setQuoteCode] = useState("");
  const [loadedQuotation, setLoadedQuotation] = useState(null);
  const [loadingQuotation, setLoadingQuotation] = useState(false);
  const [loadQuoteModalOpen, setLoadQuoteModalOpen] = useState(false);

  const [actionPanel, setActionPanel] = useState(null);
  const [priceOverrideConfig, setPriceOverrideConfig] = useState({
    enabled: false,
    requires_manager_approval: true,
    cashier_can_override_without_manager: false,
  });
  const [priceOverrideLoading, setPriceOverrideLoading] = useState(false);
  const [priceOverrideRows, setPriceOverrideRows] = useState([]);
  const [priceOverrideContext, setPriceOverrideContext] = useState(null);
  const [pendingPriceOverride, setPendingPriceOverride] = useState(null);
  const [managerApprovalOpen, setManagerApprovalOpen] = useState(false);
  const [discountModalOpen, setDiscountModalOpen] = useState(false);
  const [enterpriseCashupOpen, setEnterpriseCashupOpen] = useState(false);
  const [enterpriseEndShiftOpen, setEnterpriseEndShiftOpen] = useState(false);
  const [editQty, setEditQty] = useState("");
  const [discountValue, setDiscountValue] = useState("");

  const [customer, setCustomer] = useState({
    name: "Walk-in Customer",
    phone: "",
    taxNumber: "",
    notes: "",
  });

  const selectedLine = cart.find((line) => line.lineId === selectedLineId);

  const selectedPaymentType = useMemo(() => {
    return paymentTypes.find((item) => item.id === selectedPaymentTypeId) || null;
  }, [paymentTypes, selectedPaymentTypeId]);

  const paymentTypeById = useMemo(() => {
    const map = new Map();
    paymentTypes.forEach((item) => map.set(String(item.id), item));
    return map;
  }, [paymentTypes]);

  const activePaymentRows = useMemo(() => {
    if (paymentRows.length > 0) {
      return paymentRows.map((row) => ({
        ...row,
        payment_type_id: row.payment_type_id || selectedPaymentTypeId || "",
        currency_code: row.currency_code || saleCurrencyCode || "",
      }));
    }

    if (!selectedPaymentTypeId) return [];

    return [
      createPaymentRow({
        paymentTypeId: selectedPaymentTypeId,
        currencyCode: saleCurrencyCode,
        amount: amountReceived,
      }),
    ];
  }, [paymentRows, selectedPaymentTypeId, saleCurrencyCode, amountReceived]);

  const activePaymentRow = useMemo(() => {
    return (
      activePaymentRows.find((row) => row.id === activePaymentRowId) ||
      activePaymentRows[0] ||
      null
    );
  }, [activePaymentRows, activePaymentRowId]);

  const activePaymentAmount = activePaymentRow?.amount || "";

  const activePaymentReceived = useMemo(() => {
    return sumPaymentRows(activePaymentRows);
  }, [activePaymentRows]);

  const selectedCreditCustomer = useMemo(() => {
    return (
      creditCustomers.find(
        (item) => String(item.id) === String(selectedCreditCustomerId)
      ) || null
    );
  }, [creditCustomers, selectedCreditCustomerId]);

  const isCreditSale = useMemo(() => {
    return isCustomerCreditPaymentType(selectedPaymentType);
  }, [selectedPaymentType]);


  const paymentCanChooseCurrency = useMemo(() => {
    return paymentSupportsMultiCurrency(selectedPaymentType);
  }, [selectedPaymentType]);

  const amountTenderedRequired = useMemo(() => {
    return paymentRequiresAmountTendered(selectedPaymentType);
  }, [selectedPaymentType]);

  const categoryOptions = useMemo(() => {
    return [
      {
        id: "all",
        name: "All",
        code: "ALL",
      },
      ...categories.filter((category) => category?.is_active !== false),
    ];
  }, [categories]);

  const selectedCategory = useMemo(() => {
    return (
      categoryOptions.find((category) => category.id === selectedCategoryId) ||
      categoryOptions[0]
    );
  }, [categoryOptions, selectedCategoryId]);

  const filteredProducts = useMemo(() => {
    const q = search.toLowerCase().trim();

    return products.filter((product) => {
      if (product?.is_active === false) return false;
      if (!productMatchesPosType(product)) return false;

      const categoryMatch =
        selectedCategoryId === "all" || product.category_id === selectedCategoryId;

      const barcodeValues = getProductBarcodeValues(product).join(" ");

      const searchMatch =
        !q ||
        normalizeText(product.name).includes(q) ||
        normalizeText(product.sku).includes(q) ||
        normalizeText(product.code).includes(q) ||
        normalizeText(product.barcode).includes(q) ||
        normalizeText(product.category?.name).includes(q) ||
        normalizeText(product.category?.code).includes(q) ||
        normalizeText(barcodeValues).includes(q);

      return categoryMatch && searchMatch;
    });
  }, [products, selectedCategoryId, search]);




  const getLineGross = useCallback((line) => {
    return calculateTaxBreakdown({
      price: getLineEffectiveUnitPrice(line),
      qty: line?.qty || line?.quantity || 0,
      discount: 0,
      taxes: getLineTaxes(line),
    }).gross;
  }, []);

  const getLineDiscount = useCallback(
    (line) => {
      const payloadDiscount = Number(line?.discountPayload?.discount_amount || 0);
      const normalDiscount = Number(line?.discount || 0);
      const discount = payloadDiscount || normalDiscount;

      return Math.min(Math.max(discount, 0), getLineGross(line));
    },
    [getLineGross]
  );

  const getLineTaxBreakdown = useCallback(
    (line) =>
      calculateTaxBreakdown({
        price: getLineEffectiveUnitPrice(line),
        qty: line?.qty || line?.quantity || 0,
        discount: getLineDiscount(line),
        taxes: getLineTaxes(line),
      }),
    [getLineDiscount]
  );

  const getLineSubtotal = useCallback(
    (line) => getLineTaxBreakdown(line).taxableAmount,
    [getLineTaxBreakdown]
  );

  const getLineTax = useCallback(
    (line) => getLineTaxBreakdown(line).tax,
    [getLineTaxBreakdown]
  );

  const getLineInclusiveTax = useCallback(
    (line) => getLineTaxBreakdown(line).inclusiveTax,
    [getLineTaxBreakdown]
  );

  const getLineExclusiveTax = useCallback(
    (line) => getLineTaxBreakdown(line).exclusiveTax,
    [getLineTaxBreakdown]
  );

  const getLineTotal = useCallback(
    (line) => getLineTaxBreakdown(line).total,
    [getLineTaxBreakdown]
  );

  const toHospitalityItem = useCallback((line) => ({
    product_id: line.product_id || line.productId,
    product_price_id: line.productPriceId || null,
    quantity: Number(line.qty || line.quantity || 0),
    unit_price: Number(getLineEffectiveUnitPrice(line).toFixed(4)),
    line_discount: Number(getLineDiscount(line).toFixed(4)),
    line_tax: Number(getLineTax(line).toFixed(4)),
    name: line.name || line.product_name || "Menu item",
    metadata: {
      pos_line: {
        client_line_id: line.lineId,
        sku: line.sku || null,
        code: line.code || null,
        barcode: line.barcode || null,
        category_id: line.category_id || line.categoryId || null,
        category_name: line.categoryName || null,
        unit: line.unit || null,
        allow_decimal_qty: Boolean(line.allowDecimalQty),
        decimal_places: Number(line.decimalPlaces || 0),
        prep_message_ids: line.prep_message_ids || line.prepMessageIds || [],
        prep_messages: line.prep_messages || line.prepMessages || [],
        prep_note: line.prep_note || line.prepNote || null,
        modifier_ids: line.modifier_ids || line.modifierIds || [],
        modifiers: line.modifiers || [],
        taxes: line.taxes || line.productTaxes || [],
        discount_payload: line.discountPayload || null,
      },
    },
  }), [getLineDiscount, getLineTax]);

  const hospitalityPayloadForCart = useCallback((lines, options = {}) => ({
    ...(options.create ? { client_request_id: hospitalityClientRequestIdRef.current } : {}),
    service_type: hospitalityServiceType,
    ...(hospitalityServiceType === "dine_in"
      ? {
          assignment_mode: hospitalityAssignmentMode === "automatic" ? "auto" : "manual",
          ...(hospitalityAssignmentMode === "manual"
            ? { table_id: hospitalitySelectedTableId || null }
            : {}),
          guest_count: Number(hospitalityCurrentOrder?.guest_count || 1),
        }
      : {}),
    notes: customer.notes?.trim() || null,
    currency_code: saleCurrencyCode || lines[0]?.currency || "USD",
    items: lines.map(toHospitalityItem),
  }), [
    hospitalityAssignmentMode,
    hospitalityCurrentOrder?.guest_count,
    hospitalitySelectedTableId,
    hospitalityServiceType,
    customer.notes,
    saleCurrencyCode,
    toHospitalityItem,
  ]);

  const restoreHospitalityLines = useCallback((order) => {
    // The table-order API returns its saved lines as `lines`.
    const items = Array.isArray(order?.lines)
      ? order.lines
      : Array.isArray(order?.items)
      ? order.items
      : Array.isArray(order?.order_items)
      ? order.order_items
      : [];

    return items.map((item) => {
      const productId = item.product_id || item.product?.id;
      const product = products.find((candidate) => String(candidate.id) === String(productId)) || item.product || {};
      const saved = item?.metadata?.pos_line || item?.meta?.pos_line || {};
      const unitPrice = Number(item.unit_price ?? item.price ?? 0);
      const taxes = Array.isArray(saved.taxes) ? saved.taxes : getProductTaxesForSale(product);

      return {
        lineId: saved.client_line_id || item.client_line_id || item.line_id || createLineId(productId || "recalled"),
        productId,
        product_id: productId,
        productPriceId: item.product_price_id || null,
        baseProductPriceId: item.product_price_id || null,
        sku: saved.sku || product.sku || "-",
        code: saved.code || product.code || "-",
        barcode: saved.barcode || product.barcode || "-",
        name: item.line_name || item.name || product.name || "Menu item",
        categoryId: saved.category_id || product.category_id || product.category?.id || null,
        category_id: saved.category_id || product.category_id || product.category?.id || null,
        categoryName: saved.category_name || product.category?.name || "Uncategorised",
        basePrice: unitPrice,
        baseCurrency: order.currency_code || saleCurrencyCode || "USD",
        baseMenuPrice: unitPrice,
        menuBasePrice: unitPrice,
        modifierUnitTotal: 0,
        prep_message_ids: saved.prep_message_ids || [],
        prepMessageIds: saved.prep_message_ids || [],
        prep_messages: saved.prep_messages || [],
        prepMessages: saved.prep_messages || [],
        prep_note: saved.prep_note || null,
        prepNote: saved.prep_note || null,
        modifier_ids: saved.modifier_ids || [],
        modifierIds: saved.modifier_ids || [],
        modifiers: saved.modifiers || [],
        price: unitPrice,
        currency: order.currency_code || saleCurrencyCode || "USD",
        qty: Number(item.quantity || 0),
        unit: saved.unit || getProductUnit(product),
        allowDecimalQty: Boolean(saved.allow_decimal_qty ?? isDecimalProduct(product)),
        decimalPlaces: Number(saved.decimal_places ?? getProductDecimalPlaces(product)),
        taxable: taxes.length > 0,
        taxes,
        productTaxes: taxes,
        taxSummary: getProductTaxSummary(product),
        taxRate: Number(product.tax?.rate || product.tax_rate || 0),
        discount: Number(item.line_discount || item.discount_amount || 0),
        discountPayload: saved.discount_payload || null,
        availableStock: getProductAvailableStock(product),
        rawProduct: product,
      };
    });
  }, [products, saleCurrencyCode]);

  const getLineTaxSummary = useCallback((line) => {
    const taxes = getLineTaxes(line);
    if (!taxes.length) return 'No Tax';
    return taxes.map(getTaxDisplayName).join(' + ');
  }, []);

  const totals = useMemo(() => {
    const breakdowns = cart.map((line) => getLineTaxBreakdown(line));

    const subTotal = breakdowns.reduce((sum, item) => sum + item.taxableAmount, 0);
    const discount = breakdowns.reduce((sum, item) => sum + item.discount, 0);
    const inclusiveTax = breakdowns.reduce((sum, item) => sum + item.inclusiveTax, 0);
    const exclusiveTax = breakdowns.reduce((sum, item) => sum + item.exclusiveTax, 0);
    const tax = inclusiveTax + exclusiveTax;
    const total = breakdowns.reduce((sum, item) => sum + item.total, 0);
    const received = isCreditSale
      ? 0
      : amountTenderedRequired
      ? Number(activePaymentReceived || 0)
      : total;
    const balance = isCreditSale ? total : Math.max(total - received, 0);
    const change = isCreditSale ? 0 : Math.max(received - total, 0);

    return {
      subTotal,
      discount,
      taxableAmount: subTotal,
      inclusiveTax,
      exclusiveTax,
      tax,
      total,
      received,
      balance,
      change,
      currency: saleCurrencyCode || cart[0]?.currency || 'USD',
    };
  }, [
    cart,
    amountReceived,
    activePaymentReceived,
    amountTenderedRequired,
    isCreditSale,
    saleCurrencyCode,
    getLineTaxBreakdown,
  ]);

  const creditAvailable = useMemo(() => {
    if (!selectedCreditCustomer) return 0;
    return getCreditCustomerAvailableCredit(selectedCreditCustomer);
  }, [selectedCreditCustomer]);

  const creditLimitExceeded = useMemo(() => {
    if (!isCreditSale || !selectedCreditCustomer) return false;
    return Number(totals.total || 0) > Number(creditAvailable || 0);
  }, [isCreditSale, selectedCreditCustomer, creditAvailable, totals.total]);

  useEffect(() => {
    if (Number(totals.change || 0) > 0.0001 && !isCreditSale) return;
    setPostChangeToCreditAccount(false);
    setChangeCreditCustomerId("");
  }, [isCreditSale, totals.change]);

  useEffect(() => {
    // A different tender, currency or sale total is a new customer decision.
    // Never carry consent to retain change across those changes.
    setPostChangeToCreditAccount(false);
    setChangeCreditCustomerId("");
  }, [saleCurrencyCode, selectedPaymentTypeId, totals.total]);

  useEffect(() => {
    if (canCaptureCreditCustomerPayment) return;
    setPostChangeToCreditAccount(false);
    setChangeCreditCustomerId("");
    setCustomerAccountModalOpen(false);
  }, [canCaptureCreditCustomerPayment]);

  // A newer notice replaces the previous one, so its timer must not cut the
  // newer (possibly longer) notice short.
  const noticeTimerRef = useRef(null);
  const showNotice = (message, { duration = 2800 } = {}) => {
    setNotice(message);

    window.clearTimeout(noticeTimerRef.current);
    noticeTimerRef.current = window.setTimeout(() => {
      setNotice("");
    }, duration);
  };

  const focusBarcode = () => {
    window.setTimeout(() => {
      if (barcodeScanEnabled) {
        barcodeInputRef.current?.focus();
      }
    }, 80);
  };

  const normalizeHospitalityOrder = useCallback((payload) => (
    payload?.order || payload?.table_order || payload?.data || payload || null
  ), []);

  const loadHospitalityWorkspace = useCallback(async ({ quiet = false } = {}) => {
    if (!isHospitality || !isFrontOfficeWorkspace) return;

    if (!quiet) setHospitalityBusy(true);
    try {
      const [service, orders] = await Promise.all([
        getHospitalityTableService(),
        getHospitalityTableOrders(),
      ]);
      const settings = service?.settings || service?.configuration || {};
      const rows = Array.isArray(service?.tables)
        ? service.tables
        : Array.isArray(service?.data?.tables)
        ? service.data.tables
        : [];
      const configuredService = String(
        settings.default_service_type || settings.service_type || "takeaway"
      ).toLowerCase();
      const configuredAssignment = String(
        settings.assignment_mode || settings.table_assignment_mode || "manual"
      ).toLowerCase();

      setHospitalitySettings(settings);
      setHospitalityTables(rows);
      setHospitalityOpenOrders(orders);
      if (!hospitalityCurrentOrder && cart.length === 0) {
        setHospitalityServiceType(
          ["dine_in", "takeaway"].includes(configuredService) ? configuredService : "takeaway"
        );
        setHospitalityAssignmentMode(
          ["automatic", "auto"].includes(configuredAssignment) ? "automatic" : "manual"
        );
      }
      setHospitalitySaveError("");
    } catch (error) {
      const message = getApiMessage(error, "Table service could not be loaded.");
      setHospitalitySaveError(message);
      if (!quiet) showNotice(message);
    } finally {
      if (!quiet) setHospitalityBusy(false);
    }
  }, [isHospitality, isFrontOfficeWorkspace, hospitalityCurrentOrder, cart.length]);

  const persistHospitalityCart = useCallback(async (lines = cart, { force = false } = {}) => {
    if (!isHospitality || !isFrontOfficeWorkspace || hospitalityPersistenceSuspendedRef.current) {
      return hospitalityCurrentOrder;
    }
    if (!Array.isArray(lines) || lines.length === 0) return hospitalityCurrentOrder;
    if (
      hospitalityServiceType === "dine_in" &&
      hospitalityAssignmentMode === "manual" &&
      !hospitalitySelectedTableId &&
      !hospitalityCurrentOrder?.table_id
    ) {
      const message = "Choose a restaurant table before saving this dine-in order.";
      setHospitalitySaveError(message);
      if (force) showNotice(message);
      return null;
    }

    const signature = JSON.stringify({
      order: hospitalityOrderId(hospitalityCurrentOrder) || "new",
      serviceType: hospitalityServiceType,
      assignmentMode: hospitalityAssignmentMode,
      tableId: hospitalitySelectedTableId || null,
      currency: saleCurrencyCode,
      items: lines.map(toHospitalityItem),
    });
    if (!force && hospitalityPersistedSignatureRef.current === signature) {
      return hospitalityCurrentOrder;
    }

    setHospitalityBusy(true);
    setHospitalitySaveError("");
    try {
      let saved;
      const currentId = hospitalityOrderId(hospitalityCurrentOrder);
      if (currentId) {
        saved = normalizeHospitalityOrder(await updateHospitalityTableOrder(currentId, {
          ...hospitalityPayloadForCart(lines),
          expected_revision: hospitalityOrderRevision(hospitalityCurrentOrder),
        }));
      } else {
        saved = normalizeHospitalityOrder(await createHospitalityTableOrder(
          hospitalityPayloadForCart(lines, { create: true })
        ));
      }

      if (saved) {
        setHospitalityCurrentOrder(saved);
        const savedTableId = saved?.table_id || saved?.table?.id || saved?.hospitality_table?.id;
        if (savedTableId) setHospitalitySelectedTableId(savedTableId);
      }
      hospitalityPersistedSignatureRef.current = signature;
      loadHospitalityWorkspace({ quiet: true });
      return saved || hospitalityCurrentOrder;
    } catch (error) {
      const message = getApiMessage(
        error,
        hospitalityOrderId(hospitalityCurrentOrder)
          ? "The open table changed elsewhere. Refresh it before continuing."
          : "The order is still on screen, but its table could not be reserved. Choose another table or retry."
      );
      setHospitalitySaveError(message);
      showNotice(message);
      return null;
    } finally {
      setHospitalityBusy(false);
    }
  }, [
    cart,
    hospitalityAssignmentMode,
    hospitalityCurrentOrder,
    hospitalityPayloadForCart,
    hospitalitySelectedTableId,
    hospitalityServiceType,
    isFrontOfficeWorkspace,
    isHospitality,
    loadHospitalityWorkspace,
    normalizeHospitalityOrder,
    saleCurrencyCode,
    toHospitalityItem,
  ]);

  useEffect(() => {
    if (!open || !isHospitality || !isFrontOfficeWorkspace || cart.length === 0 || saleComplete) return undefined;
    window.clearTimeout(hospitalityPersistTimerRef.current);
    hospitalityPersistTimerRef.current = window.setTimeout(() => {
      persistHospitalityCart(cart);
    }, hospitalityCurrentOrder ? 450 : 0);

    return () => window.clearTimeout(hospitalityPersistTimerRef.current);
  }, [
    cart,
    hospitalityCurrentOrder,
    hospitalitySelectedTableId,
    hospitalityServiceType,
    isFrontOfficeWorkspace,
    isHospitality,
    open,
    persistHospitalityCart,
    saleComplete,
  ]);

  // Loads a saved open/hanging order back onto the till's selling screen. With
  // { pay: true } it also tells the cashier to take payment with Pay.
  const recallHospitalityOrder = useCallback(async (summary, { pay = false } = {}) => {
    const currentId = hospitalityOrderId(hospitalityCurrentOrder);
    const switchingOrder = currentId !== hospitalityOrderId(summary);
    if (switchingOrder && cart.length > 0) {
      if (!currentId) {
        showNotice("Park the current order before recalling another table.");
        return;
      }
      // The order on screen is already saved: park it as hanging first, the
      // same as "Use later", so it stays in the open orders list.
      if (await clearSaleRef.current?.() === false) return;
    }

    setHospitalityBusy(true);
    setHospitalitySaveError("");
    try {
      let recalled = summary;
      if (String(summary?.status || "").toLowerCase() === "hanging") {
        recalled = normalizeHospitalityOrder(await recallHospitalityTableOrder(
          hospitalityOrderId(summary),
          hospitalityOrderRevision(summary),
        ));
      }
      const detail = normalizeHospitalityOrder(await getHospitalityTableOrder(
        hospitalityOrderId(recalled || summary)
      ));
      const order = detail || recalled || summary;
      const restored = restoreHospitalityLines(order);
      const nextServiceType = order.service_type || order.service_mode || "dine_in";
      const nextTableId = order.table_id || order.table?.id || order.hospitality_table?.id || "";
      const nextAssignmentMode = ["auto", "automatic"].includes(
        String(order.assignment_mode || order.table_assignment_mode || hospitalityAssignmentMode).toLowerCase(),
      ) ? "automatic" : "manual";

      hospitalityPersistenceSuspendedRef.current = true;
      setHospitalityCurrentOrder(order);
      setHospitalityServiceType(nextServiceType);
      setHospitalityAssignmentMode(nextAssignmentMode);
      setHospitalitySelectedTableId(nextTableId);
      setSaleCurrencyCode(order.currency_code || saleCurrencyCode);
      setCart(restored);
      setSelectedLineId(restored[0]?.lineId || null);
      setSaleComplete(false);
      const orderLabel = order?.order_number || "Order";
      let recallNotice = `${orderLabel} recalled.`;
      // Payment happens on the selling screen: the cash is typed once on the
      // keypad (or left blank for the exact amount) and Pay completes it.
      setActionPanel(null);
      setRightMode("browse");
      if (pay && restored.length === 0) {
        recallNotice = `${orderLabel} has no items to pay for.`;
      } else if (pay && paymentTypes.length === 0) {
        recallNotice = `${orderLabel} recalled. No payment methods are set up for this shop. Add one in Back Office → Store Configuration → Payment Method with this shop selected.`;
      } else if (pay) {
        if (!selectedPaymentTypeId) setSelectedPaymentTypeId(paymentTypes[0].id);
        recallNotice = `${orderLabel} recalled for payment. Type the cash received (or nothing for the exact amount) and press Pay.`;
      }
      hospitalityPersistedSignatureRef.current = JSON.stringify({
        order: hospitalityOrderId(order),
        serviceType: nextServiceType,
        assignmentMode: nextAssignmentMode,
        tableId: nextTableId || null,
        currency: order.currency_code || saleCurrencyCode,
        items: restored.map(toHospitalityItem),
      });
      window.setTimeout(() => { hospitalityPersistenceSuspendedRef.current = false; }, 0);
      showNotice(recallNotice);
      loadHospitalityWorkspace({ quiet: true });
    } catch (error) {
      const message = getApiMessage(error, "The open table could not be recalled.");
      setHospitalitySaveError(message);
      showNotice(message);
    } finally {
      setHospitalityBusy(false);
    }
  }, [
    cart.length,
    hospitalityAssignmentMode,
    hospitalityCurrentOrder,
    loadHospitalityWorkspace,
    normalizeHospitalityOrder,
    paymentTypes,
    restoreHospitalityLines,
    saleCurrencyCode,
    selectedPaymentTypeId,
    toHospitalityItem,
  ]);

  const clearOpenHospitalityOrder = useCallback(async (order, reason, approvalId = null) => {
    setHospitalityBusy(true);
    try {
      await clearHospitalityTableOrder(hospitalityOrderId(order), {
        expected_revision: hospitalityOrderRevision(order),
        reason,
        manager_approval_id: approvalId,
      });
      if (hospitalityOrderId(order) === hospitalityOrderId(hospitalityCurrentOrder)) {
        hospitalityPersistenceSuspendedRef.current = true;
        setHospitalityCurrentOrder(null);
        setHospitalitySelectedTableId("");
        setCart([]);
        setSelectedLineId(null);
        hospitalityPersistedSignatureRef.current = "";
        hospitalityClientRequestIdRef.current = createHospitalityRequestId();
        window.setTimeout(() => { hospitalityPersistenceSuspendedRef.current = false; }, 0);
      }
      showNotice("Open table cleared and released.");
      await loadHospitalityWorkspace({ quiet: true });
    } catch (error) {
      const message = getApiMessage(error, "The open table could not be cleared.");
      setHospitalitySaveError(message);
      showNotice(message);
      throw error;
    } finally {
      setHospitalityBusy(false);
    }
  }, [hospitalityCurrentOrder, loadHospitalityWorkspace]);

  const sendCurrentOrderToKitchen = useCallback(async () => {
    setHospitalityBusy(true);
    try {
      const saved = await persistHospitalityCart(cart, { force: true });
      if (!saved) return;
      const response = await sendHospitalityTableOrderToKitchen(hospitalityOrderId(saved), {
        expected_revision: hospitalityOrderRevision(saved),
        command_id: createHospitalityRequestId(),
      });
      const envelope = response?.data || {};
      const updatedOrder = envelope?.data?.order || envelope?.data || envelope?.order || null;
      const kitchenPrint = envelope?.data?.kitchen_print || envelope?.kitchen_print || null;
      if (updatedOrder) setHospitalityCurrentOrder(updatedOrder);
      const printResult = await dispatchSalePrints(response, { printCustomer: false, printKitchen: true });
      const printerName = kitchenPrint?.printer?.printer_name || "the kitchen printer";
      const orderNumber = kitchenPrint?.payload?.order_number || updatedOrder?.order_number || "Order";
      showNotice(printResult.kitchen
        ? `${orderNumber} sent to ${printerName}.`
        : `${orderNumber} queued for kitchen. Automatic QZ printing is not ready or is switched off.`);
      await loadHospitalityWorkspace({ quiet: true });
    } catch (error) {
      const message = getApiMessage(error, "The kitchen order could not be sent or printed.");
      setHospitalitySaveError(message);
      showNotice(message);
    } finally {
      setHospitalityBusy(false);
    }
  }, [cart, loadHospitalityWorkspace, persistHospitalityCart]);

  const buildCustomerDisplayPayload = useCallback(
    (overrides = {}) => {
      const status =
        overrides.status ||
        (saleComplete
          ? "completed"
          : rightMode === "payment"
          ? "payment"
          : cart.length === 0
          ? "idle"
          : "selling");

      const defaultMessage =
        status === "completed"
          ? "Thank you for shopping with us"
          : status === "payment"
          ? "Payment in progress"
          : status === "idle"
          ? "Welcome"
          : "Sale in progress";

      return {
        status,
        cart,
        totals,
        customer,
        saleComplete,
        lastSaleResponse,
        message: overrides.message || defaultMessage,
        receiptNumber:
          overrides.receiptNumber ||
          lastSaleResponse?.receipt_number ||
          lastSaleResponse?.sale?.invoice_number ||
          null,
        updated_at: new Date().toISOString(),
        ...overrides,
      };
    },
    [cart, totals, customer, saleComplete, rightMode, lastSaleResponse]
  );

  const pushCustomerDisplayUpdate = useCallback(
    (overrides = {}) => {
      try {
        sendCustomerDisplayUpdate(buildCustomerDisplayPayload(overrides));
      } catch (error) {
        // Customer display must never block POS selling.
        // eslint-disable-next-line no-console
        console.warn("Customer display update failed", error);
      }
    },
    [buildCustomerDisplayPayload]
  );

  const openCustomerScreen = useCallback(() => {
    try {
      openCustomerDisplayWindow();
      pushCustomerDisplayUpdate({
        message: cart.length === 0 ? "Welcome" : "Customer display connected",
      });
      showNotice("Customer screen opened.");
    } catch (error) {
      showNotice(getApiMessage(error, "Failed to open customer screen."));
    }
  }, [cart.length, pushCustomerDisplayUpdate]);

const convertMoney = useCallback(async (from, to, amount, options = {}) => {
  const cleanFrom = String(from || "").trim().toUpperCase();
  const cleanTo = String(to || "").trim().toUpperCase();
  const numericAmount = Number(amount || 0);

  if (!cleanFrom || !cleanTo) return numericAmount;
  if (Number.isNaN(numericAmount)) return 0;
  if (cleanFrom === cleanTo) return Number(numericAmount.toFixed(4));

  const useCache = options.useCache !== false;
  const cacheKey = `${cleanFrom}:${cleanTo}:${numericAmount.toFixed(4)}`;

  if (useCache && conversionCacheRef.current[cacheKey] !== undefined) {
    return conversionCacheRef.current[cacheKey];
  }

  const response = await convertCurrency({
    from: cleanFrom,
    to: cleanTo,
    amount: numericAmount,

    // backward-compatible names in case your API expects these
    from_currency: cleanFrom,
    to_currency: cleanTo,
    currency_from: cleanFrom,
    currency_to: cleanTo,
  });

  const converted = Number(getConvertedAmount(response) || 0);
  const cleanConverted = Number(converted.toFixed(4));

  if (useCache) {
    conversionCacheRef.current[cacheKey] = cleanConverted;
  }

  return cleanConverted;
}, []);

const refreshExchangeRateQuote = useCallback(async (targetCurrencyCode, currencyRows = currencies) => {
  const base = getBaseCurrency(currencyRows);
  const baseCode = getCurrencyCode(base);
  const targetCode = String(targetCurrencyCode || baseCode || "").trim().toUpperCase();

  if (!baseCode || !targetCode) return null;

  const rate = await convertMoney(baseCode, targetCode, 1, { useCache: false });
  const cleanRate = Number(Number(rate || 0).toFixed(8));
  setExchangeRateQuote(cleanRate);
  return cleanRate;
}, [currencies, convertMoney]);

const convertCartToCurrency = useCallback(
  async (targetCurrencyCode, oldCurrencyCode = saleCurrencyCode) => {
    const targetCurrency = String(targetCurrencyCode || "").trim().toUpperCase();
    const oldCurrency = String(oldCurrencyCode || saleCurrencyCode || "").trim().toUpperCase();

    if (!targetCurrency) return;

    try {
      setConvertingCurrency(true);

      const updatedLines = await Promise.all(
        cart.map(async (line) => {
          const lineCurrency = String(
            line.currency ||
              oldCurrency ||
              saleCurrencyCode ||
              line.baseCurrency ||
              targetCurrency
          ).toUpperCase();

          const baseCurrency = String(
            line.baseCurrency ||
              lineCurrency ||
              oldCurrency ||
              targetCurrency
          ).toUpperCase();

          /*
           * Always convert from original product base price/currency where possible.
           * This avoids repeatedly converting an already-converted selling price.
           */
          const convertedBaseMenuPrice = await convertMoney(
            baseCurrency,
            targetCurrency,
            line.basePrice ?? line.baseMenuPrice ?? line.menuBasePrice ?? line.price ?? 0,
            { useCache: false }
          );

          const convertedDiscount = await convertMoney(
            lineCurrency,
            targetCurrency,
            line.discount || 0,
            { useCache: false }
          );

          const convertedModifiers = await Promise.all(
            (Array.isArray(line.modifiers) ? line.modifiers : []).map(async (modifier) => {
              const modifierCurrency = String(
                modifier.currency_code ||
                  modifier.currency ||
                  lineCurrency ||
                  oldCurrency ||
                  targetCurrency
              ).toUpperCase();

              const convertedModifierPrice = await convertMoney(
                modifierCurrency,
                targetCurrency,
                modifier.price || 0,
                { useCache: false }
              );

              return {
                ...modifier,
                price: Number(convertedModifierPrice || 0),
                currency_code: targetCurrency,
                currency: targetCurrency,
              };
            })
          );

          const convertedModifierUnitTotal = convertedModifiers.reduce(
            (sum, modifier) =>
              sum + Number(modifier.price || 0) * Number(modifier.quantity || 1),
            0
          );

          let convertedDiscountPayload = line.discountPayload || null;

          if (convertedDiscountPayload) {
            const payloadCurrency = String(
              convertedDiscountPayload.currency ||
                lineCurrency ||
                oldCurrency ||
                targetCurrency
            ).toUpperCase();

            const [
              payloadDiscountAmount,
              payloadGrossAmount,
              payloadNetAmount,
            ] = await Promise.all([
              convertMoney(
                payloadCurrency,
                targetCurrency,
                convertedDiscountPayload.discount_amount || 0,
                { useCache: false }
              ),
              convertMoney(
                payloadCurrency,
                targetCurrency,
                convertedDiscountPayload.gross_amount || getLineGross(line),
                { useCache: false }
              ),
              convertMoney(
                payloadCurrency,
                targetCurrency,
                convertedDiscountPayload.net_amount || getLineTotal(line),
                { useCache: false }
              ),
            ]);

            let payloadDiscountValue = convertedDiscountPayload.discount_value;

            if (
              convertedDiscountPayload.discount_basis === "fixed_amount" ||
              convertedDiscountPayload.discount_basis === "fixed_price"
            ) {
              payloadDiscountValue = await convertMoney(
                payloadCurrency,
                targetCurrency,
                convertedDiscountPayload.discount_value || 0,
                { useCache: false }
              );
            }

            convertedDiscountPayload = {
              ...convertedDiscountPayload,
              currency: targetCurrency,
              currency_code: targetCurrency,
              discount_value: Number(payloadDiscountValue || 0),
              discount_amount: Number(payloadDiscountAmount || 0),
              gross_amount: Number(payloadGrossAmount || 0),
              net_amount: Number(payloadNetAmount || 0),
            };
          }

          return {
            ...line,

            // keep original source price/currency for future conversions
            basePrice: Number(line.basePrice ?? line.baseMenuPrice ?? line.menuBasePrice ?? line.price ?? 0),
            baseCurrency,

            baseMenuPrice: Number(convertedBaseMenuPrice || 0),
            menuBasePrice: Number(convertedBaseMenuPrice || 0),

            modifiers: convertedModifiers,
            modifierUnitTotal: Number(convertedModifierUnitTotal.toFixed(4)),

            price: Number((Number(convertedBaseMenuPrice || 0) + convertedModifierUnitTotal).toFixed(4)),
            currency: targetCurrency,

            discount: Number(convertedDiscount || 0),
            discountPayload: convertedDiscountPayload,
          };
        })
      );

      setCart(updatedLines);

      if (amountTenderedRequired && amountReceived) {
        const convertedReceived = await convertMoney(
          oldCurrency,
          targetCurrency,
          amountReceived,
          { useCache: false }
        );

        setAmountReceived(String(Number(convertedReceived).toFixed(2)));
      }

      setPaymentRows((prev) =>
        prev.map((row) => ({
          ...row,
          currency_code: targetCurrency,
          amount:
            row.amount && oldCurrency && targetCurrency && oldCurrency !== targetCurrency
              ? row.amount
              : row.amount,
        }))
      );

      setSaleCurrencyCode(targetCurrency);
    } catch (error) {
      showNotice(getApiMessage(error, "Currency conversion failed."));
    } finally {
      setConvertingCurrency(false);
    }
  },
  [
    cart,
    saleCurrencyCode,
    amountReceived,
    amountTenderedRequired,
    convertMoney,
    getLineGross,
    getLineTotal,
  ]
);

  const handleSaleCurrencyChange = async (currencyCode) => {
    const oldCurrency = saleCurrencyCode;
    await convertCartToCurrency(currencyCode, oldCurrency);
    await refreshExchangeRateQuote(currencyCode);

    setPaymentRows((prev) =>
      prev.map((row) => ({
        ...row,
        currency_code: currencyCode,
      }))
    );
  };

  const handleCreditCustomerChange = async (customerId) => {
    setSelectedCreditCustomerId(customerId || "");

    const nextCustomer = creditCustomers.find(
      (item) => String(item.id) === String(customerId)
    );

    const accountCurrency = getCreditCustomerCurrency(nextCustomer);

    if (accountCurrency && accountCurrency !== saleCurrencyCode) {
      await handleSaleCurrencyChange(accountCurrency);
    }

    if (nextCustomer) {
      setCustomer((prev) => ({
        ...prev,
        id: nextCustomer.id,
        name: getCreditCustomerName(nextCustomer),
        phone: nextCustomer.phone || nextCustomer.mobile || prev.phone || "",
        taxNumber: nextCustomer.tax_number || nextCustomer.taxNumber || prev.taxNumber || "",
      }));
    }
  };


  const loadSalesLayoutSetting = async () => {
    if (trustedSalesLayout) {
      setSalesLayout(trustedSalesLayout);
      setLoadingSalesLayout(false);
      return;
    }

    try {
      setLoadingSalesLayout(true);
      setSalesLayout(null);

      const setting = await getSalesScreenLayoutSetting({
        module: "sales",
        setting_key: "sales_screen_layout",
      });

      const value = normalizeSalesLayout(getSettingRawValue(setting));
      if (!value) {
        throw new Error("The configured sales screen layout is invalid.");
      }
      setSalesLayout(value);
    } catch (error) {
      setSalesLayout(null);
      showNotice(getApiMessage(error, "The configured sales screen could not be loaded. Retry before selling."));
    } finally {
      setLoadingSalesLayout(false);
    }
  };

  const loadBarcodeScanSetting = async () => {
    try {
      setLoadingBarcodeScanSetting(true);

      const setting = await getBarcodeScanSetting({
        module: "sales",
        setting_key: "barcode_scan_enabled",
      });

      setBarcodeScanEnabled(isSettingActive(setting));
    } catch (error) {
      setBarcodeScanEnabled(false);
      showNotice(
        getApiMessage(
          error,
          "Barcode scanning is disabled because the setting could not be verified."
        )
      );
    } finally {
      setLoadingBarcodeScanSetting(false);
    }
  };

  const loadCategories = async () => {
    if (hasAuthoritativeTillMenu) {
      setCategories(configuredMenuCategories);
      setLoadingCategories(false);
      return;
    }

    try {
      setLoadingCategories(true);

      const response = await getProductCategories({
        per_page: 100,
        is_active: true,
      });

      setCategories(normalizePaginatedData(response));
    } catch (error) {
      showNotice(getApiMessage(error, "Failed to load product categories."));
    } finally {
      setLoadingCategories(false);
    }
  };

  const loadProducts = async () => {
    if (hasAuthoritativeTillMenu) {
      setProducts(sellableTillMenuItems(configuredMenuItems));
      setLoadingProducts(false);
      return;
    }

    try {
      setLoadingProducts(true);

      const response = await getProducts({
        per_page: 300,
        is_active: true,

        // Kept here in case the backend supports server-side product type filtering.
        product_type: POS_PRODUCT_TYPES.join(","),
        product_types: POS_PRODUCT_TYPES,
      });

      const rawProducts = normalizePaginatedData(response);

      setProducts(
        rawProducts.filter((product) => {
          const price = getStandardPrice(product);
          return (
            product?.is_active !== false &&
            !!price &&
            productMatchesPosType(product)
          );
        })
      );
    } catch (error) {
      showNotice(getApiMessage(error, "Failed to load products."));
    } finally {
      setLoadingProducts(false);
    }
  };

  const loadPaymentTypes = async () => {
    try {
      setLoadingPayments(true);

      const response = await getPaymentTypes({
        per_page: 100,
        is_active: true,
      });

      const rows = normalizePaginatedData(response).filter(
        (item) => item?.is_active !== false
      );

      setPaymentTypes(rows);

      if (rows.length > 0) {
        const firstPaymentTypeId = rows[0].id;

        setSelectedPaymentTypeId((prev) => prev || firstPaymentTypeId);
        setPaymentRows((prev) => {
          if (prev.length > 0) return prev;

          const row = createPaymentRow({
            paymentTypeId: firstPaymentTypeId,
            currencyCode: saleCurrencyCode,
            amount: "",
          });

          setActivePaymentRowId(row.id);
          return [row];
        });
      }
    } catch (error) {
      showNotice(getApiMessage(error, "Failed to load payment types."));
    } finally {
      setLoadingPayments(false);
    }
  };

  const loadCurrencies = async () => {
    try {
      setLoadingCurrencies(true);

      const response = await getCurrencies({
        per_page: 100,
        is_active: true,
      });

      const rows = normalizePaginatedData(response).filter(
        (item) => item?.is_active !== false
      );

      setCurrencies(rows);
      conversionCacheRef.current = {};

      const base = getBaseCurrency(rows);
      const baseCode = getCurrencyCode(base);

      if (baseCode) {
        setSaleCurrencyCode((prev) => prev || baseCode);
        await refreshExchangeRateQuote(saleCurrencyCode || baseCode, rows);
      }
    } catch (error) {
      showNotice(getApiMessage(error, "Failed to load currencies."));
    } finally {
      setLoadingCurrencies(false);
    }
  };

  const loadCreditCustomers = async () => {
    try {
      setLoadingCreditCustomers(true);

      const response = await getCreditCustomersForPos({
        per_page: 300,
        is_active: true,
        status: "approved",
      });

      setCreditCustomers(normalizePaginatedData(response).filter(creditCustomerIsAllowed));
    } catch (error) {
      setCreditCustomers([]);
      showNotice(getApiMessage(error, "Failed to load credit customers."));
    } finally {
      setLoadingCreditCustomers(false);
    }
  };

  const loadAccountCustomers = async ({ quiet = false } = {}) => {
    if (!canCaptureCreditCustomerPayment) {
      if (!quiet) showNotice("Your role does not have permission to receive or credit customer-account money.");
      return [];
    }

    try {
      setLoadingAccountCustomers(true);
      const response = await getCreditCustomersForPos({
        purpose: "account_payment",
        limit: 300,
      });
      const rows = normalizePaginatedData(response);
      setAccountCustomers(rows);
      return rows;
    } catch (error) {
      setAccountCustomers([]);
      if (!quiet) showNotice(getApiMessage(error, "Failed to load customer accounts."));
      return [];
    } finally {
      setLoadingAccountCustomers(false);
    }
  };

  const handlePostChangeToggle = async (enabled) => {
    if (!enabled) {
      setPostChangeToCreditAccount(false);
      setChangeCreditCustomerId("");
      return;
    }

    if (!canCaptureCreditCustomerPayment) {
      showNotice("Your role does not have permission to post customer change to an account.");
      return;
    }

    // Lock the change disposition immediately. This prevents a fast click on
    // Complete from returning cash while the account list is still loading.
    setPostChangeToCreditAccount(true);
    const rows = accountCustomers.length ? accountCustomers : await loadAccountCustomers();
    if (!rows.length) {
      setPostChangeToCreditAccount(false);
      setChangeCreditCustomerId("");
      showNotice("No eligible customer accounts are available for credited change.");
      return;
    }
  };

  const deliverChangeCreditReceipt = async (delivery = "open") => {
    const customerId = changeCreditReceipt?.customerId;
    const paymentId = changeCreditReceipt?.paymentId;
    if (!customerId || !paymentId) {
      showNotice("The retained-change payment confirmation is not available.");
      return;
    }

    const busyKey = `${paymentId}:${delivery}`;
    try {
      setChangeCreditReceiptBusy(busyKey);
      await downloadCreditCustomerPaymentReceipt(
        customerId,
        paymentId,
        { paper: "80mm" },
        delivery
      );
    } catch (error) {
      showNotice(getApiMessage(error, "The retained-change payment confirmation could not be opened."));
    } finally {
      setChangeCreditReceiptBusy("");
    }
  };

  const loadPriceOverrideConfig = async () => {
    try {
      const response = await getPriceLevelOverrideConfig();
      const data = response?.data || response || {};

      setPriceOverrideConfig({
        enabled: boolValue(data.enabled),
        requires_manager_approval: boolValue(data.requires_manager_approval),
        cashier_can_override_without_manager: boolValue(data.cashier_can_override_without_manager),
      });
    } catch {
      setPriceOverrideConfig({
        enabled: false,
        requires_manager_approval: true,
        cashier_can_override_without_manager: false,
      });
    }
  };

  const loadSaleData = async () => {
    await Promise.all([
      loadCategories(),
      loadProducts(),
      loadPaymentTypes(),
      loadCurrencies(),
      loadCreditCustomers(),
      loadBarcodeScanSetting(),
      loadPriceOverrideConfig(),
    ]);
  };

  // A Back Office change (new payment method, base currency, ...) reaches
  // the till while a sale is open; the shop server announces it with
  // salexa_workspace_changed. Pick it up without closing the sale.
  const refreshSaleSettingsRef = useRef(null);
  refreshSaleSettingsRef.current = () => Promise.allSettled([loadPaymentTypes(), loadCurrencies()]);
  useEffect(() => {
    if (!open) return undefined;
    const handleWorkspaceChanged = () => { refreshSaleSettingsRef.current?.(); };
    window.addEventListener("salexa_workspace_changed", handleWorkspaceChanged);
    return () => window.removeEventListener("salexa_workspace_changed", handleWorkspaceChanged);
  }, [open]);

  useEffect(() => {
    if (!selectedPaymentType) return;

    const supportsMulti = paymentSupportsMultiCurrency(selectedPaymentType);
    const requiresTendered = paymentRequiresAmountTendered(selectedPaymentType);
    const defaultCurrency = getPaymentDefaultCurrency(selectedPaymentType);

    if (!supportsMulti && defaultCurrency) {
      handleSaleCurrencyChange(defaultCurrency);
    }

    if (!requiresTendered || isCustomerCreditPaymentType(selectedPaymentType)) {
      setAmountReceived("");
    }

    if (!isCustomerCreditPaymentType(selectedPaymentType)) {
      setSelectedCreditCustomerId("");
      setCreditPoNumber("");
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedPaymentTypeId]);

  const handleCategorySelect = (category) => {
    setSelectedCategoryId(category.id);
    setBrowseTab("products");
    focusBarcode();
  };

  const findProductByCode = useCallback(
    (value) => {
      const clean = normalizeText(value);

      if (!clean) return null;

      return products.find((product) => {
        const values = getProductBarcodeValues(product).map(normalizeText);
        return values.includes(clean);
      });
    },
    [products]
  );

  const addProductToCart = useCallback(
    async (product, qty = 1) => {
      if (!product) return null;

      if (
        isHospitality &&
        isFrontOfficeWorkspace &&
        hospitalityServiceType === "dine_in" &&
        hospitalityAssignmentMode === "manual" &&
        !hospitalitySelectedTableId &&
        !hospitalityCurrentOrder
      ) {
        showNotice("Assign a table before adding the first dine-in item.");
        return null;
      }

      if (!saleCurrencyCode) {
        showNotice("Please select sale currency first.");
        return null;
      }

      const standardPrice = getStandardPrice(product);

      if (!standardPrice) {
        showNotice(`${product.name} has no selling price.`);
        return null;
      }

      const allowDecimalQty = isDecimalProduct(product);
      const decimalPlaces = getProductDecimalPlaces(product);
      const unit = getProductUnit(product);
      const requestedQty = normalizeQty(qty, allowDecimalQty, decimalPlaces);
      const plainCandidateLine = {
        productId: product.id,
        product_id: product.id,
        prep_message_ids: [],
        prepMessageIds: [],
        prep_messages: [],
        prepMessages: [],
        prep_note: null,
        prepNote: null,
        modifier_ids: [],
        modifierIds: [],
        modifiers: [],
      };
      const existingLine = cart.find((line) =>
        sameSaleLineIdentity(line, plainCandidateLine)
      );
      const currentCartQtyForStock = cart
        .filter((line) => line.productId === product.id)
        .reduce((sum, line) => sum + Number(line.qty || 0), 0);
      const stockCheck = validateProductStockForCart(
        product,
        requestedQty,
        currentCartQtyForStock
      );

      if (!stockCheck.allowed) {
        showNotice(stockCheck.message);
        focusBarcode();
        return null;
      }

      const basePrice = Number(standardPrice.price || 0);
      const baseCurrency = standardPrice.currency || saleCurrencyCode;

      let convertedPrice = basePrice;

      try {
        convertedPrice = await convertMoney(
          baseCurrency,
          saleCurrencyCode,
          basePrice,
          { useCache: false }
        );
      } catch (error) {
        showNotice(getApiMessage(error, "Could not convert product price."));
        return null;
      }

      const targetLineId = existingLine?.lineId || createLineId(product.id);

      setSaleComplete(false);
      setSelectedLineId(targetLineId);

      setCart((prev) => {
        const existing = prev.find((line) =>
          sameSaleLineIdentity(line, plainCandidateLine)
        );

        if (existing) {
          return prev.map((line) =>
            line.lineId === existing.lineId
              ? {
                  ...line,
                  qty: normalizeQty(
                    Number(line.qty || 0) + Number(requestedQty || 0),
                    line.allowDecimalQty,
                    line.decimalPlaces
                  ),
                }
              : line
          );
        }

        const newLine = {
          lineId: targetLineId,
          productId: product.id,
          product_id: product.id,
          productPriceId: standardPrice.id || null,
          baseProductPriceId: standardPrice.id || null,
          priceLevelId: getPriceLevelId(standardPrice),
          basePriceLevelId: getPriceLevelId(standardPrice),
          priceLevelName: getPriceLevelName(standardPrice),
          priceLevelCode: getPriceLevelCode(standardPrice),
          priceOverride: null,

          sku: product.sku || "-",
          code: product.code || "-",
          barcode: product.barcode || "-",
          name: product.name,
          categoryId: product.category_id || product.category?.id || null,
          category_id: product.category_id || product.category?.id || null,
          categoryName: product.category?.name || "Uncategorised",

          basePrice,
          baseCurrency,
          baseMenuPrice: Number(convertedPrice || 0),
          menuBasePrice: Number(convertedPrice || 0),
          modifierUnitTotal: 0,
          prep_message_ids: [],
          prepMessageIds: [],
          prep_messages: [],
          prepMessages: [],
          prep_note: null,
          prepNote: null,
          modifier_ids: [],
          modifierIds: [],
          modifiers: [],

          price: Number(convertedPrice || 0),
          currency: saleCurrencyCode,
          qty: requestedQty,
          unit,
          allowDecimalQty,
          decimalPlaces,

          taxable: getProductTaxesForSale(product).length > 0 || !!product.taxable,
          taxes: getProductTaxesForSale(product),
          productTaxes: getProductTaxesForSale(product),
          taxSummary: getProductTaxSummary(product),
          taxRate: Number(product.tax?.rate || product.tax_rate || 0),
          discount: 0,

          availableStock: getProductAvailableStock(product),
          rawProduct: product,
        };

        return [...prev, newLine];
      });

      return targetLineId;
    },
    [
      cart,
      saleCurrencyCode,
      convertMoney,
      focusBarcode,
      hospitalityAssignmentMode,
      hospitalityCurrentOrder,
      hospitalitySelectedTableId,
      hospitalityServiceType,
      isFrontOfficeWorkspace,
      isHospitality,
    ]
  );

  useEffect(() => {
    if (!pendingEditLineId) return;

    const line = cart.find((item) => item.lineId === pendingEditLineId);

    if (!line) return;

    setSelectedLineId(line.lineId);
    setEditQty("");
    setActionPanel("editLine");
    setPendingEditLineId(null);
  }, [cart, pendingEditLineId]);

  const addScannedProduct = useCallback(
    async (value) => {
      const scanned = String(value || "").trim();

      if (!scanned) return false;

      const product = findProductByCode(scanned);

      if (!product) return false;

      const decimalProduct = isDecimalProduct(product);
      const decimalPlaces = getProductDecimalPlaces(product);
      const startingQty = decimalProduct ? getDecimalStep(decimalPlaces) : 1;

      const lineId = await addProductToCart(product, startingQty);

      if (!lineId) return false;

      setBarcodeInput("");

      if (decimalProduct) {
        setPendingEditLineId(lineId);
        showNotice(`${product.name} added. Enter quantity.`);
      } else {
        showNotice(`${product.name} added.`);
      }

      focusBarcode();

      return true;
    },
    [addProductToCart, findProductByCode]
  );

  // keyedQty comes from the till keypad: type a quantity, then tap the item.
  const handleProductClick = async (product, keyedQty = null) => {
    const decimalProduct = isDecimalProduct(product);
    const decimalPlaces = getProductDecimalPlaces(product);
    const hasKeyedQty = Number(keyedQty) > 0;
    const startingQty = hasKeyedQty
      ? normalizeQty(keyedQty, decimalProduct, decimalPlaces)
      : decimalProduct ? getDecimalStep(decimalPlaces) : 1;

    const lineId = await addProductToCart(product, startingQty);

    if (!lineId) return;

    if (decimalProduct && !hasKeyedQty) {
      setPendingEditLineId(lineId);
      showNotice(`${product.name} added. Enter quantity.`);
    }

    focusBarcode();
  };

  const handleBarcodeSubmit = async (event) => {
    event.preventDefault();

    if (!barcodeScanEnabled) {
      showNotice("Barcode scanning is disabled in General Settings.");
      return;
    }

    const scanned = barcodeInput.trim();

    if (!scanned) return;

    const added = await addScannedProduct(scanned);

    if (!added) {
      showNotice(`Barcode not found: ${scanned}`);
      setBarcodeInput("");
      focusBarcode();
    }
  };

  const handleBarcodeChange = (event) => {
    if (!barcodeScanEnabled) {
      setBarcodeInput("");
      showNotice("Barcode scanning is disabled in General Settings.");
      return;
    }

    const value = event.target.value;

    setBarcodeInput(value);

    const matched = findProductByCode(value);

    if (matched) {
      window.setTimeout(() => {
        addScannedProduct(value);
      }, 30);
    }
  };

  const updateLine = (lineId, updates) => {
    setCart((prev) =>
      prev.map((line) =>
        line.lineId === lineId
          ? {
              ...line,
              ...updates,
            }
          : line
      )
    );
  };

  const removeSelectedLine = () => {
    if (!selectedLine) {
      showNotice("Select a sale line first.");
      focusBarcode();
      return;
    }

    setCart((prev) => prev.filter((line) => line.lineId !== selectedLine.lineId));
    setSelectedLineId(null);
    showNotice("Line removed.");
    focusBarcode();
  };

  const resetCustomer = () => {
    setCustomer({
      name: "Walk-in Customer",
      phone: "",
      taxNumber: "",
      notes: "",
    });
  };

  const clearSale = async (options = {}) => {
    const skipHospitality = options?.skipHospitality === true;
    if (
      !skipHospitality &&
      isHospitality &&
      isFrontOfficeWorkspace &&
      hospitalityOrderId(hospitalityCurrentOrder) &&
      !saleComplete
    ) {
      try {
        setHospitalityBusy(true);
        const saved = cart.length > 0
          ? await persistHospitalityCart(cart, { force: true })
          : hospitalityCurrentOrder;
        if (!saved) return false;
        await hangHospitalityTableOrder(
          hospitalityOrderId(saved),
          hospitalityOrderRevision(saved),
        );
        showNotice("Order parked as a hanging table for later recall.");
        await loadHospitalityWorkspace({ quiet: true });
      } catch (error) {
        const message = getApiMessage(error, "The order could not be parked. It remains on screen.");
        setHospitalitySaveError(message);
        showNotice(message);
        return false;
      } finally {
        setHospitalityBusy(false);
      }
    }

    hospitalityPersistenceSuspendedRef.current = true;
    setSaleError(null);
    setClientSaleId(createSaleAttemptId());
    setCart([]);
    setSelectedLineId(null);
    setPendingEditLineId(null);
    setAmountReceived("");
    const resetPaymentTypeId = paymentTypes?.[0]?.id || "";
    setSelectedPaymentTypeId(resetPaymentTypeId);
    const resetPaymentRow = createPaymentRow({
      paymentTypeId: resetPaymentTypeId,
      currencyCode: saleCurrencyCode || totals.currency || "",
      amount: "",
    });
    setPaymentRows(resetPaymentTypeId ? [resetPaymentRow] : []);
    setActivePaymentRowId(resetPaymentTypeId ? resetPaymentRow.id : "");
    setSelectedCreditCustomerId("");
    setCreditPoNumber("");
    setPostChangeToCreditAccount(false);
    setChangeCreditCustomerId("");
    setChangeCreditReceipt(null);
    setChangeCreditReceiptBusy("");
    setSaleComplete(false);
    setLastSaleResponse(null);
    setRightMode("browse");
    setSelectedCategoryId("all");
    setBrowseTab("categories");
    setSearch("");
    setBarcodeInput("");
    setActionPanel(null);
    setDiscountModalOpen(false);
    setPrepMessageModalOpen(false);
    setModifierModalOpen(false);
    setSplitLineModalOpen(false);
    setEditQty("");
    setDiscountValue("");
    setQuoteCode("");
    setLoadedQuotation(null);
    setLoadQuoteModalOpen(false);
    setCashupPayments([]);
    setEndShiftPayments([]);
    setCashupNotes("");
    setEndShiftNotes("");
    setHospitalityCurrentOrder(null);
    setHospitalitySelectedTableId("");
    setHospitalitySaveError("");
    hospitalityPersistedSignatureRef.current = "";
    hospitalityClientRequestIdRef.current = createHospitalityRequestId();
    resetCustomer();

    const base = getBaseCurrency(currencies);
    const baseCode = getCurrencyCode(base);

    if (baseCode) {
      setSaleCurrencyCode(baseCode);
    }

    sendCustomerDisplayUpdate({
      status: "idle",
      cart: [],
      totals: {
        subTotal: 0,
        discount: 0,
        taxableAmount: 0,
        tax: 0,
        total: 0,
        received: 0,
        balance: 0,
        change: 0,
        currency: baseCode || totals.currency || "USD",
      },
      customer: {
        name: "Walk-in Customer",
        phone: "",
        taxNumber: "",
        notes: "",
      },
      saleComplete: false,
      lastSaleResponse: null,
      receiptNumber: null,
      message: "Welcome",
      updated_at: new Date().toISOString(),
    });

    loadProducts();
    focusBarcode();
    window.setTimeout(() => { hospitalityPersistenceSuspendedRef.current = false; }, 0);
    return true;
  };
  clearSaleRef.current = clearSale;


  const loadQuotationIntoSale = async (inputCode = quoteCode) => {
    const code = String(inputCode || "").trim();

    if (!code) {
      showNotice("Enter quotation code.");
      return;
    }

    try {
      setLoadingQuotation(true);

      const response = await lookupQuotationByCode(code);
      const quote = response?.data || response;

      if (!quote?.items?.length) {
        showNotice("Quotation has no items.");
        return;
      }

      const quoteCurrency = quote.currency || saleCurrencyCode || "USD";

      const quoteLines = quote.items.map((item) => {
        const quantity = Number(item.quantity || 1);
        const unitPrice = Number(item.unit_price || 0);
        const discountAmount = Number(
          item.discount_amount || item.discount_payload?.discount_amount || 0
        );

        return {
          lineId: `quote-${item.id || createLineId(item.product_id)}`,
          productId: item.product_id,
          product_id: item.product_id,
          productPriceId: item.product_price_id || null,
          product_price_id: item.product_price_id || null,
          baseProductPriceId: item.product_price_id || null,
          priceLevelId: item.price_level_id || item.price_override_level_id || null,
          price_level_id: item.price_level_id || item.price_override_level_id || null,
          basePriceLevelId: item.price_level_id || null,
          priceLevelName:
            item.price_override_payload?.price_level_name ||
            item.price_override_payload?.price_level?.name ||
            "Quotation Price",
          priceLevelCode:
            item.price_override_payload?.price_level_code ||
            item.price_override_payload?.price_level?.code ||
            "",

          sku: item.sku || "-",
          code: item.product_code || "-",
          barcode: item.barcode || "-",
          name: item.product_name || "Quoted Product",
          categoryId: item.category_id || item.discount_payload?.category_id || null,
          category_id: item.category_id || item.discount_payload?.category_id || null,
          categoryName: item.category_name || "Quotation",

          basePrice: Number(item.base_unit_price || unitPrice || 0),
          baseCurrency: item.currency || quoteCurrency,
          baseMenuPrice: Number(unitPrice || 0),
          menuBasePrice: Number(unitPrice || 0),
          modifierUnitTotal: 0,
          prep_message_ids: [],
          prepMessageIds: [],
          prep_messages: [],
          prepMessages: [],
          prep_note: null,
          prepNote: null,
          modifier_ids: [],
          modifierIds: [],
          modifiers: [],
          price: unitPrice,
          currency: quoteCurrency,
          qty: quantity,
          unit: item.unit || "EACH",
          allowDecimalQty: true,
          decimalPlaces: 4,

          taxable: Boolean(item.taxable) || getProductTaxesForSale(item.product || {}).length > 0,
          taxes: getProductTaxesForSale(item.product || {}),
          productTaxes: getProductTaxesForSale(item.product || {}),
          taxSummary: getProductTaxSummary(item.product || {}),
          taxRate: Number(item.tax_rate || 0),
          discount: discountAmount,
          discountPayload: item.discount_payload
            ? {
                ...item.discount_payload,
                discount_amount: discountAmount,
                gross_amount: Number(item.gross_amount || quantity * unitPrice),
                net_amount: Number(
                  item.net_amount || Math.max(quantity * unitPrice - discountAmount, 0)
                ),
                product_id: item.product_id,
                category_id: item.category_id || item.discount_payload?.category_id || null,
                from_quotation: true,
                quotation_id: quote.id,
                quotation_code: quote.quote_code,
                quotation_item_id: item.id,
              }
            : null,

          priceOverride: item.price_override_payload
            ? {
                ...item.price_override_payload,
                manager_approval_id:
                  item.price_override_approval_id ||
                  item.price_override_payload?.manager_approval_id ||
                  null,
                product_price_id:
                  item.product_price_id ||
                  item.price_override_payload?.product_price_id ||
                  null,
                price_level_id:
                  item.price_override_level_id ||
                  item.price_override_payload?.price_level_id ||
                  item.price_level_id ||
                  null,
                price: Number(item.base_unit_price || unitPrice || 0),
                currency: item.currency || quoteCurrency,
                from_quotation: true,
                quotation_id: quote.id,
                quotation_code: quote.quote_code,
                quotation_item_id: item.id,
              }
            : null,

          availableStock: Number(item.available_stock || 0),
          fromQuotation: true,
          quotationId: quote.id,
          quotationCode: quote.quote_code,
          quotationItemId: item.id,
          rawProduct: item.product || null,
        };
      });

      setCart(quoteLines);
      setSelectedLineId(quoteLines[0]?.lineId || null);
      setSaleCurrencyCode(quoteCurrency);
      setLoadedQuotation(quote);
      setQuoteCode(quote.quote_code || code);
      setLoadQuoteModalOpen(false);
      setSaleComplete(false);
      setRightMode("browse");
      setActionPanel(null);

      setCustomer((prev) => ({
        ...prev,
        id: quote.customer_id || prev.id || null,
        name: quote.customer_name || prev.name || "Walk-in Customer",
        phone: quote.customer_phone || prev.phone || "",
        taxNumber: prev.taxNumber || "",
        notes: quote.notes || prev.notes || "",
      }));

      showNotice(`Quotation loaded: ${quote.quote_code}. Prices and discounts locked from quote.`);
      focusBarcode();
    } catch (error) {
      showNotice(getApiMessage(error, "Failed to load quotation."));
    } finally {
      setLoadingQuotation(false);
    }
  };


  const handleProcess = () => {
    if (cart.length === 0) {
      showNotice("Add items before processing the sale.");
      focusBarcode();
      return;
    }

    if (!selectedPaymentTypeId) {
      showNotice(
        paymentTypes.length === 0
          ? "No payment methods are set up for this shop. Add one in Back Office → Store Configuration → Payment Method with this shop selected."
          : "Select payment method first."
      );
      return;
    }

    setRightMode("payment");
    setActionPanel(null);
  };

  const openAction = (action) => {
    if (["prepMessages", "modifiers", "splitLine"].includes(action)) {
      if (!selectedLine) {
        showNotice("Select a sale line first.");
        focusBarcode();
        return;
      }

      setActionPanel(null);
      setPrepMessageModalOpen(action === "prepMessages");
      setModifierModalOpen(action === "modifiers");
      setSplitLineModalOpen(action === "splitLine");
      return;
    }

    if (action === "editLine" || action === "discount") {
      if (!selectedLine) {
        showNotice("Select a sale line first.");
        focusBarcode();
        return;
      }
    }

    if (action === "editLine") {
      setEditQty(String(selectedLine.qty || ""));
    }

    if (action === "discount") {
      setDiscountValue(String(selectedLine.discount || ""));
    }

    setActionPanel(action);
  };

  const applyEditLine = () => {
    if (!selectedLine) return;

    const qty = normalizeQty(
      editQty,
      selectedLine.allowDecimalQty,
      selectedLine.decimalPlaces
    );

    const stockCheck = validateLineStockForQty(selectedLine, qty);

    if (!stockCheck.allowed) {
      showNotice(stockCheck.message);
      focusBarcode();
      return;
    }

    updateLine(selectedLine.lineId, {
      qty,
    });

    setActionPanel(null);
    showNotice("Line quantity updated.");
    focusBarcode();
  };

  const setSelectedLineQuantity = (value) => {
    if (!selectedLine) {
      showNotice("Select a sale line first.");
      return false;
    }

    const qty = normalizeQty(value, selectedLine.allowDecimalQty, selectedLine.decimalPlaces);
    if (!(Number(qty) > 0)) {
      showNotice("Enter a quantity above zero.");
      return false;
    }

    const stockCheck = validateLineStockForQty(selectedLine, qty);
    if (!stockCheck.allowed) {
      showNotice(stockCheck.message);
      return false;
    }

    updateLine(selectedLine.lineId, { qty });
    showNotice(`${selectedLine.name} quantity set to ${qty}.`);
    return true;
  };

  // The +/- buttons on an order line add or take away one of that item
  // without going back to the menu tile.
  const stepLineQuantity = (lineId, direction) => {
    const line = cart.find((row) => row.lineId === lineId);
    if (!line) return;

    setSelectedLineId(lineId);
    const step = line.allowDecimalQty ? getDecimalStep(line.decimalPlaces || 2) : 1;
    const nextQty = Number(line.qty || 0) + (direction < 0 ? -step : step);
    if (nextQty <= 0) {
      showNotice(`Use the bin button to remove ${line.name}.`);
      return;
    }

    const qty = normalizeQty(nextQty, line.allowDecimalQty, line.decimalPlaces);
    if (direction > 0) {
      const stockCheck = validateLineStockForQty(line, qty);
      if (!stockCheck.allowed) {
        showNotice(stockCheck.message);
        return;
      }
    }

    updateLine(lineId, { qty });
  };

  const fastCash = () => {
    const cashType = paymentTypes.find((type) =>
      normalizeText(type?.name).includes("cash")
    );
    if (cashType) {
      setSelectedPaymentTypeId(cashType.id);
    }
    handleProcess();
  };

  const incrementSelectedLine = () => {
    if (!selectedLine) {
      showNotice("Select an item first.");
      return;
    }
    const step = selectedLine.allowDecimalQty ? getDecimalStep(selectedLine.decimalPlaces || 2) : 1;
    const qty = normalizeQty(Number(selectedLine.qty || 0) + Number(step), selectedLine.allowDecimalQty, selectedLine.decimalPlaces);
    const stockCheck = validateLineStockForQty(selectedLine, qty);
    if (!stockCheck.allowed) {
      showNotice(stockCheck.message);
      return;
    }
    updateLine(selectedLine.lineId, { qty });
    showNotice(`${selectedLine.name} quantity increased to ${qty}.`);
  };

  const applyPrepMessagesToSelectedLine = (payload) => {
    if (!selectedLine) return;

    updateLine(selectedLine.lineId, {
      prep_message_ids: payload.prep_message_ids || [],
      prepMessageIds: payload.prepMessageIds || payload.prep_message_ids || [],
      prep_messages: payload.prep_messages || [],
      prepMessages: payload.prepMessages || payload.prep_messages || [],
      prep_note: payload.prep_note || null,
      prepNote: payload.prepNote || payload.prep_note || null,
    });

    setPrepMessageModalOpen(false);
    showNotice("Prep messages applied to selected line.");
    focusBarcode();
  };

  const applyModifiersToSelectedLine = (payload) => {
    if (!selectedLine) return;

    updateLine(selectedLine.lineId, {
      baseMenuPrice: payload.baseMenuPrice ?? selectedLine.baseMenuPrice ?? selectedLine.basePrice ?? selectedLine.price,
      menuBasePrice: payload.menuBasePrice ?? selectedLine.menuBasePrice ?? selectedLine.basePrice ?? selectedLine.price,
      modifiers: payload.modifiers || [],
      modifier_ids: payload.modifier_ids || [],
      modifierIds: payload.modifierIds || payload.modifier_ids || [],
      modifierUnitTotal: Number(payload.modifierUnitTotal || 0),
      price: Number(payload.price || selectedLine.price || 0),
    });

    setModifierModalOpen(false);
    showNotice("Modifiers applied and line price updated.");
    focusBarcode();
  };

  const applySplitToSelectedLine = ({ splitQty = 1, copyCustomisation = false } = {}) => {
    if (!selectedLine) return;

    const currentQty = Number(selectedLine.qty || 0);
    const cleanSplitQty = normalizeQty(
      splitQty,
      selectedLine.allowDecimalQty,
      selectedLine.decimalPlaces
    );

    if (currentQty <= 1) {
      showNotice("Line quantity must be greater than 1 before splitting.");
      return;
    }

    if (cleanSplitQty <= 0 || cleanSplitQty >= currentQty) {
      showNotice("Split quantity must be less than the selected line quantity.");
      return;
    }

    const clearCustom = {
      prep_message_ids: [],
      prepMessageIds: [],
      prep_messages: [],
      prepMessages: [],
      prep_note: null,
      prepNote: null,
      modifier_ids: [],
      modifierIds: [],
      modifiers: [],
      modifierUnitTotal: 0,
      price: getLineBaseMenuPrice(selectedLine),
    };

    let nextSelectedLineId = null;

    setCart((prev) => {
      const index = prev.findIndex((line) => line.lineId === selectedLine.lineId);
      if (index < 0) return prev;

      const source = prev[index];
      const sourceQty = Number(source.qty || 0);

      if (cleanSplitQty <= 0 || cleanSplitQty >= sourceQty) {
        return prev;
      }

      const remainingQty = normalizeQty(
        sourceQty - cleanSplitQty,
        source.allowDecimalQty,
        source.decimalPlaces
      );

      const newLineId = createSplitLineId(source.lineId);
      nextSelectedLineId = newLineId;

      const splitLine = {
        ...source,
        lineId: newLineId,
        qty: normalizeQty(
          cleanSplitQty,
          source.allowDecimalQty,
          source.decimalPlaces
        ),
        split_from_line_id: source.lineId,
        splitFromLineId: source.lineId,
        splitParentProductId: source.productId || source.product_id,

        prep_message_ids: copyCustomisation ? cloneJson(source.prep_message_ids || [], []) : [],
        prepMessageIds: copyCustomisation ? cloneJson(source.prepMessageIds || source.prep_message_ids || [], []) : [],
        prep_messages: copyCustomisation ? cloneJson(source.prep_messages || [], []) : [],
        prepMessages: copyCustomisation ? cloneJson(source.prepMessages || source.prep_messages || [], []) : [],
        prep_note: copyCustomisation ? source.prep_note ?? source.prepNote ?? null : null,
        prepNote: copyCustomisation ? source.prepNote ?? source.prep_note ?? null : null,
        modifier_ids: copyCustomisation ? cloneJson(source.modifier_ids || [], []) : [],
        modifierIds: copyCustomisation ? cloneJson(source.modifierIds || source.modifier_ids || [], []) : [],
        modifiers: copyCustomisation ? cloneJson(source.modifiers || [], []) : [],
        modifierUnitTotal: copyCustomisation ? Number(source.modifierUnitTotal || getModifierUnitTotal(source) || 0) : 0,
        price: copyCustomisation ? Number(source.price || 0) : getLineBaseMenuPrice(source),
        customisationKey: `${buildLineCustomisationKey(copyCustomisation ? source : clearCustom)}:${newLineId}`,
      };

      const remainingLine = {
        ...source,
        qty: remainingQty,
      };

      const next = [...prev];
      next.splice(index, 1, remainingLine, splitLine);
      return next;
    });

    if (nextSelectedLineId) {
      setSelectedLineId(nextSelectedLineId);
    }

    setSplitLineModalOpen(false);
    showNotice("Line split. Select either line and apply prep messages or modifiers.");
    focusBarcode();
  };

  const applyDiscount = () => {
    if (!selectedLine) return;

    const maxDiscount = selectedLine.qty * selectedLine.price;
    const discount = Math.min(Math.max(Number(discountValue || 0), 0), maxDiscount);

    updateLine(selectedLine.lineId, {
      discount,
      discountPayload: null,
    });

    setActionPanel(null);
    showNotice("Manual discount applied.");
    focusBarcode();
  };

  const applyLineDiscountPayload = (payload) => {
    if (!selectedLine || !payload) {
      showNotice("Select a product line first.");
      return;
    }

    const selectedProductId =
      selectedLine.product_id ||
      selectedLine.productId ||
      selectedLine.rawProduct?.id ||
      null;

    const selectedCategoryId =
      selectedLine.category_id ||
      selectedLine.categoryId ||
      selectedLine.rawProduct?.category_id ||
      selectedLine.rawProduct?.category?.id ||
      null;

    /*
     * Discount configuration guard:
     * If the discount payload says it was calculated for a product, do not allow it
     * to be applied to another selected line.
     */
    if (
      payload.product_id &&
      selectedProductId &&
      String(payload.product_id) !== String(selectedProductId)
    ) {
      showNotice("This discount is not configured for the selected product line.");
      return;
    }

    const lineGross = getLineGross(selectedLine);
    const discount = Math.min(
      Math.max(Number(payload.discount_amount || 0), 0),
      lineGross
    );

    if (discount <= 0) {
      showNotice("This discount calculated as 0.00 for the selected product.");
      return;
    }

    const originalScope = payload.original_scope || payload.scope || "line";
    const saleScope = payload.scope === "cart" ? "cart" : "line";

    updateLine(selectedLine.lineId, {
      discount,
      discountPayload: {
        ...payload,
        scope: saleScope,
        original_scope: originalScope,
        product_id: selectedProductId,
        category_id: selectedCategoryId,
        discount_amount: discount,
        gross_amount: lineGross,
        net_amount: Math.max(lineGross - discount, 0),
      },
    });

    setDiscountModalOpen(false);
    setActionPanel(null);
    showNotice(`Discount applied: ${payload.discount_name || payload.discount_code || "Discount"}.`);
    focusBarcode();
  };

  const voidSale = () => {
    if (cart.length === 0) {
      showNotice("No sale to void.");
      focusBarcode();
      return;
    }

    clearSale();
    showNotice("Sale voided.");
  };

  const noSale = useCallback(async () => {
    try {
      const printerName = lastSaleResponse?.print?.printer?.printer_name;

      await qzOpenCashDrawer({
        printerName,
        jobName: "No Sale Drawer Open",
      });

      showNotice("Cash drawer opened.");
    } catch (error) {
      showNotice(getApiMessage(error, "Could not open cash drawer."));
    } finally {
      focusBarcode();
    }
  }, [lastSaleResponse, barcodeScanEnabled]);

  const openPriceOverridePanel = async () => {
    if (!selectedLine) {
      showNotice("Select a sale line first.");
      focusBarcode();
      return;
    }

    if (!priceOverrideConfig.enabled) {
      showNotice("Price level override is disabled in General Settings.");
      return;
    }

    setActionPanel("priceOverride");
    setPriceOverrideRows([]);
    setPriceOverrideContext(null);

    if (priceOverrideConfig.requires_manager_approval && !priceOverrideConfig.cashier_can_override_without_manager) {
      setPendingPriceOverride({
        line: selectedLine,
        title: "Manager Approval Required",
        description: `Approve price level override for ${selectedLine.name}.`,
        action: "price_level_override",
        permission: "approve_price_override",
        reason: `Price level override for ${selectedLine.name}`,
        referenceType: "price_level_override",
        referenceId: selectedLine.productId,
        metadata: {
          product_id: selectedLine.productId,
          product_name: selectedLine.name,
          current_price: selectedLine.price,
          current_price_level_id: selectedLine.priceLevelId || selectedLine.basePriceLevelId,
        },
      });
      setManagerApprovalOpen(true);
      return;
    }

    await loadOverrideLevelsForLine(selectedLine, null);
  };

  const loadOverrideLevelsForLine = async (line, approval = null) => {
    if (!line) return;

    try {
      setPriceOverrideLoading(true);

      const response = await getProductOverridePriceLevels(line.productId, {
        manager_approval_id: approval?.approval_id || null,
      });

      const data = response?.data || response || {};
      const rows = Array.isArray(data.rows) ? data.rows : [];

      setPriceOverrideRows(rows);
      setPriceOverrideContext({ ...(data.context || {}), manager_approval: approval });

      if (rows.length === 0) {
        showNotice("No eligible price levels found for this role.");
      }
    } catch (error) {
      showNotice(getApiMessage(error, "Failed to load eligible price levels."));
    } finally {
      setPriceOverrideLoading(false);
    }
  };

  const handlePriceOverrideApproved = async (approval) => {
    if (!pendingPriceOverride?.line) return;
    setActionPanel("priceOverride");
    await loadOverrideLevelsForLine(pendingPriceOverride.line, approval);
    setPendingPriceOverride((prev) => ({ ...prev, manager_approval: approval }));
  };

  const applySelectedPriceLevel = async (priceRow) => {
    if (!selectedLine || !priceRow) return;

    try {
      setPriceOverrideLoading(true);

      const approvalId =
        priceOverrideContext?.manager_approval?.approval_id ||
        pendingPriceOverride?.manager_approval?.approval_id ||
        null;

      const response = await applyPriceLevelOverride({
        product_id: selectedLine.productId,
        product_price_id: priceRow.id,
        line_id: selectedLine.lineId,
        old_price: selectedLine.price,
        old_price_level_id: selectedLine.priceLevelId || selectedLine.basePriceLevelId || null,
        manager_approval_id: approvalId,
        reason: `Applied ${priceRow.price_level_name} price level during sale`,
        metadata: {
          product_name: selectedLine.name,
          current_price: selectedLine.price,
          current_currency: selectedLine.currency,
          target_price: priceRow.price,
          target_currency: priceRow.currency,
          target_price_level_id: priceRow.price_level_id,
          target_price_level_name: priceRow.price_level_name,
        },
      });

      const payload = response?.data || response || {};
      const approvedPrice = payload.product_price || priceRow;

      let convertedPrice = Number(approvedPrice.price || priceRow.price || 0);

      try {
        convertedPrice = await convertMoney(
          approvedPrice.currency || priceRow.currency || saleCurrencyCode,
          saleCurrencyCode,
          approvedPrice.price || priceRow.price || 0
        );
      } catch {
        convertedPrice = Number(approvedPrice.price || priceRow.price || 0);
      }

      updateLine(selectedLine.lineId, {
        productPriceId: approvedPrice.id || priceRow.id,
        priceLevelId: approvedPrice.price_level_id || priceRow.price_level_id,
        priceLevelName: approvedPrice.price_level_name || priceRow.price_level_name,
        priceLevelCode: approvedPrice.price_level_code || priceRow.price_level_code,
        basePrice: Number(approvedPrice.price || priceRow.price || 0),
        baseCurrency: approvedPrice.currency || priceRow.currency || saleCurrencyCode,
        price: convertedPrice,
        currency: saleCurrencyCode,
        priceOverride: {
          audit_id: payload.audit_id,
          manager_approval_id: approvalId,
          product_price_id: approvedPrice.id || priceRow.id,
          price_level_id: approvedPrice.price_level_id || priceRow.price_level_id,
          price_level_name: approvedPrice.price_level_name || priceRow.price_level_name,
          old_price: selectedLine.price,
          new_price: convertedPrice,
          approved_by: payload.approved_by || null,
        },
      });

      showNotice(`Price level changed to ${approvedPrice.price_level_name || priceRow.price_level_name}.`);
      setActionPanel(null);
      setPendingPriceOverride(null);
      setPriceOverrideRows([]);
      focusBarcode();
    } catch (error) {
      showNotice(getApiMessage(error, "Price override failed."));
    } finally {
      setPriceOverrideLoading(false);
    }
  };

  const openMoreFunctions = () => {
    setActionPanel("moreFunctions");
  };

  const buildCountedPaymentRows = (summary) => {
    const payments = Array.isArray(summary?.payments) ? summary.payments : [];
    const canViewAmounts = canViewCashupFinancialAmounts(summary);

    return payments.map((row) => ({
      payment_name: row.payment_name || "Unknown",
      money_code: row.money_code || null,
      currency_code: row.currency_code || totals.currency || "USD",
      expected_amount: canViewAmounts ? Number(row.amount || 0) : null,
      counted_amount: canViewAmounts ? Number(row.amount || 0) : 0,
      payment_count: Number(row.payment_count || 0),
      base_currency_code: row.base_currency_code || null,
      base_amount: Number(row.base_amount || 0),
    }));
  };

  const loadShiftSummary = async () => {
    try {
      setLoadingShiftSummary(true);

      const response = await getActiveShiftSummary();
      const summary = response?.data || response;
      const countedRows = buildCountedPaymentRows(summary);

      setShiftSummary(summary);
      setCashupPayments(countedRows);
      setEndShiftPayments(countedRows);

      return summary;
    } catch (error) {
      showNotice(getApiMessage(error, "Failed to load shift summary."));
      return null;
    } finally {
      setLoadingShiftSummary(false);
    }
  };

  const openShiftSummary = async () => {
    setActionPanel("shiftSummary");
    await loadShiftSummary();
  };

  const openCashup = () => {
    setEnterpriseCashupOpen(true);
  };

  const openEndShift = () => {
    setEnterpriseEndShiftOpen(true);
  };

  const updateCountedPayment = (mode, index, value) => {
    const setter = mode === "endShift" ? setEndShiftPayments : setCashupPayments;

    setter((prev) =>
      prev.map((row, rowIndex) =>
        rowIndex === index
          ? {
              ...row,
              counted_amount: value,
            }
          : row
      )
    );
  };

  const validateCountedPayments = (rows) => {
    if (!Array.isArray(rows) || rows.length === 0) {
      showNotice("No payment rows found for this shift.");
      return false;
    }

    const invalid = rows.some((row) => {
      const amount = Number(row.counted_amount || 0);
      return Number.isNaN(amount) || amount < 0;
    });

    if (invalid) {
      showNotice("Enter valid counted amounts.");
      return false;
    }

    return true;
  };

  const buildCashupPayload = ({ closeShift }) => {
    const rows = closeShift ? endShiftPayments : cashupPayments;

    return {
      counted_payments: rows.map((row) => ({
        payment_name: row.payment_name,
        money_code: row.money_code || null,
        currency_code: row.currency_code,
        amount: Number(Number(row.counted_amount || 0).toFixed(4)),
      })),
      notes: closeShift ? endShiftNotes || null : cashupNotes || null,
      close_shift: closeShift,
    };
  };

  const submitCashup = async () => {
    if (savingCashup) return;
    if (!validateCountedPayments(cashupPayments)) return;

    try {
      setSavingCashup(true);

      const response = await cashupShift(
        buildCashupPayload({
          closeShift: false,
        })
      );

      showNotice(response?.message || "Cashup saved successfully.");
      setActionPanel(null);
      await loadShiftSummary();
    } catch (error) {
      showNotice(getApiMessage(error, "Cashup failed."));
    } finally {
      setSavingCashup(false);
    }
  };

  const submitEndShift = async () => {
    if (savingCashup) return;
    if (!validateCountedPayments(endShiftPayments)) return;

    try {
      setSavingCashup(true);

      const response = await endShiftApi(
        buildCashupPayload({
          closeShift: true,
        })
      );

      showNotice(response?.message || "Shift ended successfully.");

      if (isFrontOfficeWorkspace) {
        updateFrontOfficeUser?.({ active_shift: null, active_shift_session: null });
      }
      setActionPanel(null);
      clearSale();
      onClose?.();
    } catch (error) {
      showNotice(getApiMessage(error, "End shift failed."));
    } finally {
      setSavingCashup(false);
    }
  };

  const loadSalesReportsSnapshot = async () => {
    try {
      setReportsLoading(true);

      const today = new Date().toISOString().slice(0, 10);
      const params = {
        date_from: today,
        date_to: today,
      };

      const [
        dashboard,
        sales,
        payments,
        productsReport,
        cashiersReport,
      ] = await Promise.all([
        getSalesDashboard(params),
        getSalesReport({ ...params, per_page: 10 }),
        getPaymentReport(params),
        getProductSalesReport({ ...params, per_page: 10 }),
        getCashierSalesReport(params),
      ]);

      const snapshot = {
        dashboard: dashboard?.data || dashboard,
        sales: sales?.data || sales,
        payments: payments?.data || payments,
        products: productsReport?.data || productsReport,
        cashiers: cashiersReport?.data || cashiersReport,
      };

      setReportsSnapshot(snapshot);

      return snapshot;
    } catch (error) {
      showNotice(getApiMessage(error, "Failed to load sales reports."));
      return null;
    } finally {
      setReportsLoading(false);
    }
  };

  const openReportsSnapshot = async () => {
    setActionPanel("reportsSnapshot");
    await loadSalesReportsSnapshot();
  };

  const reprintLastReceipt = async () => {
    const receiptUrl = lastSaleResponse?.receipt_url;
    const printerName = lastSaleResponse?.print?.printer?.printer_name;
    const receiptNumber =
      lastSaleResponse?.receipt_number ||
      lastSaleResponse?.sale?.invoice_number ||
      "Receipt";

    if (!receiptUrl) {
      showNotice("No receipt available to reprint.");
      return;
    }

    if (!printerName) {
      window.open(receiptUrl, "_blank", "noopener,noreferrer");
      showNotice("Receipt opened. No printer was returned for QZ print.");
      return;
    }

    try {
      setPrintingReceipt(true);

      await qzPrintPdfUrl({
        printerName,
        pdfUrl: receiptUrl,
        jobName: `Reprint ${receiptNumber}`,
      });

      showNotice(`Receipt reprinted on ${printerName}.`);
    } catch (error) {
      showNotice(getApiMessage(error, "Receipt reprint failed."));
    } finally {
      setPrintingReceipt(false);
    }
  };

  const updatePaymentRowAmount = useCallback((rowId, nextValue) => {
    setPaymentRows((prev) =>
      prev.map((row) =>
        row.id === rowId
          ? {
              ...row,
              amount: nextValue,
              currency_code: saleCurrencyCode,
            }
          : row
      )
    );

    if (rowId === activePaymentRowId || !activePaymentRowId) {
      setAmountReceived(nextValue);
    }
  }, [activePaymentRowId, saleCurrencyCode]);

  const selectActivePaymentType = useCallback((paymentTypeId) => {
    setSelectedPaymentTypeId(paymentTypeId || "");

    setPaymentRows((prev) => {
      if (prev.length === 0) {
        const row = createPaymentRow({
          paymentTypeId,
          currencyCode: saleCurrencyCode,
          amount: amountReceived,
        });

        setActivePaymentRowId(row.id);
        return [row];
      }

      const targetId = activePaymentRowId || prev[0]?.id;

      return prev.map((row) =>
        row.id === targetId
          ? {
              ...row,
              payment_type_id: paymentTypeId,
              currency_code: saleCurrencyCode,
            }
          : row
      );
    });
  }, [activePaymentRowId, amountReceived, saleCurrencyCode]);

  const addPaymentRow = useCallback(() => {
    const row = createPaymentRow({
      paymentTypeId: selectedPaymentTypeId || paymentTypes?.[0]?.id || "",
      currencyCode: saleCurrencyCode,
      amount: "",
    });

    setPaymentRows((prev) => [...prev, row]);
    setActivePaymentRowId(row.id);
    setAmountReceived("");
  }, [paymentTypes, saleCurrencyCode, selectedPaymentTypeId]);

  const removePaymentRow = useCallback((rowId) => {
    setPaymentRows((prev) => {
      if (prev.length <= 1) {
        const first = prev[0];
        return first
          ? [
              {
                ...first,
                amount: "",
                currency_code: saleCurrencyCode,
              },
            ]
          : prev;
      }

      const next = prev.filter((row) => row.id !== rowId);
      const nextActive = next[0] || null;

      if (rowId === activePaymentRowId) {
        setActivePaymentRowId(nextActive?.id || "");
        setAmountReceived(nextActive?.amount || "");
      }

      return next;
    });
  }, [activePaymentRowId, saleCurrencyCode]);

  const selectPaymentRow = useCallback((rowId) => {
    const row = activePaymentRows.find((item) => item.id === rowId);
    setActivePaymentRowId(rowId);
    setSelectedPaymentTypeId(row?.payment_type_id || selectedPaymentTypeId || "");
    setAmountReceived(row?.amount || "");
  }, [activePaymentRows, selectedPaymentTypeId]);

  const updatePaymentRowType = useCallback((rowId, paymentTypeId) => {
    setPaymentRows((prev) =>
      prev.map((row) =>
        row.id === rowId
          ? {
              ...row,
              payment_type_id: paymentTypeId,
              currency_code: saleCurrencyCode,
            }
          : row
      )
    );

    setActivePaymentRowId(rowId);
    setSelectedPaymentTypeId(paymentTypeId || "");
  }, [saleCurrencyCode]);

  const updateAmount = useCallback((nextValue) => {
    if (activePaymentRow?.id) {
      updatePaymentRowAmount(activePaymentRow.id, nextValue);
      return;
    }

    setAmountReceived(nextValue);
  }, [activePaymentRow, updatePaymentRowAmount]);

  // True while the tendered amount is the amount due filled in by the payment
  // page itself: the first key typed then replaces it instead of appending.
  const tenderPrefilledRef = useRef(false);

  const appendPaymentValue = useCallback(
    (nextValue) => {
      if (!amountTenderedRequired) return;
      const current = tenderPrefilledRef.current ? "" : activePaymentAmount || "";
      tenderPrefilledRef.current = false;
      updateAmount(appendDecimalNumber(current, nextValue));
    },
    [activePaymentAmount, updateAmount, amountTenderedRequired]
  );

  const backspacePaymentValue = useCallback(() => {
    if (!amountTenderedRequired) return;
    const current = tenderPrefilledRef.current ? "" : String(activePaymentAmount || "");
    tenderPrefilledRef.current = false;
    updateAmount(current.slice(0, -1));
  }, [activePaymentAmount, updateAmount, amountTenderedRequired]);

  const clearPaymentValue = useCallback(() => {
    if (!amountTenderedRequired) return;
    tenderPrefilledRef.current = false;
    updateAmount("");
  }, [updateAmount, amountTenderedRequired]);

  const exactPayment = useCallback(() => {
    if (!amountTenderedRequired) return;
    tenderPrefilledRef.current = false;

    const activeId = activePaymentRow?.id;
    const paidByOtherRows = activePaymentRows
      .filter((row) => row.id !== activeId)
      .reduce((sum, row) => sum + Number(row.amount || 0), 0);
    const remaining = Math.max(Number(totals.total || 0) - paidByOtherRows, 0);

    updateAmount(remaining.toFixed(2));
  }, [activePaymentRow, activePaymentRows, totals.total, updateAmount, amountTenderedRequired]);

  const quickPayment = useCallback(
    (value) => {
      if (!amountTenderedRequired) return;
      tenderPrefilledRef.current = false;
      updateAmount(String(value));
    },
    [updateAmount, amountTenderedRequired]
  );

  // The payment page opens with the amount due already entered, so the
  // cashier never types the cash twice: Complete Sale works straight away.
  useEffect(() => {
    if (rightMode !== "payment") {
      tenderPrefilledRef.current = false;
      return;
    }
    if (!amountTenderedRequired || activePaymentRows.length !== 1 || activePaymentAmount !== "") return;
    const due = Number(totals.total || 0);
    if (due <= 0) return;
    updateAmount(due.toFixed(2));
    tenderPrefilledRef.current = true;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [rightMode, amountTenderedRequired]);

  const handleAutoPrintReceipt = useCallback(async (response) => {
    const payload = getSaleResponsePayload(response);

    const autoPrint = payload?.print?.auto_print === true;
    const receiptUrl = payload?.receipt_url;
    const printerName = payload?.print?.printer?.printer_name;
    const receiptNumber =
      payload?.receipt_number ||
      payload?.sale?.invoice_number ||
      "Receipt";

    if (!autoPrint) return;

    if (!receiptUrl) {
      showNotice("Sale completed, but receipt URL was not returned.");
      return;
    }

    if (!printerName) {
      showNotice("Sale completed, but no active receipt printer was found.");
      return;
    }

    try {
      setPrintingReceipt(true);

      await qzPrintPdfUrl({
        printerName,
        pdfUrl: receiptUrl,
        jobName: receiptNumber,
      });

      showNotice(`Receipt sent to ${printerName}.`);
    } catch (error) {
      showNotice(
        `Sale completed, but printing failed:\n${getApiMessage(
          error,
          "Could not print receipt."
        )}`
      );
    } finally {
      setPrintingReceipt(false);
    }
  }, []);

  const openDiscountModal = useCallback(() => {
  if (!selectedLine) {
    showNotice("Select a sale line first.");
    focusBarcode();
    return;
  }

  setActionPanel(null);
  setDiscountModalOpen(true);
}, [selectedLine, focusBarcode]);

  const handleAutoPrintKitchen = useCallback(async (response) => {
    if (!isHospitality) return;

    const payload = getSaleResponsePayload(response);
    const kitchenPrint =
      payload?.kitchen_print ||
      payload?.fast_food?.kitchen_print ||
      payload?.print?.kitchen ||
      null;

    if (!kitchenPrint?.auto_print) return;

    const printerName = kitchenPrint?.printer?.printer_name;
    const orderNo =
      kitchenPrint?.payload?.order_number ||
      payload?.order_number ||
      payload?.fast_food?.order_number ||
      "Kitchen Order";

    if (!printerName) {
      showNotice("Sale completed, but no active kitchen printer was found.");
      return;
    }

    try {
      await dispatchSalePrints(response, {
        printCustomer: false,
        printKitchen: true,
      });

      showNotice(`Kitchen order ${orderNo} sent to ${printerName}.`);
    } catch (error) {
      showNotice(
        `Sale completed, but kitchen printing failed:\n${getApiMessage(
          error,
          "Could not print kitchen order."
        )}`
      );
    }
  }, [isHospitality]);

  const handleOpenCashDrawer = useCallback(async (response) => {
    const payload = getSaleResponsePayload(response);

    const printerName = payload?.print?.printer?.printer_name;
    const receiptNumber =
      payload?.receipt_number ||
      payload?.sale?.invoice_number ||
      "Sale";

    try {
      await qzOpenCashDrawer({
        printerName,
        jobName: `Open Drawer ${receiptNumber}`,
      });
    } catch (error) {
      showNotice(
        `Sale completed, but cash drawer did not open:\n${getApiMessage(
          error,
          "Could not open cash drawer."
        )}`
      );
    }
  }, []);

  // directPayment ({ paymentType, tendered }) completes a sale straight from
  // the selling screen: Pay uses the cash typed on the keypad (or the exact
  // total) without the payment page. The payment page's Complete button
  // passes a click event, which is not a direct payment.
  const completeSale = useCallback(async (directPayment = null) => {
    if (savingSale) return;

    const direct = directPayment && typeof directPayment === "object" && "tendered" in directPayment
      ? directPayment
      : null;
    const paymentType = direct?.paymentType || selectedPaymentType;

    if (cart.length === 0) {
      showNotice("No items in the sale.");
      setRightMode("browse");
      return;
    }

    if (!paymentType) {
      showNotice("Select payment method first.");
      return;
    }

    if (!saleCurrencyCode) {
      showNotice("Select sale currency first.");
      return;
    }

    try {
      const previousRate = exchangeRateQuote;
      const liveRate = await refreshExchangeRateQuote(saleCurrencyCode);

      if (
        previousRate !== null &&
        liveRate !== null &&
        Math.abs(Number(previousRate) - Number(liveRate)) > 0.00000001
      ) {
        conversionCacheRef.current = {};
        await convertCartToCurrency(saleCurrencyCode, saleCurrencyCode);
        setAmountReceived("");
        setPaymentRows((current) => current.map((row) => ({ ...row, amount: "" })));
        showNotice(`The ${saleCurrencyCode} rate changed from ${previousRate} to ${liveRate}. Prices were refreshed; please review the total and enter payment again.`);
        return;
      }
    } catch (error) {
      showNotice(getApiMessage(error, "Could not verify the current exchange rate."));
      return;
    }

    const tenderRequired = direct ? paymentRequiresAmountTendered(paymentType) : amountTenderedRequired;
    const directTendered = direct ? Number(direct.tendered || 0) : 0;
    const receivedAmount = direct ? directTendered : totals.received;
    const saleChange = direct
      ? Math.max(directTendered - Number(totals.total || 0), 0)
      : totals.change;

    let salePaymentRows = (direct
      ? [{ payment_type_id: paymentType.id, currency_code: saleCurrencyCode, amount: directTendered }]
      : activePaymentRows)
      .map((row) => ({
        ...row,
        payment_type_id: row.payment_type_id || paymentType?.id || "",
        currency_code: row.currency_code || saleCurrencyCode,
        amount: Number(row.amount || 0),
      }))
      .filter((row) => row.payment_type_id && row.amount > 0);

    if (!paymentRequiresAmountTendered(paymentType) && !isCustomerCreditPaymentType(paymentType)) {
      salePaymentRows = [
        {
          payment_type_id: paymentType.id,
          currency_code: saleCurrencyCode,
          amount: Number(totals.total || 0),
        },
      ];
    }

    const hasCustomerCreditPayment = salePaymentRows.some((row) =>
      isCustomerCreditPaymentType(paymentTypeById.get(String(row.payment_type_id)))
    );

    const creditSale = isCustomerCreditPaymentType(paymentType) || hasCustomerCreditPayment;

    if (creditSale && !selectedCreditCustomerId) {
      showNotice("Select an approved credit customer before completing this sale.");
      return;
    }

    if (creditSale && creditLimitExceeded) {
      showNotice("Credit limit exceeded. Customer does not have enough available credit.");
      return;
    }

    if (!creditSale && salePaymentRows.length === 0) {
      showNotice("Add at least one payment amount before completing the sale.");
      return;
    }

    const hasCurrencyMismatch = salePaymentRows.some(
      (row) => String(row.currency_code || "").toUpperCase() !== String(saleCurrencyCode || "").toUpperCase()
    );

    if (hasCurrencyMismatch) {
      showNotice("Split payments must all use the sale currency.");
      return;
    }

    if (creditSale && salePaymentRows.length > 1) {
      showNotice("Customer credit cannot be mixed with other payment methods.");
      return;
    }

    if (!creditSale && tenderRequired && receivedAmount + 0.00001 < totals.total) {
      showNotice("Amount received is less than the sale total.");
      return;
    }

    if (postChangeToCreditAccount && creditSale) {
      showNotice("Change can only be credited from a paid sale, not from a customer credit sale.");
      return;
    }

    if (postChangeToCreditAccount && !canCaptureCreditCustomerPayment) {
      showNotice("Your role does not have permission to post customer change to an account.");
      return;
    }

    if (postChangeToCreditAccount && Number(totals.change || 0) <= 0.0001) {
      showNotice("There is no sale change to post to a customer account.");
      return;
    }

    if (postChangeToCreditAccount && !changeCreditCustomerId) {
      showNotice("Select the customer account that agreed to receive the change.");
      return;
    }

    const blockedStockLine = cart.find((line) => {
      const stockCheck = validateLineStockForQty(line, line.qty);
      return !stockCheck.allowed;
    });

    if (blockedStockLine) {
      const stockCheck = validateLineStockForQty(blockedStockLine, blockedStockLine.qty);
      showNotice(stockCheck.message);
      setRightMode("browse");
      setSelectedLineId(blockedStockLine.lineId);
      focusBarcode();
      return;
    }

    let checkoutHospitalityOrder = hospitalityCurrentOrder;
    if (isHospitality && isFrontOfficeWorkspace) {
      checkoutHospitalityOrder = await persistHospitalityCart(cart, { force: true });
      if (!checkoutHospitalityOrder) {
        showNotice("Save the hospitality order and table assignment before taking payment.");
        setRightMode("browse");
        return;
      }
    }

    const amountTendered = creditSale
      ? 0
      : tenderRequired
      ? (direct ? directTendered : Number(activePaymentReceived || 0))
      : Number(totals.total || 0);

    const primaryPaymentTypeId = salePaymentRows[0]?.payment_type_id || paymentType.id;

    const payload = {
      client_sale_id: clientSaleId,
      hospitality_table_order_id: hospitalityOrderId(checkoutHospitalityOrder) || null,
      hospitality_table_order_revision: checkoutHospitalityOrder
        ? hospitalityOrderRevision(checkoutHospitalityOrder)
        : null,
      service_type: isHospitality ? hospitalityServiceType : null,
      hospitality_table_id:
        checkoutHospitalityOrder?.table_id ||
        checkoutHospitalityOrder?.table?.id ||
        hospitalitySelectedTableId ||
        null,
      currency_code: saleCurrencyCode,
      payment_type_id: primaryPaymentTypeId,
      amount_tendered: Number(amountTendered.toFixed(4)),
      payments: creditSale
        ? []
        : salePaymentRows.map((row) => ({
            payment_type_id: row.payment_type_id,
            currency_code: saleCurrencyCode,
            amount: Number(Number(row.amount || 0).toFixed(4)),
          })),
      credit_customer_id: creditSale ? selectedCreditCustomerId : null,
      credit_po_number: creditSale ? creditPoNumber?.trim() || null : null,
      post_change_to_credit_account: Boolean(postChangeToCreditAccount && !creditSale),
      change_credit_customer_id:
        postChangeToCreditAccount && !creditSale ? changeCreditCustomerId : null,
      customer_name:
        creditSale && selectedCreditCustomer
          ? getCreditCustomerName(selectedCreditCustomer)
          : customer.name?.trim() || "Walk-in Customer",
      customer_phone:
        creditSale && selectedCreditCustomer
          ? selectedCreditCustomer.phone || selectedCreditCustomer.mobile || null
          : customer.phone?.trim() || null,
      customer_tax_number:
        creditSale && selectedCreditCustomer
          ? selectedCreditCustomer.tax_number || selectedCreditCustomer.taxNumber || null
          : customer.taxNumber?.trim() || null,
      notes: customer.notes?.trim() || null,
      quotation_id: loadedQuotation?.id || null,
      quote_code: loadedQuotation?.quote_code || null,
      products: cart.map((line) => ({
        product_id: line.product_id || line.productId,
        category_id:
          line.category_id ||
          line.categoryId ||
          line.rawProduct?.category_id ||
          line.rawProduct?.category?.id ||
          line.discountPayload?.category_id ||
          null,
        product_price_id: line.productPriceId || null,
        quantity: Number(line.qty || 0),
        discount_amount: Number(getLineDiscount(line) || 0),
        discount_payload: line.discountPayload || null,
        prep_message_ids: line.prep_message_ids || line.prepMessageIds || [],
        prep_messages: line.prep_messages || line.prepMessages || [],
        prep_note: line.prep_note || line.prepNote || null,
        modifier_ids: line.modifier_ids || line.modifierIds || [],
        modifiers: (line.modifiers || []).map((modifier) => ({
          id: modifier.id || modifier.modifier_id,
          modifier_id: modifier.modifier_id || modifier.id,
          name: modifier.name,
          quantity: Number(modifier.quantity || 1),
          price: Number(modifier.price || 0),
          currency_code: modifier.currency_code || modifier.currency || line.currency || saleCurrencyCode,
          recipe_id: modifier.recipe_id || null,
          print_on_kitchen: modifier.print_on_kitchen !== false,
          print_on_customer_receipt: modifier.print_on_customer_receipt !== false,
          deduct_recipe_stock: modifier.deduct_recipe_stock !== false,
        })),
        quotation_id: line.quotationId || loadedQuotation?.id || null,
        quote_code: line.quotationCode || loadedQuotation?.quote_code || null,
        quotation_item_id: line.quotationItemId || null,
        price_level_override: line.priceOverride
          ? {
              audit_id: line.priceOverride.audit_id,
              manager_approval_id: line.priceOverride.manager_approval_id,
              product_price_id: line.priceOverride.product_price_id,
              price_level_id: line.priceOverride.price_level_id,
              price: Number(line.basePrice || line.price || 0),
              currency: line.baseCurrency || line.currency,
            }
          : null,
      })),
    };

    try {
      setSavingSale(true);

      const response = await createSale(payload);
      const salePayload = getSaleResponsePayload(response);
      const changeWasCredited = boolValue(salePayload?.change_posted_to_account);
      const { creditedChange, cashChangeReturned } = resolveCreditedSaleChange(
        salePayload,
        saleChange
      );
      const creditedCurrency = salePayload?.change_credit_currency_code || saleCurrencyCode;
      const creditedCustomer = salePayload?.change_credit_customer || null;
      const creditedAccountCurrency =
        creditedCustomer?.currency_code ||
        salePayload?.credited_change?.account_currency_code ||
        creditedCurrency;
      const creditedAccountAmount = Number(
        salePayload?.credited_change?.account_amount ?? creditedChange
      );
      const resultingBalance = Number(
        creditedCustomer?.current_balance ??
        creditedCustomer?.balance ??
        salePayload?.change_credit_resulting_balance ??
        0
      );

      if (changeWasCredited) {
        setChangeCreditReceipt({
          tendered: Number(amountTendered || totals.received || 0),
          cashChangeReturned,
          creditedChange,
          saleCurrency: creditedCurrency,
          accountCurrency: creditedAccountCurrency,
          accountAmount: creditedAccountAmount,
          accountNumber:
            salePayload?.change_credit_account_number ||
            getCreditCustomerAccount(creditedCustomer) ||
            "-",
          customerName: getCreditCustomerName(creditedCustomer),
          resultingBalance,
          resultingCredit: Math.max(0, -resultingBalance),
          paymentNumber:
            salePayload?.change_credit_payment_number ||
            salePayload?.change_credit_payment?.payment_number ||
            salePayload?.change_credit_reference ||
            salePayload?.change_credit_payment?.reference ||
            "-",
          customerId:
            salePayload?.change_credit_customer_id ||
            creditedCustomer?.id ||
            salePayload?.credited_change?.customer_id ||
            null,
          paymentId:
            salePayload?.change_credit_payment?.id ||
            salePayload?.credited_change?.payment_id ||
            null,
          receiptNumber:
            salePayload?.receipt_number ||
            salePayload?.sale?.invoice_number ||
            "-",
        });
      }

      setSaleComplete(true);
      setLastSaleResponse(salePayload);
      setSplitPaymentModalOpen(false);

      // The server opens the cashier's shift with the sale when none was open.
      const saleShift = salePayload?.shift;
      if (isFrontOfficeWorkspace && saleShift?.session_id && saleShift.session_id !== activeShift?.id) {
        const openedShift = {
          id: saleShift.session_id,
          shift_configuration_id: saleShift.id ?? null,
          shift_name: saleShift.shift_name,
          opened_at: saleShift.opened_at,
          status: "open",
        };
        updateFrontOfficeUser?.({ active_shift: openedShift, active_shift_session: openedShift });
      }

      await loadProducts();

      if (changeWasCredited) {
        showNotice(`Sale completed. Change returned: ${money(cashChangeReturned, creditedCurrency)}. Change credited: ${money(creditedChange, creditedCurrency)} to account ${salePayload?.change_credit_account_number || "-"}.`);
      } else if (Number(saleChange || 0) > 0.0001) {
        // The cashier reads this while handing over change: keep it up longer.
        showNotice(`Sale completed. CHANGE: ${money(saleChange, saleCurrencyCode)}`, { duration: 8000 });
      } else {
        showNotice(response?.message || salePayload?.message || "Sale completed successfully.");
      }

      sendCustomerDisplayUpdate({
        status: "completed",
        cart,
        totals: {
          ...totals,
          received: direct ? directTendered : totals.received,
          change: changeWasCredited ? cashChangeReturned : saleChange,
          changeCredited: changeWasCredited ? creditedChange : 0,
          changeCreditCurrency: changeWasCredited ? creditedCurrency : null,
        },
        customer,
        saleComplete: true,
        lastSaleResponse: salePayload,
        receiptNumber:
          salePayload?.receipt_number ||
          salePayload?.sale?.invoice_number ||
          null,
        message: "Thank you for shopping with us",
        updated_at: new Date().toISOString(),
      });

      await handleAutoPrintReceipt(response);
      await handleAutoPrintKitchen(response);
      await handleOpenCashDrawer(response);

      if (loadedQuotation?.quote_code) {
        try {
          await markQuotationConverted(
            loadedQuotation.quote_code,
            salePayload?.sale?.id ||
              salePayload?.sales_transaction_id ||
              salePayload?.id ||
              null
          );
        } catch (conversionError) {
          showNotice(
            `Sale completed, but quotation conversion update failed:\n${getApiMessage(
              conversionError,
              "Could not mark quotation as converted."
            )}`
          );
        }
      }

      /*
       * After a successful sale, clear the left sale-items workspace immediately
       * so the cashier is ready for the next customer. We keep lastSaleResponse
       * for reprint, and saleComplete stays true so the customer display can show
       * the thank-you/completed state.
       */
      setCart([]);
      hospitalityPersistenceSuspendedRef.current = true;
      setHospitalityCurrentOrder(null);
      setHospitalitySelectedTableId("");
      setHospitalitySaveError("");
      hospitalityPersistedSignatureRef.current = "";
      hospitalityClientRequestIdRef.current = createHospitalityRequestId();
      setClientSaleId(createSaleAttemptId());
      setSelectedLineId(null);
      setPendingEditLineId(null);
      setAmountReceived("");
      setPaymentRows([]);
      setActivePaymentRowId("");
      setSelectedCreditCustomerId("");
      setCreditPoNumber("");
      setPostChangeToCreditAccount(false);
      setChangeCreditCustomerId("");
      setRightMode("browse");
      setSelectedCategoryId("all");
      setBrowseTab("categories");
      setSearch("");
      setBarcodeInput("");
      setActionPanel(null);
      setDiscountModalOpen(false);
      setEditQty("");
      setDiscountValue("");
      setQuoteCode("");
      setLoadedQuotation(null);
      conversionCacheRef.current = {};
      resetCustomer();

      const base = getBaseCurrency(currencies);
      const baseCode = getCurrencyCode(base);

      if (baseCode) {
        setSaleCurrencyCode(baseCode);
        setExchangeRateQuote(1);
      }

      focusBarcode();
      window.setTimeout(() => { hospitalityPersistenceSuspendedRef.current = false; }, 0);
      loadHospitalityWorkspace({ quiet: true });
    } catch (error) {
      const details = extractSaleErrorDetails(error, "Sale failed.");

      setSaleError(details);
      showNotice(details.title || details.message || "Sale failed.");
      setRightMode("browse");
      focusBarcode();
    } finally {
      setSavingSale(false);
    }
  }, [
    savingSale,
    clientSaleId,
    cart,
    activePaymentRows,
    activePaymentReceived,
    paymentTypeById,
    selectedPaymentType,
    saleCurrencyCode,
    exchangeRateQuote,
    refreshExchangeRateQuote,
    convertCartToCurrency,
    amountTenderedRequired,
    totals.received,
    totals.total,
    amountReceived,
    customer,
    selectedCreditCustomerId,
    selectedCreditCustomer,
    creditPoNumber,
    creditLimitExceeded,
    postChangeToCreditAccount,
    changeCreditCustomerId,
    canCaptureCreditCustomerPayment,
    hospitalityCurrentOrder,
    hospitalitySelectedTableId,
    hospitalityServiceType,
    handleAutoPrintReceipt,
    handleAutoPrintKitchen,
    handleOpenCashDrawer,
    currencies,
    getLineDiscount,
    loadedQuotation,
    isFrontOfficeWorkspace,
    isHospitality,
    loadHospitalityWorkspace,
    persistHospitalityCart,
    activeShift,
    updateFrontOfficeUser,
  ]);

  // Pay from the selling screen: complete the sale with the cash typed on the
  // keypad (or the exact total when nothing was typed) without the separate
  // payment page. Account sales still need that page to pick the customer.
  const quickPay = useCallback(async (keyedAmount = null, { cash = false } = {}) => {
    if (savingSale) return false;
    if (cart.length === 0) {
      showNotice("Add items before processing the sale.");
      return false;
    }

    const paymentType = cash
      ? paymentTypes.find((type) => normalizeText(type?.name).includes("cash")) || selectedPaymentType
      : selectedPaymentType;
    if (!paymentType) {
      showNotice(
        paymentTypes.length === 0
          ? "No payment methods are set up for this shop. Add one in Back Office → Store Configuration → Payment Method with this shop selected."
          : "Select payment method first."
      );
      return false;
    }
    if (isCustomerCreditPaymentType(paymentType)) {
      handleProcess();
      return false;
    }

    const total = Number(totals.total || 0);
    const keyed = Number(keyedAmount);
    const tendered = paymentRequiresAmountTendered(paymentType) && keyedAmount !== null && keyed > 0
      ? keyed
      : total;
    if (tendered + 0.00001 < total) {
      showNotice(`Cash received ${money(tendered, saleCurrencyCode)} is less than the total ${money(total, saleCurrencyCode)}.`);
      return false;
    }

    await completeSale({ paymentType, tendered });
    return true;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [savingSale, cart.length, paymentTypes, selectedPaymentType, totals.total, saleCurrencyCode, completeSale]);

  useEffect(() => {
    if (!open) return;

    loadSaleData();
    if (isHospitality && isFrontOfficeWorkspace) {
      loadHospitalityWorkspace();
    }
    focusBarcode();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  // The till menu comes from the workspace configuration, which is reloaded
  // when sync brings new configuration (or arrives after the screen opened).
  // Rebuild the product list from it so new menu items appear without a
  // page reload.
  useEffect(() => {
    if (!open || !hasAuthoritativeTillMenu) return;
    setProducts(sellableTillMenuItems(configuredMenuItems));
    setCategories(configuredMenuCategories);
    setLoadingProducts(false);
    setLoadingCategories(false);
  }, [open, hasAuthoritativeTillMenu, configuredMenu]);

  useEffect(() => {
    if (!open) return;
    loadSalesLayoutSetting();
    // The trusted layout is supplied by login/workspace bootstrap and changes
    // only when the active branch configuration changes.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, trustedSalesLayout]);

  const checkoutOverlayOpen = Boolean(
    splitPaymentModalOpen ||
      customerAccountModalOpen ||
      changeCreditReceipt ||
      prepMessageModalOpen ||
      modifierModalOpen ||
      splitLineModalOpen ||
      discountModalOpen ||
      managerApprovalOpen ||
      loadQuoteModalOpen ||
      enterpriseCashupOpen ||
      enterpriseEndShiftOpen ||
      saleError ||
      actionPanel
  );

  useEffect(() => {
    if (!open) return;
    if (rightMode !== "payment") return;
    if (!amountTenderedRequired) return;
    if (checkoutOverlayOpen) return;

    const handleKeyboard = (event) => {
      const target = event.target;
      const isTyping =
        target?.tagName === "INPUT" ||
        target?.tagName === "TEXTAREA" ||
        target?.tagName === "SELECT";

      if (isTyping && target?.dataset?.paymentInput !== "true") {
        return;
      }

      const { key } = event;

      if (/^[0-9]$/.test(key)) {
        event.preventDefault();
        appendPaymentValue(key);
      }

      if (key === ".") {
        event.preventDefault();
        appendPaymentValue(".");
      }

      if (key === "Backspace") {
        event.preventDefault();
        backspacePaymentValue();
      }

      if (key === "Enter") {
        event.preventDefault();
        completeSale();
      }

      if (key.toLowerCase() === "c") {
        event.preventDefault();
        clearPaymentValue();
      }

      if (key === "Escape") {
        event.preventDefault();
        setRightMode("browse");
        focusBarcode();
      }
    };

    window.addEventListener("keydown", handleKeyboard);

    return () => window.removeEventListener("keydown", handleKeyboard);
  }, [
    open,
    rightMode,
    amountTenderedRequired,
    appendPaymentValue,
    backspacePaymentValue,
    clearPaymentValue,
    completeSale,
    checkoutOverlayOpen,
  ]);

  useEffect(() => {
    if (!open) return;

    const handleSaleShortcuts = (event) => {
      if (rightMode === "payment" || checkoutOverlayOpen) return;

      const target = event.target;
      const isTyping =
        target?.tagName === "INPUT" ||
        target?.tagName === "TEXTAREA" ||
        target?.tagName === "SELECT";

      if (isTyping) return;

      switch (event.key) {
        case "F2":
          event.preventDefault();
          openDiscountModal();
          break;
        case "F3":
          event.preventDefault();
          setSearch("");
          focusBarcode();
          break;
        case "F4":
          event.preventDefault();
          openAction("editLine");
          break;
        case "F7":
          event.preventDefault();
          openAction("transfer");
          break;
        case "F8":
          event.preventDefault();
          clearSale();
          break;
        case "F9":
          event.preventDefault();
          noSale();
          break;
        case "F10":
          event.preventDefault();
          quickPay(null);
          break;
        case "F12":
          event.preventDefault();
          quickPay(null, { cash: true });
          break;
        case "Delete":
          event.preventDefault();
          removeSelectedLine();
          break;
        default:
          break;
      }
    };

    window.addEventListener("keydown", handleSaleShortcuts);

    return () => window.removeEventListener("keydown", handleSaleShortcuts);
  }, [
    open,
    paymentTypes,
    openDiscountModal,
    openAction,
    clearSale,
    noSale,
    handleProcess,
    quickPay,
    removeSelectedLine,
    rightMode,
    checkoutOverlayOpen,
  ]);

  useEffect(() => {
    if (!open) {
      clearCustomerDisplay();
      return;
    }

    pushCustomerDisplayUpdate();
  }, [open, pushCustomerDisplayUpdate]);

  const shouldShowActionPanel =
    actionPanel &&
    ((actionPanel !== "editLine" && actionPanel !== "discount") || selectedLine);

  return (
    <FullScreenModal
      open={open}
      onClose={async () => {
        const canClose = await clearSale();
        if (canClose === false) return;
        clearCustomerDisplay();
        onClose?.();
      }}
      title="CREATE SALE"
      subtitle={
        <span title={`Logged in as ${operatorName} / ${activeShiftLabel}`}>
          <span className={`font-semibold ${activeShift ? "text-[#087F5B]" : "text-[#B42318]"}`}>
            {operatorName} · {activeShiftLabel}
          </span>
          {` / ${salesLayout === "visual" ? "VISUAL TOUCH MODE" : "STANDARD KEYBOARD MODE"}`}
        </span>
      }
      headerContent={
        <div className="flex h-full min-w-0 flex-1 items-center gap-3 overflow-x-auto overflow-y-hidden px-3">
          <div className="flex shrink-0 items-center gap-2">
            <div className="hidden text-[9px] font-black uppercase tracking-[0.12em] text-[#546766] 2xl:block">Currency</div>
            <div className="flex items-center gap-1">
              {currencies.map((currency) => {
                const code = getCurrencyCode(currency);
                const active = code === saleCurrencyCode;
                return <button key={currency.id || code} type="button" disabled={convertingCurrency || loadingCurrencies} onClick={() => handleSaleCurrencyChange(code)} title={getCurrencyName(currency)} className={`h-8 min-w-[58px] shrink-0 rounded px-3 text-[10px] font-black transition-colors disabled:opacity-50 ${active ? "bg-[#007474] text-white" : "border border-[#ACB8B8] bg-[#F7F9FB] text-[#384B4A] hover:bg-[#ECF2F2]"}`}>{code}</button>;
              })}
              {!currencies.length && <span className="text-[10px] font-semibold text-[#798887]">{loadingCurrencies ? "Loading currencies..." : saleCurrencyCode || "No configured currencies"}</span>}
            </div>
            <div className="hidden whitespace-nowrap text-[9px] font-semibold text-[#6E7D7C] 2xl:block">
              {exchangeRateQuote !== null ? `1 ${getCurrencyCode(getBaseCurrency(currencies)) || "base"} = ${exchangeRateQuote} ${saleCurrencyCode}` : ""}
            </div>
          </div>

          {isHospitality && isFrontOfficeWorkspace && (
            <div className="flex h-full min-w-0 flex-1 items-center border-l border-[#D9DDDD] pl-3">
              <HospitalityServiceBar
                enabled
                inline
                serviceType={hospitalityServiceType}
                assignmentMode={hospitalityAssignmentMode}
                selectedTableId={hospitalitySelectedTableId}
                settings={hospitalitySettings}
                tables={hospitalityTables}
                currentOrder={hospitalityCurrentOrder}
                openOrders={hospitalityOpenOrders}
                cartLength={cart.length}
                busy={hospitalityBusy}
                saveError={hospitalitySaveError}
                canClear={canClearOpenTables}
                onServiceTypeChange={(nextMode) => {
                  setHospitalityServiceType(nextMode);
                  setHospitalitySelectedTableId("");
                  setHospitalitySaveError("");
                  hospitalityPersistedSignatureRef.current = "";
                }}
                onTableSelect={(nextTableId) => {
                  setHospitalitySelectedTableId(nextTableId);
                  setHospitalitySaveError("");
                  hospitalityPersistedSignatureRef.current = "";
                }}
                onRefresh={() => loadHospitalityWorkspace()}
                onRecall={recallHospitalityOrder}
                onHang={() => clearSale()}
                onSendKitchen={sendCurrentOrderToKitchen}
                onClear={clearOpenHospitalityOrder}
                onRetry={() => persistHospitalityCart(cart, { force: true })}
              />
            </div>
          )}
        </div>
      }
    >
      <div className="servenest-pos-surface relative grid h-full min-h-0 grid-rows-[minmax(0,1fr)] overflow-hidden bg-[#EFF3F3] text-[#003636] font-black">
        {notice && (
          // White card so it stands out against the dark teal menu bar it overlaps.
          <div role="status" className="absolute left-1/2 top-3 z-[60] max-w-[min(560px,calc(100%-24px))] -translate-x-1/2 whitespace-pre-line border border-[#ACB8B8] border-l-4 border-l-[#069494] bg-white px-4 py-2.5 text-[13px] font-black text-[#1C2C2B] shadow-[0_12px_30px_rgba(9,27,27,0.25)]">
            {notice}
          </div>
        )}

        {(convertingCurrency ||
          savingSale ||
          printingReceipt ||
          loadingProducts ||
          loadingCreditCustomers ||
          loadingShiftSummary ||
          savingCashup ||
          reportsLoading ||
          loadingSalesLayout) && (
          <div className="absolute right-3 top-3 z-[60] border border-[#00BCBC] bg-white px-3 py-2 text-[11px] font-black text-[#005A5A] shadow-xl">
            {savingSale
              ? "Saving sale..."
              : printingReceipt
              ? "Printing receipt..."
              : loadingProducts
              ? "Refreshing products..."
              : loadingCreditCustomers
              ? "Loading credit customers..."
              : loadingShiftSummary
              ? "Loading shift..."
              : savingCashup
              ? "Saving..."
              : reportsLoading
              ? "Loading reports..."
              : loadingSalesLayout
              ? "Loading layout..."
              : "Converting currency..."}
          </div>
        )}

        <div className="min-h-0 overflow-hidden">
        {loadingSalesLayout || !salesLayout ? (
          <div className="flex h-full min-h-0 items-center justify-center bg-[#EEF1F1] text-[#293939]">
            <div className="border border-[#BAC8C8] bg-white px-8 py-6 text-center shadow-[0_12px_30px_rgba(9,27,27,0.08)]">
              <div className="mx-auto mb-3 flex h-10 w-10 items-center justify-center bg-[#EBF3F3] text-[#006E6E]">
                <RefreshCw className={`h-5 w-5 ${loadingSalesLayout ? "animate-spin" : ""}`} />
              </div>
              <div className="text-[13px] font-semibold uppercase tracking-[0.16em] text-[#243A3A]">{loadingSalesLayout ? "Preparing checkout" : "Sales screen unavailable"}</div>
              <div className="mt-1 text-[11px] font-normal text-[#697877]">{loadingSalesLayout ? "Applying this branch's configured sales screen" : "The configured screen must be confirmed before selling."}</div>
              {!loadingSalesLayout && !salesLayout ? (
                <button type="button" onClick={loadSalesLayoutSetting} className="mt-4 h-9 border border-[#006E6E] bg-[#006E6E] px-5 text-[10px] font-black uppercase tracking-wide text-white hover:bg-[#005A5A]">
                  Retry configuration
                </button>
              ) : null}
            </div>
          </div>
        ) : (
        <SalesPointFace
          layout={salesLayout}
          cart={cart}
          selectedLineId={selectedLineId}
          setSelectedLineId={setSelectedLineId}
          selectedLine={selectedLine}
          totals={totals}
          money={money}
          formatQty={formatQty}
          getLineTotal={getLineTotal}
          getLineTax={getLineTax}
          getLineSubtotal={getLineSubtotal}
          getLineDiscount={getLineDiscount}
          getLineTaxSummary={getLineTaxSummary}
          search={search}
          setSearch={setSearch}
          barcodeInput={barcodeInput}
          barcodeInputRef={barcodeInputRef}
          handleBarcodeSubmit={handleBarcodeSubmit}
          handleBarcodeChange={handleBarcodeChange}
          barcodeScanEnabled={barcodeScanEnabled}
          loadingBarcodeScanSetting={loadingBarcodeScanSetting}
          categoryOptions={categoryOptions}
          selectedCategoryId={selectedCategoryId}
          selectedCategory={selectedCategory}
          handleCategorySelect={handleCategorySelect}
          setSelectedCategoryId={setSelectedCategoryId}
          setBrowseTab={setBrowseTab}
          filteredProducts={filteredProducts}
          loadingProducts={loadingProducts}
          handleProductClick={handleProductClick}
          saleCurrencyCode={saleCurrencyCode}
          convertMoney={convertMoney}
          paymentTypes={paymentTypes}
          selectedPaymentTypeId={selectedPaymentTypeId}
          setSelectedPaymentTypeId={setSelectedPaymentTypeId}
          voidSale={voidSale}
          removeSelectedLine={removeSelectedLine}
          incrementSelectedLine={incrementSelectedLine}
          setSelectedLineQuantity={setSelectedLineQuantity}
          fastCash={fastCash}
          quickPay={quickPay}
          stepLineQuantity={stepLineQuantity}
          openAction={openAction}
          openDiscountModal={openDiscountModal}
          handleProcess={handleProcess}
          clearSale={clearSale}
          openCashup={openCashup}
          openMoreFunctions={openMoreFunctions}
          noSale={noSale}
          openCustomerScreen={openCustomerScreen}
          rightMode={rightMode}
          setRightMode={setRightMode}
          focusBarcode={focusBarcode}
          paymentPanel={
            <PaymentPanel
              totals={totals}
              amountReceived={amountReceived}
              paymentRows={activePaymentRows}
              activePaymentRowId={activePaymentRow?.id || ""}
              activePaymentAmount={activePaymentAmount}
              selectPaymentRow={selectPaymentRow}
              updatePaymentRowAmount={updatePaymentRowAmount}
              updatePaymentRowType={updatePaymentRowType}
              addPaymentRow={addPaymentRow}
              removePaymentRow={removePaymentRow}
              openSplitPaymentModal={() => setSplitPaymentModalOpen(true)}
              paymentTypes={paymentTypes}
              selectedPaymentTypeId={selectedPaymentTypeId}
              setSelectedPaymentTypeId={selectActivePaymentType}
              selectedPaymentType={selectedPaymentType}
              currencies={currencies}
              saleCurrencyCode={saleCurrencyCode}
              handleSaleCurrencyChange={handleSaleCurrencyChange}
              paymentCanChooseCurrency={paymentCanChooseCurrency}
              amountTenderedRequired={amountTenderedRequired}
              creditCustomers={creditCustomers}
              selectedCreditCustomerId={selectedCreditCustomerId}
              selectedCreditCustomer={selectedCreditCustomer}
              handleCreditCustomerChange={handleCreditCustomerChange}
              creditPoNumber={creditPoNumber}
              setCreditPoNumber={setCreditPoNumber}
              isCreditSale={isCreditSale}
              creditAvailable={creditAvailable}
              creditLimitExceeded={creditLimitExceeded}
              loadingCreditCustomers={loadingCreditCustomers}
              accountCustomers={accountCustomers}
              loadingAccountCustomers={loadingAccountCustomers}
              canCaptureCreditCustomerPayment={canCaptureCreditCustomerPayment}
              postChangeToCreditAccount={postChangeToCreditAccount}
              changeCreditCustomerId={changeCreditCustomerId}
              setChangeCreditCustomerId={setChangeCreditCustomerId}
              handlePostChangeToggle={handlePostChangeToggle}
              loadingPayments={loadingPayments}
              loadingCurrencies={loadingCurrencies}
              appendPaymentValue={appendPaymentValue}
              backspacePaymentValue={backspacePaymentValue}
              clearPaymentValue={clearPaymentValue}
              exactPayment={exactPayment}
              quickPayment={quickPayment}
              completeSale={completeSale}
              saleComplete={saleComplete}
              clearSale={clearSale}
              savingSale={savingSale}
              printingReceipt={printingReceipt}
              lastSaleResponse={lastSaleResponse}
              onBack={() => {
                setRightMode("browse");
                focusBarcode();
              }}
            />
          }
        />
        )}
        </div>

        <SaleErrorModal
          error={saleError}
          onClose={() => {
            setSaleError(null);
            focusBarcode();
          }}
          onBackToSale={() => {
            setSaleError(null);
            setRightMode("browse");
            focusBarcode();
          }}
        />

        <EnterpriseCashupModal
          open={enterpriseCashupOpen}
          onClose={() => setEnterpriseCashupOpen(false)}
          closeShiftDefault={false}
        />

        <EnterpriseCashupModal
          open={enterpriseEndShiftOpen}
          onClose={() => setEnterpriseEndShiftOpen(false)}
          closeShiftDefault
        />

        {shouldShowActionPanel && (
          <ActionPanel
            actionPanel={actionPanel}
            selectedLine={selectedLine}
            formatQty={formatQty}
            editQty={editQty}
            setEditQty={setEditQty}
            discountValue={discountValue}
            setDiscountValue={setDiscountValue}
            applyEditLine={applyEditLine}
            applyDiscount={applyDiscount}
            close={() => {
              setActionPanel(null);
              setPendingEditLineId(null);
              focusBarcode();
            }}
            totals={totals}
            shiftSummary={shiftSummary}
            loadingShiftSummary={loadingShiftSummary}
            cashupNotes={cashupNotes}
            setCashupNotes={setCashupNotes}
            cashupPayments={cashupPayments}
            endShiftPayments={endShiftPayments}
            endShiftNotes={endShiftNotes}
            setEndShiftNotes={setEndShiftNotes}
            updateCountedPayment={updateCountedPayment}
            savingCashup={savingCashup}
            submitCashup={submitCashup}
            submitEndShift={submitEndShift}
            openShiftSummary={openShiftSummary}
            openCashup={openCashup}
            openEndShift={openEndShift}
            openReportsSnapshot={openReportsSnapshot}
            reprintLastReceipt={reprintLastReceipt}
            openCustomerScreen={openCustomerScreen}
            openPaymentOptions={handleProcess}
            openCustomerAccount={() => {
              if (!canCaptureCreditCustomerPayment) {
                showNotice("Your role does not have permission to receive customer-account payments.");
                return;
              }
              setCustomerAccountModalOpen(true);
              setActionPanel(null);
            }}
            canCaptureCreditCustomerPayment={canCaptureCreditCustomerPayment}
            openManager={() => setActionPanel("manager")}
            openPriceOverridePanel={openPriceOverridePanel}
            priceOverrideConfig={priceOverrideConfig}
            priceOverrideRows={priceOverrideRows}
            priceOverrideLoading={priceOverrideLoading}
            applySelectedPriceLevel={applySelectedPriceLevel}
            openDiscountModal={openDiscountModal}
            openLoadQuoteModal={() => {
              setLoadQuoteModalOpen(true);
              setActionPanel(null);
            }}
            loadingQuotation={loadingQuotation}
            noSale={noSale}
            reportsLoading={reportsLoading}
            reportsSnapshot={reportsSnapshot}
            customer={customer}
            setCustomer={setCustomer}
          />
        )}
      </div>

      <PosCustomerAccountModal
        open={customerAccountModalOpen}
        onClose={() => {
          setCustomerAccountModalOpen(false);
          focusBarcode();
        }}
        currencies={currencies}
        paymentTypes={paymentTypes}
        onPaymentPosted={() => {
          loadAccountCustomers({ quiet: true });
          showNotice("Customer account payment posted and included in this shift's cashup.");
        }}
      />

      <ChangeCreditSuccessModal
        data={changeCreditReceipt}
        busy={changeCreditReceiptBusy}
        onPrint={() => deliverChangeCreditReceipt("open")}
        onDownload={() => deliverChangeCreditReceipt("download")}
        onClose={() => {
          setChangeCreditReceipt(null);
          focusBarcode();
        }}
      />

      <FastFoodLinePrepMessageModal
        open={prepMessageModalOpen}
        line={selectedLine}
        branchId={selectedLine?.rawProduct?.branch_id || ""}
        onClose={() => {
          setPrepMessageModalOpen(false);
          focusBarcode();
        }}
        onApply={applyPrepMessagesToSelectedLine}
      />

      <FastFoodLineModifierModal
        open={modifierModalOpen}
        line={selectedLine}
        branchId={selectedLine?.rawProduct?.branch_id || ""}
        saleCurrencyCode={saleCurrencyCode}
        convertMoney={convertMoney}
        onClose={() => {
          setModifierModalOpen(false);
          focusBarcode();
        }}
        onApply={applyModifiersToSelectedLine}
      />

      <FastFoodLineSplitModal
        open={splitLineModalOpen}
        line={selectedLine}
        onClose={() => {
          setSplitLineModalOpen(false);
          focusBarcode();
        }}
        onApply={applySplitToSelectedLine}
      />

      <DiscountApplyModal
        open={discountModalOpen}
        onClose={() => setDiscountModalOpen(false)}
        line={selectedLine}
        saleTotal={totals.subTotal}
        customerId={customer?.id || null}
        currency={totals.currency}
        onApplied={applyLineDiscountPayload}
      />

      <ManagerApprovalModal
        open={managerApprovalOpen}
        onClose={() => {
          setManagerApprovalOpen(false);
          setPendingPriceOverride(null);
        }}
        onApproved={handlePriceOverrideApproved}
        title={pendingPriceOverride?.title}
        description={pendingPriceOverride?.description}
        action={pendingPriceOverride?.action || "price_level_override"}
        permission={pendingPriceOverride?.permission || "approve_price_override"}
        reason={pendingPriceOverride?.reason}
        referenceType={pendingPriceOverride?.referenceType || "price_level_override"}
        referenceId={pendingPriceOverride?.referenceId || selectedLine?.productId}
        metadata={pendingPriceOverride?.metadata || {}}
      />

      <LoadQuotationModal
        open={loadQuoteModalOpen}
        value={quoteCode}
        loading={loadingQuotation}
        onChange={setQuoteCode}
        onClose={() => setLoadQuoteModalOpen(false)}
        onSubmit={(code) => loadQuotationIntoSale(code)}
      />


      <SplitPaymentModal
        open={splitPaymentModalOpen}
        onClose={() => setSplitPaymentModalOpen(false)}
        paymentRows={activePaymentRows}
        paymentTypes={paymentTypes}
        saleCurrencyCode={saleCurrencyCode}
        totals={totals}
        loadingPayments={loadingPayments}
        activePaymentRowId={activePaymentRowId}
        selectPaymentRow={selectPaymentRow}
        updatePaymentRowAmount={updatePaymentRowAmount}
        updatePaymentRowType={updatePaymentRowType}
        addPaymentRow={addPaymentRow}
        removePaymentRow={removePaymentRow}
        completeSale={completeSale}
        savingSale={savingSale}
        printingReceipt={printingReceipt}
        paymentTypeById={paymentTypeById}
      />

      {salesLayout === "visual" && (
        <TouchKeyboardOverlay
          settingKey="touch_keyboard_enabled"
          module="sales"
          title="Touch Keyboard"
        />
      )}
    </FullScreenModal>
  );
};



const ChangeCreditSuccessModal = ({ data, busy = "", onPrint, onDownload, onClose }) => {
  if (!data) return null;

  return (
    <div className="fixed inset-0 z-[115] flex items-center justify-center bg-[#003637]/70 p-4" role="presentation">
      <div className="sx-large-modal-window border border-[#00BCBC] bg-white text-[#1C2C2B] shadow-2xl" role="dialog" aria-modal="true" aria-label="Change credited successfully">
        <div className="flex shrink-0 items-center justify-between border-b border-[#00BCBC] bg-[#005A5A] px-4 py-3 text-white">
          <div>
            <div className="text-[14px] font-black uppercase tracking-[0.1em]">Change Credited Successfully</div>
            <div className="mt-0.5 text-[9px] font-semibold uppercase tracking-[0.14em] text-white/70">Ledger posted / cash and account split confirmed</div>
          </div>
          <button type="button" onClick={onClose} className="flex h-9 w-9 items-center justify-center border border-white/20 bg-white/10 text-[18px] hover:bg-white/20">×</button>
        </div>

        <div className="min-h-0 flex-1 overflow-y-auto bg-[#F3F6F8] p-3">
          <div className="border border-[#6CE9A6] bg-[#ECFDF3] p-3 text-[11px] font-semibold text-[#067647]">
            The customer chose account credit instead of physical change. The credited amount is a ledger payment, is included correctly in cashup, and does not create another sale or stock movement.
          </div>
          <div className="mt-3 grid grid-cols-2 gap-2 md:grid-cols-3">
            <ChangeCreditMetric label="Tendered" value={money(data.tendered, data.saleCurrency)} />
            <ChangeCreditMetric label="Change Returned" value={money(data.cashChangeReturned, data.saleCurrency)} />
            <ChangeCreditMetric label="Change Credited" value={money(data.creditedChange, data.saleCurrency)} good />
            <ChangeCreditMetric label="Account Value" value={money(data.accountAmount, data.accountCurrency)} good />
            <ChangeCreditMetric label="Resulting Balance" value={money(data.resultingBalance, data.accountCurrency)} />
            <ChangeCreditMetric label="Account Credit" value={money(data.resultingCredit, data.accountCurrency)} good={data.resultingCredit > 0} />
            <ChangeCreditMetric label="Receipt" value={data.receiptNumber || "-"} />
          </div>
          <div className="mt-3 border border-[#CFD9D8] bg-white p-3 text-[11px]">
            <div className="flex justify-between gap-4 border-b border-[#E4E8E8] py-1"><span className="text-[#627474]">Customer</span><strong className="text-right">{data.customerName || "-"}</strong></div>
            <div className="flex justify-between gap-4 border-b border-[#E4E8E8] py-1"><span className="text-[#627474]">Account</span><strong className="text-right">{data.accountNumber || "-"}</strong></div>
            <div className="flex justify-between gap-4 py-1"><span className="text-[#627474]">Payment / Reference</span><strong className="text-right">{data.paymentNumber || "-"}</strong></div>
          </div>
        </div>

        <div className="flex shrink-0 flex-wrap justify-end gap-2 border-t border-[#CFD9D8] bg-[#F8FAFC] p-3">
          {data.paymentId && <button type="button" onClick={onPrint} disabled={Boolean(busy)} className="flex h-10 items-center gap-1.5 border border-[#00883F] bg-white px-4 text-[10px] font-black uppercase text-[#067647] disabled:opacity-50"><Printer className="h-4 w-4" />{busy === `${data.paymentId}:open` ? "Opening..." : "Print 80mm"}</button>}
          {data.paymentId && <button type="button" onClick={onDownload} disabled={Boolean(busy)} className="flex h-10 items-center gap-1.5 border border-[#057B7B] bg-[#057B7B] px-4 text-[10px] font-black uppercase text-white disabled:opacity-50"><Download className="h-4 w-4" />{busy === `${data.paymentId}:download` ? "Downloading..." : "Download"}</button>}
          <button type="button" onClick={onClose} className="h-10 border border-[#00883F] bg-[#00A651] px-6 text-[11px] font-black uppercase text-white">Acknowledge & Continue</button>
        </div>
      </div>
    </div>
  );
};

const ChangeCreditMetric = ({ label, value, good = false }) => (
  <div className="border border-[#CFD9D8] bg-white p-2">
    <div className="text-[8px] font-black uppercase text-[#627474]">{label}</div>
    <div className={`mt-1 break-words text-[13px] font-black ${good ? "text-[#067647]" : "text-[#004141]"}`}>{value}</div>
  </div>
);

const SaleErrorModal = ({ error, onClose, onBackToSale }) => {
  if (!error) return null;

  const isFrontStock = error.type === "front_stock" || error.type === "stock";
  const isShiftFailure = error.type === "shift";
  const isShiftCutoff = error.type === "shift_cutoff";
  const guidance = isFrontStock
    ? "Fix this by receiving the missing FRONT stock ingredient or reducing the sale quantity. The sale has not been posted."
    : isShiftCutoff
    ? "Complete the cashier cash-up, or ask an authorised supervisor or Super Admin to cash up on the cashier's behalf. Signing in again will not bypass a genuinely ended shift. The sale has not been posted."
    : isShiftFailure
    ? "Sign out and log in to Front Office again to open or resume your assigned shift. If the shift still does not open, ask a supervisor to review the shift configuration. The sale has not been posted."
    : "Review the error details, correct the highlighted problem, and try the sale again. The sale has not been posted.";

  return (
    <div className="fixed inset-0 z-[109] flex items-center justify-center bg-[#003637]/55 p-3" role="presentation">
      <div className="sx-large-modal-window border border-[#B42318] bg-white text-[#1C2C2B] shadow-2xl" role="alertdialog" aria-modal="true" aria-label={error.title || "Sale failed"}>
        <div className="grid min-h-[52px] shrink-0 grid-cols-[1fr_auto] border-b-2 border-[#FDA29B] bg-[#7A1D16] text-white">
          <div className="flex min-w-0 items-center gap-3 px-3 py-2">
            <div className="flex h-9 w-9 shrink-0 items-center justify-center bg-[#B42318]">
              <AlertTriangle className="h-5 w-5" />
            </div>
            <div className="min-w-0">
              <div className="truncate text-[13px] font-black uppercase tracking-[0.1em]">
                {error.title || "Sale Failed"}
              </div>
              <div className="mt-[1px] truncate text-[9px] font-semibold uppercase tracking-[0.16em] text-white/70">
                POS selling validation / cashier action required
              </div>
            </div>
          </div>

          <button
            type="button"
            onClick={onClose}
            className="flex h-full w-[58px] items-center justify-center border-l border-white/20 bg-[#B42318] text-[20px] font-black text-white hover:bg-[#9E1F16]"
            title="Close"
          >
            ×
          </button>
        </div>

        <div className="min-h-0 flex-1 overflow-y-auto bg-[#FFF7F7] p-3">
          <div className="border border-[#FDA29B] bg-white p-3">
            <div className="text-[13px] font-black uppercase tracking-wide text-[#7A1D16]">
              {error.headline || "The sale could not be completed."}
            </div>

            {isFrontStock ? (
              <div className="mt-3 grid gap-2 text-[11px] md:grid-cols-3">
                <SaleErrorMetric label="Ingredient" value={error.ingredient || "-"} />
                <SaleErrorMetric label="Required" value={error.required || "-"} danger />
                <SaleErrorMetric label="Available" value={error.available || "-"} />
              </div>
            ) : null}

            {error.area ? (
              <div className="mt-2 border border-[#CFD6D6] bg-[#F8FAFC] px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-[#465757]">
                Stock Area: {error.area}
              </div>
            ) : null}

            {error.apiMessage && error.backendError && error.apiMessage !== error.backendError ? (
              <div className="mt-3 grid gap-2 text-[11px] md:grid-cols-2">
                <div className="border border-[#CFD6D6] bg-[#F8FAFC] p-2">
                  <div className="text-[9px] font-black uppercase tracking-[0.14em] text-[#627474]">
                    API Message
                  </div>
                  <div className="mt-1 whitespace-pre-line font-semibold leading-relaxed text-[#324443]">
                    {error.apiMessage}
                  </div>
                </div>
                <div className="border border-[#FEE4E2] bg-[#FEF3F2] p-2">
                  <div className="text-[9px] font-black uppercase tracking-[0.14em] text-[#B42318]">
                    Actual Backend Error
                  </div>
                  <div className="mt-1 whitespace-pre-line font-semibold leading-relaxed text-[#912018]">
                    {error.backendError}
                  </div>
                </div>
              </div>
            ) : (
              <div className="mt-3 whitespace-pre-line border border-[#FEE4E2] bg-[#FEF3F2] p-2 text-[11px] font-semibold leading-relaxed text-[#912018]">
                {error.message}
              </div>
            )}

            {(error.status || error.code) ? (
              <div className="mt-2 grid gap-2 text-[10px] md:grid-cols-2">
                {error.status ? <SaleErrorMetric label="HTTP Status" value={error.status} /> : null}
                {error.code ? <SaleErrorMetric label="Error Code" value={error.code} /> : null}
              </div>
            ) : null}
          </div>

          <div className="mt-2 border border-[#FEDF89] bg-[#FFFAEB] p-2 text-[10px] font-semibold leading-relaxed text-[#B54708]">
            {guidance}
          </div>
        </div>

        <div className="flex min-h-[48px] shrink-0 flex-wrap items-center justify-between gap-2 border-t border-[#FDA29B] bg-[#F8FAFC] px-3 py-2">
          <div className="text-[10px] font-semibold uppercase tracking-wide text-[#627474]">
            This message stays on screen until the cashier closes it.
          </div>
          <div className="flex gap-2">
            <button
              type="button"
              onClick={onBackToSale || onClose}
              className="h-9 border border-[#AAB5B5] bg-white px-4 text-[10px] font-black uppercase text-[#324443] hover:bg-[#EFF3F3]"
            >
              Back to Sale
            </button>
            <button
              type="button"
              onClick={onClose}
              className="h-9 border border-[#B42318] bg-[#B42318] px-5 text-[10px] font-black uppercase text-white hover:bg-[#9E1F16]"
            >
              Close Message
            </button>
          </div>
        </div>
      </div>
    </div>
  );
};

const SaleErrorMetric = ({ label, value, danger = false }) => (
  <div className="border border-[#CFD6D6] bg-[#F8FAFC] p-2">
    <div className="text-[9px] font-black uppercase tracking-[0.14em] text-[#627474]">
      {label}
    </div>
    <div className={`mt-1 truncate text-[13px] font-black ${danger ? "text-[#B42318]" : "text-[#004141]"}`}>
      {value || "-"}
    </div>
  </div>
);

const LoadQuotationModal = ({
  open,
  value,
  loading,
  onChange,
  onClose,
  onSubmit,
}) => {
  const [localValue, setLocalValue] = useState(value || "");
  const inputRef = useRef(null);

  useEffect(() => {
    if (open) {
      setLocalValue(value || "");
      window.setTimeout(() => {
        inputRef.current?.focus();
        inputRef.current?.select();
      }, 50);
    }
  }, [open, value]);

  if (!open) return null;

  const submit = (event) => {
    event.preventDefault();

    const code = String(localValue || "").trim().toUpperCase();
    if (!code) return;
    onChange?.(code);
    onSubmit?.(code);
  };

  const appendCode = (next) => {
    setLocalValue((prev) => `${prev || ""}${next}`.toUpperCase());
    inputRef.current?.focus();
  };

  const backspaceCode = () => {
    setLocalValue((prev) => String(prev || "").slice(0, -1).toUpperCase());
    inputRef.current?.focus();
  };

  const clearCode = () => {
    setLocalValue("");
    inputRef.current?.focus();
  };

  return (
    <div className="absolute inset-0 z-[96] flex items-center justify-center bg-[#003637]/45 p-4">
      <form
        onSubmit={submit}
        className="sx-large-modal-window sx-large-modal-scroll border border-[#AAB5B5] bg-white text-[#1C2C2B] shadow-2xl"
      >
        <div className="flex h-11 items-center justify-between border-b border-[#00BCBC] bg-[#004142] px-3 text-white">
          <div>
            <div className="text-[13px] font-black uppercase">Load Quotation</div>
            <div className="text-[10px] font-semibold uppercase text-white/70">
              Type quotation code, then press Enter
            </div>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="flex h-8 w-8 items-center justify-center border border-white/20 bg-white/10 text-[18px] font-black hover:bg-white/20"
          >
            ×
          </button>
        </div>

        <div className="grid gap-3 p-3 md:grid-cols-[210px_1fr]">
          <aside className="border border-[#CFD9D8] bg-[#F5F7FA] p-3 text-[11px]">
            <div className="border-b border-[#CFD9D8] pb-2 text-[10px] font-black uppercase text-[#627474]">
              Quote Loader
            </div>
            <div className="mt-2 space-y-2">
              <QtySummaryLine label="Current" value={value || "-"} />
              <QtySummaryLine label="Mode" value="Keyboard + Keypad" />
              <QtySummaryLine label="Status" value={loading ? "Loading" : "Ready"} />
            </div>
            <div className="mt-3 border border-[#FEDF89] bg-[#FFFAEB] p-2 text-[10px] font-semibold text-[#B54708]">
              The loaded quote will bring products, quantities, prices and discounts into the sale.
            </div>
          </aside>

          <main className="min-w-0">
            <label className="block">
              <div className="mb-1 text-[10px] font-black uppercase text-[#627474]">
                Quotation Code
              </div>
              <input
                ref={inputRef}
                type="text"
                value={localValue}
                onChange={(event) => setLocalValue(event.target.value.toUpperCase())}
                onKeyDown={(event) => {
                  if (event.key === "Escape") {
                    event.preventDefault();
                    onClose?.();
                  }
                }}
                placeholder="Example: QT-KM-260520-0001"
                className="h-14 w-full border border-[#AAB5B5] bg-white px-3 text-right text-[24px] font-black uppercase text-[#004141] outline-none focus:border-[#069495]"
              />
            </label>

            <div className="mt-2 grid grid-cols-4 gap-2">
              {["7", "8", "9"].map((key) => <QtyKeyButton key={key} onClick={() => appendCode(key)}>{key}</QtyKeyButton>)}
              <QtyKeyButton variant="muted" onClick={backspaceCode}>⌫</QtyKeyButton>
              {["4", "5", "6"].map((key) => <QtyKeyButton key={key} onClick={() => appendCode(key)}>{key}</QtyKeyButton>)}
              <QtyKeyButton variant="muted" onClick={clearCode}>Clear</QtyKeyButton>
              {["1", "2", "3"].map((key) => <QtyKeyButton key={key} onClick={() => appendCode(key)}>{key}</QtyKeyButton>)}
              <QtyKeyButton variant="navy" onClick={() => appendCode("QT-")}>QT-</QtyKeyButton>
              <QtyKeyButton onClick={() => appendCode("0")}>0</QtyKeyButton>
              <QtyKeyButton onClick={() => appendCode("-")}>-</QtyKeyButton>
              <QtyKeyButton onClick={() => appendCode("/")}>/</QtyKeyButton>
              <QtyKeyButton variant="navy" onClick={() => inputRef.current?.focus()}>ABC</QtyKeyButton>
            </div>
          </main>
        </div>

        <div className="flex h-12 items-center justify-between border-t border-[#CFD9D8] bg-[#F5F7FA] px-3">
          <div className="text-[10px] font-semibold uppercase text-[#627474]">
            Keyboard supported: type quote code, Enter loads, Escape closes.
          </div>
          <div className="flex gap-2">
            <button type="button" onClick={onClose} className="h-8 border border-[#AAB5B5] bg-white px-4 text-[10px] font-black uppercase text-[#324443]">Cancel</button>
            <button type="submit" disabled={loading || !String(localValue || "").trim()} className="h-8 border border-[#069495] bg-[#069495] px-5 text-[10px] font-black uppercase text-white disabled:opacity-60">
              {loading ? "Loading..." : "Load Quote"}
            </button>
          </div>
        </div>
      </form>
    </div>
  );
};

const CategoryGrid = ({
  categoryOptions,
  selectedCategoryId,
  products,
  loadingCategories,
  handleCategorySelect,
  loadSaleData,
}) => {
  return (
    <div className="flex h-full min-h-0 flex-col">
      <div className="flex h-8 shrink-0 items-center justify-between border-b border-[#ACB8B8] bg-[#F5F7FA] px-3">
        <div className="text-[12px] font-black">Select Category</div>

        <div className="flex items-center gap-2">
          <div className="text-[10px] text-[#637877]">
            {loadingCategories
              ? "Loading..."
              : `${categoryOptions.length} categories`}
          </div>

          <button
            type="button"
            onClick={loadSaleData}
            className="h-6 border border-[#ACB8B8] bg-white px-2 text-[9px]"
          >
            <RefreshCw className="h-3 w-3" />
          </button>
        </div>
      </div>

      <div className="min-h-0 flex-1 overflow-y-auto p-1">
        <div className="grid grid-cols-3 gap-1 md:grid-cols-4 xl:grid-cols-5 2xl:grid-cols-6">
          {categoryOptions.map((category) => {
            const categoryCount =
              category.id === "all"
                ? products.length
                : products.filter(
                    (product) => product.category_id === category.id
                  ).length;

            const active = selectedCategoryId === category.id;

            return (
              <button
                key={category.id}
                type="button"
                onClick={() => handleCategorySelect(category)}
                className={`min-h-[48px] border px-2 py-1 text-left transition hover:bg-white ${
                  active
                    ? "border-[#00BCBC] bg-[#E8F7F5]"
                    : "border-[#CAD5D5] bg-[#F8FAFC] hover:bg-white"
                }`}
              >
                <div className="truncate text-[12px] font-black uppercase text-[#003636]">
                  {category.name}
                </div>

                <div className="mt-1 text-[17px] font-black leading-none text-[#069495]">
                  {categoryCount}
                </div>

                <div className="mt-[2px] text-[8px] uppercase text-[#637877]">
                  Product{categoryCount === 1 ? "" : "s"}
                </div>
              </button>
            );
          })}
        </div>
      </div>
    </div>
  );
};

const ProductTable = ({
  loadingProducts,
  filteredProducts,
  selectedCategory,
  selectedCategoryId,
  setSelectedCategoryId,
  setBrowseTab,
  focusBarcode,
  handleProductClick,
  saleCurrencyCode,
  convertMoney,
}) => {
  const [previewPrices, setPreviewPrices] = useState({});

  useEffect(() => {
    let mounted = true;

    const loadPreviewPrices = async () => {
      if (!saleCurrencyCode) return;

      const next = {};

      for (const product of filteredProducts.slice(0, 150)) {
        const price = getStandardPrice(product);
        if (!price) continue;

        const fromCurrency = price.currency || saleCurrencyCode;

        try {
          next[product.id] = await convertMoney(
            fromCurrency,
            saleCurrencyCode,
            price.price
          );
        } catch {
          next[product.id] = Number(price.price || 0);
        }
      }

      if (mounted) setPreviewPrices(next);
    };

    loadPreviewPrices();

    return () => {
      mounted = false;
    };
  }, [filteredProducts, saleCurrencyCode, convertMoney]);

  return (
    <div className="flex h-full min-h-0 flex-col">
      <div className="flex h-8 shrink-0 items-center justify-between border-b border-[#ACB8B8] bg-[#F5F7FA] px-3">
        <div className="text-[10px] font-black">Products</div>

        <div className="flex items-center gap-2">
          <div className="text-[10px] text-[#637877]">
            {loadingProducts
              ? "Loading..."
              : `${filteredProducts.length} items / ${
                  selectedCategory?.name || "All"
                }`}
          </div>

          {selectedCategoryId !== "all" && (
            <button
              type="button"
              onClick={() => {
                setSelectedCategoryId("all");
                setBrowseTab("categories");
                focusBarcode();
              }}
              className="h-6 border border-[#ACB8B8] bg-white px-2 text-[9px] text-[#003636]"
            >
              Clear
            </button>
          )}
        </div>
      </div>

      <div className="min-h-0 flex-1 overflow-auto">
        {loadingProducts ? (
          <div className="flex h-full flex-col items-center justify-center text-[#637877]">
            <RefreshCw className="mb-2 h-8 w-8 animate-spin" />
            <div className="text-[12px]">Loading products...</div>
          </div>
        ) : filteredProducts.length === 0 ? (
          <div className="flex h-full flex-col items-center justify-center text-[#637877]">
            <PackageSearch className="mb-2 h-8 w-8" />
            <div className="text-[12px]">No products found.</div>
          </div>
        ) : (
          <table className="w-full border-collapse">
            <thead className="sticky top-0 z-10">
              <tr className="border-b border-[#D7DFDE] bg-[#F3F6F8]">
                <th className="px-2 py-2 text-left text-[12px] font-black uppercase">
                  Product
                </th>
                <th className="w-[90px] px-2 py-2 text-left text-[12px] font-black uppercase">
                  SKU
                </th>
                <th className="w-[100px] px-2 py-2 text-left text-[12px] font-black uppercase">
                  Category
                </th>
                <th className="w-[80px] px-2 py-2 text-right text-[12px] font-black uppercase">
                  Stock
                </th>
                <th className="w-[115px] px-2 py-2 text-right text-[12px] font-black uppercase">
                  Selling
                </th>
              </tr>
            </thead>

            <tbody>
              {filteredProducts.map((product) => {
                const standardPrice = getStandardPrice(product);
                const decimalQty = isDecimalProduct(product);
                const decimalPlaces = getProductDecimalPlaces(product);
                const unit = getProductUnit(product);
                const availableStock = getProductAvailableStock(product);
                const shownPrice =
                  previewPrices[product.id] ?? Number(standardPrice?.price || 0);
                const productTaxes = getProductTaxesForSale(product);
                const taxPreview = calculateTaxBreakdown({
                  price: shownPrice,
                  qty: 1,
                  discount: 0,
                  taxes: productTaxes,
                });
                const taxSummary = getProductTaxSummary(product);

                return (
                  <tr
                    key={product.id}
                    onClick={() => handleProductClick(product)}
                    className="cursor-pointer border-b border-[#EFF2F2] hover:bg-[#E8F7F5]"
                  >
                    <td className="px-2 py-2">
                      <div className="text-[13px] font-black">
                        {product.name}
                      </div>

                      <div className="mt-[2px] text-[10px] text-[#637877]">
                        {product.barcode || product.code || "-"}
                      </div>

                      {decimalQty && (
                        <div className="mt-[2px] text-[8px] uppercase text-[#069495]">
                          Decimal Qty / {unit}
                        </div>
                      )}
                    </td>

                    <td className="px-2 py-2 text-[12px]">
                      {product.sku || "-"}
                    </td>

                    <td className="px-2 py-2 text-[12px]">
                      {product.category?.name || "Uncategorised"}
                    </td>

                    <td className="px-2 py-2 text-right text-[12px]">
                      {decimalQty
                        ? Number(availableStock).toFixed(decimalPlaces)
                        : Number(availableStock).toFixed(0)}
                    </td>

                    <td className="px-2 py-2 text-right text-[13px] font-black text-[#069495]">
                      {money(shownPrice, saleCurrencyCode)}
                      {decimalQty ? ` / ${unit}` : ""}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        )}
      </div>
    </div>
  );
};

export const PaymentPanel = ({
  totals,
  amountReceived,
  paymentRows = [],
  activePaymentRowId,
  activePaymentAmount,
  selectPaymentRow,
  updatePaymentRowAmount,
  updatePaymentRowType,
  addPaymentRow,
  removePaymentRow,
  openSplitPaymentModal,
  paymentTypes,
  selectedPaymentTypeId,
  setSelectedPaymentTypeId,
  selectedPaymentType,
  currencies,
  saleCurrencyCode,
  handleSaleCurrencyChange,
  paymentCanChooseCurrency,
  amountTenderedRequired,
  creditCustomers = [],
  selectedCreditCustomerId,
  selectedCreditCustomer,
  handleCreditCustomerChange,
  creditPoNumber,
  setCreditPoNumber,
  isCreditSale,
  creditAvailable,
  creditLimitExceeded,
  loadingCreditCustomers,
  accountCustomers = [],
  loadingAccountCustomers,
  canCaptureCreditCustomerPayment,
  postChangeToCreditAccount,
  changeCreditCustomerId,
  setChangeCreditCustomerId,
  handlePostChangeToggle,
  loadingPayments,
  loadingCurrencies,
  appendPaymentValue,
  backspacePaymentValue,
  clearPaymentValue,
  exactPayment,
  quickPayment,
  completeSale,
  saleComplete,
  clearSale,
  savingSale,
  printingReceipt,
  lastSaleResponse,
  onBack,
}) => {
  const selectedChangeAccount = accountCustomers.find(
    (row) => String(row?.id) === String(changeCreditCustomerId)
  ) || null;
  const changeAccountStatus = normalizeCode(selectedChangeAccount?.status || selectedChangeAccount?.account_status);
  const changeAccountIsSettlementOnly = ["blocked", "suspended"].includes(changeAccountStatus);
  const canComplete = !loadingAccountCustomers && (
    isCreditSale
      ? totals.total > 0 && Boolean(selectedCreditCustomerId) && !creditLimitExceeded
      : amountTenderedRequired
      ? totals.received >= totals.total && (!postChangeToCreditAccount || Boolean(changeCreditCustomerId))
      : totals.total > 0
  );
  const completionLabel = isCreditSale
    ? "Complete Credit Sale"
    : postChangeToCreditAccount
    ? changeAccountIsSettlementOnly
      ? "Complete · Settle Account"
      : `Complete · Credit ${money(totals.change, totals.currency)}`
    : Number(totals.change || 0) > 0.0001
    ? `Complete · Return ${money(totals.change, totals.currency)}`
    : "Complete Sale";

  return (
    <div
      data-testid="payment-finalisation"
      className="grid h-full min-h-0 grid-rows-[64px_minmax(0,1fr)] overflow-hidden bg-[#EFF3F3]"
    >
      <header className="grid min-w-0 grid-cols-[minmax(0,1fr)_190px] gap-2 border-b border-[#ACB8B8] bg-white px-2 py-1.5 shadow-sm">
        <div className="flex min-w-0 items-center gap-2">
          <div className="w-[112px] shrink-0 border-r border-[#CFD9D8] pr-2">
            <div className="text-[11px] font-black uppercase tracking-[0.08em] text-[#004141]">
              Finalise Sale
            </div>
            <div className="mt-0.5 text-[9px] font-semibold text-[#627474]">
              Choose tender
            </div>
          </div>

          <div
            className="flex min-w-0 flex-1 gap-1.5 overflow-x-auto pb-0.5"
            aria-label="Payment methods"
          >
            {loadingPayments ? (
              <div className="flex h-11 min-w-[180px] items-center justify-center rounded-md border border-[#CAD5D5] bg-[#F8FAFC] px-4 text-[10px] font-semibold text-[#627474]">
                Loading payment types...
              </div>
            ) : (
              paymentTypes.map((method) => {
                const Icon = getPaymentIcon(method);
                const active = selectedPaymentTypeId === method.id;

                return (
                  <button
                    key={method.id}
                    type="button"
                    aria-pressed={active}
                    onClick={() => setSelectedPaymentTypeId(method.id)}
                    className={`pos-payment-method flex h-11 min-w-[112px] shrink-0 items-center justify-center gap-1.5 rounded-md border px-3 text-[10px] font-black uppercase shadow-sm transition-colors focus:outline-none focus:ring-2 focus:ring-[#0AA7A7] ${
                      active
                        ? "border-[#004141] bg-[#004142] text-white"
                        : "border-[#CAD5D5] bg-[#F7F9FB] text-[#004141] hover:border-[#809393] hover:bg-[#ECF2F2]"
                    }`}
                  >
                    <Icon className="h-4 w-4 shrink-0" />
                    <span className="truncate">{method.name}</span>
                  </button>
                );
              })
            )}
          </div>
        </div>

        <label className="rounded-md border border-[#ACB8B8] bg-[#F8FAFC] px-2 py-1">
          <span className="block text-[8px] font-black uppercase tracking-wide text-[#637877]">
            Sale Currency
          </span>
          <select
            value={saleCurrencyCode}
            disabled={!paymentCanChooseCurrency || loadingCurrencies}
            onChange={(event) => handleSaleCurrencyChange(event.target.value)}
            className="mt-0.5 h-7 w-full rounded border border-[#ACB8B8] bg-white px-2 text-[11px] font-black text-[#004141] outline-none focus:border-[#069495] disabled:bg-[#E4E8E8]"
          >
            {!paymentCanChooseCurrency && (
              <option value={saleCurrencyCode}>
                {saleCurrencyCode || "Payment Default"}
              </option>
            )}
            {paymentCanChooseCurrency &&
              currencies.map((currency) => {
                const code = getCurrencyCode(currency);
                return (
                  <option key={currency.id || code} value={code}>
                    {code} - {getCurrencyName(currency)}
                  </option>
                );
              })}
          </select>
        </label>
      </header>

      <div data-testid="payment-workspace" className="grid min-h-0 grid-cols-[minmax(240px,30%)_minmax(0,1fr)] gap-2 overflow-hidden p-2">
        <aside
          data-testid="payment-summary-rail"
          className="min-h-0 space-y-2 overflow-y-auto rounded-lg border border-[#BAC8C8] bg-[#F8FAFC] p-2 shadow-sm"
        >
          <div className="pos-payment-total-card rounded-lg border border-[#003636] bg-[#004142] p-3 text-white shadow-sm">
            <div className="text-[10px] uppercase text-white/70">
              {isCreditSale ? "Amount to Account" : "Amount Due"}
            </div>

            <div className="mt-1 break-words text-[30px] font-black leading-none">
              {money(totals.total, totals.currency)}
            </div>
          </div>

          <div className="grid grid-cols-2 gap-2">
            <Metric
              label={amountTenderedRequired ? "Received" : "Auto Paid"}
              value={money(totals.received, totals.currency)}
            />

            <Metric
              label={postChangeToCreditAccount ? "Change Allocation" : "Change"}
              value={postChangeToCreditAccount && changeAccountIsSettlementOnly ? "Finalised on completion" : money(totals.change, totals.currency)}
              green
            />
          </div>

          <div className="rounded-lg border border-[#ACB8B8] bg-white p-3 shadow-sm">
            <div className="text-[8px] uppercase text-[#637877]">
              Balance
            </div>

            <div
              className={`text-[26px] font-black ${
                totals.balance > 0 ? "text-[#E30613]" : "text-[#00A651]"
              }`}
            >
              {money(totals.balance, totals.currency)}
            </div>
          </div>

          {paymentRows.length > 1 && !isCreditSale && (
            <div className="rounded-lg border border-[#0AA7A7] bg-[#E8F7F5] p-3 text-[#005A5A] shadow-sm">
              <div className="text-[9px] font-black uppercase tracking-wide">Split payment active</div>
              <div className="mt-1 text-[10px] font-semibold">
                {paymentRows.length} methods · Paid {money(totals.received, totals.currency)} · Balance {money(totals.balance, totals.currency)}
              </div>
            </div>
          )}

          {isCreditSale && (
            <section
              data-testid="credit-sale-account-card"
              className="rounded-lg border border-[#D9A400] bg-[#FFF9E8] p-3 text-[#5E4300] shadow-sm"
            >
              <div className="flex items-start justify-between gap-2">
                <div>
                  <div className="text-[10px] font-black uppercase tracking-wide">Customer Credit Sale</div>
                  <div className="mt-1 text-[10px] font-semibold leading-snug">
                    Select the account that will receive this bill. Stock is reduced only once when the sale completes.
                  </div>
                </div>
                <span className="shrink-0 rounded-full border border-[#D9A400] bg-white px-2 py-1 text-[8px] font-black uppercase">
                  Account sale
                </span>
              </div>

              <label className="mt-3 block">
                <span className="mb-1 block text-[8px] font-black uppercase tracking-wide">
                  Approved customer
                </span>
                <select
                  value={selectedCreditCustomerId || ""}
                  onChange={(event) => handleCreditCustomerChange?.(event.target.value)}
                  disabled={loadingCreditCustomers}
                  className="h-10 w-full rounded-md border border-[#D9A400] bg-white px-2 text-[10px] font-black text-[#324443] outline-none focus:border-[#069495] disabled:opacity-60"
                >
                  <option value="">{loadingCreditCustomers ? "Loading customers..." : "Select credit customer"}</option>
                  {creditCustomers.map((customer) => {
                    const account = getCreditCustomerAccount(customer);
                    const currency = getCreditCustomerCurrency(customer) || totals.currency;
                    return (
                      <option key={customer.id} value={customer.id}>
                        {account ? `${account} - ` : ""}{getCreditCustomerName(customer)} / {currency}
                      </option>
                    );
                  })}
                </select>
              </label>

              <label className="mt-2 block">
                <span className="mb-1 block text-[8px] font-black uppercase tracking-wide">PO / Reference</span>
                <input
                  type="text"
                  value={creditPoNumber || ""}
                  onChange={(event) => setCreditPoNumber?.(event.target.value.toUpperCase())}
                  className="h-9 w-full rounded-md border border-[#D9A400] bg-white px-2 text-[10px] font-black uppercase text-[#324443] outline-none focus:border-[#069495]"
                  placeholder="Optional reference"
                />
              </label>

              {selectedCreditCustomer ? (
                <div className="mt-3 grid grid-cols-2 gap-x-3 gap-y-1 rounded-md border border-[#E7C75D] bg-white p-2 text-[9px] text-[#627474]">
                  <span>Limit</span><strong className="text-right text-[#324443]">{money(getCreditCustomerLimit(selectedCreditCustomer), totals.currency)}</strong>
                  <span>Balance</span><strong className="text-right text-[#324443]">{money(getCreditCustomerBalance(selectedCreditCustomer), totals.currency)}</strong>
                  <span>Available</span><strong className={`text-right ${creditLimitExceeded ? "text-[#B42318]" : "text-[#057B7C]"}`}>{money(creditAvailable, totals.currency)}</strong>
                </div>
              ) : (
                <div className="mt-2 text-[9px] font-semibold text-[#B54708]">Select a customer to validate the credit limit.</div>
              )}

              {creditLimitExceeded && (
                <div className="mt-2 rounded-md border border-[#FDA29B] bg-[#FEF3F2] p-2 text-[9px] font-black uppercase text-[#B42318]">
                  Credit limit exceeded. Choose another customer or reduce the sale.
                </div>
              )}
            </section>
          )}

          {!isCreditSale && amountTenderedRequired && Number(totals.change || 0) > 0.0001 && (
            <section
              data-testid="change-allocation-card"
              className={`rounded-lg border p-3 shadow-sm ${
                postChangeToCreditAccount
                  ? "border-[#069495] bg-[#ECFDF8]"
                  : "border-[#ACB8B8] bg-white"
              }`}
            >
              <label className="flex cursor-pointer items-start gap-2">
                <input
                  type="checkbox"
                  checked={Boolean(postChangeToCreditAccount)}
                  onChange={(event) => handlePostChangeToggle?.(event.target.checked)}
                  disabled={!canCaptureCreditCustomerPayment || loadingAccountCustomers}
                  className="mt-0.5 h-5 w-5 shrink-0 accent-[#069495] disabled:cursor-not-allowed"
                />
                <span className="min-w-0">
                  <span className="block text-[10px] font-black uppercase tracking-wide text-[#005A5A]">
                    Credit change to account
                  </span>
                  <span className="mt-0.5 block text-[9px] font-semibold leading-snug text-[#627474]">
                    Turn this on only when the customer agrees. The payment pad remains available while you choose the account.
                  </span>
                </span>
              </label>

              {postChangeToCreditAccount && (
                <div className="mt-3 border-t border-[#A4E2E1] pt-3">
                  <label className="block">
                    <span className="mb-1 block text-[8px] font-black uppercase tracking-wide text-[#005A5A]">
                      Customer account receiving change
                    </span>
                    <select
                      value={changeCreditCustomerId || ""}
                      onChange={(event) => setChangeCreditCustomerId?.(event.target.value)}
                      disabled={loadingAccountCustomers}
                      className="h-10 w-full rounded-md border border-[#069495] bg-white px-2 text-[10px] font-black text-[#324443] outline-none focus:ring-2 focus:ring-[#9DE2E1] disabled:bg-[#E9EDED]"
                    >
                      <option value="">{loadingAccountCustomers ? "Loading accounts..." : "Select customer account"}</option>
                      {accountCustomers.map((row) => (
                        <option key={row.id} value={row.id}>
                          {getCreditCustomerAccount(row)} - {getCreditCustomerName(row)} / {getCreditCustomerCurrency(row) || totals.currency}
                        </option>
                      ))}
                    </select>
                  </label>

                  <div className="mt-2 grid grid-cols-2 gap-2 text-[9px]">
                    <div className="rounded-md border border-[#CFD6D6] bg-white p-2">
                      <span className="block uppercase text-[#627474]">Cash returned</span>
                      <strong className="mt-1 block text-[10px] text-[#004141]">
                        {changeAccountIsSettlementOnly ? "Finalised after debt" : money(0, totals.currency)}
                      </strong>
                    </div>
                    <div className="rounded-md border border-[#81D7D6] bg-white p-2">
                      <span className="block uppercase text-[#627474]">Account credit</span>
                      <strong className="mt-1 block text-[10px] text-[#057B7C]">
                        {changeAccountIsSettlementOnly ? "Debt only" : money(totals.change, totals.currency)}
                      </strong>
                    </div>
                  </div>

                  {selectedChangeAccount && (
                    <div className="mt-2 flex items-center justify-between gap-2 rounded-md bg-[#DFF7F1] px-2 py-1.5 text-[9px] text-[#005A5A]">
                      <span className="truncate">{getCreditCustomerName(selectedChangeAccount)}</span>
                      <strong className="shrink-0 uppercase">{changeAccountStatus || "active"}</strong>
                    </div>
                  )}
                </div>
              )}

              {!canCaptureCreditCustomerPayment && (
                <div className="mt-2 rounded-md border border-[#FDA29B] bg-[#FEF3F2] p-2 text-[9px] font-semibold text-[#B42318]">
                  Capture Credit Customer Payment permission is required.
                </div>
              )}
            </section>
          )}

          {saleComplete ? (
            <div className="border border-[#00A651] bg-[#E9FBEF] p-4">
              <div className="text-[18px] font-black text-[#007A3D]">
                Sale Complete
              </div>

              <div className="mt-1 text-[12px] text-[#324444]">
                Receipt:{" "}
                {lastSaleResponse?.receipt_number ||
                  lastSaleResponse?.sale?.invoice_number ||
                  "-"}
              </div>

              <div className="mt-1 text-[12px] text-[#324444]">
                Change: {money(totals.change, totals.currency)}
              </div>

              {lastSaleResponse?.print?.auto_print && (
                <div className="mt-2 border border-[#00BCBC] bg-white p-2 text-[10px] uppercase text-[#005A5A]">
                  {printingReceipt
                    ? "Printing receipt..."
                    : `Printer: ${
                        lastSaleResponse?.print?.printer?.printer_name || "-"
                      }`}
                </div>
              )}

              <button
                type="button"
                onClick={clearSale}
                className="mt-4 h-11 w-full border border-[#003636] bg-[#004142] text-[12px] font-black uppercase text-white"
              >
                New Sale
              </button>
            </div>
          ) : (
            <div className="min-w-0 border border-[#ACB8B8] bg-white p-2">
              <div className="grid grid-cols-[minmax(0,1fr)_minmax(120px,auto)] gap-x-2 gap-y-1 text-[11px]">
                <div>Subtotal</div>
                <div className="min-w-0 break-words text-right font-black leading-tight">
                  {money(totals.subTotal, totals.currency)}
                </div>

                <div>Discount</div>
                <div className="min-w-0 break-words text-right font-black leading-tight text-[#069494]">
                  -{money(totals.discount, totals.currency)}
                </div>

                <div>Tax</div>
                <div className="min-w-0 break-words text-right font-black leading-tight">
                  {money(totals.tax, totals.currency)}
                </div>

                <div className="mt-2 text-[14px] font-black">Total</div>
                <div className="mt-2 min-w-0 break-words text-right text-[13px] font-black leading-tight">
                  {money(totals.total, totals.currency)}
                </div>
              </div>
            </div>
          )}
        </aside>

        <section
          data-testid="payment-keypad"
          className="grid min-h-0 grid-rows-[58px_minmax(0,1fr)_58px_auto] overflow-hidden rounded-lg border border-[#ACB8B8] bg-white shadow-sm"
        >
          <div className="grid min-w-0 grid-cols-[110px_minmax(0,1fr)_104px] border-b border-[#ACB8B8] bg-[#F8FAFC]">
            <div className="flex flex-col justify-center border-r border-[#CFD9D8] px-3">
              <span className="text-[8px] font-black uppercase tracking-wide text-[#627474]">
                {isCreditSale ? "To account" : amountTenderedRequired ? "Tendered" : "Auto paid"}
              </span>
              <span className="mt-0.5 truncate text-[9px] font-semibold text-[#324443]">
                {selectedPaymentType?.name || "Payment"}
              </span>
            </div>
            <input
              data-payment-input="true"
              data-touch-keyboard="off"
              inputMode="decimal"
              aria-label="Amount tendered"
              type="text"
              value={
                isCreditSale
                  ? "0.00"
                  : amountTenderedRequired
                  ? activePaymentAmount || amountReceived || ""
                  : totals.total.toFixed(2)
              }
              disabled={isCreditSale || !amountTenderedRequired}
              onChange={(event) => quickPayment(event.target.value)}
              onFocus={(event) => event.target.select()}
              placeholder="0.00"
              className="h-full min-w-0 bg-white px-3 text-right text-[26px] font-black text-[#004141] outline-none focus:bg-[#F5FFFD] disabled:bg-[#E9EDED]"
            />

            <button
              type="button"
              onClick={exactPayment}
              disabled={isCreditSale || !amountTenderedRequired}
              className="border-l border-[#057B7C] bg-[#069495] text-[11px] font-black uppercase text-white transition-colors hover:bg-[#067C7C] disabled:opacity-50"
            >
              Exact
            </button>
          </div>

          <div
            data-testid="payment-keypad-grid"
            className="grid min-h-0 grid-cols-4 grid-rows-[repeat(5,minmax(42px,1fr))] gap-2 bg-[#F5F7FA] p-2"
          >
            <KeyButton
              onClick={openSplitPaymentModal}
              variant="navy"
              disabled={isCreditSale || loadingPayments || saleComplete || savingSale || printingReceipt}
            >
              <span className="flex flex-col items-center justify-center leading-tight">
                <GitBranch className="mb-[2px] h-4 w-4" />
                <span className="text-[9px] uppercase">Split</span>
              </span>
            </KeyButton>

            <KeyButton
              onClick={() => quickPayment("10")}
              variant="navy"
              disabled={isCreditSale || !amountTenderedRequired}
            >
              10
            </KeyButton>

            <KeyButton
              onClick={() => quickPayment("20")}
              variant="navy"
              disabled={isCreditSale || !amountTenderedRequired}
            >
              20
            </KeyButton>

            <KeyButton
              onClick={clearPaymentValue}
              variant="muted"
              disabled={isCreditSale || !amountTenderedRequired}
            >
              Clear
            </KeyButton>

            {["7", "8", "9"].map((key) => (
              <KeyButton
                key={key}
                onClick={() => appendPaymentValue(key)}
                large
                disabled={isCreditSale || !amountTenderedRequired}
              >
                {key}
              </KeyButton>
            ))}

            <KeyButton
              onClick={() => quickPayment("50")}
              variant="navy"
              disabled={isCreditSale || !amountTenderedRequired}
            >
              50
            </KeyButton>

            {["4", "5", "6"].map((key) => (
              <KeyButton
                key={key}
                onClick={() => appendPaymentValue(key)}
                large
                disabled={isCreditSale || !amountTenderedRequired}
              >
                {key}
              </KeyButton>
            ))}

            <KeyButton
              onClick={() => quickPayment("100")}
              variant="navy"
              disabled={isCreditSale || !amountTenderedRequired}
            >
              100
            </KeyButton>

            {["1", "2", "3"].map((key) => (
              <KeyButton
                key={key}
                onClick={() => appendPaymentValue(key)}
                large
                disabled={isCreditSale || !amountTenderedRequired}
              >
                {key}
              </KeyButton>
            ))}

            <KeyButton
              onClick={backspacePaymentValue}
              variant="muted"
              disabled={isCreditSale || !amountTenderedRequired}
            >
              <Delete className="mx-auto h-5 w-5" />
            </KeyButton>

            <KeyButton
              onClick={() => appendPaymentValue("00")}
              large
              disabled={isCreditSale || !amountTenderedRequired}
            >
              00
            </KeyButton>

            <KeyButton
              onClick={() => appendPaymentValue("0")}
              large
              disabled={isCreditSale || !amountTenderedRequired}
            >
              0
            </KeyButton>

            <KeyButton
              onClick={() => appendPaymentValue(".")}
              large
              disabled={isCreditSale || !amountTenderedRequired}
            >
              .
            </KeyButton>

            <KeyButton onClick={onBack} variant="danger">
              Back
            </KeyButton>
          </div>

          <button
            type="button"
            disabled={!canComplete || saleComplete || savingSale || printingReceipt}
            onClick={completeSale}
            className="m-2 mt-0 rounded-md border border-[#00883F] bg-[#00A651] text-[16px] font-black uppercase tracking-wide text-white shadow-sm transition-colors hover:bg-[#008F46] disabled:cursor-not-allowed disabled:opacity-50"
          >
            {savingSale
              ? "Saving Sale..."
              : printingReceipt
              ? "Printing..."
              : completionLabel}
          </button>

          <div className="border-t border-[#CFD9D8] bg-[#F8FAFC] px-3 py-1.5 text-[9px] font-semibold leading-snug text-[#637877]">
            {isCreditSale
              ? "Credit sale: stock is reduced now, and the full sale total is posted to the customer's account."
              : postChangeToCreditAccount
              ? changeAccountIsSettlementOnly
                ? "Restricted account: change will settle existing debt only; the completed sale will show any physical remainder returned."
                : `Customer agreed: ${money(totals.change, totals.currency)} change will be credited to the selected account; change returned is ${money(0, totals.currency)}.`
              : amountTenderedRequired
              ? "Keyboard: numbers to type, Backspace to delete, Enter to complete, Esc to go back."
              : "This payment type does not require amount tendered. Amount is auto-paid."}
          </div>
        </section>
      </div>
    </div>
  );
};


export const SplitPaymentModal = ({
  open,
  onClose,
  paymentRows = [],
  paymentTypes = [],
  saleCurrencyCode,
  totals,
  loadingPayments,
  activePaymentRowId,
  selectPaymentRow,
  updatePaymentRowAmount,
  updatePaymentRowType,
  addPaymentRow,
  removePaymentRow,
  completeSale,
  savingSale,
  printingReceipt,
  paymentTypeById,
}) => {
  const rows = Array.isArray(paymentRows) ? paymentRows : [];
  const paid = rows.reduce((sum, row) => sum + Number(row?.amount || 0), 0);
  const balance = Math.max(Number(totals?.total || 0) - paid, 0);
  const change = Math.max(paid - Number(totals?.total || 0), 0);
  const currency = saleCurrencyCode || totals?.currency || "USD";

  useEffect(() => {
    if (!open) return undefined;

    const handleEscape = (event) => {
      if (event.key !== "Escape") return;
      event.preventDefault();
      event.stopPropagation();
      onClose?.();
    };

    window.addEventListener("keydown", handleEscape, true);
    return () => window.removeEventListener("keydown", handleEscape, true);
  }, [open, onClose]);

  if (!open) return null;

  const setBalanceToRow = (rowId) => {
    const otherPaid = rows
      .filter((row) => row.id !== rowId)
      .reduce((sum, row) => sum + Number(row?.amount || 0), 0);
    const amount = Math.max(Number(totals?.total || 0) - otherPaid, 0);
    updatePaymentRowAmount?.(rowId, amount.toFixed(2));
    selectPaymentRow?.(rowId);
  };

  const canComplete =
    Number(totals?.total || 0) > 0 &&
    paid + 0.0001 >= Number(totals?.total || 0) &&
    rows.some((row) => row.payment_type_id && Number(row.amount || 0) > 0) &&
    !savingSale &&
    !printingReceipt;

  return (
    <div className="fixed inset-0 z-[110] flex items-center justify-center bg-[#003637]/65 p-3" role="presentation">
      <div
        data-testid="split-payment-modal"
        className="pos-split-payment-panel sx-large-modal-window border border-[#0AA7A7] bg-white shadow-2xl"
        role="dialog"
        aria-modal="true"
        aria-label="Split payment"
      >
        <header className="pos-split-payment-header flex h-14 shrink-0 items-center justify-between border-b border-white/15 bg-[#004142] pl-4 text-white">
          <div className="flex items-center gap-3">
            <div>
              <div className="text-[15px] font-black uppercase tracking-wide">Split Payment</div>
              <div className="text-[9px] font-semibold text-white/65">Use two or more payment methods</div>
            </div>
            <span className="rounded-full border border-white/25 bg-white/10 px-3 py-1 text-[10px] font-black">
              {currency}
            </span>
          </div>
          <button
            type="button"
            onClick={onClose}
            aria-label="Close split payment"
            className="flex h-full w-14 items-center justify-center border-l border-white/15 bg-[#B42318] text-[22px] font-black text-white hover:bg-[#9E1F16]"
          >
            ×
          </button>
        </header>

        <div className="grid shrink-0 grid-cols-2 gap-2 border-b border-[#CFD9D8] bg-[#F5F7FA] p-3 sm:grid-cols-4">
          <SplitTotal label="Total" value={money(totals?.total, currency)} />
          <SplitTotal label="Paid" value={money(paid, currency)} positive={paid > 0} />
          <SplitTotal label="Balance" value={money(balance, currency)} warning={balance > 0.0001} />
          <SplitTotal label="Change" value={money(change, currency)} positive={change > 0} />
        </div>

        <main className="flex min-h-0 flex-1 flex-col bg-[#F8FAFC] p-3">
          <div className="flex h-10 shrink-0 items-center justify-between">
            <div className="text-[11px] font-black uppercase tracking-wide text-[#004141]">Payments</div>
            <button
              type="button"
              onClick={addPaymentRow}
              disabled={loadingPayments}
              className="h-9 rounded-md border border-[#067C7C] bg-[#069495] px-4 text-[10px] font-black uppercase text-white shadow-sm hover:bg-[#067C7C] disabled:opacity-50"
            >
              + Add payment
            </button>
          </div>

          <div className="mt-2 min-h-0 flex-1 space-y-2 overflow-y-auto pr-1">
            {rows.map((row, index) => {
              const active = row.id === activePaymentRowId;
              const method = paymentTypeById?.get?.(String(row.payment_type_id));
              const isCredit = isCustomerCreditPaymentType(method);

              return (
                <div
                  key={row.id || index}
                  onClick={() => selectPaymentRow?.(row.id)}
                  className={`grid gap-2 rounded-lg border p-2 shadow-sm md:grid-cols-[minmax(220px,1fr)_170px_150px] ${
                    active
                      ? "border-[#069495] bg-[#ECFDF8]"
                      : "border-[#CFD9D8] bg-white hover:border-[#ACB8B8]"
                  }`}
                >
                  <label className="min-w-0">
                    <span className="mb-1 block text-[8px] font-black uppercase text-[#627474]">Method</span>
                    <select
                      value={row.payment_type_id || ""}
                      onFocus={() => selectPaymentRow?.(row.id)}
                      onChange={(event) => updatePaymentRowType?.(row.id, event.target.value)}
                      className="h-10 w-full min-w-0 rounded-md border border-[#ACB8B8] bg-white px-2 text-[11px] font-black text-[#004141] outline-none focus:border-[#069495]"
                    >
                      <option value="">Select method</option>
                      {paymentTypes
                        .filter((methodRow) => !isCustomerCreditPaymentType(methodRow))
                        .map((methodRow) => (
                          <option key={methodRow.id} value={methodRow.id}>{methodRow.name}</option>
                        ))}
                    </select>
                    {isCredit && <span className="mt-1 block text-[8px] font-black uppercase text-[#B42318]">Not available for split</span>}
                  </label>

                  <label>
                    <span className="mb-1 block text-[8px] font-black uppercase text-[#627474]">Amount · {currency}</span>
                    <input
                      data-payment-input="true"
                      inputMode="decimal"
                      type="number"
                      min="0"
                      step="0.01"
                      value={row.amount || ""}
                      onFocus={() => selectPaymentRow?.(row.id)}
                      onChange={(event) => updatePaymentRowAmount?.(row.id, event.target.value)}
                      className="h-10 w-full rounded-md border border-[#ACB8B8] bg-white px-3 text-right text-[15px] font-black text-[#004141] outline-none focus:border-[#069495]"
                      placeholder="0.00"
                    />
                  </label>

                  <div className="flex items-end gap-2">
                    <button
                      type="button"
                      onClick={(event) => {
                        event.stopPropagation();
                        setBalanceToRow(row.id);
                      }}
                      className="h-10 flex-1 rounded-md border border-[#069495] bg-white px-2 text-[9px] font-black uppercase text-[#057B7C] hover:bg-[#E8F7F5]"
                    >
                      Fill balance
                    </button>
                    <button
                      type="button"
                      onClick={(event) => {
                        event.stopPropagation();
                        removePaymentRow?.(row.id);
                      }}
                      aria-label={`Remove payment ${index + 1}`}
                      title="Remove"
                      className="flex h-10 w-10 shrink-0 items-center justify-center rounded-md border border-[#FDA29B] bg-white text-[#B42318] hover:bg-[#FEF3F2]"
                    >
                      <Delete className="h-4 w-4" />
                    </button>
                  </div>
                </div>
              );
            })}

            {rows.length === 0 && (
              <button
                type="button"
                onClick={addPaymentRow}
                className="flex h-24 w-full items-center justify-center rounded-lg border border-dashed border-[#96A5A4] bg-white text-[11px] font-black uppercase text-[#465757]"
              >
                + Add first payment
              </button>
            )}
          </div>
        </main>

        <footer className="flex min-h-[60px] shrink-0 items-center justify-end gap-2 border-t border-[#CFD9D8] bg-white px-3 py-2">
          <button
            type="button"
            onClick={onClose}
            className="h-10 rounded-md border border-[#AAB5B5] bg-white px-5 text-[10px] font-black uppercase text-[#324443] hover:bg-[#F2F4F7]"
          >
            Close
          </button>
          <button
            type="button"
            disabled={!canComplete}
            onClick={completeSale}
            className="h-10 min-w-[170px] rounded-md border border-[#00883F] bg-[#00A651] px-5 text-[11px] font-black uppercase text-white shadow-sm hover:bg-[#008F46] disabled:cursor-not-allowed disabled:opacity-50"
          >
            {savingSale ? "Saving..." : printingReceipt ? "Printing..." : "Complete Sale"}
          </button>
        </footer>
      </div>
    </div>
  );
};

const SplitTotal = ({ label, value, warning = false, positive = false }) => (
  <div className="min-w-0 rounded-lg border border-[#CFD9D8] bg-white px-3 py-2 shadow-sm">
    <div className="text-[8px] font-black uppercase tracking-wide text-[#627474]">{label}</div>
    <div className={`mt-1 truncate text-[14px] font-black ${warning ? "text-[#B42318]" : positive ? "text-[#057B7C]" : "text-[#004141]"}`} title={String(value || "")}>
      {value}
    </div>
  </div>
);

const ActionPanel = ({
  actionPanel,
  selectedLine,
  formatQty,
  editQty,
  setEditQty,
  discountValue,
  setDiscountValue,
  applyEditLine,
  applyDiscount,
  close,
  totals,

  shiftSummary,
  loadingShiftSummary,
  cashupNotes,
  setCashupNotes,
  cashupPayments,
  endShiftPayments,
  endShiftNotes,
  setEndShiftNotes,
  updateCountedPayment,
  savingCashup,
  submitCashup,
  submitEndShift,

  openShiftSummary,
  openCashup,
  openEndShift,
  openReportsSnapshot,
  reprintLastReceipt,
  openCustomerScreen,
  openPaymentOptions,
  openCustomerAccount,
  canCaptureCreditCustomerPayment,
  openManager,
  openPriceOverridePanel,
  priceOverrideConfig,
  priceOverrideRows,
  priceOverrideLoading,
  applySelectedPriceLevel,
  openDiscountModal,
  openLoadQuoteModal,
  loadingQuotation,
  noSale,

  reportsLoading,
  reportsSnapshot,

  customer,
  setCustomer,
}) => {
  const title =
    {
      editLine: "Edit Line",
      discount: "Discount",
      moreFunctions: "More Functions",
      shiftSummary: "Shift Summary",
      cashup: "Cashup",
      endShift: "End Shift",
      reportsSnapshot: "Today Sales Reports",
      hold: "Hold Sale",
      customer: "Customer Details",
      manager: "Manager Override",
    }[actionPanel] || "Action";

  const summaryTotals = shiftSummary?.totals || {};
  const summaryCash = shiftSummary?.cash || {};
  const paymentRows = Array.isArray(shiftSummary?.payments)
    ? shiftSummary.payments
    : [];
  const canViewCashupAmounts = canViewCashupFinancialAmounts(shiftSummary);

  const dashboardSummary =
    reportsSnapshot?.dashboard?.base_summary ||
    reportsSnapshot?.dashboard?.summary ||
    {};

  const dashboardPayments =
    reportsSnapshot?.dashboard?.payment_summary ||
    reportsSnapshot?.payments ||
    [];

  const topProducts =
    reportsSnapshot?.dashboard?.top_products ||
    reportsSnapshot?.products ||
    [];

  if (actionPanel === "editLine" && selectedLine) {
    return (
      <QuantityEntryModal
        selectedLine={selectedLine}
        editQty={editQty}
        setEditQty={setEditQty}
        applyEditLine={applyEditLine}
        close={close}
        formatQty={formatQty}
      />
    );
  }

  if (actionPanel === "customer") {
    return (
      <CustomerDetailsEntryModal
        customer={customer}
        setCustomer={setCustomer}
        close={close}
      />
    );
  }

  if (actionPanel === "priceOverride") {
    return (
      <PriceOverrideEntryModal
        selectedLine={selectedLine}
        priceOverrideConfig={priceOverrideConfig}
        priceOverrideRows={priceOverrideRows}
        priceOverrideLoading={priceOverrideLoading}
        applySelectedPriceLevel={applySelectedPriceLevel}
        close={close}
      />
    );
  }

  return (
    <div className="absolute inset-0 z-[70] flex items-center justify-center bg-[#003637]/45 p-4">
      <div className="sx-large-modal-window border border-[#00BCBC] bg-white shadow-[0_16px_36px_rgba(0,54,54,0.28)]" role="dialog" aria-modal="true" aria-label={title}>
        <div className="grid min-h-[46px] grid-cols-[1fr_auto] border-b border-[#00BCBC] bg-[#004142] text-white">
          <div className="flex min-w-0 items-center gap-2 px-3 py-2">
            <div className="flex h-7 w-7 items-center justify-center border border-white/20 bg-white/10 text-[15px] font-black">
              ≡
            </div>
            <div className="min-w-0">
              <div className="text-[13px] font-black uppercase tracking-[0.08em]">
                {title}
              </div>
              <div className="mt-[1px] text-[9px] font-semibold uppercase tracking-[0.14em] text-white/70">
                Enterprise POS controls / clear action buttons / touch-ready
              </div>
            </div>
          </div>

          <button
            type="button"
            onClick={close}
            className="flex h-full w-[58px] items-center justify-center border-l border-white/20 bg-[#B42318] text-[18px] font-black text-white hover:bg-[#9E1F16]"
            title="Close"
          >
            ×
          </button>
        </div>

        <div className="min-h-0 flex-1 overflow-y-auto bg-[#F3F6F8] p-2 text-[11px]">
          {actionPanel === "moreFunctions" && (
            <div className="border border-[#ACB8B8] bg-white">
              <div className="flex h-8 items-center justify-between border-b border-[#CFD9D8] bg-[#F5F7FA] px-2">
                <div className="text-[10px] font-black uppercase tracking-[0.14em] text-[#004141]">
                  Enterprise POS Functions
                </div>
                <div className="text-[9px] font-semibold uppercase tracking-[0.12em] text-[#627474]">
                  Pricing / Shift / Reports / Devices
                </div>
              </div>

              <div className="p-2">
                <div className="grid grid-cols-2 gap-1.5 md:grid-cols-4 xl:grid-cols-6">
                  {/* Pay on the selling screen takes cash directly; split,
                      card and customer-account payments use this page. */}
                  <MoreButton onClick={openPaymentOptions}>
                    Payment Options
                  </MoreButton>

                  <MoreButton onClick={openDiscountModal} variant="warning">
                    Enterprise Discount
                  </MoreButton>

                  <MoreButton onClick={openPriceOverridePanel} variant="warning">
                    Price Override
                  </MoreButton>

                  <MoreButton onClick={openLoadQuoteModal} variant="warning">
                    {loadingQuotation ? "Loading Quote" : "Load Quote"}
                  </MoreButton>

                  <MoreButton onClick={openManager} variant="warning">
                    Manager Override
                  </MoreButton>

                  <MoreButton onClick={openCashup}>Cashup</MoreButton>

                  <MoreButton onClick={openEndShift} variant="danger">
                    End Shift
                  </MoreButton>

                  <MoreButton onClick={openShiftSummary}>
                    Shift Summary
                  </MoreButton>

                  <MoreButton onClick={openReportsSnapshot}>
                    Reports
                  </MoreButton>

                  <MoreButton onClick={reprintLastReceipt}>
                    Reprint Receipt
                  </MoreButton>

                  <MoreButton onClick={() => {}}>
                    Hold Sale
                  </MoreButton>

                  <MoreButton onClick={noSale}>
                    No Sale / Drawer
                  </MoreButton>

                  <MoreButton onClick={openCustomerScreen}>
                    Customer Display
                  </MoreButton>

                  <MoreButton
                    onClick={openCustomerAccount}
                    disabled={!canCaptureCreditCustomerPayment}
                    title={canCaptureCreditCustomerPayment ? "Receive a customer bill payment" : "Permission required: Capture Credit Customer Payment"}
                  >
                    Customer Bill Payment
                  </MoreButton>
                </div>
              </div>
            </div>
          )}

          {actionPanel === "customer" && (
            <div className="space-y-3">
              <div className="grid grid-cols-1 gap-2 md:grid-cols-2">
                <label className="block">
                  <div className="mb-1 text-[10px] text-[#637877]">
                    Customer Name
                  </div>
                  <input
                    autoFocus
                    type="text"
                    value={customer.name}
                    onChange={(event) =>
                      setCustomer((prev) => ({
                        ...prev,
                        name: event.target.value,
                      }))
                    }
                    className="h-9 w-full border border-[#ACB8B8] px-2 text-[12px] outline-none focus:border-[#069495]"
                    placeholder="Walk-in Customer"
                  />
                </label>

                <label className="block">
                  <div className="mb-1 text-[10px] text-[#637877]">
                    Phone
                  </div>
                  <input
                    type="text"
                    value={customer.phone}
                    onChange={(event) =>
                      setCustomer((prev) => ({
                        ...prev,
                        phone: event.target.value,
                      }))
                    }
                    className="h-9 w-full border border-[#ACB8B8] px-2 text-[12px] outline-none focus:border-[#069495]"
                    placeholder="Optional"
                  />
                </label>

                <label className="block">
                  <div className="mb-1 text-[10px] text-[#637877]">
                    Tax Number
                  </div>
                  <input
                    type="text"
                    value={customer.taxNumber}
                    onChange={(event) =>
                      setCustomer((prev) => ({
                        ...prev,
                        taxNumber: event.target.value,
                      }))
                    }
                    className="h-9 w-full border border-[#ACB8B8] px-2 text-[12px] outline-none focus:border-[#069495]"
                    placeholder="Optional"
                  />
                </label>

                <label className="block">
                  <div className="mb-1 text-[10px] text-[#637877]">
                    Notes
                  </div>
                  <input
                    type="text"
                    value={customer.notes}
                    onChange={(event) =>
                      setCustomer((prev) => ({
                        ...prev,
                        notes: event.target.value,
                      }))
                    }
                    className="h-9 w-full border border-[#ACB8B8] px-2 text-[12px] outline-none focus:border-[#069495]"
                    placeholder="Optional"
                  />
                </label>
              </div>

              <button
                type="button"
                onClick={close}
                className="h-9 w-full border border-[#057B7C] bg-[#069495] text-[11px] font-black text-white"
              >
                Save Customer
              </button>
            </div>
          )}

          {actionPanel === "editLine" && selectedLine && (
            <div className="space-y-3">
              <div className="border border-[#CAD5D5] bg-[#F8FAFC] p-3">
                <div className="text-[12px] font-black">{selectedLine.name}</div>

                <div className="mt-1 text-[11px] text-[#637877]">
                  Current Qty:{" "}
                  {formatQty(
                    selectedLine.qty,
                    selectedLine.unit,
                    selectedLine.allowDecimalQty,
                    selectedLine.decimalPlaces
                  )}
                </div>
              </div>

              <label className="block">
                <div className="mb-1 text-[10px] text-[#637877]">
                  New Quantity
                  {selectedLine.allowDecimalQty
                    ? ` (${selectedLine.unit || "PORTION"})`
                    : ""}
                </div>

                <input
                  autoFocus
                  type="number"
                  min={
                    selectedLine.allowDecimalQty
                      ? String(getDecimalStep(selectedLine.decimalPlaces || 2))
                      : "1"
                  }
                  step={
                    selectedLine.allowDecimalQty
                      ? String(getDecimalStep(selectedLine.decimalPlaces || 2))
                      : "1"
                  }
                  value={editQty}
                  onChange={(event) => setEditQty(event.target.value)}
                  className="h-9 w-full border border-[#ACB8B8] px-2 text-[12px] outline-none focus:border-[#069495]"
                />
              </label>

              <button
                type="button"
                onClick={applyEditLine}
                className="h-9 w-full border border-[#057B7C] bg-[#069495] text-[11px] font-black text-white"
              >
                Apply Quantity
              </button>
            </div>
          )}

          {actionPanel === "discount" && selectedLine && (
            <div className="space-y-3">
              <div className="border border-[#CAD5D5] bg-[#F8FAFC] p-3">
                <div className="text-[12px] font-black">{selectedLine.name}</div>

                <div className="mt-1 text-[11px] text-[#637877]">
                  Line Total:{" "}
                  {money(
                    selectedLine.qty * selectedLine.price,
                    selectedLine.currency
                  )}
                </div>
              </div>

              <label className="block">
                <div className="mb-1 text-[10px] text-[#637877]">
                  Discount Amount
                </div>

                <input
                  autoFocus
                  type="number"
                  min="0"
                  value={discountValue}
                  onChange={(event) => setDiscountValue(event.target.value)}
                  className="h-9 w-full border border-[#ACB8B8] px-2 text-[12px] outline-none focus:border-[#069495]"
                />
              </label>

              <button
                type="button"
                onClick={applyDiscount}
                className="h-9 w-full border border-[#057B7C] bg-[#069495] text-[11px] font-black text-white"
              >
                Apply Discount
              </button>
            </div>
          )}

          {actionPanel === "shiftSummary" && (
            <div className="space-y-3">
              {loadingShiftSummary ? (
                <PanelLoading label="Loading shift summary." />
              ) : (
                <>
                  <div className={`grid grid-cols-2 gap-2 ${canViewCashupAmounts ? "md:grid-cols-4" : "md:grid-cols-2"}`}>
                    <Metric
                      label="Transactions"
                      value={summaryTotals.transaction_count || 0}
                    />

                    {canViewCashupAmounts && <Metric
                      label={summaryTotals.is_multi_currency ? `Base Total (${summaryTotals.base_currency_code || totals.currency})` : "Expected Total"}
                      value={money(summaryTotals.is_multi_currency ? summaryTotals.base_total : summaryTotals.expected_total, summaryTotals.is_multi_currency ? summaryTotals.base_currency_code : (summaryTotals.currency_code || totals.currency))}
                    />}

                    {canViewCashupAmounts && <Metric
                      label={summaryCash.is_multi_currency ? `Base Expected Cash (${summaryTotals.base_currency_code || totals.currency})` : "Expected Cash"}
                      value={money(summaryCash.is_multi_currency ? summaryCash.base_expected_cash : summaryCash.expected_cash, summaryCash.is_multi_currency ? summaryTotals.base_currency_code : (summaryCash.currency_code || totals.currency))}
                    />}

                    {canViewCashupAmounts && <Metric
                      label={summaryTotals.is_multi_currency ? `Base Tax (${summaryTotals.base_currency_code || totals.currency})` : "Tax"}
                      value={money(summaryTotals.is_multi_currency ? summaryTotals.base_tax : summaryTotals.tax, summaryTotals.is_multi_currency ? summaryTotals.base_currency_code : (summaryTotals.currency_code || totals.currency))}
                    />}
                  </div>

                  {!canViewCashupAmounts && <RestrictedCashupAmountsNotice />}
                  <PaymentSummaryTable rows={paymentRows} canViewAmounts={canViewCashupAmounts} />

                  <div className="grid grid-cols-2 gap-2">
                    <button
                      type="button"
                      onClick={openCashup}
                      className="h-9 border border-[#057B7C] bg-[#069495] text-[11px] font-black text-white"
                    >
                      Cashup
                    </button>

                    <button
                      type="button"
                      onClick={openEndShift}
                      className="h-9 border border-[#B8000B] bg-[#E30613] text-[11px] font-black text-white"
                    >
                      End Shift
                    </button>
                  </div>
                </>
              )}
            </div>
          )}

          {actionPanel === "cashup" && (
            <CashCountPanel
              title="Cashup Count"
              loading={loadingShiftSummary}
              rows={cashupPayments}
              mode="cashup"
              notes={cashupNotes}
              setNotes={setCashupNotes}
              updateCountedPayment={updateCountedPayment}
              saving={savingCashup}
              submitLabel="Save Cashup"
              onSubmit={submitCashup}
              danger={false}
              canViewAmounts={canViewCashupAmounts}
            />
          )}

          {actionPanel === "endShift" && (
            <CashCountPanel
              title="End Shift Count"
              loading={loadingShiftSummary}
              rows={endShiftPayments}
              mode="endShift"
              notes={endShiftNotes}
              setNotes={setEndShiftNotes}
              updateCountedPayment={updateCountedPayment}
              saving={savingCashup}
              submitLabel="End Shift"
              onSubmit={submitEndShift}
              danger
              canViewAmounts={canViewCashupAmounts}
            />
          )}

          {actionPanel === "reportsSnapshot" && (
            <div className="space-y-3">
              {reportsLoading ? (
                <PanelLoading label="Loading reports." />
              ) : (
                <>
                  <div className="grid grid-cols-2 gap-2 md:grid-cols-4">
                    <Metric
                      label="Transactions"
                      value={dashboardSummary.transaction_count || 0}
                    />

                    <Metric
                      label="Net Sales"
                      value={money(
                        dashboardSummary.net_sales ||
                          dashboardSummary.base_net_sales ||
                          0,
                        totals.currency
                      )}
                    />

                    <Metric
                      label="Tax"
                      value={money(dashboardSummary.tax, totals.currency)}
                    />

                    <Metric
                      label="Avg Basket"
                      value={money(
                        dashboardSummary.average_basket,
                        totals.currency
                      )}
                    />
                  </div>

                  <ReportSection title="Payment Summary">
                    {Array.isArray(dashboardPayments) &&
                    dashboardPayments.length > 0 ? (
                      dashboardPayments.map((row, index) => (
                        <ReportRow
                          key={index}
                          label={`${row.payment_name} / ${row.currency_code}`}
                          value={money(row.amount, row.currency_code)}
                        />
                      ))
                    ) : (
                      <EmptyText>No payment data.</EmptyText>
                    )}
                  </ReportSection>

                  <ReportSection title="Top Products">
                    {Array.isArray(topProducts) && topProducts.length > 0 ? (
                      topProducts.slice(0, 8).map((row, index) => (
                        <ReportRow
                          key={index}
                          label={`${index + 1}. ${row.product_name}`}
                          value={money(
                            row.total || row.base_total,
                            row.currency_code || totals.currency
                          )}
                          subValue={`Qty: ${Number(
                            row.quantity_sold || 0
                          ).toFixed(2)}`}
                        />
                      ))
                    ) : (
                      <EmptyText>No product sales found.</EmptyText>
                    )}
                  </ReportSection>
                </>
              )}
            </div>
          )}

          {actionPanel === "hold" && (
            <div className="border border-[#CAD5D5] bg-[#F8FAFC] p-3 text-[11px] text-[#324444]">
              Sale hold flow can later save this transaction and resume it.
            </div>
          )}

          {actionPanel === "priceOverride" && (
            <div className="space-y-3">
              <div className="border border-[#00BCBC] bg-[#E8F7F5] p-3">
                <div className="text-[12px] font-black uppercase text-[#005A5A]">
                  Price Level Override
                </div>

              </div>

              {!priceOverrideConfig?.enabled && (
                <div className="border border-[#FDA29B] bg-[#FEF3F2] p-3 text-[11px] font-black text-[#B42318]">
                  Price level override is disabled in General Settings.
                </div>
              )}

              {priceOverrideLoading && (
                <div className="border border-[#ACB8B8] bg-[#F8FAFC] p-3 text-[11px] font-black text-[#637877]">
                  Loading eligible price levels...
                </div>
              )}

              {!priceOverrideLoading && Array.isArray(priceOverrideRows) && priceOverrideRows.length === 0 && (
                <div className="border border-[#FEDF89] bg-[#FFFAEB] p-3 text-[11px] font-black text-[#B54708]">
                  No eligible price levels loaded. Select a line, then press Price Override again.
                </div>
              )}

              <div className="space-y-2">
                {(priceOverrideRows || []).map((row) => (
                  <button
                    key={row.id}
                    type="button"
                    onClick={() => applySelectedPriceLevel(row)}
                    className="w-full border border-[#ACB8B8] bg-white p-3 text-left transition hover:border-[#00BCBC] hover:bg-[#E8F7F5]"
                  >
                    <div className="flex items-center justify-between gap-3">
                      <div>
                        <div className="text-[12px] font-black uppercase text-[#003636]">
                          {row.price_level_name}
                        </div>
                        <div className="text-[10px] font-bold text-[#637877]">
                          {row.price_level_code || "LEVEL"} / Product price: 
                        </div>
                      </div>
                      <div className="text-right text-[16px] font-black text-[#069495]">
                        {money(row.price, row.currency)}
                      </div>
                    </div>
                  </button>
                ))}
              </div>
            </div>
          )}

          {actionPanel === "manager" && (
            <div className="space-y-3">
              <div className="border border-[#FFF0B3] bg-[#FFF7D6] p-3 text-[11px] text-[#5E4300]">
                Manager override requires PIN. This can later approve voids,
                price overrides, discounts, refunds, and drawer events.
              </div>

              <input
                autoFocus
                type="password"
                placeholder="Manager PIN"
                className="h-9 w-full border border-[#ACB8B8] px-2 text-[12px] outline-none"
              />

              <button
                type="button"
                className="h-9 w-full border border-[#D9A400] bg-[#FFC107] text-[11px] font-black text-[#5E4300]"
              >
                Verify Manager
              </button>
            </div>
          )}
        </div>
      </div>
    </div>
  );
};


const CustomerDetailsEntryModal = ({ customer, setCustomer, close }) => {
  const nameRef = useRef(null);

  useEffect(() => {
    window.setTimeout(() => {
      nameRef.current?.focus();
      nameRef.current?.select();
    }, 50);
  }, []);

  const submit = (event) => {
    event.preventDefault();
    close?.();
  };

  const updateCustomerField = (field, value) => {
    setCustomer((prev) => ({
      ...prev,
      [field]: value,
    }));
  };

  return (
    <div className="absolute inset-0 z-[95] flex items-center justify-center bg-[#003637]/45 p-4">
      <form
        onSubmit={submit}
        className="sx-large-modal-window sx-large-modal-scroll border border-[#AAB5B5] bg-white text-[#1C2C2B] shadow-2xl"
      >
        <div className="flex h-11 items-center justify-between border-b border-[#00BCBC] bg-[#004142] px-3 text-white">
          <div>
            <div className="text-[13px] font-black uppercase">Customer Details</div>
            <div className="text-[10px] font-semibold uppercase text-white/70">
              Type details, Enter saves, Escape closes
            </div>
          </div>
          <button
            type="button"
            onClick={close}
            className="flex h-8 w-8 items-center justify-center border border-white/20 bg-white/10 text-[18px] font-black hover:bg-white/20"
          >
            ×
          </button>
        </div>

        <div className="grid gap-3 p-3 md:grid-cols-[210px_1fr]">
          <aside className="border border-[#CFD9D8] bg-[#F5F7FA] p-3 text-[11px]">
            <div className="border-b border-[#CFD9D8] pb-2 text-[10px] font-black uppercase text-[#627474]">
              Sale Customer
            </div>
            <div className="mt-2 space-y-2">
              <QtySummaryLine label="Name" value={customer?.name || "Walk-in Customer"} />
              <QtySummaryLine label="Phone" value={customer?.phone || "-"} />
              <QtySummaryLine label="Tax No" value={customer?.taxNumber || "-"} />
              <QtySummaryLine label="Notes" value={customer?.notes ? "Added" : "-"} />
            </div>
            <div className="mt-3 border border-[#CFD9D8] bg-white p-2 text-[10px] text-[#627474]">
              These details will print/use on the current sale only unless the sale is posted to a credit customer.
            </div>
          </aside>

          <main className="min-w-0 space-y-2">
            <CustomerModalInput
              inputRef={nameRef}
              label="Customer Name"
              value={customer?.name || ""}
              onChange={(value) => updateCustomerField("name", value)}
              placeholder="Walk-in Customer"
              onEscape={close}
            />
            <div className="grid gap-2 md:grid-cols-2">
              <CustomerModalInput
                label="Phone"
                value={customer?.phone || ""}
                onChange={(value) => updateCustomerField("phone", value)}
                placeholder="Optional"
              />
              <CustomerModalInput
                label="Tax Number"
                value={customer?.taxNumber || ""}
                onChange={(value) => updateCustomerField("taxNumber", value.toUpperCase())}
                placeholder="Optional"
              />
            </div>
            <label className="block">
              <div className="mb-1 text-[10px] font-black uppercase text-[#627474]">Notes</div>
              <textarea
                value={customer?.notes || ""}
                onChange={(event) => updateCustomerField("notes", event.target.value)}
                onKeyDown={(event) => {
                  if (event.key === "Escape") {
                    event.preventDefault();
                    close?.();
                  }
                }}
                rows={5}
                className="w-full border border-[#AAB5B5] bg-white px-3 py-2 text-[13px] font-semibold text-[#004141] outline-none focus:border-[#069495]"
                placeholder="Optional sale notes"
              />
            </label>
          </main>
        </div>

        <div className="flex h-12 items-center justify-between border-t border-[#CFD9D8] bg-[#F5F7FA] px-3">
          <div className="text-[10px] font-semibold uppercase text-[#627474]">
            Physical keyboard supported. Press Enter from fields to save.
          </div>
          <div className="flex gap-2">
            <button type="button" onClick={close} className="h-8 border border-[#AAB5B5] bg-white px-4 text-[10px] font-black uppercase text-[#324443]">Cancel</button>
            <button type="submit" className="h-8 border border-[#069495] bg-[#069495] px-5 text-[10px] font-black uppercase text-white">Save Customer</button>
          </div>
        </div>
      </form>
    </div>
  );
};

const CustomerModalInput = ({ label, value, onChange, placeholder = "", inputRef = null, onEscape = null }) => (
  <label className="block">
    <div className="mb-1 text-[10px] font-black uppercase text-[#627474]">{label}</div>
    <input
      ref={inputRef}
      type="text"
      value={value || ""}
      onChange={(event) => onChange?.(event.target.value)}
      onKeyDown={(event) => {
        if (event.key === "Escape") {
          event.preventDefault();
          onEscape?.();
        }
      }}
      placeholder={placeholder}
      className="h-11 w-full border border-[#AAB5B5] bg-white px-3 text-[14px] font-black text-[#004141] outline-none focus:border-[#069495]"
    />
  </label>
);

const PriceOverrideEntryModal = ({
  selectedLine,
  priceOverrideConfig,
  priceOverrideRows,
  priceOverrideLoading,
  applySelectedPriceLevel,
  close,
}) => {
  const [selectedIndex, setSelectedIndex] = useState(0);
  const selectedRow = Array.isArray(priceOverrideRows) ? priceOverrideRows[selectedIndex] : null;

  useEffect(() => {
    setSelectedIndex(0);
  }, [priceOverrideRows]);

  useEffect(() => {
    const handler = (event) => {
      if (event.key === "Escape") {
        event.preventDefault();
        close?.();
      }
      if (event.key === "ArrowDown") {
        event.preventDefault();
        setSelectedIndex((prev) => Math.min(prev + 1, Math.max((priceOverrideRows || []).length - 1, 0)));
      }
      if (event.key === "ArrowUp") {
        event.preventDefault();
        setSelectedIndex((prev) => Math.max(prev - 1, 0));
      }
      if (event.key === "Enter" && selectedRow) {
        event.preventDefault();
        applySelectedPriceLevel?.(selectedRow);
      }
    };

    window.addEventListener("keydown", handler);
    return () => window.removeEventListener("keydown", handler);
  }, [close, priceOverrideRows, selectedRow, applySelectedPriceLevel]);

  return (
    <div className="absolute inset-0 z-[95] flex items-center justify-center bg-[#003637]/45 p-4">
      <div className="sx-large-modal-window sx-large-modal-scroll border border-[#AAB5B5] bg-white text-[#1C2C2B] shadow-2xl" role="dialog" aria-modal="true" aria-label="Price Override">
        <div className="flex h-11 items-center justify-between border-b border-[#00BCBC] bg-[#004142] px-3 text-white">
          <div>
            <div className="text-[13px] font-black uppercase">Price Override</div>
            <div className="text-[10px] font-semibold uppercase text-white/70">
              Select level, double click or press Enter to apply
            </div>
          </div>
          <button type="button" onClick={close} className="flex h-8 w-8 items-center justify-center border border-white/20 bg-white/10 text-[18px] font-black hover:bg-white/20">×</button>
        </div>

        <div className="grid min-h-[360px] gap-3 p-3 md:grid-cols-[230px_1fr]">
          <aside className="border border-[#CFD9D8] bg-[#F5F7FA] p-3 text-[11px]">
            <div className="border-b border-[#CFD9D8] pb-2 text-[10px] font-black uppercase text-[#627474]">Product Line</div>
            <div className="mt-2 space-y-2">
              <QtySummaryLine label="Product" value={selectedLine?.name || "-"} />
              <QtySummaryLine label="Code" value={selectedLine?.code || selectedLine?.sku || "-"} />
              <QtySummaryLine label="Current Price" value={money(selectedLine?.price, selectedLine?.currency)} />
              <QtySummaryLine label="Current Level" value={selectedLine?.priceLevelName || "Standard"} />
              <QtySummaryLine label="Rows" value={(priceOverrideRows || []).length} />
            </div>
            {!priceOverrideConfig?.enabled && (
              <div className="mt-3 border border-[#FDA29B] bg-[#FEF3F2] p-2 text-[10px] font-black uppercase text-[#B42318]">
                Price override is disabled.
              </div>
            )}
          </aside>

          <main className="min-h-0 overflow-hidden border border-[#CFD9D8] bg-white">
            <div className="flex h-9 items-center justify-between border-b border-[#CFD9D8] bg-[#F5F7FA] px-2">
              <div className="text-[12px] font-black uppercase text-[#324443]">Eligible Price Levels</div>
              <div className="text-[10px] font-semibold uppercase text-[#627474]">
                {priceOverrideLoading ? "Loading..." : "Arrow keys + Enter supported"}
              </div>
            </div>
            <div className="max-h-[360px] overflow-auto">
              {priceOverrideLoading ? (
                <PanelLoading label="Loading eligible price levels." />
              ) : !Array.isArray(priceOverrideRows) || priceOverrideRows.length === 0 ? (
                <div className="p-8 text-center text-[12px] font-semibold text-[#B54708]">
                  No eligible price levels loaded. Select a line, then press Price Override again.
                </div>
              ) : (
                <table className="w-full min-w-[600px] border-collapse text-[11px]">
                  <thead className="sticky top-0 bg-[#0E4D4D] text-white">
                    <tr>
                      <GridHead>#</GridHead>
                      <GridHead>Level</GridHead>
                      <GridHead>Code</GridHead>
                      <GridHead align="right">Price</GridHead>
                      <GridHead>Action</GridHead>
                    </tr>
                  </thead>
                  <tbody>
                    {priceOverrideRows.map((row, index) => {
                      const active = index === selectedIndex;
                      return (
                        <tr
                          key={row.id || index}
                          onClick={() => setSelectedIndex(index)}
                          onDoubleClick={() => applySelectedPriceLevel?.(row)}
                          className={`cursor-pointer border-b border-[#CFD9D8] ${active ? "bg-[#E8F7F5] text-[#005A5A]" : "bg-white hover:bg-[#F8FAFC]"}`}
                        >
                          <GridCell strong={active}>{index + 1}</GridCell>
                          <GridCell strong={active}>{row.price_level_name || "Price Level"}</GridCell>
                          <GridCell>{row.price_level_code || "LEVEL"}</GridCell>
                          <GridCell right strong>{money(row.price, row.currency || selectedLine?.currency)}</GridCell>
                          <GridCell>
                            <button type="button" onClick={() => applySelectedPriceLevel?.(row)} className="h-7 border border-[#069495] bg-[#069495] px-3 text-[10px] font-black uppercase text-white">Apply</button>
                          </GridCell>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              )}
            </div>
          </main>
        </div>

        <div className="flex h-12 items-center justify-between border-t border-[#CFD9D8] bg-[#F5F7FA] px-3">
          <div className="text-[10px] font-semibold uppercase text-[#627474]">Keyboard supported: Arrow Up/Down selects, Enter applies, Escape closes.</div>
          <div className="flex gap-2">
            <button type="button" onClick={close} className="h-8 border border-[#AAB5B5] bg-white px-4 text-[10px] font-black uppercase text-[#324443]">Cancel</button>
            <button type="button" disabled={!selectedRow || priceOverrideLoading} onClick={() => applySelectedPriceLevel?.(selectedRow)} className="h-8 border border-[#069495] bg-[#069495] px-5 text-[10px] font-black uppercase text-white disabled:opacity-60">Apply Price</button>
          </div>
        </div>
      </div>
    </div>
  );
};

const GridHead = ({ children, align = "left" }) => (
  <th className={`border-r border-white/10 px-2 py-2 text-${align} text-[10px] font-black uppercase last:border-r-0`}>{children}</th>
);

const GridCell = ({ children, right = false, strong = false }) => (
  <td className={`border-r border-[#CFD9D8] px-2 py-2 align-top last:border-r-0 ${right ? "text-right" : "text-left"} ${strong ? "font-black text-[#0C1B1B]" : "text-[#324443]"}`}>{children}</td>
);


const QuantityEntryModal = ({
  selectedLine,
  editQty,
  setEditQty,
  applyEditLine,
  close,
  formatQty,
}) => {
  const inputRef = useRef(null);
  const allowDecimalQty = Boolean(selectedLine?.allowDecimalQty);
  const decimalPlaces = Number(selectedLine?.decimalPlaces || 2);
  const unit = selectedLine?.unit || "EACH";
  const minQty = allowDecimalQty ? getDecimalStep(decimalPlaces) : 1;
  const currentQtyLabel = formatQty
    ? formatQty(selectedLine?.qty, unit, allowDecimalQty, decimalPlaces)
    : `${Number(selectedLine?.qty || 0)} ${unit}`;
  const availableStock = Number(selectedLine?.availableStock ?? 0);

  useEffect(() => {
    window.setTimeout(() => {
      inputRef.current?.focus();
      inputRef.current?.select();
    }, 50);
  }, []);

  const cleanValue = (value) => {
    if (allowDecimalQty) {
      return String(value || "")
        .replace(/[^0-9.]/g, "")
        .replace(/(\..*)\./g, "$1");
    }

    return String(value || "").replace(/\D/g, "");
  };

  const setCleanQty = (value) => {
    setEditQty(cleanValue(value));
  };

  const appendQty = (value) => {
    setEditQty((prev) => {
      if (value === "." && !allowDecimalQty) return cleanValue(prev || "");
      return cleanValue(appendDecimalNumber(prev || "", value));
    });
    inputRef.current?.focus();
  };

  const backspaceQty = () => {
    setEditQty((prev) => cleanValue(String(prev || "").slice(0, -1)));
    inputRef.current?.focus();
  };

  const clearQty = () => {
    setEditQty("");
    inputRef.current?.focus();
  };

  const addStep = () => {
    const next = Number(editQty || selectedLine?.qty || 0) + minQty;
    setEditQty(allowDecimalQty ? next.toFixed(decimalPlaces) : String(Math.floor(next)));
    inputRef.current?.focus();
  };

  const subtractStep = () => {
    const next = Math.max(Number(editQty || selectedLine?.qty || 0) - minQty, minQty);
    setEditQty(allowDecimalQty ? next.toFixed(decimalPlaces) : String(Math.floor(next)));
    inputRef.current?.focus();
  };

  const submit = (event) => {
    event.preventDefault();
    applyEditLine?.();
  };

  return (
    <div className="absolute inset-0 z-[95] flex items-center justify-center bg-[#003637]/45 p-4">
      <form
        onSubmit={submit}
        className="sx-large-modal-window sx-large-modal-scroll border border-[#AAB5B5] bg-white text-[#1C2C2B] shadow-2xl"
      >
        <div className="flex h-11 items-center justify-between border-b border-[#00BCBC] bg-[#004142] px-3 text-white">
          <div>
            <div className="text-[13px] font-black uppercase">Enter Quantity</div>
            <div className="text-[10px] font-semibold uppercase text-white/70">
              {selectedLine?.name || "Selected Product"}
            </div>
          </div>

          <button
            type="button"
            onClick={close}
            className="flex h-8 w-8 items-center justify-center border border-white/20 bg-white/10 text-[18px] font-black hover:bg-white/20"
          >
            ×
          </button>
        </div>

        <div className="grid gap-3 p-3 md:grid-cols-[190px_1fr]">
          <aside className="border border-[#CFD9D8] bg-[#F5F7FA] p-3 text-[11px]">
            <div className="border-b border-[#CFD9D8] pb-2 text-[10px] font-black uppercase text-[#627474]">
              Product Line
            </div>

            <div className="mt-2 space-y-2">
              <QtySummaryLine label="Product" value={selectedLine?.name || "-"} />
              <QtySummaryLine label="Code" value={selectedLine?.code || selectedLine?.sku || "-"} />
              <QtySummaryLine label="Current" value={currentQtyLabel} />
              <QtySummaryLine label="Unit" value={unit} />
              <QtySummaryLine
                label="Available"
                value={Number.isFinite(availableStock) ? availableStock.toFixed(4) : "-"}
              />
            </div>
          </aside>

          <main className="min-w-0">
            <label className="block">
              <div className="mb-1 text-[10px] font-black uppercase text-[#627474]">
                New Quantity {allowDecimalQty ? `(${unit}, decimals allowed)` : `(${unit})`}
              </div>
              <input
                ref={inputRef}
                type="text"
                inputMode={allowDecimalQty ? "decimal" : "numeric"}
                value={editQty || ""}
                onChange={(event) => setCleanQty(event.target.value)}
                onKeyDown={(event) => {
                  if (event.key === "Escape") {
                    event.preventDefault();
                    close?.();
                  }
                }}
                placeholder="Type quantity and press Enter"
                className="h-14 w-full border border-[#AAB5B5] bg-white px-3 text-right text-[32px] font-black text-[#004141] outline-none focus:border-[#069495]"
              />
            </label>

            <div className="mt-2 grid grid-cols-4 gap-2">
              {["7", "8", "9"].map((key) => (
                <QtyKeyButton key={key} onClick={() => appendQty(key)}>{key}</QtyKeyButton>
              ))}
              <QtyKeyButton variant="muted" onClick={backspaceQty}>⌫</QtyKeyButton>

              {["4", "5", "6"].map((key) => (
                <QtyKeyButton key={key} onClick={() => appendQty(key)}>{key}</QtyKeyButton>
              ))}
              <QtyKeyButton variant="muted" onClick={clearQty}>Clear</QtyKeyButton>

              {["1", "2", "3"].map((key) => (
                <QtyKeyButton key={key} onClick={() => appendQty(key)}>{key}</QtyKeyButton>
              ))}
              <QtyKeyButton variant="navy" onClick={addStep}>+{minQty}</QtyKeyButton>

              <QtyKeyButton onClick={() => appendQty("0")}>0</QtyKeyButton>
              <QtyKeyButton onClick={() => appendQty("00")}>00</QtyKeyButton>
              <QtyKeyButton disabled={!allowDecimalQty} onClick={() => appendQty(".")}>.</QtyKeyButton>
              <QtyKeyButton variant="navy" onClick={subtractStep}>-{minQty}</QtyKeyButton>
            </div>
          </main>
        </div>

        <div className="flex h-12 items-center justify-between border-t border-[#CFD9D8] bg-[#F5F7FA] px-3">
          <div className="text-[10px] font-semibold uppercase text-[#627474]">
            Keyboard supported: type quantity, Enter applies, Escape closes.
          </div>
          <div className="flex gap-2">
            <button
              type="button"
              onClick={close}
              className="h-8 border border-[#AAB5B5] bg-white px-4 text-[10px] font-black uppercase text-[#324443]"
            >
              Cancel
            </button>
            <button
              type="submit"
              className="h-8 border border-[#069495] bg-[#069495] px-5 text-[10px] font-black uppercase text-white"
            >
              Apply Quantity
            </button>
          </div>
        </div>
      </form>
    </div>
  );
};

const QtySummaryLine = ({ label, value }) => (
  <div className="flex items-center justify-between gap-2 border-b border-[#E4E8E8] pb-1 last:border-b-0">
    <span className="text-[#627474]">{label}</span>
    <span className="truncate text-right font-black text-[#1C2C2B]">{value}</span>
  </div>
);

const QtyKeyButton = ({ children, onClick, variant = "default", disabled = false }) => {
  const variants = {
    default: "border-[#ACB8B8] bg-white text-[#003636] hover:bg-[#F1F5F5]",
    navy: "border-[#003636] bg-[#004142] text-white",
    muted: "border-[#ACB8B8] bg-[#E6ECEC] text-[#003636]",
  };

  return (
    <button
      type="button"
      disabled={disabled}
      onClick={onClick}
      className={`h-14 border text-[18px] font-black disabled:cursor-not-allowed disabled:opacity-40 ${variants[variant]}`}
    >
      {children}
    </button>
  );
};


const MoreButton = ({ children, onClick, variant = "default", disabled = false, title = "" }) => {
  const variants = {
    default:
      "border-[#ACB8B8] bg-[#F8FAFC] text-[#004141] shadow-[inset_0_-3px_0_#DAE2E2] hover:border-[#069495] hover:bg-white",
    danger:
      "border-[#B42318] bg-[#B42318] text-white shadow-[inset_0_-3px_0_rgba(0,0,0,0.22)] hover:bg-[#9E1F16]",
    warning:
      "border-[#D9A400] bg-[#FFC107] text-[#5E4300] shadow-[inset_0_-3px_0_#D9A400] hover:bg-[#FFD34D]",
  };

  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled}
      title={title}
      className={`group flex min-h-[52px] items-center justify-center border px-2 text-center text-[10px] font-black uppercase tracking-wide transition active:scale-[0.99] disabled:cursor-not-allowed disabled:opacity-40 ${variants[variant]}`}
    >
      <span className="leading-tight">{children}</span>
    </button>
  );
};

const CashCountPanel = ({
  title,
  loading,
  rows,
  mode,
  notes,
  setNotes,
  updateCountedPayment,
  saving,
  submitLabel,
  onSubmit,
  danger = false,
  canViewAmounts = false,
}) => {
  const totalsByCurrency = Object.values(rows.reduce((acc, row) => {
    const currency = String(row.currency_code || "USD").toUpperCase();
    if (!acc[currency]) acc[currency] = { currency, expected: 0, counted: 0 };
    acc[currency].expected += Number(row.expected_amount || 0);
    acc[currency].counted += Number(row.counted_amount || 0);
    return acc;
  }, {}));

  if (loading) {
    return <PanelLoading label={`Loading ${title.toLowerCase()}.`} />;
  }

  return (
    <div className="space-y-3">
      {canViewAmounts ? <div className="space-y-2">
        {totalsByCurrency.map((currencyTotals) => {
          const variance = currencyTotals.counted - currencyTotals.expected;
          return <div key={currencyTotals.currency} className="grid grid-cols-3 gap-2">
            <Metric label={`${currencyTotals.currency} Expected`} value={currencyTotals.expected.toFixed(2)} />
            <Metric label={`${currencyTotals.currency} Counted`} value={currencyTotals.counted.toFixed(2)} />
            <Metric label={`${currencyTotals.currency} Variance`} value={variance.toFixed(2)} green={variance >= 0} />
          </div>;
        })}
        {totalsByCurrency.length > 1 && <div className="border border-[#B2DFDB] bg-[#F0FDFA] px-2 py-1.5 text-[10px] font-semibold text-[#006869]">Currencies are reconciled separately and are never added together.</div>}
      </div> : <RestrictedCashupAmountsNotice />}

      <div className="border border-[#ACB8B8] bg-white">
        <div className="border-b border-[#ACB8B8] bg-[#F5F7FA] px-3 py-2 text-[10px] text-[#324444]">
          {title}
        </div>

        {rows.length === 0 ? (
          <div className="p-3 text-[11px] text-[#637877]">
            No payment rows found for this shift.
          </div>
        ) : (
          <table className="w-full border-collapse text-[11px]">
            <thead>
              <tr className="border-b border-[#E2E9E9] bg-[#F8FAFC]">
                <th className="px-2 py-2 text-left font-black">Payment</th>
                <th className="px-2 py-2 text-left font-black">Currency</th>
                {canViewAmounts && <th className="px-2 py-2 text-right font-black">Expected</th>}
                <th className="px-2 py-2 text-right font-black">Counted</th>
                {canViewAmounts && <th className="px-2 py-2 text-right font-black">Variance</th>}
              </tr>
            </thead>

            <tbody>
              {rows.map((row, index) => {
                const rowVariance =
                  Number(row.counted_amount || 0) -
                  Number(row.expected_amount || 0);

                return (
                  <tr key={`${row.payment_name}-${row.currency_code}-${index}`}>
                    <td className="border-b border-[#EFF2F2] px-2 py-2">
                      <div className="font-black">{row.payment_name}</div>
                      {row.money_code && (
                        <div className="text-[10px] text-[#637877]">
                          {row.money_code}
                        </div>
                      )}
                    </td>

                    <td className="border-b border-[#EFF2F2] px-2 py-2">
                      {row.currency_code}
                    </td>

                    {canViewAmounts && <td className="border-b border-[#EFF2F2] px-2 py-2 text-right">
                      {Number(row.expected_amount || 0).toFixed(2)}
                    </td>}

                    <td className="border-b border-[#EFF2F2] px-2 py-2">
                      <input
                        type="number"
                        min="0"
                        value={row.counted_amount}
                        onChange={(event) =>
                          updateCountedPayment(mode, index, event.target.value)
                        }
                        className="h-8 w-full border border-[#ACB8B8] px-2 text-right text-[11px] outline-none focus:border-[#069495]"
                      />
                    </td>

                    {canViewAmounts && <td
                      className={`border-b border-[#EFF2F2] px-2 py-2 text-right ${
                        rowVariance < 0 ? "text-[#E30613]" : "text-[#057B7C]"
                      }`}
                    >
                      {rowVariance.toFixed(2)}
                    </td>}
                  </tr>
                );
              })}
            </tbody>
          </table>
        )}
      </div>

      <label className="block">
        <div className="mb-1 text-[10px] text-[#637877]">
          Notes
        </div>

        <textarea
          value={notes}
          onChange={(event) => setNotes(event.target.value)}
          className="min-h-[70px] w-full border border-[#ACB8B8] p-2 text-[11px] outline-none focus:border-[#069495]"
        />
      </label>

      <button
        type="button"
        disabled={saving}
        onClick={onSubmit}
        className={`h-9 w-full border text-[11px] font-black text-white disabled:opacity-50 ${
          danger
            ? "border-[#B8000B] bg-[#E30613]"
            : "border-[#057B7C] bg-[#069495]"
        }`}
      >
        {saving ? "Saving..." : submitLabel}
      </button>
    </div>
  );
};

const PaymentSummaryTable = ({ rows, canViewAmounts = false }) => (
  <div className="border border-[#ACB8B8] bg-white">
    <div className="border-b border-[#ACB8B8] bg-[#F5F7FA] px-3 py-2 text-[10px] text-[#324444]">
      Payment Breakdown
    </div>

    {rows.length === 0 ? (
      <div className="p-3 text-[11px] text-[#637877]">
        No payments found for this shift.
      </div>
    ) : (
      <table className="w-full border-collapse text-[11px]">
        <thead>
          <tr className="border-b border-[#E2E9E9] bg-[#F8FAFC]">
            <th className="px-2 py-2 text-left font-black">Payment</th>
            <th className="px-2 py-2 text-left font-black">Currency</th>
            <th className="px-2 py-2 text-right font-black">Count</th>
            {canViewAmounts && <th className="px-2 py-2 text-right font-black">Amount</th>}
          </tr>
        </thead>

        <tbody>
          {rows.map((row, index) => (
            <tr key={index} className="border-b border-[#EFF2F2]">
              <td className="px-2 py-2">{row.payment_name}</td>
              <td className="px-2 py-2">{row.currency_code}</td>
              <td className="px-2 py-2 text-right">{row.payment_count}</td>
              {canViewAmounts && <td className="px-2 py-2 text-right">
                {money(row.amount, row.currency_code)}
              </td>}
            </tr>
          ))}
        </tbody>
      </table>
    )}
  </div>
);

const RestrictedCashupAmountsNotice = () => (
  <div className="border border-[#F2C94C] bg-[#FFF8E1] px-3 py-2 text-[11px] font-semibold text-[#7A5200]">
    Financial cashup amounts are restricted for this role. You can still enter the physical count and complete the shift.
  </div>
);

const PanelLoading = ({ label }) => (
  <div className="flex min-h-[160px] flex-col items-center justify-center border border-[#ACB8B8] bg-[#F8FAFC] text-[#637877]">
    <RefreshCw className="mb-2 h-7 w-7 animate-spin" />
    <div className="text-[12px]">{label}</div>
  </div>
);

const ReportSection = ({ title, children }) => (
  <div className="border border-[#ACB8B8] bg-white">
    <div className="border-b border-[#ACB8B8] bg-[#F5F7FA] px-3 py-2 text-[10px] font-black text-[#324444]">
      {title}
    </div>

    <div className="divide-y divide-[#EFF2F2]">{children}</div>
  </div>
);

const ReportRow = ({ label, value, subValue }) => (
  <div className="grid grid-cols-[1fr_auto] gap-2 px-3 py-2 text-[11px]">
    <div>
      <div className="text-[#324444]">{label}</div>
      {subValue && <div className="text-[10px] text-[#637877]">{subValue}</div>}
    </div>

    <div className="font-black text-[#003636]">{value}</div>
  </div>
);

const EmptyText = ({ children }) => (
  <div className="p-3 text-[11px] text-[#637877]">{children}</div>
);

const Metric = ({ label, value, green = false }) => (
  <div className="min-w-0 rounded-lg border border-[#ACB8B8] bg-white p-3 shadow-sm">
    <div className="text-[9px] uppercase text-[#637877]">{label}</div>

    <div
      className={`mt-1 break-words text-[14px] font-black leading-tight ${
        green ? "text-[#057B7C]" : "text-[#003636]"
      }`}
    >
      {value}
    </div>
  </div>
);

const TabButton = ({ children, active, onClick }) => (
  <button
    type="button"
    onClick={onClick}
    className={`border px-3 text-[12px] font-black uppercase tracking-wide transition ${
      active
        ? "border-[#003636] bg-[#003637] text-white shadow-sm"
        : "border-transparent bg-transparent text-[#324444] hover:border-[#ACB8B8] hover:bg-white"
    }`}
  >
    {children}
  </button>
);

const ActionButton = ({ children, onClick, variant = "default" }) => {
  const variants = {
    default: "border-[#ACB8B8] bg-[#E6ECEC] text-[#003636]",
    danger: "border-[#B8000B] bg-[#E30613] text-white",
    warning: "border-[#D9A400] bg-[#FFC107] text-[#5E4300]",
  };

  return (
    <button
      type="button"
      onClick={onClick}
      className={`border text-[10px] font-black uppercase ${variants[variant]}`}
    >
      {children}
    </button>
  );
};

const KeyButton = ({
  children,
  onClick,
  variant = "default",
  large = false,
  disabled = false,
}) => {
  const variants = {
    default: "border-[#ACB8B8] bg-white text-[#003636]",
    navy: "border-[#003636] bg-[#004142] text-white",
    muted: "border-[#ACB8B8] bg-[#E6ECEC] text-[#003636]",
    danger: "border-[#B8000B] bg-[#E30613] text-white",
  };

  return (
    <button
      type="button"
      disabled={disabled}
      onClick={onClick}
      className={`border text-center font-black disabled:opacity-50 ${
        large ? "text-[18px]" : "text-[12px]"
      } min-h-0 ${variants[variant]}`}
    >
      {children}
    </button>
  );
};

export default CreateSaleModal;
