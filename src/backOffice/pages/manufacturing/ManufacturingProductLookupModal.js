import React, { useEffect } from "react";
import { Search } from "lucide-react";
import {
  SmallButton,
  getProductCode,
  getProductCost,
  getProductCurrency,
  getProductName,
  getProductStock,
  getProductUnit,
  moneyNumber,
  qtyNumber,
  formatProductType,
} from "./ManufacturingShared";

export default function ManufacturingProductLookupModal({
  open,
  title = "Select Product",
  search,
  setSearch,
  products,
  searchProducts,
  onSelect,
  onClose,
  defaultCurrency = "",
}) {
  useEffect(() => {
    if (!open) return;
    const timer = window.setTimeout(() => searchProducts(search || ""), 250);
    return () => window.clearTimeout(timer);
  }, [open, search]);

  if (!open) return null;

  return (
    <div className="fixed inset-0 z-[150000] flex items-center justify-center bg-black/50 p-4">
      <div className="sx-large-modal-window border border-[#ACB8B8] bg-white shadow-2xl" role="dialog" aria-modal="true" aria-label={title}>
        <div className="flex h-11 items-center justify-between border-b border-[#ACB8B8] bg-[#004142] px-3">
          <div className="text-[12px] font-semibold uppercase text-white">{title}</div>
          <button type="button" onClick={onClose} className="text-[20px] leading-none text-white">×</button>
        </div>

        <div className="border-b border-[#D8DFDF] bg-[#F8FAFC] p-2">
          <div className="relative">
            <Search className="absolute left-2 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-[#627474]" />
            <input
              value={search || ""}
              onChange={(event) => setSearch(event.target.value)}
              placeholder="Search by product, code, SKU or barcode..."
              className="h-8 w-full border border-[#ACB8B8] bg-white pl-8 pr-2 text-[12px] outline-none"
            />
          </div>
        </div>

        <div className="min-h-0 flex-1 overflow-auto">
          <table className="w-full min-w-[920px] border-collapse text-left text-[11px]">
            <thead className="sticky top-0 z-10 bg-[#0E4D4D] text-white">
              <tr>
                <th className="border-r border-[#465857] px-2 py-1.5">Code</th>
                <th className="border-r border-[#465857] px-2 py-1.5">Product</th>
                <th className="border-r border-[#465857] px-2 py-1.5">Type</th>
                <th className="border-r border-[#465857] px-2 py-1.5">UOM</th>
                <th className="border-r border-[#465857] px-2 py-1.5 text-right">Available</th>
                <th className="border-r border-[#465857] px-2 py-1.5 text-right">Avg Cost</th>
                <th className="px-2 py-1.5">Action</th>
              </tr>
            </thead>
            <tbody>
              {products.length === 0 ? (
                <tr><td colSpan={7} className="px-3 py-10 text-center text-[#627474]">No matching products found.</td></tr>
              ) : products.map((product) => (
                <tr key={product.id} className="border-b border-[#CFD9D8] hover:bg-[#F8FAFC]">
                  <td className="border-r border-[#CFD9D8] px-2 py-1.5">{getProductCode(product)}</td>
                  <td className="border-r border-[#CFD9D8] px-2 py-1.5 font-semibold text-[#0C1B1B]">{getProductName(product)}</td>
                  <td className="border-r border-[#CFD9D8] px-2 py-1.5 capitalize">{formatProductType(product.product_type)}</td>
                  <td className="border-r border-[#CFD9D8] px-2 py-1.5">{getProductUnit(product)}</td>
                  <td className="border-r border-[#CFD9D8] px-2 py-1.5 text-right">{qtyNumber(getProductStock(product))}</td>
                  <td className="border-r border-[#CFD9D8] px-2 py-1.5 text-right">{getProductCurrency(product, defaultCurrency)} {moneyNumber(getProductCost(product))}</td>
                  <td className="px-2 py-1.5"><SmallButton onClick={() => onSelect(product)}>Select</SmallButton></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
