
import React, { useCallback, useEffect, useMemo, useRef, useState } from "react";
import {
  ArrowUp,
  CornerDownLeft,
  Delete,
  GripHorizontal,
  Keyboard,
  Minus,
  Move,
  PanelBottomClose,
  PanelBottomOpen,
  Space,
  X,
} from "lucide-react";
import { getTouchKeyboardSetting } from "../services/GeneralSettingsService";

const TEXT_INPUT_TYPES = new Set([
  "text",
  "search",
  "tel",
  "url",
  "email",
  "password",
  "number",
]);

const CONTROL_INPUT_TYPES = new Set([
  "button",
  "checkbox",
  "color",
  "date",
  "datetime-local",
  "file",
  "hidden",
  "image",
  "month",
  "radio",
  "range",
  "reset",
  "submit",
  "time",
  "week",
]);

const LETTER_ROWS = [
  ["1", "2", "3", "4", "5", "6", "7", "8", "9", "0"],
  ["q", "w", "e", "r", "t", "y", "u", "i", "o", "p"],
  ["a", "s", "d", "f", "g", "h", "j", "k", "l"],
  ["z", "x", "c", "v", "b", "n", "m", ".", "-", "_"],
];

const NUMERIC_ROWS = [
  ["1", "2", "3"],
  ["4", "5", "6"],
  ["7", "8", "9"],
  [".", "0", "00"],
];

const STORAGE_KEY = "salexa_touch_keyboard_position_v3";

const boolValue = (value) =>
  value === true ||
  value === 1 ||
  value === "1" ||
  String(value || "").toLowerCase() === "true" ||
  String(value || "").toLowerCase() === "yes";

const clamp = (value, min, max) => Math.min(Math.max(value, min), max);

const getInputType = (element) =>
  String(element?.getAttribute?.("type") || "text").toLowerCase();

const isNumberInput = (element) =>
  element?.tagName?.toLowerCase() === "input" && getInputType(element) === "number";

const supportsTextSelection = (element) => {
  if (!element) return false;
  if (element.isContentEditable) return false;

  const tagName = element.tagName?.toLowerCase();
  if (tagName === "textarea") return true;
  if (tagName !== "input") return false;

  /*
   * HTMLInputElement.setSelectionRange is not supported on type="number".
   * Supported types include text/search/tel/url/password.
   */
  return ["text", "search", "tel", "url", "password"].includes(getInputType(element));
};

const isEditableElement = (element) => {
  if (!element) return false;

  if (
    element.dataset?.touchKeyboard === "off" ||
    element.dataset?.touchKeyboardDisabled === "true" ||
    element.closest?.("[data-touch-keyboard-disabled='true']")
  ) {
    return false;
  }

  const tagName = element.tagName?.toLowerCase();

  if (element.isContentEditable) return true;
  if (tagName === "textarea") return true;

  if (tagName !== "input") return false;

  const type = getInputType(element);

  if (CONTROL_INPUT_TYPES.has(type)) return false;

  return TEXT_INPUT_TYPES.has(type) || !type;
};

const shouldUseNumericKeyboard = (element) => {
  if (!element) return false;

  const type = getInputType(element);
  const inputMode = String(element.getAttribute("inputmode") || "").toLowerCase();

  return (
    type === "number" ||
    inputMode === "numeric" ||
    inputMode === "decimal" ||
    element.dataset?.keyboard === "number" ||
    element.dataset?.touchKeyboard === "number"
  );
};

const setNativeValue = (element, value) => {
  /*
   * React controlled inputs do not always react to element.value = x.
   * Using the native value setter and then dispatching input/change keeps
   * onChange handlers firing continuously.
   */
  const proto =
    element instanceof HTMLTextAreaElement
      ? window.HTMLTextAreaElement.prototype
      : window.HTMLInputElement.prototype;

  const descriptor = Object.getOwnPropertyDescriptor(proto, "value");

  if (descriptor?.set) {
    descriptor.set.call(element, value);
  } else {
    element.value = value;
  }
};

const dispatchInputEvents = (element) => {
  element.dispatchEvent(new Event("input", { bubbles: true }));
  element.dispatchEvent(new Event("change", { bubbles: true }));
};

