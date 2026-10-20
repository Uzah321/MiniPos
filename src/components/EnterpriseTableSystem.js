import { useEffect } from "react";

const ACTION_HEADER = /^(action|actions|option|options|more|menu|manage|controls?)$/i;
const ACTION_TARGETS = "button, a[href], input[type='button'], input[type='submit']";
const DATA_ENTRY_TARGETS = "input:not([type='button']):not([type='submit']):not([type='reset']):not([type='hidden']), select, textarea, [contenteditable='true']";

function findActionColumn(table) {
  const headers = Array.from(table.tHead?.rows?.[0]?.cells || []);
  const labelledIndex = headers.findIndex((cell) =>
    ACTION_HEADER.test(String(cell.textContent || "").trim())
  );

  if (labelledIndex >= 0) return labelledIndex;

  const bodyRows = Array.from(table.tBodies || []).flatMap((body) =>
    Array.from(body.rows || [])
  );
  const maximumCells = Math.max(0, ...bodyRows.map((row) => row.cells.length));
  const lastIndex = maximumCells - 1;
  const lastCells = bodyRows
    .map((row) => row.cells[lastIndex])
    .filter(Boolean);

  if (
    lastIndex >= 0 &&
    lastCells.some((cell) => cell.querySelector(ACTION_TARGETS)) &&
    !lastCells.some((cell) => cell.querySelector(DATA_ENTRY_TARGETS))
  ) {
    return lastIndex;
  }

  return -1;
}

function enhanceTable(table) {
  if (!(table instanceof HTMLTableElement)) return;

  table.classList.add("sx-enterprise-table");
  // Tables with their own dropdown menu must keep that component visible and
  // clickable. The generic action-column enhancer is only for inline buttons.
  if (table.querySelector(".action-menu-wrap")) {
    table.querySelectorAll(".sx-table-actions-head").forEach((cell) => cell.classList.remove("sx-table-actions-head"));
    table.querySelectorAll("td.sx-table-actions").forEach((cell) => {
      cell.classList.remove("sx-table-actions", "sx-actions-open");
      cell.removeAttribute("tabindex");
      cell.removeAttribute("role");
      cell.removeAttribute("aria-label");
      cell.removeAttribute("aria-expanded");
    });
    return;
  }
  const actionIndex = findActionColumn(table);
  if (actionIndex < 0) return;

  const header = table.tHead?.rows?.[0]?.cells?.[actionIndex];
  if (header) {
    header.classList.add("sx-table-actions-head");
    header.setAttribute("aria-label", "Options");
  }

  Array.from(table.tBodies || []).forEach((body) => {
    Array.from(body.rows || []).forEach((row) => {
      const cell = row.cells[actionIndex];
      if (!cell?.querySelector(ACTION_TARGETS)) return;

      cell.classList.add("sx-table-actions");
      cell.tabIndex = 0;
      cell.setAttribute("role", "button");
      cell.setAttribute("aria-label", "Row options");
      cell.setAttribute(
        "aria-expanded",
        cell.classList.contains("sx-actions-open") ? "true" : "false"
      );
    });
  });
}

function enhanceTablesWithin(root) {
  if (root instanceof HTMLTableElement) enhanceTable(root);
  const parentTable = root.closest?.("table");
  if (parentTable) enhanceTable(parentTable);
  root.querySelectorAll?.("table").forEach(enhanceTable);
}

function closeActionMenus(except = null) {
  document.querySelectorAll("td.sx-actions-open").forEach((cell) => {
    if (cell === except) return;
    cell.classList.remove("sx-actions-open");
    cell.setAttribute("aria-expanded", "false");
  });
}

export default function EnterpriseTableSystem() {
  useEffect(() => {
    enhanceTablesWithin(document);

    const observer = new MutationObserver((mutations) => {
      mutations.forEach((mutation) => {
        mutation.addedNodes.forEach((node) => {
          if (node.nodeType === Node.ELEMENT_NODE) enhanceTablesWithin(node);
        });
      });
    });

    observer.observe(document.body, { childList: true, subtree: true });

    const handleClick = (event) => {
      const cell = event.target.closest?.("td.sx-table-actions");
      const action = event.target.closest?.(ACTION_TARGETS);

      if (cell && (!action || action === cell)) {
        event.preventDefault();
        event.stopPropagation();
        const shouldOpen = !cell.classList.contains("sx-actions-open");
        closeActionMenus(cell);
        cell.classList.toggle("sx-actions-open", shouldOpen);
        cell.setAttribute("aria-expanded", String(shouldOpen));
        return;
      }

      if (cell && action) {
        window.setTimeout(() => closeActionMenus(), 0);
        return;
      }

      closeActionMenus();
    };

    const handleKeyDown = (event) => {
      const cell = event.target.closest?.("td.sx-table-actions");
      if (event.key === "Escape") {
        closeActionMenus();
        return;
      }

      if (!cell || (event.key !== "Enter" && event.key !== " ")) return;
      event.preventDefault();
      event.stopPropagation();
      const shouldOpen = !cell.classList.contains("sx-actions-open");
      closeActionMenus(cell);
      cell.classList.toggle("sx-actions-open", shouldOpen);
      cell.setAttribute("aria-expanded", String(shouldOpen));
    };

    document.addEventListener("click", handleClick, true);
    document.addEventListener("keydown", handleKeyDown, true);

    return () => {
      observer.disconnect();
      document.removeEventListener("click", handleClick, true);
      document.removeEventListener("keydown", handleKeyDown, true);
    };
  }, []);

  return null;
}
