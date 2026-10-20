import React from "react";
import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import "@testing-library/jest-dom";

import SalesPointFace from "./SalesPointFace";
import { sellableTillMenuItems } from "../CreateSale";

jest.mock("../../../../services/MenuItemService", () => ({
  getSalesIndustryMode: () => Promise.resolve(null),
}));

const burger = {
  id: "burger",
  name: "Burger",
  code: "PRD00005",
  product_type: "finished_good",
  category_id: "food",
  image_url: "http://127.0.0.1:8002/assets/product-images/burger.png",
  is_active: true,
  prices: [{ id: "channel-1", price: 3, currency: "USD", is_default: true, is_active: true }],
};

const menuItem = (number) => ({
  ...burger,
  id: `item-${number}`,
  name: `Menu item ${number}`,
  image_url: null,
});

const noop = () => {};

const renderTill = (props = {}) => render(
  <SalesPointFace
    layout="standard"
    cart={[]}
    totals={{ subTotal: 0, discount: 0, tax: 0, total: 0, currency: "USD" }}
    money={(value) => `USD ${Number(value || 0).toFixed(2)}`}
    formatQty={(value) => String(value)}
    getLineTotal={(line) => line.qty * line.price}
    getLineTax={() => 0}
    getLineSubtotal={() => 0}
    getLineDiscount={() => 0}
    getLineTaxSummary={() => ""}
    search=""
    setSearch={noop}
    barcodeInput=""
    handleBarcodeSubmit={noop}
    handleBarcodeChange={noop}
    categoryOptions={[{ id: "all", name: "All" }, { id: "food", name: "Food", display_area: "both" }]}
    selectedCategoryId="all"
    handleCategorySelect={noop}
    filteredProducts={[burger]}
    loadingProducts={false}
    saleCurrencyCode="USD"
    convertMoney={async (from, to, amount) => Number(amount)}
    paymentTypes={[]}
    setSelectedPaymentTypeId={noop}
    setRightMode={noop}
    focusBarcode={noop}
    {...props}
  />,
);

const orderWithBurger = {
  cart: [{ lineId: "line-1", name: "Burger", code: "PRD00005", qty: 2, price: 3, currency: "USD" }],
  selectedLineId: "line-1",
  totals: { subTotal: 6, discount: 0, tax: 0, total: 6, currency: "USD" },
};

