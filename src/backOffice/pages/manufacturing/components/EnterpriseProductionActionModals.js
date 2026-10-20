import React, { useMemo, useState } from 'react';
import { Calculator, CheckCircle2, RotateCcw, Trash2, X } from 'lucide-react';
import { completeProductionOrder, addProductionOverhead, returnProductionMaterial, recordProductionWastage } from '../../../../services/ManufacturingService';
import { LoadingPill, Notice, moneyNumber, numberValue, qtyNumber, useNotice } from '../ManufacturingShared';

const DEFAULT_CURRENCY = 'USD';

export function EnterpriseAddCostModal({ open, onClose, order, onSaved }) {
  const { notice, showNotice } = useNotice();
  const [loading, setLoading] = useState(false);
  const [form, setForm] = useState({
    cost_type: 'labour',
    accounting_class: 'DIRECT_LABOUR',
    description: '',
    staff_name: '',
    staff_id: '',
    costing_method: 'hourly_rate',
    quantity: 1,
    rate: 0,
    worked_hours: 1,
    hourly_rate: 0,
    monthly_salary: 0,
    working_days_in_month: 26,
    working_hours_per_day: 8,
    production_hours: 1,
    allocation_basis: 'hours',
    allocation_percent: 0,
    amount: 0,
    currency: order?.currency || DEFAULT_CURRENCY,
    notes: '',
  });

  const calc = useMemo(() => calculateFlexibleCost(form), [form]);

  const save = async () => {
    try {
      setLoading(true);
      const payload = { ...form, amount: calc.amount, metadata: calc.metadata };
      const res = await addProductionOverhead(order.id, payload);
      showNotice(res?.message || 'Production cost captured.', 'success');
      onSaved?.(res);
      onClose?.();
    } catch (error) {
      showNotice(error?.response?.data?.message || error?.message || 'Failed to add production cost.', 'error');
    } finally {
      setLoading(false);
    }
  };

  if (!open) return null;
  return (
    <EnterpriseDialog title="ADD PRODUCTION COST" subtitle="Direct labour, salary allocation, machine rate or indirect overhead" onClose={onClose}>
      <Notice notice={notice} />
      <LoadingPill loading={loading} />
      <div className="grid gap-3 lg:grid-cols-[1.2fr_0.8fr]">
        <div className="grid grid-cols-3 gap-2">
          <Select label="Cost Type" value={form.cost_type} onChange={(v) => setForm({ ...form, cost_type: v })} options={[['labour','Labour'],['salary','Monthly Salary'],['electricity','Electricity'],['machine','Machine'],['rent','Factory Rent'],['maintenance','Maintenance'],['other','Other']]} />
          <Select label="Accounting Class" value={form.accounting_class} onChange={(v) => setForm({ ...form, accounting_class: v })} options={[['DIRECT_LABOUR','Direct Labour'],['INDIRECT_OVERHEAD','Indirect Overhead'],['DIRECT_EXPENSE','Direct Expense']]} />
          <Select label="Costing Method" value={form.costing_method} onChange={(v) => setForm({ ...form, costing_method: v })} options={[['hourly_rate','Hours x Rate'],['quantity_rate','Quantity x Rate'],['monthly_salary_allocation','Monthly Salary Allocation'],['percentage_allocation','Percentage Allocation'],['manual_amount','Manual Amount']]} />
          <Input label="Staff Name / Description" value={form.staff_name} onChange={(v) => setForm({ ...form, staff_name: v, description: v })} />
          <Input label="Staff ID" value={form.staff_id} onChange={(v) => setForm({ ...form, staff_id: v })} />
          <Input label="Currency" value={form.currency} onChange={(v) => setForm({ ...form, currency: v })} />

          {form.costing_method === 'hourly_rate' && <>
            <Input label="Worked Hours" type="number" value={form.worked_hours} onChange={(v) => setForm({ ...form, worked_hours: v })} />
            <Input label="Hourly Rate" type="number" value={form.hourly_rate} onChange={(v) => setForm({ ...form, hourly_rate: v })} />
          </>}

          {form.costing_method === 'quantity_rate' && <>
            <Input label="Quantity" type="number" value={form.quantity} onChange={(v) => setForm({ ...form, quantity: v })} />
            <Input label="Rate" type="number" value={form.rate} onChange={(v) => setForm({ ...form, rate: v })} />
          </>}

          {form.costing_method === 'monthly_salary_allocation' && <>
            <Input label="Monthly Salary" type="number" value={form.monthly_salary} onChange={(v) => setForm({ ...form, monthly_salary: v })} />
            <Input label="Working Days / Month" type="number" value={form.working_days_in_month} onChange={(v) => setForm({ ...form, working_days_in_month: v })} />
            <Input label="Hours / Day" type="number" value={form.working_hours_per_day} onChange={(v) => setForm({ ...form, working_hours_per_day: v })} />
            <Input label="Production Hours" type="number" value={form.production_hours} onChange={(v) => setForm({ ...form, production_hours: v })} />
          </>}

          {form.costing_method === 'percentage_allocation' && <>
            <Input label="Base Monthly Cost" type="number" value={form.monthly_salary} onChange={(v) => setForm({ ...form, monthly_salary: v })} />
            <Input label="Allocation %" type="number" value={form.allocation_percent} onChange={(v) => setForm({ ...form, allocation_percent: v })} />
          </>}

          {form.costing_method === 'manual_amount' && <Input label="Manual Amount" type="number" value={form.amount} onChange={(v) => setForm({ ...form, amount: v })} />}

          <div className="col-span-3">
            <Textarea label="Notes" value={form.notes} onChange={(v) => setForm({ ...form, notes: v })} />
          </div>
        </div>

        <CalculationPanel title="Cost Calculation" rows={calc.explain} total={`${form.currency} ${moneyNumber(calc.amount)}`} />
      </div>
      <DialogFooter onCancel={onClose} onSave={save} saveLabel="Save Cost" />
    </EnterpriseDialog>
  );
}

