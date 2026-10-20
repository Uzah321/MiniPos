

import React, { useEffect, useMemo, useRef, useState } from "react";
import {
  Banknote,
  Barcode,
  Calculator,
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  ChevronUp,
  CreditCard,
  Delete,
  EllipsisVertical,
  Hash,
  House,
  Keyboard,
  LayoutGrid,
  Menu,
  MessageSquare,
  Minus,
  Percent,
  Plus,
  QrCode,
  ReceiptText,
  RefreshCw,
  Search,
  ShoppingCart,
  SlidersHorizontal,
  Tag,
  Trash2,
  UserPlus,
  UtensilsCrossed,
  X,
  XCircle,
} from "lucide-react";
import { getSalesIndustryMode } from "../../../../services/MenuItemService";
import { publicAssetUrl } from "../../../../utils/publicAssetUrl";

const normalizeText = (value) => String(value || "").trim().toLowerCase();

const boolValue = (value) =>
  value === true || value === 1 || value === "1" || value === "true";

const SALES_LAYOUT_SETTING_KEYS = [
  "sales_layout",
  "sales_face_layout",
  "sales_screen_layout",
  "pos_layout",
  "pos_screen_layout",
  "selling_layout",
  "selling_screen_layout",
  "sale_layout",
  "layout",
];

const normalizeSalesLayout = (value) => {
  const clean = normalizeText(value)
    .replaceAll("-", "_")
    .replaceAll(" ", "_");

  if (["visual", "touch", "tile", "tiles", "image", "images", "food", "fast_food", "hospitality"].includes(clean)) {
    return "visual";
  }

  if (["standard", "classic", "list", "default", "normal"].includes(clean)) {
    return "standard";
  }

  return null;
};

const findSalesLayoutSettingValue = (source) => {
  const seen = new Set();

  const visit = (value) => {
    if (!value || typeof value !== "object") return null;
    if (seen.has(value)) return null;
    seen.add(value);

    if (Array.isArray(value)) {
      for (const item of value) {
        const result = visit(item);
        if (result) return result;
      }
      return null;
    }

    const key = normalizeText(value.setting_key || value.key || value.name);
    if (SALES_LAYOUT_SETTING_KEYS.includes(key)) {
      return (
        value.setting_value ??
        value.value ??
        value.settingValue ??
        value.data?.setting_value ??
        null
      );
    }

    for (const settingKey of SALES_LAYOUT_SETTING_KEYS) {
      if (value[settingKey] !== undefined && value[settingKey] !== null) {
        return value[settingKey];
      }
    }

    for (const nested of Object.values(value)) {
      const result = visit(nested);
      if (result) return result;
    }

    return null;
  };

  return visit(source);
};

const resolveSalesLayout = (layout, mode) => {
  const explicitLayout = normalizeSalesLayout(layout);
  if (explicitLayout) return explicitLayout;

  const configuredLayout = normalizeSalesLayout(findSalesLayoutSettingValue(mode));
  if (configuredLayout) return configuredLayout;

  return "standard";
};

const getStandardPrice = (product) => {
  const prices = Array.isArray(product?.prices) ? product.prices : [];

  return (
    prices.find((price) => {
      const level = price?.price_level || price?.priceLevel;
      return (
        normalizeText(level?.name) === "standard" ||
        normalizeText(level?.code) === "standard"
      );
    }) ||
    prices.find((price) => boolValue(price?.is_default)) ||
    prices.find((price) => boolValue(price?.is_active)) ||
    prices[0] ||
    null
  );
};

const currencyMoney = (currency, value) =>
  `${currency || "USD"} ${Number(value || 0).toFixed(2)}`;

const getLinePrepMessages = (line) =>
  Array.isArray(line?.prep_messages) ? line.prep_messages :
  Array.isArray(line?.prepMessages) ? line.prepMessages : [] ;

const getLineModifiers = (line) =>
  Array.isArray(line?.modifiers) ? line.modifiers : [] ;

const getLinePrepNote = (line) => line?.prep_note || line?.prepNote || "";

const getLineModifierUnitTotal = (line) =>
  getLineModifiers(line).reduce((sum, modifier) => sum + Number(modifier?.price || 0) * Number(modifier?.quantity || 1), 0);

const getLineEffectiveUnitPrice = (line) =>
  Number((Number(line?.baseMenuPrice ?? line?.menuBasePrice ?? line?.basePrice ?? line?.price ?? 0) + getLineModifierUnitTotal(line)).toFixed(4));

const hasLineCustomisation = (line) =>
  getLinePrepMessages(line).length > 0 || Boolean(getLinePrepNote(line)) || getLineModifiers(line).length > 0;

const LineCustomisationSummary = ({ line, selected = false, compact = false }) => {
  const prepMessages = getLinePrepMessages(line);
  const modifiers = getLineModifiers(line);
  const prepNote = getLinePrepNote(line);

  if (!prepMessages.length && !prepNote && !modifiers.length) return null;

  const textClass = selected ? "text-white/90" : "text-[#465757]";
  const chipClass = selected ? "border-white/30 bg-white/10 text-white" : "border-[#CFD6D6] bg-[#F8FAFC] text-[#324443]";

  return (
    <div className={`mt-1 ${compact ? "space-y-0.5" : "space-y-1"}`}>
      {modifiers.map((modifier) => (
        <div key={`${modifier.id || modifier.modifier_id}-${modifier.quantity}`} className={`inline-flex max-w-full border px-1.5 py-[1px] text-[8px] font-black uppercase ${chipClass}`}>
          + {modifier.name || "Modifier"} x {Number(modifier.quantity || 1).toFixed(0)}
        </div>
      ))}
      {prepMessages.map((message, index) => (
        <div key={`${message.id || index}-${message.message || message}`} className={`truncate text-[8px] font-black uppercase ${textClass}`}>
          * {typeof message === "string" ? message : message.message}
        </div>
      ))}
      {prepNote ? <div className={`truncate text-[8px] font-black uppercase ${textClass}`}>NOTE: {prepNote}</div> : null}
    </div>
  );
};

const getModeObject = (industryMode, salesIndustryMode) =>
  salesIndustryMode || industryMode || null;

const isFastFoodIndustryMode = (industryMode, salesIndustryMode) => {
  const mode = getModeObject(industryMode, salesIndustryMode);

  return boolValue(
    mode?.is_fast_food_industry_mode ??
      mode?.isFastFoodIndustryMode ??
      mode?.fast_food_industry_mode ??
      mode?.fastFoodIndustryMode
  );
};

const isFinishedGoodProduct = (product) =>
  normalizeText(product?.product_type || product?.productType) === "finished_good";

const getImageUrl = (value) => {
  if (!value) return "";
  if (typeof value === "string") return publicAssetUrl(value);

  return publicAssetUrl(
    value.path ||
    value.image_path ||
    value.url ||
    value.image_url ||
    value.imageUrl ||
    value.path ||
    value.file_url ||
    value.fileUrl ||
    value.public_url ||
    value.publicUrl
  );
};

const getProductImageUrl = (product) => {
  const direct =
    product?.image_path ||
    product?.image_url ||
    product?.imageUrl ||
    product?.image ||
    product?.photo_url ||
    product?.photoUrl ||
    product?.picture_url ||
    product?.pictureUrl ||
    product?.thumbnail_url ||
    product?.thumbnailUrl;

  if (direct) return getImageUrl(direct);

  const images = Array.isArray(product?.images) ? product.images : [];
  const primary =
    images.find((image) => boolValue(image?.is_primary || image?.isDefault)) ||
    images[0];

  return getImageUrl(primary);
};

const getCategoryImageUrl = (category) => {
  const direct =
    category?.image_path ||
    category?.image_url ||
    category?.imageUrl ||
    category?.image ||
    category?.photo_url ||
    category?.photoUrl ||
    category?.picture_url ||
    category?.pictureUrl ||
    category?.thumbnail_url ||
    category?.thumbnailUrl;

  if (direct) return getImageUrl(direct);

  const images = Array.isArray(category?.images) ? category.images : [];
  const primary =
    images.find((image) => boolValue(image?.is_primary || image?.isDefault)) ||
    images[0];

  return getImageUrl(primary);
};

const blurActiveInput = () => {
  const active = document.activeElement;

  if (
    active &&
    typeof active.blur === "function" &&
    ["INPUT", "TEXTAREA"].includes(active.tagName)
  ) {
    active.blur();
  }
};

const getProductAvailableStock = (product) => {
  const stocks = Array.isArray(product?.stocks) ? product.stocks : [];

  if (stocks.length === 0) return 0;

  return stocks.reduce(
    (sum, stock) =>
      sum + Number(stock.available_quantity ?? stock.quantity_on_hand ?? 0),
    0
  );
};

const getProductQuantityOnHand = (product) => {
  const stocks = Array.isArray(product?.stocks) ? product.stocks : [];

  if (stocks.length === 0) return 0;

  return stocks.reduce(
    (sum, stock) =>
      sum + Number(stock.quantity_on_hand ?? stock.available_quantity ?? 0),
    0
  );
};

const productTracksStock = (product) =>
  boolValue(product?.stock_tracking_enabled ?? product?.stockTrackingEnabled ?? false);

const productAllowsNegativeStock = (product) =>
  boolValue(product?.allow_negative_stock ?? product?.allowNegativeStock ?? false);

const productIsBlockedByStock = (product) =>
  productTracksStock(product) &&
  !productAllowsNegativeStock(product) &&
  (getProductAvailableStock(product) <= 0 || getProductQuantityOnHand(product) <= 0);

const formatStockQty = (value) => {
  const numeric = Number(value || 0);
  return Number.isInteger(numeric) ? numeric.toFixed(0) : numeric.toFixed(4);
};

const getDisplayArea = (value) =>
  normalizeText(value || "both")
    .replaceAll("-", "_")
    .replaceAll(" ", "_");

const categoryVisibleOnFront = (category) => {
  const area = getDisplayArea(category?.display_area || category?.displayArea);
  return area === "" || area === "front" || area === "both" || area === "front_and_back";
};

const getCategoryChildren = (category) => {
  const rows =
    category?.subCategories ||
    category?.sub_categories ||
    category?.subcategories ||
    [];

  return Array.isArray(rows) ? rows.filter(categoryVisibleOnFront) : [];
};

const getProductCategoryId = (product) =>
  String(product?.category_id || product?.category?.id || "");

const getProductSubCategoryId = (product) =>
  String(
    product?.sub_category_id ||
      product?.subCategoryId ||
      product?.subCategory?.id ||
      product?.sub_category?.id ||
      ""
  );

const productMatchesCategory = (product, categoryId) => {
  if (!categoryId) return true;
  return getProductCategoryId(product) === String(categoryId);
};

const productMatchesSubCategory = (product, subCategoryId) => {
  if (!subCategoryId) return true;
  return getProductSubCategoryId(product) === String(subCategoryId);
};

