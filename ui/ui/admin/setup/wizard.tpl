{include file="sections/header.tpl"}
<div class="row"><div class="col-md-10 col-md-offset-1">
<form method="post" enctype="multipart/form-data" action="{Text::url('setup/save')}">
<input type="hidden" name="csrf_token" value="{$csrf_token}">
<div class="callout callout-info"><h4>First-run Setup</h4><p>Complete these settings once. The setup wizard locks after saving; everything remains editable from Admin Settings.</p></div>

<div class="box box-primary"><div class="box-header"><h3 class="box-title">1. Company</h3></div><div class="box-body">
<div class="form-group"><label>ISP / Company name</label><input class="form-control" name="CompanyName" value="{$_c.CompanyName|default:''|escape}" required></div>
<div class="row"><div class="col-md-6"><div class="form-group"><label>Phone</label><input class="form-control" name="phone" value="{$_c.phone|default:''|escape}"></div></div><div class="col-md-6"><div class="form-group"><label>Currency</label><input class="form-control" name="currency_code" value="{$_c.currency_code|default:'৳'|escape}"></div></div></div>
<div class="form-group"><label>Address</label><textarea class="form-control" name="address">{$_c.address|default:''|escape}</textarea></div>
<div class="row"><div class="col-md-6"><div class="form-group"><label>Timezone</label><select class="form-control" name="timezone">{foreach $timezones as $tz}<option value="{$tz|escape}" {if $tz eq 'Asia/Dhaka'}selected{/if}>{$tz|escape}</option>{/foreach}</select></div></div><div class="col-md-6"><div class="form-group"><label>Logo (PNG/JPEG)</label><input class="form-control" type="file" name="logo" accept="image/png,image/jpeg"></div></div></div>
</div></div>

<div class="box"><div class="box-header"><h3 class="box-title">2. Public access</h3></div><div class="box-body">
<div class="alert alert-info">Caddy automatically provisions HTTPS when the installer is given a domain in <code>PANEL_SITE_ADDRESS</code>. These fields record the intended public endpoint.</div>
<div class="row"><div class="col-md-6"><div class="form-group"><label>Domain / public IP</label><input class="form-control" name="public_host" value="{$current_host|escape}"></div></div><div class="col-md-3"><div class="form-group"><label>Scheme</label><select class="form-control" name="public_scheme"><option value="https" {if $current_scheme eq 'https'}selected{/if}>HTTPS</option><option value="http" {if $current_scheme eq 'http'}selected{/if}>HTTP</option></select></div></div><div class="col-md-3"><div class="form-group"><label>External port</label><input class="form-control" name="public_port" value="{if $current_scheme eq 'https'}443{else}80{/if}"></div></div></div>
</div></div>

<div class="box"><div class="box-header"><h3 class="box-title">3. Admin</h3></div><div class="box-body">
<div class="row"><div class="col-md-6"><div class="form-group"><label>Name</label><input class="form-control" name="admin_name" value="{$setup_admin.fullname|escape}"></div></div><div class="col-md-6"><div class="form-group"><label>Username</label><input class="form-control" name="admin_username" value="{$setup_admin.username|escape}"></div></div></div>
<div class="row"><div class="col-md-4"><div class="form-group"><label>Recovery email</label><input class="form-control" type="email" name="admin_email" value="{$setup_admin.email|default:''|escape}"></div></div><div class="col-md-4"><div class="form-group"><label>Recovery phone</label><input class="form-control" name="admin_phone" value="{$setup_admin.phone|default:''|escape}"></div></div><div class="col-md-4"><div class="form-group"><label>New password (optional)</label><input class="form-control" type="password" name="admin_password" minlength="10"></div></div></div>
</div></div>

<div class="box"><div class="box-header"><h3 class="box-title">4. Billing defaults</h3></div><div class="box-body">
<div class="row"><div class="col-md-3"><div class="form-group"><label>Billing cycle</label><select class="form-control" name="billing_cycle"><option value="monthly">Monthly</option><option value="30days">30 days</option></select></div></div><div class="col-md-3"><div class="form-group"><label>Grace period (days)</label><input class="form-control" type="number" min="0" name="grace_period_days" value="0"></div></div><div class="col-md-3"><div class="form-group"><label>Expiry behavior</label><select class="form-control" name="customer_expiry_behavior"><option value="disable">Disable service</option><option value="redirect">Redirect</option></select></div></div><div class="col-md-3"><div class="form-group"><label>Invoice prefix</label><input class="form-control" name="invoice_prefix" value="INV"></div></div></div>
<input type="hidden" name="language" value="english">
</div></div>

<div class="box"><div class="box-header"><h3 class="box-title">5. Network mode</h3></div><div class="box-body">
<div class="form-group"><select class="form-control" name="network_mode"><option value="hybrid">Hybrid: MikroTik + FreeRADIUS</option><option value="radius">FreeRADIUS</option><option value="mikrotik">MikroTik direct</option><option value="demo">Demo / local</option></select></div>
<p class="help-block">Router credentials and interface choices are configured after this wizard from Network → MikroTik Onboarding, where a preview is shown before applying changes.</p>
</div></div>

<div class="box"><div class="box-header"><h3 class="box-title">6. WhatsApp</h3></div><div class="box-body"><select class="form-control" name="whatsapp_setup"><option value="now">Connect now after saving (recommended)</option><option value="later">Configure later</option></select><p class="help-block">QR pairing is shown inside the panel; the standalone WhatsApp dashboard is not public.</p></div></div>

<div class="box"><div class="box-header"><h3 class="box-title">7. Mobile app</h3></div><div class="box-body">
<div class="row"><div class="col-md-6"><div class="form-group"><label>GitHub repository</label><input class="form-control" name="mobile_repo" value="mahin-wpdev/jm-broadband-android"></div></div><div class="col-md-3"><div class="form-group"><label>Release channel</label><select class="form-control" name="mobile_release_channel"><option value="stable">Stable</option><option value="beta">Beta</option></select></div></div><div class="col-md-3"><div class="form-group"><label>Default mandatory update</label><select class="form-control" name="mobile_required_update_default"><option value="0">No</option><option value="1">Yes</option></select></div></div></div>
</div></div>

<button class="btn btn-success btn-lg btn-block" type="submit"><i class="fa fa-check"></i> Save &amp; Lock Setup</button>
</form></div></div>
{include file="sections/footer.tpl"}