export function EnterpriseCompleteOutputModal({ open, onClose, order, onSaved }) {
  const { notice, showNotice } = useNotice();
  const [loading, setLoading] = useState(false);
  const [form, setForm] = useState({ actual_good_quantity: order?.actual_good_quantity || order?.planned_output_quantity || 0, actual_reject_quantity: 0, actual_waste_quantity: 0, notes: '', currency: order?.currency || DEFAULT_CURRENCY });
  const totalOutput = numberValue(form.actual_good_quantity) + numberValue(form.actual_reject_quantity) + numberValue(form.actual_waste_quantity);
  const goodPercent = totalOutput > 0 ? (numberValue(form.actual_good_quantity) / totalOutput) * 100 : 0;
  const estUnitCost = numberValue(order?.actual_total_cost || order?.planned_total_cost) / Math.max(numberValue(form.actual_good_quantity), 1);

  const save = async () => {
    try {
      setLoading(true);
      const res = await completeProductionOrder(order.id, form);
      showNotice(res?.message || 'Production output completed.', 'success');
      onSaved?.(res);
      onClose?.();
    } catch (error) {
      showNotice(error?.response?.data?.message || error?.message || 'Failed to complete output.', 'error');
    } finally { setLoading(false); }
  };

  if (!open) return null;
  return <EnterpriseDialog title="COMPLETE PRODUCTION OUTPUT" subtitle="Record good output, rejects, waste and estimated finished-goods value" onClose={onClose}>
    <Notice notice={notice} /><LoadingPill loading={loading} />
    <div className="grid gap-3 lg:grid-cols-[1fr_0.9fr]">
      <div className="grid grid-cols-3 gap-2">
        <Input label="Good Quantity" type="number" value={form.actual_good_quantity} onChange={(v) => setForm({ ...form, actual_good_quantity: v })} />
        <Input label="Rejected Quantity" type="number" value={form.actual_reject_quantity} onChange={(v) => setForm({ ...form, actual_reject_quantity: v })} />
        <Input label="Wasted Quantity" type="number" value={form.actual_waste_quantity} onChange={(v) => setForm({ ...form, actual_waste_quantity: v })} />
        <div className="col-span-3"><Textarea label="Completion Notes" value={form.notes} onChange={(v) => setForm({ ...form, notes: v })} /></div>
      </div>
      <CalculationPanel title="Output Impact" rows={[['Planned Qty', qtyNumber(order?.planned_output_quantity || order?.planned_quantity)], ['Total Output Captured', qtyNumber(totalOutput)], ['Good Yield', `${goodPercent.toFixed(2)}%`], ['Estimated Unit Cost', `${form.currency} ${moneyNumber(estUnitCost)}`], ['Estimated FG Value', `${form.currency} ${moneyNumber(estUnitCost * numberValue(form.actual_good_quantity))}`]]} total={`${goodPercent.toFixed(2)}% yield`} />
    </div>
    <DialogFooter onCancel={onClose} onSave={save} saveLabel="Complete Production" />
  </EnterpriseDialog>;
}

