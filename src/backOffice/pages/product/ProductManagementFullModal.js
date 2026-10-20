

// import React, { useEffect, useMemo, useState } from "react";
// import ExcelJS from "exceljs";
// import { saveAs } from "file-saver";
// import jsPDF from "jspdf";
// import autoTable from "jspdf-autotable";

// import FullScreenModal from "../../../components/FullScreenModal";
// import ReusableMessageModal from "../../../components/ReusableMessageModal";

// import { getAuthorisedCompany } from "../../../services/AuthenticationService";
// import { getBaseCurrencies } from "../../../services/CurrencyService";

// import {
//   getProducts,
//   createProduct,
//   updateProduct,
//   deleteProduct,
//   generateProductIdentity,
//   breakProduct,
//   getProductCategories,
//   getSuppliers,
//   getUoms,
//   getPriceLevels,
// } from "../../../services/ProductService";

// const emptyFormData = {
//   id: null,
//   branch_id: "",

//   category_id: "",
//   supplier_id: "",
//   parent_product_id: "",
//   base_uom_id: "",
//   tax_id: "",

//   name: "",
//   code: "",
//   sku: "",
//   barcode: "",
//   description: "",
//   product_type: "simple",

//   stock_tracking_enabled: true,
//   allow_negative_stock: false,
//   allow_discount: true,
//   taxable: false,
//   is_active: true,

//   reorder_level: "0",
//   minimum_stock: "0",
//   maximum_stock: "0",

//   barcodes: [],

//   create_price_enabled: true,
//   price_rows: [],

//   opening_stock_enabled: false,
//   opening_stock_branch_id: "",
//   opening_stock_quantity: "",
//   opening_stock_unit_cost: "",
//   opening_stock_currency: "",

//   break_rule_enabled: false,
//   break_parent_quantity: "1",
//   break_units_per_parent: "1",
//   break_is_auto_break: true,
// };

// const emptyBreakForm = {
//   child_product_id: "",
//   branch_id: "",
//   break_times: "1",
// };

// const ProductManagementFullModal = ({ open, onClose, currentUser }) => {
//   const [products, setProducts] = useState([]);
//   const [branches, setBranches] = useState([]);
//   const [categories, setCategories] = useState([]);
//   const [suppliers, setSuppliers] = useState([]);
//   const [uoms, setUoms] = useState([]);
//   const [priceLevels, setPriceLevels] = useState([]);
//   const [baseCurrencies, setBaseCurrencies] = useState([]);

//   const [loading, setLoading] = useState(false);
//   const [savingProduct, setSavingProduct] = useState(false);
//   const [savingBreak, setSavingBreak] = useState(false);

//   const [deletingProductId, setDeletingProductId] = useState(null);
//   const [generatingType, setGeneratingType] = useState("");

//   const [productModalOpen, setProductModalOpen] = useState(false);
//   const [breakModalOpen, setBreakModalOpen] = useState(false);

//   const [search, setSearch] = useState("");
//   const [typeFilter, setTypeFilter] = useState("");
//   const [categoryFilter, setCategoryFilter] = useState("");
//   const [supplierFilter, setSupplierFilter] = useState("");
//   const [statusFilter, setStatusFilter] = useState("");

//   const [activeTab, setActiveTab] = useState("register");
//   const [selectedProductId, setSelectedProductId] = useState(null);

//   const [formData, setFormData] = useState(emptyFormData);
//   const [breakForm, setBreakForm] = useState(emptyBreakForm);

//   const [messageModal, setMessageModal] = useState({
//     open: false,
//     type: "success",
//     title: "",
//     message: "",
//   });

//   const isEditing = !!formData.id;

//   const showMessage = ({ type = "success", title, message }) => {
//     setMessageModal({ open: true, type, title, message });
//   };

//   const closeMessageModal = () => {
//     setMessageModal((prev) => ({ ...prev, open: false }));
//   };

//   const getApiMessage = (source, fallback = "Request failed.") => {
//     const responseData = source?.response?.data;

//     if (responseData?.errors && typeof responseData.errors === "object") {
//       return Object.values(responseData.errors).flat().join("\n");
//     }

//     if (responseData?.data && typeof responseData.data === "object") {
//       const values = Object.values(responseData.data).flat();
//       if (values.length > 0) return values.join("\n");
//     }

//     if (responseData?.error) return responseData.error;
//     if (responseData?.message) return responseData.message;
//     if (source?.data?.message) return source.data.message;
//     if (source?.message && !source?.isAxiosError) return source.message;

//     return fallback;
//   };

//   const normalizeListResponse = (response) => {
//     const payload = response?.data;

//     if (Array.isArray(payload)) return payload;
//     if (Array.isArray(payload?.data)) return payload.data;
//     if (Array.isArray(response?.data?.data)) return response.data.data;

//     return [];
//   };

//   const normalizeCurrencyResponse = (response) => {
//     const payload = response?.data;

//     if (Array.isArray(payload)) return payload;
//     if (Array.isArray(payload?.data)) return payload.data;
//     if (Array.isArray(response?.data?.data)) return response.data.data;

//     return [];
//   };

//   const getDefaultCurrencyCode = () => {
//     return baseCurrencies?.[0]?.code || "";
//   };

//   const money = (amount, currency = "") => {
//     if (amount === null || amount === undefined || amount === "") return "-";

//     const code = currency || getDefaultCurrencyCode() || "";
//     return `${code} ${Number(amount || 0).toFixed(4)}`;
//   };

//   const quantity = (amount) => {
//     if (amount === null || amount === undefined || amount === "") {
//       return "0.0000";
//     }

//     return Number(amount || 0).toFixed(4);
//   };

//   const getBranchName = (branch) => {
//     return branch?.branch_name || branch?.name || "Unnamed Branch";
//   };

//   const getLoggedInBranchId = () => {
//     return (
//       currentUser?.branch?.id ||
//       currentUser?.branch_id ||
//       currentUser?.branchId ||
//       ""
//     );
//   };

//   const getLoggedInBranch = () => {
//     const loggedBranchId = getLoggedInBranchId();

//     const matchedBranch = branches.find(
//       (branch) => String(branch.id) === String(loggedBranchId)
//     );

//     if (matchedBranch) return matchedBranch;
//     if (currentUser?.branch) return currentUser.branch;

//     return null;
//   };

//   const getBranchOptions = () => {
//     const loggedBranch = getLoggedInBranch();

//     if (loggedBranch?.id) return [loggedBranch];

//     return branches;
//   };

//   const getDefaultBranchId = () => {
//     return getLoggedInBranch()?.id || branches?.[0]?.id || "";
//   };

//   const loadBranches = async () => {
//     const response = await getAuthorisedCompany();

//     const company = response?.company || response?.data?.company || null;

//     const companyBranches =
//       response?.company?.branches ||
//       response?.data?.branches ||
//       response?.data?.company?.branches ||
//       company?.branches ||
//       [];

//     setBranches(companyBranches);
//   };

//   const loadProducts = async () => {
//     const params = { per_page: 100 };

//     if (search.trim()) params.q = search.trim();
//     if (typeFilter) params.product_type = typeFilter;
//     if (categoryFilter) params.category_id = categoryFilter;
//     if (supplierFilter) params.supplier_id = supplierFilter;
//     if (statusFilter !== "") params.is_active = statusFilter;

//     const response = await getProducts(params);
//     setProducts(normalizeListResponse(response));
//   };

//   const loadCategories = async () => {
//     const response = await getProductCategories({ per_page: 100 });
//     setCategories(normalizeListResponse(response));
//   };

//   const loadSuppliers = async () => {
//     const response = await getSuppliers({ per_page: 100 });
//     setSuppliers(normalizeListResponse(response));
//   };

//   const loadUoms = async () => {
//     const response = await getUoms();
//     setUoms(normalizeListResponse(response));
//   };

//   const loadPriceLevels = async () => {
//     const response = await getPriceLevels();
//     setPriceLevels(normalizeListResponse(response));
//   };

//   const loadBaseCurrencyRows = async () => {
//     const response = await getBaseCurrencies();
//     const rows = normalizeCurrencyResponse(response);
//     setBaseCurrencies(rows);
//   };

//   const loadData = async () => {
//     try {
//       setLoading(true);

//       await Promise.all([
//         loadBranches(),
//         loadProducts(),
//         loadCategories(),
//         loadSuppliers(),
//         loadUoms(),
//         loadPriceLevels(),
//         loadBaseCurrencyRows(),
//       ]);
//     } catch (error) {
//       showMessage({
//         type: "error",
//         title: "Load Failed",
//         message: getApiMessage(error),
//       });
//     } finally {
//       setLoading(false);
//     }
//   };

//   useEffect(() => {
//     if (open) loadData();
//   }, [open]);

//   useEffect(() => {
//     if (!open) return;

//     const timer = setTimeout(() => {
//       loadProducts().catch((error) => {
//         showMessage({
//           type: "error",
//           title: "Filter Failed",
//           message: getApiMessage(error),
//         });
//       });
//     }, 350);

//     return () => clearTimeout(timer);
//   }, [search, typeFilter, categoryFilter, supplierFilter, statusFilter]);

//   const parentProducts = useMemo(() => {
//     return products.filter((product) => {
//       if (product.id === formData.id) return false;
//       return product.product_type === "parent" || product.product_type === "simple";
//     });
//   }, [products, formData.id]);

//   const filteredProducts = useMemo(() => {
//     const q = search.toLowerCase();

//     return products.filter((product) => {
//       const categoryName = product?.category?.name || "";
//       const supplierName = product?.supplier?.name || "";
//       const uomName = product?.uom?.name || "";
//       const parentName = product?.parent?.name || "";

//       return (
//         product?.name?.toLowerCase().includes(q) ||
//         product?.code?.toLowerCase().includes(q) ||
//         product?.sku?.toLowerCase().includes(q) ||
//         product?.barcode?.toLowerCase().includes(q) ||
//         product?.product_type?.toLowerCase().includes(q) ||
//         categoryName.toLowerCase().includes(q) ||
//         supplierName.toLowerCase().includes(q) ||
//         uomName.toLowerCase().includes(q) ||
//         parentName.toLowerCase().includes(q)
//       );
//     });
//   }, [products, search]);

//   const selectedProduct = useMemo(() => {
//     return products.find((product) => product.id === selectedProductId) || null;
//   }, [products, selectedProductId]);

//   const registerCounts = useMemo(() => {
//     return {
//       total: products.length,
//       active: products.filter((product) => product.is_active).length,
//       inactive: products.filter((product) => !product.is_active).length,
//       stockTracked: products.filter((product) => product.stock_tracking_enabled).length,
//       parents: products.filter((product) => product.product_type === "parent").length,
//       children: products.filter((product) => product.product_type === "child").length,
//       services: products.filter((product) => product.product_type === "service").length,
//       noPrice: products.filter((product) => !Array.isArray(product.prices) || product.prices.length === 0).length,
//     };
//   }, [products]);

//   const makeDefaultPriceRows = () => {
//     const defaultBranchId = getDefaultBranchId();
//     const defaultCurrency = getDefaultCurrencyCode();

//     const defaultPriceLevel =
//       priceLevels.find((level) => level.is_default) || priceLevels[0];

//     const secondPriceLevel =
//       priceLevels.find((level) => level.id !== defaultPriceLevel?.id) ||
//       priceLevels[1] ||
//       defaultPriceLevel;

//     return [
//       {
//         branch_id: defaultBranchId,
//         price_level_id: defaultPriceLevel?.id || "",
//         price: "",
//         currency: defaultCurrency,
//         is_default: true,
//         is_active: true,
//         starts_at: "",
//         ends_at: "",
//       },
//       {
//         branch_id: defaultBranchId,
//         price_level_id: secondPriceLevel?.id || "",
//         price: "",
//         currency: defaultCurrency,
//         is_default: false,
//         is_active: true,
//         starts_at: "",
//         ends_at: "",
//       },
//     ];
//   };

//   const makeEditPriceRows = (product) => {
//     if (!Array.isArray(product?.prices) || product.prices.length === 0) {
//       return makeDefaultPriceRows();
//     }

//     return product.prices.map((price) => ({
//       id: price.id,
//       branch_id: price.branch_id || getDefaultBranchId(),
//       price_level_id: price.price_level_id || "",
//       price: price.price || "",
//       currency: price.currency || getDefaultCurrencyCode(),
//       is_default: !!price.is_default,
//       is_active: !!price.is_active,
//       starts_at: price.starts_at ? String(price.starts_at).slice(0, 10) : "",
//       ends_at: price.ends_at ? String(price.ends_at).slice(0, 10) : "",
//     }));
//   };

//   const getPrimaryPrice = (product) => {
//     if (!Array.isArray(product?.prices) || product.prices.length === 0) {
//       return null;
//     }

//     return (
//       product.prices.find((price) => price.is_default) ||
//       product.prices.find((price) => price.is_active) ||
//       product.prices[0]
//     );
//   };

//   const getAllPricesDisplay = (product) => {
//     if (!Array.isArray(product?.prices) || product.prices.length === 0) {
//       return [];
//     }

//     return product.prices.map((price) => ({
//       id: price.id,
//       level: price?.price_level?.name || "Price",
//       code: price?.price_level?.code || "",
//       amount: money(price.price, price.currency),
//       is_default: price.is_default,
//       is_active: price.is_active,
//     }));
//   };

//   const getStockSummary = (product) => {
//     const stocks = Array.isArray(product?.stocks) ? product.stocks : [];

//     const quantityOnHand = stocks.reduce(
//       (sum, stock) => sum + Number(stock.quantity_on_hand || 0),
//       0
//     );

//     const reserved = stocks.reduce(
//       (sum, stock) => sum + Number(stock.reserved_quantity || 0),
//       0
//     );

//     const available = stocks.reduce(
//       (sum, stock) => sum + Number(stock.available_quantity || 0),
//       0
//     );

//     return {
//       quantityOnHand,
//       reserved,
//       available,
//     };
//   };

//   const getCostSummary = (product) => {
//     const costs = Array.isArray(product?.costs) ? product.costs : [];
//     const firstCost = costs[0];

//     return {
//       average_cost: firstCost?.average_cost ?? null,
//       last_cost: firstCost?.last_cost ?? null,
//       total_value: firstCost?.total_value ?? null,
//       currency: firstCost?.currency || getDefaultCurrencyCode(),
//     };
//   };

//   const getBarcodeSummary = (product) => {
//     const barcodes = Array.isArray(product?.barcodes) ? product.barcodes : [];
//     const primary =
//       barcodes.find((barcode) => barcode.type === "primary") || barcodes[0];

//     return {
//       primary: product?.barcode || primary?.barcode || "-",
//       count: barcodes.length,
//     };
//   };

//   const getBreakSummary = (product) => {
//     const rule = product?.child_break_rule || product?.childBreakRule;

//     if (!rule) return null;

//     return {
//       parentQuantity: rule.parent_quantity,
//       unitsPerParent: rule.units_per_parent,
//       isAutoBreak: rule.is_auto_break,
//     };
//   };

//   const getChildrenSummary = (product) => {
//     const children = Array.isArray(product?.children) ? product.children : [];

//     return {
//       count: children.length,
//       list: children,
//     };
//   };

//   const getSupplierDisplay = (product) => {
//     if (!product?.supplier) return "-";

//     const supplier = product.supplier;
//     return supplier.code ? `${supplier.name} (${supplier.code})` : supplier.name;
//   };

//   const getCategoryDisplay = (product) => {
//     if (!product?.category) return "-";

//     return product.category.code
//       ? `${product.category.name} (${product.category.code})`
//       : product.category.name;
//   };

//   const getUomDisplay = (product) => {
//     if (!product?.uom) return "-";

//     return `${product.uom.name}${product.uom.code ? ` (${product.uom.code})` : ""}`;
//   };

//   const getProductFlags = (product) => {
//     const flags = [];

//     if (product.stock_tracking_enabled) flags.push("Stock");
//     if (product.allow_negative_stock) flags.push("Negative");
//     if (product.allow_discount) flags.push("Discount");
//     if (product.taxable) flags.push("Taxable");

//     return flags;
//   };

//   const getExportFileName = (extension) => {
//     const date = new Date().toISOString().slice(0, 10);
//     return `product-management-${date}.${extension}`;
//   };

//   const buildProductExportRows = () => {
//     return filteredProducts.map((product, index) => {
//       const primaryPrice = getPrimaryPrice(product);
//       const stock = getStockSummary(product);
//       const cost = getCostSummary(product);
//       const barcode = getBarcodeSummary(product);
//       const children = getChildrenSummary(product);
//       const breakRule = getBreakSummary(product);

//       return {
//         Line: index + 1,
//         Product: product.name || "-",
//         Description: product.description || "-",
//         Type: product.product_type || "-",
//         Code: product.code || "-",
//         SKU: product.sku || "-",
//         Barcode: barcode.primary || "-",
//         "Extra Barcodes": barcode.count || 0,
//         Category: getCategoryDisplay(product),
//         Supplier: getSupplierDisplay(product),
//         UOM: getUomDisplay(product),
//         "Primary Price": primaryPrice
//           ? money(primaryPrice.price, primaryPrice.currency)
//           : "-",
//         "Price Level": primaryPrice?.price_level?.name || "-",
//         "On Hand": quantity(stock.quantityOnHand),
//         Reserved: quantity(stock.reserved),
//         Available: quantity(stock.available),
//         "Average Cost":
//           cost.average_cost !== null ? money(cost.average_cost, cost.currency) : "-",
//         "Last Cost":
//           cost.last_cost !== null ? money(cost.last_cost, cost.currency) : "-",
//         "Total Value":
//           cost.total_value !== null ? money(cost.total_value, cost.currency) : "-",
//         "Reorder Level": quantity(product.reorder_level),
//         "Minimum Stock": quantity(product.minimum_stock),
//         "Maximum Stock": quantity(product.maximum_stock),
//         "Stock Tracking": product.stock_tracking_enabled ? "Yes" : "No",
//         "Negative Stock": product.allow_negative_stock ? "Yes" : "No",
//         "Allow Discount": product.allow_discount ? "Yes" : "No",
//         Taxable: product.taxable ? "Yes" : "No",
//         Status: product.is_active ? "Active" : "Inactive",
//         Parent: product.parent?.name || "-",
//         "Child Count": children.count || 0,
//         "Break Parent Qty": breakRule ? quantity(breakRule.parentQuantity) : "-",
//         "Break Units Inside": breakRule ? quantity(breakRule.unitsPerParent) : "-",
//         "Auto Break": breakRule ? (breakRule.isAutoBreak ? "Yes" : "No") : "-",
//       };
//     });
//   };

//   const downloadProductsExcel = async () => {
//     try {
//       const rows = buildProductExportRows();

//       if (rows.length === 0) {
//         showMessage({
//           type: "error",
//           title: "Export Failed",
//           message: "No products available to export.",
//         });
//         return;
//       }

//       const workbook = new ExcelJS.Workbook();
//       workbook.creator = "ServeNest POS Enterprise";
//       workbook.created = new Date();

//       const worksheet = workbook.addWorksheet("Products", {
//         views: [{ state: "frozen", ySplit: 4, xSplit: 2 }],
//       });

//       const columns = Object.keys(rows[0]).map((key) => ({
//         header: key,
//         key,
//         width:
//           key === "Product"
//             ? 34
//             : key === "Description"
//               ? 42
//               : key === "Category" || key === "Supplier"
//                 ? 26
//                 : key.length < 10
//                   ? 16
//                   : 20,
//       }));

//       const totalColumns = columns.length;

//       worksheet.mergeCells(1, 1, 1, totalColumns);
//       worksheet.getCell("A1").value = "PRODUCT MANAGEMENT REPORT";
//       worksheet.getCell("A1").font = {
//         size: 18,
//         bold: true,
//         color: { argb: "FFFFFFFF" },
//       };
//       worksheet.getCell("A1").alignment = {
//         vertical: "middle",
//         horizontal: "center",
//       };
//       worksheet.getCell("A1").fill = {
//         type: "pattern",
//         pattern: "solid",
//         fgColor: { argb: "FF002B49" },
//       };
//       worksheet.getRow(1).height = 30;

//       worksheet.mergeCells(2, 1, 2, totalColumns);
//       worksheet.getCell("A2").value = `Generated: ${new Date().toLocaleString()} | Records: ${rows.length}`;
//       worksheet.getCell("A2").font = {
//         size: 10,
//         italic: true,
//         color: { argb: "FF475569" },
//       };
//       worksheet.getCell("A2").alignment = {
//         horizontal: "center",
//       };

//       worksheet.addRow([]);

//       worksheet.columns = columns;

//       const headerRow = worksheet.getRow(4);
//       headerRow.values = columns.map((column) => column.header);
//       headerRow.height = 24;

//       headerRow.eachCell((cell) => {
//         cell.font = {
//           bold: true,
//           color: { argb: "FFFFFFFF" },
//           size: 10,
//         };
//         cell.fill = {
//           type: "pattern",
//           pattern: "solid",
//           fgColor: { argb: "FF009688" },
//         };
//         cell.alignment = {
//           vertical: "middle",
//           horizontal: "center",
//           wrapText: true,
//         };
//         cell.border = {
//           top: { style: "thin", color: { argb: "FFD6DEE6" } },
//           left: { style: "thin", color: { argb: "FFD6DEE6" } },
//           bottom: { style: "thin", color: { argb: "FFD6DEE6" } },
//           right: { style: "thin", color: { argb: "FFD6DEE6" } },
//         };
//       });

//       rows.forEach((row) => {
//         worksheet.addRow(row);
//       });

//       worksheet.eachRow((row, rowNumber) => {
//         if (rowNumber <= 4) return;

//         row.height = 22;

//         row.eachCell((cell) => {
//           cell.font = {
//             size: 10,
//             color: { argb: "FF001B3A" },
//           };
//           cell.alignment = {
//             vertical: "middle",
//             wrapText: true,
//           };
//           cell.border = {
//             top: { style: "thin", color: { argb: "FFEEF2F5" } },
//             left: { style: "thin", color: { argb: "FFEEF2F5" } },
//             bottom: { style: "thin", color: { argb: "FFEEF2F5" } },
//             right: { style: "thin", color: { argb: "FFEEF2F5" } },
//           };
//         });

//         if (rowNumber % 2 === 0) {
//           row.eachCell((cell) => {
//             cell.fill = {
//               type: "pattern",
//               pattern: "solid",
//               fgColor: { argb: "FFF8FAFC" },
//             };
//           });
//         }
//       });

//       worksheet.autoFilter = {
//         from: {
//           row: 4,
//           column: 1,
//         },
//         to: {
//           row: 4,
//           column: totalColumns,
//         },
//       };

//       worksheet.getColumn(1).alignment = {
//         horizontal: "center",
//         vertical: "middle",
//       };

//       worksheet.getColumn(2).font = {
//         bold: true,
//         color: { argb: "FF001B3A" },
//       };

//       const buffer = await workbook.xlsx.writeBuffer();

//       saveAs(
//         new Blob([buffer], {
//           type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
//         }),
//         getExportFileName("xlsx")
//       );
//     } catch (error) {
//       showMessage({
//         type: "error",
//         title: "Excel Export Failed",
//         message: getApiMessage(error),
//       });
//     }
//   };

//   const downloadProductsPdf = () => {
//     try {
//       const rows = buildProductExportRows();

//       if (rows.length === 0) {
//         showMessage({
//           type: "error",
//           title: "Export Failed",
//           message: "No products available to export.",
//         });
//         return;
//       }

//       const doc = new jsPDF({
//         orientation: "landscape",
//         unit: "mm",
//         format: "a4",
//       });

//       const pageWidth = doc.internal.pageSize.getWidth();
//       const pageHeight = doc.internal.pageSize.getHeight();

//       doc.setFillColor(0, 43, 73);
//       doc.rect(0, 0, pageWidth, 24, "F");

//       doc.setTextColor(255, 255, 255);
//       doc.setFontSize(15);
//       doc.setFont(undefined, "bold");
//       doc.text("PRODUCT MANAGEMENT REPORT", 10, 10);

//       doc.setFontSize(8);
//       doc.setFont(undefined, "normal");
//       doc.text("SERVENEST POS ENTERPRISE", 10, 16);

//       doc.setTextColor(51, 65, 85);
//       doc.setFontSize(8);
//       doc.text(`Generated: ${new Date().toLocaleString()}`, pageWidth - 78, 30);
//       doc.text(`Records: ${rows.length}`, 10, 30);

//       const headers = [
//         "Line",
//         "Product",
//         "Type",
//         "Code",
//         "SKU",
//         "Barcode",
//         "Category",
//         "Supplier",
//         "UOM",
//         "Price",
//         "On Hand",
//         "Available",
//         "Status",
//       ];

//       const body = rows.map((row) => [
//         row.Line,
//         row.Product,
//         row.Type,
//         row.Code,
//         row.SKU,
//         row.Barcode,
//         row.Category,
//         row.Supplier,
//         row.UOM,
//         row["Primary Price"],
//         row["On Hand"],
//         row.Available,
//         row.Status,
//       ]);

