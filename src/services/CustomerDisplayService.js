// src/services/CustomerDisplayService.js

const CHANNEL_NAME = "salexa_customer_display_channel";

export const customerDisplayChannel = () => {
  if (typeof window === "undefined") return null;

  if (!("BroadcastChannel" in window)) {
    return null;
  }

  return new BroadcastChannel(CHANNEL_NAME);
};

export const sendCustomerDisplayUpdate = (payload) => {
  const nextPayload = {
    ...payload,
    updated_at: new Date().toISOString(),
  };

  const channel = customerDisplayChannel();

  localStorage.setItem(
    "salexa_customer_display_payload",
    JSON.stringify(nextPayload)
  );

  window.dispatchEvent(new Event("salexa_customer_display_updated"));

  if (!channel) return;

  channel.postMessage({
    type: "CUSTOMER_DISPLAY_UPDATE",
    payload: nextPayload,
  });

  channel.close();
};

export const clearCustomerDisplay = () => {
  sendCustomerDisplayUpdate({
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
  });
};

export const openCustomerDisplayWindow = () => {
  const url = `${window.location.origin}/admin/pos/customer-display`;

  window.open(
    url,
    "salexa_customer_display",
    "width=900,height=700,left=100,top=100"
  );
};