const focusEditable = (element) => {
  try {
    element?.focus?.({ preventScroll: true });
  } catch {
    element?.focus?.();
  }
};

const insertTextIntoElement = (element, text) => {
  if (!element) return;

  if (element.isContentEditable) {
    focusEditable(element);
    document.execCommand("insertText", false, text);
    return;
  }

  const currentValue = String(element.value ?? "");

  /*
   * type="number" does not support selectionStart/selectionEnd/setSelectionRange.
   * For number fields we append at the end and still trigger React onChange.
   */
  if (isNumberInput(element) || !supportsTextSelection(element)) {
    const nextValue = `${currentValue}${text}`;
    setNativeValue(element, nextValue);
    dispatchInputEvents(element);
    focusEditable(element);
    return;
  }

  const start = element.selectionStart ?? currentValue.length;
  const end = element.selectionEnd ?? currentValue.length;
  const before = currentValue.slice(0, start);
  const after = currentValue.slice(end);
  const nextValue = `${before}${text}${after}`;
  const nextPosition = start + text.length;

  setNativeValue(element, nextValue);

  try {
    element.setSelectionRange(nextPosition, nextPosition);
  } catch {
    // Some browser/input combinations still reject selection APIs.
  }

  dispatchInputEvents(element);
  focusEditable(element);
};

const backspaceElement = (element) => {
  if (!element) return;

  if (element.isContentEditable) {
    focusEditable(element);
    document.execCommand("delete", false, null);
    return;
  }

  const currentValue = String(element.value ?? "");

  if (!currentValue) return;

  if (isNumberInput(element) || !supportsTextSelection(element)) {
    const nextValue = currentValue.slice(0, -1);
    setNativeValue(element, nextValue);
    dispatchInputEvents(element);
    focusEditable(element);
    return;
  }

  const start = element.selectionStart ?? currentValue.length;
  const end = element.selectionEnd ?? currentValue.length;

  if (start === 0 && end === 0) return;

  const deleteStart = start === end ? Math.max(0, start - 1) : start;
  const before = currentValue.slice(0, deleteStart);
  const after = currentValue.slice(end);
  const nextValue = `${before}${after}`;

  setNativeValue(element, nextValue);

  try {
    element.setSelectionRange(deleteStart, deleteStart);
  } catch {
    // Ignore unsupported selection API.
  }

  dispatchInputEvents(element);
  focusEditable(element);
};

const clearElement = (element) => {
  if (!element) return;

  if (element.isContentEditable) {
    element.textContent = "";
    focusEditable(element);
    return;
  }

  setNativeValue(element, "");

  if (supportsTextSelection(element)) {
    try {
      element.setSelectionRange(0, 0);
    } catch {
      // Ignore unsupported selection API.
    }
  }

  dispatchInputEvents(element);
  focusEditable(element);
};

const getDefaultPosition = () => {
  const width = typeof window !== "undefined" ? window.innerWidth : 1200;
  const height = typeof window !== "undefined" ? window.innerHeight : 800;

  const keyboardWidth = Math.min(760, width - 24);
  const keyboardHeight = 330;

  return {
    x: Math.max(16, width - keyboardWidth - 16),
    y: Math.max(80, height - keyboardHeight - 16),
  };
};

const getStoredPosition = () => {
  try {
    const raw = window.localStorage.getItem(STORAGE_KEY);
    if (!raw) return getDefaultPosition();

    const parsed = JSON.parse(raw);

    return {
      x: Number(parsed.x || getDefaultPosition().x),
      y: Number(parsed.y || getDefaultPosition().y),
    };
  } catch {
    return getDefaultPosition();
  }
};

const isInsideActionModal = (element) =>
  Boolean(
    element?.closest?.("[data-pos-action-panel='true']") ||
      element?.closest?.("[data-touch-keyboard-position='below']")
  );

