{include file="sections/header.tpl"}
<style>{literal}
.settlements-ui{max-width:1400px;margin:0 auto;color:#334155}
.settlement-intro{display:flex;align-items:center;justify-content:space-between;gap:20px;margin:8px 0 24px}
.settlement-intro h2{font-size:25px;font-weight:700;margin:0 0 8px;color:#172b4d}
.settlement-intro p{margin:0;color:#718096}
.settlement-total{background:#ede9fe;border:1px solid #ddd6fe;border-radius:14px;padding:16px 24px;min-width:210px}
.settlement-total small{display:block;color:#6d5ba1;margin-bottom:5px}
.settlement-total strong{font-size:26px;color:#4c1d95}
.settlements-ui .box{border:1px solid #e4e9f2;border-radius:16px;box-shadow:0 5px 20px rgba(23,43,77,.04);margin-bottom:24px;overflow:hidden;background:#fff}
.settlements-ui .box-header{padding:20px 24px;border-bottom:1px solid #edf1f7;background:#fbfcff}
.settlements-ui .box-title{font-weight:600;color:#243b53;font-size:17px}
.settlements-ui .box-body{padding:24px}
.settlement-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px;margin-bottom:20px}
.settlement-fields>input[type="hidden"]{display:none}.settlement-fields>[class*="col-md"]{width:auto;float:none;padding:0;min-width:0}
.settlements-ui label{font-weight:600;font-size:12px;color:#52657a;display:block;margin:0 0 8px}
.settlements-ui .form-control{height:44px;border:1px solid #dbe3ee;border-radius:9px;box-shadow:none;background:#fff;font-size:14px;padding:10px 12px}
.settlements-ui .form-control:focus{border-color:#8b5cf6;box-shadow:0 0 0 3px rgba(139,92,246,.1)}
.settlements-ui .form-control:disabled{background:#f1f5f9}
.settlements-ui .btn{min-height:44px;border-radius:9px;padding:10px 20px;font-weight:600;border:0}
.settlements-ui .btn-warning{background:#0f766e;color:#fff;width:100%}
.settlements-ui .btn-primary{background:#6d28d9;color:#fff}
.settlements-ui .box-body>p{font-size:13px;color:#718096;margin-bottom:22px;line-height:1.7}
.settlement-tag{display:inline-block;padding:5px 10px;border-radius:20px;font-size:12px;font-weight:600;background:#e8f0fe;color:#2563eb;white-space:nowrap}
.settlement-tag.tag-add{background:#dcfce7;color:#15803d}.settlement-tag.tag-remove{background:#fee2e2;color:#b91c1c}.settlement-tag.tag-reverse{background:#ede9fe;color:#6d28d9}
.settlement-tag.tag-profit_earned{background:#dcfce7;color:#15803d}.settlement-tag.tag-debt_repayment{background:#e8f0fe;color:#2563eb}
.settlement-history .table{margin:0;min-width:720px}
.settlement-history th{background:#f8fafc;color:#718096;font-size:11px;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid #e5eaf2!important;padding:14px!important}
.settlement-history td{padding:16px 14px!important;border-color:#eef2f7!important;vertical-align:middle!important}
.settlement-history small{color:#94a3b8}.settlement-amount{font-weight:700;white-space:nowrap}
@media(min-width:1100px){.settlement-fields{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:600px){.settlement-intro{display:block}.settlement-total{margin-top:18px}.settlement-fields{grid-template-columns:1fr;gap:16px}.settlements-ui .box-body,.settlements-ui .box-header{padding:18px}.settlements-ui .btn{width:100%}.settlement-intro h2{font-size:22px}}

.settlements-ui .settlement-fields{display:grid!important;gap:24px!important;align-items:start!important}
.settlements-ui .settlement-fields>div{float:none!important;width:auto!important;padding:0!important;margin:0!important;min-width:0}
.settlements-ui label{position:static!important;line-height:20px!important;margin:0 0 8px!important}
.settlements-ui .form-control{height:46px!important;min-height:46px!important;padding:10px 12px!important;line-height:24px!important;margin:0!important}
.settlements-ui .settlement-fields>div[style*="display: none"]{display:none!important}
{/literal}</style>
<div class="settlements-ui">
<div class="settlement-intro"><div><h2>Profit &amp; Settlements</h2><p>Record payments, adjust reseller balances and review every change.</p></div><div class="settlement-total"><small>Total available profit</small><strong>{Lang::moneyFormat($dash_profit_due)}</strong></div></div>
<div class="box box-warning settlement-card"><div class="box-header"><h3 class="box-title">Manage Profit &amp; Settlement</h3></div><div class="box-body"><p>Choose an action and reseller. Add increases available profit; Remove reduces it. Reverse returns an earlier settlement to the balance.</p><form id="settlement-form" method="post" action="{Text::url('reseller/balance-change')}"><input type="hidden" name="csrf_token" value="{$csrf_token}"><input type="hidden" name="operation_key" value="{$operation_key}"><div class="settlement-fields"><div class="col-md-3"><label>Reseller</label><select class="form-control" id="balance-reseller" name="reseller_id" required>{foreach $resellers as $reseller}<option data-balance="{$reseller['profit_share']}" value="{$reseller['id']}">{$reseller['name']|escape} — Available: {Lang::moneyFormat($reseller['profit_share'])} | Owes me: {Lang::moneyFormat($reseller['isp_debt'])}</option>{/foreach}</select></div><div class="col-md-3"><label>Action</label><select class="form-control" name="operation" id="balance-operation"><option value="debt">Add My Receivable</option><option value="payment">Record Settlement</option><option value="add">Add Profit</option><option value="remove">Remove Profit</option><option value="reverse">Reverse Settlement</option></select></div><div class="col-md-3"><label>Amount (BDT)</label><small id="balance-limit" style="display:block;margin-bottom:8px;color:#64748b"></small><input class="form-control" name="amount" id="balance-amount" type="text" inputmode="decimal" autocomplete="off" maxlength="20" placeholder="e.g. 300.00" required></div><div class="col-md-3"><label>Original settlement (Reverse only)</label><select class="form-control" name="settlement_id" id="balance-original" disabled><option value="">Select settlement</option>{foreach $reversal_options as $entry}<option data-reseller="{$entry['reseller_id']}" value="{$entry['id']}">#{$entry['id']} - Reseller #{$entry['reseller_id']} - {Lang::moneyFormat($entry['amount'])} - {$entry['created_at']|escape}</option>{/foreach}</select></div><div id="payment-method-field"><label>Payment method</label><select class="form-control" id="balance-method" name="payment_method" required><option value="Cash">Cash</option><option value="bKash">bKash</option><option value="Nagad">Nagad</option><option value="Bank">Bank</option><option value="Other">Other</option></select></div><div id="payment-reference-field"><label>Reference (optional)</label><input class="form-control" name="reference" maxlength="100" placeholder="Transaction reference"></div></div><label id="balance-note-label">Note (optional)</label><input class="form-control" name="note" id="balance-note" maxlength="1000" placeholder="Why are you changing the balance?"><button id="balance-submit" class="btn btn-primary" style="margin-top:16px">Record Settlement</button></form></div></div>
<div class="box box-info settlement-history"><div class="box-header"><h3 class="box-title">Profit &amp; Settlement History</h3><small>Profit earned adds to available balance; payouts and debt recovery reduce it.</small></div><div class="box-body table-responsive"><table class="table"><thead><tr><th>ID / Date</th><th>Reseller</th><th>Action</th><th>Balance Change</th><th>Reason / Reference</th><th>Admin / Source</th></tr></thead><tbody>{foreach $balance_history as $entry}<tr><td>{$entry['history_id']|escape} / {$entry['created_at']|escape}</td><td>{foreach $resellers as $r}{if $r['id']==$entry['reseller_id']}<strong>{$r['name']|escape}</strong><br><small>{$r['username']|escape}</small>{/if}{/foreach}</td><td><span class="settlement-tag tag-{$entry['entry_type']|escape}">{if $entry['entry_type']=='profit_earned'}Profit earned{elseif $entry['entry_type']=='add'}Profit added{elseif $entry['entry_type']=='remove'}Profit removed{elseif $entry['entry_type']=='reverse'}Settlement reversed{elseif $entry['entry_type']=='debt_repayment'}Debt recovered{else}Payment{/if}</span>{if $entry['reversal_of']} of #{$entry['reversal_of']}{/if}</td><td class="settlement-amount {if $entry['balance_change']>0}text-success{elseif $entry['balance_change']<0}text-danger{else}text-muted{/if}">{if $entry['balance_change']>0}+{/if}{Lang::moneyFormat($entry['balance_change_abs'])}</td><td>{$entry['note']|escape}{if $entry['note'] && $entry['reference']} — {/if}{$entry['reference']|escape}</td><td>{$entry['history_admin']|escape}</td></tr>{foreachelse}<tr><td colspan="6">No changes yet.</td></tr>{/foreach}</tbody></table></div></div>

</div><script>{literal}(function(){
var op=document.getElementById('balance-operation'),amount=document.getElementById('balance-amount'),original=document.getElementById('balance-original'),reseller=document.getElementById('balance-reseller'),method=document.getElementById('balance-method'),note=document.getElementById('balance-note'),submit=document.getElementById('balance-submit');if(!op)return;
function normalizeAmount(value){return value.replace(/[০-৯]/g,function(d){return String('০১২৩৪৫৬৭৮৯'.indexOf(d));}).trim();}
function update(){var reverse=op.value==='reverse',payment=op.value==='payment';
var available=Number(reseller.options[reseller.selectedIndex].getAttribute('data-balance'))||0;amount.max=(op.value==='add'||op.value==='debt')?'99999999.99':String(available);amount.setCustomValidity('');document.getElementById('balance-limit').textContent=op.value==='debt'?'Enter the amount the reseller owes you':op.value==='add'?'Enter the profit to add':'Available: Tk. '+available.toFixed(2);

amount.parentNode.style.display=reverse?'none':'block';amount.disabled=reverse;amount.required=!reverse;
original.parentNode.style.display=reverse?'block':'none';original.disabled=!reverse;original.required=reverse;
document.getElementById('payment-method-field').style.display=payment?'block':'none';document.getElementById('payment-reference-field').style.display=payment?'block':'none';method.disabled=!payment;method.required=payment;
note.required=!payment;document.getElementById('balance-note-label').textContent=payment?'Note (optional)':'Reason';note.placeholder=payment?'Payment details':'Why are you changing the balance?';
submit.textContent={debt:'Add My Receivable',payment:'Record Settlement',add:'Add Profit',remove:'Remove Profit',reverse:'Reverse Settlement'}[op.value];
for(var i=1;i<original.options.length;i++){var option=original.options[i],match=option.getAttribute('data-reseller')===reseller.value;option.hidden=!match;option.disabled=!match;}
if(original.selectedIndex>0&&original.options[original.selectedIndex].disabled)original.value='';
}op.addEventListener('change',update);reseller.addEventListener('change',update);amount.addEventListener('input',function(){amount.setCustomValidity('');if(amount.value&&Number(normalizeAmount(amount.value))>Number(amount.max))amount.setCustomValidity('Amount cannot exceed Tk. '+amount.max);});update();
document.getElementById('settlement-form').addEventListener('submit',function(event){if(op.value!=='reverse'){amount.value=normalizeAmount(amount.value);if(!/^\d{1,8}(\.\d{1,2})?$/.test(amount.value)||Number(amount.value)<=0){event.preventDefault();amount.setCustomValidity('Enter a positive amount, e.g. 300 or 300.50');amount.reportValidity();return;}}});
}());{/literal}</script>
<div class="settlements-ui"><div class="box settlement-history"><div class="box-header"><h3 class="box-title">My Receivable History</h3></div><div class="box-body table-responsive"><table class="table"><thead><tr><th>Date</th><th>Reseller</th><th>Action</th><th>Amount</th><th>Recharge / Reason</th></tr></thead><tbody>{foreach $debt_history as $d}<tr><td>{$d['created_at']|escape}</td><td>{foreach $resellers as $r}{if $r['id']==$d['reseller_id']}{$r['name']|escape}{/if}{/foreach}</td><td>{if $d['entry_type']=='debt'}Receivable added{elseif $d['recharge_id']}Recovered from profit{else}Receivable adjusted{/if}</td><td>{Lang::moneyFormat($d['amount'])}</td><td>{if $d['recharge_id']}Recharge #{$d['recharge_id']} — {/if}{$d['note']|escape}</td></tr>{foreachelse}<tr><td colspan="5">No receivable recorded yet.</td></tr>{/foreach}</tbody></table></div></div></div>
{include file="sections/footer.tpl"}
