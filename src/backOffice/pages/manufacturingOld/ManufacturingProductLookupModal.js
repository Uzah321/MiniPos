import React from "react";
import {
  ActionButton,
  DataTable,
  Td,
  SmallButton,
  Search,
  X,
  getProductCode,
  getProductCost,
  getProductName,
  getProductStock,
  moneyNumber,
  qtyNumber,
} from "./ManufacturingShared";

export default function ManufacturingProductLookupModal({
  open,
  title = "Product Lookup",
  search,
  setSearch,
  products,
  searchProducts,
  onSelect,
  onClose,
}) {
  if (!open) return null;

  return (
    <div className="absolute inset-0 z-[300] flex items-center justify-center bg-[#004040]/60 p-4">
      <div className="flex h-[74vh] w-full max-w-[980px] flex-col border border-[#AFB9B9] bg-white shadow-2xl">
        <div className="flex h-11 shrink-0 items-center justify-between border-b border-[#AFB9B9] bg-[#EEF2F2] px-3">
          <div>
            <div className="text-[13px] font-bold text-[#0C1B1B]">{title}</div>
            <div className="text-[10px] text-[#627474]">Select stock item or finished product</div>
          </div>
          <button type="button" onClick={onClose} className="flex h-8 w-8 items-center justify-center border border-[#AFB9B9] bg-white">
            <X className="h-4 w-4" />
          </button>
        </div>

        <div className="border-b border-[#D8DFDF] p-2">
          <div className="flex gap-2">
            <div className="relative min-w-0 flex-1">
              <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[#627474]" />
              <input
                autoFocus
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === "Enter") searchProducts(search);
                }}
                placeholder="Search item code, SKU, barcode or description..."
                className="h-9 w-full border border-[#B9C3C3] bg-white pl-9 pr-3 text-[12px] outline-none focus:border-[#004343]"
              />
            </div>
            <ActionButton onClick={() => searchProducts(search)} label="Search" icon={Search} primary />
          </div>
        </div>

        <div className="min-h-0 flex-1 overflow-auto">
          <DataTable columns={["Code", "Product", "Category", "Stock", "Cost", ""]} emptyText="No products found.">
            {products.map((product) => (
              <tr key={product.id} className="border-b border-[#E9EDED]">
                <Td>{getProductCode(product) || "-"}</Td>
                <Td strong>{getProductName(product)}</Td>
                <Td>{product.category?.name || "Uncategorised"}</Td>
                <Td right>{qtyNumber(getProductStock(product))}</Td>
                <Td right>{moneyNumber(getProductCost(product))}</Td>
                <Td right><SmallButton onClick={() => onSelect(product)}>Select</SmallButton></Td>
              </tr>
            ))}
          </DataTable>
        </div>
      </div>
    </div>
  );
}
