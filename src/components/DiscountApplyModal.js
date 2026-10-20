import React, { useEffect, useMemo, useState } from "react";
import { RefreshCw, X } from "lucide-react";
import { calculateDiscount, getEligibleDiscounts } from "../services/DiscountService";
import ManagerApprovalModal from "./ManagerApprovalModal";

const money = (value, currency = "USD") =>
  `${currency} ${Number(value || 0).toFixed(2)}`;

const getErrorMessage = (error, fallback) => {
  const data = error?.response?.data;
  if (data?.errors) return Object.values(data.errors).flat().join("\n");
  return data?.message || data?.error || error?.message || fallback;
};

const normalizeSaleDiscountScope = (scope) => {
  return scope === "cart" ? "cart" : "line";
};

const getLineProductId = (line) =>
  line?.product_id ||
  line?.productId ||
  line?.product?.id ||
  line?.rawProduct?.id ||
  "";

const getLineCategoryId = (line) =>
  line?.category_id ||
  line?.categoryId ||
  line?.product_category_id ||
  line?.productCategoryId ||
  line?.product?.category_id ||
  line?.product?.categoryId ||
  line?.rawProduct?.category_id ||
  line?.rawProduct?.category?.id ||
  line?.category?.id ||
  "";

const getLineName = (line) =>
  line?.name ||
  line?.product_name ||
  line?.productName ||
  line?.product?.name ||
  line?.rawProduct?.name ||
  "Selected Product";

const getLineQty = (line) =>
  Number(line?.qty || line?.quantity || line?.line_quantity || 1);

const getLineUnitPrice = (line) =>
  Number(
    line?.price ||
      line?.unitPrice ||
      line?.line_price ||
      line?.selling_price ||
      line?.product?.price ||
      0
  );

const getLineGross = (line) => {
  const explicit = Number(
    line?.lineTotal ??
      line?.line_total ??
      line?.subtotal ??
      line?.line_subtotal ??
      line?.total ??
      0
  );

  if (explicit > 0) return explicit;

  return getLineQty(line) * getLineUnitPrice(line);
};

const buildDiscountPayload = ({
  result,
  selected,
  reason,
  approval = null,
  line = null,
  coupon = "",
}) => {
  const originalScope = result?.scope || selected?.scope || "line";

  return {
    discount_id: result?.discount_id || selected?.id,
    discount_code: result?.discount_code || selected?.code,
    discount_name: result?.discount_name || selected?.name,
    discount_type_id: result?.discount_type_id || selected?.discount_type_id,

    scope: normalizeSaleDiscountScope(result?.sale_scope || originalScope),
    original_scope: originalScope,

    discount_basis: result?.discount_basis || selected?.discount_basis || "percentage",
    discount_value: Number(result?.discount_value ?? selected?.value ?? 0),
    gross_amount: Number(result?.gross_amount || getLineGross(line)),
    discount_amount: Number(result?.discount_amount || 0),
    net_amount: Number(result?.net_amount || 0),

    product_id: getLineProductId(line),
    category_id: getLineCategoryId(line),
    coupon_code: coupon || null,

    reason: reason || "",
    manager_approval_id: approval?.approval_id || null,
    approved_by: approval?.manager?.id || null,
    manager_approval: approval || null,

    requires_manager_approval: Boolean(
      result?.requires_manager_approval || selected?.requires_manager_approval
    ),
    requires_reason: Boolean(result?.requires_reason || selected?.requires_reason),
    manager_approval_reason: result?.manager_approval_reason || null,
    role_permission: selected?.role_permission || null,
  };
};

const discountValueLabel = (discount, currency) => {
  const value = Number(discount?.value || 0);
  const basis = discount?.discount_basis || "percentage";

  if (basis === "percentage") return `${value.toFixed(2)}%`;
  if (basis === "fixed_price") return `Price ${money(value, currency)}`;
  return money(value, currency);
};

const basisLabel = (basis) => String(basis || "-").replaceAll("_", " ").toUpperCase();