//       autoTable(doc, {
//         head: [headers],
//         body,
//         startY: 35,
//         theme: "grid",
//         styles: {
//           fontSize: 6.5,
//           cellPadding: 1.4,
//           overflow: "linebreak",
//           valign: "middle",
//           lineColor: [214, 222, 230],
//           lineWidth: 0.1,
//         },
//         headStyles: {
//           fillColor: [0, 150, 136],
//           textColor: [255, 255, 255],
//           fontStyle: "bold",
//           halign: "center",
//         },
//         alternateRowStyles: {
//           fillColor: [248, 250, 252],
//         },
//         columnStyles: {
//           0: { cellWidth: 10, halign: "center" },
//           1: { cellWidth: 42 },
//           2: { cellWidth: 18 },
//           3: { cellWidth: 20 },
//           4: { cellWidth: 20 },
//           5: { cellWidth: 24 },
//           6: { cellWidth: 28 },
//           7: { cellWidth: 28 },
//           8: { cellWidth: 18 },
//           9: { cellWidth: 24 },
//           10: { cellWidth: 18, halign: "right" },
//           11: { cellWidth: 18, halign: "right" },
//           12: { cellWidth: 18, halign: "center" },
//         },
//         margin: {
//           top: 32,
//           left: 6,
//           right: 6,
//           bottom: 12,
//         },
//         didDrawPage: (data) => {
//           const pageCount = doc.internal.getNumberOfPages();

//           doc.setFontSize(7);
//           doc.setTextColor(100, 116, 139);

//           doc.text(
//             `Page ${data.pageNumber} of ${pageCount}`,
//             data.settings.margin.left,
//             pageHeight - 6
//           );

//           doc.text(
//             "Generated by ServeNest POS Enterprise",
//             pageWidth - 55,
//             pageHeight - 6
//           );
//         },
//       });

//       doc.save(getExportFileName("pdf"));
//     } catch (error) {
//       showMessage({
//         type: "error",
//         title: "PDF Export Failed",
//         message: getApiMessage(error),
//       });
//     }
//   };

//   const handleOpenCreate = () => {
//     setActiveTab("register");
//     setSelectedProductId(null);

//     const defaultBranchId = getDefaultBranchId();
//     const defaultCurrency = getDefaultCurrencyCode();

//     setFormData({
//       ...emptyFormData,
//       branch_id: defaultBranchId,
//       opening_stock_branch_id: defaultBranchId,
//       opening_stock_currency: defaultCurrency,
//       price_rows: makeDefaultPriceRows(),
//     });

//     setProductModalOpen(true);
//   };

//   const handleCloseProductModal = () => {
//     if (savingProduct) return;

//     setProductModalOpen(false);
//     setFormData(emptyFormData);
//     setGeneratingType("");
//   };

//   const handleChange = (e) => {
//     const { name, value, checked, type } = e.target;

//     setFormData((prev) => {
//       const next = {
//         ...prev,
//         [name]: type === "checkbox" ? checked : value,
//       };

//       if (name === "product_type") {
//         if (value !== "child") {
//           next.parent_product_id = "";
//           next.break_rule_enabled = false;
//           next.break_parent_quantity = "1";
//           next.break_units_per_parent = "1";
//           next.break_is_auto_break = true;
//         }

//         if (value === "service") {
//           next.stock_tracking_enabled = false;
//           next.opening_stock_enabled = false;
//           next.allow_negative_stock = false;
//         }
//       }

//       if (name === "stock_tracking_enabled" && !checked) {
//         next.opening_stock_enabled = false;
//         next.allow_negative_stock = false;
//       }

//       return next;
//     });
//   };

//   const handleBreakChange = (e) => {
//     const { name, value } = e.target;
//     setBreakForm((prev) => ({ ...prev, [name]: value }));
//   };

//   const handleCreatePriceRowChange = (index, field, value) => {
//     setFormData((prev) => ({
//       ...prev,
//       price_rows: prev.price_rows.map((row, rowIndex) =>
//         rowIndex === index ? { ...row, [field]: value } : row
//       ),
//     }));
//   };

//   const handleCreatePriceRowCheckbox = (index, field, checked) => {
//     setFormData((prev) => ({
//       ...prev,
//       price_rows: prev.price_rows.map((row, rowIndex) => {
//         if (field === "is_default") {
//           return { ...row, is_default: rowIndex === index ? checked : false };
//         }

//         return rowIndex === index ? { ...row, [field]: checked } : row;
//       }),
//     }));
//   };

//   const addCreatePriceRow = () => {
//     setFormData((prev) => ({
//       ...prev,
//       price_rows: [
//         ...prev.price_rows,
//         {
//           branch_id: getDefaultBranchId(),
//           price_level_id: "",
//           price: "",
//           currency: getDefaultCurrencyCode(),
//           is_default: false,
//           is_active: true,
//           starts_at: "",
//           ends_at: "",
//         },
//       ],
//     }));
//   };

//   const removeCreatePriceRow = (index) => {
//     setFormData((prev) => {
//       const nextRows = prev.price_rows.filter((_, rowIndex) => rowIndex !== index);

//       if (nextRows.length === 0) {
//         return {
//           ...prev,
//           price_rows: [
//             {
//               branch_id: getDefaultBranchId(),
//               price_level_id: "",
//               price: "",
//               currency: getDefaultCurrencyCode(),
//               is_default: true,
//               is_active: true,
//               starts_at: "",
//               ends_at: "",
//             },
//           ],
//         };
//       }

//       const hasDefault = nextRows.some((row) => row.is_default);

//       return {
//         ...prev,
//         price_rows: hasDefault
//           ? nextRows
//           : nextRows.map((row, rowIndex) => ({
//               ...row,
//               is_default: rowIndex === 0,
//             })),
//       };
//     });
//   };

//   const generateLocalBarcode = () => {
//     const prefix = "27";
//     const timestamp = Date.now().toString().slice(-9);
//     const random = Math.floor(Math.random() * 90 + 10).toString();

//     return `${prefix}${timestamp}${random}`;
//   };

//   const handleGenerateBarcode = () => {
//     const barcode = generateLocalBarcode();

//     setFormData((prev) => ({
//       ...prev,
//       barcode,
//       barcodes: [
//         { barcode, type: "primary" },
//         ...prev.barcodes.filter((item) => item.type !== "primary"),
//       ],
//     }));
//   };

//   const handleGenerateIdentity = async (type) => {
//     try {
//       setGeneratingType(type);

//       const response = await generateProductIdentity({
//         branch_id: formData.branch_id || null,
//         type,
//       });

//       const value = response?.data?.value || response?.value || "";

//       if (!value) {
//         showMessage({
//           type: "error",
//           title: "Generate Failed",
//           message: "The server did not return a generated value.",
//         });

//         return;
//       }

//       setFormData((prev) => ({
//         ...prev,
//         ...(type === "product_code" ? { code: value } : {}),
//         ...(type === "sku" ? { sku: value } : {}),
//       }));
//     } catch (error) {
//       showMessage({
//         type: "error",
//         title: "Generate Failed",
//         message: getApiMessage(error),
//       });
//     } finally {
//       setGeneratingType("");
//     }
//   };

//   const handleAddAlternativeBarcode = () => {
//     setFormData((prev) => ({
//       ...prev,
//       barcodes: [...prev.barcodes, { barcode: "", type: "alternative" }],
//     }));
//   };

//   const handleBarcodeChange = (index, field, value) => {
//     setFormData((prev) => ({
//       ...prev,
//       barcodes: prev.barcodes.map((item, itemIndex) =>
//         itemIndex === index ? { ...item, [field]: value } : item
//       ),
//     }));
//   };

//   const handleRemoveBarcode = (index) => {
//     setFormData((prev) => ({
//       ...prev,
//       barcodes: prev.barcodes.filter((_, itemIndex) => itemIndex !== index),
//     }));
//   };

//   const validateProductForm = () => {
//     if (!baseCurrencies.length) {
//       showMessage({
//         type: "error",
//         title: "Currency Missing",
//         message: "No active base currency was found. Configure currency first.",
//       });

//       return false;
//     }

//     if (!formData.name.trim()) {
//       showMessage({
//         type: "error",
//         title: "Validation Error",
//         message: "Product name is required.",
//       });

//       return false;
//     }

//     if (!formData.base_uom_id) {
//       showMessage({
//         type: "error",
//         title: "Validation Error",
//         message: "Base UOM is required.",
//       });

//       return false;
//     }

//     if (formData.product_type === "child") {
//       if (!formData.parent_product_id) {
//         showMessage({
//           type: "error",
//           title: "Validation Error",
//           message: "Child product must have a parent product.",
//         });

//         return false;
//       }

//       if (formData.break_rule_enabled) {
//         if (
//           !formData.break_parent_quantity ||
//           Number(formData.break_parent_quantity) <= 0 ||
//           !formData.break_units_per_parent ||
//           Number(formData.break_units_per_parent) <= 0
//         ) {
//           showMessage({
//             type: "error",
//             title: "Validation Error",
//             message:
//               "Parent quantity and units inside parent must be greater than zero.",
//           });

//           return false;
//         }
//       }
//     }

//     if (!isEditing && formData.create_price_enabled) {
//       const cleanPriceRows = formData.price_rows.filter(
//         (row) => row.price_level_id || row.price !== ""
//       );

//       if (cleanPriceRows.length === 0) {
//         showMessage({
//           type: "error",
//           title: "Validation Error",
//           message: "At least one product price is required.",
//         });

//         return false;
//       }

//       for (let i = 0; i < cleanPriceRows.length; i += 1) {
//         const row = cleanPriceRows[i];

//         if (!row.price_level_id) {
//           showMessage({
//             type: "error",
//             title: "Validation Error",
//             message: `Price level is required on price row ${i + 1}.`,
//           });

//           return false;
//         }

//         if (row.price === "" || Number(row.price) < 0) {
//           showMessage({
//             type: "error",
//             title: "Validation Error",
//             message: `Price must be zero or greater on price row ${i + 1}.`,
//           });

//           return false;
//         }

//         if (!row.currency) {
//           showMessage({
//             type: "error",
//             title: "Validation Error",
//             message: `Currency is required on price row ${i + 1}.`,
//           });

//           return false;
//         }
//       }
//     }

//     if (!isEditing && formData.opening_stock_enabled) {
//       if (!formData.opening_stock_branch_id) {
//         showMessage({
//           type: "error",
//           title: "Validation Error",
//           message: "Opening stock branch is required.",
//         });

//         return false;
//       }

//       if (!formData.opening_stock_quantity || Number(formData.opening_stock_quantity) <= 0) {
//         showMessage({
//           type: "error",
//           title: "Validation Error",
//           message: "Opening stock quantity must be greater than zero.",
//         });

//         return false;
//       }

//       if (!formData.opening_stock_currency) {
//         showMessage({
//           type: "error",
//           title: "Validation Error",
//           message: "Opening stock currency is required.",
//         });

//         return false;
//       }
//     }

//     return true;
//   };

//   const buildProductPayload = () => {
//     const primaryBarcode = formData.barcode
//       ? [{ barcode: formData.barcode, type: "primary" }]
//       : [];

//     const cleanBarcodes = formData.barcodes.filter((item) =>
//       String(item.barcode || "").trim()
//     );

//     const mergedBarcodes = [...primaryBarcode, ...cleanBarcodes].filter(
//       (item, index, self) =>
//         self.findIndex((barcodeItem) => barcodeItem.barcode === item.barcode) === index
//     );

//     const cleanSuppliers = formData.supplier_id
//       ? [
//           {
//             supplier_id: formData.supplier_id,
//             supplier_code: formData.code || formData.sku || null,
//             last_purchase_price: Number(formData.opening_stock_unit_cost || 0),
//             is_default: true,
//           },
//         ]
//       : [];

//     const payload = {
//       category_id: formData.category_id || null,
//       supplier_id: formData.supplier_id || null,
//       parent_product_id:
//         formData.product_type === "child" ? formData.parent_product_id || null : null,
//       base_uom_id: formData.base_uom_id,
//       tax_id: formData.tax_id || null,

//       name: formData.name.trim(),
//       code: formData.code || null,
//       sku: formData.sku || null,
//       barcode: formData.barcode || null,
//       description: formData.description || null,
//       product_type: formData.product_type,

//       stock_tracking_enabled: !!formData.stock_tracking_enabled,
//       allow_negative_stock: !!formData.allow_negative_stock,
//       allow_discount: !!formData.allow_discount,
//       taxable: !!formData.taxable,
//       is_active: !!formData.is_active,

//       reorder_level: Number(formData.reorder_level || 0),
//       minimum_stock: Number(formData.minimum_stock || 0),
//       maximum_stock: Number(formData.maximum_stock || 0),
//     };

//     if (!isEditing) {
//       payload.barcodes = mergedBarcodes;
//       payload.suppliers = cleanSuppliers;

//       if (formData.create_price_enabled) {
//         payload.prices = formData.price_rows
//           .filter((row) => row.price_level_id && row.price !== "")
//           .map((row) => ({
//             branch_id: row.branch_id || null,
//             price_level_id: row.price_level_id,
//             price: Number(row.price || 0),
//             currency: row.currency,
//             is_default: !!row.is_default,
//             is_active: !!row.is_active,
//             starts_at: row.starts_at || null,
//             ends_at: row.ends_at || null,
//           }));
//       }

//       if (formData.opening_stock_enabled && formData.stock_tracking_enabled) {
//         payload.opening_stocks = [
//           {
//             branch_id: formData.opening_stock_branch_id,
//             quantity: Number(formData.opening_stock_quantity || 0),
//             unit_cost: Number(formData.opening_stock_unit_cost || 0),
//             currency: formData.opening_stock_currency,
//           },
//         ];
//       }

//       if (formData.product_type === "child" && formData.break_rule_enabled) {
//         payload.break_rule = {
//           parent_product_id: formData.parent_product_id,
//           parent_quantity: Number(formData.break_parent_quantity || 1),
//           units_per_parent: Number(formData.break_units_per_parent || 1),
//           is_auto_break: !!formData.break_is_auto_break,
//         };
//       }
//     }

//     return payload;
//   };

//   const handleSubmitProduct = async (e) => {
//     if (e?.preventDefault) e.preventDefault();

//     if (!validateProductForm()) return;

//     try {
//       setSavingProduct(true);

//       const payload = buildProductPayload();

//       const response = isEditing
//         ? await updateProduct(formData.id, payload)
//         : await createProduct(payload);

//       showMessage({
//         type: "success",
//         title: isEditing ? "Product Updated" : "Product Created",
//         message: getApiMessage(
//           response,
//           isEditing ? "Product updated successfully." : "Product created successfully."
//         ),
//       });

//       handleCloseProductModal();
//       await loadProducts();
//     } catch (error) {
//       showMessage({
//         type: "error",
//         title: isEditing ? "Update Failed" : "Create Failed",
//         message: getApiMessage(error),
//       });
//     } finally {
//       setSavingProduct(false);
//     }
//   };

//   const handleEdit = (product) => {
//     setSelectedProductId(product.id);

//     const rule = product.childBreakRule || product.child_break_rule;
//     const stock = Array.isArray(product.stocks) ? product.stocks[0] : null;
//     const cost = Array.isArray(product.costs) ? product.costs[0] : null;

//     setFormData({
//       ...emptyFormData,
//       id: product.id,

//       category_id: product.category_id || product.category?.id || "",
//       supplier_id: product.supplier_id || product.supplier?.id || "",
//       parent_product_id: product.parent_product_id || product.parent?.id || "",
//       base_uom_id: product.base_uom_id || product.uom?.id || "",
//       tax_id: product.tax_id || product.tax?.id || "",

//       name: product.name || "",
//       code: product.code || "",
//       sku: product.sku || "",
//       barcode: product.barcode || "",
//       description: product.description || "",
//       product_type: product.product_type || "simple",

//       stock_tracking_enabled: product.stock_tracking_enabled ?? true,
//       allow_negative_stock: product.allow_negative_stock ?? false,
//       allow_discount: product.allow_discount ?? true,
//       taxable: product.taxable ?? false,
//       is_active: product.is_active ?? true,

//       reorder_level: product.reorder_level ?? "0",
//       minimum_stock: product.minimum_stock ?? "0",
//       maximum_stock: product.maximum_stock ?? "0",

//       barcodes: product.barcodes || [],
//       price_rows: makeEditPriceRows(product),

//       opening_stock_branch_id: stock?.branch_id || getDefaultBranchId(),
//       opening_stock_quantity: stock?.quantity_on_hand || "",
//       opening_stock_unit_cost: cost?.last_cost || "",
//       opening_stock_currency: cost?.currency || getDefaultCurrencyCode(),

//       break_rule_enabled: !!rule,
//       break_parent_quantity: rule?.parent_quantity || "1",
//       break_units_per_parent: rule?.units_per_parent || "1",
//       break_is_auto_break: rule?.is_auto_break ?? true,
//     });

//     setProductModalOpen(true);
//   };

//   const handleDelete = async (productId) => {
//     const ok = await requestEnterpriseConfirmation("Delete this product?");
//     if (!ok) return;

//     try {
//       setDeletingProductId(productId);

//       const response = await deleteProduct(productId);

//       showMessage({
//         type: "success",
//         title: "Product Deleted",
//         message: getApiMessage(response, "Product deleted successfully."),
//       });

//       await loadProducts();
//     } catch (error) {
//       showMessage({
//         type: "error",
//         title: "Delete Failed",
//         message: getApiMessage(error),
//       });
//     } finally {
//       setDeletingProductId(null);
//     }
//   };

//   const handleOpenBreakModal = (childProduct) => {
//     if (childProduct.product_type !== "child") {
//       showMessage({
//         type: "error",
//         title: "Invalid Product",
//         message: "Only child products can be broken from parent stock.",
//       });

//       return;
//     }

//     setBreakForm({
//       child_product_id: childProduct.id,
//       branch_id: getDefaultBranchId(),
//       break_times: "1",
//     });

//     setBreakModalOpen(true);
//   };

//   const handleCloseBreakModal = () => {
//     setBreakModalOpen(false);
//     setBreakForm(emptyBreakForm);
//   };

//   const validateBreakForm = () => {
//     if (!breakForm.child_product_id) {
//       showMessage({
//         type: "error",
//         title: "Validation Error",
//         message: "Child product is required.",
//       });

//       return false;
//     }

//     if (!breakForm.branch_id) {
//       showMessage({
//         type: "error",
//         title: "Validation Error",
//         message: "Branch is required.",
//       });

//       return false;
//     }

//     if (!breakForm.break_times || Number(breakForm.break_times) < 1) {
//       showMessage({
//         type: "error",
//         title: "Validation Error",
//         message: "Break times must be at least 1.",
//       });

//       return false;
//     }

//     return true;
//   };

//   const handleSubmitBreak = async (e) => {
//     if (e?.preventDefault) e.preventDefault();

//     if (!validateBreakForm()) return;

//     try {
//       setSavingBreak(true);

//       const response = await breakProduct(breakForm.child_product_id, {
//         branch_id: breakForm.branch_id,
//         break_times: Number(breakForm.break_times || 1),
//       });

//       showMessage({
//         type: "success",
//         title: "Product Broken",
//         message: getApiMessage(response, "Product broken successfully."),
//       });

//       handleCloseBreakModal();
//       await loadProducts();
//     } catch (error) {
//       showMessage({
//         type: "error",
//         title: "Break Failed",
//         message: getApiMessage(error),
//       });
//     } finally {
//       setSavingBreak(false);
//     }
//   };

//   const selectedBreakProduct = useMemo(() => {
//     return products.find((item) => item.id === breakForm.child_product_id);
//   }, [products, breakForm.child_product_id]);

//   return (
//     <>
//       <FullScreenModal
//         open={open}
//         onClose={onClose}
//         title="PRODUCT MANAGEMENT"
//         subtitle="SERVENEST POS ENTERPRISE"
//       >
//         <div className="h-[calc(100vh-86px)] min-h-0 bg-[#D8DFDF] overflow-hidden text-[#1C2C2B] font-normal">
//           <div className="grid h-full min-h-0 grid-rows-[auto_auto_minmax(0,1fr)] gap-2 p-2">
//             <div className="grid shrink-0 grid-cols-1 gap-2 xl:grid-cols-[300px_minmax(0,1fr)_300px]">
//               <ProductDeskPanel title="Product Card">
//                 <div className="grid grid-cols-[92px_1fr] items-center gap-1 text-[11px]">
//                   <ProductDeskLabel>Total Items</ProductDeskLabel>
//                   <ProductDeskField value={registerCounts.total} disabled />

//                   <ProductDeskLabel>Active</ProductDeskLabel>
//                   <ProductDeskField value={registerCounts.active} disabled />

//                   <ProductDeskLabel>Inactive</ProductDeskLabel>
//                   <ProductDeskField value={registerCounts.inactive} disabled />

//                   <ProductDeskLabel>Stock Tracked</ProductDeskLabel>
//                   <ProductDeskField value={registerCounts.stockTracked} disabled />

//                   <ProductDeskLabel>No Price</ProductDeskLabel>
//                   <ProductDeskField value={registerCounts.noPrice} disabled />
//                 </div>
//               </ProductDeskPanel>

//               <ProductDeskPanel title="Product Account">
//                 <div className="grid grid-cols-[120px_1fr] items-center gap-1 text-[11px]">
//                   <ProductDeskLabel>Search</ProductDeskLabel>
//                   <input
//                     type="text"
//                     value={search}
//                     onChange={(e) => setSearch(e.target.value)}
//                     placeholder="Search product, code, sku, barcode..."
//                     className="h-7 border border-[#B9C3C3] bg-white px-2 text-[11px] outline-none focus:border-[#004343]"
//                   />

//                   <ProductDeskLabel>Category</ProductDeskLabel>
//                   <select
//                     value={categoryFilter}
//                     onChange={(e) => setCategoryFilter(e.target.value)}
//                     className="h-7 border border-[#B9C3C3] bg-white px-2 text-[11px] outline-none focus:border-[#004343]"
//                   >
//                     <option value="">All Categories</option>
//                     {categories.map((category) => (
//                       <option key={category.id} value={category.id}>
//                         {category.name}
//                       </option>
//                     ))}
//                   </select>

//                   <ProductDeskLabel>Supplier</ProductDeskLabel>
//                   <select
//                     value={supplierFilter}
//                     onChange={(e) => setSupplierFilter(e.target.value)}
//                     className="h-7 border border-[#B9C3C3] bg-white px-2 text-[11px] outline-none focus:border-[#004343]"
//                   >
//                     <option value="">All Suppliers</option>
//                     {suppliers.map((supplier) => (
//                       <option key={supplier.id} value={supplier.id}>
//                         {supplier.name}
//                       </option>
//                     ))}
//                   </select>
//                 </div>
//               </ProductDeskPanel>

//               <ProductDeskPanel title="Product Details">
//                 <div className="grid grid-cols-[90px_1fr] items-center gap-1 text-[11px]">
//                   <ProductDeskLabel>Type</ProductDeskLabel>
//                   <select
//                     value={typeFilter}
//                     onChange={(e) => setTypeFilter(e.target.value)}
//                     className="h-7 border border-[#B9C3C3] bg-white px-2 text-[11px] outline-none focus:border-[#004343]"
//                   >
//                     <option value="">All Types</option>
//                     <option value="simple">Simple</option>
//                     <option value="parent">Parent</option>
//                     <option value="child">Child</option>
//                     <option value="service">Service</option>
//                   </select>

//                   <ProductDeskLabel>Status</ProductDeskLabel>
//                   <select
//                     value={statusFilter}
//                     onChange={(e) => setStatusFilter(e.target.value)}
//                     className="h-7 border border-[#B9C3C3] bg-white px-2 text-[11px] outline-none focus:border-[#004343]"
//                   >
//                     <option value="">All Status</option>
//                     <option value="true">Active</option>
//                     <option value="false">Inactive</option>
//                   </select>

//                   <ProductDeskLabel>Selected</ProductDeskLabel>
//                   <ProductDeskField value={selectedProduct?.name || "None"} disabled />
//                 </div>
//               </ProductDeskPanel>
//             </div>

//             <div className="flex shrink-0 flex-wrap items-center justify-between gap-2 border border-[#B9C3C3] bg-[#EEF2F2] px-2 py-1">
//               <div className="flex flex-wrap gap-1">
//                 <ProductDeskTab active={activeTab === "register"} onClick={() => setActiveTab("register")}>
//                   Product Register
//                 </ProductDeskTab>
//                 <ProductDeskTab active={activeTab === "pricing"} onClick={() => setActiveTab("pricing")}>
//                   Price Levels
//                 </ProductDeskTab>
//                 <ProductDeskTab active={activeTab === "stock"} onClick={() => setActiveTab("stock")}>
//                   Stock / Costing
//                 </ProductDeskTab>
//                 <ProductDeskTab active={activeTab === "break"} onClick={() => setActiveTab("break")}>
//                   Parent / Break
//                 </ProductDeskTab>
//               </div>

//               <div className="flex flex-wrap items-center gap-1">
//                 <ProductDeskCompactTool onClick={handleOpenCreate} green>
//                   Add Product
//                 </ProductDeskCompactTool>

//                 <ProductDeskCompactTool onClick={downloadProductsExcel}>
//                   Excel
//                 </ProductDeskCompactTool>

//                 <ProductDeskCompactTool onClick={downloadProductsPdf}>
//                   PDF
//                 </ProductDeskCompactTool>

//                 <ProductDeskCompactTool
//                   onClick={() => selectedProduct && handleEdit(selectedProduct)}
//                   disabled={!selectedProduct}
//                 >
//                   Edit
//                 </ProductDeskCompactTool>

