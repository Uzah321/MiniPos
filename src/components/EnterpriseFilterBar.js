import React from "react";
import { Search } from "lucide-react";

export default function EnterpriseFilterBar({
  children,
  leading,
  actions,
  searchValue,
  onSearchChange,
  onSearch,
  searchLabel = "Search records",
  searchPlaceholder = "Search",
}) {
  const hasSearch = typeof onSearchChange === "function";

  return (
    <form
      className="sx-filter-bar"
      role="search"
      onSubmit={(event) => {
        event.preventDefault();
        onSearch?.();
      }}
    >
      {leading && <div className="sx-filter-leading">{leading}</div>}
      <div className="sx-filter-fields">{children}</div>
      {hasSearch && (
        <label className="sx-filter-search">
          <span className="sr-only">{searchLabel}</span>
          <Search size={15} aria-hidden="true" />
          <input
            type="search"
            aria-label={searchLabel}
            value={searchValue ?? ""}
            onChange={(event) => onSearchChange(event.target.value)}
            placeholder={searchPlaceholder}
          />
        </label>
      )}
      {actions && <div className="sx-filter-actions">{actions}</div>}
    </form>
  );
}