export function EnterpriseReturnMaterialModal({ open, onClose, order, materials = [], onSaved }) {
  const { notice, showNotice } = useNotice();
  const [loading, setLoading] = useState(false);
  const [form, setForm] = useState({ material_line_id: '', quantity: 0, reason: '', notes: '' });
  const material = materials.find((m) => String(m.id || m.line_id || m.production_order_material_id) === String(form.material_line_id));
  const unitCost = numberValue(material?.unit_cost);
  const value = unitCost * numberValue(form.quantity);
  const save = async () => { try { setLoading(true); const res = await returnProductionMaterial(order.id, form); showNotice(res?.message || 'Material return recorded.', 'success'); onSaved?.(res); onClose?.(); } catch (error) { showNotice(error?.response?.data?.message || error?.message || 'Failed to return material.', 'error'); } finally { setLoading(false); } };
  if (!open) return null;
  return <EnterpriseDialog title="RETURN MATERIAL TO STORES" subtitle="Return unused material and reduce net production consumption" onClose={onClose}>
    <Notice notice={notice} /><LoadingPill loading={loading} />
    <div className="grid gap-3 lg:grid-cols-[1fr_0.9fr]">
      <div className="grid grid-cols-2 gap-2">
        <Select label="Material" value={form.material_line_id} onChange={(v) => setForm({ ...form, material_line_id: v })} options={[['','Select material'], ...materials.map((m) => [m.id || m.line_id || m.production_order_material_id, `${m.product?.name || m.material || m.product_name || 'Material'} - issued ${qtyNumber(m.issued_quantity || m.quantity_issued)}`])]} />
        <Input label="Return Quantity" type="number" value={form.quantity} onChange={(v) => setForm({ ...form, quantity: v })} />
        <Input label="Reason" value={form.reason} onChange={(v) => setForm({ ...form, reason: v })} />
        <Input label="Reference" value={form.notes} onChange={(v) => setForm({ ...form, notes: v })} />
      </div>
      <CalculationPanel title="Return Impact" rows={[['Material', material?.product?.name || material?.material || '-'], ['Unit Cost', moneyNumber(unitCost)], ['Return Qty', qtyNumber(form.quantity)], ['Inventory Value Returned', moneyNumber(value)]]} total={`Value ${moneyNumber(value)}`} />
    </div>
    <DialogFooter onCancel={onClose} onSave={save} saveLabel="Post Return" />
  </EnterpriseDialog>;
}

