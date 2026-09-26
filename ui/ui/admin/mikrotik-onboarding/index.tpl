{include file="sections/header.tpl"}
<div class="row"><div class="col-md-12">
{if $onboarding_error}<div class="alert alert-danger"><strong>Onboarding error:</strong> {$onboarding_error|escape}</div>{/if}
<div class="box box-primary">
<div class="box-header"><h3 class="box-title"><i class="fa fa-microchip"></i> MikroTik Safe Onboarding</h3></div>
<form method="post" action="{Text::url('mikrotik-onboarding/preview')}"><input type="hidden" name="csrf_token" value="{$csrf_token}">
<div class="box-body">
<div class="alert alert-info">Nothing is changed until you review the generated script and press Apply. API mode creates a RouterOS backup/export first; managed changes include rollback commands.</div>
<div class="row">
<div class="col-md-4"><label>Router IP / hostname</label><input class="form-control" name="host" value="{$form.host|default:''|escape}" required></div>
<div class="col-md-2"><label>API port</label><input class="form-control" type="number" name="api_port" value="{$form.api_port|default:8728}" required></div>
<div class="col-md-3"><label>Setup username</label><input class="form-control" name="setup_user" value="{$form.setup_user|default:'admin'|escape}"></div>
<div class="col-md-3"><label>Setup password</label><input class="form-control" type="password" name="setup_password"></div>
</div><br>
<div class="row">
<div class="col-md-3"><label>Mode</label><select class="form-control" name="mode"><option value="hybrid">Hybrid PPPoE + Hotspot</option><option value="pppoe">PPPoE</option><option value="hotspot">Hotspot</option></select></div>
<div class="col-md-3"><label>WAN interface</label><input class="form-control" name="wan_interface" value="{$form.wan_interface|default:''|escape}" required></div>
<div class="col-md-3"><label>LAN interface</label><input class="form-control" name="lan_interface" value="{$form.lan_interface|default:''|escape}" required></div>
<div class="col-md-3"><label>Interim update</label><input class="form-control" name="interim_update" value="{$form.interim_update|default:'5m'|escape}"></div>
</div><br>
<div class="row">
<div class="col-md-4"><label>RADIUS server IP</label><input class="form-control" name="radius_server" value="{$form.radius_server|default:''|escape}" required></div>
<div class="col-md-4"><label>Panel server IP</label><input class="form-control" name="panel_server" value="{$form.panel_server|default:''|escape}" required></div>
<div class="col-md-4"><label>Dedicated API username</label><input class="form-control" name="panel_api_user" value="{$form.panel_api_user|default:'jm-panel-api'|escape}"></div>
</div><br>
<div class="row">
<div class="col-md-4"><label>PPPoE profile name</label><input class="form-control" name="pppoe_profile" value="{$form.pppoe_profile|default:'jm-panel-radius'|escape}"></div>
<div class="col-md-4"><label>Existing IP pool name (optional)</label><input class="form-control" name="pppoe_pool" value="{$form.pppoe_pool|default:''|escape}"></div>
<div class="col-md-4"><label style="margin-top:28px"><input type="checkbox" name="api_ssl" value="1"> Use API-SSL</label></div>
</div>
</div>
<div class="box-footer"><button class="btn btn-primary"><i class="fa fa-eye"></i> Test Connection &amp; Generate Preview</button></div>
</form></div>

{if $preview}
<div class="box box-success">
<div class="box-header"><h3 class="box-title">Configuration Preview</h3></div>
<div class="box-body">
{if $probe}<div class="alert alert-success">Connected: <strong>{$probe.identity|escape}</strong> — RouterOS {$probe.version|escape} — {$probe.board|escape}</div>{/if}
<p>The same script can be copied into MikroTik Terminal as the fallback onboarding method.</p>
<textarea class="form-control" rows="20" readonly>{$preview|escape}</textarea>
<h4>Rollback script</h4><textarea class="form-control" rows="8" readonly>{$rollback|escape}</textarea>
</div>
<div class="box-footer"><form method="post" action="{Text::url('mikrotik-onboarding/apply')}"><input type="hidden" name="csrf_token" value="{$csrf_token}"><div class="input-group"><input class="form-control" name="router_name" placeholder="Router name in panel" required><span class="input-group-btn"><button class="btn btn-danger" onclick="return confirm('Apply the reviewed configuration to this MikroTik?')"><i class="fa fa-play"></i> Apply Reviewed Configuration</button></span></div></form></div>
</div>
{/if}
</div></div>
{include file="sections/footer.tpl"}