//                 <ProductDeskCompactTool
//                   onClick={() => selectedProduct && handleOpenBreakModal(selectedProduct)}
//                   disabled={!selectedProduct || selectedProduct.product_type !== "child"}
//                 >
//                   Break
//                 </ProductDeskCompactTool>

//                 <ProductDeskCompactTool onClick={loadData} disabled={loading}>
//                   {loading ? "Loading..." : "Refresh"}
//                 </ProductDeskCompactTool>

//                 <ProductDeskCompactTool
//                   onClick={() => {
//                     setSearch("");
//                     setTypeFilter("");
//                     setCategoryFilter("");
//                     setSupplierFilter("");
//                     setStatusFilter("");
//                   }}
//                 >
//                   Clear
//                 </ProductDeskCompactTool>

//                 <ProductDeskCompactTool
//                   onClick={() => selectedProduct && handleDelete(selectedProduct.id)}
//                   disabled={!selectedProduct}
//                   danger
//                 >
//                   Delete
//                 </ProductDeskCompactTool>
//               </div>
//             </div>

//             <div className="min-h-0 border border-[#AFB9B9] bg-white">
//               <div className="flex h-full min-h-0 flex-col">
//                 {activeTab === "register" && (
//                   <>
//                     <ProductDeskGridHeader columns="grid-cols-[48px_minmax(260px,1.2fr)_150px_170px_180px_150px_130px_150px]">
//                       <ProductDeskHead>Line</ProductDeskHead>
//                       <ProductDeskHead>Product</ProductDeskHead>
//                       <ProductDeskHead>Identity</ProductDeskHead>
//                       <ProductDeskHead>Classification</ProductDeskHead>
//                       <ProductDeskHead>Primary Price</ProductDeskHead>
//                       <ProductDeskHead>Stock</ProductDeskHead>
//                       <ProductDeskHead>Status</ProductDeskHead>
//                       <ProductDeskHead>Actions</ProductDeskHead>
//                     </ProductDeskGridHeader>

//                     <div className="min-h-0 flex-1 overflow-auto">
//                       {loading ? (
//                         <ProductDeskEmpty message="Loading products..." />
//                       ) : filteredProducts.length === 0 ? (
//                         <ProductDeskEmpty message="No products found." />
//                       ) : (
//                         filteredProducts.map((product, index) => {
//                           const primaryPrice = getPrimaryPrice(product);
//                           const stock = getStockSummary(product);
//                           const barcode = getBarcodeSummary(product);
//                           const flags = getProductFlags(product);
//                           const children = getChildrenSummary(product);
//                           const selected = selectedProductId === product.id;

//                           return (
//                             <div
//                               key={product.id}
//                               onClick={() => setSelectedProductId(product.id)}
//                               className={`grid min-w-[1240px] cursor-pointer grid-cols-[48px_minmax(260px,1.2fr)_150px_170px_180px_150px_130px_150px] border-b border-[#D8DFDF] text-[11px] ${
//                                 selected ? "bg-[#E5F2F2]" : "bg-white hover:bg-[#F8FAFC]"
//                               }`}
//                             >
//                               <ProductDeskCell muted>{index + 1}</ProductDeskCell>

//                               <ProductDeskCell>
//                                 <div className="font-semibold text-[#0C1B1B]">{product.name}</div>
//                                 <div className="mt-[2px] text-[#627474]">{product.description || "No description"}</div>
//                                 <div className="mt-1 flex flex-wrap gap-1">
//                                   <ProductDeskPill>{product.product_type || "simple"}</ProductDeskPill>
//                                   {children.count > 0 && <ProductDeskPill tone="info">{children.count} child</ProductDeskPill>}
//                                   {flags.map((flag) => <ProductDeskPill key={flag} tone="success">{flag}</ProductDeskPill>)}
//                                 </div>
//                               </ProductDeskCell>

//                               <ProductDeskCell>
//                                 <ProductDeskInfo label="Code" value={product.code || "-"} />
//                                 <ProductDeskInfo label="SKU" value={product.sku || "-"} />
//                                 <ProductDeskInfo label="Barcode" value={barcode.primary} />
//                               </ProductDeskCell>

//                               <ProductDeskCell>
//                                 <ProductDeskInfo label="Category" value={getCategoryDisplay(product)} />
//                                 <ProductDeskInfo label="Supplier" value={getSupplierDisplay(product)} />
//                                 <ProductDeskInfo label="UOM" value={getUomDisplay(product)} />
//                               </ProductDeskCell>

//                               <ProductDeskCell>
//                                 {primaryPrice ? (
//                                   <>
//                                     <div className="text-[13px] font-semibold text-[#005A5A]">
//                                       {money(primaryPrice.price, primaryPrice.currency)}
//                                     </div>
//                                     <div className="text-[10px] text-[#627474]">
//                                       {primaryPrice?.price_level?.name || "Price"} {primaryPrice.is_default ? "· Default" : ""}
//                                     </div>
//                                   </>
//                                 ) : (
//                                   <span className="text-red-700 font-medium">No price</span>
//                                 )}
//                               </ProductDeskCell>

//                               <ProductDeskCell>
//                                 <ProductDeskInfo label="On Hand" value={quantity(stock.quantityOnHand)} />
//                                 <ProductDeskInfo label="Reserved" value={quantity(stock.reserved)} />
//                                 <ProductDeskInfo label="Available" value={quantity(stock.available)} />
//                               </ProductDeskCell>

//                               <ProductDeskCell>
//                                 <span
//                                   className={`inline-flex border px-2 py-1 text-[10px] font-semibold ${
//                                     product.is_active
//                                       ? "border-[#ABEFC6] bg-[#ECFDF3] text-[#067647]"
//                                       : "border-[#FECDCA] bg-[#FEF3F2] text-[#B42318]"
//                                   }`}
//                                 >
//                                   {product.is_active ? "Active" : "Inactive"}
//                                 </span>
//                               </ProductDeskCell>

//                               <ProductDeskCell>
//                                 <div className="flex flex-wrap gap-1">
//                                   <ActionButton onClick={(event) => { event.stopPropagation(); handleEdit(product); }}>
//                                     Edit
//                                   </ActionButton>

//                                   {product.product_type === "child" && (
//                                     <ActionButton
//                                       color="purple"
//                                       onClick={(event) => { event.stopPropagation(); handleOpenBreakModal(product); }}
//                                     >
//                                       Break
//                                     </ActionButton>
//                                   )}

//                                   <ActionButton
//                                     color="red"
//                                     disabled={deletingProductId === product.id}
//                                     onClick={(event) => { event.stopPropagation(); handleDelete(product.id); }}
//                                   >
//                                     {deletingProductId === product.id ? "Deleting..." : "Delete"}
//                                   </ActionButton>
//                                 </div>
//                               </ProductDeskCell>
//                             </div>
//                           );
//                         })
//                       )}
//                     </div>
//                   </>
//                 )}

//                 {activeTab === "pricing" && (
//                   <>
//                     <ProductDeskGridHeader columns="grid-cols-[48px_minmax(260px,1.2fr)_130px_130px_130px_130px_minmax(220px,1fr)_130px]">
//                       <ProductDeskHead>Line</ProductDeskHead>
//                       <ProductDeskHead>Product</ProductDeskHead>
//                       <ProductDeskHead>Default</ProductDeskHead>
//                       <ProductDeskHead>Active Prices</ProductDeskHead>
//                       <ProductDeskHead>Lowest</ProductDeskHead>
//                       <ProductDeskHead>Highest</ProductDeskHead>
//                       <ProductDeskHead>Price Levels</ProductDeskHead>
//                       <ProductDeskHead>Action</ProductDeskHead>
//                     </ProductDeskGridHeader>

//                     <div className="min-h-0 flex-1 overflow-auto">
//                       {filteredProducts.map((product, index) => {
//                         const prices = getAllPricesDisplay(product);
//                         const primaryPrice = getPrimaryPrice(product);
//                         const numericPrices = Array.isArray(product.prices)
//                           ? product.prices.map((price) => Number(price.price || 0))
//                           : [];
//                         const lowest = numericPrices.length ? Math.min(...numericPrices) : null;
//                         const highest = numericPrices.length ? Math.max(...numericPrices) : null;
//                         const selected = selectedProductId === product.id;

//                         return (
//                           <div
//                             key={product.id}
//                             onClick={() => setSelectedProductId(product.id)}
//                             className={`grid min-w-[1250px] cursor-pointer grid-cols-[48px_minmax(260px,1.2fr)_130px_130px_130px_130px_minmax(220px,1fr)_130px] border-b border-[#D8DFDF] text-[11px] ${
//                               selected ? "bg-[#E5F2F2]" : "bg-white hover:bg-[#F8FAFC]"
//                             }`}
//                           >
//                             <ProductDeskCell muted>{index + 1}</ProductDeskCell>
//                             <ProductDeskCell>
//                               <div className="font-semibold text-[#0C1B1B]">{product.name}</div>
//                               <div className="text-[#627474]">{product.code || product.sku || "-"}</div>
//                             </ProductDeskCell>
//                             <ProductDeskCell>{primaryPrice ? money(primaryPrice.price, primaryPrice.currency) : "-"}</ProductDeskCell>
//                             <ProductDeskCell>{prices.filter((price) => price.is_active).length}</ProductDeskCell>
//                             <ProductDeskCell>{lowest === null ? "-" : money(lowest, primaryPrice?.currency)}</ProductDeskCell>
//                             <ProductDeskCell>{highest === null ? "-" : money(highest, primaryPrice?.currency)}</ProductDeskCell>
//                             <ProductDeskCell>
//                               <div className="space-y-1">
//                                 {prices.slice(0, 4).map((price) => (
//                                   <div key={price.id} className="flex justify-between gap-2 border-b border-[#EFF2F2] pb-1 text-[10px]">
//                                     <span>{price.level}</span>
//                                     <span className="font-semibold">{price.amount}</span>
//                                   </div>
//                                 ))}
//                                 {prices.length > 4 && <div className="text-[10px] text-[#627474]">+{prices.length - 4} more</div>}
//                               </div>
//                             </ProductDeskCell>
//                             <ProductDeskCell>
//                               <ActionButton onClick={(event) => { event.stopPropagation(); handleEdit(product); }}>
//                                 Edit Prices
//                               </ActionButton>
//                             </ProductDeskCell>
//                           </div>
//                         );
//                       })}

//                       {!loading && filteredProducts.length === 0 && <ProductDeskEmpty message="No products found." />}
//                     </div>
//                   </>
//                 )}

//                 {activeTab === "stock" && (
//                   <>
//                     <ProductDeskGridHeader columns="grid-cols-[48px_minmax(260px,1.2fr)_120px_120px_120px_130px_130px_130px_150px]">
//                       <ProductDeskHead>Line</ProductDeskHead>
//                       <ProductDeskHead>Product</ProductDeskHead>
//                       <ProductDeskHead>On Hand</ProductDeskHead>
//                       <ProductDeskHead>Reserved</ProductDeskHead>
//                       <ProductDeskHead>Available</ProductDeskHead>
//                       <ProductDeskHead>Avg Cost</ProductDeskHead>
//                       <ProductDeskHead>Last Cost</ProductDeskHead>
//                       <ProductDeskHead>Total Value</ProductDeskHead>
//                       <ProductDeskHead>Rules</ProductDeskHead>
//                     </ProductDeskGridHeader>

//                     <div className="min-h-0 flex-1 overflow-auto">
//                       {filteredProducts.map((product, index) => {
//                         const stock = getStockSummary(product);
//                         const cost = getCostSummary(product);
//                         const selected = selectedProductId === product.id;

//                         return (
//                           <div
//                             key={product.id}
//                             onClick={() => setSelectedProductId(product.id)}
//                             className={`grid min-w-[1200px] cursor-pointer grid-cols-[48px_minmax(260px,1.2fr)_120px_120px_120px_130px_130px_130px_150px] border-b border-[#D8DFDF] text-[11px] ${
//                               selected ? "bg-[#E5F2F2]" : "bg-white hover:bg-[#F8FAFC]"
//                             }`}
//                           >
//                             <ProductDeskCell muted>{index + 1}</ProductDeskCell>
//                             <ProductDeskCell>
//                               <div className="font-semibold text-[#0C1B1B]">{product.name}</div>
//                               <div className="text-[#627474]">{getCategoryDisplay(product)}</div>
//                             </ProductDeskCell>
//                             <ProductDeskCell right>{quantity(stock.quantityOnHand)}</ProductDeskCell>
//                             <ProductDeskCell right>{quantity(stock.reserved)}</ProductDeskCell>
//                             <ProductDeskCell right>{quantity(stock.available)}</ProductDeskCell>
//                             <ProductDeskCell right>{cost.average_cost !== null ? money(cost.average_cost, cost.currency) : "-"}</ProductDeskCell>
//                             <ProductDeskCell right>{cost.last_cost !== null ? money(cost.last_cost, cost.currency) : "-"}</ProductDeskCell>
//                             <ProductDeskCell right>{cost.total_value !== null ? money(cost.total_value, cost.currency) : "-"}</ProductDeskCell>
//                             <ProductDeskCell>
//                               <ProductDeskInfo label="Reorder" value={quantity(product.reorder_level)} />
//                               <ProductDeskInfo label="Min" value={quantity(product.minimum_stock)} />
//                               <ProductDeskInfo label="Max" value={quantity(product.maximum_stock)} />
//                             </ProductDeskCell>
//                           </div>
//                         );
//                       })}

//                       {!loading && filteredProducts.length === 0 && <ProductDeskEmpty message="No products found." />}
//                     </div>
//                   </>
//                 )}

//                 {activeTab === "break" && (
//                   <>
//                     <ProductDeskGridHeader columns="grid-cols-[48px_minmax(260px,1.2fr)_220px_160px_160px_160px_160px]">
//                       <ProductDeskHead>Line</ProductDeskHead>
//                       <ProductDeskHead>Product</ProductDeskHead>
//                       <ProductDeskHead>Parent</ProductDeskHead>
//                       <ProductDeskHead>Child Count</ProductDeskHead>
//                       <ProductDeskHead>Parent Qty</ProductDeskHead>
//                       <ProductDeskHead>Units Inside</ProductDeskHead>
//                       <ProductDeskHead>Action</ProductDeskHead>
//                     </ProductDeskGridHeader>

//                     <div className="min-h-0 flex-1 overflow-auto">
//                       {filteredProducts.map((product, index) => {
//                         const breakRule = getBreakSummary(product);
//                         const children = getChildrenSummary(product);
//                         const selected = selectedProductId === product.id;

//                         return (
//                           <div
//                             key={product.id}
//                             onClick={() => setSelectedProductId(product.id)}
//                             className={`grid min-w-[1120px] cursor-pointer grid-cols-[48px_minmax(260px,1.2fr)_220px_160px_160px_160px_160px] border-b border-[#D8DFDF] text-[11px] ${
//                               selected ? "bg-[#E5F2F2]" : "bg-white hover:bg-[#F8FAFC]"
//                             }`}
//                           >
//                             <ProductDeskCell muted>{index + 1}</ProductDeskCell>
//                             <ProductDeskCell>
//                               <div className="font-semibold text-[#0C1B1B]">{product.name}</div>
//                               <div className="text-[#627474]">{product.product_type}</div>
//                             </ProductDeskCell>
//                             <ProductDeskCell>{product.parent?.name || "-"}</ProductDeskCell>
//                             <ProductDeskCell right>{children.count}</ProductDeskCell>
//                             <ProductDeskCell right>{breakRule ? quantity(breakRule.parentQuantity) : "-"}</ProductDeskCell>
//                             <ProductDeskCell right>{breakRule ? quantity(breakRule.unitsPerParent) : "-"}</ProductDeskCell>
//                             <ProductDeskCell>
//                               {product.product_type === "child" ? (
//                                 <ActionButton color="purple" onClick={(event) => { event.stopPropagation(); handleOpenBreakModal(product); }}>
//                                   Break
//                                 </ActionButton>
//                               ) : (
//                                 "-"
//                               )}
//                             </ProductDeskCell>
//                           </div>
//                         );
//                       })}

//                       {!loading && filteredProducts.length === 0 && <ProductDeskEmpty message="No products found." />}
//                     </div>
//                   </>
//                 )}
//               </div>
//             </div>
//           </div>
//         </div>
//       </FullScreenModal>

//       {productModalOpen && (
//         <div className="clean-modal-backdrop">
//           <div className="clean-product-modal">
//             <div className="clean-modal-header">
//               <div>
//                 <h2>{isEditing ? "Edit Product" : "Add Product"}</h2>
//                 <p>
//                   {isEditing
//                     ? "Update the product details below."
//                     : "Create a new product with identity, pricing, stock and rules."}
//                 </p>
//               </div>

//               <button
//                 type="button"
//                 onClick={handleCloseProductModal}
//                 disabled={savingProduct}
//                 className="clean-close-button"
//               >
//                 ×
//               </button>
//             </div>

//             <form onSubmit={handleSubmitProduct} className="clean-modal-body">
//               <CleanSection title="Product Identity">
//                 <CleanGrid>
//                   <CleanField label="Product Type" span="2">
//                     <select
//                       name="product_type"
//                       value={formData.product_type}
//                       onChange={handleChange}
//                       className="clean-input"
//                     >
//                       <option value="simple">Simple</option>
//                       <option value="parent">Parent</option>
//                       <option value="child">Child</option>
//                       <option value="service">Service</option>
//                     </select>
//                   </CleanField>

//                   <CleanField label="Product Name" span="4">
//                     <input
//                       type="text"
//                       name="name"
//                       value={formData.name}
//                       onChange={handleChange}
//                       className="clean-input"
//                       autoFocus
//                     />
//                   </CleanField>

//                   <CleanField label="Branch For Generation" span="3">
//                     <select
//                       name="branch_id"
//                       value={formData.branch_id}
//                       onChange={handleChange}
//                       className="clean-input"
//                     >
//                       <option value="">Select Branch</option>
//                       {getBranchOptions().map((branch) => (
//                         <option key={branch.id} value={branch.id}>
//                           {getBranchName(branch)}
//                         </option>
//                       ))}
//                     </select>
//                   </CleanField>

//                   <CleanField label="Primary Barcode" span="3">
//                     <div className="clean-input-group">
//                       <input
//                         type="text"
//                         name="barcode"
//                         value={formData.barcode}
//                         onChange={handleChange}
//                         className="clean-input"
//                       />

//                       <button
//                         type="button"
//                         onClick={handleGenerateBarcode}
//                         className="clean-small-button"
//                       >
//                         Gen
//                       </button>
//                     </div>
//                   </CleanField>

//                   <CleanField label="Product Code" span="3">
//                     <div className="clean-input-group">
//                       <input
//                         type="text"
//                         name="code"
//                         value={formData.code}
//                         onChange={handleChange}
//                         className="clean-input"
//                       />

//                       <button
//                         type="button"
//                         onClick={() => handleGenerateIdentity("product_code")}
//                         disabled={generatingType === "product_code"}
//                         className="clean-small-button"
//                       >
//                         {generatingType === "product_code" ? "..." : "Gen"}
//                       </button>
//                     </div>
//                   </CleanField>

//                   <CleanField label="SKU" span="3">
//                     <div className="clean-input-group">
//                       <input
//                         type="text"
//                         name="sku"
//                         value={formData.sku}
//                         onChange={handleChange}
//                         className="clean-input"
//                       />

//                       <button
//                         type="button"
//                         onClick={() => handleGenerateIdentity("sku")}
//                         disabled={generatingType === "sku"}
//                         className="clean-small-button"
//                       >
//                         {generatingType === "sku" ? "..." : "Gen"}
//                       </button>
//                     </div>
//                   </CleanField>

//                   <CleanField label="Category" span="3">
//                     <select
//                       name="category_id"
//                       value={formData.category_id}
//                       onChange={handleChange}
//                       className="clean-input"
//                     >
//                       <option value="">Select Category</option>
//                       {categories.map((category) => (
//                         <option key={category.id} value={category.id}>
//                           {category.name}
//                         </option>
//                       ))}
//                     </select>
//                   </CleanField>

//                   <CleanField label="Supplier" span="3">
//                     <select
//                       name="supplier_id"
//                       value={formData.supplier_id}
//                       onChange={handleChange}
//                       className="clean-input"
//                     >
//                       <option value="">Select Supplier</option>
//                       {suppliers.map((supplier) => (
//                         <option key={supplier.id} value={supplier.id}>
//                           {supplier.name}
//                         </option>
//                       ))}
//                     </select>
//                   </CleanField>

//                   <CleanField label="Base UOM" span="3">
//                     <select
//                       name="base_uom_id"
//                       value={formData.base_uom_id}
//                       onChange={handleChange}
//                       className="clean-input"
//                     >
//                       <option value="">Select UOM</option>
//                       {uoms.map((uom) => (
//                         <option key={uom.id} value={uom.id}>
//                           {uom.name} {uom.code ? `(${uom.code})` : ""}
//                         </option>
//                       ))}
//                     </select>
//                   </CleanField>

//                   <CleanField label="Tax ID" span="3">
//                     <input
//                       type="text"
//                       name="tax_id"
//                       value={formData.tax_id}
//                       onChange={handleChange}
//                       placeholder="Optional"
//                       className="clean-input"
//                     />
//                   </CleanField>
//                 </CleanGrid>
//               </CleanSection>

//               {formData.product_type === "child" && (
//                 <CleanSection title="Parent / Child Break Setup">
//                   <CleanGrid>
//                     <CleanField label="Parent Product" span="4">
//                       <select
//                         name="parent_product_id"
//                         value={formData.parent_product_id}
//                         onChange={handleChange}
//                         className="clean-input"
//                       >
//                         <option value="">Select Parent Product</option>

//                         {parentProducts.map((product) => (
//                           <option key={product.id} value={product.id}>
//                             {product.name}
//                           </option>
//                         ))}
//                       </select>
//                     </CleanField>

//                     {!isEditing && (
//                       <>
//                         <CleanCheck span="2">
//                           <input
//                             type="checkbox"
//                             name="break_rule_enabled"
//                             checked={formData.break_rule_enabled}
//                             onChange={handleChange}
//                           />
//                           Add Break Rule
//                         </CleanCheck>

//                         {formData.break_rule_enabled && (
//                           <>
//                             <CleanField label="Parent Qty" span="2">
//                               <input
//                                 type="number"
//                                 step="0.0001"
//                                 name="break_parent_quantity"
//                                 value={formData.break_parent_quantity}
//                                 onChange={handleChange}
//                                 className="clean-input"
//                               />
//                             </CleanField>

//                             <CleanField label="Units Inside Parent" span="2">
//                               <input
//                                 type="number"
//                                 step="0.0001"
//                                 name="break_units_per_parent"
//                                 value={formData.break_units_per_parent}
//                                 onChange={handleChange}
//                                 className="clean-input"
//                               />
//                             </CleanField>

//                             <CleanCheck span="2">
//                               <input
//                                 type="checkbox"
//                                 name="break_is_auto_break"
//                                 checked={formData.break_is_auto_break}
//                                 onChange={handleChange}
//                               />
//                               Auto Break
//                             </CleanCheck>

//                             <div className="clean-note clean-span-12">
//                               Example: Parent Qty 1 and Units Inside Parent 10 means
//                               1 parent product creates 10 child products.
//                             </div>
//                           </>
//                         )}
//                       </>
//                     )}
//                   </CleanGrid>
//                 </CleanSection>
//               )}

//               <CleanSection title="Stock Rules">
//                 <CleanGrid>
//                   <CleanField label="Reorder Level" span="4">
//                     <input
//                       type="number"
//                       step="0.0001"
//                       name="reorder_level"
//                       value={formData.reorder_level}
//                       onChange={handleChange}
//                       className="clean-input"
//                     />
//                   </CleanField>

//                   <CleanField label="Minimum Stock" span="4">
//                     <input
//                       type="number"
//                       step="0.0001"
//                       name="minimum_stock"
//                       value={formData.minimum_stock}
//                       onChange={handleChange}
//                       className="clean-input"
//                     />
//                   </CleanField>

//                   <CleanField label="Maximum Stock" span="4">
//                     <input
//                       type="number"
//                       step="0.0001"
//                       name="maximum_stock"
//                       value={formData.maximum_stock}
//                       onChange={handleChange}
//                       className="clean-input"
//                     />
//                   </CleanField>
//                 </CleanGrid>
//               </CleanSection>

//               {!isEditing && (
//                 <>
//                   <CleanSection
//                     title="Price Settings"
//                     right={
//                       formData.create_price_enabled && (
//                         <button
//                           type="button"
//                           onClick={addCreatePriceRow}
//                           className="clean-small-button"
//                         >
//                           Add Price Row
//                         </button>
//                       )
//                     }
//                   >
//                     <label className="clean-section-check">
//                       <input
//                         type="checkbox"
//                         name="create_price_enabled"
//                         checked={formData.create_price_enabled}
//                         onChange={handleChange}
//                       />
//                       Add prices on product creation
//                     </label>

//                     {formData.create_price_enabled && (
//                       <div className="clean-list-stack">
//                         {formData.price_rows.map((row, index) => (
//                           <PriceRowEditor
//                             key={index}
//                             index={index}
//                             row={row}
//                             branches={getBranchOptions()}
//                             priceLevels={priceLevels}
//                             currencies={baseCurrencies}
//                             getBranchName={getBranchName}
//                             onChange={handleCreatePriceRowChange}
//                             onCheckboxChange={handleCreatePriceRowCheckbox}
//                             onRemove={removeCreatePriceRow}
//                           />
//                         ))}
//                       </div>
//                     )}
//                   </CleanSection>