export function EnterpriseWastageModal({ open, onClose, order, materials = [], onSaved }) {
  const { notice, showNotice } = useNotice();
  const [loading, setLoading] = useState(false);
  const [form, setForm] = useState({ material_line_id: '', quantity: 0, wastage_type: 'process_loss', reason: '', notes: '' });
  const material = materials.find((m) => String(m.id || m.line_id || m.production_order_material_id) === String(form.material_line_id));
  const value = numberValue(material?.unit_cost) * numberValue(form.quantity);
  const save = async () => { try { setLoading(true); const res = await recordProductionWastage(order.id, form); showNotice(res?.message || 'Wastage recorded.', 'success'); onSaved?.(res); onClose?.(); } catch (error) { showNotice(error?.response?.data?.message || error?.message || 'Failed to record wastage.', 'error'); } finally { setLoading(false); } };
  if (!open) return null;
  return <EnterpriseDialog title="RECORD MATERIAL WASTAGE" subtitle="Capture process loss, damaged material, setup loss and cost impact" onClose={onClose}>
    <Notice notice={notice} /><LoadingPill loading={loading} />
    <div className="grid gap-3 lg:grid-cols-[1fr_0.9fr]">
      <div className="grid grid-cols-2 gap-2">
        <Select label="Material" value={form.material_line_id} onChange={(v) => setForm({ ...form, material_line_id: v })} options={[['','Select material'], ...materials.map((m) => [m.id || m.line_id || m.production_order_material_id, `${m.product?.name || m.material || m.product_name || 'Material'} - issued ${qtyNumber(m.issued_quantity || m.quantity_issued)}`])]} />
        <Input label="Waste Quantity" type="number" value={form.quantity} onChange={(v) => setForm({ ...form, quantity: v })} />
        <Select label="Wastage Type" value={form.wastage_type} onChange={(v) => setForm({ ...form, wastage_type: v })} options={[['process_loss','Process Loss'],['damage','Damage'],['setup_loss','Setup Loss'],['quality_reject','Quality Reject'],['other','Other']]} />
        <Input label="Reason" value={form.reason} onChange={(v) => setForm({ ...form, reason: v })} />
      </div>
      <CalculationPanel title="Wastage Impact" rows={[['Material', material?.product?.name || material?.material || '-'], ['Waste Qty', qtyNumber(form.quantity)], ['Unit Cost', moneyNumber(material?.unit_cost)], ['Cost Impact', moneyNumber(value)]]} total={`Loss ${moneyNumber(value)}`} danger />
    </div>
    <DialogFooter onCancel={onClose} onSave={save} saveLabel="Post Wastage" danger />
  </EnterpriseDialog>;
}

function calculateFlexibleCost(form) {
  const currency = form.currency || DEFAULT_CURRENCY;
  let amount = 0;
  const explain = [];
  const method = form.costing_method;
  if (method === 'hourly_rate') {
    amount = numberValue(form.worked_hours) * numberValue(form.hourly_rate);
    explain.push(['Formula', 'Worked hours x hourly rate'], ['Worked Hours', qtyNumber(form.worked_hours)], ['Hourly Rate', `${currency} ${moneyNumber(form.hourly_rate)}`]);
  } else if (method === 'quantity_rate') {
    amount = numberValue(form.quantity) * numberValue(form.rate);
    explain.push(['Formula', 'Quantity x rate'], ['Quantity', qtyNumber(form.quantity)], ['Rate', `${currency} ${moneyNumber(form.rate)}`]);
  } else if (method === 'monthly_salary_allocation') {
    const monthlyHours = Math.max(numberValue(form.working_days_in_month) * numberValue(form.working_hours_per_day), 1);
    const hourlyRate = numberValue(form.monthly_salary) / monthlyHours;
    amount = hourlyRate * numberValue(form.production_hours);
    explain.push(['Formula', 'Monthly salary / monthly work hours x production hours'], ['Monthly Hours', qtyNumber(monthlyHours)], ['Derived Hourly Rate', `${currency} ${moneyNumber(hourlyRate)}`], ['Production Hours', qtyNumber(form.production_hours)]);
  } else if (method === 'percentage_allocation') {
    amount = numberValue(form.monthly_salary) * (numberValue(form.allocation_percent) / 100);
    explain.push(['Formula', 'Base monthly cost x allocation %'], ['Base Monthly Cost', `${currency} ${moneyNumber(form.monthly_salary)}`], ['Allocation %', `${numberValue(form.allocation_percent).toFixed(2)}%`]);
  } else {
    amount = numberValue(form.amount);
    explain.push(['Formula', 'Manual amount'], ['Manual Amount', `${currency} ${moneyNumber(amount)}`]);
  }
  return { amount, explain, metadata: { costing_method: method, auto_calculated_amount: amount, explain } };
}

