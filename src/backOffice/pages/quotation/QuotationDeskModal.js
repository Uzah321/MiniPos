import React, { useEffect, useMemo, useRef, useState } from "react";
import {
  ClipboardList,
  FileText,
  PackageSearch,
  Plus,
  Printer,
  RefreshCw,
  Search,
  X,
} from "lucide-react";

import FullScreenModal from "../../../components/FullScreenModal";
import { requestEnterpriseConfirmation } from "../../../components/EnterpriseConfirmHost";
import { getProducts } from "../../../services/ProductService";
import { getCurrencies, convertCurrency } from "../../../services/CurrencyService";
import {
  saveQuotation,
  getQuotations,
  getQuotation,
  issueQuotation,
  cancelQuotation,
} from "../../../services/QuotationService";
import DiscountApplyModal from "../../../components/DiscountApplyModal";
import PriceOverrideModal from "../../../components/PriceOverrideModal";
import { getAuthorisedCompany } from "../../../services/AuthenticationService";
import { API_BASE_URL } from "../../../utils/apiConfig";

// ----------------------------------------------------------------------
// Helper functions (unchanged from original, but included for completeness)
// ----------------------------------------------------------------------

const numberValue = (value) => Number(value || 0);
const moneyNumber = (value) => Number(value || 0).toFixed(2);
const money = (value, currency = "USD") => `${currency || "USD"} ${moneyNumber(value)}`;
const todayPlusDays = (days = 7) => new Date(Date.now() + days * 86400000).toISOString().slice(0, 10);
const boolValue = (value) => value === true || value === 1 || value === "1" || value === "true";
const normalizeText = (value) => String(value || "").trim().toLowerCase();

const POS_PRODUCT_TYPES = ["simple", "child", "parent", "finished_good"];

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

const normalizeRows = (response) => {
  const payload = response?.data ?? response;
  if (Array.isArray(payload)) return payload;
  if (Array.isArray(payload?.data)) return payload.data;
  if (Array.isArray(payload?.data?.data)) return payload.data.data;
  if (Array.isArray(payload?.items)) return payload.items;
  if (Array.isArray(payload?.currencies)) return payload.currencies;
  return [];
};

const getPayloadData = (response) => response?.data?.data || response?.data || response || null;

const getErrorMessage = (error, fallback) => {
  const data = error?.response?.data;
  if (data?.errors && typeof data.errors === "object") {
    return Object.values(data.errors).flat().join("\n");
  }
  return data?.message || data?.error || error?.message || fallback;
};

const getConvertedAmount = (response) => response?.data?.converted_amount ?? response?.converted_amount ?? response?.amount ?? 0;
const getCurrencyCode = (currency) => currency?.code || currency?.currency_code || "";
const getCurrencyName = (currency) => currency?.name || currency?.currency_name || currency?.code || "";
const getBaseCurrency = (currencies) => currencies.find((c) => boolValue(c?.is_base_currency)) || currencies.find((c) => boolValue(c?.is_active)) || currencies[0] || null;

const safeJsonParse = (value) => { try { return JSON.parse(value); } catch { return null; } };

const getLoggedInUser = () => {
  const keys = ["pos_user", "user", "auth_user", "logged_user", "loggedInUser", "salexa_user"];
  for (const key of keys) {
    const parsed = safeJsonParse(localStorage.getItem(key));
    if (parsed?.name || parsed?.username) return parsed;
    if (parsed?.user?.name || parsed?.user?.username) return parsed.user;
    if (parsed?.data?.name || parsed?.data?.username) return parsed.data;
  }
  for (const key of keys) {
    const parsed = safeJsonParse(sessionStorage.getItem(key));
    if (parsed?.name || parsed?.username) return parsed;
    if (parsed?.user?.name || parsed?.user?.username) return parsed.user;
    if (parsed?.data?.name || parsed?.data?.username) return parsed.data;
  }
  return null;
};

const getLoggedInBranchId = () => {
  const keys = ["pos_user", "user", "auth_user", "logged_user", "loggedInUser", "salexa_user", "back_user", "adminUser", "currentUser"];
  for (const key of keys) {
    const localParsed = safeJsonParse(localStorage.getItem(key));
    const sessionParsed = safeJsonParse(sessionStorage.getItem(key));
    for (const parsed of [localParsed, sessionParsed]) {
      if (!parsed) continue;
      if (parsed?.selectedBranchId) return parsed.selectedBranchId;
      if (parsed?.branch_id) return parsed.branch_id;
      if (parsed?.branch?.id) return parsed.branch.id;
      if (parsed?.selectedBranch?.id) return parsed.selectedBranch.id;
      if (parsed?.data?.selectedBranchId) return parsed.data.selectedBranchId;
      if (parsed?.data?.branch_id) return parsed.data.branch_id;
      if (parsed?.data?.branch?.id) return parsed.data.branch.id;
      if (parsed?.data?.selectedBranch?.id) return parsed.data.selectedBranch.id;
      if (parsed?.user?.branch_id) return parsed.user.branch_id;
      if (parsed?.user?.branch?.id) return parsed.user.branch.id;
    }
  }
  return null;
};