//                   <CleanSection title="Opening Stock">
//                     <label className="clean-section-check">
//                       <input
//                         type="checkbox"
//                         name="opening_stock_enabled"
//                         checked={formData.opening_stock_enabled}
//                         onChange={handleChange}
//                         disabled={!formData.stock_tracking_enabled}
//                       />
//                       Add opening stock
//                     </label>

//                     {formData.opening_stock_enabled && (
//                       <CleanGrid>
//                         <CleanField label="Stock Branch" span="3">
//                           <select
//                             name="opening_stock_branch_id"
//                             value={formData.opening_stock_branch_id}
//                             onChange={handleChange}
//                             className="clean-input"
//                           >
//                             <option value="">Select Branch</option>
//                             {getBranchOptions().map((branch) => (
//                               <option key={branch.id} value={branch.id}>
//                                 {getBranchName(branch)}
//                               </option>
//                             ))}
//                           </select>
//                         </CleanField>

//                         <CleanField label="Quantity" span="3">
//                           <input
//                             type="number"
//                             step="0.0001"
//                             name="opening_stock_quantity"
//                             value={formData.opening_stock_quantity}
//                             onChange={handleChange}
//                             className="clean-input"
//                           />
//                         </CleanField>

//                         <CleanField label="Unit Cost" span="3">
//                           <input
//                             type="number"
//                             step="0.0001"
//                             name="opening_stock_unit_cost"
//                             value={formData.opening_stock_unit_cost}
//                             onChange={handleChange}
//                             className="clean-input"
//                           />
//                         </CleanField>

//                         <CleanField label="Currency" span="3">
//                           <select
//                             name="opening_stock_currency"
//                             value={formData.opening_stock_currency}
//                             onChange={handleChange}
//                             className="clean-input"
//                           >
//                             <option value="">Select Currency</option>

//                             {baseCurrencies.map((currency) => (
//                               <option
//                                 key={currency.id || currency.code}
//                                 value={currency.code}
//                               >
//                                 {currency.code} - {currency.name}
//                               </option>
//                             ))}
//                           </select>
//                         </CleanField>
//                       </CleanGrid>
//                     )}
//                   </CleanSection>
//                 </>
//               )}

//               <CleanSection title="Extra Details">
//                 <CleanGrid>
//                   <CleanField label="Description" span="12">
//                     <textarea
//                       name="description"
//                       value={formData.description}
//                       onChange={handleChange}
//                       rows={3}
//                       className="clean-textarea"
//                     />
//                   </CleanField>
//                 </CleanGrid>
//               </CleanSection>

//               {!isEditing && (
//                 <CleanSection
//                   title="Additional Barcodes"
//                   right={
//                     <button
//                       type="button"
//                       onClick={handleAddAlternativeBarcode}
//                       className="clean-small-button"
//                     >
//                       Add Barcode
//                     </button>
//                   }
//                 >
//                   {formData.barcodes.length === 0 ? (
//                     <div className="clean-empty-line">
//                       No additional barcodes added.
//                     </div>
//                   ) : (
//                     <div className="clean-list-stack">
//                       {formData.barcodes.map((item, index) => (
//                         <div key={index} className="clean-barcode-row">
//                           <input
//                             type="text"
//                             value={item.barcode}
//                             onChange={(e) =>
//                               handleBarcodeChange(index, "barcode", e.target.value)
//                             }
//                             placeholder="Barcode"
//                             className="clean-input"
//                           />

//                           <select
//                             value={item.type}
//                             onChange={(e) =>
//                               handleBarcodeChange(index, "type", e.target.value)
//                             }
//                             className="clean-input"
//                           >
//                             <option value="alternative">Alternative</option>
//                             <option value="supplier">Supplier</option>
//                             <option value="primary">Primary</option>
//                           </select>

//                           <button
//                             type="button"
//                             onClick={() => handleRemoveBarcode(index)}
//                             className="clean-danger-button"
//                           >
//                             Remove
//                           </button>
//                         </div>
//                       ))}
//                     </div>
//                   )}
//                 </CleanSection>
//               )}

//               <CleanSection title="Product Rules">
//                 <div className="clean-rules-grid">
//                   {[
//                     ["stock_tracking_enabled", "Stock Tracking"],
//                     ["allow_negative_stock", "Negative Stock"],
//                     ["allow_discount", "Allow Discount"],
//                     ["taxable", "Taxable"],
//                     ["is_active", "Active"],
//                   ].map(([name, label]) => (
//                     <label key={name} className="clean-check-card">
//                       <input
//                         type="checkbox"
//                         name={name}
//                         checked={!!formData[name]}
//                         onChange={handleChange}
//                       />
//                       {label}
//                     </label>
//                   ))}
//                 </div>
//               </CleanSection>
//             </form>

//             <div className="clean-modal-footer">
//               <div className="clean-modal-status">
//                 {formData.name ? formData.name : "New product"}
//               </div>

//               <div className="clean-footer-actions">
//                 <button
//                   type="button"
//                   onClick={handleCloseProductModal}
//                   disabled={savingProduct}
//                   className="clean-secondary-button"
//                 >
//                   Cancel
//                 </button>

//                 <button
//                   type="button"
//                   disabled={savingProduct}
//                   onClick={handleSubmitProduct}
//                   className="clean-primary-button"
//                 >
//                   {savingProduct
//                     ? "Saving..."
//                     : isEditing
//                       ? "Update Product"
//                       : "Save Product"}
//                 </button>
//               </div>
//             </div>
//           </div>
//         </div>
//       )}

//       {breakModalOpen && (
//         <SmallModalShell
//           title="Break Parent Into Child"
//           subtitle={selectedBreakProduct ? `Child: ${selectedBreakProduct.name}` : ""}
//           onClose={handleCloseBreakModal}
//           maxWidth="max-w-xl"
//           footer={
//             <>
//               <button
//                 type="button"
//                 onClick={handleCloseBreakModal}
//                 className="h-8 px-4 bg-[#E4E8E8] border border-[#ACB8B8] text-[#003636] text-[11px] font-semibold uppercase"
//               >
//                 Cancel
//               </button>

//               <button
//                 type="button"
//                 disabled={savingBreak}
//                 onClick={handleSubmitBreak}
//                 className="h-8 px-4 bg-[#069495] text-white text-[11px] font-semibold uppercase disabled:opacity-60"
//               >
//                 {savingBreak ? "Breaking..." : "Break Product"}
//               </button>
//             </>
//           }
//         >
//           <form onSubmit={handleSubmitBreak} className="space-y-3">
//             <div className="border border-[#ACB8B8] bg-[#E8F7F5] p-2">
//               <div className="text-[10px] font-semibold uppercase text-[#005A5A]">
//                 Break Flow
//               </div>

//               <div className="text-[11px] font-medium mt-1 text-[#003636]">
//                 This uses the saved child break rule: parent stock goes down,
//                 child stock goes up.
//               </div>
//             </div>

//             <div className="border border-[#ACB8B8] bg-[#F8FAFC] p-2 space-y-2">
//               <Field label="Branch">
//                 <select
//                   name="branch_id"
//                   value={breakForm.branch_id}
//                   onChange={handleBreakChange}
//                   className="input-small"
//                 >
//                   <option value="">Select Branch</option>

//                   {getBranchOptions().map((branch) => (
//                     <option key={branch.id} value={branch.id}>
//                       {getBranchName(branch)}
//                     </option>
//                   ))}
//                 </select>
//               </Field>

//               <Field label="Break Times">
//                 <input
//                   type="number"
//                   min="1"
//                   step="1"
//                   name="break_times"
//                   value={breakForm.break_times}
//                   onChange={handleBreakChange}
//                   className="input-small"
//                 />
//               </Field>
//             </div>
//           </form>
//         </SmallModalShell>
//       )}

//       <ReusableMessageModal
//         open={messageModal.open}
//         type={messageModal.type}
//         title={messageModal.title}
//         message={messageModal.message}
//         onClose={closeMessageModal}
//       />

//       <AppInlineStyles />
//     </>
//   );
// };

// const ProductDeskPanel = ({ title, children }) => (
//   <div className="border border-[#AFB9B9] bg-white">
//     <div className="border-b border-[#D8DFDF] bg-[#EEF2F2] px-2 py-1 text-[11px] font-semibold text-[#324443]">
//       {title}
//     </div>
//     <div className="p-2">{children}</div>
//   </div>
// );

// const ProductDeskLabel = ({ children }) => (
//   <div className="text-[11px] text-[#465757]">{children}</div>
// );

// const ProductDeskField = ({ value, disabled = false }) => (
//   <input
//     value={value ?? ""}
//     disabled={disabled}
//     readOnly
//     className="h-7 border border-[#B9C3C3] bg-white px-2 text-[11px] outline-none disabled:bg-[#EEF2F2]"
//   />
// );

// const ProductDeskTab = ({ active, onClick, children }) => (
//   <button
//     type="button"
//     onClick={onClick}
//     className={`h-7 border px-4 text-[11px] font-semibold ${
//       active
//         ? "border-[#7C8888] bg-white text-[#0C1B1B]"
//         : "border-transparent bg-transparent text-[#465757] hover:border-[#B9C3C3] hover:bg-white"
//     }`}
//   >
//     {children}
//   </button>
// );

// const ProductDeskGridHeader = ({ columns, children }) => (
//   <div
//     className={`grid shrink-0 ${columns} border-b border-[#AFB9B9] bg-[#EEF2F2] text-[10px] font-semibold uppercase text-[#465757]`}
//   >
//     {children}
//   </div>
// );

// const ProductDeskHead = ({ children }) => (
//   <div className="border-r border-[#D8DFDF] px-2 py-2 text-[10px]">
//     {children}
//   </div>
// );

// const ProductDeskCell = ({ children, right = false, center = false, muted = false }) => (
//   <div
//     className={`min-h-[36px] border-r border-[#E4EAEA] px-2 py-2 ${
//       right ? "text-right" : center ? "text-center" : ""
//     } ${muted ? "text-[#627474]" : "text-[#1C2C2B]"}`}
//   >
//     {children}
//   </div>
// );

// const ProductDeskInfo = ({ label, value }) => (
//   <div className="flex justify-between gap-2 border-b border-[#EFF2F2] py-[2px]">
//     <span className="text-[10px] text-[#637877] uppercase">{label}</span>
//     <span className="text-[10px] font-semibold text-[#003636] text-right">{value}</span>
//   </div>
// );

// const ProductDeskPill = ({ children, tone = "default" }) => {
//   const tones = {
//     default: "border-[#CFD6D6] bg-[#F9FAFB] text-[#465757]",
//     info: "border-[#A4E5E4] bg-[#F1F8F8] text-[#057B7B]",
//     success: "border-[#ABEFC6] bg-[#ECFDF3] text-[#067647]",
//   };

//   return (
//     <span className={`inline-flex border px-2 py-[1px] text-[9px] font-semibold uppercase ${tones[tone]}`}>
//       {children}
//     </span>
//   );
// };

// const ProductDeskEmpty = ({ message }) => (
//   <div className="flex h-[220px] items-center justify-center text-[12px] text-[#627474]">
//     {message}
//   </div>
// );

// const ProductDeskCompactTool = ({
//   children,
//   onClick,
//   disabled = false,
//   green = false,
//   danger = false,
// }) => {
//   let cls = "border-[#B9C3C3] bg-white text-[#324443]";
//   if (green) cls = "border-[#027A48] bg-[#027A48] text-white";
//   if (danger) cls = "border-[#FECDCA] bg-white text-[#B42318]";

//   return (
//     <button
//       type="button"
//       disabled={disabled}
//       onClick={onClick}
//       className={`h-7 border px-3 text-[11px] font-semibold disabled:opacity-50 ${cls}`}
//     >
//       {children}
//     </button>
//   );
// };

// const ProductDeskTool = ({ children, onClick, disabled = false, green = false, danger = false }) => {
//   let cls = "border-[#B9C3C3] bg-white text-[#324443]";
//   if (green) cls = "border-[#027A48] bg-[#027A48] text-white";
//   if (danger) cls = "border-[#FECDCA] bg-white text-[#B42318]";

//   return (
//     <button
//       type="button"
//       disabled={disabled}
//       onClick={onClick}
//       className={`h-8 border px-3 text-[11px] font-semibold disabled:opacity-50 ${cls}`}
//     >
//       {children}
//     </button>
//   );
// };

// const ProductDeskTotal = ({ label, value, danger = false }) => (
//   <div className="grid grid-cols-[1fr_90px] gap-2 border-b border-[#E4EAEA] py-[2px] last:border-b-0">
//     <span className="text-[#465757]">{label}</span>
//     <span className={`text-right font-semibold ${danger ? "text-[#B42318]" : "text-[#0C1B1B]"}`}>
//       {value}
//     </span>
//   </div>
// );

// const PriceRowEditor = ({
//   index,
//   row,
//   branches,
//   priceLevels,
//   currencies,
//   getBranchName,
//   onChange,
//   onCheckboxChange,
//   onRemove,
//   title,
// }) => (
//   <div className="clean-price-row">
//     <div className="clean-price-row-header">
//       <span>{title || `Price ${index + 1}`}</span>

//       <button
//         type="button"
//         onClick={() => onRemove(index)}
//         className="clean-danger-button"
//       >
//         Remove
//       </button>
//     </div>

//     <div className="clean-price-grid">
//       <CleanField label="Branch">
//         <select
//           value={row.branch_id}
//           onChange={(e) => onChange(index, "branch_id", e.target.value)}
//           className="clean-input"
//         >
//           <option value="">Company Level</option>

//           {branches.map((branch) => (
//             <option key={branch.id} value={branch.id}>
//               {getBranchName(branch)}
//             </option>
//           ))}
//         </select>
//       </CleanField>

//       <CleanField label="Price Level">
//         <select
//           value={row.price_level_id}
//           onChange={(e) => onChange(index, "price_level_id", e.target.value)}
//           className="clean-input"
//         >
//           <option value="">Select Price Level</option>

//           {priceLevels.map((level) => (
//             <option key={level.id} value={level.id}>
//               {level.name} {level.code ? `(${level.code})` : ""}
//             </option>
//           ))}
//         </select>
//       </CleanField>

//       <CleanField label="Price">
//         <input
//           type="number"
//           step="0.0001"
//           value={row.price}
//           onChange={(e) => onChange(index, "price", e.target.value)}
//           className="clean-input"
//         />
//       </CleanField>

//       <CleanField label="Currency">
//         <select
//           value={row.currency}
//           onChange={(e) => onChange(index, "currency", e.target.value)}
//           className="clean-input"
//         >
//           <option value="">Select Currency</option>

//           {currencies.map((currency) => (
//             <option key={currency.id || currency.code} value={currency.code}>
//               {currency.code} - {currency.name}
//             </option>
//           ))}
//         </select>
//       </CleanField>

//       <CleanField label="Starts">
//         <input
//           type="date"
//           value={row.starts_at}
//           onChange={(e) => onChange(index, "starts_at", e.target.value)}
//           className="clean-input"
//         />
//       </CleanField>

//       <CleanField label="Ends">
//         <input
//           type="date"
//           value={row.ends_at}
//           onChange={(e) => onChange(index, "ends_at", e.target.value)}
//           className="clean-input"
//         />
//       </CleanField>

//       <label className="clean-check-card">
//         <input
//           type="checkbox"
//           checked={row.is_default}
//           onChange={(e) =>
//             onCheckboxChange(index, "is_default", e.target.checked)
//           }
//         />
//         Default
//       </label>

//       <label className="clean-check-card">
//         <input
//           type="checkbox"
//           checked={row.is_active}
//           onChange={(e) =>
//             onCheckboxChange(index, "is_active", e.target.checked)
//           }
//         />
//         Active
//       </label>
//     </div>
//   </div>
// );

// const InfoLine = ({ label, value }) => (
//   <div className="flex justify-between gap-2 border-b border-[#EFF2F2] py-[2px]">
//     <span className="text-[10px] font-medium text-[#637877] uppercase">
//       {label}
//     </span>

//     <span className="text-[10px] font-semibold text-[#003636] text-right">
//       {value}
//     </span>
//   </div>
// );

// const ActionButton = ({ children, color = "blue", ...props }) => {
//   const colors = {
//     blue: "bg-[#004142] hover:bg-[#003637]",
//     purple: "bg-[#7C3AED] hover:bg-[#6D28D9]",
//     red: "bg-red-700 hover:bg-red-800",
//   };

//   return (
//     <button
//       type="button"
//       className={`h-7 px-2 text-white text-[10px] font-medium uppercase disabled:opacity-60 ${colors[color]}`}
//       {...props}
//     >
//       {children}
//     </button>
//   );
// };

// const Field = ({ label, children, className = "" }) => (
//   <div className={className}>
//     <label className="block text-[10px] font-medium mb-1 uppercase text-[#324444]">
//       {label}
//     </label>

//     {children}
//   </div>
// );

// const CleanSection = ({ title, right, children }) => (
//   <section className="clean-section">
//     <div className="clean-section-header">
//       <h3>{title}</h3>
//       {right}
//     </div>

//     <div className="clean-section-body">{children}</div>
//   </section>
// );

// const CleanGrid = ({ children }) => (
//   <div className="clean-grid">{children}</div>
// );

// const CleanField = ({ label, children, span = "12" }) => (
//   <div className={`clean-field clean-span-${span}`}>
//     <label>{label}</label>
//     {children}
//   </div>
// );

// const CleanCheck = ({ children, span = "12" }) => (
//   <label className={`clean-check-card clean-span-${span}`}>
//     {children}
//   </label>
// );

// const SmallModalShell = ({
//   title,
//   subtitle,
//   color = "#004141",
//   accent = "#00BCBC",
//   onClose,
//   children,
//   footer,
//   maxWidth = "max-w-lg",
// }) => (
//   <div className="fixed inset-0 z-[100000] bg-black/50 flex items-center justify-center p-4">
//     <div className={`w-full ${maxWidth} bg-white border border-[#ACB8B8] shadow-2xl`}>
//       <div
//         className="h-10 px-3 flex items-center justify-between border-b"
//         style={{ backgroundColor: color, borderColor: accent }}
//       >
//         <div>
//           <h2 className="text-[12px] font-semibold tracking-wide text-white uppercase">
//             {title}
//           </h2>

//           {subtitle && (
//             <div className="text-[9px] text-white/80 font-medium uppercase">
//               {subtitle}
//             </div>
//           )}
//         </div>

//         <button
//           type="button"
//           onClick={onClose}
//           className="text-white text-[18px] font-medium leading-none"
//         >
//           ×
//         </button>
//       </div>

//       <div className="bg-[#DFE5E5] p-2">
//         <div className="bg-white border border-[#CAD5D5] p-3 max-h-[75vh] overflow-y-auto">
//           {children}
//         </div>
//       </div>

//       {footer && (
//         <div className="h-11 border-t border-[#CAD5D5] bg-[#F8FAFC] px-3 flex items-center justify-end gap-2">
//           {footer}
//         </div>
//       )}
//     </div>
//   </div>
// );

// const AppInlineStyles = () => (
//   <style>
//     {`
//       .input-small {
//         width: 100%;
//         height: 2rem;
//         border: 1px solid #ACB8B8;
//         padding: 0 0.5rem;
//         font-size: 11px;
//         outline: none;
//         background: white;
//         border-radius: 0;
//       }

//       .input-small:focus {
//         border-color: #00BCBC;
//         box-shadow: inset 0 0 0 1px #00BCBC;
//       }

//       button,
//       input,
//       select,
//       textarea {
//         border-radius: 0 !important;
//       }

//       .clean-modal-backdrop {
//         position: fixed;
//         inset: 0;
//         z-index: 99999;
//         background: rgba(0, 52, 52, 0.62);
//         display: flex;
//         align-items: center;
//         justify-content: center;
//         padding: 14px;
//       }

//       .clean-product-modal {
//         width: min(1280px, 98vw);
//         height: min(92vh, 860px);
//         background: #ffffff;
//         border: 1px solid #9BA5A5;
//         box-shadow: 0 20px 50px rgba(0, 0, 0, 0.32);
//         display: flex;
//         flex-direction: column;
//         color: #0D1B1B;
//       }

//       .clean-modal-header {
//         min-height: 58px;
//         flex-shrink: 0;
//         background: #F8FAFC;
//         border-bottom: 1px solid #CBD6D6;
//         padding: 10px 14px;
//         display: flex;
//         align-items: center;
//         justify-content: space-between;
//         gap: 14px;
//       }

//       .clean-modal-header h2 {
//         margin: 0;
//         font-size: 15px;
//         font-weight: 500;
//         color: #091B1B;
//       }

//       .clean-modal-header p {
//         margin: 3px 0 0;
//         font-size: 12px;
//         color: #637877;
//       }

//       .clean-close-button {
//         width: 34px;
//         height: 34px;
//         border: 1px solid #CBD6D6;
//         background: #ffffff;
//         font-size: 22px;
//         line-height: 1;
//         color: #324444;
//       }

//       .clean-close-button:hover:not(:disabled) {
//         background: #FEE2E2;
//         border-color: #EF4444;
//         color: #991B1B;
//       }

//       .clean-modal-body {
//         flex: 1;
//         min-height: 0;
//         overflow-y: auto;
//         padding: 14px;
//         background: #F1F5F5;
//       }

//       .clean-section {
//         background: #ffffff;
//         border: 1px solid #CBD6D6;
//         margin-bottom: 12px;
//       }

//       .clean-section-header {
//         min-height: 36px;
//         border-bottom: 1px solid #E2E9E9;
//         background: #F8FAFC;
//         padding: 7px 10px;
//         display: flex;
//         align-items: center;
//         justify-content: space-between;
//         gap: 10px;
//       }

//       .clean-section-header h3 {
//         margin: 0;
//         font-size: 12px;
//         font-weight: 500;
//         color: #091B1B;
//       }

//       .clean-section-body {
//         padding: 10px;
//       }

//       .clean-grid {
//         display: grid;
//         grid-template-columns: repeat(12, minmax(0, 1fr));
//         gap: 10px;
//       }

//       .clean-field label {
//         display: block;
//         margin-bottom: 4px;
//         font-size: 11px;
//         font-weight: 400;
//         color: #465858;
//       }

//       .clean-span-2 {
//         grid-column: span 2 / span 2;
//       }

//       .clean-span-3 {
//         grid-column: span 3 / span 3;
//       }

//       .clean-span-4 {
//         grid-column: span 4 / span 4;
//       }

//       .clean-span-12 {
//         grid-column: span 12 / span 12;
//       }

//       .clean-input,
//       .clean-textarea {
//         width: 100%;
//         border: 1px solid #93A6A6;
//         background: #ffffff;
//         color: #091B1B;
//         font-size: 12px;
//         outline: none;
//       }

//       .clean-input {
//         height: 32px;
//         padding: 0 8px;
//       }

//       .clean-textarea {
//         padding: 8px;
//         resize: vertical;
//       }

//       .clean-input:focus,
//       .clean-textarea:focus {
//         border-color: #069495;
//         box-shadow: inset 0 0 0 1px #93F5F4;
//       }

//       .clean-input-group {
//         display: grid;
//         grid-template-columns: 1fr auto;
//       }

//       .clean-small-button,
//       .clean-secondary-button,
//       .clean-primary-button,
//       .clean-danger-button {
//         height: 32px;
//         border: 1px solid #93A6A6;
//         padding: 0 12px;
//         font-size: 11px;
//         font-weight: 400;
//         white-space: nowrap;
//       }

//       .clean-small-button {
//         background: #F8FAFC;
//         color: #091B1B;
//       }

//       .clean-small-button:hover:not(:disabled),
//       .clean-secondary-button:hover:not(:disabled) {
//         background: #E2E9E9;
//       }

//       .clean-primary-button {
//         background: #069495;
//         border-color: #057B7C;
//         color: #ffffff;
//       }

//       .clean-primary-button:hover:not(:disabled) {
//         background: #057B7C;
//       }

//       .clean-secondary-button {
//         background: #ffffff;
//         color: #091B1B;
//       }

//       .clean-danger-button {
//         background: #ffffff;
//         border-color: #DC2626;
//         color: #B91C1C;
//       }

//       .clean-danger-button:hover:not(:disabled) {
//         background: #FEE2E2;
//       }

//       .clean-section-check,
//       .clean-check-card {
//         min-height: 32px;
//         border: 1px solid #CBD6D6;
//         background: #F8FAFC;
//         padding: 0 9px;
//         display: flex;
//         align-items: center;
//         gap: 7px;
//         font-size: 12px;
//         font-weight: 400;
//         color: #324444;
//       }

//       .clean-section-check {
//         display: inline-flex;
//         margin-bottom: 10px;
//       }

//       .clean-rules-grid {
//         display: grid;
//         grid-template-columns: repeat(5, minmax(120px, 1fr));
//         gap: 8px;
//       }

//       .clean-note {
//         border: 1px solid #F59E0B;
//         background: #FFFBEB;
//         color: #92400E;
//         padding: 9px 10px;
//         font-size: 12px;
//       }

//       .clean-list-stack {
//         display: flex;
//         flex-direction: column;
//         gap: 8px;
//       }

//       .clean-empty-line {
//         color: #637877;
//         font-size: 12px;
//       }

