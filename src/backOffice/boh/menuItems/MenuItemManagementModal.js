import React, { useEffect, useMemo, useRef, useState } from "react";
import { createPortal } from "react-dom";

import FullScreenModal from "../../../components/FullScreenModal";
import ReusableMessageModal from "../../../components/ReusableMessageModal";
import EnterpriseConfirmModal from "../../../components/EnterpriseConfirmModal";
import { publicAssetUrl } from "../../../utils/publicAssetUrl";
import { getAuthorisedCompany } from "../../../services/AuthenticationService";
import { getBaseCurrencies } from "../../../services/CurrencyService";
import { getTaxDropdown } from "../../../services/TaxService";
import {
  getProductCategories,
  getProductSubCategories,
  getPriceLevels,
  getUoms,
  generateProductIdentity,
  exportProducts,
} from "../../../services/ProductService";
import {
  buildMenuItemPayload,
  buildMenuItemPricePayload,
  createMenuItem,
  deleteMenuItem,
  getMenuItems,
  deleteMenuItemImage,
  updateMenuItem,
  uploadMenuItemImage,
  addMenuItemPrice,
} from "../../../services/MenuItemService";

const emptyFormData = {
  id: null,
  branch_id: "",
  category_id: "",
  sub_category_id: "",
  base_uom_id: "",
  tax_id: "",
  price_id: "",
  price_level_id: "",
  price: "",
  currency: "",
  original_price: "",
  original_price_level_id: "",
  original_branch_id: "",
  original_currency: "",
  name: "",
  code: "",
  sku: "",
  barcode: "",
  description: "",
  product_type: "finished_good",
  stock_tracking_enabled: false,
  allow_negative_stock: false,
  allow_discount: true,
  taxable: false,
  is_active: true,
  image_url: "",
  image_path: "",
};

const MENU_PRODUCT_TYPES = [
  ["finished_good", "Finished Good / Menu Item"],
  ["service", "Service Menu Item"],
];

const normalizeListResponse = (response) => {
  const payload = response?.data || response;
  if (Array.isArray(payload)) return payload;
  if (Array.isArray(payload?.data)) return payload.data;
  if (Array.isArray(payload?.data?.data)) return payload.data.data;
  return [];
};

const getApiMessage = (source, fallback = "Request failed.") => {
  const responseData = source?.response?.data;

  if (responseData?.errors && typeof responseData.errors === "object") {
    return Object.values(responseData.errors).flat().join("\n");
  }

  if (responseData?.message) return responseData.message;
  if (responseData?.error) return responseData.error;
  if (source?.message && !source?.isAxiosError) return source.message;
  return fallback;
};

const getProductImage = (product) => publicAssetUrl(
  product?.image_path ||
  product?.image_url ||
  product?.imageUrl ||
  product?.image ||
  product?.photo_url ||
  product?.photo,
);

const getPrimaryPrice = (product) => {
  const prices = Array.isArray(product?.prices) ? product.prices : [];
  if (!prices.length) return null;
  return (
    prices.find((price) => price.is_default) ||
    prices.find((price) => price.is_active) ||
    prices[0]
  );
};

const getUomDisplay = (product) => {
  if (!product?.uom) return "-";
  return `${product.uom.name || ""}${product.uom.code ? ` (${product.uom.code})` : ""}`;
};

const getCategoryDisplay = (product) => {
  if (!product?.category) return "-";
  return product.category.code
    ? `${product.category.name} (${product.category.code})`
    : product.category.name;
};

const getSubCategoryDisplay = (product) => {
  const subCategory =
    product?.subCategory ||
    product?.sub_category ||
    product?.sub_category_details ||
    null;

  if (!subCategory) return "-";

  return subCategory.code
    ? `${subCategory.name} (${subCategory.code})`
    : subCategory.name;
};

const getTypeLabel = (type) =>
  MENU_PRODUCT_TYPES.find(([value]) => value === type)?.[1] ||
  String(type || "-").replaceAll("_", " ");

