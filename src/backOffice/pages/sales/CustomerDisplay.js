// src/backOffice/pages/sales/CustomerDisplay.jsx

import React, { useEffect, useMemo, useState } from "react";
import { customerDisplayChannel } from "../../../services/CustomerDisplayService";

const defaultPayload = {
  status: "idle",
  cart: [],
  totals: {
    subTotal: 0,
    discount: 0,
    tax: 0,
    total: 0,
    received: 0,
    change: 0,
    balance: 0,
    currency: "USD",
  },
  customer: {
    name: "Walk-in Customer",
  },
  message: "Welcome",
};

const money = (value, currency = "USD") =>
  `${currency || "USD"} ${Number(value || 0).toFixed(2)}`;

const qtyText = (line) => {
  const allowDecimalQty = line?.allowDecimalQty || line?.allow_decimal_qty;
  const decimalPlaces = Number(line?.decimalPlaces ?? line?.decimal_places ?? 2);
  const qty = Number(line?.qty || line?.quantity || 0);
  const unit = line?.unit || line?.uom || "";

  const cleanQty = allowDecimalQty
    ? qty.toFixed(decimalPlaces)
    : qty.toFixed(0);

  return unit ? `${cleanQty} ${unit}` : cleanQty;
};

const lineTotal = (line) => {
  return (
    Number(line?.price || 0) * Number(line?.qty || line?.quantity || 0) -
    Number(line?.discount || 0)
  );
};

