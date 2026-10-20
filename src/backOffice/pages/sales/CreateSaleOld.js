// src/backOffice/pages/sales/CreateSale.jsx

import React, {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
} from "react";

import {
  Banknote,
  CreditCard,
  Delete,
  FileText,
  PackageSearch,
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
import { qzPrintPdfUrl } from "../../../services/QzService";

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

import { getBarcodeScanSetting } from "../../../services/GeneralSettingsService";
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


const money = (value, currency = "USD") =>
  `${currency || "USD"} ${Number(value || 0).toFixed(2)}`;

const createLineId = (productId) => {
  if (typeof crypto !== "undefined" && crypto.randomUUID) {
    return crypto.randomUUID();
  }

  return `${productId}-${Date.now()}`;
};

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

const getSaleResponsePayload = (response) => {
  return response?.data?.data || response?.data || response || {};
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
  return (
    response?.data?.converted_amount ??
    response?.converted_amount ??
    response?.amount ??
    0
  );
};

const CreateSaleModal = ({ open, onClose }) => {
  const barcodeInputRef = useRef(null);
  const conversionCacheRef = useRef({});

  const [products, setProducts] = useState([]);
  const [categories, setCategories] = useState([]);
  const [paymentTypes, setPaymentTypes] = useState([]);
  const [currencies, setCurrencies] = useState([]);

  const [loadingProducts, setLoadingProducts] = useState(false);
  const [loadingCategories, setLoadingCategories] = useState(false);
  const [loadingBarcodeScanSetting, setLoadingBarcodeScanSetting] = useState(false);
  const [barcodeScanEnabled, setBarcodeScanEnabled] = useState(false);
  const [loadingPayments, setLoadingPayments] = useState(false);
  const [loadingCurrencies, setLoadingCurrencies] = useState(false);
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
  const [selectedLineId, setSelectedLineId] = useState(null);
  const [pendingEditLineId, setPendingEditLineId] = useState(null);

  const [rightMode, setRightMode] = useState("browse");

  const [selectedCategoryId, setSelectedCategoryId] = useState("all");
  const [browseTab, setBrowseTab] = useState("categories");
  const [search, setSearch] = useState("");
  const [barcodeInput, setBarcodeInput] = useState("");

  const [selectedPaymentTypeId, setSelectedPaymentTypeId] = useState("");
  const [saleCurrencyCode, setSaleCurrencyCode] = useState("");
  const [amountReceived, setAmountReceived] = useState("");

  const [notice, setNotice] = useState("");
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
      price: line?.price || line?.unitPrice || 0,
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
        price: line?.price || line?.unitPrice || 0,
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
    const received = amountTenderedRequired ? Number(amountReceived || 0) : total;
    const balance = Math.max(total - received, 0);
    const change = Math.max(received - total, 0);

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
    amountTenderedRequired,
    saleCurrencyCode,
    getLineTaxBreakdown,
  ]);

  const showNotice = (message) => {
    setNotice(message);

    window.setTimeout(() => {
      setNotice("");
    }, 2800);
  };

  const focusBarcode = () => {
    window.setTimeout(() => {
      if (barcodeScanEnabled) {
        barcodeInputRef.current?.focus();
      }
    }, 80);
  };

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

  const convertMoney = useCallback(async (from, to, amount) => {
    const cleanFrom = String(from || "").toUpperCase();
    const cleanTo = String(to || "").toUpperCase();
    const numericAmount = Number(amount || 0);

    if (!cleanFrom || !cleanTo) return numericAmount;
    if (cleanFrom === cleanTo) return Number(numericAmount.toFixed(4));

    const cacheKey = `${cleanFrom}:${cleanTo}:${numericAmount.toFixed(4)}`;

    if (conversionCacheRef.current[cacheKey] !== undefined) {
      return conversionCacheRef.current[cacheKey];
    }

    const response = await convertCurrency({
      from: cleanFrom,
      to: cleanTo,
      amount: numericAmount,
    });

    const converted = Number(getConvertedAmount(response) || 0);

    conversionCacheRef.current[cacheKey] = Number(converted.toFixed(4));

    return conversionCacheRef.current[cacheKey];
  }, []);

  const convertCartToCurrency = useCallback(
    async (targetCurrencyCode, oldCurrencyCode = saleCurrencyCode) => {
      if (!targetCurrencyCode) return;

      try {
        setConvertingCurrency(true);

        const updatedLines = await Promise.all(
          cart.map(async (line) => {
            const sourceCurrency =
              line.currency ||
              oldCurrencyCode ||
              saleCurrencyCode ||
              line.baseCurrency ||
              targetCurrencyCode;

            const convertedPrice = await convertMoney(
              line.baseCurrency || sourceCurrency,
              targetCurrencyCode,
              line.basePrice ?? line.price ?? 0
            );

            const convertedDiscount = await convertMoney(
              sourceCurrency,
              targetCurrencyCode,
              line.discount || 0
            );

            /*
             * Critical discount currency fix:
             * getLineDiscount() gives priority to discountPayload.discount_amount.
             * Previously only line.discount was converted, but discountPayload stayed
             * in the old currency. That is why the total discount did not change
             * after changing sale currency.
             */
            let convertedDiscountPayload = line.discountPayload || null;

            if (convertedDiscountPayload) {
              const [
                payloadDiscountAmount,
                payloadGrossAmount,
                payloadNetAmount,
              ] = await Promise.all([
                convertMoney(
                  sourceCurrency,
                  targetCurrencyCode,
                  convertedDiscountPayload.discount_amount || 0
                ),
                convertMoney(
                  sourceCurrency,
                  targetCurrencyCode,
                  convertedDiscountPayload.gross_amount || getLineGross(line)
                ),
                convertMoney(
                  sourceCurrency,
                  targetCurrencyCode,
                  convertedDiscountPayload.net_amount || getLineTotal(line)
                ),
              ]);

              let payloadDiscountValue = convertedDiscountPayload.discount_value;

              /*
               * Percent discount value stays as percentage.
               * Fixed amount/fixed price values are money and must convert.
               */
              if (
                convertedDiscountPayload.discount_basis === "fixed_amount" ||
                convertedDiscountPayload.discount_basis === "fixed_price"
              ) {
                payloadDiscountValue = await convertMoney(
                  sourceCurrency,
                  targetCurrencyCode,
                  convertedDiscountPayload.discount_value || 0
                );
              }

              convertedDiscountPayload = {
                ...convertedDiscountPayload,
                currency: targetCurrencyCode,
                discount_value: Number(payloadDiscountValue || 0),
                discount_amount: Number(payloadDiscountAmount || 0),
                gross_amount: Number(payloadGrossAmount || 0),
                net_amount: Number(payloadNetAmount || 0),
              };
            }

            return {
              ...line,
              price: Number(convertedPrice || 0),
              currency: targetCurrencyCode,
              discount: Number(convertedDiscount || 0),
              discountPayload: convertedDiscountPayload,
            };
          })
        );

        setCart(updatedLines);

        if (amountTenderedRequired && amountReceived) {
          const convertedReceived = await convertMoney(
            oldCurrencyCode || saleCurrencyCode,
            targetCurrencyCode,
            amountReceived
          );

          setAmountReceived(String(Number(convertedReceived).toFixed(2)));
        }

        setSaleCurrencyCode(targetCurrencyCode);
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
    try {
      setLoadingProducts(true);

      const response = await getProducts({
        per_page: 300,
        is_active: true,
      });

      const rawProducts = normalizePaginatedData(response);

      setProducts(
        rawProducts.filter((product) => {
          const price = getStandardPrice(product);
          return product?.is_active !== false && !!price;
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
        setSelectedPaymentTypeId((prev) => prev || rows[0].id);
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

      const base = getBaseCurrency(rows);
      const baseCode = getCurrencyCode(base);

      if (baseCode) {
        setSaleCurrencyCode((prev) => prev || baseCode);
      }
    } catch (error) {
      showNotice(getApiMessage(error, "Failed to load currencies."));
    } finally {
      setLoadingCurrencies(false);
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
      loadBarcodeScanSetting(),
      loadPriceOverrideConfig(),
    ]);
  };

  useEffect(() => {
    if (!selectedPaymentType) return;

    const supportsMulti = paymentSupportsMultiCurrency(selectedPaymentType);
    const requiresTendered = paymentRequiresAmountTendered(selectedPaymentType);
    const defaultCurrency = getPaymentDefaultCurrency(selectedPaymentType);

    if (!supportsMulti && defaultCurrency) {
      handleSaleCurrencyChange(defaultCurrency);
    }

    if (!requiresTendered) {
      setAmountReceived("");
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

      const basePrice = Number(standardPrice.price || 0);
      const baseCurrency = standardPrice.currency || saleCurrencyCode;

      let convertedPrice = basePrice;

      try {
        convertedPrice = await convertMoney(
          baseCurrency,
          saleCurrencyCode,
          basePrice
        );
      } catch (error) {
        showNotice(getApiMessage(error, "Could not convert product price."));
        return null;
      }

      const existingLine = cart.find((line) => line.productId === product.id);
      const targetLineId = existingLine?.lineId || createLineId(product.id);

      setSaleComplete(false);
      setSelectedLineId(targetLineId);

      setCart((prev) => {
        const existing = prev.find((line) => line.productId === product.id);

        if (existing) {
          return prev.map((line) =>
            line.productId === product.id
              ? {
                  ...line,
                  qty: normalizeQty(
                    Number(line.qty || 0) + Number(qty || 0),
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

          price: Number(convertedPrice || 0),
          currency: saleCurrencyCode,
          qty: normalizeQty(qty, allowDecimalQty, decimalPlaces),
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
    [cart, saleCurrencyCode, convertMoney]
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

  const handleProductClick = async (product) => {
    const decimalProduct = isDecimalProduct(product);
    const decimalPlaces = getProductDecimalPlaces(product);
    const startingQty = decimalProduct ? getDecimalStep(decimalPlaces) : 1;

    const lineId = await addProductToCart(product, startingQty);

    if (!lineId) return;

    if (decimalProduct) {
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

  const clearSale = () => {
    setCart([]);
    setSelectedLineId(null);
    setPendingEditLineId(null);
    setAmountReceived("");
    setSelectedPaymentTypeId(paymentTypes?.[0]?.id || "");
    setSaleComplete(false);
    setLastSaleResponse(null);
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
    setLoadQuoteModalOpen(false);
    setCashupPayments([]);
    setEndShiftPayments([]);
    setCashupNotes("");
    setEndShiftNotes("");
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
  };


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
      showNotice("Select payment method first.");
      return;
    }

    setRightMode("payment");
    setActionPanel(null);
  };

  const openAction = (action) => {
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

    updateLine(selectedLine.lineId, {
      qty,
    });

    setActionPanel(null);
    showNotice("Line quantity updated.");
    focusBarcode();
  };

  const openDiscountModal = () => {
    if (!selectedLine) {
      showNotice("Select a sale line first.");
      focusBarcode();
      return;
    }

    setActionPanel(null);
    setDiscountModalOpen(true);
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

  const noSale = () => {
    showNotice("No sale drawer event recorded.");
    focusBarcode();
  };

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

  const updateAmount = useCallback((nextValue) => {
    setAmountReceived(nextValue);
  }, []);

  const appendPaymentValue = useCallback(
    (nextValue) => {
      if (!amountTenderedRequired) return;
      updateAmount(appendDecimalNumber(amountReceived || "", nextValue));
    },
    [amountReceived, updateAmount, amountTenderedRequired]
  );

  const backspacePaymentValue = useCallback(() => {
    if (!amountTenderedRequired) return;
    updateAmount(String(amountReceived || "").slice(0, -1));
  }, [amountReceived, updateAmount, amountTenderedRequired]);

  const clearPaymentValue = useCallback(() => {
    if (!amountTenderedRequired) return;
    updateAmount("");
  }, [updateAmount, amountTenderedRequired]);

  const exactPayment = useCallback(() => {
    if (!amountTenderedRequired) return;
    updateAmount(totals.total.toFixed(2));
  }, [totals.total, updateAmount, amountTenderedRequired]);

  const quickPayment = useCallback(
    (value) => {
      if (!amountTenderedRequired) return;
      updateAmount(String(value));
    },
    [updateAmount, amountTenderedRequired]
  );

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

  const completeSale = useCallback(async () => {
    if (savingSale) return;

    if (cart.length === 0) {
      showNotice("No items in the sale.");
      setRightMode("browse");
      return;
    }

    if (!selectedPaymentType) {
      showNotice("Select payment method first.");
      return;
    }

    if (!saleCurrencyCode) {
      showNotice("Select sale currency first.");
      return;
    }

    if (amountTenderedRequired && totals.received < totals.total) {
      showNotice("Amount received is less than the sale total.");
      return;
    }

    const amountTendered = amountTenderedRequired
      ? Number(amountReceived || 0)
      : Number(totals.total || 0);

    const payload = {
      currency_code: saleCurrencyCode,
      payment_type_id: selectedPaymentType.id,
      amount_tendered: Number(amountTendered.toFixed(4)),
      customer_name: customer.name?.trim() || "Walk-in Customer",
      customer_phone: customer.phone?.trim() || null,
      customer_tax_number: customer.taxNumber?.trim() || null,
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

      setSaleComplete(true);
      setLastSaleResponse(salePayload);

      await loadProducts();

      showNotice(
        response?.message ||
          salePayload?.message ||
          "Sale completed successfully."
      );

      sendCustomerDisplayUpdate({
        status: "completed",
        cart,
        totals,
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
      setSelectedLineId(null);
      setPendingEditLineId(null);
      setAmountReceived("");
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
      resetCustomer();

      const base = getBaseCurrency(currencies);
      const baseCode = getCurrencyCode(base);

      if (baseCode) {
        setSaleCurrencyCode(baseCode);
      }

      focusBarcode();
    } catch (error) {
      showNotice(getApiMessage(error, "Sale failed."));
    } finally {
      setSavingSale(false);
    }
  }, [
    savingSale,
    cart,
    selectedPaymentType,
    saleCurrencyCode,
    amountTenderedRequired,
    totals.received,
    totals.total,
    amountReceived,
    customer,
    handleAutoPrintReceipt,
    currencies,
    getLineDiscount,
    loadedQuotation,
  ]);

  useEffect(() => {
    if (!open) return;

    loadSaleData();
    focusBarcode();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  useEffect(() => {
    if (!open) return;
    if (rightMode !== "payment") return;
    if (!amountTenderedRequired) return;

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
      onClose={() => {
        clearCustomerDisplay();
        onClose?.();
      }}
      title="CREATE SALE"
      subtitle="SERVENEST HOSPITALITY ENTERPRISE"
    >
      <div className="relative h-[calc(100vh-86px)] overflow-hidden bg-[#DFE5E5] text-[#003636] font-black">
        {notice && (
          <div className="absolute left-1/2 top-3 z-[60] -translate-x-1/2 whitespace-pre-line border border-[#003636] bg-[#003637] px-4 py-2 text-[12px] font-black text-white shadow-xl">
            {notice}
          </div>
        )}

        {(convertingCurrency ||
          savingSale ||
          printingReceipt ||
          loadingProducts ||
          loadingShiftSummary ||
          savingCashup ||
          reportsLoading) && (
          <div className="absolute right-3 top-3 z-[60] border border-[#00BCBC] bg-white px-3 py-2 text-[11px] font-black text-[#005A5A] shadow-xl">
            {savingSale
              ? "Saving sale..."
              : printingReceipt
              ? "Printing receipt..."
              : loadingProducts
              ? "Refreshing products..."
              : loadingShiftSummary
              ? "Loading shift..."
              : savingCashup
              ? "Saving..."
              : reportsLoading
              ? "Loading reports..."
              : "Converting currency..."}
          </div>
        )}

        <div className="grid h-full grid-cols-1 gap-2 p-2 xl:grid-cols-[38%_62%]">
          <section className="min-h-0 grid grid-rows-[minmax(0,1fr)_134px_104px_58px] gap-2">
            <div className="flex min-h-0 flex-col border border-[#ACB8B8] bg-white">
              <div className="flex h-9 shrink-0 items-center justify-between border-b border-[#ACB8B8] bg-[#F5F7FA] px-3">
                <div className="text-[11px] font-black">Sale Items</div>

                <div className="flex items-center gap-2">
                  {loadedQuotation?.quote_code && (
                    <span className="border border-[#FEDF89] bg-[#FFFAEB] px-2 py-[2px] text-[9px] font-black uppercase text-[#B54708]">
                      Quote: {loadedQuotation.quote_code}
                    </span>
                  )}

                  <div className="text-[10px] text-[#637877]">
                    {cart.length} line{cart.length === 1 ? "" : "s"} /{" "}
                    {totals.currency}
                  </div>
                </div>
              </div>

              <div className="min-h-0 flex-1 overflow-y-auto">
                {cart.length === 0 ? (
                  <div className="flex h-full flex-col items-center justify-center text-center text-[#637877]">
                    <ShoppingCart className="mb-2 h-8 w-8" />

                    <div className="text-[12px]">
                      Scan barcode or select a product.
                    </div>
                  </div>
                ) : (
                  cart.map((line) => {
                    const selected = selectedLineId === line.lineId;
                    const lineTotal = getLineTotal(line);
                    const lineTax = getLineTax(line);
                    const lineSubtotal = getLineSubtotal(line);
                    const taxSummary = getLineTaxSummary(line);

                    return (
                      <button
                        key={line.lineId}
                        type="button"
                        onClick={() => setSelectedLineId(line.lineId)}
                        className={`w-full border-b border-[#E2E9E9] px-3 py-2 text-left ${
                          selected ? "bg-[#E8F7F5]" : "bg-white"
                        }`}
                      >
                        <div className="flex items-start justify-between gap-2">
                          <div className="min-w-0">
                            <div className="truncate text-[12px] font-black">
                              {line.name}
                            </div>

                            <div className="mt-[2px] text-[10px] text-[#069494]">
                              {formatQty(
                                line.qty,
                                line.unit,
                                line.allowDecimalQty,
                                line.decimalPlaces
                              )}{" "}
                              @ {money(line.price, line.currency)}
                            </div>

                            <div className="mt-[1px] text-[9px] text-[#637877]">
                              {line.sku} / {line.barcode}
                            </div>

                            <div className={`mt-[2px] inline-flex border px-2 py-[1px] text-[9px] font-black uppercase ${
                              taxSummary === 'No Tax'
                                ? 'border-[#FECDCA] bg-[#FEF3F2] text-[#B42318]'
                                : 'border-[#ABEFC6] bg-[#ECFDF3] text-[#067647]'
                            }`}>
                              {taxSummary}
                            </div>

                            {lineTax > 0 && (
                              <div className="mt-[2px] text-[9px] text-[#465757]">
                                Excl: {money(lineSubtotal, line.currency)} / Tax: {money(lineTax, line.currency)}
                              </div>
                            )}

                            {line.priceOverride && (
                              <div className="mt-[2px] inline-flex border border-[#D9A400] bg-[#FFF7D6] px-2 py-[1px] text-[9px] font-black uppercase text-[#5E4300]">
                                Override: {line.priceLevelName || "Price Level"}
                              </div>
                            )}

                            {line.fromQuotation && (
                              <div className="mt-[2px] inline-flex border border-[#A4E5E4] bg-[#F1F8F8] px-2 py-[1px] text-[9px] font-black uppercase text-[#057B7B]">
                                Quotation: {line.quotationCode}
                              </div>
                            )}

                            {Number(getLineDiscount(line) || 0) > 0 && (
                              <div className="mt-[2px] text-[9px] text-[#E30613]">
                                Discount: -{money(getLineDiscount(line), line.currency)}
                                {line.discountPayload?.discount_name
                                  ? ` / ${line.discountPayload.discount_name}`
                                  : ""}
                              </div>
                            )}
                          </div>

                          <div className="shrink-0 text-right">
                            <div className="text-[12px] font-black">
                              {money(lineTotal, line.currency)}
                            </div>

                            {selected && (
                              <div className="mt-1 text-[9px] text-[#069495]">
                                Selected
                              </div>
                            )}
                          </div>
                        </div>
                      </button>
                    );
                  })
                )}
              </div>
            </div>

            <div className="border border-[#ACB8B8] bg-[#EAF5F4] px-4 py-2">
              <div className="grid grid-cols-[1fr_120px] gap-y-1 text-[11px]">
                <div className="text-[#324444]">Sub Total Excl Tax</div>
                <div className="text-right font-black">
                  {money(totals.subTotal, totals.currency)}
                </div>

                <div className="text-[#324444]">Discount</div>
                <div className="text-right font-black text-[#069494]">
                  -{money(totals.discount, totals.currency)}
                </div>

                {/* <div className="text-[#324444]">Inclusive Tax</div>
                <div className="text-right font-black text-[#027A48]">
                  {money(totals.inclusiveTax, totals.currency)}
                </div>

                <div className="text-[#324444]">Exclusive Tax</div>
                <div className="text-right font-black text-[#B54708]">
                  {money(totals.exclusiveTax, totals.currency)}
                </div> */}

                <div className="text-[#324444]">Total Tax</div>
                <div className="text-right font-black">
                  {money(totals.tax, totals.currency)}
                </div>

                <div className="mt-1 text-[17px] font-black">Total</div>
                <div className="mt-1 text-right text-[17px] font-black">
                  {money(totals.total, totals.currency)}
                </div>
              </div>
            </div>

            <div className="grid grid-cols-4 gap-1 border border-[#ACB8B8] bg-white p-1">
              <ActionButton onClick={voidSale} variant="danger">
                Clear 
              </ActionButton>

              <ActionButton onClick={removeSelectedLine} variant="danger">
                Del Line
              </ActionButton>

              <ActionButton onClick={() => openAction("editLine")}>
                Edit Line
              </ActionButton>

              <ActionButton onClick={openDiscountModal}>
                Discount
              </ActionButton>

              <ActionButton onClick={openCashup}>Cashup</ActionButton>

              <ActionButton onClick={noSale}>No Sale</ActionButton>

              <ActionButton onClick={() => openAction("customer")}>
                Customer
              </ActionButton>

              <ActionButton onClick={openMoreFunctions} variant="warning">
                More
              </ActionButton>
            </div>

            <div className="grid grid-cols-[1fr_1fr] gap-2">
              <button
                type="button"
                onClick={clearSale}
                className="border border-[#ACB8B8] bg-white text-[11px] font-black uppercase text-[#003636]"
              >
                Clear Sale
              </button>

              <button
                type="button"
                onClick={handleProcess}
                className="border border-[#057B7C] bg-[#069495] text-[12px] font-black uppercase text-white"
              >
                Process
              </button>
            </div>
          </section>

          <section className="relative min-h-0 overflow-hidden border border-[#ACB8B8] bg-white">
            <div
              className={`absolute inset-0 flex flex-col transition-all duration-300 ease-out ${
                rightMode === "browse"
                  ? "translate-x-0 opacity-100"
                  : "pointer-events-none -translate-x-8 opacity-0"
              }`}
            >
              <div className="shrink-0 border-b border-[#ACB8B8] bg-[#F5F7FA] p-2">
                <form
                  onSubmit={handleBarcodeSubmit}
                  className="grid grid-cols-1 gap-2 lg:grid-cols-[1fr_330px]"
                >
                  <div className="relative">
                    <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[#637877]" />

                    <input
                      type="text"
                      value={search}
                      onChange={(event) => setSearch(event.target.value)}
                      placeholder="Search product, SKU, barcode, category..."
                      className="h-10 w-full border border-[#ACB8B8] bg-white pl-9 pr-3 text-[12px] outline-none focus:border-[#069495]"
                    />
                  </div>

                  <div className="relative">
                    <QrCode
                      className={`absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 ${
                        barcodeScanEnabled ? "text-[#069495]" : "text-[#93A6A6]"
                      }`}
                    />

                    <input
                      ref={barcodeInputRef}
                      type="text"
                      value={barcodeInput}
                      onChange={handleBarcodeChange}
                      disabled={!barcodeScanEnabled}
                      placeholder={
                        loadingBarcodeScanSetting
                          ? "Checking barcode setting..."
                          : barcodeScanEnabled
                          ? "Scan or type barcode..."
                          : "Barcode scan disabled in General Settings"
                      }
                      className={`h-10 w-full border pl-9 pr-3 text-[12px] font-black outline-none ${
                        barcodeScanEnabled
                          ? "border-[#069495] bg-[#E8F7F5] text-[#003636]"
                          : "cursor-not-allowed border-[#CBD6D6] bg-[#E4E8E8] text-[#637877]"
                      }`}
                    />

                    {!barcodeScanEnabled && !loadingBarcodeScanSetting && (
                      <div className="absolute right-2 top-1/2 -translate-y-1/2 text-[9px] font-black uppercase text-[#B45309]">
                        Disabled
                      </div>
                    )}
                  </div>
                </form>
              </div>

              <div className="flex min-h-0 flex-1 flex-col p-2">
                <div className="mb-2 grid h-9 shrink-0 grid-cols-2 gap-2 rounded-none border border-[#ACB8B8] bg-[#F8FAFC] p-1">
                  <TabButton
                    active={browseTab === "categories"}
                    onClick={() => setBrowseTab("categories")}
                  >
                    Categories
                  </TabButton>

                  <TabButton
                    active={browseTab === "products"}
                    onClick={() => setBrowseTab("products")}
                  >
                    Products
                    {selectedCategoryId !== "all"
                      ? ` / ${selectedCategory?.name}`
                      : ""}
                  </TabButton>
                </div>

                <div className="min-h-0 flex-1 overflow-hidden border border-[#ACB8B8] bg-white">
                  {browseTab === "categories" && (
                    <CategoryGrid
                      categoryOptions={categoryOptions}
                      selectedCategoryId={selectedCategoryId}
                      products={products}
                      loadingCategories={loadingCategories}
                      handleCategorySelect={handleCategorySelect}
                      loadSaleData={loadSaleData}
                    />
                  )}

                  {browseTab === "products" && (
                    <ProductTable
                      loadingProducts={loadingProducts}
                      filteredProducts={filteredProducts}
                      selectedCategory={selectedCategory}
                      selectedCategoryId={selectedCategoryId}
                      setSelectedCategoryId={setSelectedCategoryId}
                      setBrowseTab={setBrowseTab}
                      focusBarcode={focusBarcode}
                      handleProductClick={handleProductClick}
                      saleCurrencyCode={saleCurrencyCode}
                      convertMoney={convertMoney}
                    />
                  )}
                </div>
              </div>
            </div>

            <div
              className={`absolute inset-0 flex flex-col transition-all duration-300 ease-out ${
                rightMode === "payment"
                  ? "translate-x-0 opacity-100"
                  : "pointer-events-none translate-x-8 opacity-0"
              }`}
            >
              <PaymentPanel
                totals={totals}
                amountReceived={amountReceived}
                paymentTypes={paymentTypes}
                selectedPaymentTypeId={selectedPaymentTypeId}
                setSelectedPaymentTypeId={setSelectedPaymentTypeId}
                selectedPaymentType={selectedPaymentType}
                currencies={currencies}
                saleCurrencyCode={saleCurrencyCode}
                handleSaleCurrencyChange={handleSaleCurrencyChange}
                paymentCanChooseCurrency={paymentCanChooseCurrency}
                amountTenderedRequired={amountTenderedRequired}
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
            </div>
          </section>
        </div>

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

      <TouchKeyboardOverlay
        settingKey="touch_keyboard_enabled"
        module="sales"
        title="Touch Keyboard"
      />
</FullScreenModal>
  );
};


const LoadQuotationModal = ({
  open,
  value,
  loading,
  onChange,
  onClose,
  onSubmit,
}) => {
  const [localValue, setLocalValue] = useState(value || "");

  useEffect(() => {
    if (open) {
      setLocalValue(value || "");
    }
  }, [open, value]);

  if (!open) return null;

  const submit = (event) => {
    event.preventDefault();

    const code = String(localValue || "").trim();
    onChange?.(code);
    onSubmit?.(code);
  };

  return (
    <div className="absolute inset-0 z-[90] flex items-center justify-center bg-[#003637]/55 p-4">
      <form
        onSubmit={submit}
        className="w-full max-w-[440px] border border-[#ACB8B8] bg-white shadow-2xl"
      >
        <div className="flex h-11 items-center justify-between border-b border-[#ACB8B8] bg-[#F5F7FA] px-4">
          <div className="flex items-center gap-2 text-[12px] font-black uppercase text-[#003636]">
            <FileText className="h-4 w-4" />
            Load Quotation
          </div>

          <button
            type="button"
            onClick={onClose}
            className="h-7 w-7 border border-[#ACB8B8] bg-white text-[14px] text-[#003636]"
          >
            ×
          </button>
        </div>

        <div className="p-4">
          <div className="mb-2 text-[11px] font-bold text-[#465757]">
            Enter the quotation number/code. The sale will load the quoted
            products, quantities, price overrides and discounts.
          </div>

          <input
            autoFocus
            type="text"
            value={localValue}
            onChange={(event) => setLocalValue(event.target.value.toUpperCase())}
            placeholder="Example: QT-KM-260520-0001"
            className="h-11 w-full border border-[#ACB8B8] px-3 text-[13px] font-black uppercase text-[#003636] outline-none focus:border-[#069495]"
          />

          <div className="mt-4 grid grid-cols-2 gap-2">
            <button
              type="button"
              onClick={onClose}
              className="h-10 border border-[#ACB8B8] bg-white text-[11px] font-black uppercase text-[#003636]"
            >
              Cancel
            </button>

            <button
              type="submit"
              disabled={loading}
              className="h-10 border border-[#057B7C] bg-[#069495] text-[11px] font-black uppercase text-white disabled:opacity-50"
            >
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

const PaymentPanel = ({
  totals,
  amountReceived,
  paymentTypes,
  selectedPaymentTypeId,
  setSelectedPaymentTypeId,
  selectedPaymentType,
  currencies,
  saleCurrencyCode,
  handleSaleCurrencyChange,
  paymentCanChooseCurrency,
  amountTenderedRequired,
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
  const canComplete = amountTenderedRequired
    ? totals.received >= totals.total
    : totals.total > 0;

  return (
    <div className="flex h-full min-h-0 flex-col bg-white">
      <div className="shrink-0 border-b border-[#ACB8B8] bg-[#F5F7FA] p-2">
        <div className="grid grid-cols-2 gap-2 lg:grid-cols-[1fr_220px]">
          <div className="grid grid-cols-2 gap-1 md:grid-cols-4 xl:grid-cols-5">
            {loadingPayments ? (
              <div className="col-span-full flex h-12 items-center justify-center border border-[#CAD5D5] bg-white text-[11px]">
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
                    onClick={() => setSelectedPaymentTypeId(method.id)}
                    className={`flex h-12 items-center justify-center gap-1 border text-[10px] font-black uppercase ${
                      active
                        ? "border-[#003636] bg-[#004142] text-white"
                        : "border-[#CAD5D5] bg-[#E6ECEC] text-[#003636]"
                    }`}
                  >
                    <Icon className="h-4 w-4" />
                    {method.name}
                  </button>
                );
              })
            )}
          </div>

          <div className="border border-[#ACB8B8] bg-white p-2">
            <div className="mb-1 text-[9px] text-[#637877]">Sale Currency</div>

            <select
              value={saleCurrencyCode}
              disabled={!paymentCanChooseCurrency || loadingCurrencies}
              onChange={(event) => handleSaleCurrencyChange(event.target.value)}
              className="h-8 w-full border border-[#ACB8B8] bg-white px-2 text-[11px] outline-none disabled:bg-[#E4E8E8]"
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

            <div className="mt-1 text-[9px] text-[#637877]">
              {selectedPaymentType
                ? paymentCanChooseCurrency
                  ? "Multi-currency enabled"
                  : `Fixed currency: ${
                      getPaymentDefaultCurrency(selectedPaymentType) ||
                      saleCurrencyCode
                    }`
                : "Select payment method"}
            </div>
          </div>
        </div>
      </div>

      <div className="grid min-h-0 flex-1 grid-cols-1 gap-2 p-2 xl:grid-cols-[270px_1fr]">
        <div className="grid min-h-0 grid-rows-[auto_auto_auto_minmax(0,1fr)] gap-2">
          <div className="border border-[#ACB8B8] bg-[#004142] p-4 text-white">
            <div className="text-[10px] uppercase text-white/70">
              Amount Due
            </div>

            <div className="mt-1 text-[38px] font-black leading-none">
              {money(totals.total, totals.currency)}
            </div>
          </div>

          <div className="grid grid-cols-2 gap-2">
            <Metric
              label={amountTenderedRequired ? "Received" : "Auto Paid"}
              value={money(totals.received, totals.currency)}
            />

            <Metric
              label="Change"
              value={money(totals.change, totals.currency)}
              green
            />
          </div>

          <div className="border border-[#ACB8B8] bg-[#F8FAFC] p-3">
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
            <div className="border border-[#ACB8B8] bg-white p-3">
              <div className="grid grid-cols-[1fr_100px] gap-y-1 text-[11px]">
                <div>Subtotal</div>
                <div className="text-right font-black">
                  {money(totals.subTotal, totals.currency)}
                </div>

                <div>Discount</div>
                <div className="text-right font-black text-[#069494]">
                  -{money(totals.discount, totals.currency)}
                </div>

                <div>Tax</div>
                <div className="text-right font-black">
                  {money(totals.tax, totals.currency)}
                </div>

                <div className="mt-2 text-[14px] font-black">Total</div>
                <div className="mt-2 text-right text-[14px] font-black">
                  {money(totals.total, totals.currency)}
                </div>
              </div>
            </div>
          )}
        </div>

        <div className="flex min-h-0 flex-col border border-[#ACB8B8] bg-white">
          <div className="grid h-14 shrink-0 grid-cols-[1fr_120px] border-b border-[#ACB8B8]">
            <input
              data-payment-input="true"
              type="text"
              value={
                amountTenderedRequired
                  ? amountReceived || ""
                  : totals.total.toFixed(2)
              }
              disabled={!amountTenderedRequired}
              onChange={(event) => quickPayment(event.target.value)}
              placeholder="0.00"
              className="h-full px-4 text-right text-[32px] font-black text-[#004141] outline-none disabled:bg-[#E4E8E8]"
            />

            <button
              type="button"
              onClick={exactPayment}
              disabled={!amountTenderedRequired}
              className="border-l border-[#057B7C] bg-[#069495] text-[11px] font-black uppercase text-white disabled:opacity-50"
            >
              Exact
            </button>
          </div>

          <div className="grid min-h-[390px] flex-1 grid-cols-4 gap-2 p-2">
            <KeyButton
              onClick={() => quickPayment("5")}
              variant="navy"
              disabled={!amountTenderedRequired}
            >
              5
            </KeyButton>

            <KeyButton
              onClick={() => quickPayment("10")}
              variant="navy"
              disabled={!amountTenderedRequired}
            >
              10
            </KeyButton>

            <KeyButton
              onClick={() => quickPayment("20")}
              variant="navy"
              disabled={!amountTenderedRequired}
            >
              20
            </KeyButton>

            <KeyButton
              onClick={clearPaymentValue}
              variant="muted"
              disabled={!amountTenderedRequired}
            >
              Clear
            </KeyButton>

            {["7", "8", "9"].map((key) => (
              <KeyButton
                key={key}
                onClick={() => appendPaymentValue(key)}
                large
                disabled={!amountTenderedRequired}
              >
                {key}
              </KeyButton>
            ))}

            <KeyButton
              onClick={() => quickPayment("50")}
              variant="navy"
              disabled={!amountTenderedRequired}
            >
              50
            </KeyButton>

            {["4", "5", "6"].map((key) => (
              <KeyButton
                key={key}
                onClick={() => appendPaymentValue(key)}
                large
                disabled={!amountTenderedRequired}
              >
                {key}
              </KeyButton>
            ))}

            <KeyButton
              onClick={() => quickPayment("100")}
              variant="navy"
              disabled={!amountTenderedRequired}
            >
              100
            </KeyButton>

            {["1", "2", "3"].map((key) => (
              <KeyButton
                key={key}
                onClick={() => appendPaymentValue(key)}
                large
                disabled={!amountTenderedRequired}
              >
                {key}
              </KeyButton>
            ))}

            <KeyButton
              onClick={backspacePaymentValue}
              variant="muted"
              disabled={!amountTenderedRequired}
            >
              <Delete className="mx-auto h-5 w-5" />
            </KeyButton>

            <KeyButton
              onClick={() => appendPaymentValue("00")}
              large
              disabled={!amountTenderedRequired}
            >
              00
            </KeyButton>

            <KeyButton
              onClick={() => appendPaymentValue("0")}
              large
              disabled={!amountTenderedRequired}
            >
              0
            </KeyButton>

            <KeyButton
              onClick={() => appendPaymentValue(".")}
              large
              disabled={!amountTenderedRequired}
            >
              .
            </KeyButton>

            <KeyButton onClick={onBack} variant="danger">
              Back
            </KeyButton>

            <button
              type="button"
              disabled={!canComplete || saleComplete || savingSale || printingReceipt}
              onClick={completeSale}
              className="col-span-4 border border-[#00883F] bg-[#00A651] text-[18px] font-black uppercase text-white disabled:cursor-not-allowed disabled:opacity-50"
            >
              {savingSale
                ? "Saving Sale..."
                : printingReceipt
                ? "Printing..."
                : "Complete Sale"}
            </button>
          </div>

          <div className="shrink-0 border-t border-[#ACB8B8] bg-[#F8FAFC] px-3 py-2 text-[10px] text-[#637877]">
            {amountTenderedRequired
              ? "Keyboard: numbers to type, Backspace to delete, Enter to complete, Esc to go back."
              : "This payment type does not require amount tendered. Amount is auto-paid."}
          </div>
        </div>
      </div>
    </div>
  );
};

const ActionPanel = ({
  actionPanel,
  selectedLine,
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

  return (
    <div className="absolute inset-0 z-[70] flex items-center justify-center bg-[#003637]/50 p-3">
      <div className="w-full max-w-3xl border border-[#ACB8B8] bg-white shadow-2xl">
        <div className="flex h-10 items-center justify-between border-b border-[#ACB8B8] bg-[#F5F7FA] px-3">
          <div className="text-[12px] font-black text-[#003636]">
            {title}
          </div>

          <button
            type="button"
            onClick={close}
            className="h-7 w-7 border border-[#ACB8B8] bg-white text-[14px] text-[#003636]"
          >
            ×
          </button>
        </div>

        <div className="max-h-[78vh] overflow-y-auto p-3 text-[11px]">
          {actionPanel === "moreFunctions" && (
            <div className="grid grid-cols-4 gap-1 border border-[#ACB8B8] bg-white p-1">
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
                Manager
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
                Reprint
              </MoreButton>

              <MoreButton onClick={() => {}}>
                Hold
              </MoreButton>

              <MoreButton onClick={noSale}>
                No Sale
              </MoreButton>

              <MoreButton onClick={openCustomerScreen}>
                Customer Display
              </MoreButton>
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

const MoreButton = ({ children, onClick, variant = "default" }) => {
  const variants = {
    default: "border-[#ACB8B8] bg-[#E6ECEC] text-[#003636]",
    danger: "border-[#B8000B] bg-[#E30613] text-white",
    warning: "border-[#D9A400] bg-[#FFC107] text-[#5E4300]",
  };

  return (
    <button
      type="button"
      onClick={onClick}
      className={`min-h-[44px] border text-[10px] font-black uppercase ${variants[variant]}`}
    >
      {children}
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
  <div className="border border-[#ACB8B8] bg-white p-3">
    <div className="text-[9px] uppercase text-[#637877]">{label}</div>

    <div
      className={`mt-1 truncate text-[14px] font-black ${
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
        large ? "text-[22px]" : "text-[13px]"
      } ${variants[variant]}`}
    >
      {children}
    </button>
  );
};

export default CreateSaleModal;