export default function MenuItemManagementModal({
  open,
  onClose,
  currentUser = null,
  onCreateRecipeForMenuItem = null,
}) {
  const imageInputRef = useRef(null);
  const menuLoadSequenceRef = useRef(0);
  const previewObjectUrlRef = useRef("");

  const [menuItems, setMenuItems] = useState([]);
  const [branches, setBranches] = useState([]);
  const [categories, setCategories] = useState([]);
  const [subCategories, setSubCategories] = useState([]);
  const [uoms, setUoms] = useState([]);
  const [priceLevels, setPriceLevels] = useState([]);
  const [baseCurrencies, setBaseCurrencies] = useState([]);
  const [taxes, setTaxes] = useState([]);

  const [search, setSearch] = useState("");
  const [categoryFilter, setCategoryFilter] = useState("");
  const [subCategoryFilter, setSubCategoryFilter] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [typeFilter, setTypeFilter] = useState("");
  const [selectedMenuItemId, setSelectedMenuItemId] = useState(null);
  const [activeTab, setActiveTab] = useState("register");

  const [formOpen, setFormOpen] = useState(false);
  const [formData, setFormData] = useState(emptyFormData);
  const [selectedImageFile, setSelectedImageFile] = useState(null);
  const [selectedImagePreview, setSelectedImagePreview] = useState("");
  const [imageZoomOpen, setImageZoomOpen] = useState(false);
  const [deleteConfirmId, setDeleteConfirmId] = useState(null);

  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [uploadingImage, setUploadingImage] = useState(false);
  const [deletingId, setDeletingId] = useState(null);
  const [generatingType, setGeneratingType] = useState("");
  const [exportingFormat, setExportingFormat] = useState("");

  const [messageModal, setMessageModal] = useState({
    open: false,
    type: "success",
    title: "",
    message: "",
  });

  const isEditing = !!formData.id;
  const currentZoomImage = selectedImagePreview || formData.image_url || "";

  const showMessage = ({ type = "success", title, message }) => {
    setMessageModal({ open: true, type, title, message });
  };

  const closeMessageModal = () =>
    setMessageModal((prev) => ({ ...prev, open: false }));

  const defaultCurrency = useMemo(
    () => baseCurrencies?.[0]?.code || "USD",
    [baseCurrencies],
  );

  const defaultPriceLevel = useMemo(
    () =>
      priceLevels.find((level) => level.is_default) || priceLevels[0] || null,
    [priceLevels],
  );

  const selectedMenuItem = useMemo(
    () => menuItems.find((item) => item.id === selectedMenuItemId) || null,
    [menuItems, selectedMenuItemId],
  );

  const money = (amount, currency = "") => {
    if (amount === null || amount === undefined || amount === "") return "-";
    return `${currency || defaultCurrency || "USD"} ${Number(amount || 0).toFixed(4)}`;
  };

  const getLoggedInBranchId = () =>
    currentUser?.selectedBranchId ||
    currentUser?.selectedBranch?.id ||
    currentUser?.branch?.id ||
    currentUser?.branch_id ||
    currentUser?.branchId ||
    "";

  const getBranchName = (branch) =>
    branch?.branch_name || branch?.name || "Unnamed Branch";

  const getBranchOptions = () => {
    const branchId = getLoggedInBranchId();
    const matched = branches.find(
      (branch) => String(branch.id) === String(branchId),
    );
    if (matched) return [matched];
    if (currentUser?.selectedBranch?.id) return [currentUser.selectedBranch];
    if (currentUser?.branch?.id) return [currentUser.branch];
    return branches;
  };

  const defaultBranchId = () =>
    getBranchOptions()?.[0]?.id || getLoggedInBranchId() || "";

  const getFilteredSubCategoriesForCategory = (categoryId) => {
    if (!categoryId) return subCategories;

    return subCategories.filter(
      (subCategory) => String(subCategory.category_id) === String(categoryId)
    );
  };

  const getFormSubCategoryOptions = () => {
    return getFilteredSubCategoriesForCategory(formData.category_id);
  };

  const getFilterSubCategoryOptions = () => {
    return getFilteredSubCategoriesForCategory(categoryFilter);
  };

  const loadBranches = async () => {
    const response = await getAuthorisedCompany();
    const company = response?.company || response?.data?.company || null;
    const rows =
      response?.company?.branches ||
      response?.data?.branches ||
      response?.data?.company?.branches ||
      company?.branches ||
      [];
    setBranches(rows);
  };

  const loadMenuItems = async () => {
    const sequence = ++menuLoadSequenceRef.current;
    const params = {
      per_page: 500,
      branch_id: defaultBranchId() || undefined,
      branch_only: true,
      product_types:
        typeFilter || MENU_PRODUCT_TYPES.map(([value]) => value).join(","),
    };
    if (search.trim()) params.q = search.trim();
    if (categoryFilter) params.category_id = categoryFilter;
    if (subCategoryFilter) params.sub_category_id = subCategoryFilter;
    if (statusFilter !== "") params.is_active = statusFilter;

    const rows = [];
    let page = 1;
    let lastPage = 1;

    do {
      const response = await getMenuItems({ ...params, page });
      rows.push(...normalizeListResponse(response));
      const paginator = response?.data?.last_page
        ? response.data
        : response?.last_page
          ? response
          : {};
      lastPage = Math.max(Number(paginator?.last_page || 1), 1);
      page += 1;
    } while (page <= lastPage);

    if (sequence !== menuLoadSequenceRef.current) return;

    const uniqueRows = Array.from(
      new Map(rows.map((item) => [String(item.id), item])).values(),
    );
    setMenuItems(uniqueRows);
  };

  const loadData = async () => {
    try {
      setLoading(true);
      const [categoryRes, subCategoryRes, uomRes, priceLevelRes, currencyRes, taxRes] =
        await Promise.all([
          getProductCategories({ per_page: 500 }),
          getProductSubCategories({ per_page: 500 }),
          getUoms(),
          getPriceLevels(),
          getBaseCurrencies(),
          getTaxDropdown({ active_only: true }),
          loadBranches(),
        ]);

      setCategories(normalizeListResponse(categoryRes));
      setSubCategories(normalizeListResponse(subCategoryRes));
      setUoms(normalizeListResponse(uomRes));
      setPriceLevels(normalizeListResponse(priceLevelRes));
      setBaseCurrencies(normalizeListResponse(currencyRes));
      setTaxes(normalizeListResponse(taxRes));

      await loadMenuItems();
    } catch (error) {
      showMessage({
        type: "error",
        title: "Load Failed",
        message: getApiMessage(error),
      });
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (open) loadData();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  useEffect(() => {
    if (!open) return;
    const timer = setTimeout(() => {
      loadMenuItems().catch((error) => {
        showMessage({
          type: "error",
          title: "Filter Failed",
          message: getApiMessage(error),
        });
      });
    }, 350);
    return () => clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search, categoryFilter, subCategoryFilter, statusFilter, typeFilter]);

  useEffect(() => {
    if (!formOpen) return undefined;

    const onKeyDown = (event) => {
      if (event.key === "Escape" && !saving && !uploadingImage) {
        if (previewObjectUrlRef.current) {
          URL.revokeObjectURL(previewObjectUrlRef.current);
          previewObjectUrlRef.current = "";
        }
        setImageZoomOpen(false);
        setFormOpen(false);
        setFormData(emptyFormData);
        setSelectedImageFile(null);
        setSelectedImagePreview("");
        if (imageInputRef.current) imageInputRef.current.value = "";
      }
    };

    document.addEventListener("keydown", onKeyDown);
    return () => document.removeEventListener("keydown", onKeyDown);
  }, [formOpen, saving, uploadingImage]);

  useEffect(
    () => () => {
      if (previewObjectUrlRef.current) {
        URL.revokeObjectURL(previewObjectUrlRef.current);
      }
    },
    [],
  );

  const filteredMenuItems = useMemo(() => {
    const q = search.trim().toLowerCase();
    if (!q) return menuItems;

    return menuItems.filter((item) =>
      [
        item.name,
        item.code,
        item.sku,
        item.barcode,
        item.description,
        item?.category?.name,
        item?.subCategory?.name,
        item?.sub_category?.name,
      ]
        .filter(Boolean)
        .some((value) => String(value).toLowerCase().includes(q)),
    );
  }, [menuItems, search]);

  const registerCounts = useMemo(() => {
    return {
      total: menuItems.length,
      active: menuItems.filter((item) => item.is_active).length,
      inactive: menuItems.filter((item) => !item.is_active).length,
      withImage: menuItems.filter((item) => !!getProductImage(item)).length,
      noPrice: menuItems.filter((item) => !getPrimaryPrice(item)).length,
    };
  }, [menuItems]);

  const openCreate = () => {
    if (previewObjectUrlRef.current) {
      URL.revokeObjectURL(previewObjectUrlRef.current);
      previewObjectUrlRef.current = "";
    }
    setSelectedMenuItemId(null);
    setSelectedImageFile(null);
    setSelectedImagePreview("");
    setFormData({
      ...emptyFormData,
      branch_id: defaultBranchId(),
      base_uom_id: uoms?.[0]?.id || "",
      price_level_id: defaultPriceLevel?.id || "",
      currency: defaultCurrency,
      product_type: "finished_good",
      stock_tracking_enabled: false,
      allow_negative_stock: false,
    });
    setFormOpen(true);
  };

  const openEdit = (product) => {
    if (previewObjectUrlRef.current) {
      URL.revokeObjectURL(previewObjectUrlRef.current);
      previewObjectUrlRef.current = "";
    }
    const price = getPrimaryPrice(product);
    setSelectedMenuItemId(product.id);
    setSelectedImageFile(null);
    setSelectedImagePreview(getProductImage(product));
    setFormData({
      ...emptyFormData,
      id: product.id,
      branch_id: price?.branch_id || defaultBranchId(),
      category_id: product.category_id || product.category?.id || "",
      sub_category_id:
        product.sub_category_id ||
        product.subCategory?.id ||
        product.sub_category?.id ||
        "",
      base_uom_id: product.base_uom_id || product.uom?.id || "",
      tax_id: product.tax_id || product.tax?.id || "",
      price_id: price?.id || "",
      price_level_id:
        price?.price_level_id ||
        price?.price_level?.id ||
        defaultPriceLevel?.id ||
        "",
      price: price?.price ?? "",
      currency: price?.currency || defaultCurrency,
      original_price: price?.price ?? "",
      original_price_level_id:
        price?.price_level_id ||
        price?.price_level?.id ||
        defaultPriceLevel?.id ||
        "",
      original_branch_id: price?.branch_id || defaultBranchId(),
      original_currency: price?.currency || defaultCurrency,
      name: product.name || "",
      code: product.code || "",
      sku: product.sku || "",
      barcode: product.barcode || "",
      description: product.description || "",
      product_type:
        product.product_type === "service" ? "service" : "finished_good",
      stock_tracking_enabled: product.stock_tracking_enabled ?? false,
      allow_negative_stock: product.allow_negative_stock ?? false,
      allow_discount: product.allow_discount ?? true,
      taxable: product.taxable ?? false,
      is_active: product.is_active ?? true,
      image_url: getProductImage(product),
      image_path: product.image_path || "",
    });
    setFormOpen(true);
  };

  const closeForm = () => {
    if (saving || uploadingImage) return;
    if (previewObjectUrlRef.current) {
      URL.revokeObjectURL(previewObjectUrlRef.current);
      previewObjectUrlRef.current = "";
    }
    setFormOpen(false);
    setFormData(emptyFormData);
    setSelectedImageFile(null);
    setSelectedImagePreview("");
    setImageZoomOpen(false);
    if (imageInputRef.current) imageInputRef.current.value = "";
  };

  const closeFormAfterSave = () => {
    if (previewObjectUrlRef.current) {
      URL.revokeObjectURL(previewObjectUrlRef.current);
      previewObjectUrlRef.current = "";
    }
    setFormOpen(false);
    setFormData(emptyFormData);
    setSelectedImageFile(null);
    setSelectedImagePreview("");
    setImageZoomOpen(false);
    if (imageInputRef.current) imageInputRef.current.value = "";
  };

  const handleChange = (event) => {
    const { name, value, checked, type } = event.target;

    setFormData((prev) => {
      const next = {
        ...prev,
        [name]: type === "checkbox" ? checked : value,
      };

      if (name === "category_id") {
        next.sub_category_id = "";
      }

      if (name === "product_type" && value === "service") {
        next.stock_tracking_enabled = false;
        next.allow_negative_stock = false;
      }

      return next;
    });
  };

  const handleGenerateBarcode = () => {
    const barcode = `27${Date.now().toString().slice(-9)}${Math.floor(Math.random() * 90 + 10)}`;
    setFormData((prev) => ({ ...prev, barcode }));
  };

  const handleGenerateIdentity = async (type) => {
    try {
      setGeneratingType(type);
      const response = await generateProductIdentity({
        branch_id: formData.branch_id || null,
        type,
      });
      const value =
        response?.data?.value ||
        response?.value ||
        response?.data?.data?.value ||
        "";
      if (!value)
        throw new Error("The server did not return a generated value.");
      setFormData((prev) => ({
        ...prev,
        ...(type === "product_code" ? { code: value } : { sku: value }),
      }));
    } catch (error) {
      showMessage({
        type: "error",
        title: "Generate Failed",
        message: getApiMessage(error),
      });
    } finally {
      setGeneratingType("");
    }
  };

  const handleImageSelect = (event) => {
    const file = event.target.files?.[0];
    if (!file) return;

    if (!file.type.startsWith("image/")) {
      showMessage({
        type: "error",
        title: "Invalid Image",
        message: "Select a JPG, PNG or WEBP image.",
      });
      return;
    }

    if (previewObjectUrlRef.current) {
      URL.revokeObjectURL(previewObjectUrlRef.current);
    }
    const previewUrl = URL.createObjectURL(file);
    previewObjectUrlRef.current = previewUrl;
    setSelectedImageFile(file);
    setSelectedImagePreview(previewUrl);
  };

  const validateForm = () => {
    if (!formData.branch_id) {
      showMessage({
        type: "error",
        title: "Validation Error",
        message: "An active Hospitality branch is required.",
      });
      return false;
    }

    if (!formData.name.trim()) {
      showMessage({
        type: "error",
        title: "Validation Error",
        message: "Menu item name is required.",
      });
      return false;
    }

    if (isEditing && (!String(formData.code || "").trim() || !String(formData.sku || "").trim())) {
      showMessage({
        type: "error",
        title: "Validation Error",
        message: "Product Code and SKU are required when updating a menu item. Use Generate if either is blank.",
      });
      return false;
    }

    if (!formData.base_uom_id) {
      showMessage({
        type: "error",
        title: "Validation Error",
        message: "Base UOM is required.",
      });
      return false;
    }

    if (!formData.price_level_id) {
      showMessage({
        type: "error",
        title: "Validation Error",
        message: "Default price level is required.",
      });
      return false;
    }

    if (formData.price === "" || Number(formData.price) < 0) {
      showMessage({
        type: "error",
        title: "Validation Error",
        message: "Selling price must be zero or greater.",
      });
      return false;
    }

    if (!formData.currency) {
      showMessage({
        type: "error",
        title: "Validation Error",
        message: "Currency is required.",
      });
      return false;
    }

    return true;
  };

  const saveMenuItem = async (event) => {
    if (event?.preventDefault) event.preventDefault();
    if (!validateForm()) return;

    try {
      setSaving(true);

      const payload = {
        ...buildMenuItemPayload(formData),
        sub_category_id: formData.sub_category_id || null,
      };
      const response = isEditing
        ? await updateMenuItem(formData.id, payload)
        : await createMenuItem(payload);

      const savedProduct = response?.data || response?.data?.data || response;
      const savedId = savedProduct?.id || formData.id;

      const priceChanged =
        String(formData.price || "") !== String(formData.original_price || "") ||
        String(formData.price_level_id || "") !==
          String(formData.original_price_level_id || "") ||
        String(formData.branch_id || "") !== String(formData.original_branch_id || "") ||
        String(formData.currency || "") !== String(formData.original_currency || "");

      if (
        isEditing &&
        priceChanged &&
        savedId &&
        formData.price_level_id &&
        formData.price !== ""
      ) {
        await addMenuItemPrice(
          savedId,
          buildMenuItemPricePayload({
            branch_id: formData.branch_id,
            price_level_id: formData.price_level_id,
            price: formData.price,
            currency: formData.currency,
          }),
        );
      }

      if (selectedImageFile && savedId) {
        setUploadingImage(true);
        await uploadMenuItemImage(savedId, selectedImageFile);
      }

      const productForRecipe = {
        ...formData,
        ...savedProduct,
        id: savedId,
        name: savedProduct?.name || formData.name,
        code: savedProduct?.code || formData.code,
        sku: savedProduct?.sku || formData.sku,
        barcode: savedProduct?.barcode || formData.barcode,
        base_uom_id: savedProduct?.base_uom_id || formData.base_uom_id,
        sub_category_id: savedProduct?.sub_category_id || formData.sub_category_id || null,
        subCategory:
          savedProduct?.subCategory ||
          savedProduct?.sub_category ||
          subCategories.find((subCategory) => String(subCategory.id) === String(formData.sub_category_id)) ||
          null,
        uom:
          savedProduct?.uom ||
          uoms.find((uom) => String(uom.id) === String(formData.base_uom_id)) ||
          null,
        product_type: savedProduct?.product_type || formData.product_type,
      };

      if (
        !isEditing &&
        formData.product_type === "finished_good" &&
        typeof onCreateRecipeForMenuItem === "function"
      ) {
        onCreateRecipeForMenuItem(productForRecipe);
      }

      showMessage({
        type: "success",
        title: isEditing ? "Menu Item Updated" : "Menu Item Created",
        message: getApiMessage(
          response,
          isEditing
            ? "Menu item updated successfully."
            : "Menu item created successfully.",
        ),
      });

      closeFormAfterSave();
      await loadMenuItems();
    } catch (error) {
      showMessage({
        type: "error",
        title: isEditing ? "Update Failed" : "Create Failed",
        message: getApiMessage(error),
      });
    } finally {
      setSaving(false);
      setUploadingImage(false);
    }
  };

  const uploadImageForSelected = async (product) => {
    setSelectedMenuItemId(product.id);
    openEdit(product);
    window.setTimeout(() => imageInputRef.current?.click(), 0);
  };

  const deleteSelectedImage = async () => {
    if (!formData.id) {
      if (previewObjectUrlRef.current) {
        URL.revokeObjectURL(previewObjectUrlRef.current);
        previewObjectUrlRef.current = "";
      }
      setSelectedImageFile(null);
      setSelectedImagePreview("");
      if (imageInputRef.current) imageInputRef.current.value = "";
      return;
    }

    try {
      setUploadingImage(true);
      await deleteMenuItemImage(formData.id);
      setSelectedImageFile(null);
      setSelectedImagePreview("");
      if (previewObjectUrlRef.current) {
        URL.revokeObjectURL(previewObjectUrlRef.current);
        previewObjectUrlRef.current = "";
      }
      if (imageInputRef.current) imageInputRef.current.value = "";
      setFormData((prev) => ({ ...prev, image_url: "", image_path: "" }));
      await loadMenuItems();
      showMessage({
        type: "success",
        title: "Image Removed",
        message: "Menu item image removed successfully.",
      });
    } catch (error) {
      showMessage({
        type: "error",
        title: "Remove Failed",
        message: getApiMessage(error),
      });
    } finally {
      setUploadingImage(false);
    }
  };

  const handleDelete = async (productId) => {
    try {
      setDeleteConfirmId(null);
      setDeletingId(productId);
      const response = await deleteMenuItem(productId);
      showMessage({
        type: "success",
        title: "Menu Item Deleted",
        message: getApiMessage(response, "Menu item deleted successfully."),
      });
      setSelectedMenuItemId((current) =>
        String(current) === String(productId) ? null : current,
      );
      await loadMenuItems();
    } catch (error) {
      showMessage({
        type: "error",
        title: "Delete Failed",
        message: getApiMessage(error),
      });
    } finally {
      setDeletingId(null);
    }
  };

  const getMenuItemExportParams = () => ({
    product_types: typeFilter || MENU_PRODUCT_TYPES.map(([value]) => value).join(","),
    branch_id: defaultBranchId() || undefined,
    branch_only: true,
    ...(search.trim() ? { q: search.trim() } : {}),
    ...(categoryFilter ? { category_id: categoryFilter } : {}),
    ...(subCategoryFilter ? { sub_category_id: subCategoryFilter } : {}),
    ...(statusFilter !== "" ? { is_active: statusFilter } : {}),
  });

  const downloadMenuItemReport = async (format) => {
    if (!filteredMenuItems.length) return;

    try {
      setExportingFormat(format);
      const response = await exportProducts(format, getMenuItemExportParams());
      const disposition = response.headers?.["content-disposition"] || "";
      const encodedName = disposition.match(/filename\*=UTF-8''([^;]+)/i)?.[1];
      const plainName = disposition.match(/filename="?([^";]+)"?/i)?.[1];
      const filename = encodedName
        ? decodeURIComponent(encodedName)
        : plainName || `menu-items.${format}`;
      const url = window.URL.createObjectURL(response.data);
      const link = document.createElement("a");
      link.href = url;
      link.download = filename;
      document.body.appendChild(link);
      link.click();
      link.remove();
      window.URL.revokeObjectURL(url);
    } catch (error) {
      showMessage({
        type: "error",
        title: "Export Failed",
        message: getApiMessage(error, "The backend could not generate the menu items register."),
      });
    } finally {
      setExportingFormat("");
    }
  };

  const downloadExcel = () => downloadMenuItemReport("xlsx");
  const downloadPdf = () => downloadMenuItemReport("pdf");

  if (!open) return null;

  return (
    <>
      <FullScreenModal
        open={open}
        onClose={onClose}
        title="MENU ITEM MANAGEMENT"
        subtitle="Create sellable POS menu items and upload item images"
      >
        <div className="menu-management-workspace h-full min-h-0 overflow-hidden bg-[#D8DFDF] text-[#1C2C2B] font-normal">
          <div className="grid h-full min-h-0 grid-rows-[auto_auto_minmax(0,1fr)] gap-2 p-2">
            <div className="grid shrink-0 grid-cols-1 gap-2 xl:grid-cols-[300px_minmax(0,1fr)_300px]">
              <DeskPanel title="Menu Card">
                <div className="grid grid-cols-[92px_1fr] items-center gap-1 text-[11px]">
                  <DeskLabel>Total Items</DeskLabel>
                  <DeskField value={registerCounts.total} disabled />
                  <DeskLabel>Active</DeskLabel>
                  <DeskField value={registerCounts.active} disabled />
                  <DeskLabel>Inactive</DeskLabel>
                  <DeskField value={registerCounts.inactive} disabled />
                  <DeskLabel>With Image</DeskLabel>
                  <DeskField value={registerCounts.withImage} disabled />
                  <DeskLabel>No Price</DeskLabel>
                  <DeskField value={registerCounts.noPrice} disabled />
                </div>
              </DeskPanel>

              <DeskPanel title="Menu Item Search">
                <div className="grid grid-cols-[120px_1fr] items-center gap-1 text-[11px]">
                  <DeskLabel>Search</DeskLabel>
                  <input
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    placeholder="Search menu, code, sku, barcode..."
                    className="h-7 border border-[#B9C3C3] bg-white px-2 text-[11px] outline-none focus:border-[#004343]"
                  />
                  <DeskLabel>Category</DeskLabel>
                  <select
                    value={categoryFilter}
                    onChange={(e) => {
                      setCategoryFilter(e.target.value);
                      setSubCategoryFilter("");
                    }}
                    className="desk-select"
                  >
                    <option value="">All Categories</option>
                    {categories.map((category) => (
                      <option key={category.id} value={category.id}>
                        {category.name}
                      </option>
                    ))}
                  </select>
                  <DeskLabel>Sub Category</DeskLabel>
                  <select
                    value={subCategoryFilter}
                    onChange={(e) => setSubCategoryFilter(e.target.value)}
                    className="desk-select"
                  >
                    <option value="">All Sub Categories</option>
                    {getFilterSubCategoryOptions().map((subCategory) => (
                      <option key={subCategory.id} value={subCategory.id}>
                        {subCategory.name}
                      </option>
                    ))}
                  </select>
                  <DeskLabel>Status</DeskLabel>
                  <select
                    value={statusFilter}
                    onChange={(e) => setStatusFilter(e.target.value)}
                    className="desk-select"
                  >
                    <option value="">All Status</option>
                    <option value="true">Active</option>
                    <option value="false">Inactive</option>
                  </select>
                </div>
              </DeskPanel>

              <DeskPanel title="Selected Menu Item">
                <div className="grid grid-cols-[90px_1fr] items-center gap-1 text-[11px]">
                  <DeskLabel>Type</DeskLabel>
                  <select
                    value={typeFilter}
                    onChange={(e) => setTypeFilter(e.target.value)}
                    className="desk-select"
                  >
                    <option value="">All Types</option>
                    {MENU_PRODUCT_TYPES.map(([value, label]) => (
                      <option key={value} value={value}>
                        {label}
                      </option>
                    ))}
                  </select>
                  <DeskLabel>Selected</DeskLabel>
                  <DeskField
                    value={selectedMenuItem?.name || "None"}
                    disabled
                  />
                  <DeskLabel>Price</DeskLabel>
                  <DeskField
                    value={
                      selectedMenuItem
                        ? money(
                            getPrimaryPrice(selectedMenuItem)?.price,
                            getPrimaryPrice(selectedMenuItem)?.currency,
                          )
                        : "-"
                    }
                    disabled
                  />
                </div>
              </DeskPanel>
            </div>

            <div className="flex shrink-0 flex-wrap items-center justify-between gap-2 border border-[#B9C3C3] bg-[#EEF2F2] px-2 py-1">
              <div className="flex flex-wrap gap-1">
                <DeskTab
                  active={activeTab === "register"}
                  onClick={() => setActiveTab("register")}
                >
                  Menu Register
                </DeskTab>
                <DeskTab
                  active={activeTab === "images"}
                  onClick={() => setActiveTab("images")}
                >
                  Images
                </DeskTab>
                <DeskTab
                  active={activeTab === "pricing"}
                  onClick={() => setActiveTab("pricing")}
                >
                  Selling Prices
                </DeskTab>
              </div>

              <div className="flex flex-wrap items-center gap-1">
                <DeskTool onClick={openCreate} green>
                  Add Menu Item
                </DeskTool>
                <DeskTool onClick={downloadExcel} disabled={!!exportingFormat}>
                  {exportingFormat === "xlsx" ? "Preparing..." : "Excel"}
                </DeskTool>
                <DeskTool onClick={downloadPdf} disabled={!!exportingFormat}>
                  {exportingFormat === "pdf" ? "Preparing..." : "PDF"}
                </DeskTool>
                <DeskTool
                  onClick={() => selectedMenuItem && openEdit(selectedMenuItem)}
                  disabled={!selectedMenuItem}
                >
                  Edit
                </DeskTool>
                <DeskTool
                  onClick={() =>
                    selectedMenuItem && uploadImageForSelected(selectedMenuItem)
                  }
                  disabled={!selectedMenuItem}
                >
                  Upload Image
                </DeskTool>
                <DeskTool onClick={loadData} disabled={loading}>
                  {loading ? "Loading..." : "Refresh"}
                </DeskTool>
                <DeskTool
                  onClick={() => {
                    setSearch("");
                    setCategoryFilter("");
                    setSubCategoryFilter("");
                    setStatusFilter("");
                    setTypeFilter("");
                  }}
                >
                  Clear
                </DeskTool>
                <DeskTool
                  onClick={() =>
                    selectedMenuItem && setDeleteConfirmId(selectedMenuItem.id)
                  }
                  disabled={!selectedMenuItem}
                  danger
                >
                  Delete
                </DeskTool>
              </div>
            </div>

            <div className="min-h-0 min-w-0 overflow-hidden border border-[#AFB9B9] bg-white">
              <div className="flex h-full min-h-0 flex-col">
                {activeTab === "register" && (
                  <div className="min-h-0 min-w-0 flex-1 overflow-auto overscroll-contain">
                    <div className="min-w-[2440px]">
                      <GridHeader columns="grid-cols-[52px_86px_minmax(220px,1.2fr)_240px_120px_120px_130px_160px_160px_160px_100px_130px_110px_140px_100px_90px_90px_110px_72px]">
                        <GridHead>Line</GridHead>
                        <GridHead>Image</GridHead>
                        <GridHead>Menu Item</GridHead>
                        <GridHead>Description</GridHead>
                        <GridHead>Code</GridHead>
                        <GridHead>SKU</GridHead>
                        <GridHead>Barcode</GridHead>
                        <GridHead>Category</GridHead>
                        <GridHead>Sub Category</GridHead>
                        <GridHead>Type</GridHead>
                        <GridHead>UOM</GridHead>
                        <GridHead>Price</GridHead>
                        <GridHead>Currency</GridHead>
                        <GridHead>Price Level</GridHead>
                        <GridHead>Stock Mode</GridHead>
                        <GridHead>Discount</GridHead>
                        <GridHead>Taxable</GridHead>
                        <GridHead>Status</GridHead>
                        <GridHead stickyRight>⋮</GridHead>
                      </GridHeader>
                      {loading ? (
                        <Empty message="Loading menu items..." />
                      ) : filteredMenuItems.length === 0 ? (
                        <Empty message="No menu items found." />
                      ) : (
                        filteredMenuItems.map((item, index) => {
                          const selected = selectedMenuItemId === item.id;
                          const price = getPrimaryPrice(item);
                          const image = getProductImage(item);

                          return (
                            <div
                              key={item.id}
                              onClick={() => setSelectedMenuItemId(item.id)}
                              className={`sx-enterprise-grid-row grid w-full min-w-[2440px] cursor-pointer grid-cols-[52px_86px_minmax(220px,1.2fr)_240px_120px_120px_130px_160px_160px_160px_100px_130px_110px_140px_100px_90px_90px_110px_72px] border-b border-[#D8DFDF] text-[11px] ${selected ? "sx-enterprise-grid-selected bg-[#E5F2F2]" : "bg-white hover:bg-[#F8FAFC]"}`}
                            >
                              <GridCell muted>{index + 1}</GridCell>
                              <GridCell center>
                                {image ? (
                                  <img
                                    src={image}
                                    alt={item.name}
                                    className="h-14 w-16 object-cover border border-[#CBD6D6] bg-white"
                                  />
                                ) : (
                                  <div className="h-14 w-16 border border-dashed border-[#CBD6D6] bg-[#F8FAFC] text-[9px] flex items-center justify-center text-[#627474]">
                                    No Image
                                  </div>
                                )}
                              </GridCell>
                              <GridCell>{item.name}</GridCell>
                              <GridCell>{item.description || "-"}</GridCell>
                              <GridCell>{item.code || "-"}</GridCell>
                              <GridCell>{item.sku || "-"}</GridCell>
                              <GridCell>{item.barcode || "-"}</GridCell>
                              <GridCell>{getCategoryDisplay(item)}</GridCell>
                              <GridCell>{getSubCategoryDisplay(item)}</GridCell>
                              <GridCell>{getTypeLabel(item.product_type)}</GridCell>
                              <GridCell>{getUomDisplay(item)}</GridCell>
                              <GridCell>{price ? Number(price.price || 0).toFixed(4) : "No price"}</GridCell>
                              <GridCell>{price?.currency || "-"}</GridCell>
                              <GridCell>{price?.price_level?.name || "-"}</GridCell>
                              <GridCell>
                                {item.product_type === "service"
                                  ? "Service"
                                  : item.stock_tracking_enabled
                                    ? "Stock tracked"
                                    : "Recipe stock"}
                              </GridCell>
                              <GridCell>{item.allow_discount ? "Allowed" : "Not allowed"}</GridCell>
                              <GridCell>{item.taxable ? "Yes" : "No"}</GridCell>
                              <GridCell>
                                <Status active={item.is_active} />
                              </GridCell>
                              <GridCell stickyRight>
                                <MenuRowActionMenu
                                  label={`Actions for ${item.name}`}
                                  actions={[
                                    { label: "Edit", onClick: () => openEdit(item) },
                                    { label: "Image", onClick: () => uploadImageForSelected(item) },
                                    {
                                      label: deletingId === item.id ? "Deleting..." : "Delete",
                                      danger: true,
                                      disabled: deletingId === item.id,
                                      onClick: () => setDeleteConfirmId(item.id),
                                    },
                                  ]}
                                />
                              </GridCell>
                            </div>
                          );
                        })
                      )}
                    </div>
                  </div>
                )}

                {activeTab === "images" && (
                  <div className="min-h-0 flex-1 overflow-auto bg-[#F8FAFC] p-2">
                    {loading ? (
                      <Empty message="Loading menu item images..." />
                    ) : filteredMenuItems.length === 0 ? (
                      <Empty message="No menu item images found." />
                    ) : (
                      <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-5">
                        {filteredMenuItems.map((item) => {
                          const image = getProductImage(item);
                          return (
                          <div
                            key={item.id}
                            className="border border-[#CBD6D6] bg-white p-2"
                          >
                            <div className="h-36 border border-[#E2E9E9] bg-[#F8FAFC] flex items-center justify-center overflow-hidden">
                              {image ? (
                                <img
                                  src={image}
                                  alt={item.name}
                                  className="h-full w-full object-cover"
                                />
                              ) : (
                                <span className="text-[11px] text-[#627474]">
                                  No Image
                                </span>
                              )}
                            </div>
                            <div className="mt-2 text-[12px] font-semibold text-[#0C1B1B] truncate">
                              {item.name}
                            </div>
                            <div className="text-[10px] text-[#627474] truncate">
                              {item.code || item.sku || "-"}
                            </div>
                            <button
                              type="button"
                              onClick={() => uploadImageForSelected(item)}
                              className="mt-2 h-8 w-full bg-[#004142] text-white text-[11px] font-semibold uppercase"
                            >
                              Upload Image
                            </button>
                          </div>
                          );
                        })}
                      </div>
                    )}
                  </div>
                )}

                {activeTab === "pricing" && (
                  <div className="min-h-0 min-w-0 flex-1 overflow-auto overscroll-contain">
                    <div className="min-w-[980px]">
                      <GridHeader columns="grid-cols-[52px_minmax(280px,1fr)_140px_160px_160px_160px_72px]">
                        <GridHead>Line</GridHead>
                        <GridHead>Menu Item</GridHead>
                        <GridHead>Code</GridHead>
                        <GridHead>Price</GridHead>
                        <GridHead>Price Level</GridHead>
                        <GridHead>Currency</GridHead>
                        <GridHead stickyRight>⋮</GridHead>
                      </GridHeader>
                      {loading ? (
                        <Empty message="Loading selling prices..." />
                      ) : filteredMenuItems.length === 0 ? (
                        <Empty message="No selling prices found." />
                      ) : filteredMenuItems.map((item, index) => {
                        const price = getPrimaryPrice(item);
                        return (
                          <div
                            key={item.id}
                            onClick={() => setSelectedMenuItemId(item.id)}
                            className={`sx-enterprise-grid-row grid w-full min-w-[980px] cursor-pointer grid-cols-[52px_minmax(280px,1fr)_140px_160px_160px_160px_72px] border-b border-[#D8DFDF] text-[11px] ${selectedMenuItemId === item.id ? "sx-enterprise-grid-selected bg-[#E5F2F2]" : "bg-white hover:bg-[#F8FAFC]"}`}
                          >
                            <GridCell muted>{index + 1}</GridCell>
                            <GridCell>{item.name}</GridCell>
                            <GridCell>{item.code || "-"}</GridCell>
                            <GridCell>{price ? Number(price.price || 0).toFixed(4) : "-"}</GridCell>
                            <GridCell>
                              {price?.price_level?.name || "-"}
                            </GridCell>
                            <GridCell>{price?.currency || "-"}</GridCell>
                            <GridCell stickyRight>
                              <MenuRowActionMenu
                                label={`Pricing actions for ${item.name}`}
                                actions={[{ label: "Edit Price", onClick: () => openEdit(item) }]}
                              />
                            </GridCell>
                          </div>
                        );
                      })}
                    </div>
                  </div>
                )}
              </div>
            </div>
          </div>
        </div>
      </FullScreenModal>

      {formOpen && (
        <div className="menu-modal-backdrop">
          <div
            className="menu-item-modal"
            role="dialog"
            aria-modal="true"
            aria-label={isEditing ? "Edit menu item" : "Add menu item"}
          >
            <div className="menu-modal-header">
              <div>
                <h2>{isEditing ? "Edit Menu Item" : "Add Menu Item"}</h2>
                <p>
                  No opening stock. One default selling price only. Recipe/BOM
                  will deduct ingredients from FRONT stock.
                </p>
              </div>
              <button
                type="button"
                onClick={closeForm}
                disabled={saving || uploadingImage}
                className="menu-close-button"
              >
                x
              </button>
            </div>

            <form onSubmit={saveMenuItem} className="menu-modal-body">
              <section className="menu-section">
                <div className="menu-section-header">
                  <h3>Menu Item Identity</h3>
                </div>
                <div className="menu-grid">
                  <Field label="Menu Type" span="3">
                    <select
                      name="product_type"
                      value={formData.product_type}
                      onChange={handleChange}
                      className="menu-input"
                    >
                      {MENU_PRODUCT_TYPES.map(([value, label]) => (
                        <option key={value} value={value}>
                          {label}
                        </option>
                      ))}
                    </select>
                  </Field>
                  <Field label="Menu Item Name" span="5">
                    <input
                      name="name"
                      value={formData.name}
                      onChange={handleChange}
                      className="menu-input"
                      autoFocus
                    />
                  </Field>
                  <Field label="Branch" span="4">
                    <select
                      name="branch_id"
                      value={formData.branch_id}
                      onChange={handleChange}
                      disabled={isEditing}
                      className="menu-input"
                    >
                      <option value="">Select Branch</option>
                      {getBranchOptions().map((branch) => (
                        <option key={branch.id} value={branch.id}>
                          {getBranchName(branch)}
                        </option>
                      ))}
                    </select>
                  </Field>
                  <Field label="Product Code" span="4">
                    <div className="menu-input-group">
                      <input
                        name="code"
                        value={formData.code}
                        onChange={handleChange}
                        className="menu-input"
                      />
                      <button
                        type="button"
                        onClick={() => handleGenerateIdentity("product_code")}
                        disabled={generatingType === "product_code"}
                        className="menu-small-button"
                      >
                        {generatingType === "product_code" ? "..." : "Gen"}
                      </button>
                    </div>
                  </Field>
                  <Field label="SKU" span="4">
                    <div className="menu-input-group">
                      <input
                        name="sku"
                        value={formData.sku}
                        onChange={handleChange}
                        className="menu-input"
                      />
                      <button
                        type="button"
                        onClick={() => handleGenerateIdentity("sku")}
                        disabled={generatingType === "sku"}
                        className="menu-small-button"
                      >
                        {generatingType === "sku" ? "..." : "Gen"}
                      </button>
                    </div>
                  </Field>
                  <Field label="Barcode" span="4">
                    <div className="menu-input-group">
                      <input
                        name="barcode"
                        value={formData.barcode}
                        onChange={handleChange}
                        className="menu-input"
                      />
                      <button
                        type="button"
                        onClick={handleGenerateBarcode}
                        className="menu-small-button"
                      >
                        Gen
                      </button>
                    </div>
                  </Field>
                  <Field label="Category" span="4">
                    <select
                      name="category_id"
                      value={formData.category_id}
                      onChange={handleChange}
                      className="menu-input"
                    >
                      <option value="">Select Category</option>
                      {categories.map((category) => (
                        <option key={category.id} value={category.id}>
                          {category.name}
                        </option>
                      ))}
                    </select>
                  </Field>
                  <Field label="Sub Category" span="4">
                    <select
                      name="sub_category_id"
                      value={formData.sub_category_id}
                      onChange={handleChange}
                      disabled={!formData.category_id}
                      className="menu-input"
                    >
                      <option value="">
                        {formData.category_id ? "Select Sub Category" : "Select Category First"}
                      </option>
                      {getFormSubCategoryOptions().map((subCategory) => (
                        <option key={subCategory.id} value={subCategory.id}>
                          {subCategory.name}
                        </option>
                      ))}
                    </select>
                  </Field>
                  <Field label="Base UOM" span="4">
                    <select
                      name="base_uom_id"
                      value={formData.base_uom_id}
                      onChange={handleChange}
                      className="menu-input"
                    >
                      <option value="">Select UOM</option>
                      {uoms.map((uom) => (
                        <option key={uom.id} value={uom.id}>
                          {uom.name} {uom.code ? `(${uom.code})` : ""}
                        </option>
                      ))}
                    </select>
                  </Field>
                  <Field label="Tax" span="4">
                    <select
                      name="tax_id"
                      value={formData.tax_id}
                      onChange={handleChange}
                      className="menu-input"
                    >
                      <option value="">No Tax</option>
                      {taxes.map((tax) => (
                        <option key={tax.id} value={tax.id}>
                          {tax.name || tax.tax_name || tax.code || `Tax ${tax.id}`}
                          {tax.rate !== undefined && tax.rate !== null
                            ? ` (${Number(tax.rate).toFixed(2)}%)`
                            : ""}
                        </option>
                      ))}
                    </select>
                  </Field>
                </div>
              </section>

              <section className="menu-section">
                <div className="menu-section-header">
                  <h3>Default Selling Price</h3>
                  <span>Only one price row. Price 2 removed.</span>
                </div>
                <div className="menu-grid">
                  <Field label="Price Level" span="4">
                    <select
                      name="price_level_id"
                      value={formData.price_level_id}
                      onChange={handleChange}
                      disabled={isEditing}
                      className="menu-input"
                    >
                      <option value="">Select Price Level</option>
                      {priceLevels.map((level) => (
                        <option key={level.id} value={level.id}>
                          {level.name} {level.code ? `(${level.code})` : ""}
                        </option>
                      ))}
                    </select>
                  </Field>
                  <Field label="Selling Price" span="4">
                    <input
                      type="number"
                      step="0.0001"
                      name="price"
                      value={formData.price}
                      onChange={handleChange}
                      className="menu-input"
                    />
                  </Field>
                  <Field label="Currency" span="4">
                    <select
                      name="currency"
                      value={formData.currency}
                      onChange={handleChange}
                      className="menu-input"
                    >
                      <option value="">Select Currency</option>
                      {baseCurrencies.map((currency) => (
                        <option
                          key={currency.id || currency.code}
                          value={currency.code}
                        >
                          {currency.code} - {currency.name}
                        </option>
                      ))}
                    </select>
                  </Field>
                </div>
              </section>

              <section className="menu-section">
                <div className="menu-section-header">
                  <h3>Menu Image</h3>
                  <button
                    type="button"
                    onClick={() => imageInputRef.current?.click()}
                    className="menu-small-button"
                  >
                    Upload Image
                  </button>
                </div>
                <div className="menu-image-panel">
                  <button
                    type="button"
                    className="menu-image-preview"
                    onClick={() => currentZoomImage && setImageZoomOpen(true)}
                    title={currentZoomImage ? "Click to zoom image" : "No image selected"}
                  >
                    {currentZoomImage ? (
                      <>
                        <img src={currentZoomImage} alt="Menu preview" />
                        <span className="menu-image-zoom-hint">Click to zoom</span>
                      </>
                    ) : (
                      <span>No image selected</span>
                    )}
                  </button>
                  <div className="menu-image-actions">
                    <input
                      ref={imageInputRef}
                      type="file"
                      accept="image/png,image/jpeg,image/jpg,image/webp"
                      onChange={handleImageSelect}
                      className="hidden"
                    />
                    <button
                      type="button"
                      onClick={() => imageInputRef.current?.click()}
                      className="menu-primary-outline"
                    >
                      Choose Image
                    </button>
                    {(selectedImagePreview || formData.image_url) && (
                      <button
                        type="button"
                        onClick={deleteSelectedImage}
                        disabled={uploadingImage}
                        className="menu-danger-outline"
                      >
                        Remove Image
                      </button>
                    )}
                    <div className="menu-note">
                      Image is uploaded after the menu item is saved. Max
                      backend size: 4MB.
                    </div>
                  </div>
                </div>
              </section>

              <section className="menu-section">
                <div className="menu-section-header">
                  <h3>Description & Inventory Controls</h3>
                </div>
                <div className="menu-grid">
                  <Field label="Description" span="12">
                    <textarea
                      name="description"
                      value={formData.description}
                      onChange={handleChange}
                      rows={3}
                      className="menu-textarea"
                    />
                  </Field>
                  <label className="menu-check">
                    <input
                      type="checkbox"
                      name="stock_tracking_enabled"
                      checked={formData.stock_tracking_enabled}
                      onChange={handleChange}
                      disabled={formData.product_type === "service"}
                    />{" "}
                    Stock Tracking
                  </label>
                  <label className="menu-check">
                    <input
                      type="checkbox"
                      name="allow_negative_stock"
                      checked={formData.allow_negative_stock}
                      onChange={handleChange}
                      disabled={formData.product_type === "service"}
                    />{" "}
                    Allow Recipe to Use Negative Front Stock
                  </label>
                  <div className="menu-note" style={{ gridColumn: "1 / -1" }}>
                    When enabled, this menu item may still sell when one or more recipe ingredients have not yet been issued to FRONT. The ingredient balance becomes negative and must be cleared by a BOH-to-FRONT issue before Front Stock Reconciliation and Day End.
                  </div>
                  <label className="menu-check">
                    <input
                      type="checkbox"
                      name="allow_discount"
                      checked={formData.allow_discount}
                      onChange={handleChange}
                    />{" "}
                    Allow Discount
                  </label>
                  <label className="menu-check">
                    <input
                      type="checkbox"
                      name="taxable"
                      checked={formData.taxable}
                      onChange={handleChange}
                    />{" "}
                    Taxable
                  </label>
                  <label className="menu-check">
                    <input
                      type="checkbox"
                      name="is_active"
                      checked={formData.is_active}
                      onChange={handleChange}
                    />{" "}
                    Active
                  </label>
                </div>
              </section>
            </form>

            <div className="menu-modal-footer">
              <div className="menu-modal-status">
                {formData.name || "New menu item"}
              </div>
              <div className="menu-footer-actions">
                <button
                  type="button"
                  onClick={closeForm}
                  disabled={saving || uploadingImage}
                  className="menu-secondary-button"
                >
                  Cancel
                </button>
                <button
                  type="button"
                  onClick={saveMenuItem}
                  disabled={saving || uploadingImage}
                  className="menu-primary-button"
                >
                  {saving || uploadingImage
                    ? "Saving..."
                    : isEditing
                      ? "Update Menu Item"
                      : "Save Menu Item"}
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      {imageZoomOpen && currentZoomImage && (
        <div
          className="menu-image-zoom-backdrop"
          onClick={() => setImageZoomOpen(false)}
        >
          <div
            className="menu-image-zoom-card"
            onClick={(event) => event.stopPropagation()}
          >
            <div className="menu-image-zoom-header">
              <span>{formData.name || "Menu item image"}</span>
              <button type="button" onClick={() => setImageZoomOpen(false)}>
                x
              </button>
            </div>
            <div className="menu-image-zoom-body">
              <img src={currentZoomImage} alt={formData.name || "Menu item"} />
            </div>
          </div>
        </div>
      )}

      <EnterpriseConfirmDialog
        open={!!deleteConfirmId}
        busy={!!deletingId}
        title="Delete Menu Item"
        message="Delete this menu item? This action is permanent and may be rejected when the item is already used by transactions or recipes."
        confirmLabel={deletingId ? "Deleting..." : "Delete"}
        onCancel={() => !deletingId && setDeleteConfirmId(null)}
        onConfirm={() => deleteConfirmId && handleDelete(deleteConfirmId)}
      />

      <ReusableMessageModal
        open={messageModal.open}
        type={messageModal.type}
        title={messageModal.title}
        message={messageModal.message}
        onClose={closeMessageModal}
      />
      <MenuInlineStyles />
    </>
  );
}

function DeskPanel({ title, children }) {
  return (
    <div className="border border-[#AFB9B9] bg-white">
      <div className="border-b border-[#D8DFDF] bg-[#EEF2F2] px-2 py-1 text-[11px] font-semibold text-[#324443]">
        {title}
      </div>
      <div className="p-2">{children}</div>
    </div>
  );
}
function DeskLabel({ children }) {
  return <div className="text-[11px] text-[#465757]">{children}</div>;
}
function DeskField({ value, disabled }) {
  return (
    <input
      value={value ?? ""}
      disabled={disabled}
      readOnly
      className="h-7 border border-[#B9C3C3] bg-white px-2 text-[11px] outline-none disabled:bg-[#EEF2F2]"
    />
  );
}
function DeskTab({ active, onClick, children }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={`h-7 border px-4 text-[11px] font-semibold ${active ? "border-[#7C8888] bg-white text-[#0C1B1B]" : "border-transparent bg-transparent text-[#465757] hover:border-[#B9C3C3] hover:bg-white"}`}
    >
      {children}
    </button>
  );
}
function DeskTool({ children, onClick, disabled, green, danger }) {
  let cls = "border-[#B9C3C3] bg-white text-[#324443]";
  if (green) cls = "border-[#027A48] bg-[#027A48] text-white";
  if (danger) cls = "border-[#FECDCA] bg-white text-[#B42318]";
  return (
    <button
      type="button"
      disabled={disabled}
      onClick={onClick}
      className={`h-7 border px-3 text-[11px] font-semibold disabled:opacity-50 ${cls}`}
    >
      {children}
    </button>
  );
}
function GridHeader({ columns, children }) {
  return (
    <div
      className={`sx-enterprise-grid-head sticky top-0 z-20 grid shrink-0 ${columns} border-b border-[#AFB9B9] bg-[#EEF2F2] text-[10px] font-semibold uppercase text-[#465757]`}
    >
      {children}
    </div>
  );
}
function GridHead({ children, stickyRight = false }) {
  return (
    <div
      className={`sx-enterprise-grid-heading min-w-0 overflow-hidden text-ellipsis whitespace-nowrap border-r border-[#D8DFDF] px-2 py-2 text-[10px] ${stickyRight ? "sticky right-0 z-30 bg-[#EEF2F2] shadow-[-2px_0_4px_rgba(9,27,27,0.12)]" : ""}`}
    >
      {children}
    </div>
  );
}
function GridCell({ children, right, center, muted, stickyRight = false }) {
  return (
    <div
      className={`sx-enterprise-grid-cell min-h-[36px] min-w-0 overflow-hidden text-ellipsis whitespace-nowrap border-r border-[#E4EAEA] px-2 py-2 ${right ? "text-right" : center ? "text-center" : ""} ${muted ? "text-[#627474]" : "text-[#1C2C2B]"} ${stickyRight ? "sticky right-0 z-10 bg-inherit shadow-[-2px_0_4px_rgba(9,27,27,0.12)]" : ""}`}
    >
      {children}
    </div>
  );
}
function Status({ active }) {
  return (
    <span
      className={`inline-flex border px-2 py-1 text-[10px] font-semibold ${active ? "border-[#ABEFC6] bg-[#ECFDF3] text-[#067647]" : "border-[#FECDCA] bg-[#FEF3F2] text-[#B42318]"}`}
    >
      {active ? "Active" : "Inactive"}
    </span>
  );
}
function MenuRowActionMenu({ label = "Row actions", actions = [] }) {
  const [open, setOpen] = useState(false);
  const [position, setPosition] = useState({ top: 0, left: 0 });
  const buttonRef = useRef(null);
  const menuRef = useRef(null);
  const availableActions = actions.filter(Boolean);

  useEffect(() => {
    if (!open) return undefined;

    const updatePosition = () => {
      const rect = buttonRef.current?.getBoundingClientRect();
      if (!rect) return;
      const width = 164;
      const estimatedHeight = Math.max(40, availableActions.length * 34 + 8);
      const left = Math.max(8, Math.min(rect.right - width, window.innerWidth - width - 8));
      const below = rect.bottom + estimatedHeight <= window.innerHeight - 8;
      const top = below
        ? rect.bottom + 4
        : Math.max(8, rect.top - estimatedHeight - 4);
      setPosition({ top, left });
    };

    const handlePointerDown = (event) => {
      if (
        !buttonRef.current?.contains(event.target) &&
        !menuRef.current?.contains(event.target)
      ) {
        setOpen(false);
      }
    };
    const handleKeyDown = (event) => {
      if (event.key === "Escape") {
        setOpen(false);
        buttonRef.current?.focus();
      }
    };

    updatePosition();
    document.addEventListener("mousedown", handlePointerDown);
    document.addEventListener("keydown", handleKeyDown);
    window.addEventListener("resize", updatePosition);
    window.addEventListener("scroll", updatePosition, true);
    const focusTimer = window.setTimeout(
      () => menuRef.current?.querySelector('[role="menuitem"]:not(:disabled)')?.focus(),
      0,
    );

    return () => {
      window.clearTimeout(focusTimer);
      document.removeEventListener("mousedown", handlePointerDown);
      document.removeEventListener("keydown", handleKeyDown);
      window.removeEventListener("resize", updatePosition);
      window.removeEventListener("scroll", updatePosition, true);
    };
  }, [open, availableActions.length]);

  return (
    <div
      className="action-menu-wrap inline-flex"
      onClick={(event) => event.stopPropagation()}
    >
      <button
        ref={buttonRef}
        type="button"
        aria-label={label}
        aria-haspopup="menu"
        aria-expanded={open}
        onClick={() => setOpen((current) => !current)}
        className="flex h-7 w-7 items-center justify-center border border-[#B9C3C3] bg-white text-[20px] font-bold leading-none text-[#324443] hover:border-[#069494] hover:bg-[#F3F8F8]"
      >
        ⋮
      </button>
      {open && typeof document !== "undefined" && createPortal(
        <div
          ref={menuRef}
          role="menu"
          aria-label={label}
          style={{ top: position.top, left: position.left }}
          className="fixed z-[1000002] min-w-[164px] border border-[#96A5A4] bg-white p-1 text-left shadow-xl"
          onClick={(event) => event.stopPropagation()}
        >
          {availableActions.map((action) => (
            <button
              key={action.label}
              type="button"
              role="menuitem"
              disabled={action.disabled}
              onClick={() => {
                setOpen(false);
                action.onClick?.();
              }}
              className={`block h-8 w-full px-2 text-left text-[10px] font-semibold hover:bg-[#F2F4F7] disabled:opacity-50 ${action.danger ? "text-[#B42318]" : "text-[#324443]"}`}
            >
              {action.label}
            </button>
          ))}
        </div>,
        document.body,
      )}
    </div>
  );
}

function EnterpriseConfirmDialog({
  open,
  busy = false,
  title,
  message,
  confirmLabel = "Confirm",
  onCancel,
  onConfirm,
}) {
  return <EnterpriseConfirmModal open={open} title={title} message={message} confirmLabel={confirmLabel} busy={busy} onClose={onCancel} onConfirm={onConfirm} />;
}
function Empty({ message }) {
  return (
    <div className="flex h-[220px] items-center justify-center text-[12px] text-[#627474]">
      {message}
    </div>
  );
}
function Field({ label, children, span = "12" }) {
  return (
    <div className={`menu-field menu-span-${span}`}>
      <label>{label}</label>
      {children}
    </div>
  );
}

function MenuInlineStyles() {
  return (
    <style>{`
    .menu-management-workspace .desk-select { height: 1.75rem; border: 1px solid #B9C3C3; background: white; padding: 0 0.5rem; font-size: 11px; outline: none; }
    .menu-modal-backdrop { position: fixed; inset: 0; z-index: 99999; background: rgba(0, 52, 52, 0.62); display: flex; align-items: center; justify-content: center; padding: 14px; }
    .menu-item-modal { width: min(1180px, 98vw); height: min(90vh, 820px); background: white; border: 1px solid #9BA5A5; box-shadow: 0 20px 50px rgba(0,0,0,.32); display: flex; flex-direction: column; color: #0D1B1B; }
    .menu-modal-header { min-height: 54px; flex-shrink: 0; background: #F8FAFC; border-bottom: 1px solid #CBD6D6; padding: 9px 13px; display: flex; align-items: center; justify-content: space-between; gap: 14px; }
    .menu-modal-header h2 { margin: 0; font-size: 15px; font-weight: 600; color: #091B1B; }
    .menu-modal-header p { margin: 3px 0 0; font-size: 12px; color: #637877; }
    .menu-close-button { width: 34px; height: 34px; border: 1px solid #CBD6D6; background: white; font-size: 18px; color: #324444; }
    .menu-modal-body { flex: 1; min-height: 0; overflow-y: auto; padding: 10px; background: #F1F5F5; }
    .menu-section { background: white; border: 1px solid #CBD6D6; margin-bottom: 10px; }
    .menu-section-header { min-height: 34px; border-bottom: 1px solid #E2E9E9; background: #F8FAFC; padding: 6px 9px; display: flex; align-items: center; justify-content: space-between; gap: 10px; }
    .menu-section-header h3 { margin: 0; font-size: 12px; font-weight: 600; color: #091B1B; }
    .menu-section-header span { font-size: 11px; color: #637877; }
    .menu-grid { padding: 9px; display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 8px; }
    .menu-field label { display: block; margin-bottom: 3px; font-size: 10.5px; color: #465858; }
    .menu-span-3 { grid-column: span 3 / span 3; } .menu-span-4 { grid-column: span 4 / span 4; } .menu-span-5 { grid-column: span 5 / span 5; } .menu-span-12 { grid-column: span 12 / span 12; }
    .menu-input, .menu-textarea { width: 100%; border: 1px solid #93A6A6; background: white; color: #091B1B; font-size: 12px; outline: none; border-radius: 0; }
    .menu-input { height: 31px; padding: 0 8px; } .menu-textarea { padding: 8px; resize: vertical; }
    .menu-input:focus, .menu-textarea:focus { border-color: #069495; box-shadow: inset 0 0 0 1px #93F5F4; }
    .menu-input-group { display: grid; grid-template-columns: 1fr auto; }
    .menu-small-button, .menu-secondary-button, .menu-primary-button, .menu-primary-outline, .menu-danger-outline { height: 31px; border: 1px solid #93A6A6; padding: 0 10px; font-size: 11px; white-space: nowrap; border-radius: 0; }
    .menu-small-button { background: #F8FAFC; color: #091B1B; }
    .menu-primary-button { background: #069495; border-color: #057B7C; color: white; }
    .menu-secondary-button { background: white; color: #091B1B; }
    .menu-primary-outline { background: white; border-color: #057B7C; color: #057B7C; }
    .menu-danger-outline { background: white; border-color: #DC2626; color: #B91C1C; }
    .menu-image-panel { display: grid; grid-template-columns: 180px 1fr; gap: 12px; padding: 9px; }
    .menu-image-preview { position: relative; height: 150px; width: 180px; border: 1px solid #CBD6D6; background: #F8FAFC; display: flex; align-items: center; justify-content: center; overflow: hidden; color: #637877; font-size: 12px; cursor: zoom-in; padding: 0; }
    .menu-image-preview img { width: 100%; height: 100%; object-fit: contain; background: #fff; }
    .menu-image-zoom-hint { position: absolute; right: 6px; bottom: 6px; background: rgba(0,52,52,.78); color: white; font-size: 10px; padding: 3px 6px; }
    .menu-image-zoom-backdrop { position: fixed; inset: 0; z-index: 1000000; background: rgba(0,52,52,.82); display: flex; align-items: center; justify-content: center; padding: 18px; }
    .menu-image-zoom-card { width: min(96vw, 980px); height: min(92vh, 760px); background: white; border: 1px solid #CBD6D6; box-shadow: 0 24px 70px rgba(0,0,0,.45); display: flex; flex-direction: column; }
    .menu-image-zoom-header { height: 42px; flex-shrink: 0; display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 0 10px; border-bottom: 1px solid #CBD6D6; background: #F8FAFC; font-size: 12px; font-weight: 700; color: #091B1B; }
    .menu-image-zoom-header button { width: 32px; height: 30px; border: 1px solid #CBD6D6; background: white; font-size: 18px; color: #324444; }
    .menu-image-zoom-body { flex: 1; min-height: 0; background: #003434; display: flex; align-items: center; justify-content: center; overflow: auto; padding: 12px; }
    .menu-image-zoom-body img { max-width: 100%; max-height: 100%; object-fit: contain; background: white; }
    .menu-image-actions { display: flex; flex-direction: column; align-items: flex-start; gap: 8px; }
    .menu-note { border: 1px solid #A4E5E4; background: #F1F8F8; color: #057B7B; padding: 8px; font-size: 11px; }
    .menu-check { min-height: 31px; border: 1px solid #CBD6D6; background: #F8FAFC; padding: 0 8px; display: flex; align-items: center; gap: 7px; font-size: 12px; color: #324444; }
    .menu-modal-footer { min-height: 50px; flex-shrink: 0; border-top: 1px solid #CBD6D6; background: #F8FAFC; padding: 9px 12px; display: flex; align-items: center; justify-content: space-between; gap: 12px; }
    .menu-modal-status { font-size: 12px; color: #637877; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .menu-footer-actions { display: flex; align-items: center; justify-content: flex-end; gap: 8px; }
    .menu-management-workspace button:disabled, .menu-management-workspace input:disabled, .menu-management-workspace select:disabled, .menu-management-workspace textarea:disabled, .menu-item-modal button:disabled, .menu-item-modal input:disabled, .menu-item-modal select:disabled, .menu-item-modal textarea:disabled { opacity: .55; cursor: not-allowed; }
    .menu-management-workspace button, .menu-management-workspace input, .menu-management-workspace select, .menu-management-workspace textarea, .menu-item-modal button, .menu-item-modal input, .menu-item-modal select, .menu-item-modal textarea { border-radius: 0 !important; }
    @media (max-width: 900px) { .menu-span-3, .menu-span-4, .menu-span-5 { grid-column: span 6 / span 6; } .menu-image-panel { grid-template-columns: 1fr; } }
    @media (max-width: 640px) { .menu-item-modal { width: 99vw; height: 98vh; } .menu-grid { grid-template-columns: 1fr; } .menu-span-3, .menu-span-4, .menu-span-5, .menu-span-12 { grid-column: span 1 / span 1; } .menu-modal-footer { align-items: stretch; flex-direction: column; } .menu-footer-actions { width: 100%; } .menu-footer-actions button { flex: 1; } }
  `}</style>
  );
}