describe("restaurant till layout", () => {
  test("menu picture tiles add the item to the order", async () => {
    const added = [];
    renderTill({ handleProductClick: (product, qty) => added.push([product.id, qty]) });

    expect(await screen.findByAltText("Burger")).toHaveAttribute("src", burger.image_url);
    expect(screen.getByText("3.00")).toBeInTheDocument();
    fireEvent.click(screen.getByTitle("Burger"));
    expect(added).toEqual([["burger", null]]);
  });

  test("categories run down the left of the menu", async () => {
    const chosen = [];
    renderTill({ handleCategorySelect: (category) => chosen.push(category.id) });

    const column = await screen.findByRole("navigation", { name: "Menu categories" });
    expect(within(column).getByRole("button", { name: /All/ })).toHaveAttribute("aria-pressed", "true");
    fireEvent.click(within(column).getByRole("button", { name: /Food/ }));
    expect(chosen).toEqual(["food"]);
  });

  test("shows sixteen tiles per page with page navigation", async () => {
    renderTill({ filteredProducts: Array.from({ length: 20 }, (_, index) => menuItem(index + 1)) });

    expect(await screen.findByTitle("Menu item 16")).toBeInTheDocument();
    expect(screen.queryByTitle("Menu item 17")).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Next page" }));
    expect(screen.getByTitle("Menu item 20")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Page 2" })).toHaveAttribute("aria-current", "page");
  });

  test("a keyed quantity is used for the next item tapped", async () => {
    const added = [];
    renderTill({ handleProductClick: (product, qty) => added.push([product.id, qty]) });

    fireEvent.click(await screen.findByRole("button", { name: "3" }));
    expect(screen.getByLabelText("Keypad entry")).toHaveTextContent("3");
    fireEvent.click(screen.getByTitle("Burger"));
    expect(added).toEqual([["burger", 3]]);
    expect(screen.getByLabelText("Keypad entry")).toHaveTextContent("Qty or cash");
  });

  test("Pay takes the keyed cash straight to checkout without a payment page", async () => {
    const payments = [];
    renderTill({
      ...orderWithBurger,
      quickPay: async (tendered, options) => {
        payments.push([tendered, options]);
        return true;
      },
    });

    fireEvent.click(await screen.findByRole("button", { name: "2" }));
    fireEvent.click(screen.getByRole("button", { name: "0" }));
    expect(screen.getByLabelText("Keypad entry")).toHaveTextContent("20");
    fireEvent.click(screen.getByRole("button", { name: /Pay/ }));
    await waitFor(() => expect(payments).toEqual([[20, undefined]]));
    await waitFor(() => expect(screen.getByLabelText("Keypad entry")).toHaveTextContent("Qty or cash"));

    fireEvent.click(screen.getByRole("button", { name: /Pay/ }));
    await waitFor(() => expect(payments[1]).toEqual([null, undefined]));

    fireEvent.click(screen.getByRole("button", { name: /Fast cash/ }));
    await waitFor(() => expect(payments[2]).toEqual([null, { cash: true }]));
  });

  test("Qty sets the selected line to the keyed quantity, or opens the editor", async () => {
    const quantities = [];
    const actions = [];
    renderTill({
      ...orderWithBurger,
      setSelectedLineQuantity: (qty) => quantities.push(qty),
      openAction: (action) => actions.push(action),
    });

    fireEvent.click(await screen.findByRole("button", { name: /Qty/ }));
    expect(actions).toEqual(["editLine"]);

    fireEvent.click(screen.getByRole("button", { name: "5" }));
    fireEvent.click(screen.getByRole("button", { name: /Qty/ }));
    expect(quantities).toEqual([5]);
  });

  test("the order shows its lines, line buttons and total on the right", async () => {
    const actions = [];
    const removed = [];
    renderTill({
      ...orderWithBurger,
      openAction: (action) => actions.push(action),
      removeSelectedLine: () => removed.push(true),
    });

    const order = await screen.findByRole("region", { name: "Order" });
    expect(within(order).getByText("Burger")).toBeInTheDocument();
    expect(within(order).getByLabelText("Order total")).toHaveTextContent("USD 6.00");
    expect(within(order).getByLabelText("2 items in order")).toBeInTheDocument();

    fireEvent.click(within(order).getByRole("button", { name: "Prep note" }));
    fireEvent.click(within(order).getByRole("button", { name: "Line discount" }));
    fireEvent.click(within(order).getByRole("button", { name: "Remove line" }));
    expect(actions).toEqual(["prepMessages", "discount"]);
    expect(removed).toHaveLength(1);
    expect(within(order).getByRole("button", { name: /Pay/ })).toBeInTheDocument();
  });

  test("plus and minus on a line add or take away one of that item", async () => {
    const steps = [];
    renderTill({
      cart: [
        { lineId: "line-1", name: "Burger", qty: 2, price: 3, currency: "USD" },
        { lineId: "line-2", name: "Chips", qty: 1, price: 1, currency: "USD" },
      ],
      selectedLineId: "line-1",
      stepLineQuantity: (lineId, direction) => steps.push([lineId, direction]),
    });

    fireEvent.click(await screen.findByRole("button", { name: "One more Chips" }));
    fireEvent.click(screen.getByRole("button", { name: "One less Burger" }));
    expect(steps).toEqual([["line-2", 1], ["line-1", -1]]);
  });

  test("a line shows its prep note and messages in readable text", async () => {
    renderTill({
      cart: [{
        lineId: "line-1", name: "Burger", qty: 1, price: 3, currency: "USD",
        prep_messages: [{ id: "p1", message: "No onions" }], prep_note: "Cut in half",
      }],
    });

    expect(await screen.findByText("* No onions")).toBeInTheDocument();
    expect(screen.getByText("Note: Cut in half")).toBeInTheDocument();
  });

  test("only priced, active menu items from the till menu are sellable", () => {
    const rows = sellableTillMenuItems([
      burger,
      { ...burger, id: "unpriced", prices: [] },
      { ...burger, id: "inactive", is_active: false },
      { ...burger, id: "ingredient", product_type: "raw_material" },
    ]);

    expect(rows.map((row) => row.id)).toEqual(["burger"]);
  });
});