//       .clean-barcode-row {
//         display: grid;
//         grid-template-columns: 1fr 180px 90px;
//         gap: 8px;
//       }

//       .clean-price-row {
//         border: 1px solid #CBD6D6;
//         background: #F8FAFC;
//       }

//       .clean-price-row-header {
//         min-height: 32px;
//         border-bottom: 1px solid #E2E9E9;
//         padding: 6px 8px;
//         display: flex;
//         align-items: center;
//         justify-content: space-between;
//         font-size: 12px;
//         color: #324444;
//       }

//       .clean-price-grid {
//         padding: 8px;
//         display: grid;
//         grid-template-columns: repeat(8, minmax(0, 1fr));
//         gap: 8px;
//       }

//       .clean-modal-footer {
//         min-height: 54px;
//         flex-shrink: 0;
//         border-top: 1px solid #CBD6D6;
//         background: #F8FAFC;
//         padding: 10px 14px;
//         display: flex;
//         align-items: center;
//         justify-content: space-between;
//         gap: 12px;
//       }

//       .clean-modal-status {
//         font-size: 12px;
//         color: #637877;
//         min-width: 0;
//         overflow: hidden;
//         text-overflow: ellipsis;
//         white-space: nowrap;
//       }

//       .clean-footer-actions {
//         display: flex;
//         align-items: center;
//         justify-content: flex-end;
//         gap: 8px;
//       }

//       button:disabled,
//       input:disabled,
//       select:disabled,
//       textarea:disabled {
//         opacity: 0.55;
//         cursor: not-allowed;
//       }

//       @media (max-width: 1100px) {
//         .clean-span-2,
//         .clean-span-3,
//         .clean-span-4 {
//           grid-column: span 6 / span 6;
//         }

//         .clean-rules-grid {
//           grid-template-columns: repeat(2, minmax(120px, 1fr));
//         }

//         .clean-price-grid {
//           grid-template-columns: repeat(2, minmax(0, 1fr));
//         }
//       }

//       @media (max-width: 700px) {
//         .clean-product-modal {
//           width: 99vw;
//           height: 98vh;
//         }

//         .clean-grid {
//           grid-template-columns: 1fr;
//         }

//         .clean-span-2,
//         .clean-span-3,
//         .clean-span-4,
//         .clean-span-12 {
//           grid-column: span 1 / span 1;
//         }

//         .clean-rules-grid,
//         .clean-price-grid,
//         .clean-barcode-row {
//           grid-template-columns: 1fr;
//         }

//         .clean-modal-footer {
//           align-items: stretch;
//           flex-direction: column;
//         }

//         .clean-footer-actions {
//           width: 100%;
//         }

//         .clean-footer-actions button {
//           flex: 1;
//         }
//       }
//     `}
//   </style>
// );

// export default ProductManagementFullModal;

import React, { useEffect, useMemo, useState } from "react";
import { Download, Filter, MoreVertical, Plus, RefreshCw, Search, X } from "lucide-react";
import { Menu as MuiMenu, MenuItem as MuiMenuItem } from "@mui/material";

import FullScreenModal from "../../../components/FullScreenModal";
import { requestEnterpriseConfirmation } from "../../../components/EnterpriseConfirmHost";
import ReusableMessageModal from "../../../components/ReusableMessageModal";

import { getAuthorisedCompany } from "../../../services/AuthenticationService";
import { getBaseCurrencies } from "../../../services/CurrencyService";
import {
  getInventoryWarehouses,
  getInventoryBatches,
  getInventoryMovements,
  getInventoryAlerts,
} from "../../../services/EnterpriseInventoryService";

import {
  getProducts,
  getHsCodeSuggestions,
  approveProductHsCode,
  exportProducts,
  createProduct,
  updateProduct,
  deleteProduct,
  generateProductIdentity,
  breakProduct,
  getProductCategories,
  getProductSubCategories,
  getSuppliers,
  getUoms,
  getPriceLevels,
  uploadProductImage,
} from "../../../services/ProductService";
import { publicAssetUrl } from "../../../utils/publicAssetUrl";

const PRODUCT_TYPE_OPTIONS = [
  ["simple", "Simple"],
  ["parent", "Parent"],
  ["child", "Child"],
  ["service", "Service"],
  ["raw_material", "Raw Material"],
  ["finished_good", "Finished Good"],
  ["semi_finished", "Semi Finished"],
  ["packaging", "Packaging"],
  ["consumable", "Consumable"],
];

const PARENT_ALLOWED_TYPES = [
  "simple",
  "parent",
  "finished_good",
  "semi_finished",
  "packaging",
  "consumable",
];

const emptyFormData = {
  id: null,
  is_global: true,
  branch_id: "",
  image_url: "",
  image_path: "",

  category_id: "",
  sub_category_id: "",
  supplier_id: "",
  parent_product_id: "",
  base_uom_id: "",
  tax_id: "",

  name: "",
  code: "",
  sku: "",
  barcode: "",
  description: "",
  hs_code: "",
  hs_classification_basis: "",
  hs_tariff_confirmed: false,
  product_type: "simple",

  stock_tracking_enabled: false,
  batch_tracking_enabled: false,
  expiry_tracking_enabled: false,
  default_shelf_life_days: "",
  allow_negative_stock: false,
  allow_discount: true,
  taxable: false,
  is_active: true,

  reorder_level: "0",
  minimum_stock: "0",
  maximum_stock: "0",

  barcodes: [],

  create_price_enabled: true,
  price_rows: [],

  opening_stock_enabled: false,
  opening_stock_branch_id: "",
  opening_stock_quantity: "",
  opening_stock_unit_cost: "",
  opening_stock_currency: "",

  break_rule_enabled: false,
  break_parent_quantity: "1",
  break_units_per_parent: "1",
  break_is_auto_break: true,
};

const emptyBreakForm = {
  child_product_id: "",
  branch_id: "",
  break_times: "1",
};

