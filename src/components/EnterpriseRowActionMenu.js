import React, { useEffect, useLayoutEffect, useRef, useState } from "react";
import { createPortal } from "react-dom";
import { MoreVertical } from "lucide-react";

const MENU_WIDTH = 196;

export default function EnterpriseRowActionMenu({
  actions = [],
  label = "More actions",
  align = "end",
}) {
  const [open, setOpen] = useState(false);
  const [position, setPosition] = useState({ top: 0, left: 0 });
  const triggerRef = useRef(null);
  const menuRef = useRef(null);
  const availableActions = actions.filter(Boolean);

  useLayoutEffect(() => {
    if (!open) return undefined;

    const placeMenu = () => {
      const trigger = triggerRef.current?.getBoundingClientRect();
      if (!trigger) return;

      const estimatedHeight = Math.max(40, availableActions.length * 36 + 8);
      const preferredLeft = align === "start" ? trigger.left : trigger.right - MENU_WIDTH;
      const left = Math.max(8, Math.min(preferredLeft, window.innerWidth - MENU_WIDTH - 8));
      const below = trigger.bottom + estimatedHeight <= window.innerHeight - 8;
      const top = below
        ? trigger.bottom + 4
        : Math.max(8, trigger.top - estimatedHeight - 4);

      setPosition({ top, left });
    };

    placeMenu();
    window.addEventListener("resize", placeMenu);
    window.addEventListener("scroll", placeMenu, true);
    return () => {
      window.removeEventListener("resize", placeMenu);
      window.removeEventListener("scroll", placeMenu, true);
    };
  }, [align, availableActions.length, open]);

  useEffect(() => {
    if (!open) return undefined;

    const closeOutside = (event) => {
      if (
        !triggerRef.current?.contains(event.target)
        && !menuRef.current?.contains(event.target)
      ) {
        setOpen(false);
      }
    };
    const closeWithKeyboard = (event) => {
      if (event.key === "Escape") {
        setOpen(false);
        triggerRef.current?.focus();
      }
    };

    document.addEventListener("mousedown", closeOutside);
    document.addEventListener("keydown", closeWithKeyboard);
    const focusTimer = window.setTimeout(() => {
      menuRef.current?.querySelector('[role="menuitem"]:not(:disabled)')?.focus();
    }, 0);

    return () => {
      window.clearTimeout(focusTimer);
      document.removeEventListener("mousedown", closeOutside);
      document.removeEventListener("keydown", closeWithKeyboard);
    };
  }, [open]);

  if (!availableActions.length) return null;

  return (
    <span className="action-menu-wrap sx-row-actions" onClick={(event) => event.stopPropagation()}>
      <button
        ref={triggerRef}
        type="button"
        className="sx-row-actions-trigger"
        aria-label={label}
        aria-haspopup="menu"
        aria-expanded={open}
        onClick={() => setOpen((current) => !current)}
      >
        <MoreVertical size={18} aria-hidden="true" />
      </button>

      {open && typeof document !== "undefined" && createPortal(
        <div
          ref={menuRef}
          role="menu"
          aria-label={label}
          className="sx-row-actions-menu"
          style={{ top: position.top, left: position.left }}
          onClick={(event) => event.stopPropagation()}
        >
          {availableActions.map((action, index) => (
            <button
              key={`${action.label}-${index}`}
              type="button"
              role="menuitem"
              disabled={action.disabled}
              className={action.danger ? "sx-row-action-danger" : ""}
              onClick={() => {
                setOpen(false);
                action.onClick?.();
              }}
            >
              {action.label}
            </button>
          ))}
        </div>,
        document.body,
      )}
    </span>
  );
}