const SalesPointFace = ({
  layout = null,
  industryMode = null,
  salesIndustryMode = null,
  cart = [],
  selectedLineId,
  setSelectedLineId,
  totals,
  money,
  formatQty,
  getLineTotal,
  getLineTax,
  getLineSubtotal,
  getLineDiscount,
  getLineTaxSummary,

  search,
  setSearch,
  barcodeInput,
  barcodeInputRef,
  handleBarcodeSubmit,
  handleBarcodeChange,
  barcodeScanEnabled,
  loadingBarcodeScanSetting,

  categoryOptions = [],
  selectedCategoryId,
  handleCategorySelect,
  filteredProducts = [],
  loadingProducts,
  handleProductClick,
  saleCurrencyCode,
  convertMoney,

  paymentTypes = [],
  selectedPaymentTypeId,
  setSelectedPaymentTypeId,

  voidSale,
  removeSelectedLine,
  incrementSelectedLine,
  setSelectedLineQuantity,
  fastCash,
  quickPay,
  stepLineQuantity,
  openAction,
  openDiscountModal,
  handleProcess,
  clearSale,
  openCashup,
  openMoreFunctions,
  noSale,
  openCustomerScreen,

  rightMode,
  setRightMode,
  paymentPanel,
  focusBarcode,
}) => {
  const explicitLayout = normalizeSalesLayout(layout);
  const [detectedIndustryMode, setDetectedIndustryMode] = useState(null);
  const [layoutReady, setLayoutReady] = useState(Boolean(explicitLayout || industryMode || salesIndustryMode));

  useEffect(() => {
    let mounted = true;

    if (industryMode || salesIndustryMode) {
      setLayoutReady(true);
      return undefined;
    }

    // An explicit layout comes from the authenticated branch configuration.
    // Industry metadata may continue loading, but it must not delay or replace
    // the confirmed visual/standard selling face.
    setLayoutReady(Boolean(explicitLayout));

    getSalesIndustryMode()
      .then((data) => {
        if (!mounted) return;
        setDetectedIndustryMode(data || null);
        setLayoutReady(true);
      })
      .catch(() => {
        if (!mounted) return;
        setDetectedIndustryMode(null);
        setLayoutReady(true);
      });

    return () => {
      mounted = false;
    };
  }, [explicitLayout, industryMode, salesIndustryMode]);

  const resolvedIndustryMode =
    getModeObject(industryMode, salesIndustryMode) || detectedIndustryMode;
  const resolvedLayout = resolveSalesLayout(layout, resolvedIndustryMode);

  const handleProductSelect = async (product, keyedQty = null) => {
    if (!product) return;
    await handleProductClick?.(product, keyedQty);
    setSearch?.("");
  };

  const sharedProps = {
    cart,
    selectedLineId,
    setSelectedLineId,
    totals,
    money,
    formatQty,
    getLineTotal,
    getLineTax,
    getLineSubtotal,
    getLineDiscount,
    getLineTaxSummary,
    search,
    setSearch,
    barcodeInput,
    barcodeInputRef,
    handleBarcodeSubmit,
    handleBarcodeChange,
    barcodeScanEnabled,
    loadingBarcodeScanSetting,
    filteredProducts,
    loadingProducts,
    handleProductClick: handleProductSelect,
    saleCurrencyCode,
    convertMoney,
    paymentTypes,
    selectedPaymentTypeId,
    setSelectedPaymentTypeId,
    voidSale,
    removeSelectedLine,
    incrementSelectedLine,
    setSelectedLineQuantity,
    fastCash,
    quickPay,
    stepLineQuantity,
    openAction,
    openDiscountModal,
    handleProcess,
    clearSale,
    openCashup,
    openMoreFunctions,
    noSale,
    openCustomerScreen,
    rightMode,
    setRightMode,
    paymentPanel,
    focusBarcode,
    industryMode: resolvedIndustryMode,
  };

  if (!layoutReady) {
    return <VisualFaceLoading />;
  }

  if (resolvedLayout === "visual") {
    return (
      <VisualSellingLayout
        {...sharedProps}
        categoryOptions={categoryOptions}
        selectedCategoryId={selectedCategoryId}
        handleCategorySelect={handleCategorySelect}
      />
    );
  }

  return (
    <StandardSellingLayout
      {...sharedProps}
      categoryOptions={categoryOptions}
      selectedCategoryId={selectedCategoryId}
      handleCategorySelect={handleCategorySelect}
    />
  );
};


const VisualFaceLoading = () => (
  <div className="flex h-full min-h-0 items-center justify-center bg-[#EFF3F3] text-[#293939]">
    <div className="border border-[#BAC8C8] bg-white px-8 py-6 text-center shadow-[0_12px_30px_rgba(9,27,27,0.08)]">
      <div className="mx-auto mb-3 flex h-10 w-10 items-center justify-center bg-[#EEF8F6] text-[#057B7C]">
        <RefreshCw className="h-5 w-5 animate-spin" />
      </div>
      <div className="text-[13px] font-black uppercase tracking-[0.18em] text-[#004141]">Loading Sales Layout</div>
      <div className="mt-1 text-[11px] font-semibold uppercase tracking-wide text-[#627474]">Reading the configured selling layout...</div>
    </div>
  </div>
);

const MENU_PAGE_SIZE = 16;
const KEYPAD_KEYS = ["7", "8", "9", "4", "5", "6", "1", "2", "3", ".", "0", "back"];

// Restaurant till: categories down the left, a paged grid of menu picture
// tiles in the middle, and the order, keypad and till actions on the right.
const StandardSellingLayout = ({
  cart,
  selectedLineId,
  setSelectedLineId,
  totals,
  money,
  formatQty,
  getLineTotal,
  getLineDiscount,
  search,
  setSearch,
  barcodeInput,
  barcodeInputRef,
  handleBarcodeSubmit,
  handleBarcodeChange,
  barcodeScanEnabled,
  categoryOptions = [],
  selectedCategoryId,
  handleCategorySelect,
  filteredProducts,
  loadingProducts,
  handleProductClick,
  saleCurrencyCode,
  voidSale,
  removeSelectedLine,
  openAction,
  openDiscountModal,
  handleProcess,
  openCashup,
  openMoreFunctions,
  rightMode,
  setRightMode,
  paymentPanel,
  setSelectedLineQuantity,
  fastCash,
  quickPay,
  stepLineQuantity,
}) => {
  const showingSearch = Boolean(String(search || "").trim());
  const [searchOpen, setSearchOpen] = useState(false);
  const [keypadValue, setKeypadValue] = useState("");
  const [page, setPage] = useState(1);
  const searchInputRef = useRef(null);
  const categoryListRef = useRef(null);
  const frontCategories = useMemo(
    () => (categoryOptions || []).filter(categoryVisibleOnFront),
    [categoryOptions]
  );
  const selectedCategory = frontCategories.find(
    (category) => String(category.id) === String(selectedCategoryId)
  );
  const pageCount = Math.max(1, Math.ceil(filteredProducts.length / MENU_PAGE_SIZE));
  const currentPage = Math.min(Math.max(page, 1), pageCount);
  const pageProducts = filteredProducts.slice(
    (currentPage - 1) * MENU_PAGE_SIZE,
    currentPage * MENU_PAGE_SIZE
  );
  const itemCount = cart.reduce(
    (count, line) => count + (line.allowDecimalQty ? 1 : Number(line.qty || 0)),
    0
  );

  useEffect(() => {
    setPage(1);
  }, [selectedCategoryId, search]);

  const selectCategory = (category) => {
    handleCategorySelect?.(category);
    setSearch?.("");
    setSearchOpen(false);
  };

  const showAllItems = () => {
    const all = frontCategories.find((category) => String(category.id) === "all");
    if (all) selectCategory(all);
  };

  const keyedQuantity = () => {
    const value = Number(keypadValue);
    return keypadValue && value > 0 ? value : null;
  };

  const addProduct = async (product) => {
    const qty = keyedQuantity();
    setKeypadValue("");
    await handleProductClick?.(product, qty);
  };

  // Pay finishes the sale here: an amount typed on the keypad is the cash
  // received, otherwise the exact total is taken. No separate payment page.
  const payWithKeypad = async () => {
    const tendered = keyedQuantity();
    const paid = await quickPay?.(tendered);
    if (paid) setKeypadValue("");
  };

  const applyQuantity = () => {
    const qty = keyedQuantity();
    if (qty === null) {
      openAction?.("editLine");
      return;
    }
    if (setSelectedLineQuantity?.(qty) !== false) setKeypadValue("");
  };

  const addFirstSearchProduct = async () => {
    if (!showingSearch || filteredProducts.length === 0) return;
    await addProduct(filteredProducts[0]);
    searchInputRef.current?.blur();
  };

  const toggleSearch = () => {
    setRightMode?.("browse");
    if (searchOpen) {
      setSearch?.("");
      setSearchOpen(false);
      return;
    }
    setSearchOpen(true);
    window.setTimeout(() => searchInputRef.current?.focus(), 30);
  };

  const scrollCategories = (direction) => {
    const list = categoryListRef.current;
    if (list?.scrollBy) list.scrollBy({ top: direction * list.clientHeight * 0.8, behavior: "smooth" });
  };

  const pressKey = (key) => {
    setKeypadValue((current) => {
      if (key === "back") return current.slice(0, -1);
      if (key === ".") return current.includes(".") ? current : `${current || "0"}.`;
      if (current.length >= 7) return current;
      return current === "0" ? key : `${current}${key}`;
    });
  };

  if (rightMode === "payment") {
    return (
      <div className="h-full overflow-hidden bg-[#F3F6F8] p-1.5 text-[#202A2A]">
        <div className="grid h-full min-h-0 grid-cols-1 gap-1.5 lg:grid-cols-[minmax(280px,30vw)_minmax(0,1fr)]">
          <section className="hidden min-h-0 grid-rows-[38px_minmax(0,1fr)_124px] border border-[#BAC5C5] bg-white lg:grid">
            <div className="flex items-center border-b border-[#BAC5C5] bg-[#FAFBFC] px-2 text-[12px] font-semibold text-[#202A2A]">
              Sale items
            </div>

            <ReceiptListCompact
              cart={cart}
              selectedLineId={selectedLineId}
              setSelectedLineId={setSelectedLineId}
              money={money}
              formatQty={formatQty}
              getLineTotal={getLineTotal}
              getLineDiscount={getLineDiscount}
            />

            <CompactTotals totals={totals} money={money} />
          </section>

          <aside className="min-h-0 min-w-0 overflow-hidden border border-[#BAC5C5] bg-white">
            {paymentPanel}
          </aside>
        </div>
      </div>
    );
  }

  return (
    <div className="h-full overflow-hidden bg-[#EEF1F1] p-1.5 text-[#1C2C2B]">
      <div className="grid h-full min-h-0 grid-cols-[minmax(0,1fr)_minmax(380px,34%)] gap-1.5">
        <section aria-label="Menu" className="grid min-h-0 grid-rows-[56px_minmax(0,1fr)_52px] overflow-hidden border border-[#BAC5C5] bg-white">
          <MenuTopBar
            categoryName={showingSearch ? "Search results" : selectedCategory?.name || "All"}
            onHome={showAllItems}
            searchOpen={searchOpen}
            search={search}
            setSearch={setSearch}
            searchInputRef={searchInputRef}
            onToggleSearch={toggleSearch}
            onEnterSearch={addFirstSearchProduct}
            saleCurrencyCode={saleCurrencyCode}
            openMoreFunctions={openMoreFunctions}
            barcodeScanEnabled={barcodeScanEnabled}
            barcodeInput={barcodeInput}
            barcodeInputRef={barcodeInputRef}
            handleBarcodeSubmit={handleBarcodeSubmit}
            handleBarcodeChange={handleBarcodeChange}
          />

          <div className="grid min-h-0 grid-cols-[minmax(160px,22%)_minmax(0,1fr)]">
            <MenuCategoryColumn
              listRef={categoryListRef}
              categories={frontCategories}
              selectedCategoryId={selectedCategoryId}
              onSelect={selectCategory}
            />
            <MenuTileGrid
              products={pageProducts}
              loading={loadingProducts}
              onSelect={addProduct}
              highlightFirst={showingSearch && currentPage === 1}
            />
          </div>

          <MenuBottomNav
            onCategoriesUp={() => scrollCategories(-1)}
            onCategoriesDown={() => scrollCategories(1)}
            page={currentPage}
            pageCount={pageCount}
            onPage={setPage}
          />
        </section>

        <section aria-label="Order" className="grid min-h-0 grid-rows-[56px_minmax(0,1fr)_56px_auto] overflow-hidden border border-[#BAC5C5] bg-white">
          <div className="flex items-center justify-between bg-[#004142] px-3 text-white">
            <div>
              <div className="text-[15px] font-semibold">Current order</div>
              <div className="text-[11px] text-white/75">
                {cart.length} {cart.length === 1 ? "line" : "lines"}
              </div>
            </div>
            <ReceiptText className="h-6 w-6 text-white/85" />
          </div>

          <OrderLineList
            cart={cart}
            selectedLineId={selectedLineId}
            setSelectedLineId={setSelectedLineId}
            money={money}
            formatQty={formatQty}
            getLineTotal={getLineTotal}
            getLineDiscount={getLineDiscount}
            openAction={openAction}
            removeSelectedLine={removeSelectedLine}
            stepLineQuantity={stepLineQuantity}
          />

          <div className="grid grid-cols-[56px_minmax(0,1fr)_auto] items-stretch bg-[#067C7C] text-white">
            <button
              type="button"
              aria-label="Customer"
              onClick={() => openAction?.("customer")}
              className="flex items-center justify-center hover:bg-[#069494]"
            >
              <UserPlus className="h-6 w-6" />
            </button>
            <div className="flex items-center justify-end pr-4">
              <span className="relative" aria-label={`${itemCount} items in order`}>
                <ShoppingCart className="h-7 w-7" />
                <span className="absolute -right-2.5 -top-2 min-w-[20px] rounded-full bg-[#E8F7F5] px-1 text-center text-[11px] font-bold leading-5 text-[#004142]">
                  {itemCount}
                </span>
              </span>
            </div>
            <div
              aria-label="Order total"
              className="flex min-w-[150px] items-center justify-end bg-[#004142] px-4 text-[26px] font-bold tabular-nums"
            >
              {money(totals.total, totals.currency)}
            </div>
          </div>

          <div className="grid grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)] border-t border-[#D5DDDD]">
            <OrderKeypad value={keypadValue} onKey={pressKey} />
            <div className="grid grid-cols-2 grid-rows-4 gap-px border-l border-[#D5DDDD] bg-[#D5DDDD]">
              <OrderAction primary hotkey="F10" icon={CreditCard} onClick={quickPay ? payWithKeypad : handleProcess}>Pay</OrderAction>
              <OrderAction hotkey="F12" icon={Banknote} onClick={quickPay ? () => quickPay(null, { cash: true }) : fastCash}>Fast cash</OrderAction>
              <OrderAction hotkey="F4" icon={Hash} onClick={applyQuantity}>Qty</OrderAction>
              <OrderAction hotkey="F2" icon={Percent} onClick={openDiscountModal}>Discount</OrderAction>
              <OrderAction icon={UserPlus} onClick={() => openAction?.("customer")}>Customer</OrderAction>
              <OrderAction icon={Calculator} onClick={openCashup}>Cash-up</OrderAction>
              <OrderAction danger icon={XCircle} onClick={voidSale}>Clear</OrderAction>
              <OrderAction icon={LayoutGrid} onClick={openMoreFunctions}>Functions</OrderAction>
            </div>
          </div>
        </section>
      </div>
    </div>
  );
};