const ProductManagementFullModal = ({ open, onClose, currentUser, initialTab = "register" }) => {
  const [products, setProducts] = useState([]);
  const [branches, setBranches] = useState([]);
  const [categories, setCategories] = useState([]);
  const [subCategories, setSubCategories] = useState([]);
  const [suppliers, setSuppliers] = useState([]);
  const [uoms, setUoms] = useState([]);
  const [priceLevels, setPriceLevels] = useState([]);
  const [baseCurrencies, setBaseCurrencies] = useState([]);
  const [inventoryWarehouses, setInventoryWarehouses] = useState([]);
  const [inventoryBatches, setInventoryBatches] = useState([]);
  const [inventoryMovements, setInventoryMovements] = useState([]);
  const [inventoryAlerts, setInventoryAlerts] = useState([]);

  const [loading, setLoading] = useState(false);
  const [savingProduct, setSavingProduct] = useState(false);
  const [loadingHsSuggestions, setLoadingHsSuggestions] = useState(false);
  const [hsSuggestions, setHsSuggestions] = useState(null);
  const [hsApprovalRequested, setHsApprovalRequested] = useState(false);
  const [savingBreak, setSavingBreak] = useState(false);

  const [deletingProductId, setDeletingProductId] = useState(null);
  const [generatingType, setGeneratingType] = useState("");
  const [exportingFormat, setExportingFormat] = useState("");

  const [productModalOpen, setProductModalOpen] = useState(false);
  const [selectedImageFile, setSelectedImageFile] = useState(null);
  const [selectedImagePreview, setSelectedImagePreview] = useState("");
  const [productFormTab, setProductFormTab] = useState("identity");
  const [breakModalOpen, setBreakModalOpen] = useState(false);

  const [search, setSearch] = useState("");
  const [typeFilter, setTypeFilter] = useState("");
  const [categoryFilter, setCategoryFilter] = useState("");
  const [supplierFilter, setSupplierFilter] = useState("");
  const [statusFilter, setStatusFilter] = useState("");

  const [activeTab, setActiveTab] = useState("register");
  const [selectedProductId, setSelectedProductId] = useState(null);
  const [workspaceMenuOpen, setWorkspaceMenuOpen] = useState(false);
  const [filtersOpen, setFiltersOpen] = useState(false);

  const [formData, setFormData] = useState(emptyFormData);
  const [breakForm, setBreakForm] = useState(emptyBreakForm);

  const [messageModal, setMessageModal] = useState({
    open: false,
    type: "success",
    title: "",
    message: "",
  });

  const isEditing = !!formData.id;
  const normalizedRole = String(currentUser?.role?.name || currentUser?.role_name || "").trim().toLowerCase().replace(/[ -]+/g, "_");
  const normalizedUserType = String(currentUser?.user_type || "").trim().toLowerCase().replace(/[ -]+/g, "_");
  const isSuperAdmin = Boolean(currentUser?.is_super_admin || normalizedRole === "super_admin" || normalizedRole === "super_administrator" || normalizedUserType === "super_admin");
  const assignedBranchIds = new Set((currentUser?.branches || currentUser?.assigned_branches || []).map((branch) => String(branch?.id || branch?.branch_id || branch)).filter(Boolean));
  const showMessage = ({ type = "success", title, message }) => {
    setMessageModal({ open: true, type, title, message });
  };

  const closeMessageModal = () => {
    setMessageModal((prev) => ({ ...prev, open: false }));
  };

  const getApiMessage = (source, fallback = "Request failed.") => {
    const responseData = source?.response?.data;

    if (responseData?.errors && typeof responseData.errors === "object") {
      return Object.values(responseData.errors).flat().join("\n");
    }

    if (responseData?.data && typeof responseData.data === "object") {
      const values = Object.values(responseData.data).flat();
      if (values.length > 0) return values.join("\n");
    }

    if (responseData?.error) return responseData.error;
    if (responseData?.message) return responseData.message;
    if (source?.data?.message) return source.data.message;
    if (source?.message && !source?.isAxiosError) return source.message;

    return fallback;
  };

  const normalizeListResponse = (response) => {
    const payload = response?.data;

    if (Array.isArray(payload)) return payload;
    if (Array.isArray(payload?.data)) return payload.data;
    if (Array.isArray(response?.data?.data)) return response.data.data;

    return [];
  };

  const normalizeCurrencyResponse = (response) => {
    const payload = response?.data;

    if (Array.isArray(payload)) return payload;
    if (Array.isArray(payload?.data)) return payload.data;
    if (Array.isArray(response?.data?.data)) return response.data.data;

    return [];
  };

  const getDefaultCurrencyCode = () => baseCurrencies?.[0]?.code || "";

  const money = (amount, currency = "") => {
    if (amount === null || amount === undefined || amount === "") return "-";
    const code = currency || getDefaultCurrencyCode() || "";
    return `${code} ${Number(amount || 0).toFixed(4)}`;
  };

  const quantity = (amount) => {
    if (amount === null || amount === undefined || amount === "") return "0.0000";
    return Number(amount || 0).toFixed(4);
  };

  const getBranchName = (branch) => branch?.branch_name || branch?.name || "Unnamed Branch";

  const getLoggedInBranchId = () => {
    return currentUser?.branch?.id || currentUser?.branch_id || currentUser?.branchId || "";
  };

  const getLoggedInBranch = () => {
    const loggedBranchId = getLoggedInBranchId();
    const matchedBranch = branches.find((branch) => String(branch.id) === String(loggedBranchId));
    if (matchedBranch) return matchedBranch;
    if (currentUser?.branch) return currentUser.branch;
    return null;
  };

  const getBranchOptions = () => {
    if (isSuperAdmin) return branches;
    if (assignedBranchIds.size > 0) return branches.filter((branch) => assignedBranchIds.has(String(branch.id)));
    const loggedBranch = getLoggedInBranch();
    return loggedBranch?.id ? [loggedBranch] : branches.slice(0, 1);
  };

  const canChooseProductScope = isSuperAdmin || getBranchOptions().length > 1;

  const getDefaultBranchId = () => getLoggedInBranch()?.id || branches?.[0]?.id || "";

  const loadBranches = async () => {
    const response = await getAuthorisedCompany();

    const company = response?.company || response?.data?.company || null;

    const companyBranches =
      response?.company?.branches ||
      response?.data?.branches ||
      response?.data?.company?.branches ||
      company?.branches ||
      [];

    setBranches(companyBranches);
  };

  const getProductFilterParams = () => {
    const params = { per_page: 100, include_all_branches: canChooseProductScope ? 1 : 0 };

    if (search.trim()) params.q = search.trim();
    if (typeFilter) params.product_type = typeFilter;
    if (categoryFilter) params.category_id = categoryFilter;
    if (supplierFilter) params.supplier_id = supplierFilter;
    if (statusFilter !== "") params.is_active = statusFilter;

    return params;
  };

  const loadProducts = async () => {
    const response = await getProducts(getProductFilterParams());
    setProducts(normalizeListResponse(response));
  };

  const loadCategories = async () => {
    const response = await getProductCategories({ per_page: 100 });
    setCategories(normalizeListResponse(response));
  };

  const loadSubCategories = async () => {
    const response = await getProductSubCategories({ per_page: 500 });
    setSubCategories(normalizeListResponse(response));
  };

  const loadSuppliers = async () => {
    const response = await getSuppliers({ per_page: 100 });
    setSuppliers(normalizeListResponse(response));
  };

  const loadUoms = async () => {
    const response = await getUoms();
    setUoms(normalizeListResponse(response));
  };

  const loadPriceLevels = async () => {
    const response = await getPriceLevels();
    setPriceLevels(normalizeListResponse(response));
  };

  const loadBaseCurrencyRows = async () => {
    const response = await getBaseCurrencies();
    const rows = normalizeCurrencyResponse(response);
    setBaseCurrencies(rows);
  };

  const loadData = async () => {
    try {
      setLoading(true);

      await Promise.all([
        loadBranches(),
        loadProducts(),
        loadCategories(),
        loadSubCategories(),
        loadSuppliers(),
        loadUoms(),
        loadPriceLevels(),
        loadBaseCurrencyRows(),
        getInventoryWarehouses().then((response) => setInventoryWarehouses(normalizeListResponse(response))).catch(() => setInventoryWarehouses([])),
        getInventoryBatches({ per_page: 200 }).then((response) => setInventoryBatches(normalizeListResponse(response))).catch(() => setInventoryBatches([])),
        getInventoryMovements({ per_page: 200 }).then((response) => setInventoryMovements(normalizeListResponse(response))).catch(() => setInventoryMovements([])),
        getInventoryAlerts({ per_page: 200 }).then((response) => setInventoryAlerts(normalizeListResponse(response))).catch(() => setInventoryAlerts([])),
      ]);
    } catch (error) {
      showMessage({ type: "error", title: "Load Failed", message: getApiMessage(error) });
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (open) {
      setActiveTab(["register", "pricing", "stock", "warehouses", "batches", "movements", "alerts", "break"].includes(initialTab) ? initialTab : "register");
      loadData();
    }
  }, [open, initialTab]);

  useEffect(() => {
    if (!open) return;

    const timer = setTimeout(() => {
      loadProducts().catch((error) => {
        showMessage({ type: "error", title: "Filter Failed", message: getApiMessage(error) });
      });
    }, 350);

    return () => clearTimeout(timer);
  }, [search, typeFilter, categoryFilter, supplierFilter, statusFilter]);

  const parentProducts = useMemo(() => {
    return products.filter((product) => {
      if (product.id === formData.id) return false;
      return PARENT_ALLOWED_TYPES.includes(product.product_type);
    });
  }, [products, formData.id]);

  const filteredProducts = useMemo(() => {
    const q = search.toLowerCase();

    return products.filter((product) => {
      const categoryName = product?.category?.name || "";
      const supplierName = product?.supplier?.name || "";
      const uomName = product?.uom?.name || "";
      const parentName = product?.parent?.name || "";

      return (
        product?.name?.toLowerCase().includes(q) ||
        product?.code?.toLowerCase().includes(q) ||
        product?.sku?.toLowerCase().includes(q) ||
        product?.barcode?.toLowerCase().includes(q) ||
        product?.product_type?.toLowerCase().includes(q) ||
        categoryName.toLowerCase().includes(q) ||
        supplierName.toLowerCase().includes(q) ||
        uomName.toLowerCase().includes(q) ||
        parentName.toLowerCase().includes(q)
      );
    });
  }, [products, search]);

  const selectedProduct = useMemo(() => {
    return products.find((product) => product.id === selectedProductId) || null;
  }, [products, selectedProductId]);

  const registerCounts = useMemo(() => {
    return {
      total: products.length,
      active: products.filter((product) => product.is_active).length,
      inactive: products.filter((product) => !product.is_active).length,
      stockTracked: products.filter((product) => product.stock_tracking_enabled).length,
      parents: products.filter((product) => product.product_type === "parent").length,
      children: products.filter((product) => product.product_type === "child").length,
      rawMaterials: products.filter((product) => product.product_type === "raw_material").length,
      finishedGoods: products.filter((product) => product.product_type === "finished_good").length,
      noPrice: products.filter((product) => !Array.isArray(product.prices) || product.prices.length === 0).length,
    };
  }, [products]);

  const makeDefaultPriceRows = () => {
    const defaultBranchId = getDefaultBranchId();
    const defaultCurrency = getDefaultCurrencyCode();

    const defaultPriceLevel = priceLevels.find((level) => level.is_default) || priceLevels[0];

    return [
      {
        branch_id: defaultBranchId,
        price_level_id: defaultPriceLevel?.id || "",
        price: "",
        currency: defaultCurrency,
        is_default: true,
        is_active: true,
        starts_at: "",
        ends_at: "",
      },
    ];
  };

  const makeEditPriceRows = (product) => {
    if (!Array.isArray(product?.prices) || product.prices.length === 0) {
      return makeDefaultPriceRows();
    }

    return product.prices.map((price) => ({
      id: price.id,
      branch_id: price.branch_id || getDefaultBranchId(),
      price_level_id: price.price_level_id || "",
      price: price.price || "",
      currency: price.currency || getDefaultCurrencyCode(),
      is_default: !!price.is_default,
      is_active: !!price.is_active,
      starts_at: price.starts_at ? String(price.starts_at).slice(0, 10) : "",
      ends_at: price.ends_at ? String(price.ends_at).slice(0, 10) : "",
    }));
  };

  const getPrimaryPrice = (product) => {
    if (!Array.isArray(product?.prices) || product.prices.length === 0) return null;
    return product.prices.find((price) => price.is_default) || product.prices.find((price) => price.is_active) || product.prices[0];
  };

  const getAllPricesDisplay = (product) => {
    if (!Array.isArray(product?.prices) || product.prices.length === 0) return [];

    return product.prices.map((price) => ({
      id: price.id,
      level: price?.price_level?.name || "Price",
      code: price?.price_level?.code || "",
      amount: money(price.price, price.currency),
      is_default: price.is_default,
      is_active: price.is_active,
    }));
  };

  const getStockSummary = (product) => {
    const stocks = Array.isArray(product?.stocks) ? product.stocks : [];

    const quantityOnHand = stocks.reduce((sum, stock) => sum + Number(stock.quantity_on_hand || 0), 0);
    const reserved = stocks.reduce((sum, stock) => sum + Number(stock.reserved_quantity || 0), 0);
    const available = stocks.reduce((sum, stock) => sum + Number(stock.available_quantity || 0), 0);

    return { quantityOnHand, reserved, available };
  };

  const getCostSummary = (product) => {
    const costs = Array.isArray(product?.costs) ? product.costs : [];
    const firstCost = costs[0];

    return {
      average_cost: firstCost?.average_cost ?? null,
      last_cost: firstCost?.last_cost ?? null,
      total_value: firstCost?.total_value ?? null,
      currency: firstCost?.currency || getDefaultCurrencyCode(),
    };
  };

  const getBarcodeSummary = (product) => {
    const barcodes = Array.isArray(product?.barcodes) ? product.barcodes : [];
    const primary = barcodes.find((barcode) => barcode.type === "primary") || barcodes[0];

    return {
      primary: product?.barcode || primary?.barcode || "-",
      count: barcodes.length,
    };
  };

  const getBreakSummary = (product) => {
    const rule = product?.child_break_rule || product?.childBreakRule;
    if (!rule) return null;

    return {
      parentQuantity: rule.parent_quantity,
      unitsPerParent: rule.units_per_parent,
      isAutoBreak: rule.is_auto_break,
    };
  };

  const getChildrenSummary = (product) => {
    const children = Array.isArray(product?.children) ? product.children : [];
    return { count: children.length, list: children };
  };

  const getSupplierDisplay = (product) => {
    if (!product?.supplier) return "-";
    const supplier = product.supplier;
    return supplier.code ? `${supplier.name} (${supplier.code})` : supplier.name;
  };

  const getCategoryDisplay = (product) => {
    if (!product?.category) return "-";
    return product.category.code ? `${product.category.name} (${product.category.code})` : product.category.name;
  };

  const getUomDisplay = (product) => {
    if (!product?.uom) return "-";
    return `${product.uom.name}${product.uom.code ? ` (${product.uom.code})` : ""}`;
  };

  const getProductTypeLabel = (type) => {
    return PRODUCT_TYPE_OPTIONS.find(([value]) => value === type)?.[1] || type || "simple";
  };

  const getProductFlags = (product) => {
    const flags = [];
    if (product.stock_tracking_enabled) flags.push("Stock");
    if (product.allow_negative_stock) flags.push("Negative");
    if (product.allow_discount) flags.push("Discount");
    if (product.taxable) flags.push("Taxable");
    return flags;
  };

  const downloadProductReport = async (format) => {
    try {
      setExportingFormat(format);
      const response = await exportProducts(format, getProductFilterParams());
      const disposition = response.headers?.["content-disposition"] || "";
      const encodedName = disposition.match(/filename\*=UTF-8''([^;]+)/i)?.[1];
      const plainName = disposition.match(/filename="?([^";]+)"?/i)?.[1];
      const filename = encodedName ? decodeURIComponent(encodedName) : plainName || `product-management.${format}`;
      const url = window.URL.createObjectURL(response.data);
      const link = document.createElement("a");
      link.href = url;
      link.download = filename;
      document.body.appendChild(link);
      link.click();
      link.remove();
      window.URL.revokeObjectURL(url);
    } catch (error) {
      showMessage({ type: "error", title: "Export Failed", message: getApiMessage(error, "The backend could not generate this report.") });
    } finally {
      setExportingFormat("");
    }
  };

  const downloadProductsExcel = () => downloadProductReport("xlsx");
  const downloadProductsPdf = () => downloadProductReport("pdf");

  const handleOpenCreate = () => {
    setActiveTab("register");
    setSelectedProductId(null);

    const defaultBranchId = getDefaultBranchId();
    const defaultCurrency = getDefaultCurrencyCode();

    setFormData({
      ...emptyFormData,
      is_global: canChooseProductScope,
      branch_id: defaultBranchId,
      opening_stock_branch_id: defaultBranchId,
      opening_stock_currency: defaultCurrency,
      price_rows: makeDefaultPriceRows(),
    });

    setSelectedImageFile(null);
    setSelectedImagePreview("");
    setHsSuggestions(null);
    setHsApprovalRequested(false);

    setProductFormTab("identity");
    setProductModalOpen(true);
  };

  const handleCloseProductModal = (force = false) => {
    if (savingProduct && !force) return;
    setProductModalOpen(false);
    if (selectedImagePreview) URL.revokeObjectURL(selectedImagePreview);
    setSelectedImageFile(null);
    setSelectedImagePreview("");
    setFormData(emptyFormData);
    setHsSuggestions(null);
    setHsApprovalRequested(false);
    setGeneratingType("");
    setProductFormTab("identity");
  };

  const handleChange = (e) => {
    const { name, value, checked, type } = e.target;

    setFormData((prev) => {
      const next = { ...prev, [name]: type === "checkbox" ? checked : value };

      if (name === "is_global") {
        next.is_global = value === "global";
        if (!next.is_global) {
          next.branch_id = prev.branch_id || getDefaultBranchId();
          next.opening_stock_branch_id = next.branch_id;
        }
      }

      if (name === "branch_id" && !next.is_global) {
        next.opening_stock_branch_id = value;
      }

      if (name === "category_id") {
        next.sub_category_id = "";
      }

      if (name === "product_type") {
        if (value !== "child") {
          next.parent_product_id = "";
          next.break_rule_enabled = false;
          next.break_parent_quantity = "1";
          next.break_units_per_parent = "1";
          next.break_is_auto_break = true;
        }

        if (value === "service") {
          next.stock_tracking_enabled = false;
          next.opening_stock_enabled = false;
          next.allow_negative_stock = false;
        }

        // Stock management is optional. Changing a product type must not
        // silently opt the product into inventory tracking.
      }

      if (name === "stock_tracking_enabled" && !checked) {
        next.opening_stock_enabled = false;
        next.allow_negative_stock = false;
        next.batch_tracking_enabled = false;
        next.expiry_tracking_enabled = false;
        next.default_shelf_life_days = "";
      }

      if (name === "expiry_tracking_enabled") {
        next.batch_tracking_enabled = checked ? true : next.batch_tracking_enabled;
        if (!checked) next.default_shelf_life_days = "";
      }

      if (name === "create_price_enabled" && !checked) {
        next.create_price_enabled = false;
      }

      if (name === "opening_stock_enabled" && !checked) {
        next.opening_stock_enabled = false;
      }

      return next;
    });
  };

  const handleSuggestHsCode = async () => {
    if (!formData.name.trim()) {
      showMessage({ type: "error", title: "Product Name Needed", message: "Enter the product name before looking up HS headings." });
      return;
    }

    const categoryName = categories.find((item) => String(item.id) === String(formData.category_id))?.name || "";
    const query = [formData.name, categoryName, formData.description].filter(Boolean).join(" ");
    try {
      setLoadingHsSuggestions(true);
      const response = await getHsCodeSuggestions(query);
      setHsSuggestions(response?.data || null);
    } catch (error) {
      showMessage({ type: "error", title: "HS Lookup Failed", message: getApiMessage(error) });
    } finally {
      setLoadingHsSuggestions(false);
    }
  };

  const handleChooseHsSuggestion = (code) => {
    setFormData((prev) => ({ ...prev, hs_code: code, hs_classification_basis: "", hs_tariff_confirmed: false }));
    setHsApprovalRequested(true);
  };

  const handleImageSelect = (event) => {
    const file = event.target.files?.[0] || null;
    if (!file) return;
    if (selectedImagePreview) URL.revokeObjectURL(selectedImagePreview);
    setSelectedImageFile(file);
    setSelectedImagePreview(URL.createObjectURL(file));
  };

  const handleBreakChange = (e) => {
    const { name, value } = e.target;
    setBreakForm((prev) => ({ ...prev, [name]: value }));
  };

  const handleCreatePriceRowChange = (index, field, value) => {
    setFormData((prev) => ({
      ...prev,
      price_rows: prev.price_rows.map((row, rowIndex) => (rowIndex === index ? { ...row, [field]: value } : row)),
    }));
  };

  const handleCreatePriceRowCheckbox = (index, field, checked) => {
    setFormData((prev) => ({
      ...prev,
      price_rows: prev.price_rows.map((row, rowIndex) => {
        if (field === "is_default") return { ...row, is_default: rowIndex === index ? checked : false };
        return rowIndex === index ? { ...row, [field]: checked } : row;
      }),
    }));
  };

  const addCreatePriceRow = () => {
    setFormData((prev) => ({
      ...prev,
      price_rows: [
        ...prev.price_rows,
        {
          branch_id: getDefaultBranchId(),
          price_level_id: "",
          price: "",
          currency: getDefaultCurrencyCode(),
          is_default: false,
          is_active: true,
          starts_at: "",
          ends_at: "",
        },
      ],
    }));
  };

  const removeCreatePriceRow = (index) => {
    setFormData((prev) => {
      const nextRows = prev.price_rows.filter((_, rowIndex) => rowIndex !== index);

      if (nextRows.length === 0) {
        return {
          ...prev,
          price_rows: [
            {
              branch_id: getDefaultBranchId(),
              price_level_id: "",
              price: "",
              currency: getDefaultCurrencyCode(),
              is_default: true,
              is_active: true,
              starts_at: "",
              ends_at: "",
            },
          ],
        };
      }

      const hasDefault = nextRows.some((row) => row.is_default);
      return { ...prev, price_rows: hasDefault ? nextRows : nextRows.map((row, rowIndex) => ({ ...row, is_default: rowIndex === 0 })) };
    });
  };

  const generateLocalBarcode = () => {
    const prefix = "27";
    const timestamp = Date.now().toString().slice(-9);
    const random = Math.floor(Math.random() * 90 + 10).toString();
    return `${prefix}${timestamp}${random}`;
  };

  const handleGenerateBarcode = () => {
    const barcode = generateLocalBarcode();

    setFormData((prev) => ({
      ...prev,
      barcode,
      barcodes: [{ barcode, type: "primary" }, ...prev.barcodes.filter((item) => item.type !== "primary")],
    }));
  };

  const handleGenerateIdentity = async (type) => {
    try {
      setGeneratingType(type);
      const response = await generateProductIdentity({ branch_id: formData.branch_id || null, type });
      const value = response?.data?.value || response?.value || "";

      if (!value) {
        showMessage({ type: "error", title: "Generate Failed", message: "The server did not return a generated value." });
        return;
      }

      setFormData((prev) => ({
        ...prev,
        ...(type === "product_code" ? { code: value } : {}),
        ...(type === "sku" ? { sku: value } : {}),
      }));
    } catch (error) {
      showMessage({ type: "error", title: "Generate Failed", message: getApiMessage(error) });
    } finally {
      setGeneratingType("");
    }
  };

  const handleAddAlternativeBarcode = () => {
    setFormData((prev) => ({ ...prev, barcodes: [...prev.barcodes, { barcode: "", type: "alternative" }] }));
  };

  const handleBarcodeChange = (index, field, value) => {
    setFormData((prev) => ({
      ...prev,
      barcodes: prev.barcodes.map((item, itemIndex) => (itemIndex === index ? { ...item, [field]: value } : item)),
    }));
  };

  const handleRemoveBarcode = (index) => {
    setFormData((prev) => ({ ...prev, barcodes: prev.barcodes.filter((_, itemIndex) => itemIndex !== index) }));
  };

  const validateProductForm = () => {
    if (!baseCurrencies.length) {
      setProductFormTab("pricing");
      showMessage({ type: "error", title: "Currency Missing", message: "No active base currency was found. Configure currency first." });
      return false;
    }

    if (!formData.name.trim()) {
      setProductFormTab("identity");
      showMessage({ type: "error", title: "Validation Error", message: "Product name is required." });
      return false;
    }

    if (hsApprovalRequested && (!/^(?:[0-9]{4}|[0-9]{8})$/.test(formData.hs_code) || formData.hs_code === "0000" || formData.hs_code === "00000000")) {
      setProductFormTab("identity");
      showMessage({ type: "error", title: "HS Approval Needed", message: "Choose a valid HS heading from the suggestions." });
      return false;
    }

    if (hsApprovalRequested && (!formData.hs_tariff_confirmed || formData.hs_classification_basis.trim().length < 10)) {
      setProductFormTab("identity");
      showMessage({ type: "error", title: "HS Approval Needed", message: "Verify the current ZIMRA tariff and describe why this code fits the product before approving it." });
      return false;
    }

    if (!formData.base_uom_id) {
      setProductFormTab("identity");
      showMessage({ type: "error", title: "Validation Error", message: "Base UOM is required." });
      return false;
    }

    if (!formData.is_global && !formData.branch_id) {
      setProductFormTab("identity");
      showMessage({ type: "error", title: "Validation Error", message: "Select the branch where this product will be available." });
      return false;
    }

    if (!PRODUCT_TYPE_OPTIONS.some(([value]) => value === formData.product_type)) {
      setProductFormTab("identity");
      showMessage({ type: "error", title: "Validation Error", message: "Invalid product type selected." });
      return false;
    }

    if (formData.product_type === "child") {
      if (!formData.parent_product_id) {
        setProductFormTab("identity");
        showMessage({ type: "error", title: "Validation Error", message: "Child product must have a parent product." });
        return false;
      }

      if (formData.break_rule_enabled) {
        if (!formData.break_parent_quantity || Number(formData.break_parent_quantity) <= 0 || !formData.break_units_per_parent || Number(formData.break_units_per_parent) <= 0) {
          setProductFormTab("identity");
          showMessage({ type: "error", title: "Validation Error", message: "Parent quantity and units inside parent must be greater than zero." });
          return false;
        }
      }
    }

    if (formData.expiry_tracking_enabled && Number(formData.default_shelf_life_days || 0) < 1) {
      setProductFormTab("rules");
      showMessage({ type: "error", title: "Validation Error", message: "Default shelf life must be at least one day when expiry tracking is enabled." });
      return false;
    }

    if (!isEditing && formData.create_price_enabled) {
      const cleanPriceRows = formData.price_rows.filter((row) => row.price_level_id || row.price !== "");

      if (cleanPriceRows.length === 0) {
        setProductFormTab("pricing");
        showMessage({ type: "error", title: "Validation Error", message: "At least one product price is required." });
        return false;
      }

      for (let i = 0; i < cleanPriceRows.length; i += 1) {
        const row = cleanPriceRows[i];

        if (!row.price_level_id) {
          setProductFormTab("pricing");
          showMessage({ type: "error", title: "Validation Error", message: `Price level is required on price row ${i + 1}.` });
          return false;
        }

        if (row.price === "" || Number(row.price) < 0) {
          setProductFormTab("pricing");
          showMessage({ type: "error", title: "Validation Error", message: `Price must be zero or greater on price row ${i + 1}.` });
          return false;
        }

        if (!row.currency) {
          setProductFormTab("pricing");
          showMessage({ type: "error", title: "Validation Error", message: `Currency is required on price row ${i + 1}.` });
          return false;
        }
      }
    }

    if (!isEditing && formData.opening_stock_enabled) {
      if (!formData.stock_tracking_enabled) {
        setProductFormTab("stock");
        showMessage({ type: "error", title: "Validation Error", message: "Opening stock requires stock tracking to be enabled." });
        return false;
      }

      if (!formData.opening_stock_branch_id) {
        setProductFormTab("stock");
        showMessage({ type: "error", title: "Validation Error", message: "Opening stock branch is required." });
        return false;
      }

      if (!formData.opening_stock_quantity || Number(formData.opening_stock_quantity) <= 0) {
        setProductFormTab("stock");
        showMessage({ type: "error", title: "Validation Error", message: "Opening stock quantity must be greater than zero." });
        return false;
      }

      if (!formData.opening_stock_currency) {
        setProductFormTab("stock");
        showMessage({ type: "error", title: "Validation Error", message: "Opening stock currency is required." });
        return false;
      }
    }

    return true;
  };

  const buildProductPayload = () => {
    const primaryBarcode = formData.barcode ? [{ barcode: formData.barcode, type: "primary" }] : [];
    const cleanBarcodes = formData.barcodes.filter((item) => String(item.barcode || "").trim());
    const mergedBarcodes = [...primaryBarcode, ...cleanBarcodes].filter((item, index, self) => self.findIndex((barcodeItem) => barcodeItem.barcode === item.barcode) === index);

    const cleanSuppliers = formData.supplier_id
      ? [
          {
            supplier_id: formData.supplier_id,
            supplier_code: formData.code || formData.sku || null,
            last_purchase_price: Number(formData.opening_stock_unit_cost || 0),
            is_default: true,
          },
        ]
      : [];

    const payload = {
      is_global: !!formData.is_global,
      branch_id: formData.is_global ? null : formData.branch_id,
      category_id: formData.category_id || null,
      sub_category_id: formData.sub_category_id || null,
      supplier_id: formData.supplier_id || null,
      parent_product_id: formData.product_type === "child" ? formData.parent_product_id || null : null,
      base_uom_id: formData.base_uom_id,
      tax_id: formData.tax_id || null,

      name: formData.name.trim(),
      code: formData.code || null,
      sku: formData.sku || null,
      barcode: formData.barcode || null,
      description: formData.description || null,
      product_type: formData.product_type,

      stock_tracking_enabled: !!formData.stock_tracking_enabled,
      batch_tracking_enabled: !!formData.batch_tracking_enabled,
      expiry_tracking_enabled: !!formData.expiry_tracking_enabled,
      default_shelf_life_days: formData.expiry_tracking_enabled ? Number(formData.default_shelf_life_days || 0) : null,
      allow_negative_stock: !!formData.allow_negative_stock,
      allow_discount: !!formData.allow_discount,
      taxable: !!formData.taxable,
      is_active: !!formData.is_active,

      reorder_level: Number(formData.reorder_level || 0),
      minimum_stock: Number(formData.minimum_stock || 0),
      maximum_stock: Number(formData.maximum_stock || 0),
    };

    if (!isEditing) {
      payload.barcodes = mergedBarcodes;
      payload.suppliers = cleanSuppliers;

      if (formData.create_price_enabled) {
        payload.prices = formData.price_rows
          .filter((row) => row.price_level_id && row.price !== "")
          .map((row) => ({
            branch_id: row.branch_id || null,
            price_level_id: row.price_level_id,
            price: Number(row.price || 0),
            currency: row.currency,
            is_default: !!row.is_default,
            is_active: !!row.is_active,
            starts_at: row.starts_at || null,
            ends_at: row.ends_at || null,
          }));
      }

      if (formData.opening_stock_enabled && formData.stock_tracking_enabled) {
        payload.opening_stocks = [
          {
            branch_id: formData.opening_stock_branch_id,
            quantity: Number(formData.opening_stock_quantity || 0),
            unit_cost: Number(formData.opening_stock_unit_cost || 0),
            currency: formData.opening_stock_currency,
          },
        ];
      }

      if (formData.product_type === "child" && formData.break_rule_enabled) {
        payload.break_rule = {
          parent_product_id: formData.parent_product_id,
          parent_quantity: Number(formData.break_parent_quantity || 1),
          units_per_parent: Number(formData.break_units_per_parent || 1),
          is_auto_break: !!formData.break_is_auto_break,
        };
      }
    }

    return payload;
  };

  const handleSubmitProduct = async (e) => {
    if (e?.preventDefault) e.preventDefault();
    if (!validateProductForm()) return;

    try {
      setSavingProduct(true);
      const payload = buildProductPayload();
      const response = isEditing ? await updateProduct(formData.id, payload) : await createProduct(payload);
      const savedProductId = response?.data?.id || formData.id;

      if (hsApprovalRequested && savedProductId) {
        try {
          await approveProductHsCode(savedProductId, {
            hs_code: formData.hs_code,
            classification_basis: formData.hs_classification_basis.trim(),
            confirmed_current_tariff: true,
          });
        } catch (approvalError) {
          setFormData((prev) => ({ ...prev, id: savedProductId }));
          setHsApprovalRequested(true);
          showMessage({ type: "error", title: "Product Saved, HS Approval Failed", message: getApiMessage(approvalError) });
          await loadProducts();
          return;
        }
      }

      if (selectedImageFile && savedProductId) {
        await uploadProductImage(savedProductId, selectedImageFile);
      }

      showMessage({
        type: "success",
        title: isEditing ? "Product Updated" : "Product Created",
        message: getApiMessage(response, isEditing ? "Product updated successfully." : "Product created successfully."),
      });

      handleCloseProductModal(true);
      await loadProducts();
    } catch (error) {
      showMessage({ type: "error", title: isEditing ? "Update Failed" : "Create Failed", message: getApiMessage(error) });
    } finally {
      setSavingProduct(false);
    }
  };

  const handleEdit = (product) => {
    setSelectedProductId(product.id);

    const rule = product.childBreakRule || product.child_break_rule;
    const stock = Array.isArray(product.stocks) ? product.stocks[0] : null;
    const cost = Array.isArray(product.costs) ? product.costs[0] : null;

    setFormData({
      ...emptyFormData,
      id: product.id,
      is_global: product.is_global ?? !product.branch_id,
      branch_id: product.branch_id || getDefaultBranchId(),
      image_url: publicAssetUrl(product.image_path || product.image_url),
      image_path: product.image_path || "",

      category_id: product.category_id || product.category?.id || "",
      sub_category_id: product.sub_category_id || product.subCategory?.id || product.sub_category?.id || "",
      supplier_id: product.supplier_id || product.supplier?.id || "",
      parent_product_id: product.parent_product_id || product.parent?.id || "",
      base_uom_id: product.base_uom_id || product.uom?.id || "",
      tax_id: product.tax_id || product.tax?.id || "",

      name: product.name || "",
      code: product.code || "",
      sku: product.sku || "",
      barcode: product.barcode || "",
      description: product.description || "",
      hs_code: product.hs_code || "",
      product_type: product.product_type || "simple",

      stock_tracking_enabled: product.stock_tracking_enabled ?? false,
      batch_tracking_enabled: product.batch_tracking_enabled ?? false,
      expiry_tracking_enabled: product.expiry_tracking_enabled ?? false,
      default_shelf_life_days: product.default_shelf_life_days ?? "",
      allow_negative_stock: product.allow_negative_stock ?? false,
      allow_discount: product.allow_discount ?? true,
      taxable: product.taxable ?? false,
      is_active: product.is_active ?? true,

      reorder_level: product.reorder_level ?? "0",
      minimum_stock: product.minimum_stock ?? "0",
      maximum_stock: product.maximum_stock ?? "0",

      barcodes: product.barcodes || [],
      price_rows: makeEditPriceRows(product),

      opening_stock_branch_id: stock?.branch_id || getDefaultBranchId(),
      opening_stock_quantity: stock?.quantity_on_hand || "",
      opening_stock_unit_cost: cost?.last_cost || "",
      opening_stock_currency: cost?.currency || getDefaultCurrencyCode(),

      break_rule_enabled: !!rule,
      break_parent_quantity: rule?.parent_quantity || "1",
      break_units_per_parent: rule?.units_per_parent || "1",
      break_is_auto_break: rule?.is_auto_break ?? true,
    });

    setSelectedImageFile(null);
    setSelectedImagePreview("");
    setHsSuggestions(null);
    setHsApprovalRequested(false);

    setProductFormTab("identity");
    setProductModalOpen(true);
  };

  const handleDelete = async (productId) => {
    const ok = await requestEnterpriseConfirmation({
      title: "Delete Product",
      message: "Delete this product?",
      confirmLabel: "Delete product",
    });
    if (!ok) return;

    try {
      setDeletingProductId(productId);
      const response = await deleteProduct(productId);
      showMessage({ type: "success", title: "Product Deleted", message: getApiMessage(response, "Product deleted successfully.") });
      await loadProducts();
    } catch (error) {
      showMessage({ type: "error", title: "Delete Failed", message: getApiMessage(error) });
    } finally {
      setDeletingProductId(null);
    }
  };

  const handleOpenBreakModal = (childProduct) => {
    if (childProduct.product_type !== "child") {
      showMessage({ type: "error", title: "Invalid Product", message: "Only child products can be broken from parent stock." });
      return;
    }

    setBreakForm({ child_product_id: childProduct.id, branch_id: getDefaultBranchId(), break_times: "1" });
    setBreakModalOpen(true);
  };

  const handleCloseBreakModal = () => {
    setBreakModalOpen(false);
    setBreakForm(emptyBreakForm);
  };

  const validateBreakForm = () => {
    if (!breakForm.child_product_id) {
      showMessage({ type: "error", title: "Validation Error", message: "Child product is required." });
      return false;
    }

    if (!breakForm.branch_id) {
      showMessage({ type: "error", title: "Validation Error", message: "Branch is required." });
      return false;
    }

    if (!breakForm.break_times || Number(breakForm.break_times) < 1) {
      showMessage({ type: "error", title: "Validation Error", message: "Break times must be at least 1." });
      return false;
    }

    return true;
  };

  const handleSubmitBreak = async (e) => {
    if (e?.preventDefault) e.preventDefault();
    if (!validateBreakForm()) return;

    try {
      setSavingBreak(true);
      const response = await breakProduct(breakForm.child_product_id, { branch_id: breakForm.branch_id, break_times: Number(breakForm.break_times || 1) });
      showMessage({ type: "success", title: "Product Broken", message: getApiMessage(response, "Product broken successfully.") });
      handleCloseBreakModal();
      await loadProducts();
    } catch (error) {
      showMessage({ type: "error", title: "Break Failed", message: getApiMessage(error) });
    } finally {
      setSavingBreak(false);
    }
  };

  const selectedBreakProduct = useMemo(() => products.find((item) => item.id === breakForm.child_product_id), [products, breakForm.child_product_id]);

  const workspaceTitle = {
    register: "Product Register",
    pricing: "Product Pricing",
    stock: "Stock & Costing",
    warehouses: "Warehouses & Locations",
    batches: "Batches & Expiry",
    movements: "Stock Movements",
    alerts: "Inventory Alerts",
    break: "Pack Relationships",
  }[activeTab] || "Product Register";

  return (
    <>
      <FullScreenModal open={open} onClose={onClose} title={workspaceTitle.toUpperCase()} subtitle="Hospitality inventory">
        <div className="product-management-v4 h-[calc(100vh-86px)] min-h-0 overflow-hidden bg-[#F1F3F5] p-2 text-[#242424] font-normal">
          <div className="grid h-full min-h-0 grid-rows-[auto_auto_minmax(0,1fr)] border border-[#D3D8D8] bg-white shadow-[0_2px_10px_rgba(36,51,50,0.08)]">
            <div className="flex min-h-[58px] shrink-0 flex-wrap items-center justify-between gap-3 border-b border-[#D9DEDE] px-5">
              <div className="flex h-full min-w-0 items-center">
                <div>
                  <div className="text-[14px] font-semibold text-[#283838]">{workspaceTitle}</div>
                  <div className="mt-0.5 text-[10px] text-[#7A8483]">Opened from the {(["warehouses", "batches", "movements", "alerts"].includes(activeTab)) ? "Inventory" : "Products"} menu</div>
                </div>
              </div>

              <div className="relative flex items-center gap-2 py-2">
                {selectedProduct && <div className="hidden max-w-[180px] truncate text-[11px] text-[#687271] xl:block">Selected: <span className="text-[#293232]">{selectedProduct.name}</span></div>}
                <button type="button" onClick={handleOpenCreate} className="inline-flex h-9 items-center gap-2 bg-[#057B7B] px-4 text-[12px] font-medium text-white hover:bg-[#006767]"><Plus className="h-4 w-4" />Add product</button>
                <button type="button" onClick={() => setWorkspaceMenuOpen((value) => !value)} className="flex h-9 w-9 items-center justify-center border border-[#B9C3C3] bg-white text-[#505959] hover:bg-[#F4F6F7]" title="More actions"><MoreVertical className="h-4 w-4" /></button>
                {workspaceMenuOpen && <ProductActionPopover onClose={() => setWorkspaceMenuOpen(false)} items={[
                  { label: "Edit selected", disabled: !selectedProduct, action: () => handleEdit(selectedProduct) },
                  { label: "Break selected pack", disabled: !selectedProduct || selectedProduct.product_type !== "child", action: () => handleOpenBreakModal(selectedProduct) },
                  { label: exportingFormat === "xlsx" ? "Preparing spreadsheet…" : "Export spreadsheet", icon: Download, disabled: !!exportingFormat, action: downloadProductsExcel },
                  { label: exportingFormat === "pdf" ? "Preparing PDF…" : "Export PDF", icon: Download, disabled: !!exportingFormat, action: downloadProductsPdf },
                  { label: loading ? "Refreshing…" : "Refresh", icon: RefreshCw, disabled: loading, action: loadData },
                  { label: "Clear filters", icon: Filter, action: () => { setSearch(""); setTypeFilter(""); setCategoryFilter(""); setSupplierFilter(""); setStatusFilter(""); } },
                  { label: "Delete selected", danger: true, disabled: !selectedProduct, action: () => handleDelete(selectedProduct.id) },
                ]} />}
              </div>
            </div>

            <div className="shrink-0 border-b border-[#D9DEDE] bg-[#FAFBFC]">
              <div className="flex min-h-[54px] flex-wrap items-center gap-2 px-4 py-2">
                <label className="flex h-9 min-w-[280px] max-w-[560px] flex-1 items-center border border-[#B9C3C3] bg-white px-3 focus-within:border-[#069494]"><Search className="h-4 w-4 text-[#778181]" /><input type="text" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search products" className="h-full min-w-0 flex-1 border-0 bg-transparent px-2 text-[12px] outline-none" />{search && <button type="button" onClick={() => setSearch("")} className="text-[#778181]"><X className="h-3.5 w-3.5" /></button>}</label>
                <button type="button" onClick={() => setFiltersOpen((value) => !value)} className={`inline-flex h-9 items-center gap-2 border px-3 text-[12px] ${filtersOpen ? "border-[#069494] bg-[#F0F7F7] text-[#008585]" : "border-[#B9C3C3] bg-white text-[#505959]"}`}><Filter className="h-4 w-4" />Filters</button>
                <span className="ml-auto text-[11px] text-[#7A8483]">{filteredProducts.length} of {registerCounts.total} products</span>
              </div>
              {filtersOpen && <div className="grid gap-2 border-t border-[#E2E5E5] px-4 py-3 sm:grid-cols-2 lg:grid-cols-4">
                <ProductFilter value={categoryFilter} onChange={setCategoryFilter} label="Category"><option value="">All categories</option>{categories.map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}</ProductFilter>
                <ProductFilter value={supplierFilter} onChange={setSupplierFilter} label="Supplier"><option value="">All suppliers</option>{suppliers.map((supplier) => <option key={supplier.id} value={supplier.id}>{supplier.name}</option>)}</ProductFilter>
                <ProductFilter value={typeFilter} onChange={setTypeFilter} label="Type"><option value="">All types</option>{PRODUCT_TYPE_OPTIONS.map(([value, label]) => <option key={value} value={value}>{label}</option>)}</ProductFilter>
                <ProductFilter value={statusFilter} onChange={setStatusFilter} label="Status"><option value="">All statuses</option><option value="true">Active</option><option value="false">Inactive</option></ProductFilter>
              </div>}
            </div>

            <div className="min-h-0 bg-white">
              <div className="flex h-full min-h-0 flex-col">
                {activeTab === "register" && (
                  <div className="h-full overflow-auto bg-white">
                    <table><thead><tr><th>Product</th><th>Description</th><th>Code</th><th>SKU</th><th>Barcode</th><th>Category</th><th>Supplier</th><th>Type</th><th className="text-right">Price</th><th className="text-right">On Hand</th><th>Status</th><th aria-label="Actions">⋮</th></tr></thead><tbody>
                      {loading ? <tr><td colSpan="12" className="empty-cell">Loading products...</td></tr> : filteredProducts.length === 0 ? <tr><td colSpan="12" className="empty-cell">No products found.</td></tr> : (
                        filteredProducts.map((product) => {
                          const primaryPrice = getPrimaryPrice(product);
                          const stock = getStockSummary(product);
                          const barcode = getBarcodeSummary(product);

                          return (
                            <tr key={product.id} onClick={() => setSelectedProductId(product.id)}><td><strong>{product.name}</strong></td><td>{product.description || "-"}</td><td>{product.code || "-"}</td><td>{product.sku || "-"}</td><td>{barcode.primary}</td><td>{getCategoryDisplay(product)}</td><td>{getSupplierDisplay(product)}</td><td>{getProductTypeLabel(product.product_type)}</td><td className="text-right">{primaryPrice ? money(primaryPrice.price, primaryPrice.currency) : "No price"}</td><td className="text-right">{quantity(stock.quantityOnHand)}</td><td>{product.is_active ? "Active" : "Inactive"}</td><td><div className="action-menu-wrap"><RowMoreButton product={product} deleting={deletingProductId === product.id} onEdit={handleEdit} onBreak={handleOpenBreakModal} onDelete={handleDelete} /></div></td></tr>
                          );
                        })
                      )}
                    </tbody></table>
                  </div>
                )}

                {activeTab === "pricing" && (
                  <>
                    <ProductDeskGridHeader columns="grid-cols-[48px_minmax(240px,1.2fr)_130px_140px_90px_110px_130px_130px_130px_58px]">
                      <ProductDeskHead>Line</ProductDeskHead><ProductDeskHead>Product</ProductDeskHead><ProductDeskHead>Code</ProductDeskHead><ProductDeskHead>Default Price</ProductDeskHead><ProductDeskHead>Currency</ProductDeskHead><ProductDeskHead>Active Prices</ProductDeskHead><ProductDeskHead>Lowest</ProductDeskHead><ProductDeskHead>Highest</ProductDeskHead><ProductDeskHead>Price Levels</ProductDeskHead><ProductDeskHead>⋮</ProductDeskHead>
                    </ProductDeskGridHeader>
                    <div className="min-h-0 flex-1 overflow-auto">
                      {filteredProducts.map((product, index) => {
                        const prices = getAllPricesDisplay(product);
                        const primaryPrice = getPrimaryPrice(product);
                        const numericPrices = Array.isArray(product.prices) ? product.prices.map((price) => Number(price.price || 0)) : [];
                        const lowest = numericPrices.length ? Math.min(...numericPrices) : null;
                        const highest = numericPrices.length ? Math.max(...numericPrices) : null;
                        const selected = selectedProductId === product.id;

                        return (
                          <div key={product.id} onClick={() => setSelectedProductId(product.id)} className={`sx-enterprise-grid-row grid min-w-[1320px] cursor-pointer grid-cols-[48px_minmax(240px,1.2fr)_130px_140px_90px_110px_130px_130px_130px_58px] border-b border-[#D8DFDF] text-[11px] ${selected ? "sx-enterprise-grid-selected bg-[#F0F7F7]" : "bg-white hover:bg-[#F8FAFC]"}`}>
                            <ProductDeskCell muted>{index + 1}</ProductDeskCell>
                            <ProductDeskCell>{product.name}</ProductDeskCell>
                            <ProductDeskCell>{product.code || "-"}</ProductDeskCell>
                            <ProductDeskCell>{primaryPrice ? money(primaryPrice.price, primaryPrice.currency) : "-"}</ProductDeskCell>
                            <ProductDeskCell>{primaryPrice?.currency || "-"}</ProductDeskCell>
                            <ProductDeskCell>{prices.filter((price) => price.is_active).length}</ProductDeskCell>
                            <ProductDeskCell>{lowest === null ? "-" : money(lowest, primaryPrice?.currency)}</ProductDeskCell>
                            <ProductDeskCell>{highest === null ? "-" : money(highest, primaryPrice?.currency)}</ProductDeskCell>
                            <ProductDeskCell>{prices.length}</ProductDeskCell>
                            <ProductDeskCell center><RowMoreButton product={product} deleting={deletingProductId === product.id} onEdit={handleEdit} onBreak={handleOpenBreakModal} onDelete={handleDelete} /></ProductDeskCell>
                          </div>
                        );
                      })}
                      {!loading && filteredProducts.length === 0 && <ProductDeskEmpty message="No products found." />}
                    </div>
                  </>
                )}

                {activeTab === "stock" && (
                  <>
                    <ProductDeskGridHeader columns="grid-cols-[48px_minmax(230px,1.2fr)_170px_110px_110px_110px_130px_130px_130px_100px_100px_100px_58px]">
                      <ProductDeskHead>Line</ProductDeskHead><ProductDeskHead>Product</ProductDeskHead><ProductDeskHead>Category</ProductDeskHead><ProductDeskHead>On Hand</ProductDeskHead><ProductDeskHead>Reserved</ProductDeskHead><ProductDeskHead>Available</ProductDeskHead><ProductDeskHead>Avg Cost</ProductDeskHead><ProductDeskHead>Last Cost</ProductDeskHead><ProductDeskHead>Total Value</ProductDeskHead><ProductDeskHead>Reorder</ProductDeskHead><ProductDeskHead>Minimum</ProductDeskHead><ProductDeskHead>Maximum</ProductDeskHead><ProductDeskHead>⋮</ProductDeskHead>
                    </ProductDeskGridHeader>
                    <div className="min-h-0 flex-1 overflow-auto">
                      {filteredProducts.map((product, index) => {
                        const stock = getStockSummary(product);
                        const cost = getCostSummary(product);
                        const selected = selectedProductId === product.id;

                        return (
                          <div key={product.id} onClick={() => setSelectedProductId(product.id)} className={`sx-enterprise-grid-row grid min-w-[1530px] cursor-pointer grid-cols-[48px_minmax(230px,1.2fr)_170px_110px_110px_110px_130px_130px_130px_100px_100px_100px_58px] border-b border-[#D8DFDF] text-[11px] ${selected ? "sx-enterprise-grid-selected bg-[#F0F7F7]" : "bg-white hover:bg-[#F8FAFC]"}`}>
                            <ProductDeskCell muted>{index + 1}</ProductDeskCell>
                            <ProductDeskCell>{product.name}</ProductDeskCell>
                            <ProductDeskCell>{getCategoryDisplay(product)}</ProductDeskCell>
                            <ProductDeskCell right>{quantity(stock.quantityOnHand)}</ProductDeskCell>
                            <ProductDeskCell right>{quantity(stock.reserved)}</ProductDeskCell>
                            <ProductDeskCell right>{quantity(stock.available)}</ProductDeskCell>
                            <ProductDeskCell right>{cost.average_cost !== null ? money(cost.average_cost, cost.currency) : "-"}</ProductDeskCell>
                            <ProductDeskCell right>{cost.last_cost !== null ? money(cost.last_cost, cost.currency) : "-"}</ProductDeskCell>
                            <ProductDeskCell right>{cost.total_value !== null ? money(cost.total_value, cost.currency) : "-"}</ProductDeskCell>
                            <ProductDeskCell right>{quantity(product.reorder_level)}</ProductDeskCell>
                            <ProductDeskCell right>{quantity(product.minimum_stock)}</ProductDeskCell>
                            <ProductDeskCell right>{quantity(product.maximum_stock)}</ProductDeskCell>
                            <ProductDeskCell center><RowMoreButton product={product} deleting={deletingProductId === product.id} onEdit={handleEdit} onBreak={handleOpenBreakModal} onDelete={handleDelete} /></ProductDeskCell>
                          </div>
                        );
                      })}
                      {!loading && filteredProducts.length === 0 && <ProductDeskEmpty message="No products found." />}
                    </div>
                  </>
                )}

                {activeTab === "warehouses" && (
                  <div className="h-full overflow-auto bg-white"><table><thead><tr><th>Code</th><th>Warehouse</th><th>Branch</th><th>Type</th><th>Address</th><th className="text-right">Locations</th><th>Status</th></tr></thead><tbody>
                    {inventoryWarehouses.map((warehouse) => <tr key={warehouse.id}><td><strong>{warehouse.code}</strong></td><td>{warehouse.name}</td><td>{warehouse.branch_name || "Company-wide"}</td><td>{String(warehouse.warehouse_type || "general").replaceAll("_", " ")}</td><td>{warehouse.address || "-"}</td><td className="text-right">{warehouse.location_count ?? warehouse.locations?.length ?? 0}</td><td>{warehouse.is_active === false ? "Inactive" : "Active"}</td></tr>)}
                    {!inventoryWarehouses.length && <tr><td colSpan="7" className="empty-cell">No warehouses found.</td></tr>}
                  </tbody></table></div>
                )}

                {activeTab === "batches" && (
                  <div className="h-full overflow-auto bg-white"><table><thead><tr><th>Lot</th><th>Product</th><th>Branch</th><th>Warehouse / Location</th><th>Received</th><th>Expiry</th><th className="text-right">On Hand</th><th>Status</th></tr></thead><tbody>
                    {inventoryBatches.map((batch) => <tr key={batch.id}><td><strong>{batch.lot_number}</strong></td><td>{batch.product_name}</td><td>{batch.branch_name || "-"}</td><td>{batch.warehouse_name || "-"}<small className="block text-[#627474]">{batch.location_name || ""}</small></td><td>{batch.received_at || "-"}</td><td>{batch.expires_at || "No expiry"}</td><td className="text-right">{quantity(batch.quantity_on_hand)}</td><td>{batch.quality_status || batch.status}</td></tr>)}
                    {!inventoryBatches.length && <tr><td colSpan="8" className="empty-cell">No tracked batches found.</td></tr>}
                  </tbody></table></div>
                )}

                {activeTab === "movements" && (
                  <div className="h-full overflow-auto bg-white"><table><thead><tr><th>Date</th><th>Product</th><th>Branch</th><th>Warehouse / Location</th><th>Lot</th><th>Movement</th><th className="text-right">In</th><th className="text-right">Out</th><th className="text-right">Unit Cost</th><th>Reference</th></tr></thead><tbody>
                    {inventoryMovements.map((movement) => <tr key={movement.id}><td>{movement.created_at ? new Date(movement.created_at).toLocaleString() : "-"}</td><td><strong>{movement.product_name}</strong><small className="block text-[#627474]">{movement.product_code || ""}</small></td><td>{movement.branch_name || "-"}</td><td>{movement.warehouse_name || "-"}<small className="block text-[#627474]">{movement.location_name || ""}</small></td><td>{movement.lot_number || "-"}</td><td>{String(movement.movement_type || "-").replaceAll("_", " ")}</td><td className="text-right">{quantity(movement.quantity_in)}</td><td className="text-right">{quantity(movement.quantity_out)}</td><td className="text-right">{Number(movement.unit_cost || 0).toFixed(4)}</td><td>{movement.reference_type || "-"}</td></tr>)}
                    {!inventoryMovements.length && <tr><td colSpan="10" className="empty-cell">No stock movements found.</td></tr>}
                  </tbody></table></div>
                )}

                {activeTab === "alerts" && (
                  <div className="h-full overflow-auto bg-white"><table><thead><tr><th>Created</th><th>Alert</th><th>Product</th><th>Branch</th><th>Type</th><th>Severity</th><th>Status</th></tr></thead><tbody>
                    {inventoryAlerts.map((alert) => <tr key={alert.id}><td>{alert.created_at ? new Date(alert.created_at).toLocaleString() : "-"}</td><td><strong>{alert.title}</strong><small className="block text-[#627474]">{alert.message}</small></td><td>{alert.product_name || "-"}</td><td>{alert.branch_name || "-"}</td><td>{String(alert.alert_type || "-").replaceAll("_", " ")}</td><td>{alert.severity || "-"}</td><td>{alert.status || "-"}</td></tr>)}
                    {!inventoryAlerts.length && <tr><td colSpan="7" className="empty-cell">No inventory alerts found.</td></tr>}
                  </tbody></table></div>
                )}

                {activeTab === "break" && (
                  <>
                    <ProductDeskGridHeader columns="grid-cols-[48px_minmax(240px,1.2fr)_150px_220px_160px_160px_160px_58px]">
                      <ProductDeskHead>Line</ProductDeskHead><ProductDeskHead>Product</ProductDeskHead><ProductDeskHead>Type</ProductDeskHead><ProductDeskHead>Parent</ProductDeskHead><ProductDeskHead>Child Count</ProductDeskHead><ProductDeskHead>Parent Qty</ProductDeskHead><ProductDeskHead>Units Inside</ProductDeskHead><ProductDeskHead>⋮</ProductDeskHead>
                    </ProductDeskGridHeader>
                    <div className="min-h-0 flex-1 overflow-auto">
                      {filteredProducts.map((product, index) => {
                        const breakRule = getBreakSummary(product);
                        const children = getChildrenSummary(product);
                        const selected = selectedProductId === product.id;

                        return (
                          <div key={product.id} onClick={() => setSelectedProductId(product.id)} className={`sx-enterprise-grid-row grid min-w-[1210px] cursor-pointer grid-cols-[48px_minmax(240px,1.2fr)_150px_220px_160px_160px_160px_58px] border-b border-[#D8DFDF] text-[11px] ${selected ? "sx-enterprise-grid-selected bg-[#F0F7F7]" : "bg-white hover:bg-[#F8FAFC]"}`}>
                            <ProductDeskCell muted>{index + 1}</ProductDeskCell>
                            <ProductDeskCell>{product.name}</ProductDeskCell>
                            <ProductDeskCell>{getProductTypeLabel(product.product_type)}</ProductDeskCell>
                            <ProductDeskCell>{product.parent?.name || "-"}</ProductDeskCell>
                            <ProductDeskCell right>{children.count}</ProductDeskCell>
                            <ProductDeskCell right>{breakRule ? quantity(breakRule.parentQuantity) : "-"}</ProductDeskCell>
                            <ProductDeskCell right>{breakRule ? quantity(breakRule.unitsPerParent) : "-"}</ProductDeskCell>
                            <ProductDeskCell center><RowMoreButton product={product} deleting={deletingProductId === product.id} onEdit={handleEdit} onBreak={handleOpenBreakModal} onDelete={handleDelete} /></ProductDeskCell>
                          </div>
                        );
                      })}
                      {!loading && filteredProducts.length === 0 && <ProductDeskEmpty message="No products found." />}
                    </div>
                  </>
                )}
              </div>
            </div>
          </div>
        </div>
      </FullScreenModal>

      {productModalOpen && (
        <div className="clean-modal-backdrop">
          <div className="clean-product-modal compact-product-modal">
            <div className="clean-modal-header compact-modal-header">
              <div>
                <h2>{isEditing ? "Edit Product" : "Add Product"}</h2>
                <p>{isEditing ? "Update product identity, stock rules and controls." : "Create product with identity, pricing, opening stock and rules."}</p>
              </div>
              <button type="button" onClick={handleCloseProductModal} disabled={savingProduct} className="clean-close-button">x</button>
            </div>

            <div className="product-form-tabs" role="tablist" aria-label="Product form sections">
              {[
                ["identity", "Product"],
                ["pricing", "Pricing"],
                ["stock", "Stock"],
                ["rules", "Rules"],
              ].map(([value, label], index) => (
                <button key={value} type="button" role="tab" aria-selected={productFormTab === value} onClick={() => setProductFormTab(value)} className={productFormTab === value ? "active" : ""}>
                  <span>{String(index + 1).padStart(2, "0")}</span>{label}
                </button>
              ))}
            </div>

            <form onSubmit={handleSubmitProduct} className="clean-modal-body compact-modal-body">
              <div className={productFormTab === "identity" ? "contents" : "hidden"}>
              <CleanSection title="Product Identity">
                <CleanGrid>
                  <CleanField label="Product Type" span="2">
                    <select name="product_type" value={formData.product_type} onChange={handleChange} className="clean-input">
                      {PRODUCT_TYPE_OPTIONS.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                    </select>
                  </CleanField>

                  <CleanField label="Product Name" span="4">
                    <input type="text" name="name" value={formData.name} onChange={handleChange} className="clean-input" autoFocus />
                  </CleanField>

                  {canChooseProductScope && <CleanField label="Product Availability" span="3">
                    <select name="is_global" value={formData.is_global ? "global" : "branch"} onChange={handleChange} className="clean-input">
                      <option value="global">Global — every branch</option>
                      <option value="branch">Selected branch only</option>
                    </select>
                  </CleanField>}

                  <CleanField label={formData.is_global ? "Identity Branch" : "Available At Branch"} span="3">
                    <select name="branch_id" value={formData.branch_id} onChange={handleChange} disabled={!canChooseProductScope && getBranchOptions().length === 1} className="clean-input">
                      <option value="">Select Branch</option>
                      {getBranchOptions().map((branch) => <option key={branch.id} value={branch.id}>{getBranchName(branch)}</option>)}
                    </select>
                  </CleanField>

                  <CleanField label="Barcode" span="3">
                    <div className="clean-input-group">
                      <input type="text" name="barcode" value={formData.barcode} onChange={handleChange} className="clean-input" />
                      <button type="button" onClick={handleGenerateBarcode} className="clean-small-button">Gen</button>
                    </div>
                  </CleanField>

                  <CleanField label="Product Code" span="3">
                    <div className="clean-input-group">
                      <input type="text" name="code" value={formData.code} onChange={handleChange} className="clean-input" />
                      <button type="button" onClick={() => handleGenerateIdentity("product_code")} disabled={generatingType === "product_code"} className="clean-small-button">{generatingType === "product_code" ? "..." : "Gen"}</button>
                    </div>
                  </CleanField>

                  <CleanField label="SKU" span="3">
                    <div className="clean-input-group">
                      <input type="text" name="sku" value={formData.sku} onChange={handleChange} className="clean-input" />
                      <button type="button" onClick={() => handleGenerateIdentity("sku")} disabled={generatingType === "sku"} className="clean-small-button">{generatingType === "sku" ? "..." : "Gen"}</button>
                    </div>
                  </CleanField>

                  <CleanField label="Category" span="3">
                    <select name="category_id" value={formData.category_id} onChange={handleChange} className="clean-input">
                      <option value="">Select Category</option>
                      {categories.map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}
                    </select>
                  </CleanField>

                  <CleanField label="Sub Category" span="3">
                    <select name="sub_category_id" value={formData.sub_category_id} onChange={handleChange} disabled={!formData.category_id} className="clean-input">
                      <option value="">{formData.category_id ? "Select Sub Category" : "Select Category First"}</option>
                      {subCategories.filter((subCategory) => String(subCategory.category_id) === String(formData.category_id)).map((subCategory) => <option key={subCategory.id} value={subCategory.id}>{subCategory.name}{subCategory.code ? ` (${subCategory.code})` : ""}</option>)}
                    </select>
                  </CleanField>

                  <CleanField label="Supplier" span="3">
                    <select name="supplier_id" value={formData.supplier_id} onChange={handleChange} className="clean-input">
                      <option value="">Select Supplier</option>
                      {suppliers.map((supplier) => <option key={supplier.id} value={supplier.id}>{supplier.name}</option>)}
                    </select>
                  </CleanField>

                  <CleanField label="Base UOM" span="3">
                    <select name="base_uom_id" value={formData.base_uom_id} onChange={handleChange} className="clean-input">
                      <option value="">Select UOM</option>
                      {uoms.map((uom) => <option key={uom.id} value={uom.id}>{uom.name} {uom.code ? `(${uom.code})` : ""}</option>)}
                    </select>
                  </CleanField>

                  <CleanField label="Tax ID" span="3">
                    <input type="text" name="tax_id" value={formData.tax_id} onChange={handleChange} placeholder="Optional" className="clean-input" />
                  </CleanField>
                </CleanGrid>
              </CleanSection>

              <CleanSection title="Fiscal HS Code" right={<button type="button" onClick={handleSuggestHsCode} disabled={loadingHsSuggestions} className="clean-small-button">{loadingHsSuggestions ? "Looking up..." : "Suggest headings"}</button>}>
                <div className="grid gap-3">
                  <div className="text-[11px] text-[#546767]">Choose a tariff heading, check the current ZIMRA classification, then approve it. The system never invents a code from a product name.</div>
                  {hsSuggestions && (
                    <div className="grid gap-2">
                      <div className="text-[11px] text-[#546767]">{hsSuggestions.message}</div>
                      {hsSuggestions.candidates?.length > 0 && (
                        <div className="grid gap-2 md:grid-cols-2">
                          {hsSuggestions.candidates.map((candidate) => (
                            <button key={candidate.code} type="button" onClick={() => handleChooseHsSuggestion(candidate.code)} className={`border px-3 py-2 text-left text-[12px] ${formData.hs_code === candidate.code && hsApprovalRequested ? "border-[#008B8B] bg-[#EEF7F6]" : "border-[#CBD6D6] bg-white"}`}>
                              <strong>{candidate.code}</strong> · {candidate.description}
                              <span className="block pt-1 text-[11px] text-[#637877]">{candidate.check}</span>
                            </button>
                          ))}
                        </div>
                      )}
                    </div>
                  )}
                  <CleanGrid>
                    <CleanField label="Verified HS code" span="3">
                      <input type="text" value={formData.hs_code} onChange={(event) => { setFormData((prev) => ({ ...prev, hs_code: event.target.value.replace(/\D/g, "").slice(0, 8), hs_tariff_confirmed: false })); setHsApprovalRequested(true); }} inputMode="numeric" maxLength={8} placeholder="4 or 8 digits" className="clean-input" />
                    </CleanField>
                    <CleanField label="Classification basis" span="9">
                      <input type="text" name="hs_classification_basis" value={formData.hs_classification_basis} onChange={handleChange} placeholder="For footwear: upper material, outer sole material and type" className="clean-input" />
                    </CleanField>
                  </CleanGrid>
                  <div className="flex flex-wrap items-center justify-between gap-2 text-[11px]">
                    <label className="flex items-center gap-2"><input type="checkbox" name="hs_tariff_confirmed" checked={!!formData.hs_tariff_confirmed} onChange={handleChange} /> I checked this code against the current ZIMRA tariff</label>
                    <a href={hsSuggestions?.verification_url || "https://etariff.zimra.co.zw/"} target="_blank" rel="noopener noreferrer" className="text-[#007474] underline">Open ZIMRA eTariff</a>
                  </div>
                  {!formData.hs_code && <div className="text-[11px] text-[#9A6111]">VAT fiscal sales of this product remain unavailable until an HS code is approved and synced to the terminal.</div>}
                </div>
              </CleanSection>

              <CleanSection title="Product Image">
                <div className="grid gap-3 md:grid-cols-[140px_1fr]">
                  <div className="flex h-[110px] items-center justify-center overflow-hidden border border-[#CBD6D6] bg-[#F8FAFC]">
                    {selectedImagePreview || formData.image_url ? <img src={selectedImagePreview || formData.image_url} alt="Product preview" className="h-full w-full object-contain" /> : <span className="text-[11px] text-[#637877]">No product image</span>}
                  </div>
                  <div className="flex flex-col justify-center gap-2">
                    <input type="file" accept="image/png,image/jpeg,image/jpg,image/webp" onChange={handleImageSelect} className="clean-input h-auto py-1.5" />
                    <div className="text-[11px] text-[#637877]">Available for hospitality products. JPG, PNG or WebP, maximum 4MB. The image is uploaded after the product is saved.</div>
                  </div>
                </div>
              </CleanSection>

              {formData.product_type === "child" && (
                <CleanSection title="Parent / Child Break Setup">
                  <CleanGrid>
                    <CleanField label="Parent Product" span="4">
                      <select name="parent_product_id" value={formData.parent_product_id} onChange={handleChange} className="clean-input">
                        <option value="">Select Parent Product</option>
                        {parentProducts.map((product) => <option key={product.id} value={product.id}>{product.name}</option>)}
                      </select>
                    </CleanField>

                    {!isEditing && (
                      <>
                        <CleanCheck span="2"><input type="checkbox" name="break_rule_enabled" checked={formData.break_rule_enabled} onChange={handleChange} /> Add Break Rule</CleanCheck>
                        {formData.break_rule_enabled && (
                          <>
                            <CleanField label="Parent Qty" span="2"><input type="number" step="0.0001" name="break_parent_quantity" value={formData.break_parent_quantity} onChange={handleChange} className="clean-input" /></CleanField>
                            <CleanField label="Units Inside" span="2"><input type="number" step="0.0001" name="break_units_per_parent" value={formData.break_units_per_parent} onChange={handleChange} className="clean-input" /></CleanField>
                            <CleanCheck span="2"><input type="checkbox" name="break_is_auto_break" checked={formData.break_is_auto_break} onChange={handleChange} /> Auto Break</CleanCheck>
                            <div className="clean-note clean-span-12">Example: Parent Qty 1 and Units Inside 10 means 1 parent product creates 10 child products.</div>
                          </>
                        )}
                      </>
                    )}
                  </CleanGrid>
                </CleanSection>
              )}
              </div>

              <div className={productFormTab === "stock" ? "contents" : "hidden"}>
              <CleanSection title="Stock Rules">
                <CleanGrid>
                  <CleanField label="Reorder Level" span="4"><input type="number" step="0.0001" name="reorder_level" value={formData.reorder_level} onChange={handleChange} className="clean-input" /></CleanField>
                  <CleanField label="Minimum Stock" span="4"><input type="number" step="0.0001" name="minimum_stock" value={formData.minimum_stock} onChange={handleChange} className="clean-input" /></CleanField>
                  <CleanField label="Maximum Stock" span="4"><input type="number" step="0.0001" name="maximum_stock" value={formData.maximum_stock} onChange={handleChange} className="clean-input" /></CleanField>
                </CleanGrid>
              </CleanSection>
              </div>

              <div className={productFormTab === "pricing" ? "contents" : "hidden"}>
              {!isEditing ? (
                  <CleanSection title="Price Settings" right={formData.create_price_enabled && <button type="button" onClick={addCreatePriceRow} className="clean-small-button">Add Price</button>}>
                    <label className="clean-section-check compact-check"><input type="checkbox" name="create_price_enabled" checked={formData.create_price_enabled} onChange={handleChange} /> Add prices on creation</label>
                    <fieldset disabled={!formData.create_price_enabled} className={!formData.create_price_enabled ? "clean-section-disabled" : ""}>
                      <div className="compact-price-table">
                        <div className="compact-price-table-head"><span>#</span><span>Branch</span><span>Price level</span><span>Price</span><span>Currency</span><span>Start</span><span>End</span><span>Flags</span><span></span></div>
                        {formData.price_rows.map((row, index) => (
                          <CompactPriceRowEditor key={index} index={index} row={row} branches={getBranchOptions()} priceLevels={priceLevels} currencies={baseCurrencies} getBranchName={getBranchName} onChange={handleCreatePriceRowChange} onCheckboxChange={handleCreatePriceRowCheckbox} onRemove={removeCreatePriceRow} />
                        ))}
                      </div>
                    </fieldset>
                  </CleanSection>
              ) : <CleanSection title="Pricing"><div className="clean-note">Existing product prices are managed from the Pricing register. Select this product there to review all price levels.</div></CleanSection>}
              </div>

              <div className={productFormTab === "stock" ? "contents" : "hidden"}>
              {!isEditing && (
                  <CleanSection title="Opening Stock">
                    <label className="clean-section-check compact-check"><input type="checkbox" name="opening_stock_enabled" checked={formData.opening_stock_enabled} onChange={handleChange} disabled={!formData.stock_tracking_enabled} /> Add opening stock</label>
                    <fieldset disabled={!formData.opening_stock_enabled || !formData.stock_tracking_enabled} className={!formData.opening_stock_enabled || !formData.stock_tracking_enabled ? "clean-section-disabled" : ""}>
                      <CleanGrid>
                        <CleanField label="Inventory Branch" span="6"><select name="opening_stock_branch_id" value={formData.opening_stock_branch_id} onChange={handleChange} disabled={!formData.is_global} className="clean-input"><option value="">Select Branch</option>{getBranchOptions().map((branch) => <option key={branch.id} value={branch.id}>{getBranchName(branch)}</option>)}</select></CleanField>
                        <CleanField label="Quantity" span="6"><input type="number" step="0.0001" name="opening_stock_quantity" value={formData.opening_stock_quantity} onChange={handleChange} className="clean-input" /></CleanField>
                        <CleanField label="Unit Cost" span="6"><input type="number" step="0.0001" name="opening_stock_unit_cost" value={formData.opening_stock_unit_cost} onChange={handleChange} className="clean-input" /></CleanField>
                        <CleanField label="Currency" span="6"><select name="opening_stock_currency" value={formData.opening_stock_currency} onChange={handleChange} className="clean-input"><option value="">Select Currency</option>{baseCurrencies.map((currency) => <option key={currency.id || currency.code} value={currency.code}>{currency.code} - {currency.name}</option>)}</select></CleanField>
                      </CleanGrid>
                      <div className="clean-note compact-note">Choose the branch receiving this opening quantity. You can leave “Add opening stock” off and create the product with zero stock.</div>
                    </fieldset>
                  </CleanSection>
              )}
              </div>

              <div className={productFormTab === "identity" ? "contents" : "hidden"}>
              <CleanSection title="Extra Details">
                <CleanGrid>
                  <CleanField label="Description" span="12"><textarea name="description" value={formData.description} onChange={handleChange} rows={2} className="clean-textarea" /></CleanField>
                </CleanGrid>
              </CleanSection>

              {!isEditing && (
                <CleanSection title="Additional Barcodes" right={<button type="button" onClick={handleAddAlternativeBarcode} className="clean-small-button">Add Barcode</button>}>
                  {formData.barcodes.length === 0 ? <div className="clean-empty-line">No additional barcodes added.</div> : (
                    <div className="clean-list-stack">
                      {formData.barcodes.map((item, index) => (
                        <div key={index} className="clean-barcode-row">
                          <input type="text" value={item.barcode} onChange={(e) => handleBarcodeChange(index, "barcode", e.target.value)} placeholder="Barcode" className="clean-input" />
                          <select value={item.type} onChange={(e) => handleBarcodeChange(index, "type", e.target.value)} className="clean-input"><option value="alternative">Alternative</option><option value="supplier">Supplier</option><option value="primary">Primary</option></select>
                          <button type="button" onClick={() => handleRemoveBarcode(index)} className="clean-danger-button">Remove</button>
                        </div>
                      ))}
                    </div>
                  )}
                </CleanSection>
              )}
              </div>

              <div className={productFormTab === "rules" ? "contents" : "hidden"}>
              <CleanSection title="Product Rules">
                <div className="clean-rules-grid">
                  {[
                    ["stock_tracking_enabled", "Manage stock for this product"],
                    ["batch_tracking_enabled", "Track batches / lots"],
                    ["expiry_tracking_enabled", "Track expiry dates"],
                    ["allow_negative_stock", "Negative Stock"],
                    ["allow_discount", "Allow Discount"],
                    ["taxable", "Taxable"],
                    ["is_active", "Active"],
                  ].map(([name, label]) => (
                    <label key={name} className="clean-check-card"><input type="checkbox" name={name} checked={!!formData[name]} onChange={handleChange} disabled={(name === "stock_tracking_enabled" && formData.product_type === "service") || (["batch_tracking_enabled", "expiry_tracking_enabled"].includes(name) && !formData.stock_tracking_enabled)} />{label}</label>
                  ))}
                </div>
                {!formData.stock_tracking_enabled && <div className="clean-note compact-note">Stock management is optional. Enable it only when this product needs quantities, batches, expiry dates, costing, or stock alerts.</div>}
                {formData.expiry_tracking_enabled && (
                  <div className="mt-2 max-w-[260px]">
                    <CleanField label="Default Shelf Life (days)" span="12"><input type="number" min="1" name="default_shelf_life_days" value={formData.default_shelf_life_days} onChange={handleChange} className="clean-input" /></CleanField>
                  </div>
                )}
              </CleanSection>
              </div>
            </form>

            <div className="clean-modal-footer compact-modal-footer">
              <div className="clean-modal-status">{formData.name ? formData.name : "New product"}</div>
              <div className="clean-footer-actions">
                <button type="button" onClick={handleCloseProductModal} disabled={savingProduct} className="clean-secondary-button">Cancel</button>
                <button type="button" disabled={savingProduct} onClick={handleSubmitProduct} className="clean-primary-button">{savingProduct ? "Saving..." : isEditing ? "Update Product" : "Save Product"}</button>
              </div>
            </div>
          </div>
        </div>
      )}

      {breakModalOpen && (
        <SmallModalShell title="Break Parent Into Child" subtitle={selectedBreakProduct ? `Child: ${selectedBreakProduct.name}` : ""} onClose={handleCloseBreakModal} maxWidth="max-w-xl" footer={<><button type="button" onClick={handleCloseBreakModal} className="h-8 px-4 bg-[#E4E8E8] border border-[#ACB8B8] text-[#003636] text-[11px] font-semibold uppercase">Cancel</button><button type="button" disabled={savingBreak} onClick={handleSubmitBreak} className="h-8 px-4 bg-[#069495] text-white text-[11px] font-semibold uppercase disabled:opacity-60">{savingBreak ? "Breaking..." : "Break Product"}</button></>}>
          <form onSubmit={handleSubmitBreak} className="space-y-3">
            <div className="border border-[#ACB8B8] bg-[#E8F7F5] p-2">
              <div className="text-[10px] font-semibold uppercase text-[#005A5A]">Break Flow</div>
              <div className="text-[11px] font-medium mt-1 text-[#003636]">This uses the saved child break rule: parent stock goes down, child stock goes up.</div>
            </div>
            <div className="border border-[#ACB8B8] bg-[#F8FAFC] p-2 space-y-2">
              <Field label="Branch"><select name="branch_id" value={breakForm.branch_id} onChange={handleBreakChange} className="input-small"><option value="">Select Branch</option>{getBranchOptions().map((branch) => <option key={branch.id} value={branch.id}>{getBranchName(branch)}</option>)}</select></Field>
              <Field label="Break Times"><input type="number" min="1" step="1" name="break_times" value={breakForm.break_times} onChange={handleBreakChange} className="input-small" /></Field>
            </div>
          </form>
        </SmallModalShell>
      )}

      <ReusableMessageModal open={messageModal.open} type={messageModal.type} title={messageModal.title} message={messageModal.message} onClose={closeMessageModal} />
      <AppInlineStyles />
    </>
  );
};

const ProductDeskGridHeader = ({ columns, children }) => <div className={`sx-enterprise-grid-head grid min-h-10 shrink-0 ${columns} border-b border-[#CAD0D0] bg-[#F4F6F8] text-[10px] font-semibold uppercase text-[#515C5B]`}>{children}</div>;
const ProductDeskHead = ({ children }) => <div className="sx-enterprise-grid-heading flex items-center border-r border-[#D9DEDE] px-3 text-[10px]">{children}</div>;
const ProductDeskCell = ({ children, right = false, center = false, muted = false }) => <div className={`sx-enterprise-grid-cell flex min-h-[36px] flex-col justify-center border-r border-[#E5E8E8] px-3 py-2 ${right ? "items-end text-right" : center ? "items-center text-center" : ""} ${muted ? "text-[#627474]" : "text-[#242424]"}`}>{children}</div>;

const ProductDeskPill = ({ children, tone = "default" }) => {
  const tones = { default: "border-[#CFD6D6] bg-[#F9FAFB] text-[#465757]", info: "border-[#A4E5E4] bg-[#F1F8F8] text-[#057B7B]", success: "border-[#ABEFC6] bg-[#ECFDF3] text-[#067647]" };
  return <span className={`inline-flex border px-2 py-[1px] text-[9px] font-semibold uppercase ${tones[tone]}`}>{children}</span>;
};

const ProductDeskEmpty = ({ message }) => <div className="flex h-[220px] items-center justify-center text-[12px] text-[#627474]">{message}</div>;

const ProductFilter = ({ label, value, onChange, children }) => <label className="relative"><span className="pointer-events-none absolute left-2 top-1 text-[8px] uppercase tracking-wide text-[#7D8585]">{label}</span><select value={value} onChange={(event) => onChange(event.target.value)} className="h-9 w-full border border-[#B9C3C3] bg-white px-2 pt-2 text-[11px] outline-none focus:border-[#069494]">{children}</select></label>;

const ProductActionPopover = ({ items, onClose }) => <><button type="button" aria-label="Close actions" onClick={onClose} className="fixed inset-0 z-[70] cursor-default bg-transparent" /><div className="absolute right-0 top-12 z-[71] w-[220px] border border-[#B9C3C3] bg-white py-1 shadow-xl">{items.map((item, index) => { const Icon = item.icon; return <button key={`${item.label}-${index}`} type="button" disabled={item.disabled} onClick={() => { if (!item.disabled) item.action?.(); onClose(); }} className={`flex min-h-9 w-full items-center gap-2 px-3 text-left text-[12px] disabled:opacity-40 ${item.danger ? "text-[#B42318] hover:bg-[#FEF3F2]" : "text-[#324443] hover:bg-[#F4F6F7]"}`}>{Icon && <Icon className="h-3.5 w-3.5" />}<span>{item.label}</span></button>; })}</div></>;

const RowMoreButton = ({ product, deleting, onEdit, onBreak, onDelete }) => {
  const [anchorEl, setAnchorEl] = useState(null);
  const close = (event) => { event?.stopPropagation?.(); setAnchorEl(null); };
  const run = (event, action) => { event.stopPropagation(); close(); action(); };
  return <>
    <button type="button" onClick={(event) => { event.stopPropagation(); setAnchorEl(event.currentTarget); }} className="flex h-7 w-8 items-center justify-center text-[#5B6565] hover:bg-[#EAEEEE]" aria-label={`Actions for ${product.name}`}><MoreVertical className="h-4 w-4" /></button>
    <MuiMenu anchorEl={anchorEl} open={Boolean(anchorEl)} onClose={close} onClick={(event) => event.stopPropagation()} slotProps={{ paper: { sx: { minWidth: 180, border: "1px solid #c9cfcf", borderRadius: 0, boxShadow: "0 8px 22px rgba(0,0,0,.16)" } } }}>
      <MuiMenuItem onClick={(event) => run(event, () => onEdit(product))} sx={{ fontSize: 12, minHeight: 36 }}>Edit product</MuiMenuItem>
      {product.product_type === "child" && <MuiMenuItem onClick={(event) => run(event, () => onBreak(product))} sx={{ fontSize: 12, minHeight: 36 }}>Break parent stock</MuiMenuItem>}
      <MuiMenuItem disabled={deleting} onClick={(event) => run(event, () => onDelete(product.id))} sx={{ fontSize: 12, minHeight: 36, color: "#b42318" }}>{deleting ? "Deleting…" : "Delete product"}</MuiMenuItem>
    </MuiMenu>
  </>;
};

const Field = ({ label, children, className = "" }) => <div className={className}><label className="block text-[10px] font-medium mb-1 uppercase text-[#324444]">{label}</label>{children}</div>;
const CleanSection = ({ title, right, children }) => <section className="clean-section"><div className="clean-section-header"><h3>{title}</h3>{right}</div><div className="clean-section-body">{children}</div></section>;
const CleanGrid = ({ children }) => <div className="clean-grid">{children}</div>;
const CleanField = ({ label, children, span = "12" }) => <div className={`clean-field clean-span-${span}`}><label>{label}</label>{children}</div>;
const CleanCheck = ({ children, span = "12" }) => <label className={`clean-check-card clean-span-${span}`}>{children}</label>;

const CompactPriceRowEditor = ({ index, row, branches, priceLevels, currencies, getBranchName, onChange, onCheckboxChange, onRemove }) => (
  <div className="compact-price-table-row">
    <span className="compact-price-index">{index + 1}</span>
    <select value={row.branch_id} onChange={(e) => onChange(index, "branch_id", e.target.value)} className="clean-input"><option value="">Company Level</option>{branches.map((branch) => <option key={branch.id} value={branch.id}>{getBranchName(branch)}</option>)}</select>
    <select value={row.price_level_id} onChange={(e) => onChange(index, "price_level_id", e.target.value)} className="clean-input"><option value="">Price Level</option>{priceLevels.map((level) => <option key={level.id} value={level.id}>{level.name} {level.code ? `(${level.code})` : ""}</option>)}</select>
    <input type="number" min="0" step="0.0001" value={row.price} onChange={(e) => onChange(index, "price", e.target.value)} placeholder="0.0000" className="clean-input" />
    <select value={row.currency} onChange={(e) => onChange(index, "currency", e.target.value)} className="clean-input"><option value="">Currency</option>{currencies.map((currency) => <option key={currency.id || currency.code} value={currency.code}>{currency.code}</option>)}</select>
    <input type="date" value={row.starts_at} onChange={(e) => onChange(index, "starts_at", e.target.value)} className="clean-input" />
    <input type="date" value={row.ends_at} onChange={(e) => onChange(index, "ends_at", e.target.value)} className="clean-input" />
    <div className="compact-price-flags"><label><input type="checkbox" checked={row.is_default} onChange={(e) => onCheckboxChange(index, "is_default", e.target.checked)} /> Default</label><label><input type="checkbox" checked={row.is_active} onChange={(e) => onCheckboxChange(index, "is_active", e.target.checked)} /> Active</label></div>
    <button type="button" onClick={() => onRemove(index)} className="compact-price-delete" aria-label={`Remove price ${index + 1}`}>×</button>
  </div>
);

const SmallModalShell = ({ title, subtitle, color = "#004141", accent = "#00BCBC", onClose, children, footer, maxWidth = "max-w-lg" }) => (
  <div className="fixed inset-0 z-[100000] bg-black/50 flex items-center justify-center p-4">
    <div className={`sx-large-modal-window ${maxWidth} bg-white border border-[#ACB8B8] shadow-2xl`} role="dialog" aria-modal="true" aria-label={title}>
      <div className="h-10 px-3 flex items-center justify-between border-b" style={{ backgroundColor: color, borderColor: accent }}>
        <div><h2 className="text-[12px] font-semibold tracking-wide text-white uppercase">{title}</h2>{subtitle && <div className="text-[9px] text-white/80 font-medium uppercase">{subtitle}</div>}</div>
        <button type="button" onClick={onClose} className="text-white text-[18px] font-medium leading-none">x</button>
      </div>
      <div className="min-h-0 flex-1 bg-[#DFE5E5] p-2"><div className="h-full bg-white border border-[#CAD5D5] p-3 overflow-y-auto">{children}</div></div>
      {footer && <div className="h-11 border-t border-[#CAD5D5] bg-[#F8FAFC] px-3 flex items-center justify-end gap-2">{footer}</div>}
    </div>
  </div>
);

const AppInlineStyles = () => (
  <style>{`
    .product-management-v4, .product-management-v4 button, .product-management-v4 input, .product-management-v4 select, .product-management-v4 textarea { font-family: "Segoe UI Variable Text", "Segoe UI Variable", "Segoe UI", Arial, sans-serif !important; }
    .product-management-v4 > div { box-shadow: 0 2px 12px rgba(36, 51, 50, 0.09) !important; }
    .input-small { width: 100%; height: 2rem; border: 1px solid #ACB8B8; padding: 0 0.5rem; font-size: 11px; outline: none; background: white; border-radius: 0; }
    .input-small:focus { border-color: #00BCBC; box-shadow: inset 0 0 0 1px #00BCBC; }
    button, input, select, textarea { border-radius: 0 !important; }

    .clean-modal-backdrop { position: fixed; inset: 0; z-index: 99999; background: rgba(0, 52, 52, 0.62); display: flex; align-items: center; justify-content: center; padding: 14px; }
    .clean-product-modal { width: min(1280px, 98vw); height: min(92vh, 860px); background: #ffffff; border: 1px solid #9BA5A5; box-shadow: 0 20px 50px rgba(0, 0, 0, 0.32); display: flex; flex-direction: column; color: #0D1B1B; }
    .compact-product-modal { height: min(90vh, 820px); }
    .clean-modal-header { min-height: 58px; flex-shrink: 0; background: #F8FAFC; border-bottom: 1px solid #CBD6D6; padding: 10px 14px; display: flex; align-items: center; justify-content: space-between; gap: 14px; }
    .compact-modal-header { min-height: 50px; padding: 8px 12px; }
    .clean-modal-header h2 { margin: 0; font-size: 15px; font-weight: 500; color: #091B1B; }
    .clean-modal-header p { margin: 3px 0 0; font-size: 12px; color: #637877; }
    .clean-close-button { width: 34px; height: 34px; border: 1px solid #CBD6D6; background: #ffffff; font-size: 18px; line-height: 1; color: #324444; }
    .clean-close-button:hover:not(:disabled) { background: #FEE2E2; border-color: #EF4444; color: #991B1B; }
    .clean-modal-body { flex: 1; min-height: 0; overflow-y: auto; padding: 14px; background: #F1F5F5; }
    .compact-modal-body { padding: 10px; }

    .clean-section { background: #ffffff; border: 1px solid #CBD6D6; margin-bottom: 10px; }
    .clean-section-header { min-height: 34px; border-bottom: 1px solid #E2E9E9; background: #F8FAFC; padding: 6px 9px; display: flex; align-items: center; justify-content: space-between; gap: 10px; }
    .clean-section-header h3 { margin: 0; font-size: 12px; font-weight: 500; color: #091B1B; }
    .clean-section-body { padding: 9px; }
    .clean-grid { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 8px; }
    .clean-field label { display: block; margin-bottom: 3px; font-size: 10.5px; font-weight: 400; color: #465858; }
    .clean-span-2 { grid-column: span 2 / span 2; }
    .clean-span-3 { grid-column: span 3 / span 3; }
    .clean-span-4 { grid-column: span 4 / span 4; }
    .clean-span-6 { grid-column: span 6 / span 6; }
    .clean-span-12 { grid-column: span 12 / span 12; }

    .clean-input, .clean-textarea { width: 100%; border: 1px solid #93A6A6; background: #ffffff; color: #091B1B; font-size: 12px; outline: none; }
    .clean-input { height: 31px; padding: 0 8px; }
    .clean-textarea { padding: 8px; resize: vertical; }
    .clean-input:focus, .clean-textarea:focus { border-color: #069495; box-shadow: inset 0 0 0 1px #93F5F4; }
    .clean-input-group { display: grid; grid-template-columns: 1fr auto; }
    .clean-small-button, .clean-secondary-button, .clean-primary-button, .clean-danger-button { height: 31px; border: 1px solid #93A6A6; padding: 0 10px; font-size: 11px; font-weight: 400; white-space: nowrap; }
    .clean-small-button { background: #F8FAFC; color: #091B1B; }
    .clean-small-button:hover:not(:disabled), .clean-secondary-button:hover:not(:disabled) { background: #E2E9E9; }
    .clean-primary-button { background: #069495; border-color: #057B7C; color: #ffffff; }
    .clean-primary-button:hover:not(:disabled) { background: #057B7C; }
    .clean-secondary-button { background: #ffffff; color: #091B1B; }
    .clean-danger-button { background: #ffffff; border-color: #DC2626; color: #B91C1C; }
    .clean-danger-button:hover:not(:disabled) { background: #FEE2E2; }

    .clean-section-check, .clean-check-card { min-height: 31px; border: 1px solid #CBD6D6; background: #F8FAFC; padding: 0 8px; display: flex; align-items: center; gap: 7px; font-size: 12px; font-weight: 400; color: #324444; }
    .clean-section-check { display: inline-flex; margin-bottom: 8px; }
    .clean-rules-grid { display: grid; grid-template-columns: repeat(5, minmax(120px, 1fr)); gap: 8px; }
    .clean-note { border: 1px solid #F59E0B; background: #FFFBEB; color: #92400E; padding: 8px 9px; font-size: 12px; }
    .clean-list-stack { display: flex; flex-direction: column; gap: 8px; }
    .clean-empty-line { color: #637877; font-size: 12px; }
    .clean-barcode-row { display: grid; grid-template-columns: 1fr 180px 90px; gap: 8px; }

    .clean-section-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 10px; align-items: start; }
    .clean-section-disabled { opacity: 0.45; pointer-events: none; }
    fieldset { border: 0; margin: 0; padding: 0; min-width: 0; }
    .compact-check { min-height: 29px; margin-bottom: 7px; }
    .compact-stack { gap: 6px; }
    .compact-note { margin-top: 8px; padding: 7px 8px; font-size: 11px; }
    .compact-price-row { border: 1px solid #CBD6D6; background: #F8FAFC; }
    .compact-price-title { min-height: 29px; border-bottom: 1px solid #E2E9E9; padding: 5px 7px; display: flex; align-items: center; justify-content: space-between; gap: 8px; font-size: 11px; color: #324444; }
    .compact-price-grid { padding: 7px; display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 6px; }
    .compact-price-grid .clean-input { height: 29px; font-size: 11px; }
    .compact-check-card { min-height: 29px; border: 1px solid #CBD6D6; background: #FFFFFF; padding: 0 8px; display: flex; align-items: center; gap: 6px; font-size: 11px; color: #324444; }
    .compact-remove { height: 25px; padding: 0 8px; font-size: 10px; }

    .clean-modal-footer { min-height: 52px; flex-shrink: 0; border-top: 1px solid #CBD6D6; background: #F8FAFC; padding: 9px 12px; display: flex; align-items: center; justify-content: space-between; gap: 12px; }
    .compact-modal-footer { min-height: 48px; }
    .clean-modal-status { font-size: 12px; color: #637877; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .clean-footer-actions { display: flex; align-items: center; justify-content: flex-end; gap: 8px; }
    button:disabled, input:disabled, select:disabled, textarea:disabled { opacity: 0.55; cursor: not-allowed; }

    @media (max-width: 1100px) {
      .clean-span-2, .clean-span-3, .clean-span-4 { grid-column: span 6 / span 6; }
      .clean-rules-grid { grid-template-columns: repeat(2, minmax(120px, 1fr)); }
      .clean-section-grid { grid-template-columns: 1fr; }
      .compact-price-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }

    @media (max-width: 700px) {
      .clean-product-modal { width: 99vw; height: 98vh; }
      .clean-grid { grid-template-columns: 1fr; }
      .clean-span-2, .clean-span-3, .clean-span-4, .clean-span-6, .clean-span-12 { grid-column: span 1 / span 1; }
      .clean-rules-grid, .compact-price-grid, .clean-barcode-row { grid-template-columns: 1fr; }
      .clean-modal-footer { align-items: stretch; flex-direction: column; }
      .clean-footer-actions { width: 100%; }
      .clean-footer-actions button { flex: 1; }
    }
  `}</style>
);

export default ProductManagementFullModal;