const buildLogoUrl = (logo) => {
  if (!logo) return "";
  if (/^https?:\/\//i.test(logo)) return logo;
  const baseUrl = API_BASE_URL;
  if (!baseUrl) return `/${String(logo).replace(/^\/+/, "")}`;
  return `${String(baseUrl).replace(/\/+$/, "")}/${String(logo).replace(/^\/+/, "")}`;
};

const getCompanyPayload = (response) => response?.company || response?.data?.company || response?.data || response || null;
const resolveCompanyBranch = (company) => {
  const branches = Array.isArray(company?.branches) ? company.branches : [];
  const loggedBranchId = getLoggedInBranchId();
  return branches.find((b) => String(b.id) === String(loggedBranchId)) || branches.find((b) => boolValue(b.is_main_branch)) || branches[0] || null;
};

const getCompanyPrintProfile = (company, branch) => {
  const contact = Array.isArray(branch?.contacts) ? branch.contacts[0] : null;
  const companyName = company?.company_name || company?.name || "Company";
  const branchName = branch?.branch_name || branch?.name || "Main Branch";
  const companyAddress = [company?.location_address, company?.city, company?.country].filter(Boolean).join(", ");
  const branchAddress = [branch?.branch_address, branch?.branch_city, branch?.branch_country].filter(Boolean).join(", ");
  return {
    companyName, branchName, branchCode: branch?.branch_code || branch?.code || "",
    companyAddress, branchAddress, domain: company?.domain || "",
    logoUrl: buildLogoUrl(company?.logo),
    phone: contact?.phone || company?.phone || "",
    email: contact?.email || company?.email || "",
  };
};

const escapeHtml = (value) => String(value ?? "").replaceAll("&", "&amp;").replaceAll("<", "&lt;").replaceAll(">", "&gt;").replaceAll('"', "&quot;").replaceAll("'", "&#039;");

const emptyForm = () => {
  const user = getLoggedInUser();
  return {
    id: null, quote_code: "", customer_id: null, customer_name: "", customer_phone: "", customer_email: "",
    currency: "USD", valid_until: todayPlusDays(7), notes: "", status: "draft",
    sales_person_id: user?.id || null, sales_person: user?.name || user?.username || "",
    selected_terms: "Cash / On Collection",
  };
};

const getPriceLevelName = (price) => price?.price_level_name || price?.price_level?.name || price?.priceLevel?.name || "Standard";
const getPriceLevelId = (price) => price?.price_level_id || price?.priceLevelId || price?.price_level?.id || price?.priceLevel?.id || null;
const isStandardPrice = (price) => normalizeText(price?.price_level?.name) === "standard" || normalizeText(price?.priceLevel?.name) === "standard";

const getProductPrice = (product, fallbackCurrency = "USD") => {
  const prices = Array.isArray(product?.prices) ? product.prices : [];
  const selectedPrice = prices.find(isStandardPrice) || prices.find((p) => boolValue(p?.is_default)) || prices.find((p) => boolValue(p?.is_active)) || prices[0];
  return {
    price: Number(selectedPrice?.price || product?.price || product?.selling_price || product?.default_price || product?.standard_price || 0),
    currency: selectedPrice?.currency || product?.currency || fallbackCurrency,
    product_price_id: selectedPrice?.id || product?.product_price_id || null,
    price_level_id: getPriceLevelId(selectedPrice) || product?.price_level_id || null,
    price_level_name: getPriceLevelName(selectedPrice),
  };
};

const getProductUnit = (product) => product?.uom?.code || product?.uom?.name || "EACH";
const getProductAvailableStock = (product) => Array.isArray(product?.stocks) ? product.stocks.reduce((s, st) => s + Number(st.available_quantity ?? st.quantity_on_hand ?? 0), 0) : 0;
const getProductTaxRows = (product) => {
  if (Array.isArray(product?.taxes) && product.taxes.length) return product.taxes;
  if (Array.isArray(product?.product_taxes) && product.product_taxes.length) return product.product_taxes;
  if (Array.isArray(product?.productTaxes) && product.productTaxes.length) return product.productTaxes;
  return [];
};
const getTaxObject = (row) => row?.tax || row?.taxe || row?.tax_info || row || {};
const normaliseTaxRow = (row) => {
  const tax = getTaxObject(row);
  return {
    product_tax_id: row?.id || row?.product_tax_id || null,
    tax_id: row?.tax_id || tax?.id || row?.id || null,
    fdms_tax_id: tax?.tax_id ?? row?.fdms_tax_id ?? row?.fdmsTaxId ?? null,
    name: row?.tax_name || tax?.name || row?.name || "Tax",
    code: row?.tax_code || tax?.tax_code || row?.code || "",
    type: row?.tax_type || tax?.type || row?.type || "percentage",
    rate: Number(row?.tax_rate ?? tax?.rate ?? row?.rate ?? 0),
    is_inclusive: boolValue(row?.is_inclusive ?? row?.inclusive ?? false),
    obliged_to_zimra: boolValue(row?.obliged_to_zimra ?? tax?.obliged_to_zimra ?? false),
    is_active: row?.is_active === undefined ? true : boolValue(row?.is_active),
  };
};

const getLineTaxes = (line) => {
  if (Array.isArray(line?.taxes) && line.taxes.length) return line.taxes.map(normaliseTaxRow).filter(t => t.is_active);
  if (Array.isArray(line?.productTaxes) && line.productTaxes.length) return line.productTaxes.map(normaliseTaxRow).filter(t => t.is_active);
  if (line?.rawProduct) return getProductTaxRows(line.rawProduct).map(normaliseTaxRow).filter(t => t.is_active);
  if (line?.taxable && Number(line?.taxRate || 0) > 0) return [{ product_tax_id: null, tax_id: line.tax_id || null, fdms_tax_id: null, name: "Tax", code: "TAX", type: "percentage", rate: Number(line.taxRate || 0) * 100, is_inclusive: false, obliged_to_zimra: false, is_active: true }];
  return [];
};

const getLineGross = (line) => Number(line?.qty || 0) * Number(line?.price || 0);
const getLineDiscount = (line) => Math.min(Math.max(Number(line?.discount || line?.discountPayload?.discount_amount || 0), 0), getLineGross(line));

const calculateLineTax = (line) => {
  const gross = getLineGross(line);
  const discount = getLineDiscount(line);
  const amountAfterDiscount = Math.max(gross - discount, 0);
  const quantity = Number(line?.qty || 0);
  const taxes = getLineTaxes(line);
  if (!taxes.length) return { gross, discount, taxable_base: amountAfterDiscount, subtotal_excl_tax: amountAfterDiscount, inclusive_tax: 0, exclusive_tax: 0, tax_total: 0, line_total: amountAfterDiscount, taxes: [], tax_summary: "No Tax" };
  const inclusiveFixedTotal = taxes.reduce((s, t) => t.is_inclusive && t.type === "fixed" ? s + Number(t.rate) * quantity : s, 0);
  const inclusivePercentageRate = taxes.reduce((s, t) => t.is_inclusive && t.type === "percentage" ? s + Number(t.rate) / 100 : s, 0);
  const taxableBase = inclusivePercentageRate > 0 ? Math.max((amountAfterDiscount - inclusiveFixedTotal) / (1 + inclusivePercentageRate), 0) : Math.max(amountAfterDiscount - inclusiveFixedTotal, 0);
  let inclusiveTax = 0, exclusiveTax = 0;
  const taxRows = taxes.map(t => {
    const taxAmount = t.type === "fixed" ? Number(t.rate) * quantity : taxableBase * (Number(t.rate) / 100);
    if (t.is_inclusive) inclusiveTax += taxAmount; else exclusiveTax += taxAmount;
    return { product_tax_id: t.product_tax_id, tax_id: t.tax_id, fdms_tax_id: t.fdms_tax_id, name: t.name, code: t.code, type: t.type, rate: Number(t.rate), is_inclusive: t.is_inclusive, obliged_to_zimra: t.obliged_to_zimra, taxable_amount: Number(taxableBase.toFixed(4)), tax_amount: Number(taxAmount.toFixed(4)), sales_amount_with_tax: Number((t.is_inclusive ? amountAfterDiscount : taxableBase + taxAmount).toFixed(4)) };
  });
  const taxTotal = inclusiveTax + exclusiveTax;
  const lineTotal = amountAfterDiscount + exclusiveTax;
  return { gross: Number(gross.toFixed(4)), discount: Number(discount.toFixed(4)), amount_after_discount: Number(amountAfterDiscount.toFixed(4)), taxable_base: Number(taxableBase.toFixed(4)), subtotal_excl_tax: Number(taxableBase.toFixed(4)), inclusive_tax: Number(inclusiveTax.toFixed(4)), exclusive_tax: Number(exclusiveTax.toFixed(4)), tax_total: Number(taxTotal.toFixed(4)), line_total: Number(lineTotal.toFixed(4)), taxes: taxRows, tax_summary: taxRows.map(t => `${t.code || t.name} ${t.type === "percentage" ? `${Number(t.rate).toFixed(2)}%` : moneyNumber(t.rate)} ${t.is_inclusive ? "Incl" : "Excl"}`).join(" + ") };
};

const productTaxSummary = (product) => {
  const taxes = getProductTaxRows(product).map(normaliseTaxRow).filter(t => t.is_active);
  if (!taxes.length) return "No Tax";
  return taxes.map(t => `${t.code || t.name} ${t.type === "percentage" ? `${Number(t.rate).toFixed(2)}%` : moneyNumber(t.rate)} ${t.is_inclusive ? "Incl" : "Excl"}`).join(" + ");
};

const quoteStatusTone = (status) => {
  const clean = String(status || "").toLowerCase();
  if (clean === "issued") return "border-[#A4E5E4] bg-[#F1F8F8] text-[#057B7B]";
  if (clean === "converted") return "border-[#ABEFC6] bg-[#ECFDF3] text-[#067647]";
  if (clean === "cancelled") return "border-[#FECDCA] bg-[#FEF3F2] text-[#B42318]";
  if (clean === "expired") return "border-[#FEDF89] bg-[#FFFAEB] text-[#B54708]";
  return "border-[#E4E8E8] bg-[#F9FAFB] text-[#324443]";
};

// ----------------------------------------------------------------------
// Main Component
// ----------------------------------------------------------------------

export default function QuotationDeskModal({ open, onClose }) {
  const conversionCacheRef = useRef({});
  const [activeTab, setActiveTab] = useState("quote");
  const [quotations, setQuotations] = useState([]);
  const [currencies, setCurrencies] = useState([]);
  const [cart, setCart] = useState([]);
  const [search, setSearch] = useState("");
  const [quoteSearch, setQuoteSearch] = useState("");
  const [products, setProducts] = useState([]);
  const [notice, setNotice] = useState("");
  const [loading, setLoading] = useState(false);
  const [loadingProducts, setLoadingProducts] = useState(false);
  const [loadingQuotes, setLoadingQuotes] = useState(false);
  const [loadingCurrencies, setLoadingCurrencies] = useState(false);
  const [convertingCurrency, setConvertingCurrency] = useState(false);
  const [selectedLineId, setSelectedLineId] = useState(null);
  const [discountOpen, setDiscountOpen] = useState(false);
  const [priceOverrideOpen, setPriceOverrideOpen] = useState(false);
  const [productLookupOpen, setProductLookupOpen] = useState(false);
  const [printFormatOpen, setPrintFormatOpen] = useState(false);
  const [controlModal, setControlModal] = useState(null);
  const [authorisedCompany, setAuthorisedCompany] = useState(null);
  const [authorisedBranch, setAuthorisedBranch] = useState(null);
  const [loadingCompany, setLoadingCompany] = useState(false);
  const [form, setForm] = useState(emptyForm());

  const selectedLine = cart.find((line) => line.lineId === selectedLineId) || null;
  const canEdit = !["converted", "cancelled", "expired"].includes(form.status);

  const totals = useMemo(() => {
    const grossTotal = cart.reduce((s, i) => s + calculateLineTax(i).gross, 0);
    const subTotalExclTax = cart.reduce((s, i) => s + calculateLineTax(i).subtotal_excl_tax, 0);
    const discountTotal = cart.reduce((s, i) => s + calculateLineTax(i).discount, 0);
    const inclusiveTaxTotal = cart.reduce((s, i) => s + calculateLineTax(i).inclusive_tax, 0);
    const exclusiveTaxTotal = cart.reduce((s, i) => s + calculateLineTax(i).exclusive_tax, 0);
    const taxTotal = inclusiveTaxTotal + exclusiveTaxTotal;
    const grandTotal = cart.reduce((s, i) => s + calculateLineTax(i).line_total, 0);
    return { gross_total: Number(grossTotal.toFixed(4)), sub_total: Number(subTotalExclTax.toFixed(4)), subtotal_excl_tax: Number(subTotalExclTax.toFixed(4)), discount_total: Number(discountTotal.toFixed(4)), inclusive_tax_total: Number(inclusiveTaxTotal.toFixed(4)), exclusive_tax_total: Number(exclusiveTaxTotal.toFixed(4)), tax_total: Number(taxTotal.toFixed(4)), grand_total: Number(grandTotal.toFixed(4)) };
  }, [cart]);

  useEffect(() => {
    if (!open) return;
    const user = getLoggedInUser();
    setForm(prev => ({ ...prev, sales_person_id: prev.sales_person_id || user?.id || null, sales_person: prev.sales_person || user?.name || user?.username || "" }));
    loadInitialData();
  }, [open]);

  if (!open) return null;

  const showNotice = (msg) => { setNotice(msg); setTimeout(() => setNotice(""), 3500); };
  const closeDesk = () => { setNotice(""); setDiscountOpen(false); setPriceOverrideOpen(false); setProductLookupOpen(false); setControlModal(null); onClose?.(); };
  const loadInitialData = async () => await Promise.all([loadAuthorisedCompany(), loadQuotations(), loadCurrencies(), searchProducts("")]);

  const loadAuthorisedCompany = async () => {
    try {
      setLoadingCompany(true);
      const res = await getAuthorisedCompany();
      const company = getCompanyPayload(res);
      const branch = resolveCompanyBranch(company);
      setAuthorisedCompany(company);
      setAuthorisedBranch(branch);
    } catch (err) { showNotice(getErrorMessage(err, "Failed to load company information.")); }
    finally { setLoadingCompany(false); }
  };

  const convertMoney = async (from, to, amount) => {
    const cleanFrom = String(from || "").toUpperCase(), cleanTo = String(to || "").toUpperCase(), numericAmount = Number(amount || 0);
    if (!cleanFrom || !cleanTo || cleanFrom === cleanTo) return Number(numericAmount.toFixed(4));
    const cacheKey = `${cleanFrom}:${cleanTo}:${numericAmount.toFixed(4)}`;
    if (conversionCacheRef.current[cacheKey] !== undefined) return conversionCacheRef.current[cacheKey];
    const response = await convertCurrency({ from: cleanFrom, to: cleanTo, amount: numericAmount });
    const converted = Number(getConvertedAmount(response) || 0);
    conversionCacheRef.current[cacheKey] = Number(converted.toFixed(4));
    return conversionCacheRef.current[cacheKey];
  };

  const loadCurrencies = async () => {
    try {
      setLoadingCurrencies(true);
      const res = await getCurrencies({ per_page: 100, is_active: true });
      const rows = normalizeRows(res).filter(i => i?.is_active !== false);
      setCurrencies(rows);
      const base = getBaseCurrency(rows);
      const baseCode = getCurrencyCode(base);
      if (baseCode) setForm(p => ({ ...p, currency: p.currency || baseCode }));
    } catch (err) { showNotice(getErrorMessage(err, "Failed to load currencies.")); }
    finally { setLoadingCurrencies(false); }
  };

  const handleCurrencyChange = async (currencyCode) => {
    const target = String(currencyCode || "").toUpperCase(), old = form.currency;
    if (!target || target === old) { setForm(p => ({ ...p, currency: target })); return; }
    try {
      setConvertingCurrency(true);
      const updated = await Promise.all(cart.map(async line => {
        const src = line.currency || old || line.baseCurrency || target;
        const newPrice = await convertMoney(line.baseCurrency || src, target, line.basePrice ?? line.price ?? 0);
        const newDiscount = await convertMoney(src, target, line.discount || 0);
        let newDiscountPayload = line.discountPayload ? { ...line.discountPayload, currency: target } : null;
        if (newDiscountPayload) {
          const gross = Number(line.qty) * Number(line.price);
          const net = Math.max(gross - Number(line.discount), 0);
          const [da, ga, na] = await Promise.all([
            convertMoney(src, target, newDiscountPayload.discount_amount || 0),
            convertMoney(src, target, newDiscountPayload.gross_amount || gross),
            convertMoney(src, target, newDiscountPayload.net_amount || net),
          ]);
          let dv = newDiscountPayload.discount_value;
          if (newDiscountPayload.discount_basis === "fixed_amount" || newDiscountPayload.discount_basis === "fixed_price")
            dv = await convertMoney(src, target, newDiscountPayload.discount_value || 0);
          newDiscountPayload = { ...newDiscountPayload, discount_value: Number(dv || 0), discount_amount: Number(da || 0), gross_amount: Number(ga || 0), net_amount: Number(na || 0) };
        }
        return { ...line, price: Number(newPrice || 0), currency: target, discount: Number(newDiscount || 0), discountPayload: newDiscountPayload };
      }));
      setCart(updated);
      setForm(p => ({ ...p, currency: target }));
    } catch (err) { showNotice(getErrorMessage(err, "Currency conversion failed.")); }
    finally { setConvertingCurrency(false); }
  };

  const loadQuotations = async () => {
    try {
      setLoadingQuotes(true);
      const res = await getQuotations({ per_page: 50, q: quoteSearch || undefined });
      setQuotations(normalizeRows(res));
    } catch (err) { showNotice(getErrorMessage(err, "Failed to load quotations.")); }
    finally { setLoadingQuotes(false); }
  };

  const searchProducts = async (q) => {
    try {
      setLoadingProducts(true);
      const res = await getProducts({
        q,
        search: q,
        per_page: 100,
        limit: 100,
        is_active: true,

        // Same POS product-type filtering used by CreateSale.
        product_type: POS_PRODUCT_TYPES.join(","),
        product_types: POS_PRODUCT_TYPES,
      });

      setProducts(
        normalizeRows(res).filter((product) =>
          product?.is_active !== false && productMatchesPosType(product)
        )
      );
    } catch (err) { showNotice(getErrorMessage(err, "Failed to search products.")); }
    finally { setLoadingProducts(false); }
  };

  const resetDesk = () => {
    setForm(emptyForm()); setCart([]); setSelectedLineId(null); setSearch(""); setDiscountOpen(false); setPriceOverrideOpen(false); setProductLookupOpen(false); setControlModal(null); setActiveTab("quote");
    const base = getBaseCurrency(currencies); const baseCode = getCurrencyCode(base);
    if (baseCode) setForm(p => ({ ...p, currency: baseCode }));
  };

  const addProduct = async (product, qty = 1) => {
    const selPrice = getProductPrice(product, form.currency);
    let converted = selPrice.price;
    try { converted = await convertMoney(selPrice.currency || form.currency, form.currency, selPrice.price); } catch { converted = selPrice.price; }
    const line = {
      lineId: `${product.id}-${Date.now()}`, product_id: product.id, productId: product.id, product_price_id: selPrice.product_price_id, productPriceId: selPrice.product_price_id,
      price_level_id: selPrice.price_level_id, priceLevelId: selPrice.price_level_id, priceLevelName: selPrice.price_level_name,
      category_id: product.category_id || product.category?.id || null, categoryId: product.category_id || product.category?.id || null,
      name: product.name || product.product_name || "Product", description: product.description || product.name || "Product",
      code: product.code || "", sku: product.sku || "", barcode: product.barcode || "",
      currency: form.currency || selPrice.currency || "USD", qty: Number(qty), unit: getProductUnit(product),
      basePrice: selPrice.price, baseCurrency: selPrice.currency || form.currency || "USD", price: Number(converted || 0),
      discount: 0, discountPayload: null, priceOverride: null, taxes: getProductTaxRows(product), productTaxes: getProductTaxRows(product),
      taxable: getProductTaxRows(product).length > 0 || Boolean(product.taxable), taxRate: Number(product.tax?.rate || product.tax_rate || 0),
      availableStock: getProductAvailableStock(product), rawProduct: product,
    };
    setCart(p => [...p, line]); setSelectedLineId(line.lineId); setProductLookupOpen(false); setActiveTab("quote");
  };

  const updateLine = (lineId, patch) => setCart(prev => prev.map(line => line.lineId === lineId ? { ...line, ...patch } : line));
  const removeLine = (lineId) => { setCart(prev => prev.filter(l => l.lineId !== lineId)); if (selectedLineId === lineId) setSelectedLineId(null); };

  const applyDiscount = (payload) => {
    if (!selectedLine || !payload) return;
    const gross = Number(selectedLine.qty) * Number(selectedLine.price);
    const discount = Math.min(Math.max(Number(payload.discount_amount || 0), 0), gross);
    updateLine(selectedLine.lineId, { discount, discountPayload: { ...payload, currency: form.currency, discount_amount: discount, gross_amount: gross, net_amount: Math.max(gross - discount, 0), product_id: selectedLine.product_id || selectedLine.productId, category_id: selectedLine.category_id || selectedLine.categoryId, from_quotation: true } });
    setDiscountOpen(false);
    showNotice(`Discount applied: ${payload.discount_name || payload.discount_code || "Discount"}`);
  };

  const applyPriceOverride = async (payload) => {
    if (!selectedLine || !payload) return;
    let newPrice = Number(payload.price || payload.override_price || payload.unit_price || selectedLine.price);
    try { newPrice = await convertMoney(payload.currency || selectedLine.currency || form.currency, form.currency, newPrice); } catch {}
    updateLine(selectedLine.lineId, {
      price: Number(newPrice), basePrice: Number(payload.base_price || payload.price || newPrice), baseCurrency: payload.currency || selectedLine.currency, currency: form.currency,
      price_level_id: payload.price_level_id || selectedLine.price_level_id, priceLevelId: payload.price_level_id || selectedLine.priceLevelId,
      priceLevelName: payload.price_level_name || selectedLine.priceLevelName, product_price_id: payload.product_price_id || selectedLine.product_price_id, productPriceId: payload.product_price_id || selectedLine.productPriceId,
      priceOverride: { ...payload, old_price: selectedLine.price, new_price: Number(newPrice), from_quotation: true }
    });
    setPriceOverrideOpen(false);
    showNotice("Price override applied to quotation line.");
  };

  const buildPayload = (status = form.status) => ({
    id: form.id || null, customer_id: form.customer_id || null, customer_name: form.customer_name || null, customer_phone: form.customer_phone || null, customer_email: form.customer_email || null,
    currency: form.currency, valid_until: form.valid_until || null, notes: form.notes || null, status,
    metadata: { sales_person_id: form.sales_person_id || null, sales_person: form.sales_person || null, selected_terms: form.selected_terms || null },
    items: cart.map(line => {
      const tb = calculateLineTax(line);
      return {
        product_id: line.product_id || line.productId, product_price_id: line.product_price_id || line.productPriceId || null,
        price_level_id: line.price_level_id || line.priceLevelId || null, category_id: line.category_id || line.categoryId || null,
        product_name: line.name, product_code: line.code, sku: line.sku, quantity: Number(line.qty),
        base_unit_price: Number(line.basePrice || line.price), unit_price: Number(line.price),
        has_price_override: Boolean(line.priceOverride), price_override_level_id: line.priceOverride?.price_level_id || line.priceOverride?.price_level?.id || null,
        price_override_payload: line.priceOverride || null, discount_amount: Number(tb.discount), discount_payload: line.discountPayload || null,
        taxable: getLineTaxes(line).length > 0, tax_rate: 0, tax_amount: Number(tb.tax_total), tax_total: Number(tb.tax_total),
        inclusive_tax_total: Number(tb.inclusive_tax), exclusive_tax_total: Number(tb.exclusive_tax), subtotal_excl_tax: Number(tb.subtotal_excl_tax),
        line_total: Number(tb.line_total), tax_details: tb.taxes,
        metadata: { source: "quotation_desk", line_description: line.description || null, unit: line.unit || null, tax_summary: tb.tax_summary, tax_breakdown: tb, product_taxes: line.taxes || line.productTaxes || [] }
      };
    })
  });

  const save = async (status = form.status) => {
    if (!cart.length) { showNotice("Add at least one product to quotation."); return null; }
    try {
      setLoading(true);
      const res = await saveQuotation(buildPayload(status));
      const data = getPayloadData(res);
      setForm(p => ({ ...p, id: data?.id || p.id, quote_code: data?.quote_code || p.quote_code, status: data?.status || status, sales_person_id: data?.metadata?.sales_person_id || p.sales_person_id, sales_person: data?.metadata?.sales_person || p.sales_person, selected_terms: data?.metadata?.selected_terms || p.selected_terms }));
      showNotice(`${res?.message || "Quotation saved."} ${data?.quote_code ? `Code: ${data.quote_code}` : ""}`);
      await loadQuotations();
      return data;
    } catch (err) { showNotice(getErrorMessage(err, "Failed to save quotation.")); return null; }
    finally { setLoading(false); }
  };

  const issue = async () => {
    let quote = null;
    if (!form.id) { quote = await save("issued"); if (!quote?.id) return null; }
    try {
      setLoading(true);
      const id = quote?.id || form.id;
      const res = await issueQuotation(id);
      const data = getPayloadData(res);
      setForm(p => ({ ...p, id: data?.id || p.id, quote_code: data?.quote_code || p.quote_code, status: "issued" }));
      showNotice(res?.message || "Quotation issued.");
      await loadQuotations();
      return data;
    } catch (err) { showNotice(getErrorMessage(err, "Failed to issue quotation.")); return null; }
    finally { setLoading(false); }
  };

  const cancelCurrent = async () => {
    if (!form.id) { resetDesk(); return; }
    if (!await requestEnterpriseConfirmation({
      title: "Cancel Quotation",
      message: "Cancel this quotation?",
      confirmLabel: "Cancel quotation",
    })) return;
    try {
      setLoading(true);
      const res = await cancelQuotation(form.id);
      const data = getPayloadData(res);
      setForm(p => ({ ...p, status: data?.status || "cancelled" }));
      showNotice(res?.message || "Quotation cancelled.");
      await loadQuotations();
    } catch (err) { showNotice(getErrorMessage(err, "Failed to cancel quotation.")); }
    finally { setLoading(false); }
  };

  const openQuotation = async (quote) => {
    try {
      setLoading(true);
      const res = await getQuotation(quote.id);
      const data = getPayloadData(res) || quote;
      setForm({
        id: data.id, quote_code: data.quote_code || "", customer_id: data.customer_id || null, customer_name: data.customer_name || "", customer_phone: data.customer_phone || "", customer_email: data.customer_email || "",
        currency: data.currency || "USD", valid_until: data.valid_until ? String(data.valid_until).slice(0, 10) : todayPlusDays(7),
        notes: data.notes || "", status: data.status || "draft", sales_person_id: data.metadata?.sales_person_id || null,
        sales_person: data.metadata?.sales_person || "", selected_terms: data.metadata?.selected_terms || "Cash / On Collection",
      });
      setCart((data.items || []).map(item => ({
        lineId: item.id, product_id: item.product_id, productId: item.product_id, product_price_id: item.product_price_id, productPriceId: item.product_price_id,
        price_level_id: item.price_level_id, priceLevelId: item.price_level_id, priceLevelName: item.price_override_payload?.price_level_name || item.price_override_payload?.price_level?.name || "Quotation Price",
        category_id: item.category_id, categoryId: item.category_id, name: item.product_name, description: item.metadata?.line_description || item.product_name,
        code: item.product_code, sku: item.sku, currency: item.currency || data.currency, qty: Number(item.quantity), unit: item.metadata?.unit || "EACH",
        basePrice: Number(item.base_unit_price), baseCurrency: item.currency || data.currency, price: Number(item.unit_price), discount: Number(item.discount_amount),
        discountPayload: item.discount_payload, priceOverride: item.price_override_payload,
        taxes: item.metadata?.product_taxes || item.metadata?.tax_breakdown?.taxes || item.tax_details || item.taxes || [],
        productTaxes: item.metadata?.product_taxes || item.metadata?.tax_breakdown?.taxes || item.tax_details || item.taxes || [],
        taxable: Boolean(item.taxable), taxRate: Number(item.tax_rate || 0), fromQuotation: true, quotationId: data.id, quotationCode: data.quote_code, quotationItemId: item.id,
      })));
      setSelectedLineId(null);
      setActiveTab("quote");
    } catch (err) { showNotice(getErrorMessage(err, "Failed to open quotation.")); }
    finally { setLoading(false); }
  };

  const printCurrentQuote = async (format = "a4") => {
    let issuedQuote = null;
    if (!form.id || form.status === "draft") { issuedQuote = await issue(); if (!issuedQuote) return; }
    const code = issuedQuote?.quote_code || form.quote_code || "DRAFT";
    const companyProfile = getCompanyPrintProfile(authorisedCompany, authorisedBranch);
    const is80mm = format === "80mm";
    const rowsHtml = cart.map((line, idx) => {
      const tb = calculateLineTax(line);
      if (is80mm) return `<div class="item"><div class="item-top"><span>${idx+1}. ${escapeHtml(line.description || line.name)}</span><b>${moneyNumber(tb.line_total)}</b></div><div class="muted">${escapeHtml(line.code || "-")} / ${Number(line.qty).toFixed(4)} ${escapeHtml(line.unit)} x ${moneyNumber(line.price)}</div>${tb.discount>0?`<div class="muted">Discount: ${moneyNumber(tb.discount)}</div>`:""}${tb.tax_total>0?`<div class="muted">Tax: ${moneyNumber(tb.tax_total)} / ${escapeHtml(tb.tax_summary)}</div>`:""}</div>`;
      return `<tr><td>${idx+1}</td><td><b>${escapeHtml(line.code||"-")}</b><div class="muted">${escapeHtml(line.sku||"")}</div></td><td><b>${escapeHtml(line.description||line.name)}</b>${line.priceOverride?'<span class="badge">PRICE OVERRIDE</span>':''}${line.discountPayload?'<span class="badge">DISCOUNT</span>':''}<div class="muted">${escapeHtml(line.priceLevelName||"Standard")}</div><div class="muted">${escapeHtml(tb.tax_summary)}</div></td><td class="right">${Number(line.qty).toFixed(4)}</td><td>${escapeHtml(line.unit)}</td><td class="right">${moneyNumber(line.price)}</td><td class="right">${moneyNumber(tb.discount)}</td><td class="right">${moneyNumber(tb.tax_total)}</td><td class="right"><b>${moneyNumber(tb.line_total)}</b></td></tr>`;
    }).join("");
    const a4Html = `<!DOCTYPE html><html><head><title>Quotation ${escapeHtml(code)}</title><style>*{box-sizing:border-box}@page{size:A4;margin:12mm}body{margin:0;font-family:Arial,sans-serif;background:#eef2f2}.page{width:210mm;min-height:297mm;margin:0 auto;background:#fff;padding:18mm}.top-line{height:6px;background:#004344;margin:-18mm -18mm 18px}.header{display:grid;grid-template-columns:1fr 280px;gap:22px;padding-bottom:18px;border-bottom:1px solid #CFD6D6}.brand-row{display:flex;gap:12px;align-items:center}.logo{width:58px;height:58px;border:1px solid #CFD6D6;object-fit:contain;padding:5px}.company{font-size:22px;font-weight:800;color:#004343}.branch{margin-top:2px;font-size:13px;font-weight:700;color:#324443}.address{margin-top:7px;color:#627474;font-size:11px;line-height:1.45}.doc-title{text-align:right}.doc-title h1{margin:0;font-size:30px;text-transform:uppercase;color:#0C1B1B}.doc-title .code{margin-top:8px;display:inline-block;border:1px solid #004343;padding:6px 10px;color:#004343;font-weight:800;font-size:14px}.meta{margin-top:14px;border:1px solid #CFD6D6}.meta-row{display:grid;grid-template-columns:95px 1fr;border-bottom:1px solid #E9EDED;font-size:11px}.meta-row:last-child{border-bottom:0}.meta-row b{background:#F9FAFB;padding:7px;color:#465757}.meta-row span{padding:7px;text-align:right;color:#0C1B1B}.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:18px}.panel{border:1px solid #CFD6D6;padding:12px}.panel-title{font-size:10px;font-weight:800;text-transform:uppercase;color:#627474;margin-bottom:8px}.line{font-size:12px;color:#324443}table{width:100%;border-collapse:collapse;margin-top:18px;font-size:11px}th{background:#004344;color:white;text-align:left;padding:9px 7px;text-transform:uppercase;font-size:9px}td{border-bottom:1px solid #E9EDED;padding:8px 7px;vertical-align:top}.right{text-align:right}.muted{color:#627474;font-size:10px;margin-top:2px}.badge{display:inline-block;border:1px solid #FDB022;background:#FFFAEB;color:#B54708;padding:2px 4px;margin-left:4px;font-size:8px;font-weight:800}.footer{display:grid;grid-template-columns:1fr 330px;gap:18px;margin-top:18px}.notes{border:1px solid #CFD6D6;padding:12px;font-size:11px;color:#465757}.totals{border:1px solid #CFD6D6}.total-row{display:grid;grid-template-columns:1fr 130px;border-bottom:1px solid #E9EDED;font-size:12px}.total-row:last-child{border-bottom:0;background:#004344;color:#fff;font-weight:800;font-size:15px}.total-row span{padding:9px}.total-row span:last-child{text-align:right}.signature-grid{display:grid;grid-template-columns:1fr 1fr;gap:32px;margin-top:34px}.signature{border-top:1px solid #96A5A4;padding-top:7px;color:#627474;font-size:10px;text-transform:uppercase}.terms{margin-top:24px;border-top:1px solid #CFD6D6;padding-top:12px;color:#627474;font-size:10px}</style></head><body><div class="page"><div class="top-line"></div><div class="header"><div><div class="brand-row">${companyProfile.logoUrl?`<img class="logo" src="${escapeHtml(companyProfile.logoUrl)}" />`:""}<div><div class="company">${escapeHtml(companyProfile.companyName)}</div><div class="branch">${escapeHtml(companyProfile.branchName)} ${companyProfile.branchCode?`(${escapeHtml(companyProfile.branchCode)})`:""}</div></div></div><div class="address">${escapeHtml(companyProfile.companyAddress||companyProfile.branchAddress||"-")}<br/>Branch Address: ${escapeHtml(companyProfile.branchAddress||"-")}<br/>${companyProfile.phone?`Phone: ${escapeHtml(companyProfile.phone)} · `:""}${companyProfile.email?`Email: ${escapeHtml(companyProfile.email)} · `:""}${companyProfile.domain?`Web: ${escapeHtml(companyProfile.domain)}`:""}</div></div><div class="doc-title"><h1>Quotation</h1><div class="code">${escapeHtml(code)}</div><div class="meta"><div class="meta-row"><b>Date</b><span>${new Date().toLocaleDateString()}</span></div><div class="meta-row"><b>Status</b><span>${escapeHtml(form.status||"-")}</span></div><div class="meta-row"><b>Valid Until</b><span>${escapeHtml(form.valid_until||"-")}</span></div><div class="meta-row"><b>Currency</b><span>${escapeHtml(form.currency)}</span></div></div></div></div><div class="info-grid"><div class="panel"><div class="panel-title">Customer</div><div class="line"><b>${escapeHtml(form.customer_name||"Walk-in Customer")}</b></div><div class="line">Phone: ${escapeHtml(form.customer_phone||"-")}</div><div class="line">Email: ${escapeHtml(form.customer_email||"-")}</div></div><div class="panel"><div class="panel-title">Quotation Information</div><div class="line"><b>Sales Person:</b> ${escapeHtml(form.sales_person||"-")}</div><div class="line"><b>Terms:</b> ${escapeHtml(form.selected_terms||"-")}</div><div class="line"><b>Lines:</b> ${cart.length}</div></div></div><table><thead><tr><th style="width:34px;">#</th><th>Item Code</th><th>Description</th><th class="right">Qty</th><th>Unit</th><th class="right">Unit Price</th><th class="right">Discount</th><th class="right">Tax</th><th class="right">Line Total</th></tr></thead><tbody>${rowsHtml}</tbody></table><div class="footer"><div class="notes"><b>Notes / Terms</b><br/>${escapeHtml(form.notes||"Prices and discounts are valid only until the quotation expiry date.")}</div><div class="totals"><div class="total-row"><span>Subtotal Excl Tax</span><span>${escapeHtml(form.currency)} ${moneyNumber(totals.subtotal_excl_tax)}</span></div><div class="total-row"><span>Discount</span><span>${escapeHtml(form.currency)} ${moneyNumber(totals.discount_total)}</span></div><div class="total-row"><span>Inclusive Tax</span><span>${escapeHtml(form.currency)} ${moneyNumber(totals.inclusive_tax_total)}</span></div><div class="total-row"><span>Exclusive Tax</span><span>${escapeHtml(form.currency)} ${moneyNumber(totals.exclusive_tax_total)}</span></div><div class="total-row"><span>Overall Total</span><span>${escapeHtml(form.currency)} ${moneyNumber(totals.grand_total)}</span></div></div></div><div class="signature-grid"><div class="signature">Prepared By: ${escapeHtml(form.sales_person||"-")}</div><div class="signature">Customer Acceptance</div></div><div class="terms">This is a quotation and not a tax invoice. Availability, prices, discounts and price levels are subject to confirmation at sale conversion.</div></div></body></html>`;
    const receiptHtml = `<!DOCTYPE html><html><head><title>Quotation ${escapeHtml(code)}</title><style>*{box-sizing:border-box}@page{size:80mm auto;margin:3mm}body{margin:0;width:80mm;font-family:Arial,sans-serif;background:#fff;font-size:11px}.receipt{width:80mm;padding:4mm}.center{text-align:center}.company{font-size:15px;font-weight:800;text-transform:uppercase}.branch{margin-top:2px;font-size:12px;font-weight:700}.small{margin-top:2px;font-size:10px;color:#444;line-height:1.35}.title{margin:8px 0 6px;border-top:1px dashed #111;border-bottom:1px dashed #111;padding:5px 0;text-align:center;font-size:13px;font-weight:800;text-transform:uppercase}.row{display:flex;justify-content:space-between;gap:6px;margin:2px 0}.muted{color:#555;font-size:10px}.section{border-top:1px dashed #111;margin-top:7px;padding-top:7px}.item{border-bottom:1px dashed #ddd;padding:6px 0}.item-top{display:flex;justify-content:space-between;gap:8px;font-size:11px}.item-top span{flex:1}.total{font-size:13px;font-weight:800;border-top:1px dashed #111;border-bottom:1px dashed #111;padding:6px 0;margin-top:5px}.footer{margin-top:8px;text-align:center;font-size:10px}</style></head><body><div class="receipt"><div class="center"><div class="company">${escapeHtml(companyProfile.companyName)}</div><div class="branch">${escapeHtml(companyProfile.branchName)} ${companyProfile.branchCode?`(${escapeHtml(companyProfile.branchCode)})`:""}</div><div class="small">${escapeHtml(companyProfile.branchAddress||companyProfile.companyAddress||"-")}</div><div class="small">${companyProfile.phone?`Tel: ${escapeHtml(companyProfile.phone)}`:""}${companyProfile.email?` / ${escapeHtml(companyProfile.email)}`:""}</div></div><div class="title">Quotation</div><div class="row"><span>Quote No</span><b>${escapeHtml(code)}</b></div><div class="row"><span>Date</span><span>${new Date().toLocaleDateString()}</span></div><div class="row"><span>Valid Until</span><span>${escapeHtml(form.valid_until||"-")}</span></div><div class="row"><span>Sales Person</span><span>${escapeHtml(form.sales_person||"-")}</span></div><div class="row"><span>Currency</span><span>${escapeHtml(form.currency)}</span></div><div class="section"><b>Customer</b><div>${escapeHtml(form.customer_name||"Walk-in Customer")}</div><div class="muted">${escapeHtml(form.customer_phone||"-")}</div></div><div class="section">${rowsHtml}</div><div class="section"><div class="row"><span>Subtotal Excl Tax</span><span>${moneyNumber(totals.subtotal_excl_tax)}</span></div><div class="row"><span>Discount</span><span>${moneyNumber(totals.discount_total)}</span></div><div class="row"><span>Incl Tax</span><span>${moneyNumber(totals.inclusive_tax_total)}</span></div><div class="row"><span>Excl Tax</span><span>${moneyNumber(totals.exclusive_tax_total)}</span></div><div class="row total"><span>Total</span><span>${escapeHtml(form.currency)} ${moneyNumber(totals.grand_total)}</span></div></div><div class="footer">This is a quotation, not a tax invoice.<br/>Prices are valid until ${escapeHtml(form.valid_until||"-")}.<br/>${escapeHtml(form.notes||"")}</div></div></body></html>`;
    const html = is80mm ? receiptHtml : a4Html;
    const win = window.open("", "_blank", `width=${is80mm?420:1000},height=${is80mm?760:860}`);
    win.document.write(html);
    win.document.close();
    win.focus();
    win.print();
  };

  // ----------------------------------------------------------------------
  // JSX Render
  // ----------------------------------------------------------------------
  return (
    <FullScreenModal open={open} onClose={closeDesk} title="QUOTATION DESK" subtitle="SERVENEST HOSPITALITY ENTERPRISE">
      <div className="relative h-[calc(100vh-86px)] overflow-hidden bg-[#D8DFDF] text-[#1C2C2B] font-normal">
        {notice && <div className="absolute left-1/2 top-3 z-[90] -translate-x-1/2 whitespace-pre-line border border-[#004343] bg-[#004344] px-4 py-2 text-[12px] font-semibold text-white shadow-xl">{notice}</div>}
        {(loading || loadingProducts || loadingQuotes || loadingCurrencies || loadingCompany || convertingCurrency) && (
          <div className="absolute right-3 top-3 z-[90] border border-[#A4E5E4] bg-white px-3 py-2 text-[11px] font-semibold text-[#057B7B] shadow-xl">
            {loading ? "Processing quotation..." : convertingCurrency ? "Converting currency..." : loadingProducts ? "Loading products..." : loadingCurrencies ? "Loading currencies..." : loadingCompany ? "Loading company..." : "Loading quotations..."}
          </div>
        )}
        <div className="grid h-full min-h-0 grid-rows-[auto_auto_minmax(0,1fr)_auto] gap-2 p-2">
          <DeskTabs activeTab={activeTab} setActiveTab={setActiveTab} loadQuotations={loadQuotations} resetDesk={resetDesk} />
          <QuoteTools
            canEdit={canEdit} selectedLine={selectedLine} setProductLookupOpen={setProductLookupOpen}
            setPriceOverrideOpen={setPriceOverrideOpen} setDiscountOpen={setDiscountOpen}
            save={save} openPrintFormatModal={() => setPrintFormatOpen(true)} cancelCurrent={cancelCurrent}
            loading={loading} cartLength={cart.length}
          />
          {activeTab === "register" ? (
            <RegisterTab quotations={quotations} quoteSearch={quoteSearch} setQuoteSearch={setQuoteSearch} loadQuotations={loadQuotations} openQuotation={openQuotation} loadingQuotes={loadingQuotes} />
          ) : (
            <QuoteEntryGrid
              form={form} cart={cart} selectedLine={selectedLine} selectedLineId={selectedLineId}
              setSelectedLineId={setSelectedLineId} updateLine={updateLine} removeLine={removeLine}
              canEdit={canEdit} setProductLookupOpen={setProductLookupOpen}
              setPriceOverrideOpen={setPriceOverrideOpen} setDiscountOpen={setDiscountOpen}
            />
          )}
          <FooterActions
            form={form}
            totals={totals}
            resetDesk={resetDesk}
            openControlModal={setControlModal}
          />
          <QuotationControlModal
            open={Boolean(controlModal)}
            mode={controlModal}
            onClose={() => setControlModal(null)}
            form={form}
            setForm={setForm}
            canEdit={canEdit}
            currencies={currencies}
            handleCurrencyChange={handleCurrencyChange}
            authorisedCompany={authorisedCompany}
            authorisedBranch={authorisedBranch}
          />
          <PrintFormatModal open={printFormatOpen} onClose={() => setPrintFormatOpen(false)} onSelect={(f) => { setPrintFormatOpen(false); printCurrentQuote(f); }} />
          <ProductLookupModal open={productLookupOpen} search={search} setSearch={setSearch} products={products} loadingProducts={loadingProducts} searchProducts={searchProducts} addProduct={addProduct} onClose={() => setProductLookupOpen(false)} currency={form.currency} convertMoney={convertMoney} />
          <DiscountApplyModal open={discountOpen} onClose={() => setDiscountOpen(false)} line={selectedLine} saleTotal={totals.grand_total} currency={form.currency} onApplied={applyDiscount} />
          <PriceOverrideModal open={priceOverrideOpen} onClose={() => setPriceOverrideOpen(false)} line={selectedLine} onApplied={applyPriceOverride} />
        </div>
      </div>
    </FullScreenModal>
  );
}

// ----------------------------------------------------------------------
// Subcomponents (all with rounded-none for enterprise flat design)
// ----------------------------------------------------------------------

function HeaderPanels({ form, setForm, activeTab, setActiveTab, loadQuotations, resetDesk, canEdit, currencies, handleCurrencyChange, authorisedCompany, authorisedBranch }) {
  const profile = getCompanyPrintProfile(authorisedCompany, authorisedBranch);
  return (
    <div className="grid shrink-0 grid-cols-1 gap-2 xl:grid-cols-[300px_minmax(0,1fr)_300px]">
      <Panel title="Quote Card">
        <div className="grid grid-cols-[88px_1fr] items-center gap-1 text-[11px]">
          <Label>Company</Label><Field value={profile.companyName} disabled />
          <Label>Branch</Label><Field value={profile.branchName} disabled />
          <Label>Address</Label><Field value={profile.branchAddress || profile.companyAddress || "-"} disabled />
          <Label>Quote Code</Label><Field value={form.quote_code || "NEW"} disabled />
          <Label>Status</Label>
          <select value={form.status} disabled={!canEdit} onChange={e => setForm(p => ({ ...p, status: e.target.value }))} className="h-7 border border-[#B9C3C3] bg-white px-2 text-[11px] outline-none disabled:bg-[#EEF2F2] rounded-none">
            <option value="draft">Draft</option><option value="issued">Issued</option>
          </select>
          <Label>Terms</Label>
          <select value={form.selected_terms} disabled={!canEdit} onChange={e => setForm(p => ({ ...p, selected_terms: e.target.value }))} className="h-7 border border-[#B9C3C3] bg-white px-2 text-[11px] outline-none disabled:bg-[#EEF2F2] rounded-none">
            <option>Cash / On Collection</option><option>Cash / On Delivery</option><option>Account Customer</option><option>30 Days</option>
          </select>
        </div>
      </Panel>
      <Panel title="Quote Account">
        <div className="grid grid-cols-[118px_1fr] items-center gap-1 text-[11px]">
          <Label>Customer</Label><Field value={form.customer_name} disabled={!canEdit} onChange={v => setForm(p => ({ ...p, customer_name: v }))} placeholder="Search / enter customer name" />
          <Label>Phone</Label><Field value={form.customer_phone} disabled={!canEdit} onChange={v => setForm(p => ({ ...p, customer_phone: v }))} placeholder="Customer phone" />
          <Label>Email</Label><Field value={form.customer_email} disabled={!canEdit} onChange={v => setForm(p => ({ ...p, customer_email: v }))} placeholder="Customer email" />
          <Label>Currency</Label>
          <select value={form.currency || ""} disabled={!canEdit} onChange={e => handleCurrencyChange(e.target.value)} className="h-7 border border-[#B9C3C3] bg-white px-2 text-[11px] outline-none disabled:bg-[#EEF2F2] rounded-none">
            {currencies.length === 0 && <option value={form.currency}>{form.currency || "USD"}</option>}
            {currencies.map(c => { const code = getCurrencyCode(c); return <option key={c.id || code} value={code}>{code} - {getCurrencyName(c)}</option>; })}
          </select>
        </div>
      </Panel>
      <Panel title="Quotation Details">
        <div className="grid grid-cols-[90px_1fr] items-center gap-1 text-[11px]">
          <Label>Valid Until</Label><Field type="date" value={form.valid_until} disabled={!canEdit} onChange={v => setForm(p => ({ ...p, valid_until: v }))} />
          <Label>Sales Person</Label><Field value={form.sales_person} disabled placeholder="Logged in user" />
          <Label>Status</Label><span className={`inline-flex w-fit border px-2 py-1 text-[10px] font-semibold uppercase ${quoteStatusTone(form.status)} rounded-none`}>{form.status || "draft"}</span>
        </div>
      </Panel>
      <div className="xl:col-span-3 flex items-center justify-between border border-[#B9C3C3] bg-[#EEF2F2] px-2 py-1 rounded-none">
        <div className="flex gap-1">
          <TopTab active={activeTab === "quote"} onClick={() => setActiveTab("quote")}>Quote Entry</TopTab>
          <TopTab active={activeTab === "register"} onClick={() => { setActiveTab("register"); loadQuotations(); }}>Register</TopTab>
        </div>
        <button type="button" onClick={resetDesk} className="h-7 border border-[#B9C3C3] bg-white px-3 text-[11px] font-semibold text-[#324443] rounded-none">New Quote</button>
      </div>
    </div>
  );
}

function QuoteTools({ canEdit, selectedLine, setProductLookupOpen, setPriceOverrideOpen, setDiscountOpen, save, openPrintFormatModal, cancelCurrent, loading, cartLength }) {
  return (
    <div className="shrink-0 border border-[#AFB9B9] bg-[#EEF2F2] p-2 rounded-none">
      <div className="flex flex-wrap items-center gap-2">
        <ToolButton onClick={() => setProductLookupOpen(true)} disabled={!canEdit}><Plus className="mr-1 inline h-3 w-3" /> Add Item</ToolButton>
        <ToolButton onClick={() => setPriceOverrideOpen(true)} disabled={!canEdit || !selectedLine}>Price Override</ToolButton>
        <ToolButton onClick={() => setDiscountOpen(true)} disabled={!canEdit || !selectedLine}>Discount</ToolButton>
        <div className="mx-1 h-6 w-px bg-[#B9C3C3]" />
        <ToolButton onClick={() => save("draft")} disabled={loading || !canEdit || !cartLength}>Save Draft</ToolButton>
        <ToolButton onClick={() => save("issued")} disabled={loading || !canEdit || !cartLength} green>Save / Issue</ToolButton>
        <ToolButton onClick={openPrintFormatModal} disabled={loading || !cartLength} navy><Printer className="mr-1 inline h-3 w-3" /> Print</ToolButton>
        <ToolButton onClick={cancelCurrent} disabled={loading || !cartLength} danger>Cancel</ToolButton>
      </div>
    </div>
  );
}

function QuoteEntryGrid({ form, cart, selectedLine, selectedLineId, setSelectedLineId, updateLine, removeLine, canEdit, setProductLookupOpen, setPriceOverrideOpen, setDiscountOpen }) {
  return (
    <div className="min-h-0 border border-[#AFB9B9] bg-white rounded-none">
      <div className="flex h-full min-h-0 flex-col">
        <div className="grid shrink-0 grid-cols-[44px_112px_minmax(260px,1.2fr)_90px_88px_110px_110px_130px_115px_115px] border-b border-[#AFB9B9] bg-[#EEF2F2] text-[10px] font-semibold uppercase text-[#465757]">
          <Head>Line</Head><Head>Item Code</Head><Head>Description</Head><Head>Qty</Head><Head>Units</Head><Head>Unit Price</Head><Head>Discount</Head><Head>Tax</Head><Head>Line Total</Head><Head>Action</Head>
        </div>
        <div className="min-h-0 flex-1 overflow-auto">
          {cart.map((line, idx) => {
            const tb = calculateLineTax(line);
            return (
              <div key={line.lineId} onClick={() => setSelectedLineId(line.lineId)} className={`grid min-w-[1240px] cursor-pointer grid-cols-[44px_112px_minmax(260px,1.2fr)_90px_88px_110px_110px_130px_115px_115px] border-b border-[#D8DFDF] text-[11px] ${selectedLineId === line.lineId ? "bg-[#E5F2F2]" : "bg-white hover:bg-[#F8FAFC]"}`}>
                <Cell muted>{idx+1}</Cell>
                <Cell><button type="button" onClick={e => { e.stopPropagation(); setProductLookupOpen(true); }} className="flex h-7 w-full items-center justify-between border border-transparent px-1 text-left hover:border-[#93A6A6] hover:bg-white rounded-none"><span className="truncate">{line.code || line.sku || "-"}</span><Search className="h-3 w-3 text-[#637877]" /></button></Cell>
                <Cell><input value={line.description || line.name || ""} disabled={!canEdit} onChange={e => updateLine(line.lineId, { description: e.target.value })} onClick={e => e.stopPropagation()} className="h-7 w-full border border-transparent bg-transparent px-1 text-[11px] outline-none focus:border-[#93A6A6] focus:bg-white disabled:bg-transparent rounded-none" /><div className="mt-1 flex flex-wrap gap-1"><LineBadges line={line} /><Pill tone={getLineTaxes(line).length ? "success" : "danger"}>{tb.tax_summary}</Pill></div></Cell>
                <Cell><input type="number" min="0.0001" step="0.0001" value={line.qty} disabled={!canEdit} onChange={e => updateLine(line.lineId, { qty: Number(e.target.value || 0) })} onClick={e => e.stopPropagation()} className="h-7 w-full border border-transparent bg-transparent px-1 text-right text-[11px] outline-none focus:border-[#93A6A6] focus:bg-white disabled:bg-transparent rounded-none" /></Cell>
                <Cell>{line.unit || "EACH"}</Cell>
                <Cell right><button type="button" onClick={e => { e.stopPropagation(); setPriceOverrideOpen(true); }} disabled={!canEdit} className="h-7 w-full border border-transparent px-1 text-right text-[11px] hover:border-[#93A6A6] hover:bg-white disabled:opacity-70 rounded-none">{money(line.price, form.currency)}</button></Cell>
                <Cell right><button type="button" onClick={e => { e.stopPropagation(); setDiscountOpen(true); }} disabled={!canEdit} className="h-7 w-full border border-transparent px-1 text-right text-[11px] hover:border-[#93A6A6] hover:bg-white disabled:opacity-70 rounded-none">{money(tb.discount, form.currency)}</button></Cell>
                <Cell right><div className="font-semibold">{money(tb.tax_total, form.currency)}</div><div className="text-[9px] text-[#627474]">I: {money(tb.inclusive_tax, form.currency)} / E: {money(tb.exclusive_tax, form.currency)}</div></Cell>
                <Cell right><div className="font-semibold">{money(tb.line_total, form.currency)}</div><div className="text-[9px] text-[#627474]">Excl: {money(tb.subtotal_excl_tax, form.currency)}</div></Cell>
                <Cell right><button type="button" disabled={!canEdit} onClick={e => { e.stopPropagation(); removeLine(line.lineId); }} className="h-7 border border-[#FECDCA] bg-white px-2 text-[10px] font-semibold text-[#B42318] disabled:opacity-50 rounded-none">Remove</button></Cell>
              </div>
            );
          })}
          <div className="grid min-w-[1240px] grid-cols-[44px_112px_minmax(260px,1.2fr)_90px_88px_110px_110px_130px_115px_115px] border-b border-[#D8DFDF] bg-[#F7F9FC] text-[11px]">
            <Cell muted>{cart.length+1}</Cell><Cell><button type="button" onClick={() => setProductLookupOpen(true)} className="flex h-7 w-full items-center gap-1 border border-[#93A6A6] bg-white px-2 text-left text-[11px] text-[#324443] rounded-none"><Plus className="h-3 w-3" /> Add</button></Cell>
            <Cell muted>Click Add or search item code to insert a quotation line</Cell><Cell right>0.00</Cell><Cell muted>EACH</Cell><Cell right>{form.currency}0.00</Cell><Cell right>{form.currency}0.00</Cell><Cell right>{form.currency}0.00</Cell><Cell right>{form.currency}0.00</Cell><Cell />
          </div>
          {!cart.length && <div className="flex h-[180px] flex-col items-center justify-center text-[12px] text-[#627474]"><PackageSearch className="mb-2 h-8 w-8" />Add products to build the quotation.</div>}
        </div>
      </div>
    </div>
  );
}

function FooterActions({ form, totals, resetDesk, openControlModal }) {
  return (
    <div className="grid shrink-0 grid-cols-1 gap-2 xl:grid-cols-[minmax(0,1fr)_360px]">
      <Panel title="Quotation Controls">
        <div className="grid grid-cols-2 gap-2 md:grid-cols-4">
          <ControlButton title="Quote Card" value={form.quote_code || "NEW"} onClick={() => openControlModal("quoteCard")} />
          <ControlButton title="Customer" value={form.customer_name || "Walk-in Customer"} onClick={() => openControlModal("customer")} />
          <ControlButton title="Details" value={`${form.status || "draft"} / ${form.valid_until || "-"}`} onClick={() => openControlModal("details")} />
          <ControlButton title="Notes / Terms" value={form.notes ? "Notes added" : "No notes"} onClick={() => openControlModal("notes")} />
        </div>
        <div className="mt-2 grid grid-cols-2 gap-2 text-[10px] text-[#627474] md:grid-cols-4">
          <div><span className="font-semibold text-[#324443]">Currency:</span> {form.currency || "-"}</div>
          <div><span className="font-semibold text-[#324443]">Terms:</span> {form.selected_terms || "-"}</div>
          <div><span className="font-semibold text-[#324443]">Sales Person:</span> {form.sales_person || "-"}</div>
          <div><span className="font-semibold text-[#324443]">Valid Until:</span> {form.valid_until || "-"}</div>
        </div>
      </Panel>
      <Panel title="Totals Summary">
        <div className="space-y-1">
          <TotalRow label="Subtotal Excl Tax" value={money(totals.subtotal_excl_tax, form.currency)} />
          <TotalRow label="Discount" value={money(totals.discount_total, form.currency)} danger />
          <TotalRow label="Inclusive Tax" value={money(totals.inclusive_tax_total, form.currency)} />
          <TotalRow label="Exclusive Tax" value={money(totals.exclusive_tax_total, form.currency)} />
          <TotalRow label="Total Tax" value={money(totals.tax_total, form.currency)} />
          <div className="mt-2 border-t border-[#E4EAEA] pt-2"><TotalRow label="Overall Total" value={money(totals.grand_total, form.currency)} strong /></div>
        </div>
        <button type="button" onClick={resetDesk} className="mt-4 h-8 w-full border border-[#004343] bg-[#004344] text-[11px] font-semibold text-white hover:bg-[#005757] rounded-none">New Quote</button>
      </Panel>
    </div>
  );
}

function DeskTabs({ activeTab, setActiveTab, loadQuotations, resetDesk }) {
  return (
    <div className="shrink-0 flex items-center justify-between border border-[#B9C3C3] bg-[#EEF2F2] px-2 py-1 rounded-none">
      <div className="flex gap-1">
        <TopTab active={activeTab === "quote"} onClick={() => setActiveTab("quote")}>Quote Entry</TopTab>
        <TopTab active={activeTab === "register"} onClick={() => { setActiveTab("register"); loadQuotations(); }}>Register</TopTab>
      </div>
      <button type="button" onClick={resetDesk} className="h-7 border border-[#B9C3C3] bg-white px-3 text-[11px] font-semibold text-[#324443] rounded-none">New Quote</button>
    </div>
  );
}

function QuotationControlModal({ open, mode, onClose, form, setForm, canEdit, currencies, handleCurrencyChange, authorisedCompany, authorisedBranch }) {
  if (!open) return null;

  const profile = getCompanyPrintProfile(authorisedCompany, authorisedBranch);
  const titles = {
    quoteCard: "Quote Card",
    customer: "Quote Account",
    details: "Quotation Details",
    notes: "Notes / Terms",
  };
  const title = titles[mode] || "Quotation Controls";

  return (
    <div className="absolute inset-0 z-[120] flex items-center justify-center bg-[#004040]/60 p-4">
      <div className="sx-large-modal-window border border-[#AFB9B9] bg-white shadow-2xl rounded-none" role="dialog" aria-modal="true" aria-label={title}>
        <div className="flex items-center justify-between border-b border-[#D8DFDF] bg-[#004344] px-4 py-3 text-white">
          <div>
            <div className="text-[14px] font-semibold uppercase tracking-wide">{title}</div>
            <div className="mt-1 text-[11px] text-white/75">Edit quotation controls without reducing the quote entry table space.</div>
          </div>
          <button type="button" onClick={onClose} className="h-8 w-8 border border-white/30 bg-white/10 text-[18px] text-white rounded-none">×</button>
        </div>

        <div className="min-h-0 flex-1 overflow-y-auto p-4">
          {mode === "quoteCard" && (
            <div className="grid grid-cols-[120px_1fr] items-center gap-2 text-[11px]">
              <Label>Company</Label><Field value={profile.companyName} disabled />
              <Label>Branch</Label><Field value={profile.branchName} disabled />
              <Label>Address</Label><Field value={profile.branchAddress || profile.companyAddress || "-"} disabled />
              <Label>Quote Code</Label><Field value={form.quote_code || "NEW"} disabled />
              <Label>Status</Label>
              <select value={form.status} disabled={!canEdit} onChange={e => setForm(p => ({ ...p, status: e.target.value }))} className="h-8 border border-[#B9C3C3] bg-white px-2 text-[12px] outline-none disabled:bg-[#EEF2F2] rounded-none">
                <option value="draft">Draft</option>
                <option value="issued">Issued</option>
              </select>
              <Label>Terms</Label>
              <select value={form.selected_terms} disabled={!canEdit} onChange={e => setForm(p => ({ ...p, selected_terms: e.target.value }))} className="h-8 border border-[#B9C3C3] bg-white px-2 text-[12px] outline-none disabled:bg-[#EEF2F2] rounded-none">
                <option>Cash / On Collection</option>
                <option>Cash / On Delivery</option>
                <option>Account Customer</option>
                <option>30 Days</option>
              </select>
            </div>
          )}

          {mode === "customer" && (
            <div className="grid grid-cols-[120px_1fr] items-center gap-2 text-[11px]">
              <Label>Customer</Label><Field value={form.customer_name} disabled={!canEdit} onChange={v => setForm(p => ({ ...p, customer_name: v }))} placeholder="Search / enter customer name" />
              <Label>Phone</Label><Field value={form.customer_phone} disabled={!canEdit} onChange={v => setForm(p => ({ ...p, customer_phone: v }))} placeholder="Customer phone" />
              <Label>Email</Label><Field value={form.customer_email} disabled={!canEdit} onChange={v => setForm(p => ({ ...p, customer_email: v }))} placeholder="Customer email" />
              <Label>Currency</Label>
              <select value={form.currency || ""} disabled={!canEdit} onChange={e => handleCurrencyChange(e.target.value)} className="h-8 border border-[#B9C3C3] bg-white px-2 text-[12px] outline-none disabled:bg-[#EEF2F2] rounded-none">
                {currencies.length === 0 && <option value={form.currency}>{form.currency || "USD"}</option>}
                {currencies.map(c => { const code = getCurrencyCode(c); return <option key={c.id || code} value={code}>{code} - {getCurrencyName(c)}</option>; })}
              </select>
            </div>
          )}

          {mode === "details" && (
            <div className="grid grid-cols-[120px_1fr] items-center gap-2 text-[11px]">
              <Label>Valid Until</Label><Field type="date" value={form.valid_until} disabled={!canEdit} onChange={v => setForm(p => ({ ...p, valid_until: v }))} />
              <Label>Sales Person</Label><Field value={form.sales_person} disabled placeholder="Logged in user" />
              <Label>Status</Label><span className={`inline-flex w-fit border px-2 py-1 text-[10px] font-semibold uppercase ${quoteStatusTone(form.status)} rounded-none`}>{form.status || "draft"}</span>
              <Label>Currency</Label><Field value={form.currency || "-"} disabled />
            </div>
          )}

          {mode === "notes" && (
            <div>
              <Label>Quotation Notes</Label>
              <textarea value={form.notes || ""} onChange={e => setForm(p => ({ ...p, notes: e.target.value }))} placeholder="Enter notes, terms, or special instructions..." disabled={!canEdit} rows={8} className="mt-1 w-full border border-[#B9C3C3] bg-white p-2 text-[12px] outline-none focus:border-[#004343] disabled:bg-[#EEF2F2] rounded-none" />
            </div>
          )}
        </div>

        <div className="border-t border-[#D8DFDF] bg-[#F8FAFC] p-3">
          <button type="button" onClick={onClose} className="h-9 w-full border border-[#004343] bg-[#004344] text-[12px] font-semibold text-white rounded-none">Done</button>
        </div>
      </div>
    </div>
  );
}

function RegisterTab({ quotations, quoteSearch, setQuoteSearch, loadQuotations, openQuotation, loadingQuotes }) {
  return (
    <div className="min-h-0 border border-[#AFB9B9] bg-white rounded-none">
      <div className="flex h-full min-h-0 flex-col">
        <div className="flex shrink-0 flex-wrap items-center justify-between gap-2 border-b border-[#AFB9B9] bg-[#EEF2F2] p-2">
          <div><div className="text-[13px] font-semibold text-[#0C1B1B]">Quotation Register</div><div className="text-[11px] text-[#627474]">Search, review and reopen quotations.</div></div>
          <div className="flex min-w-[320px] gap-2"><input value={quoteSearch} onChange={e => setQuoteSearch(e.target.value)} onKeyDown={e => e.key === "Enter" && loadQuotations()} placeholder="Search quote, customer or phone..." className="h-8 min-w-0 flex-1 border border-[#B9C3C3] px-3 text-[12px] outline-none focus:border-[#004343] rounded-none" /><button type="button" onClick={loadQuotations} className="h-8 border border-[#004343] bg-[#004344] px-3 text-[12px] font-semibold text-white rounded-none">{loadingQuotes ? <RefreshCw className="h-4 w-4 animate-spin" /> : "Find"}</button></div>
        </div>
        <div className="min-h-0 flex-1 overflow-auto">
          <table className="w-full min-w-[1260px] border-collapse text-[12px]">
            <thead className="sticky top-0 z-10"><tr className="border-b border-[#D8DFDF] bg-[#F4F6F8] text-left text-[10px] uppercase text-[#627474]"><th className="px-3 py-2 font-semibold">Quote Code</th><th className="px-3 py-2 font-semibold">Created</th><th className="px-3 py-2 font-semibold">Customer</th><th className="px-3 py-2 font-semibold">Contact</th><th className="px-3 py-2 font-semibold">Status</th><th className="px-3 py-2 text-right font-semibold">Items</th><th className="px-3 py-2 font-semibold">Currency</th><th className="px-3 py-2 text-right font-semibold">Total</th><th className="px-3 py-2 font-semibold">Valid Until</th><th className="w-[58px] px-3 py-2 text-center font-semibold" aria-label="Actions">⋮</th></tr></thead>
            <tbody>{quotations.map(q => <tr key={q.id} className="border-b border-[#E9EDED] hover:bg-[#F9FAFB]"><td className="px-3 py-2 font-semibold text-[#0C1B1B]">{q.quote_code}</td><td className="px-3 py-2">{String(q.created_at || "").slice(0,10) || "-"}</td><td className="px-3 py-2">{q.customer_name || "Walk-in Customer"}</td><td className="px-3 py-2">{q.customer_phone || q.customer_email || "-"}</td><td className="px-3 py-2"><span className={`border px-2 py-1 text-[10px] font-semibold uppercase ${quoteStatusTone(q.status)} rounded-none`}>{q.status}</span></td><td className="px-3 py-2 text-right">{q.items_count || q.items?.length || 0}</td><td className="px-3 py-2">{q.currency || "-"}</td><td className="px-3 py-2 text-right font-semibold text-[#0C1B1B]">{moneyNumber(q.grand_total)}</td><td className="px-3 py-2 text-[#465757]">{q.valid_until ? String(q.valid_until).slice(0,10) : "-"}</td><td className="px-3 py-2 text-center"><button type="button" onClick={() => openQuotation(q)} className="h-7 border border-[#004343] bg-white px-3 text-[11px] font-semibold text-[#004343] rounded-none">Open</button></td></tr>)}
            {!quotations.length && <tr><td colSpan={10} className="px-3 py-12 text-center text-[12px] text-[#627474]"><div className="flex flex-col items-center justify-center"><ClipboardList className="mb-2 h-8 w-8" />No quotations found.</div></td></tr>}</tbody>
          </table>
        </div>
      </div>
    </div>
  );
}

function PrintFormatModal({ open, onClose, onSelect }) {
  if (!open) return null;
  return (
    <div className="absolute inset-0 z-[120] flex items-center justify-center bg-[#004040]/60 p-4">
      <div className="sx-large-modal-window border border-[#AFB9B9] bg-white shadow-2xl rounded-none" role="dialog" aria-modal="true" aria-label="Print Quotation">
        <div className="border-b border-[#D8DFDF] bg-[#004344] px-4 py-3 text-white"><div className="text-[14px] font-semibold uppercase tracking-wide">Print Quotation</div><div className="mt-1 text-[11px] text-white/75">Choose the format for this quotation printout.</div></div>
        <div className="grid min-h-0 flex-1 content-start gap-3 overflow-y-auto p-4 md:grid-cols-2"><button type="button" onClick={() => onSelect("a4")} className="border border-[#CFD6D6] bg-white p-4 text-left hover:border-[#004343] hover:bg-[#F8FAFC] rounded-none"><div className="text-[15px] font-semibold text-[#0C1B1B]">A4 Quotation</div><div className="mt-2 text-[12px] text-[#627474]">Full-page quotation.</div></button><button type="button" onClick={() => onSelect("80mm")} className="border border-[#CFD6D6] bg-white p-4 text-left hover:border-[#004343] hover:bg-[#F8FAFC] rounded-none"><div className="text-[15px] font-semibold text-[#0C1B1B]">80mm Receipt</div><div className="mt-2 text-[12px] text-[#627474]">Thermal quotation.</div></button></div>
        <div className="border-t border-[#D8DFDF] bg-[#F8FAFC] p-3"><button type="button" onClick={onClose} className="h-9 w-full border border-[#B9C3C3] bg-white text-[12px] font-semibold text-[#324443] rounded-none">Cancel</button></div>
      </div>
    </div>
  );
}

function ProductLookupModal({ open, search, setSearch, products, loadingProducts, searchProducts, addProduct, onClose, currency, convertMoney }) {
  const [previewPrices, setPreviewPrices] = useState({});
  useEffect(() => { let mounted = true; const load = async () => { if (!open || !currency) return; const next = {}; for (const p of products.slice(0,150)) { const price = getProductPrice(p, currency); if (!price) continue; try { next[p.id] = await convertMoney(price.currency || currency, currency, price.price); } catch { next[p.id] = Number(price.price || 0); } } if (mounted) setPreviewPrices(next); }; load(); return () => { mounted = false; }; }, [open, products, currency, convertMoney]);
  if (!open) return null;
  return (
    <div className="absolute inset-0 z-[95] flex items-center justify-center bg-[#004040]/50 p-4">
      <div className="sx-large-modal-window border border-[#AFB9B9] bg-white shadow-2xl rounded-none" role="dialog" aria-modal="true" aria-label="Item Lookup">
        <div className="flex h-10 shrink-0 items-center justify-between border-b border-[#AFB9B9] bg-[#EEF2F2] px-3"><div className="flex items-center gap-2 text-[12px] font-semibold text-[#0C1B1B]"><FileText className="h-4 w-4" />Item Lookup</div><button type="button" onClick={onClose} className="h-7 w-7 border border-[#AFB9B9] bg-white text-[14px] rounded-none">×</button></div>
        <div className="shrink-0 border-b border-[#D8DFDF] p-2"><div className="flex gap-2"><div className="relative min-w-0 flex-1"><Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[#627474]" /><input autoFocus value={search} onChange={e => setSearch(e.target.value)} onKeyDown={e => e.key === "Enter" && searchProducts(search)} placeholder="Search item code, SKU, barcode or description..." className="h-9 w-full border border-[#B9C3C3] bg-white pl-9 pr-3 text-[12px] outline-none focus:border-[#004343] rounded-none" /></div><button type="button" onClick={() => searchProducts(search)} className="h-9 border border-[#004343] bg-[#004344] px-4 text-[12px] font-semibold text-white rounded-none">{loadingProducts ? "Loading..." : "Search"}</button></div></div>
        <div className="min-h-0 flex-1 overflow-auto"><table className="w-full min-w-[1320px] border-collapse text-[12px]"><thead className="sticky top-0 z-10"><tr className="border-b border-[#D8DFDF] bg-[#F4F6F8] text-left text-[10px] uppercase text-[#627474]"><th className="px-2 py-2 font-semibold">Item Code</th><th className="px-2 py-2 font-semibold">Product</th><th className="px-2 py-2 font-semibold">SKU / Barcode</th><th className="px-2 py-2 font-semibold">Category</th><th className="px-2 py-2 text-right font-semibold">Stock</th><th className="px-2 py-2 text-right font-semibold">Price</th><th className="px-2 py-2 font-semibold">Tax Profile</th><th className="px-2 py-2 text-right font-semibold">Tax Amount</th><th className="px-2 py-2 text-right font-semibold">Line Total</th><th className="w-[58px] px-2 py-2 text-center font-semibold" aria-label="Actions">⋮</th></tr></thead><tbody>{products.filter(productMatchesPosType).map(p => { const price = getProductPrice(p, currency); const shown = previewPrices[p.id] ?? Number(price.price || 0); const previewLine = { qty: 1, price: shown, discount: 0, taxes: getProductTaxRows(p), productTaxes: getProductTaxRows(p), rawProduct: p }; const tb = calculateLineTax(previewLine); return <tr key={p.id} className="border-b border-[#E9EDED] hover:bg-[#F9FAFB]"><td className="px-2 py-2 text-[12px]">{p.code || "-"}</td><td className="px-2 py-2 font-semibold text-[#0C1B1B]">{p.name || p.product_name}</td><td className="px-2 py-2">{p.sku || p.barcode || "-"}</td><td className="px-2 py-2 text-[12px]">{p.category?.name || "Uncategorised"}</td><td className="px-2 py-2 text-right text-[12px]">{Number(getProductAvailableStock(p)).toFixed(2)}</td><td className="px-2 py-2 text-right text-[12px] font-semibold">{money(shown, currency)}</td><td className={`px-2 py-2 text-[11px] ${getProductTaxRows(p).length ? "font-semibold text-[#067647]" : "font-semibold text-[#B42318]"}`}>{productTaxSummary(p)}</td><td className="px-2 py-2 text-right">{money(tb.tax_total, currency)}</td><td className="px-2 py-2 text-right">{money(tb.line_total, currency)}</td><td className="px-2 py-2 text-center"><button type="button" onClick={() => addProduct(p)} className="h-7 border border-[#004343] bg-[#004344] px-3 text-[11px] font-semibold text-white rounded-none">Select</button></td></tr>})}{!products.length && <tr><td colSpan={10} className="py-10 text-center text-[12px] text-[#627474]">No items found.</td></tr>}</tbody></table></div>
      </div>
    </div>
  );
}

// ----------------------------------------------------------------------
// Base UI Components (flat, no rounded corners)
// ----------------------------------------------------------------------

function ControlButton({ title, value, onClick }) {
  return (
    <button type="button" onClick={onClick} className="min-h-[56px] border border-[#B9C3C3] bg-white px-3 py-2 text-left hover:border-[#004343] hover:bg-[#F8FAFC] rounded-none">
      <div className="text-[10px] font-semibold uppercase text-[#627474]">{title}</div>
      <div className="mt-1 truncate text-[12px] font-semibold text-[#0C1B1B]">{value}</div>
    </button>
  );
}

function Panel({ title, children }) { return <div className="border border-[#AFB9B9] bg-white rounded-none"><div className="border-b border-[#D8DFDF] bg-[#EEF2F2] px-2 py-1 text-[11px] font-semibold text-[#324443]">{title}</div><div className="p-2">{children}</div></div>; }
function Label({ children }) { return <div className="text-[11px] text-[#465757]">{children}</div>; }
function Field({ value, onChange, type = "text", disabled = false, placeholder = "" }) { return <input type={type} value={value || ""} disabled={disabled} placeholder={placeholder} onChange={e => onChange?.(e.target.value)} className="h-7 border border-[#B9C3C3] bg-white px-2 text-[11px] outline-none focus:border-[#004343] disabled:bg-[#EEF2F2] rounded-none" />; }
function TopTab({ active, onClick, children }) { return <button type="button" onClick={onClick} className={`h-7 border px-4 text-[11px] font-semibold rounded-none ${active ? "border-[#7C8888] bg-white text-[#0C1B1B]" : "border-transparent bg-transparent text-[#465757] hover:border-[#B9C3C3] hover:bg-white"}`}>{children}</button>; }
function Head({ children }) { return <div className="border-r border-[#D8DFDF] px-2 py-2 text-[10px]">{children}</div>; }
function Cell({ children, right = false, center = false, muted = false }) { return <div className={`min-h-[32px] border-r border-[#E4EAEA] px-2 py-1 ${right ? "text-right" : center ? "text-center" : ""} ${muted ? "text-[#627474]" : "text-[#1C2C2B]"}`}>{children}</div>; }
function LineBadges({ line }) { return <div className="flex flex-wrap gap-1">{line.priceOverride && <Pill tone="warning">Override</Pill>}{line.discountPayload && <Pill tone="success">Discount</Pill>}</div>; }
function ToolButton({ children, onClick, disabled = false, green = false, navy = false, danger = false }) { let cls = "border-[#B9C3C3] bg-white text-[#324443]"; if (green) cls = "border-[#027A48] bg-[#027A48] text-white"; if (navy) cls = "border-[#004343] bg-[#004344] text-white"; if (danger) cls = "border-[#FECDCA] bg-white text-[#B42318]"; return <button type="button" disabled={disabled} onClick={onClick} className={`h-8 border px-3 text-[11px] font-semibold disabled:opacity-50 rounded-none ${cls}`}>{children}</button>; }
function TotalRow({ label, value, strong = false, danger = false }) { return <div className={`grid grid-cols-[1fr_150px] gap-3 border-b border-[#E4EAEA] py-[2px] text-[11px] last:border-b-0 ${strong ? "font-bold text-[#0C1B1B]" : "text-[#465757]"}`}><span>{label}</span><span className={`text-right ${danger ? "text-[#B42318]" : ""}`}>{value}</span></div>; }
function Pill({ children, tone = "default" }) { const tones = { default: "border-[#CFD6D6] bg-[#F9FAFB] text-[#465757]", warning: "border-[#FEDF89] bg-[#FFFAEB] text-[#B54708]", success: "border-[#ABEFC6] bg-[#ECFDF3] text-[#067647]", danger: "border-[#FECDCA] bg-[#FEF3F2] text-[#B42318]" }; return <span className={`inline-flex border px-1 py-[1px] text-[8px] font-semibold uppercase rounded-none ${tones[tone]}`}>{children}</span>; }