const CustomerDisplay = () => {
  const [payload, setPayload] = useState(defaultPayload);

  useEffect(() => {
    const saved = localStorage.getItem("salexa_customer_display_payload");

    if (saved) {
      try {
        setPayload({
          ...defaultPayload,
          ...JSON.parse(saved),
        });
      } catch {
        setPayload(defaultPayload);
      }
    }

    const channel = customerDisplayChannel();

    if (channel) {
      channel.onmessage = (event) => {
        if (event?.data?.type === "CUSTOMER_DISPLAY_UPDATE") {
          const nextPayload = {
            ...defaultPayload,
            ...event.data.payload,
          };

          setPayload(nextPayload);

          localStorage.setItem(
            "salexa_customer_display_payload",
            JSON.stringify(nextPayload)
          );
        }
      };
    }

    const fallbackHandler = () => {
      const current = localStorage.getItem("salexa_customer_display_payload");

      if (!current) return;

      try {
        setPayload({
          ...defaultPayload,
          ...JSON.parse(current),
        });
      } catch {
        // keep current display
      }
    };

    window.addEventListener("storage", fallbackHandler);
    window.addEventListener("salexa_customer_display_updated", fallbackHandler);

    return () => {
      channel?.close?.();
      window.removeEventListener("storage", fallbackHandler);
      window.removeEventListener("salexa_customer_display_updated", fallbackHandler);
    };
  }, []);

  const cart = Array.isArray(payload.cart) ? payload.cart : [];
  const totals = payload.totals || defaultPayload.totals;

  const displayTotals = useMemo(() => {
    const subTotal = Number(totals.subTotal ?? totals.subtotal ?? 0);
    const discount = Number(totals.discount || 0);
    const total = Number(totals.total || 0);
    const currency = totals.currency || cart?.[0]?.currency || "USD";

    return {
      subTotal,
      discount,
      total,
      currency,
    };
  }, [totals, cart]);

  const latestItems = useMemo(() => {
    return cart.slice(-8).reverse();
  }, [cart]);

  return (
    <div className="min-h-screen bg-[#DFE5E5] text-[#003636]">
      <div className="grid min-h-screen grid-rows-[52px_1fr_150px] sm:grid-rows-[58px_1fr_170px] lg:grid-rows-[64px_1fr_190px]">
        <header className="flex items-center justify-between border-b border-[#ACB8B8] bg-[#004142] px-3 text-white sm:px-5">
          <div>
            <div className="text-[16px] font-black tracking-wide sm:text-[20px] lg:text-[24px]">
              SERVENEST POS
            </div>
            <div className="text-[10px] font-medium text-white/70 sm:text-[12px]">
              Customer Display
            </div>
          </div>

          <div className="text-right">
            <div className="text-[10px] text-white/70 sm:text-[12px]">
              Status
            </div>
            <div className="text-[12px] font-black uppercase sm:text-[15px]">
              {payload?.message || "Welcome"}
            </div>
          </div>
        </header>

        <main className="min-h-0 overflow-hidden p-2 sm:p-3">
          <section className="flex h-full min-h-0 flex-col border border-[#ACB8B8] bg-white">
            <div className="grid h-9 shrink-0 grid-cols-[1fr_72px_100px] items-center border-b border-[#ACB8B8] bg-[#F5F7FA] px-2 text-[10px] font-black uppercase text-[#324444] sm:grid-cols-[1fr_95px_130px] sm:px-3 sm:text-[12px]">
              <div>Items</div>
              <div className="text-right">Qty</div>
              <div className="text-right">Amount</div>
            </div>

            <div className="min-h-0 flex-1 overflow-y-auto">
              {latestItems.length === 0 ? (
                <div className="flex h-full flex-col items-center justify-center px-4 text-center text-[#637877]">
                  <div className="text-[24px] font-black sm:text-[34px]">
                    Welcome
                  </div>
                  <div className="mt-2 text-[13px] font-medium sm:text-[16px]">
                    Your items will appear here.
                  </div>
                </div>
              ) : (
                <div>
                  {latestItems.map((line) => (
                    <div
                      key={line.lineId || line.productId || line.id}
                      className="grid grid-cols-[1fr_72px_100px] items-center border-b border-[#E3E9E9] px-2 py-2 sm:grid-cols-[1fr_95px_130px] sm:px-3 sm:py-3"
                    >
                      <div className="min-w-0">
                        <div className="truncate text-[13px] font-black sm:text-[17px] lg:text-[19px]">
                          {line.name || line.line_name || "Item"}
                        </div>

                        {Number(line.discount || 0) > 0 && (
                          <div className="mt-0.5 text-[10px] font-black text-[#E30613] sm:text-[12px]">
                            Discount -{money(line.discount, line.currency || displayTotals.currency)}
                          </div>
                        )}
                      </div>

                      <div className="text-right text-[12px] font-black sm:text-[15px]">
                        {qtyText(line)}
                      </div>

                      <div className="text-right text-[13px] font-black text-[#069495] sm:text-[17px] lg:text-[19px]">
                        {money(lineTotal(line), line.currency || displayTotals.currency)}
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </section>
        </main>

        <footer className="border-t border-[#ACB8B8] bg-white">
          <div className="grid h-full grid-cols-[1fr_1.35fr]">
            <div className="grid grid-rows-2 border-r border-[#ACB8B8]">
              <SummaryLine
                label="Items"
                value={String(cart.length)}
              />

              <SummaryLine
                label="Discount"
                value={`-${money(displayTotals.discount, displayTotals.currency)}`}
                danger={displayTotals.discount > 0}
              />
            </div>

            <div className="flex flex-col justify-center bg-[#004142] px-4 text-white sm:px-6">
              <div className="text-[12px] font-medium uppercase text-white/70 sm:text-[14px]">
                Total
              </div>

              <div className="mt-1 truncate text-[34px] font-black leading-none sm:text-[48px] lg:text-[58px]">
                {money(displayTotals.total, displayTotals.currency)}
              </div>
            </div>
          </div>
        </footer>
      </div>
    </div>
  );
};

const SummaryLine = ({ label, value, danger = false }) => (
  <div className="flex items-center justify-between border-b border-[#E3E9E9] px-3 last:border-b-0 sm:px-5">
    <div className="text-[12px] font-black uppercase text-[#637877] sm:text-[14px]">
      {label}
    </div>

    <div
      className={`text-[18px] font-black sm:text-[25px] ${
        danger ? "text-[#E30613]" : "text-[#003636]"
      }`}
    >
      {value}
    </div>
  </div>
);

export default CustomerDisplay;
