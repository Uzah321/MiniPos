import React, { useCallback, useEffect, useRef, useState } from "react";
import EnterpriseConfirmModal from "./EnterpriseConfirmModal";

let openConfirmation = null;

export const requestEnterpriseConfirmation = (options) => new Promise((resolve) => {
  if (!openConfirmation) {
    resolve(false);
    return;
  }

  openConfirmation(
    typeof options === "string" ? { message: options } : (options || {}),
    resolve,
  );
});

export default function EnterpriseConfirmHost() {
  const [request, setRequest] = useState(null);
  const requestRef = useRef(null);

  const settle = useCallback((confirmed) => {
    const current = requestRef.current;
    requestRef.current = null;
    setRequest(null);
    current?.resolve(Boolean(confirmed));
  }, []);

  useEffect(() => {
    const handleRequest = (options, resolve) => {
      requestRef.current?.resolve(false);
      const next = { options, resolve };
      requestRef.current = next;
      setRequest(next);
    };

    openConfirmation = handleRequest;

    return () => {
      if (openConfirmation === handleRequest) openConfirmation = null;
      requestRef.current?.resolve(false);
      requestRef.current = null;
    };
  }, []);

  const options = request?.options || {};

  return (
    <EnterpriseConfirmModal
      open={Boolean(request)}
      title={options.title || "Confirm Action"}
      message={options.message || "Continue with this action?"}
      confirmLabel={options.confirmLabel || "Confirm"}
      cancelLabel={options.cancelLabel || "Cancel"}
      danger={options.danger ?? true}
      onClose={() => settle(false)}
      onConfirm={() => settle(true)}
    />
  );
}