const getPositionForElement = ({ element, numeric, keyboardElement }) => {
  const width = window.innerWidth;
  const height = window.innerHeight;
  const keyboardWidth = numeric ? Math.min(360, width - 16) : Math.min(760, width - 16);
  const keyboardHeight = keyboardElement?.getBoundingClientRect?.().height || 330;
  const fieldBox = element?.getBoundingClientRect?.();

  if (fieldBox && isInsideActionModal(element)) {
    const preferredX = fieldBox.left;
    const belowY = fieldBox.bottom + 8;
    const aboveY = fieldBox.top - keyboardHeight - 8;
    const hasRoomBelow = belowY + keyboardHeight <= height - 8;

    return {
      x: clamp(preferredX, 8, Math.max(8, width - keyboardWidth - 8)),
      y: hasRoomBelow ? belowY : Math.max(54, aboveY),
    };
  }

  return {
    x: Math.max(8, width - keyboardWidth - 16),
    y: Math.max(54, height - keyboardHeight - 16),
  };
};

export default function TouchKeyboardOverlay({
  settingKey = "touch_keyboard_enabled",
  module = "sales",
  title = "Touch Keyboard",
}) {
  const [enabled, setEnabled] = useState(false);
  const [checking, setChecking] = useState(false);
  const [visible, setVisible] = useState(false);
  const [shift, setShift] = useState(false);
  const [numeric, setNumeric] = useState(false);
  const [floating, setFloating] = useState(true);
  const [minimized, setMinimized] = useState(false);
  const [position, setPosition] = useState(getStoredPosition);
  const [dragging, setDragging] = useState(false);
  const [statusText, setStatusText] = useState("");

  const activeElementRef = useRef(null);
  const hideTimerRef = useRef(null);
  const keyboardRef = useRef(null);
  const dragRef = useRef({
    pointerId: null,
    startX: 0,
    startY: 0,
    startLeft: 0,
    startTop: 0,
  });

  const rows = useMemo(() => (numeric ? NUMERIC_ROWS : LETTER_ROWS), [numeric]);

  const persistPosition = useCallback((nextPosition) => {
    try {
      window.localStorage.setItem(STORAGE_KEY, JSON.stringify(nextPosition));
    } catch {
      // localStorage can be disabled in some browser modes.
    }
  }, []);

  const keepInsideScreen = useCallback((nextPosition = position) => {
    const element = keyboardRef.current;
    const width = window.innerWidth;
    const height = window.innerHeight;
    const box = element?.getBoundingClientRect?.();

    const keyboardWidth = box?.width || Math.min(760, width - 24);
    const keyboardHeight = box?.height || 300;

    const safePosition = {
      x: clamp(nextPosition.x, 8, Math.max(8, width - keyboardWidth - 8)),
      y: clamp(nextPosition.y, 54, Math.max(54, height - keyboardHeight - 8)),
    };

    setPosition(safePosition);
    persistPosition(safePosition);
  }, [persistPosition, position]);

  const loadSetting = useCallback(async () => {
    try {
      setChecking(true);

      const setting = await getTouchKeyboardSetting({
        setting_key: settingKey,
        module,
      });

      const active =
        setting?.is_effectively_enabled ??
        (boolValue(setting?.is_active ?? true) &&
          boolValue(
            setting?.setting_value ??
              setting?.value ??
              setting?.enabled ??
              setting?.is_enabled ??
              false
          ));

      setEnabled(Boolean(active));
      setStatusText(Boolean(active) ? "" : "Touch keyboard disabled in General Settings");
    } catch (error) {
      setEnabled(false);
      setStatusText(
        error?.response?.data?.message ||
          "Touch keyboard disabled because General Settings could not be verified"
      );
    } finally {
      setChecking(false);
    }
  }, [module, settingKey]);

  const showForElement = useCallback(
    (element) => {
      if (!enabled || !isEditableElement(element)) return;

      const nextNumeric = shouldUseNumericKeyboard(element);

      activeElementRef.current = element;
      setNumeric(nextNumeric);
      setMinimized(false);
      setVisible(true);

      window.setTimeout(() => {
        keepInsideScreen(
          getPositionForElement({
            element,
            numeric: nextNumeric,
            keyboardElement: keyboardRef.current,
          })
        );
      }, 60);
    },
    [enabled, keepInsideScreen]
  );

  const hideKeyboard = useCallback(() => {
    setVisible(false);
    setShift(false);
    setDragging(false);
  }, []);

  const onKeyPress = (value) => {
    const element = activeElementRef.current || document.activeElement;

    if (!element) return;

    const text = shift && value.length === 1 ? value.toUpperCase() : value;
    insertTextIntoElement(element, text);
    activeElementRef.current = element;

    if (shift && !numeric) {
      setShift(false);
    }
  };

  const onEnter = () => {
    const element = activeElementRef.current || document.activeElement;

    if (!element) {
      hideKeyboard();
      return;
    }

    element.dispatchEvent(
      new KeyboardEvent("keydown", {
        key: "Enter",
        code: "Enter",
        bubbles: true,
      })
    );

    if (element.form) {
      element.form.requestSubmit?.();
    }

    focusEditable(element);
    hideKeyboard();
  };

  const startDrag = (event) => {
    if (!floating) return;

    const target = event.target;

    if (target?.closest?.("button")) return;

    dragRef.current = {
      pointerId: event.pointerId,
      startX: event.clientX,
      startY: event.clientY,
      startLeft: position.x,
      startTop: position.y,
    };

    setDragging(true);
    event.currentTarget.setPointerCapture?.(event.pointerId);
  };

  const moveDrag = (event) => {
    if (!dragging || dragRef.current.pointerId !== event.pointerId) return;

    const next = {
      x: dragRef.current.startLeft + event.clientX - dragRef.current.startX,
      y: dragRef.current.startTop + event.clientY - dragRef.current.startY,
    };

    setPosition(next);
  };

  const stopDrag = (event) => {
    if (!dragging || dragRef.current.pointerId !== event.pointerId) return;

    setDragging(false);
    event.currentTarget.releasePointerCapture?.(event.pointerId);

    const finalPosition = {
      x: dragRef.current.startLeft + event.clientX - dragRef.current.startX,
      y: dragRef.current.startTop + event.clientY - dragRef.current.startY,
    };

    window.setTimeout(() => keepInsideScreen(finalPosition), 0);
  };

  useEffect(() => {
    loadSetting();

    const onRefresh = () => loadSetting();

    window.addEventListener("salexa_general_settings_updated", onRefresh);
    window.addEventListener("storage", onRefresh);

    return () => {
      window.removeEventListener("salexa_general_settings_updated", onRefresh);
      window.removeEventListener("storage", onRefresh);
    };
  }, [loadSetting]);

  useEffect(() => {
    const onFocusIn = (event) => {
      window.clearTimeout(hideTimerRef.current);

      if (event.target?.closest?.("[data-touch-keyboard-root='true']")) {
        return;
      }

      showForElement(event.target);
    };

    const onFocusOut = () => {
      window.clearTimeout(hideTimerRef.current);

      hideTimerRef.current = window.setTimeout(() => {
        const active = document.activeElement;

        if (!active?.closest?.("[data-touch-keyboard-root='true']")) {
          /*
           * Keep keyboard visible while the same editable input is still the
           * active target internally. This makes repeated taps continuous.
           */
          if (!activeElementRef.current || document.body === active) return;
          hideKeyboard();
        }
      }, 220);
    };

    document.addEventListener("focusin", onFocusIn);
    document.addEventListener("focusout", onFocusOut);

    return () => {
      document.removeEventListener("focusin", onFocusIn);
      document.removeEventListener("focusout", onFocusOut);
      window.clearTimeout(hideTimerRef.current);
    };
  }, [hideKeyboard, showForElement]);

  useEffect(() => {
    const onResize = () => keepInsideScreen();

    window.addEventListener("resize", onResize);

    return () => window.removeEventListener("resize", onResize);
  }, [keepInsideScreen]);

  if (!enabled && !checking) {
    return null;
  }

  if (!visible) {
    return (
      <div className="pointer-events-none fixed bottom-4 right-4 z-[120]">
        {checking ? (
          <div className="rounded-full border border-[#D7DFDF] bg-white px-3 py-2 text-[11px] font-black text-[#637877] shadow-xl">
            Checking keyboard setting...
          </div>
        ) : statusText ? (
          <div className="rounded-full border border-[#FEC84B] bg-[#FFFAEB] px-3 py-2 text-[11px] font-black text-[#B54708] shadow-xl">
            {statusText}
          </div>
        ) : null}
      </div>
    );
  }

  const keyboardStyle = floating
    ? {
        transform: `translate3d(${position.x}px, ${position.y}px, 0)`,
        width: numeric ? "min(360px, calc(100vw - 16px))" : "min(760px, calc(100vw - 16px))",
      }
    : undefined;

  return (
    <div
      data-touch-keyboard-root="true"
      className={`fixed z-[120] transition-all duration-300 ease-out ${
        floating ? "left-0 top-0" : "bottom-0 left-0 right-0"
      } ${visible ? "translate-y-0 opacity-100" : "translate-y-4 opacity-0"}`}
      style={keyboardStyle}
      onMouseDown={(event) => event.preventDefault()}
      onPointerDown={(event) => event.preventDefault()}
    >
      <div
        ref={keyboardRef}
        className={`overflow-hidden border border-[#CAD5D5] bg-white/95 shadow-2xl backdrop-blur ${
          floating ? "rounded-2xl" : "rounded-t-2xl"
        } ${dragging ? "scale-[1.01] shadow-[0_20px_50px_rgba(0,54,54,0.28)]" : ""}`}
      >
        <div
          className={`flex h-11 touch-none select-none items-center justify-between border-b border-[#D7DFDF] bg-[#004142] px-3 text-white ${
            floating ? "cursor-move" : ""
          }`}
          onPointerDown={startDrag}
          onPointerMove={moveDrag}
          onPointerUp={stopDrag}
          onPointerCancel={stopDrag}
        >
          <div className="flex min-w-0 items-center gap-2">
            {floating ? (
              <Move className="h-4 w-4 shrink-0 text-[#00BCBC]" />
            ) : (
              <Keyboard className="h-4 w-4 shrink-0 text-[#00BCBC]" />
            )}

            <span className="truncate text-[12px] font-black uppercase tracking-wide">
              {title}
            </span>

            <span className="hidden rounded-full bg-white/10 px-2 py-0.5 text-[9px] font-black uppercase text-white/70 sm:inline-flex">
              {numeric ? "Numeric" : "Text"}
            </span>
          </div>

          <div className="flex shrink-0 items-center gap-1.5">
            <button
              type="button"
              onClick={() => setMinimized((current) => !current)}
              className="flex h-7 w-8 items-center justify-center rounded-lg border border-white/20 hover:bg-white/10"
              title={minimized ? "Expand keyboard" : "Minimize keyboard"}
            >
              <Minus className="h-4 w-4" />
            </button>

            <button
              type="button"
              onClick={() => {
                setFloating((current) => !current);
                window.setTimeout(() => keepInsideScreen(), 50);
              }}
              className="flex h-7 min-w-[76px] items-center justify-center gap-1 rounded-lg border border-white/20 px-2 text-[10px] font-black uppercase hover:bg-white/10"
            >
              {floating ? <PanelBottomOpen className="h-4 w-4" /> : <PanelBottomClose className="h-4 w-4" />}
              {floating ? "Dock" : "Float"}
            </button>

            <button
              type="button"
              onClick={hideKeyboard}
              className="flex h-7 w-8 items-center justify-center rounded-lg border border-white/20 hover:bg-white/10"
              title="Close keyboard"
            >
              <X className="h-4 w-4" />
            </button>
          </div>
        </div>

        <div
          className={`grid transition-all duration-300 ease-out ${
            minimized ? "max-h-0 opacity-0" : "max-h-[55vh] opacity-100"
          }`}
        >
          <div className="space-y-2 overflow-y-auto bg-[#F6F8FA] p-2 sm:p-3">
            {floating ? (
              <div className="mb-1 flex items-center justify-center gap-2 text-[10px] font-black uppercase text-[#637877]">
                <GripHorizontal className="h-4 w-4" />
                Drag from the blue bar to move
              </div>
            ) : null}

            <div className={`mx-auto ${numeric ? "max-w-[330px]" : "max-w-[720px]"}`}>
              {rows.map((row, rowIndex) => (
                <div
                  key={`row-${rowIndex}`}
                  className={`mb-2 grid gap-1.5 sm:gap-2 ${
                    numeric ? "grid-cols-3" : "grid-cols-10"
                  }`}
                >
                  {row.map((key) => (
                    <button
                      key={key}
                      type="button"
                      onPointerDown={(event) => event.preventDefault()}
                      onClick={() => onKeyPress(key)}
                      className="h-11 rounded-xl border border-[#CBD6D6] bg-white px-1 text-[14px] font-black text-[#003636] shadow-sm transition hover:bg-[#E8F7F5] active:scale-95 sm:h-12 sm:text-[16px]"
                    >
                      {shift && key.length === 1 ? key.toUpperCase() : key}
                    </button>
                  ))}
                </div>
              ))}

              <div className="grid grid-cols-12 gap-1.5 sm:gap-2">
                {!numeric && (
                  <button
                    type="button"
                    onPointerDown={(event) => event.preventDefault()}
                    onClick={() => setShift((current) => !current)}
                    className={`col-span-3 flex h-11 items-center justify-center rounded-xl border px-2 text-[11px] font-black shadow-sm transition active:scale-95 sm:h-12 ${
                      shift
                        ? "border-[#069495] bg-[#E8F7F5] text-[#006869]"
                        : "border-[#CBD6D6] bg-white text-[#003636]"
                    }`}
                  >
                    <ArrowUp className="mr-1 h-4 w-4" />
                    Shift
                  </button>
                )}

                <button
                  type="button"
                  onPointerDown={(event) => event.preventDefault()}
                  onClick={() => onKeyPress(" ")}
                  className={`${numeric ? "col-span-4" : "col-span-4"} flex h-11 items-center justify-center rounded-xl border border-[#CBD6D6] bg-white px-2 text-[11px] font-black text-[#003636] shadow-sm transition hover:bg-[#E8F7F5] active:scale-95 sm:h-12`}
                >
                  <Space className="mr-1 h-4 w-4" />
                  Space
                </button>

                <button
                  type="button"
                  onPointerDown={(event) => event.preventDefault()}
                  onClick={() => backspaceElement(activeElementRef.current || document.activeElement)}
                  className={`${numeric ? "col-span-4" : "col-span-2"} flex h-11 items-center justify-center rounded-xl border border-[#CBD6D6] bg-white px-2 text-[11px] font-black text-[#003636] shadow-sm transition hover:bg-[#FEF3F2] active:scale-95 sm:h-12`}
                >
                  <Delete className="mr-1 h-4 w-4" />
                  Back
                </button>

                <button
                  type="button"
                  onPointerDown={(event) => event.preventDefault()}
                  onClick={() => clearElement(activeElementRef.current || document.activeElement)}
                  className={`${numeric ? "col-span-2" : "col-span-1"} h-11 rounded-xl border border-[#FDA29B] bg-[#FEF3F2] px-2 text-[11px] font-black text-[#B42318] shadow-sm transition active:scale-95 sm:h-12`}
                >
                  Clear
                </button>

                <button
                  type="button"
                  onPointerDown={(event) => event.preventDefault()}
                  onClick={onEnter}
                  className={`${numeric ? "col-span-2" : "col-span-2"} flex h-11 items-center justify-center rounded-xl border border-[#069495] bg-[#069495] px-2 text-[11px] font-black text-white shadow-sm transition active:scale-95 sm:h-12`}
                >
                  <CornerDownLeft className="mr-1 h-4 w-4" />
                  Enter
                </button>
              </div>
            </div>
          </div>
        </div>

        {minimized ? (
          <button
            type="button"
            onPointerDown={(event) => event.preventDefault()}
            onClick={() => setMinimized(false)}
            className="flex h-12 w-full items-center justify-center gap-2 bg-[#F6F8FA] text-[12px] font-black uppercase text-[#003636]"
          >
            <Keyboard className="h-4 w-4 text-[#069495]" />
            Tap to expand keyboard
          </button>
        ) : null}
      </div>
    </div>
  );
}