const categoryInitials = (name) =>
  String(name || "")
    .split(/[\s-]+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((word) => word[0].toUpperCase())
    .join("") || "?";

const MenuTopBar = ({
  categoryName,
  onHome,
  searchOpen,
  search,
  setSearch,
  searchInputRef,
  onToggleSearch,
  onEnterSearch,
  saleCurrencyCode,
  openMoreFunctions,
  barcodeScanEnabled,
  barcodeInput,
  barcodeInputRef,
  handleBarcodeSubmit,
  handleBarcodeChange,
}) => (
  <div className="flex min-w-0 items-center gap-2 bg-[#004142] px-2 text-white">
    <button
      type="button"
      aria-label="All menu items"
      onClick={onHome}
      className="flex h-10 w-12 shrink-0 items-center justify-center bg-[#067C7C] hover:bg-[#069494]"
    >
      <House className="h-5 w-5" />
    </button>

    {searchOpen ? (
      <div className="relative min-w-0 flex-1">
        <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[#627474]" />
        <input
          ref={searchInputRef}
          type="text"
          value={search}
          onChange={(event) => setSearch?.(event.target.value)}
          onKeyDown={(event) => {
            if (event.key === "Enter" && String(search || "").trim()) {
              event.preventDefault();
              onEnterSearch?.();
            }
          }}
          aria-label="Search menu items"
          placeholder="Search menu items"
          className="h-10 w-full bg-white pl-9 pr-3 text-[14px] text-[#1C2C2B] outline-none"
        />
      </div>
    ) : (
      <div className="min-w-0 flex-1">
        <span className="inline-flex h-10 max-w-full items-center truncate bg-[#E8F7F5] pl-4 pr-8 text-[15px] font-semibold text-[#004142] [clip-path:polygon(0_0,calc(100%-16px)_0,100%_50%,calc(100%-16px)_100%,0_100%)]">
          {categoryName}
        </span>
      </div>
    )}

    {barcodeScanEnabled && (
      <form onSubmit={handleBarcodeSubmit} className="shrink-0">
        <input
          ref={barcodeInputRef}
          type="text"
          value={barcodeInput}
          onChange={handleBarcodeChange}
          aria-label="Scan barcode"
          placeholder="Scan barcode"
          className="h-10 w-36 bg-[#E8F7F5] px-3 text-[12px] font-semibold text-[#004142] outline-none placeholder:text-[#627474]"
        />
      </form>
    )}

    <span className="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-full bg-white px-3 text-[13px] font-bold text-[#004142]">
      <Banknote className="h-4 w-4" />
      {saleCurrencyCode || "-"}
    </span>
    <button
      type="button"
      aria-label={searchOpen ? "Close search" : "Search menu"}
      onClick={onToggleSearch}
      className="flex h-10 w-10 shrink-0 items-center justify-center hover:bg-[#067C7C]"
    >
      {searchOpen ? <X className="h-5 w-5" /> : <Search className="h-5 w-5" />}
    </button>
    <button
      type="button"
      aria-label="Functions"
      onClick={openMoreFunctions}
      className="flex h-10 w-8 shrink-0 items-center justify-center hover:bg-[#067C7C]"
    >
      <EllipsisVertical className="h-5 w-5" />
    </button>
  </div>
);

const MenuCategoryColumn = ({ listRef, categories = [], selectedCategoryId, onSelect }) => (
  <nav
    ref={listRef}
    aria-label="Menu categories"
    className="min-h-0 overflow-y-auto border-r border-[#D5DDDD] bg-[#F3F6F6] p-1.5"
  >
    <div className="grid gap-1.5">
      {categories.map((category) => {
        const active = String(selectedCategoryId) === String(category.id);
        const imageUrl = getCategoryImageUrl(category);
        return (
          <button
            key={category.id}
            type="button"
            aria-pressed={active}
            onClick={() => onSelect?.(category)}
            className={`grid h-[84px] grid-cols-[72px_minmax(0,1fr)] items-center gap-2 overflow-hidden border text-left transition ${
              active
                ? "border-[#067C7C] bg-[#069494] text-white"
                : "border-[#D5DDDD] bg-white text-[#1C2C2B] hover:border-[#069494] hover:bg-[#E8F7F5]"
            }`}
          >
            <span className="flex h-full items-center justify-center overflow-hidden bg-[#004142] text-[18px] font-bold text-white">
              {imageUrl ? (
                <img src={imageUrl} alt="" className="h-full w-full object-cover" draggable={false} />
              ) : (
                categoryInitials(category.name)
              )}
            </span>
            <span className="line-clamp-2 pr-2 text-[14px] font-semibold leading-tight">{category.name}</span>
          </button>
        );
      })}
      {categories.length === 0 && (
        <div className="px-2 py-8 text-center text-[12px] text-[#627474]">No categories</div>
      )}
    </div>
  </nav>
);

const MenuTileGrid = ({ products = [], loading = false, onSelect, highlightFirst = false }) => {
  if (loading) {
    return (
      <div className="flex items-center justify-center bg-[#EEF1F1] text-[13px] font-semibold text-[#627474]">
        <RefreshCw className="mr-2 h-4 w-4 animate-spin" /> Loading menu...
      </div>
    );
  }

  if (products.length === 0) {
    return (
      <div className="flex items-center justify-center bg-[#EEF1F1] text-[14px] font-semibold text-[#8B9797]">
        No menu items here.
      </div>
    );
  }

  return (
    <div className="grid min-h-0 grid-cols-4 grid-rows-4 gap-1.5 bg-[#EEF1F1] p-1.5">
      {products.map((product, index) => {
        const price = getStandardPrice(product);
        const blocked = productIsBlockedByStock(product);
        const highlighted = highlightFirst && index === 0;
        const hasImage = Boolean(getProductImageUrl(product));
        return (
          <button
            key={product.id}
            type="button"
            title={blocked ? `${product.name} has no available stock.` : product.name}
            disabled={blocked}
            onPointerDown={(event) => {
              event.preventDefault();
              blurActiveInput();
            }}
            onClick={() => !blocked && onSelect?.(product)}
            className={`relative min-h-0 overflow-hidden border bg-[#F1F5F5] text-left shadow-[0_2px_6px_rgba(9,27,27,0.06)] transition active:scale-[0.99] disabled:cursor-not-allowed disabled:opacity-55 ${
              highlighted ? "border-[#069494] ring-2 ring-[#069494]" : "border-[#D5DDDD] hover:border-[#069494]"
            }`}
          >
            {/* The picture fills the whole tile; name and price sit on top of it. */}
            <MenuTileImage product={product} />
            <span
              className={`absolute inset-x-0 top-0 line-clamp-2 px-2 pb-4 pt-1.5 text-[13px] font-semibold leading-tight ${
                hasImage
                  ? "bg-gradient-to-b from-[#002B2C]/80 via-[#002B2C]/45 to-transparent text-white [text-shadow:0_1px_2px_rgba(0,0,0,0.6)]"
                  : "text-[#1C2C2B]"
              }`}
            >
              {product.name}
            </span>
            {price && (
              <span className="absolute bottom-1.5 right-1.5 bg-[#004142] px-2 py-0.5 text-[13px] font-bold tabular-nums text-white">
                {Number(price.price || 0).toFixed(2)}
              </span>
            )}
            {blocked && (
              <span className="absolute bottom-1.5 left-1.5 bg-[#FEF2F2] px-1.5 py-0.5 text-[9px] font-bold uppercase text-[#B42318]">
                No stock
              </span>
            )}
          </button>
        );
      })}
    </div>
  );
};

const MenuTileImage = ({ product }) => {
  const imageUrl = getProductImageUrl(product);

  if (!imageUrl) {
    return (
      <span className="absolute inset-0 flex items-center justify-center pt-6 text-[#B4C2C2]">
        <UtensilsCrossed className="h-10 w-10" />
      </span>
    );
  }

  return (
    <img
      src={imageUrl}
      alt={product?.name || "Menu item"}
      loading="lazy"
      className="absolute inset-0 h-full w-full object-cover"
      draggable={false}
    />
  );
};

const MenuNavButton = ({ label, disabled = false, onClick, children }) => (
  <button
    type="button"
    aria-label={label}
    disabled={disabled}
    onClick={onClick}
    className="flex h-11 w-14 items-center justify-center text-white hover:bg-[#067C7C] disabled:opacity-35"
  >
    {children}
  </button>
);

const MenuBottomNav = ({ onCategoriesUp, onCategoriesDown, page, pageCount, onPage }) => (
  <div className="grid grid-cols-[minmax(160px,22%)_minmax(0,1fr)] bg-[#004142]">
    <div className="flex items-center justify-around border-r border-white/15">
      <MenuNavButton label="Scroll categories up" onClick={onCategoriesUp}>
        <ChevronUp className="h-7 w-7" />
      </MenuNavButton>
      <MenuNavButton label="Scroll categories down" onClick={onCategoriesDown}>
        <ChevronDown className="h-7 w-7" />
      </MenuNavButton>
    </div>
    <div className="flex items-center justify-between px-2">
      <MenuNavButton label="Previous page" disabled={page <= 1} onClick={() => onPage(page - 1)}>
        <ChevronLeft className="h-7 w-7" />
      </MenuNavButton>
      {pageCount <= 12 ? (
        <div className="flex items-center gap-2">
          {Array.from({ length: pageCount }, (_, index) => index + 1).map((number) => (
            <button
              key={number}
              type="button"
              aria-label={`Page ${number}`}
              aria-current={number === page ? "page" : undefined}
              onClick={() => onPage(number)}
              className={`h-3.5 w-3.5 rounded-full border-2 border-white ${number === page ? "bg-white" : "bg-transparent"}`}
            />
          ))}
        </div>
      ) : (
        <span className="text-[13px] font-semibold text-white">Page {page} of {pageCount}</span>
      )}
      <MenuNavButton label="Next page" disabled={page >= pageCount} onClick={() => onPage(page + 1)}>
        <ChevronRight className="h-7 w-7" />
      </MenuNavButton>
    </div>
  </div>
);

const OrderLineList = ({
  cart,
  selectedLineId,
  setSelectedLineId,
  money,
  formatQty,
  getLineTotal,
  getLineDiscount,
  openAction,
  removeSelectedLine,
  stepLineQuantity,
}) => {
  if (!cart.length) {
    return (
      <div className="flex min-h-0 flex-col items-center justify-center bg-[#F7F9F9] text-center text-[#8B9797]">
        <ShoppingCart className="mb-2 h-9 w-9" />
        <div className="text-[15px] font-semibold">No items yet</div>
        <div className="mt-1 text-[12px]">Tap a menu item to add it.</div>
      </div>
    );
  }

  return (
    <div className="min-h-0 overflow-y-auto bg-[#F7F9F9]">
      {cart.map((line) => {
        const selected = selectedLineId === line.lineId;
        const currency = line.currency || "USD";
        const discount = getLineDiscount(line);
        const qty = formatQty(line.qty, line.unit, line.allowDecimalQty, line.decimalPlaces).replace(/\s.*$/, "");
        return (
          <div key={line.lineId} className={`border-b border-[#D5DDDD] ${selected ? "bg-[#E8F7F5]" : "bg-white"}`}>
            <div className="grid grid-cols-[minmax(0,1fr)_auto] items-stretch">
            <button
              type="button"
              aria-pressed={selected}
              onClick={() => setSelectedLineId(line.lineId)}
              className="grid min-h-[60px] w-full grid-cols-[56px_minmax(0,1fr)_auto] items-stretch text-left"
            >
              <span className={`flex items-center justify-center text-[20px] font-bold ${selected ? "bg-[#069494] text-white" : "bg-[#E2E9E9] text-[#004142]"}`}>
                {qty}
              </span>
              <span className="min-w-0 px-3 py-2">
                <span className="block truncate text-[15px] font-semibold text-[#1C2C2B]">{line.name}</span>
                {discount > 0 && (
                  <span className="block text-[11px] text-[#069494]">Discount -{money(discount, currency)}</span>
                )}
                <OrderLineExtras line={line} />
              </span>
              <span className="px-3 py-2 text-right">
                <span className="block text-[15px] font-bold tabular-nums text-[#1C2C2B]">
                  {currencyMoney(currency, getLineTotal(line))}
                </span>
                <span className="block text-[11px] tabular-nums text-[#627474]">
                  {currencyMoney(currency, getLineEffectiveUnitPrice(line))} each
                </span>
              </span>
            </button>
            <div className="flex items-center gap-1 pr-2">
              <QtyStepButton label={`One less ${line.name}`} onClick={() => stepLineQuantity?.(line.lineId, -1)}>
                <Minus className="h-5 w-5" />
              </QtyStepButton>
              <QtyStepButton label={`One more ${line.name}`} onClick={() => stepLineQuantity?.(line.lineId, 1)}>
                <Plus className="h-5 w-5" />
              </QtyStepButton>
            </div>
            </div>
            {selected && (
              <div className="flex justify-end gap-1.5 px-2 pb-2">
                <LineIconButton label="Modifiers" onClick={() => openAction?.("modifiers")}>
                  <SlidersHorizontal className="h-5 w-5" />
                </LineIconButton>
                <LineIconButton label="Prep note" onClick={() => openAction?.("prepMessages")}>
                  <MessageSquare className="h-5 w-5" />
                </LineIconButton>
                <LineIconButton label="Line discount" onClick={() => openAction?.("discount")}>
                  <Percent className="h-5 w-5" />
                </LineIconButton>
                <LineIconButton label="Remove line" danger onClick={removeSelectedLine}>
                  <Trash2 className="h-5 w-5" />
                </LineIconButton>
              </div>
            )}
          </div>
        );
      })}
    </div>
  );
};

const OrderLineExtras = ({ line }) => {
  const modifiers = getLineModifiers(line);
  const prepMessages = getLinePrepMessages(line);
  const prepNote = getLinePrepNote(line);

  if (!modifiers.length && !prepMessages.length && !prepNote) return null;

  return (
    <span className="mt-0.5 block space-y-0.5 text-[12px] leading-snug text-[#465757]">
      {modifiers.map((modifier) => (
        <span key={`${modifier.id || modifier.modifier_id}-${modifier.quantity}`} className="block truncate">
          + {modifier.name || "Modifier"}{Number(modifier.quantity || 1) > 1 ? ` x${Number(modifier.quantity).toFixed(0)}` : ""}
        </span>
      ))}
      {prepMessages.map((message, index) => (
        <span key={`${message.id || index}-${message.message || message}`} className="block truncate font-semibold text-[#067C7C]">
          * {typeof message === "string" ? message : message.message}
        </span>
      ))}
      {prepNote && <span className="block truncate font-semibold text-[#067C7C]">Note: {prepNote}</span>}
    </span>
  );
};

const QtyStepButton = ({ label, onClick, children }) => (
  <button
    type="button"
    aria-label={label}
    title={label}
    onClick={onClick}
    className="flex h-11 w-11 items-center justify-center border border-[#BAC5C5] bg-white text-[#004142] hover:border-[#069494] hover:bg-[#E8F7F5] active:bg-[#D3EFEC]"
  >
    {children}
  </button>
);

const LineIconButton = ({ label, danger = false, onClick, children }) => (
  <button
    type="button"
    aria-label={label}
    title={label}
    onClick={onClick}
    className={`flex h-10 w-11 items-center justify-center text-white ${
      danger ? "bg-[#C93D34] hover:bg-[#B8322A]" : "bg-[#067C7C] hover:bg-[#069494]"
    }`}
  >
    {children}
  </button>
);

const OrderKeypad = ({ value, onKey }) => (
  <div className="grid grid-rows-[40px_minmax(0,1fr)]">
    <div
      aria-label="Keypad entry"
      className="flex items-center justify-end bg-[#F3F6F6] px-3 text-[20px] font-bold tabular-nums text-[#004142]"
    >
      {value || <span className="text-[11px] font-semibold uppercase tracking-wide text-[#8B9797]">Qty or cash</span>}
    </div>
    <div className="grid grid-cols-3 grid-rows-4">
      {KEYPAD_KEYS.map((key) => (
        <button
          key={key}
          type="button"
          aria-label={key === "back" ? "Backspace" : key}
          onClick={() => onKey(key)}
          className="flex h-14 items-center justify-center border-b border-r border-[#D5DDDD] bg-white text-[22px] font-semibold text-[#1C2C2B] hover:bg-[#E8F7F5] active:bg-[#D3EFEC]"
        >
          {key === "back" ? <Delete className="h-6 w-6" /> : key}
        </button>
      ))}
    </div>
  </div>
);

const OrderAction = ({ icon: Icon, hotkey, primary = false, danger = false, onClick, children }) => (
  <button
    type="button"
    onClick={onClick}
    className={`relative flex items-center gap-2 px-3 text-left text-[14px] font-semibold ${
      primary
        ? "bg-[#16A34A] text-white hover:bg-[#15803D]"
        : danger
        ? "bg-[#C93D34] text-white hover:bg-[#B8322A]"
        : "bg-white text-[#1C2C2B] hover:bg-[#E8F7F5]"
    }`}
  >
    {hotkey && <span className="absolute left-1.5 top-1 text-[9px] font-semibold opacity-70">{hotkey}</span>}
    {Icon && <Icon className="h-5 w-5 shrink-0" />}
    <span>{children}</span>
  </button>
);

const VisualSellingLayout = ({
  cart,
  selectedLineId,
  setSelectedLineId,
  totals,
  money,
  formatQty,
  getLineTotal,
  getLineTax,
  getLineSubtotal,
  getLineDiscount,
  getLineTaxSummary,
  search,
  setSearch,
  barcodeInput,
  barcodeInputRef,
  handleBarcodeSubmit,
  handleBarcodeChange,
  barcodeScanEnabled,
  loadingBarcodeScanSetting,
  categoryOptions,
  selectedCategoryId,
  handleCategorySelect,
  filteredProducts,
  loadingProducts,
  handleProductClick,
  saleCurrencyCode,
  convertMoney,
  paymentTypes,
  selectedPaymentTypeId,
  setSelectedPaymentTypeId,
  voidSale,
  removeSelectedLine,
  incrementSelectedLine,
  openAction,
  openDiscountModal,
  handleProcess,
  quickPay,
  clearSale,
  openCashup,
  openMoreFunctions,
  noSale,
  openCustomerScreen,
  rightMode,
  setRightMode,
  paymentPanel,
  focusBarcode,
  industryMode,
}) => {
  const [rightPanel, setRightPanel] = useState("products");
  const [visualPage, setVisualPage] = useState(1);
  const [drillCategory, setDrillCategory] = useState(null);
  const [selectedVisualSubCategoryId, setSelectedVisualSubCategoryId] = useState("");
  const visualPageSize = 24;
  const searchInputRef = useRef(null);
  const allowSearchKeyboardRef = useRef(false);
  const [searchKeyboardEnabled, setSearchKeyboardEnabled] = useState(false);

  const showingSearch = Boolean(String(search || "").trim());
  const fastFoodIndustryMode = isFastFoodIndustryMode(industryMode);

  const visibleCategoryOptions = useMemo(
    () => (categoryOptions || []).filter(categoryVisibleOnFront),
    [categoryOptions]
  );

  const activeDrillSubCategories = useMemo(
    () => (drillCategory ? getCategoryChildren(drillCategory) : []),
    [drillCategory]
  );

  const productRowsAfterVisualSubCategory = useMemo(() => {
    let rows = Array.isArray(filteredProducts) ? filteredProducts : [];

    if (drillCategory?.id) {
      rows = rows.filter((product) => productMatchesCategory(product, drillCategory.id));
    }

    if (selectedVisualSubCategoryId) {
      rows = rows.filter((product) =>
        productMatchesSubCategory(product, selectedVisualSubCategoryId)
      );
    }

    return rows;
  }, [filteredProducts, drillCategory, selectedVisualSubCategoryId]);

  const visualProducts = fastFoodIndustryMode
    ? productRowsAfterVisualSubCategory.filter(isFinishedGoodProduct)
    : productRowsAfterVisualSubCategory;
  const visualPageCount = Math.max(1, Math.ceil(visualProducts.length / visualPageSize));
  const safeVisualPage = Math.min(Math.max(visualPage, 1), visualPageCount);
  const pagedVisualProducts = visualProducts.slice(
    (safeVisualPage - 1) * visualPageSize,
    safeVisualPage * visualPageSize
  );

  useEffect(() => {
    setVisualPage(1);
  }, [selectedCategoryId, selectedVisualSubCategoryId, drillCategory, search, rightPanel, fastFoodIndustryMode]);

  useEffect(() => {
    setVisualPage((page) => Math.min(Math.max(page, 1), visualPageCount));
  }, [visualPageCount]);

  const showSearchPanel = () => {
    setRightPanel("products");
    setRightMode?.("browse");
  };

  const enableSearchKeyboard = () => {
    allowSearchKeyboardRef.current = true;
    setSearchKeyboardEnabled(true);
    showSearchPanel();

    window.setTimeout(() => {
      searchInputRef.current?.focus();
      searchInputRef.current?.click?.();
    }, 30);
  };

  const disableSearchKeyboard = () => {
    allowSearchKeyboardRef.current = false;
    setSearchKeyboardEnabled(false);
    searchInputRef.current?.blur();
    blurActiveInput();
  };

  const focusSearch = () => {
    enableSearchKeyboard();
  };

  const openSearchKeyboard = () => {
    enableSearchKeyboard();
  };

  const addFirstSearchProduct = async () => {
    if (!showingSearch || visualProducts.length === 0) return;

    disableSearchKeyboard();
    await handleProductClick(visualProducts[0]);
    disableSearchKeyboard();
    setRightPanel("products");
  };

  const selectCategory = (category) => {
    const children = getCategoryChildren(category);

    handleCategorySelect?.(category);
    setRightPanel("products");
    setSearch?.("");
    setSelectedVisualSubCategoryId("");

    if (children.length > 0) {
      setDrillCategory(category);
      return;
    }

    setDrillCategory(null);
  };

  const selectSubCategory = (subCategory) => {
    setSelectedVisualSubCategoryId(subCategory?.id || "");
    setRightPanel("products");
    setSearch?.("");
  };

  const goBackToMainCategories = () => {
    setDrillCategory(null);
    setSelectedVisualSubCategoryId("");
  };

  return (
    <div className={`grid h-full min-h-0 overflow-hidden bg-[#EFF3F3] p-1.5 text-[#202A2A] ${rightMode === "payment" ? "grid-rows-[minmax(0,1fr)]" : "grid-rows-[50px_minmax(0,1fr)]"}`}>
      {rightMode !== "payment" && (
        <VisualTopBar
          openAction={openAction}
          openDiscountModal={openDiscountModal}
          clearSale={clearSale}
          handleProcess={handleProcess}
          openCashup={openCashup}
          openMoreFunctions={openMoreFunctions}
          openCustomerScreen={openCustomerScreen}
          focusSearch={focusSearch}
          openSearchKeyboard={openSearchKeyboard}
          showCategories={() => {
            setRightMode?.("browse");
            setRightPanel("categories");
            setSearch?.("");
            setDrillCategory(null);
            setSelectedVisualSubCategoryId("");
          }}
        />
      )}

      <div
        className={`grid min-h-0 gap-2 ${
          rightMode === "payment"
            ? "grid-cols-1 xl:grid-cols-[minmax(330px,380px)_minmax(0,1fr)]"
            : "grid-cols-[430px_minmax(0,1fr)]"
        }`}
      >
        <section className={`min-h-0 grid-rows-[44px_minmax(0,1fr)_92px_62px_58px] border border-[#BAC8C8] bg-white shadow-[0_10px_24px_rgba(9,27,27,0.08)] ${rightMode === "payment" ? "hidden xl:grid" : "grid"}`}>
          <VisualLeftActions
            removeSelectedLine={removeSelectedLine}
            incrementSelectedLine={incrementSelectedLine}
            openAction={openAction}
          />

          <VisualReceiptList
            cart={cart}
            selectedLineId={selectedLineId}
            setSelectedLineId={setSelectedLineId}
            money={money}
            formatQty={formatQty}
            getLineTotal={getLineTotal}
            getLineDiscount={getLineDiscount}
          />

          <VisualCompactTotals totals={totals} money={money} />

          <VisualLineActionBar
            selectedLineId={selectedLineId}
            openAction={openAction}
          />

          <div className="grid grid-cols-3 gap-1.5 border-t border-[#273A3A] bg-[#004142] p-1.5">
            <MiniAction danger onClick={voidSale}>Clear</MiniAction>
            <MiniAction onClick={() => openAction("customer")}>Customer</MiniAction>
            <MiniAction primary onClick={quickPay ? () => quickPay(null) : handleProcess}>F10 Payment</MiniAction>
          </div>
        </section>

        <section className="grid min-h-0 grid-rows-[50px_minmax(0,1fr)_34px] border border-[#BAC8C8] bg-[#F8FAFC] shadow-[0_10px_24px_rgba(9,27,27,0.08)]">
          {rightMode === "payment" ? (
            <div className="row-span-3 min-h-0 overflow-hidden bg-white">{paymentPanel}</div>
          ) : (
            <>
              <VisualSearchHeader
                search={search}
                setSearch={setSearch}
                searchInputRef={searchInputRef}
                onEnterSearch={addFirstSearchProduct}
                focusSearch={focusSearch}
                searchKeyboardEnabled={searchKeyboardEnabled}
                allowSearchKeyboardRef={allowSearchKeyboardRef}
                enableSearchKeyboard={enableSearchKeyboard}
                barcodeInput={barcodeInput}
                barcodeInputRef={barcodeInputRef}
                handleBarcodeSubmit={handleBarcodeSubmit}
                handleBarcodeChange={handleBarcodeChange}
                barcodeScanEnabled={barcodeScanEnabled}
                loadingBarcodeScanSetting={loadingBarcodeScanSetting}
                focusBarcode={focusBarcode}
                showCategories={() => {
                  setRightPanel("categories");
                  setSearch?.("");
                  setDrillCategory(null);
                  setSelectedVisualSubCategoryId("");
                }}
              />

              <VisualProductArea
                rightPanel={rightPanel}
                setRightPanel={setRightPanel}
                showingSearch={showingSearch}
                categoryOptions={visibleCategoryOptions}
                selectedCategoryId={selectedCategoryId}
                handleCategorySelect={selectCategory}
                drillCategory={drillCategory}
                drillSubCategories={activeDrillSubCategories}
                selectedVisualSubCategoryId={selectedVisualSubCategoryId}
                handleSubCategorySelect={selectSubCategory}
                goBackToMainCategories={goBackToMainCategories}
                filteredProducts={pagedVisualProducts}
                loadingProducts={loadingProducts}
                handleProductClick={async (product) => {
                  disableSearchKeyboard();
                  await handleProductClick(product);
                  disableSearchKeyboard();
                  setRightPanel("products");
                }}
                saleCurrencyCode={saleCurrencyCode}
                convertMoney={convertMoney}
                paymentTypes={paymentTypes}
                selectedPaymentTypeId={selectedPaymentTypeId}
                setSelectedPaymentTypeId={setSelectedPaymentTypeId}
                openDiscountModal={openDiscountModal}
                handleProcess={handleProcess}
                openCashup={openCashup}
                fastFoodIndustryMode={fastFoodIndustryMode}
              />

              <VisualPager
                currentPage={safeVisualPage}
                pageCount={visualPageCount}
                pageSize={visualPageSize}
                totalItems={visualProducts.length}
                onFirst={() => setVisualPage(1)}
                onPrev={() => setVisualPage((page) => Math.max(1, page - 1))}
                onNext={() => setVisualPage((page) => Math.min(visualPageCount, page + 1))}
                onLast={() => setVisualPage(visualPageCount)}
              />
            </>
          )}
        </section>
      </div>
    </div>
  );
};

const VisualSearchHeader = ({
  search,
  setSearch,
  searchInputRef,
  onEnterSearch,
  focusSearch,
  searchKeyboardEnabled,
  allowSearchKeyboardRef,
  enableSearchKeyboard,
  barcodeInput,
  barcodeInputRef,
  handleBarcodeSubmit,
  handleBarcodeChange,
  barcodeScanEnabled,
  loadingBarcodeScanSetting,
  focusBarcode,
  showCategories,
}) => {
  return (
    <form onSubmit={handleBarcodeSubmit} className="grid min-w-0 grid-cols-[54px_54px_54px_minmax(0,1fr)_220px] border-b border-[#BAC5C5] bg-[#FFFFFF]">
      <button
        type="button"
        title={barcodeScanEnabled ? "Barcode scan active" : "Barcode scan disabled"}
        disabled={!barcodeScanEnabled}
        onClick={() => barcodeInputRef?.current?.focus()}
        className={`flex items-center justify-center border-r border-[#DAE2E2] disabled:cursor-not-allowed ${
          barcodeScanEnabled ? "bg-[#E9FBF7] text-[#006B6B]" : "bg-[#F3F6F8] text-[#96A5A4]"
        }`}
      >
        <Barcode className="h-4 w-4" />
      </button>

      <button
        type="button"
        title="Search product by name"
        onClick={focusSearch}
        className="flex items-center justify-center border-r border-[#DAE2E2] bg-white text-[#007474] hover:bg-[#F0F7F7]"
      >
        <Tag className="h-4 w-4" />
      </button>

      <button
        type="button"
        title="Categories"
        onClick={showCategories}
        className="flex items-center justify-center border-r border-[#DAE2E2] bg-[#004142] text-[10px] font-black uppercase tracking-[0.18em] text-white hover:bg-[#004142]"
      >
        Cat
      </button>

      <div className="relative flex h-full min-w-0 items-center bg-white">
        <Search className="absolute left-4 h-4 w-4 text-[#607171]" />
        <input
          ref={searchInputRef}
          type="text"
          value={search}
          readOnly={!searchKeyboardEnabled}
          onPointerDown={() => {
            allowSearchKeyboardRef.current = true;
            enableSearchKeyboard?.();
          }}
          onFocus={(event) => {
            if (!allowSearchKeyboardRef.current) {
              event.currentTarget.blur();
            }
          }}
          onChange={(event) => setSearch(event.target.value)}
          onKeyDown={(event) => {
            if (event.key === "Enter" && String(search || "").trim()) {
              event.preventDefault();
              onEnterSearch?.();
            }
          }}
          placeholder="Search products by name, SKU or barcode"
          className="h-full w-full border-0 bg-white pl-11 pr-12 text-[15px] font-normal text-[#152323] outline-none placeholder:text-[#8B9797]"
        />
        <Keyboard className="absolute right-4 h-4 w-4 text-[#8B9797]" />
      </div>

      <div className="relative hidden h-full items-center border-l border-[#DAE2E2] bg-[#F8FAFC] xl:flex">
        <QrCode
          className={`absolute left-4 h-4 w-4 ${
            barcodeScanEnabled ? "text-[#0AA7A7]" : "text-[#A0B0B0]"
          }`}
        />
        <input
          ref={barcodeInputRef}
          type="text"
          value={barcodeInput}
          onFocus={focusBarcode}
          onChange={handleBarcodeChange}
          disabled={!barcodeScanEnabled}
          placeholder={
            loadingBarcodeScanSetting
              ? "Checking..."
              : barcodeScanEnabled
              ? "Scan barcode"
              : "Barcode off"
          }
          className="h-full w-full border-0 bg-transparent pl-11 pr-3 text-[12px] font-semibold uppercase tracking-wide text-[#152323] outline-none disabled:text-[#93A6A6]"
        />
      </div>
    </form>
  );
};

const ReceiptListCompact = ({
  cart,
  selectedLineId,
  setSelectedLineId,
  money,
  formatQty,
  getLineTotal,
  getLineDiscount,
}) => {
  if (!cart.length) {
    return (
      <div className="flex h-full flex-col items-center justify-center text-center text-[#9BA6A6]">
        <div className="text-[18px] font-semibold">No items</div>
        <div className="mt-1 px-4 text-[12px]">Add products to receipt.</div>
      </div>
    );
  }

  return (
    <div className="min-h-0 overflow-auto bg-white">
      {cart.map((line) => {
        const selected = selectedLineId === line.lineId;
        const discount = getLineDiscount(line);
        const lineCurrency = line.currency || "USD";
        return (
          <button
            key={line.lineId}
            type="button"
            onClick={() => setSelectedLineId(line.lineId)}
            className={`grid w-full grid-cols-[1fr_46px] border-b border-dashed border-[#D3D9D8] px-2 py-2 text-left ${
              selected ? "bg-[#2EB5B5] text-white" : "bg-white text-[#202A2A] hover:bg-[#F6FAFA]"
            }`}
          >
            <div className="min-w-0">
              <div className="truncate text-[12px] font-semibold">{line.name}</div>
              <div className={`mt-0.5 text-[10px] ${selected ? "text-white/85" : "text-[#697574]"}`}>
                #{line.code || line.sku || "-"} {currencyMoney(lineCurrency, getLineEffectiveUnitPrice(line))} {currencyMoney(lineCurrency, getLineTotal(line))}
              </div>
              {discount > 0 && <div className="mt-0.5 text-[9px]">Disc -{money(discount, lineCurrency)}</div>}
              <LineCustomisationSummary line={line} selected={selected} compact />
            </div>
            <div className="text-right text-[14px] font-semibold">
              {formatQty(line.qty, line.unit, line.allowDecimalQty, line.decimalPlaces).replace(/\s.*$/, "")}
            </div>
          </button>
        );
      })}
    </div>
  );
};

const CompactTotals = ({ totals, money }) => (
  <div className="border-t border-[#CAD5D5] bg-[#FAFBFC] px-3 py-2">
    <div className="grid w-full grid-cols-[86px_minmax(0,1fr)] gap-x-2 gap-y-1 text-[12px]">
      <div className="whitespace-nowrap font-semibold">Subtotal</div>
      <div className="min-w-0 overflow-hidden text-ellipsis whitespace-nowrap text-right tabular-nums">
        {money(totals.subTotal, totals.currency)}
      </div>

      <div className="whitespace-nowrap font-semibold">Discount</div>
      <div className="min-w-0 overflow-hidden text-ellipsis whitespace-nowrap text-right tabular-nums text-[#069494]">
        -{money(totals.discount, totals.currency)}
      </div>

      <div className="whitespace-nowrap font-semibold">Tax</div>
      <div className="min-w-0 overflow-hidden text-ellipsis whitespace-nowrap text-right tabular-nums">
        {money(totals.tax, totals.currency)}
      </div>

      <div className="col-span-2 border-t border-dashed border-[#ACB8B8]" />
      <div className="whitespace-nowrap text-[14px] font-semibold">Total</div>
      <div className="min-w-0 overflow-hidden text-ellipsis whitespace-nowrap text-right text-[clamp(18px,2vw,26px)] font-semibold leading-none text-[#004141] tabular-nums">
        {money(totals.total, totals.currency)}
      </div>
    </div>
  </div>
);

const VisualTopBar = ({
  openAction,
  openDiscountModal,
  clearSale,
  handleProcess,
  openCashup,
  openMoreFunctions,
  focusSearch,
  openSearchKeyboard,
  showCategories,
}) => (
  <div className="mb-1.5 grid min-h-0 grid-cols-[1fr_auto] border border-[#BAC8C8] bg-white shadow-[0_6px_18px_rgba(9,27,27,0.06)]">
    <div className="flex min-w-0 items-stretch gap-1 p-1">
      <TopAction hotkey="F3" onClick={focusSearch}>Search</TopAction>
      <TopAction onClick={showCategories}>Categories</TopAction>
      <TopAction onClick={() => openAction("customer")}>Customer</TopAction>
      <TopAction hotkey="F8" onClick={clearSale}>New Sale</TopAction>
      <TopIconAction title="Open product search keyboard" onClick={openSearchKeyboard}>
        <Keyboard className="h-4 w-4" />
      </TopIconAction>
    </div>

    <button
      type="button"
      onClick={openMoreFunctions}
      className="flex w-[58px] items-center justify-center border-l border-[#DAE2E2] bg-[#F8FAFC] text-[#004141] hover:bg-white"
      title="More functions"
    >
      <Menu className="h-4 w-4" />
    </button>
  </div>
);

const VisualLeftActions = ({ removeSelectedLine, incrementSelectedLine, openAction }) => (
  <div className="grid grid-cols-[92px_92px_92px_1fr] gap-1 border-b border-[#DAE2E2] bg-[#F8FAFC] p-1">
    <button
      type="button"
      onClick={removeSelectedLine}
      className="flex items-center justify-center bg-[#C8372D] text-[11px] font-black uppercase tracking-wide text-white hover:bg-[#B72F26]"
    >
      Delete
    </button>
    <button
      type="button"
      onClick={incrementSelectedLine}
      className="bg-[#027A48] text-[11px] font-black uppercase tracking-wide text-white hover:bg-[#05603A]"
      title="Add one to the selected product"
    >
      + Qty
    </button>
    <button
      type="button"
      onClick={() => openAction("editLine")}
      className="bg-white text-[11px] font-black uppercase tracking-wide text-[#152323] hover:bg-[#EFF3F3]"
    >
      Quantity
    </button>
    <div className="flex items-center justify-center bg-[#EFF3F3] text-[11px] font-black uppercase tracking-[0.22em] text-[#627474]">
      Sale
    </div>
  </div>
);

const VisualReceiptList = ({
  cart,
  selectedLineId,
  setSelectedLineId,
  money,
  formatQty,
  getLineTotal,
  getLineDiscount,
}) => {
  if (!cart.length) {
    return (
      <div className="flex h-full flex-col items-center justify-center border-b border-[#DAE2E2] bg-white text-center text-[#96A5A4]">
        <div className="text-[18px] font-black uppercase tracking-[0.18em] text-[#CBD6D6]">Empty Sale</div>
        <div className="mt-2 px-6 text-[11px] font-semibold uppercase tracking-wide text-[#93A6A6]">
          Scan, search, or tap products to build the receipt.
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-0 overflow-auto bg-white">
      {cart.map((line, index) => {
        const selected = selectedLineId === line.lineId;
        const discount = getLineDiscount(line);
        const lineCurrency = line.currency || "USD";
        return (
          <button
            key={line.lineId}
            type="button"
            onClick={() => setSelectedLineId(line.lineId)}
            className={`grid w-full grid-cols-[28px_1fr_54px] border-b border-[#EEF2F2] px-2 py-2 text-left transition ${
              selected
                ? "bg-[#E7FBF7] text-[#132726] shadow-[inset_4px_0_0_#00BCBC]"
                : "bg-white text-[#293939] hover:bg-[#F8FAFC]"
            }`}
          >
            <div className={`flex h-6 w-6 items-center justify-center text-[10px] font-black ${selected ? "bg-[#00BCBC] text-white" : "bg-[#EFF3F3] text-[#627474]"}`}>
              {index + 1}
            </div>
            <div className="min-w-0 pr-2">
              <div className="truncate text-[11px] font-bold uppercase tracking-wide">{line.name}</div>
              <div className={`mt-0.5 truncate text-[9px] font-semibold ${selected ? "text-[#4D6161]" : "text-[#8B9797]"}`}>
                #{line.code || line.sku || "-"} | {currencyMoney(lineCurrency, getLineEffectiveUnitPrice(line))} | Line {currencyMoney(lineCurrency, getLineTotal(line))}
              </div>
              {discount > 0 && (
                <div className="mt-1 inline-flex bg-[#FFFAEB] px-1.5 py-[1px] text-[8px] font-black uppercase text-[#B54708]">
                  Disc -{money(discount, lineCurrency)}
                </div>
              )}
              <LineCustomisationSummary line={line} selected={selected} compact />
            </div>
            <div className="text-right text-[15px] font-black leading-none">
              {formatQty(line.qty, line.unit, line.allowDecimalQty, line.decimalPlaces).replace(/\s.*$/, "")}
              <div className={`mt-1 text-[8px] font-semibold uppercase ${selected ? "text-[#4D6161]" : "text-[#96A5A4]"}`}>Qty</div>
            </div>
          </button>
        );
      })}
    </div>
  );
};

const VisualCompactTotals = ({ totals, money }) => (
  <div className="border-t border-[#DAE2E2] bg-[#EFF3F3] p-1.5">
    <div className="grid h-full grid-cols-4 overflow-hidden border border-[#005D5D] bg-[#006969] text-white shadow-[inset_0_-4px_0_rgba(0,0,0,0.16)]">
      <VisualTotalTile
        label="Subtotal"
        value={money(totals.subTotal, totals.currency)}
      />
      <VisualTotalTile
        label="Tax"
        value={money(totals.tax, totals.currency)}
      />
      <VisualTotalTile
        label="Discount"
        value={`-${money(totals.discount, totals.currency)}`}
      />
      <VisualTotalTile
        label="Total"
        value={money(totals.total, totals.currency)}
        primary
      />
    </div>
  </div>
);

const VisualTotalTile = ({ label, value, primary = false }) => (
  <div
    className={`flex min-w-0 flex-col items-center justify-center border-r border-white/18 px-1.5 py-1 text-center last:border-r-0 ${
      primary ? "bg-[#005C5C]" : ""
    }`}
  >
    <div className="max-w-full truncate text-[8px] font-black uppercase tracking-[0.16em] text-white/72">
      {label}
    </div>
    <div
      className={`mt-1 max-w-full truncate font-black leading-none tabular-nums ${
        primary ? "text-[15px] text-[#E7FBF7]" : "text-[11px] text-white"
      }`}
      title={value}
    >
      {value}
    </div>
  </div>
);

const PrepMessageIcon = ({ className = "h-5 w-5" }) => (
  <svg viewBox="0 0 24 24" className={className} fill="none" aria-hidden="true">
    <path d="M5 5.5h14v9.2H9.2L5 18.5v-13Z" stroke="currentColor" strokeWidth="2" strokeLinejoin="round" />
    <path d="M8 8.5h8M8 11.5h5" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
  </svg>
);

const ModifierIcon = ({ className = "h-5 w-5" }) => (
  <svg viewBox="0 0 24 24" className={className} fill="none" aria-hidden="true">
    <path d="M4 7h10M4 17h10M10 12h10" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
    <path d="M17 7h3M4 12h3M17 17h3" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
    <circle cx="16" cy="7" r="2" stroke="currentColor" strokeWidth="2" />
    <circle cx="9" cy="12" r="2" stroke="currentColor" strokeWidth="2" />
    <circle cx="16" cy="17" r="2" stroke="currentColor" strokeWidth="2" />
  </svg>
);

const SplitLineIcon = ({ className = "h-5 w-5" }) => (
  <svg viewBox="0 0 24 24" className={className} fill="none" aria-hidden="true">
    <path d="M6 5h12v5H6V5ZM6 14h5v5H6v-5ZM13 14h5v5h-5v-5Z" stroke="currentColor" strokeWidth="2" strokeLinejoin="round" />
    <path d="M12 10v4" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
  </svg>
);

const VisualLineActionBar = ({ selectedLineId, openAction }) => {
  const disabled = !selectedLineId;

  return (
    <div className="grid grid-cols-3 gap-1.5 border-t border-[#DAE2E2] bg-[#F8FAFC] p-1.5">
      <LineToolButton
        title="Prep Messages"
        disabled={disabled}
        onClick={() => openAction?.("prepMessages")}
        icon={<PrepMessageIcon />}
      />
      <LineToolButton
        title="Modifiers"
        disabled={disabled}
        onClick={() => openAction?.("modifiers")}
        icon={<ModifierIcon />}
      />
      <LineToolButton
        title="Split"
        disabled={disabled}
        onClick={() => openAction?.("splitLine")}
        icon={<SplitLineIcon />}
      />
    </div>
  );
};

const LineToolButton = ({ title, icon, disabled = false, onClick }) => (
  <button
    type="button"
    disabled={disabled}
    onClick={onClick}
    className="flex min-h-[48px] flex-col items-center justify-center border border-[#BAC8C8] bg-white text-[#293939] shadow-[inset_0_-4px_0_#DAE2E2] transition active:scale-[0.99] hover:bg-[#EEF8F6] disabled:cursor-not-allowed disabled:opacity-45"
  >
    <span className="mb-1 text-[#006969]">{icon}</span>
    <span className="text-[8px] font-black uppercase tracking-[0.12em] leading-tight">{title}</span>
  </button>
);

const VisualProductArea = ({
  rightPanel,
  showingSearch,
  categoryOptions,
  selectedCategoryId,
  handleCategorySelect,
  drillCategory,
  drillSubCategories = [],
  selectedVisualSubCategoryId = "",
  handleSubCategorySelect,
  goBackToMainCategories,
  filteredProducts,
  loadingProducts,
  handleProductClick,
  saleCurrencyCode,
  convertMoney,
  paymentTypes,
  selectedPaymentTypeId,
  setSelectedPaymentTypeId,
  openDiscountModal,
  handleProcess,
  openCashup,
  fastFoodIndustryMode = false,
}) => {
  return (
    <div className="grid h-full min-h-0 grid-cols-[minmax(0,1fr)_270px] bg-[#EFF3F3]">
      <VisualProductGridPremium
        filteredProducts={filteredProducts}
        loadingProducts={loadingProducts}
        handleProductClick={handleProductClick}
        saleCurrencyCode={saleCurrencyCode}
        convertMoney={convertMoney}
        highlightFirst={showingSearch}
        fastFoodIndustryMode={fastFoodIndustryMode}
      />

      <VisualCategoryRail
        categoryOptions={categoryOptions}
        selectedCategoryId={selectedCategoryId}
        handleCategorySelect={handleCategorySelect}
        drillCategory={drillCategory}
        drillSubCategories={drillSubCategories}
        selectedVisualSubCategoryId={selectedVisualSubCategoryId}
        handleSubCategorySelect={handleSubCategorySelect}
        goBackToMainCategories={goBackToMainCategories}
      />
    </div>
  );
};

const VisualCategoryRail = ({
  categoryOptions = [],
  selectedCategoryId,
  handleCategorySelect,
  drillCategory = null,
  drillSubCategories = [],
  selectedVisualSubCategoryId = "",
  handleSubCategorySelect,
  goBackToMainCategories,
}) => {
  const showingSubCategories = Boolean(drillCategory && drillSubCategories.length > 0);
  const rows = showingSubCategories ? drillSubCategories : categoryOptions;
  const activeCategoryName = drillCategory?.name || "Categories";

  return (
    <aside className="min-h-0 border-l border-[#DAE2E2] bg-white">
      <div className="flex h-11 items-center justify-between gap-2 border-b border-[#DAE2E2] bg-[#F8FAFC] px-2">
        {showingSubCategories ? (
          <button
            type="button"
            onClick={goBackToMainCategories}
            className="h-7 bg-[#004142] px-2 text-[9px] font-black uppercase tracking-wide text-white hover:bg-[#003637]"
          >
            Back
          </button>
        ) : (
          <span className="text-[9px] font-black uppercase tracking-[0.18em] text-[#627474]">
            Categories
          </span>
        )}

        <span className="min-w-0 truncate text-right text-[9px] font-semibold uppercase tracking-wide text-[#96A5A4]">
          {showingSubCategories ? activeCategoryName : `${rows.length} front`}
        </span>
      </div>

      <div className="h-[calc(100%-44px)] overflow-auto p-2">
        <div className="grid gap-2 transition-all duration-300">
          {rows.map((category) => {
            const active = showingSubCategories
              ? String(selectedVisualSubCategoryId) === String(category.id)
              : String(selectedCategoryId) === String(category.id);

            const imageUrl = getCategoryImageUrl(category);
            const childCount = getCategoryChildren(category).length;

            return (
              <button
                key={category.id}
                type="button"
                onClick={() =>
                  showingSubCategories
                    ? handleSubCategorySelect?.(category)
                    : handleCategorySelect?.(category)
                }
                className={`group relative h-[104px] overflow-hidden border text-left shadow-[0_4px_12px_rgba(9,27,27,0.08)] transition duration-200 active:scale-[0.99] ${
                  active
                    ? "border-[#00BCBC] shadow-[inset_4px_0_0_#00BCBC,0_6px_16px_rgba(9,27,27,0.12)]"
                    : "border-[#DAE2E2] hover:border-[#00BCBC]"
                }`}
              >
                {imageUrl ? (
                  <img
                    src={imageUrl}
                    alt={category?.name || "Category"}
                    loading="lazy"
                    className="absolute inset-0 h-full w-full object-cover opacity-100 transition duration-300 group-hover:scale-[1.04]"
                    draggable={false}
                  />
                ) : (
                  <div className="absolute inset-0 bg-[#E2E9E9]" />
                )}

                <div
                  className={`absolute inset-0 ${
                    active
                      ? "bg-[#004142]/55"
                      : "bg-gradient-to-t from-[#003637]/85 via-[#003637]/35 to-transparent"
                  }`}
                />

                <div className="relative flex h-full flex-col justify-end p-2.5">
                  <div className="line-clamp-2 text-[12px] font-black uppercase leading-tight tracking-wide text-white drop-shadow">
                    {category.name}
                  </div>

                  <div className="mt-1 flex items-center justify-between gap-2">
                    <span className="min-w-0 truncate text-[8px] font-black uppercase tracking-[0.16em] text-white/85">
                      {category.code || (showingSubCategories ? "Sub Category" : "Category")}
                    </span>

                    {!showingSubCategories && childCount > 0 && (
                      <span className="shrink-0 bg-[#00BCBC] px-1.5 py-[2px] text-[8px] font-black uppercase text-[#004141]">
                        {childCount} Sub
                      </span>
                    )}
                  </div>
                </div>
              </button>
            );
          })}

          {rows.length === 0 && (
            <div className="px-2 py-8 text-center text-[10px] font-semibold uppercase tracking-wide text-[#96A5A4]">
              {showingSubCategories ? "No sub categories" : "No front categories"}
            </div>
          )}
        </div>
      </div>
    </aside>
  );
};

const VisualCategoryTilePanel = ({ categoryOptions, selectedCategoryId, handleCategorySelect }) => {
  const visibleCategories = (categoryOptions || []).filter(categoryVisibleOnFront);

  return (
    <div className="h-full overflow-auto bg-[#EFF3F3] p-2">
      <div className="grid grid-cols-[repeat(auto-fill,minmax(170px,1fr))] gap-2">
        {visibleCategories.map((category) => {
          const active = selectedCategoryId === category.id;
          const imageUrl = getCategoryImageUrl(category);
          const childCount = getCategoryChildren(category).length;

          return (
            <button
              key={category.id}
              type="button"
              onClick={() => handleCategorySelect(category)}
              className={`group relative h-[110px] overflow-hidden border bg-white text-left shadow-[0_4px_12px_rgba(9,27,27,0.08)] transition active:scale-[0.99] ${
                active ? "border-[#00BCBC]" : "border-[#DAE2E2] hover:border-[#00BCBC]"
              }`}
            >
              {imageUrl ? (
                <img
                  src={imageUrl}
                  alt={category?.name || "Category"}
                  className="absolute inset-0 h-full w-full object-cover transition duration-300 group-hover:scale-[1.04]"
                  draggable={false}
                />
              ) : (
                <div className="absolute inset-0 bg-[#E2E9E9]" />
              )}

              <div className="absolute inset-0 bg-gradient-to-t from-[#003637]/85 via-[#003637]/35 to-transparent" />

              <div className="relative flex h-full flex-col justify-end p-2.5">
                <div className="line-clamp-2 text-[12px] font-black uppercase leading-tight tracking-wide text-white drop-shadow">
                  {category.name}
                </div>
                <div className="mt-1 flex items-center justify-between gap-2">
                  <span className="truncate text-[8px] font-semibold uppercase tracking-[0.16em] text-white/80">
                    {category.code || "Category"}
                  </span>
                  {childCount > 0 && (
                    <span className="shrink-0 bg-[#00BCBC] px-1.5 py-[2px] text-[8px] font-black uppercase text-[#004141]">
                      {childCount} Sub
                    </span>
                  )}
                </div>
              </div>
            </button>
          );
        })}

        {visibleCategories.length === 0 && (
          <div className="col-span-full flex h-[180px] items-center justify-center text-[12px] font-semibold uppercase tracking-wide text-[#96A5A4]">
            No front categories
          </div>
        )}
      </div>
    </div>
  );
};

const VisualProductGridPremium = ({
  filteredProducts,
  loadingProducts,
  handleProductClick,
  saleCurrencyCode,
  convertMoney,
  highlightFirst = false,
  fastFoodIndustryMode = false,
}) => {
  const [previewPrices, setPreviewPrices] = useState({});

  useEffect(() => {
    let mounted = true;

    const loadPreviewPrices = async () => {
      if (!saleCurrencyCode) return;
      const next = {};

      for (const product of filteredProducts.slice(0, 160)) {
        const price = getStandardPrice(product);
        if (!price) continue;
        try {
          next[product.id] = await convertMoney(
            price.currency || saleCurrencyCode,
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

  if (loadingProducts) {
    return (
      <div className="flex h-full items-center justify-center bg-[#EFF3F3] text-[12px] font-black uppercase tracking-wide text-[#566969]">
        <RefreshCw className="mr-2 h-4 w-4 animate-spin" /> Loading products...
      </div>
    );
  }

  if (filteredProducts.length === 0) {
    return (
      <div className="flex h-full items-center justify-center bg-[#EFF3F3] text-[12px] font-black uppercase tracking-wide text-[#8B9797]">
        No products found.
      </div>
    );
  }

  return (
    <div className="h-full overflow-auto bg-[#EFF3F3] p-2">
      <div className="grid grid-cols-[repeat(auto-fill,minmax(178px,1fr))] gap-2">
        {filteredProducts.map((product, index) => {
          const price = getStandardPrice(product);
          const shownPrice = previewPrices[product.id] ?? Number(price?.price || 0);
          const selected = highlightFirst && index === 0;
          const stockBlocked = productIsBlockedByStock(product);
          const availableStock = getProductAvailableStock(product);
          return (
            <button
              key={product.id}
              type="button"
              tabIndex={-1}
              disabled={stockBlocked}
              onPointerDown={(event) => {
                event.preventDefault();
                blurActiveInput();
              }}
              onTouchStart={() => {
                blurActiveInput();
              }}
              onClick={(event) => {
                event.preventDefault();
                blurActiveInput();

                if (!stockBlocked) {
                  handleProductClick(product);
                }
              }}
              title={
                stockBlocked
                  ? `${product.name} has no available stock and negative stock is not allowed.`
                  : product.name
              }
              className={`group flex h-[126px] flex-col overflow-hidden bg-white text-left shadow-[0_5px_16px_rgba(9,27,27,0.07)] transition active:scale-[0.99] disabled:cursor-not-allowed disabled:opacity-55 ${
                selected && !stockBlocked ? "shadow-[inset_0_-4px_0_#00BCBC,0_8px_18px_rgba(9,27,27,0.12)]" : "hover:bg-[#FBFDFE]"
              }`}
            >
              <div className="relative flex min-h-0 flex-1 items-center justify-center bg-[#F8FAFC]">
                <ProductTileImage product={product} selected={selected && !stockBlocked} />
                {stockBlocked && (
                  <div className="absolute left-2 top-2 bg-[#FEF2F2] px-1.5 py-0.5 text-[8px] font-black uppercase tracking-wide text-[#B42318]">
                    No stock
                  </div>
                )}
              </div>

              <div className="bg-white px-2.5 py-2">
                <div className="line-clamp-2 min-h-[30px] text-[10.5px] font-bold leading-tight tracking-wide text-[#1E2B2B]">
                  {product.name}
                </div>
                <div className="mt-1 flex items-end justify-between gap-2">
                  <div className="min-w-0 truncate text-[8px] font-semibold uppercase tracking-[0.14em] text-[#8B9797]">
                    {fastFoodIndustryMode || !productTracksStock(product) ? "Menu" : `Stock ${formatStockQty(availableStock)}`}
                  </div>
                  <div className="shrink-0 text-right text-[12px] font-black leading-none text-[#057B7C]">
                    {currencyMoney(saleCurrencyCode, shownPrice)}
                  </div>
                </div>
              </div>
            </button>
          );
        })}
      </div>
    </div>
  );
};

const ProductTileImage = ({ product, selected = false }) => {
  const imageUrl = getProductImageUrl(product);

  if (!imageUrl) {
    return (
      <div className="flex h-full w-full items-center justify-center bg-[#F1F5F5] text-[9px] font-black uppercase tracking-wide text-[#93A6A6]">
        Menu Item
      </div>
    );
  }

  return (
    <div className="h-full w-full overflow-hidden bg-[#F8FAFC]">
      <img
        src={imageUrl}
        alt={product?.name || "Product"}
        loading="lazy"
        className="h-full w-full object-cover transition duration-200 group-hover:scale-[1.03]"
        draggable={false}
      />
    </div>
  );
};

const VisualPager = ({
  currentPage = 1,
  pageCount = 1,
  pageSize = 24,
  totalItems = 0,
  onFirst,
  onPrev,
  onNext,
  onLast,
}) => {
  const fromItem = totalItems === 0 ? 0 : (currentPage - 1) * pageSize + 1;
  const toItem = Math.min(totalItems, currentPage * pageSize);
  const canGoBack = currentPage > 1;
  const canGoForward = currentPage < pageCount;

  return (
    <div className="grid grid-cols-[1fr_auto_1fr] items-center border-t border-[#DAE2E2] bg-white px-2 text-[10px] font-black uppercase tracking-wide text-[#627474]">
      <span className="truncate">
        Showing {fromItem}-{toItem} of {totalItems}
      </span>

      <div className="flex h-full items-center gap-1">
        <PagerButton disabled={!canGoBack} onClick={onFirst}>|&lt;</PagerButton>
        <PagerButton disabled={!canGoBack} onClick={onPrev}>&lt;</PagerButton>
        <div className="flex h-7 min-w-[96px] items-center justify-center bg-[#E7FBF7] px-3 text-[10px] font-black text-[#005A5A]">
          Page {currentPage} / {pageCount}
        </div>
        <PagerButton disabled={!canGoForward} onClick={onNext}>&gt;</PagerButton>
        <PagerButton disabled={!canGoForward} onClick={onLast}>&gt;|</PagerButton>
      </div>

      <span className="truncate text-right text-[#96A5A4]">{pageSize} products per page</span>
    </div>
  );
};

const PagerButton = ({ children, disabled = false, onClick }) => (
  <button
    type="button"
    disabled={disabled}
    onClick={onClick}
    className="flex h-7 min-w-[38px] items-center justify-center bg-[#069495] px-2 text-[11px] font-black text-white disabled:cursor-not-allowed disabled:bg-[#DAE2E2] disabled:text-[#8B9797]"
  >
    {children}
  </button>
);

const PaymentTile = ({ method, active, hotkey, onClick }) => {
  return (
    <button
      type="button"
      onClick={onClick}
      className={`relative flex min-h-[48px] flex-col items-center justify-center border border-b-[3px] text-center transition active:scale-[0.99] ${
        active
          ? "border-[#00B2B2] border-b-[#00B2B2] bg-[#E8F7F5] text-[#005A5A]"
          : "border-[#CAD5D5] border-b-[#00B2B2] bg-white text-[#2B3A3A] hover:bg-[#F1F5F5]"
      }`}
    >
      {hotkey && <span className="absolute left-2 top-1 text-[10px] font-semibold opacity-80">{hotkey}</span>}
      <span className="text-[11px] font-semibold leading-tight">{method.name}</span>
    </button>
  );
};

const PaymentSmall = ({ method, active, onClick }) => {
  return (
    <button
      type="button"
      onClick={onClick}
      className={`flex items-center justify-center border border-b-[3px] px-2 text-[11px] font-semibold ${
        active
          ? "border-[#00B2B2] border-b-[#00B2B2] bg-[#E8F7F5] text-[#005A5A]"
          : "border-[#CAD5D5] border-b-[#00B2B2] bg-white text-[#2B3A3A]"
      }`}
    >
      {method.name}
    </button>
  );
};

const TopIconAction = ({ children, title, onClick }) => (
  <button
    type="button"
    title={title}
    onClick={onClick}
    className="flex h-full w-[48px] items-center justify-center bg-[#EFF3F3] text-[#004141] transition active:scale-[0.99] hover:bg-[#E7FBF7]"
  >
    {children}
  </button>
);

const TopAction = ({ children, hotkey, onClick, primary = false, large = false }) => (
  <button
    type="button"
    onClick={onClick}
    className={`relative flex h-full ${large ? "w-[126px]" : "w-[94px]"} flex-col items-center justify-center text-center uppercase tracking-wide transition active:scale-[0.99] ${
      primary
        ? "bg-[#069495] text-white hover:bg-[#067C7C]"
        : "bg-[#F8FAFC] text-[#293939] hover:bg-[#EEF8F6]"
    }`}
  >
    {hotkey && <span className="absolute left-1.5 top-1 text-[8px] font-black opacity-70">{hotkey}</span>}
    <span className={`${large ? "text-[16px]" : "text-[9.5px]"} font-black leading-tight`}>{children}</span>
  </button>
);

const MiniAction = ({ children, onClick, primary = false, danger = false }) => {
  let cls = "border-[#3C5352] bg-[#F8FAFC] text-[#152323] shadow-[inset_0_-4px_0_#DAE2E2] hover:bg-white";
  if (primary) cls = "border-[#117A37] bg-[#16A34A] text-white shadow-none hover:bg-[#15803D]";
  if (danger) cls = "border-[#EC4A3F] bg-[#C8372D] text-white shadow-[inset_0_-4px_0_rgba(0,0,0,0.22)] hover:bg-[#B72F26]";

  return (
    <button type="button" onClick={onClick} className={`flex flex-col items-center justify-center border text-[11px] font-black uppercase tracking-wide ${cls}`}>
      {children}
    </button>
  );
};

export default SalesPointFace;
