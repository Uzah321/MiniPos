import React, { useEffect, useState } from "react";
import { verifyManagerApproval } from "../services/ManagerApprovalService";

const getErrorMessage = (error, fallback) => {
  const data = error?.response?.data;
  if (data?.errors) return Object.values(data.errors).flat().join("\n");
  return data?.message || data?.error || error?.message || fallback;
};

export default function ManagerApprovalModal({
  open,
  onClose,
  onApproved,
  action = "manager_approval",
  permission = null,
  title = "Manager Approval Required",
  description = "A manager or supervisor must approve this action.",
  reason = "",
  referenceType = null,
  referenceId = null,
  metadata = {},
}) {
  const [form, setForm] = useState({ username: "", password: "", reason: reason || "" });
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    if (open) {
      setForm({ username: "", password: "", reason: reason || "" });
      setError("");
    }
  }, [open, reason]);

  if (!open) return null;

  const submit = async () => {
    try {
      setLoading(true);
      setError("");

      const res = await verifyManagerApproval({
        username: form.username,
        password: form.password,
        action,
        permission,
        reason: form.reason || reason,
        reference_type: referenceType,
        reference_id: referenceId,
        metadata,
      });

      onApproved?.(res?.data || res);
      setForm({ username: "", password: "", reason: "" });
      onClose?.();
    } catch (err) {
      setError(getErrorMessage(err, "Manager approval failed."));
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="fixed inset-0 z-[220] flex items-center justify-center bg-black/60 px-4">
      <div className="sx-large-modal-window border border-[#D7DFDF] bg-white shadow-2xl" role="dialog" aria-modal="true" aria-label={title}>
        <div className="border-b-2 border-[#00BCBC] bg-[#004142] px-4 py-3 text-white">
          <div className="text-[15px] font-black uppercase tracking-wide">{title}</div>
          <div className="mt-1 text-[11px] font-semibold text-white/70">{description}</div>
        </div>

        {error && (
          <div className="whitespace-pre-line border-b border-[#FDA29B] bg-[#FEF3F2] px-4 py-2 text-[12px] font-bold text-[#B42318]">
            {error}
          </div>
        )}

        <div className="min-h-0 flex-1 space-y-3 overflow-y-auto p-4">
          <label className="block">
            <span className="text-[11px] font-black uppercase text-[#637877]">Manager Username</span>
            <input value={form.username} onChange={(e) => setForm({ ...form, username: e.target.value })} autoFocus className="mt-1 h-10 w-full border border-[#ACB8B8] px-3 text-[13px] outline-none focus:border-[#069495]" />
          </label>

          <label className="block">
            <span className="text-[11px] font-black uppercase text-[#637877]">Manager Password</span>
            <input type="password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} className="mt-1 h-10 w-full border border-[#ACB8B8] px-3 text-[13px] outline-none focus:border-[#069495]" />
          </label>

          <label className="block">
            <span className="text-[11px] font-black uppercase text-[#637877]">Approval Reason</span>
            <textarea rows={3} value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })} className="mt-1 w-full border border-[#ACB8B8] px-3 py-2 text-[13px] outline-none focus:border-[#069495]" />
          </label>
        </div>

        <div className="flex justify-end gap-2 border-t border-[#D7DFDF] bg-[#F6F8FA] px-4 py-3">
          <button type="button" onClick={onClose} disabled={loading} className="h-9 border border-[#ACB8B8] bg-white px-4 text-[12px] font-black uppercase text-[#003636] disabled:opacity-60">Cancel</button>
          <button type="button" onClick={submit} disabled={loading || !form.username || !form.password} className="h-9 border border-[#069495] bg-[#069495] px-4 text-[12px] font-black uppercase text-white disabled:opacity-60">{loading ? "Verifying..." : "Approve"}</button>
        </div>
      </div>
    </div>
  );
}