export default function DiscountApplyModal({
  open,
  onClose,
  line,
  saleTotal = 0,
  customerId = null,
  currency = "USD",
  onApplied,
}) {
  const [rows, setRows] = useState([]);
  const [selected, setSelected] = useState(null);
  const [reason, setReason] = useState("");
  const [coupon, setCoupon] = useState("");
  const [loading, setLoading] = useState(false);
  const [notice, setNotice] = useState("");
  const [managerOpen, setManagerOpen] = useState(false);
  const [pendingResult, setPendingResult] = useState(null);

  const productId = useMemo(() => getLineProductId(line), [line]);
  const categoryId = useMemo(() => getLineCategoryId(line), [line]);
  const quantity = useMemo(() => getLineQty(line), [line]);
  const gross = useMemo(() => getLineGross(line), [line]);

  const selectedValue = selected ? discountValueLabel(selected, currency) : "-";
  const selectedBasis = selected ? basisLabel(selected.discount_basis) : "-";

  useEffect(() => {
    if (open && line) {
      setSelected(null);
      setPendingResult(null);
      setManagerOpen(false);
      load();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, productId, categoryId, quantity, gross]);

  if (!open) return null;

  const load = async () => {
    try {
      setLoading(true);
      setNotice("");
      setRows([]);
      setSelected(null);

      if (!productId) {
        setNotice("No product ID on selected line.");
        return;
      }

      const res = await getEligibleDiscounts({
        product_id: productId,
        category_id: categoryId || undefined,
        sale_amount: saleTotal || gross,
        line_amount: gross,
        gross_amount: gross,
        quantity,
        customer_id: customerId || undefined,
        coupon_code: coupon || undefined,
      });

      const eligible = Array.isArray(res?.data) ? res.data : [];
      setRows(eligible);

      if (!eligible.length) {
        setNotice("No eligible discounts.");
      }
    } catch (err) {
      setNotice(getErrorMessage(err, "Failed to load discounts."));
    } finally {
      setLoading(false);
    }
  };

  const apply = async (discount = selected) => {
    if (!discount) {
      setNotice("Select a discount first.");
      return;
    }

    try {
      setLoading(true);
      setNotice("");
      setSelected(discount);

      if (!productId) {
        setNotice("No product ID on selected line.");
        return;
      }

      const res = await calculateDiscount({
        discount_id: discount.id,
        product_id: productId,
        category_id: categoryId || undefined,
        gross_amount: gross,
        quantity,
        currency,
        customer_id: customerId || undefined,
        coupon_code: coupon || undefined,
      });

      const result = res?.data || res;

      if (!Number(result?.discount_amount || 0)) {
        setNotice("Discount is 0.00.");
        return;
      }

      if (result.requires_reason && !reason.trim()) {
        setNotice("Reason required.");
        return;
      }

      if (result.requires_manager_approval) {
        setPendingResult(result);
        setManagerOpen(true);
        return;
      }

      onApplied?.(
        buildDiscountPayload({
          result,
          selected: discount,
          reason,
          approval: null,
          line,
          coupon,
        })
      );

      onClose?.();
    } catch (err) {
      setNotice(getErrorMessage(err, "Failed to apply discount."));
    } finally {
      setLoading(false);
    }
  };

  const approved = (approval) => {
    if (!pendingResult) return;

    onApplied?.(
      buildDiscountPayload({
        result: pendingResult,
        selected,
        reason,
        approval,
        line,
        coupon,
      })
    );

    setManagerOpen(false);
    setPendingResult(null);
    onClose?.();
  };

  return (
    <div className="fixed inset-0 z-[210] bg-black/55 p-3">
      <form
        onSubmit={(event) => {
          event.preventDefault();
          apply(selected);
        }}
        className="flex h-full min-h-0 flex-col border border-[#AAB5B5] bg-white text-[#1C2C2B] shadow-2xl"
      >
        <div className="flex h-11 shrink-0 items-center justify-between border-b border-[#00BCBC] bg-[#004142] px-3 text-white">
          <div>
            <div className="text-[13px] font-semibold uppercase">Apply Discount</div>
            <div className="text-[10px] font-medium uppercase text-white/65">
              {getLineName(line)} / {money(gross, currency)}
            </div>
          </div>

          <button
            type="button"
            onClick={onClose}
            className="flex h-8 w-8 items-center justify-center border border-white/20 bg-white/10 hover:bg-white/20"
          >
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
            <Panel title="Sale Line">
              <SummaryLine label="Product" value={getLineName(line)} />
              <SummaryLine label="Gross" value={money(gross, currency)} />
              <SummaryLine label="Qty" value={quantity} />
              <SummaryLine label="Selected" value={selectedValue} />
            </Panel>

            <div className="mt-2 border border-[#CFD9D8] bg-white p-2">
              <div>
                <div className="mb-1 text-[10px] font-semibold uppercase text-[#627474]">
                  Coupon
                </div>
                <div className="flex gap-1">
                  <input
                    type="text"
                    value={coupon ?? ""}
                    onChange={(event) => setCoupon(event.target.value.toUpperCase())}
                    onKeyDown={(event) => {
                      if (event.key === "Enter") {
                        event.preventDefault();
                        load();
                      }
                    }}
                    className="h-8 min-w-0 flex-1 border border-[#AAB5B5] bg-white px-2 text-[11px] outline-none focus:border-[#069495]"
                  />
                  <button
                    type="button"
                    onClick={load}
                    disabled={loading}
                    className="h-8 shrink-0 border border-[#069495] bg-[#069495] px-3 text-[10px] font-semibold uppercase text-white disabled:opacity-60"
                  >
                    Load Coupon
                  </button>
                </div>
              </div>
              <label className="mt-2 block">
                <div className="mb-1 text-[10px] font-semibold uppercase text-[#627474]">
                  Reason
                </div>
                <textarea
                  value={reason}
                  onChange={(event) => setReason(event.target.value)}
                  rows={5}
                  className="w-full border border-[#AAB5B5] bg-white px-2 py-2 text-[11px] outline-none focus:border-[#069495]"
                />
              </label>
            </div>

            <div className="mt-2 border border-[#CFD9D8] bg-white p-2 text-[10px] text-[#627474]">
              <div className="font-semibold uppercase text-[#324443]">Selection</div>
              <div className="mt-2 grid gap-1">
                <SummaryLine label="Basis" value={selectedBasis} />
                <SummaryLine label="Rows" value={rows.length} />
                <SummaryLine label="Status" value={loading ? "Loading" : "Ready"} />
              </div>
            </div>
          </aside>

          <main className="min-h-0 overflow-hidden bg-[#FBFCFE] p-3">
            <div className="flex h-full min-h-0 flex-col border border-[#CFD9D8] bg-white">
              <div className="flex h-9 shrink-0 items-center justify-between border-b border-[#CFD9D8] bg-[#F5F7FA] px-2">
                <div className="text-[12px] font-semibold uppercase text-[#324443]">
                  Eligible Discounts
                </div>
                <button
                  type="button"
                  onClick={load}
                  disabled={loading}
                  className="inline-flex h-7 items-center gap-1 border border-[#AAB5B5] bg-white px-3 text-[10px] font-semibold uppercase text-[#324443] disabled:opacity-60"
                >
                  <RefreshCw className={`h-3.5 w-3.5 ${loading ? "animate-spin" : ""}`} />
                  {loading ? "Loading" : "Refresh"}
                </button>
              </div>

              <div className="min-h-0 flex-1 overflow-auto">
                <table className="w-full min-w-[980px] border-collapse text-left text-[11px]">
                  <thead className="sticky top-0 z-10 bg-[#0E4D4D] text-white">
                    <tr>
                      <GridTh>#</GridTh>
                      <GridTh>Code</GridTh>
                      <GridTh>Discount</GridTh>
                      <GridTh>Type</GridTh>
                      <GridTh>Basis</GridTh>
                      <GridTh align="right">Value</GridTh>
                      <GridTh>Flags</GridTh>
                    </tr>
                  </thead>
                  <tbody>
                    {rows.length === 0 ? (
                      <tr>
                        <td colSpan={7} className="px-3 py-12 text-center text-[12px] font-semibold text-[#627474]">
                          {loading ? "Loading..." : "No discounts."}
                        </td>
                      </tr>
                    ) : (
                      rows.map((discount, index) => {
                        const isSelected = selected?.id === discount.id;

                        return (
                          <tr
                            key={discount.id || index}
                            onClick={() => setSelected(discount)}
                            onDoubleClick={() => apply(discount)}
                            className={`cursor-pointer border-b border-[#CFD9D8] ${
                              isSelected
                                ? "bg-[#E8F7F5] text-[#005A5A]"
                                : "bg-white hover:bg-[#F8FAFC]"
                            }`}
                          >
                            <GridTd strong={isSelected}>{index + 1}</GridTd>
                            <GridTd strong={isSelected}>{discount.code || "-"}</GridTd>
                            <GridTd strong={isSelected}>{discount.name || "-"}</GridTd>
                            <GridTd>{discount.discount_type_name || discount.type?.name || "-"}</GridTd>
                            <GridTd>{basisLabel(discount.discount_basis)}</GridTd>
                            <GridTd right strong>{discountValueLabel(discount, currency)}</GridTd>
                            <GridTd>
                              <div className="flex flex-wrap gap-1">
                                {!discount.applies_to_all_products && <Pill>Product</Pill>}
                                {!discount.applies_to_all_categories && <Pill>Category</Pill>}
                                {discount.role_permission && <Pill tone="warning">Role</Pill>}
                                {discount.requires_manager_approval && <Pill tone="danger">Manager</Pill>}
                                {!discount.requires_manager_approval && <Pill tone="good">Normal</Pill>}
                              </div>
                            </GridTd>
                          </tr>
                        );
                      })
                    )}
                  </tbody>
                </table>
              </div>
            </div>
          </main>
        </div>

        <div className="flex h-12 shrink-0 items-center justify-between border-t border-[#CFD9D8] bg-[#F5F7FA] px-3">
          <div className="truncate text-[10px] font-semibold uppercase text-[#627474]">
            {selected ? `${selected.code || "DISCOUNT"} / ${selected.name || "Selected"}` : "Select a discount"}
          </div>
          <div className="flex gap-2">
            <button
              type="button"
              onClick={onClose}
              className="h-8 border border-[#AAB5B5] bg-white px-4 text-[10px] font-semibold uppercase text-[#324443]"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={loading || !selected}
              className="h-8 border border-[#069495] bg-[#069495] px-5 text-[10px] font-semibold uppercase text-white disabled:opacity-60"
            >
              {loading ? "Applying..." : "Apply Discount"}
            </button>
          </div>
        </div>
      </form>

      <ManagerApprovalModal
        open={managerOpen}
        onClose={() => setManagerOpen(false)}
        onApproved={approved}
        title="Manager Discount Approval"
        description={`Approve discount for ${getLineName(line)}.`}
        action="discount_override"
        permission="approve_discount_override"
        reason={reason || `Discount ${selected?.name || ""}`}
        referenceType="discount"
        referenceId={selected?.id}
        metadata={{
          product_id: productId,
          category_id: categoryId,
          product_name: getLineName(line),
          discount_id: selected?.id,
          discount_name: selected?.name,
          gross_amount: gross,
          discount_amount: pendingResult?.discount_amount,
          manager_approval_reason: pendingResult?.manager_approval_reason,
        }}
      />
    </div>
  );
}

function Panel({ title, children }) {
  return (
    <div className="border border-[#CFD9D8] bg-white p-2 shadow-sm">
      <div className="mb-2 border-b border-[#E4E8E8] pb-2 text-[11px] font-semibold uppercase text-[#324443]">
        {title}
      </div>
      {children}
    </div>
  );
}

function SummaryLine({ label, value }) {
  return (
    <div className="flex items-center justify-between gap-2 border-b border-[#EEF2F2] py-1 text-[10px] last:border-b-0">
      <span className="text-[#627474]">{label}</span>
      <span className="truncate text-right font-semibold text-[#1C2C2B]">{value}</span>
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

function Input({ label, value, onChange, onEnter }) {
  return (
    <label className="block">
      <div className="mb-1 text-[10px] font-semibold uppercase text-[#627474]">{label}</div>
      <input
        type="text"
        value={value ?? ""}
        onChange={(event) => onChange(event.target.value)}
        onKeyDown={(event) => {
          if (event.key === "Enter") {
            event.preventDefault();
            onEnter?.();
          }
        }}
        className="h-8 w-full border border-[#AAB5B5] bg-white px-2 text-[11px] outline-none focus:border-[#069495]"
      />
    </label>
  );
}