function EnterpriseDialog({ title, subtitle, onClose, children }) {
  return <div className="fixed inset-0 z-[9999] bg-black/40 p-6"><div className="mx-auto flex h-full max-w-[1280px] flex-col border border-[#627474] bg-white text-[#1C2C2B]"><div className="flex h-11 items-center justify-between border-b border-[#AAB5B5] bg-[#0E4D4D] px-3 text-white"><div><div className="text-[13px] font-semibold">{title}</div><div className="text-[10px] uppercase text-[#CFD6D6]">{subtitle}</div></div><button onClick={onClose} className="h-8 w-8 border border-white/30"><X className="mx-auto h-4 w-4" /></button></div><div className="min-h-0 flex-1 overflow-auto bg-[#F7F8FA] p-3">{children}</div></div><style>{`button,input,select,textarea{border-radius:0!important}`}</style></div>;
}
function Input({ label, type = 'text', value, onChange }) { return <label className="block"><div className="mb-1 text-[10px] font-semibold uppercase text-[#627474]">{label}</div><input type={type} value={value ?? ''} onChange={(e) => onChange(e.target.value)} className="h-8 w-full border border-[#AAB5B5] bg-white px-2 text-[11px] outline-none" /></label>; }
function Select({ label, value, onChange, options }) { return <label className="block"><div className="mb-1 text-[10px] font-semibold uppercase text-[#627474]">{label}</div><select value={value ?? ''} onChange={(e) => onChange(e.target.value)} className="h-8 w-full border border-[#AAB5B5] bg-white px-2 text-[11px] outline-none">{options.map(([v,l]) => <option key={v} value={v}>{l}</option>)}</select></label>; }
function Textarea({ label, value, onChange }) { return <label className="block"><div className="mb-1 text-[10px] font-semibold uppercase text-[#627474]">{label}</div><textarea value={value ?? ''} onChange={(e) => onChange(e.target.value)} className="h-20 w-full border border-[#AAB5B5] bg-white p-2 text-[11px] outline-none" /></label>; }
function CalculationPanel({ title, rows, total, danger = false }) { return <div className="border border-[#B9C3C3] bg-white"><div className="border-b border-[#CFD9D8] bg-[#F5F7FA] px-3 py-2 text-[12px] font-semibold text-[#0C1B1B]"><Calculator className="mr-1 inline h-4 w-4" /> {title}</div><div className="p-3">{rows.map(([k,v]) => <div key={k} className="flex justify-between border-b border-[#E9EDED] py-2 text-[11px]"><span className="font-medium text-[#627474]">{k}</span><span className="font-semibold text-[#004343]">{v}</span></div>)}<div className={`mt-3 border p-3 text-center text-[18px] font-semibold ${danger ? 'border-[#FDA29B] bg-[#FFFBFA] text-[#B42318]' : 'border-[#ABEFC6] bg-[#ECFDF3] text-[#027A48]'}`}>{total}</div></div></div>; }
function DialogFooter({ onCancel, onSave, saveLabel, danger = false }) { return <div className="mt-3 flex justify-end gap-2 border-t border-[#CFD9D8] pt-3"><button onClick={onCancel} className="h-9 border border-[#AAB5B5] bg-white px-4 text-[11px] font-semibold">Cancel</button><button onClick={onSave} className={`h-9 border px-4 text-[11px] font-semibold text-white ${danger ? 'border-[#B42318] bg-[#B42318]' : 'border-[#027A48] bg-[#027A48]'}`}>{danger ? <Trash2 className="mr-1 inline h-4 w-4" /> : <CheckCircle2 className="mr-1 inline h-4 w-4" />}{saveLabel}</button></div>; }
