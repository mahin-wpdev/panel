{include file="sections/header.tpl"}
{if $reseller_form}
<form class="form-horizontal" method="post" role="form" action="{Text::url('reseller/save')}">
    <input type="hidden" name="csrf_token" value="{$csrf_token}">
    <div class="row">
        <div class="col-sm-6 col-md-6">
            <div class="panel panel-primary panel-hovered panel-stacked mb30">
                <div class="panel-heading"><i class="fa fa-user"></i> Reseller Administrator Account</div>
                <div class="panel-body">
                    <p class="text-muted">A reseller always uses a native PHPNuxBill <b>Agent</b> account. Create a new Agent, or attach an existing one.</p>
                    <div class="form-group">
                        <label class="col-md-4 control-label">Account source</label>
                        <div class="col-md-8">
                            <select class="form-control" id="account_mode" name="account_mode" onchange="resellerAccountMode(this.value)">
                                <option value="new" {if $selected_agent}disabled{/if}>Create new Agent account</option>
                                <option value="existing" {if $selected_agent}selected{/if}>Use existing Agent account</option>
                            </select>
                        </div>
                    </div>
                    <div id="existing-agent"{if !$selected_agent} style="display:none"{/if}>
                        <div class="form-group">
                            <label class="col-md-4 control-label">Existing Agent</label>
                            <div class="col-md-8">
                                <select class="form-control" name="user_id">
                                    <option value="">Select Agent account</option>
                                    {foreach $agents as $agent}<option value="{$agent['id']}" {if $selected_agent && $selected_agent['id']==$agent['id']}selected{/if}>{$agent['fullname']|escape} — {$agent['username']|escape}</option>{/foreach}
                                </select>
                                {if $selected_agent}<p class="help-block"><a href="{Text::url('settings/users-edit/')}{$selected_agent['id']}">Edit this Agent account in the native Administrator User screen</a></p>{/if}
                            </div>
                        </div>
                    </div>
                    <div id="new-agent"{if $selected_agent} style="display:none"{/if}>
                        <div class="form-group"><label class="col-md-4 control-label">Full Name</label><div class="col-md-8"><input type="text" class="form-control" name="fullname" value="{if $selected_agent}{$selected_agent['fullname']|escape}{/if}"></div></div>
                        <div class="form-group"><label class="col-md-4 control-label">Username</label><div class="col-md-8"><input type="text" class="form-control" name="username" value="{if $selected_agent}{$selected_agent['username']|escape}{/if}" autocomplete="off"></div></div>
                        <div class="form-group"><label class="col-md-4 control-label">Password</label><div class="col-md-8"><input type="password" class="form-control" name="password" autocomplete="new-password"><p class="help-block">Minimum 6 characters. Password is stored using PHPNuxBill's secure password hashing.</p></div></div>
                        <div class="form-group"><label class="col-md-4 control-label">Phone</label><div class="col-md-8"><input type="text" class="form-control" name="phone" value="{if $selected_agent}{$selected_agent['phone']|escape}{/if}"></div></div>
                        <div class="form-group"><label class="col-md-4 control-label">Email</label><div class="col-md-8"><input type="email" class="form-control" name="email" value="{if $selected_agent}{$selected_agent['email']|escape}{/if}"></div></div>
                        <div class="form-group"><div class="col-md-4"><input class="form-control" name="city" placeholder="City"></div><div class="col-md-4"><input class="form-control" name="subdistrict" placeholder="Sub District"></div><div class="col-md-4"><input class="form-control" name="ward" placeholder="Ward"></div></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-md-6">
            <div class="panel panel-primary panel-hovered panel-stacked mb30">
                <div class="panel-heading"><i class="fa fa-handshake-o"></i> Reseller Profile &amp; Access</div>
                <div class="panel-body">
                    <div class="form-group"><label class="col-md-4 control-label">Reseller name</label><div class="col-md-8"><input class="form-control" name="name" value="{if $selected_profile}{$selected_profile['name']|escape}{/if}" placeholder="Display name"></div></div>
                    <div class="form-group"><label class="col-md-4 control-label">Reseller code</label><div class="col-md-8"><input class="form-control" name="reseller_code" value="{if $selected_profile}{$selected_profile['reseller_code']|escape}{/if}" placeholder="e.g. shahin"></div></div>
                    <div class="form-group"><label class="col-md-4 control-label">Customer suffix</label><div class="col-md-8"><input class="form-control" name="customer_prefix" value="{if $selected_profile}{$selected_profile['customer_prefix']|escape}{/if}" placeholder="e.g. liku"><p class="help-block">New reseller customer usernames become <b>customer.suffix</b>.</p></div></div>
                    <div class="form-group"><label class="col-md-4 control-label">Profit share %</label><div class="col-md-8"><input class="form-control" type="number" min="0" max="100" step="0.01" name="profit_percentage" value="{if $selected_profile}{$selected_profile['profit_percentage']}{else}0{/if}"></div></div>
                    <div class="form-group"><label class="col-md-4 control-label">Profile mobile</label><div class="col-md-8"><input class="form-control" name="mobile" value="{if $selected_profile}{$selected_profile['mobile']|escape}{/if}"></div></div>
                    <div class="form-group"><label class="col-md-4 control-label">Profile email</label><div class="col-md-8"><input class="form-control" name="email" value="{if $selected_profile}{$selected_profile['email']|escape}{/if}"></div></div>
                    <div class="form-group"><label class="col-md-4 control-label">Status</label><div class="col-md-8"><select class="form-control" name="status"><option value="active" {if $selected_profile && $selected_profile['status']=='active'}selected{/if}>Active</option><option value="suspended" {if $selected_profile && $selected_profile['status']=='suspended'}selected{/if}>Suspended</option><option value="disabled" {if $selected_profile && $selected_profile['status']=='disabled'}selected{/if}>Disabled</option></select></div></div>
                    <div class="form-group"><label class="col-md-4 control-label">Customer approval</label><div class="col-md-8"><div class="checkbox"><label><input type="checkbox" name="auto_approve_customers" value="1" {if $selected_profile && $selected_profile['auto_approve_customers']}checked{/if}> Auto approve new customers</label></div><p class="help-block">Off: admin must approve every new reseller customer. On: new customers are approved automatically.</p></div></div><div class="form-group"><label class="col-md-4 control-label">Allowed notification gateways</label><div class="col-md-8"><div class="checkbox"><label><input type="checkbox" name="allow_whatsapp" value="1" {if !$selected_profile || $selected_profile['allow_whatsapp']}checked{/if}> WhatsApp</label></div><div class="checkbox"><label><input type="checkbox" name="allow_sms" value="1" {if !$selected_profile || $selected_profile['allow_sms']}checked{/if}> SMS</label></div><div class="checkbox"><label><input type="checkbox" name="allow_email" value="1" {if $selected_profile && $selected_profile['allow_email']}checked{/if}> Email</label></div><p class="help-block">Reseller can only choose the channels enabled here when creating a customer.</p></div></div>
                    <div class="form-group"><label class="col-md-4 control-label">Admin notes</label><div class="col-md-8"><textarea class="form-control" name="notes" rows="3">{if $selected_profile}{$selected_profile['notes']|escape}{/if}</textarea></div></div>
                </div>
            </div>
        </div>
    </div>
    <div class="row"><div class="col-md-12"><div class="panel panel-default panel-hovered panel-stacked"><div class="panel-heading">Allowed packages</div><div class="panel-body"><p class="text-muted">Select packages the reseller may use. Individual price and profit rules can be refined from the <a href="{Text::url('reseller/packages')}">Packages</a> section.</p><select class="form-control" name="package_ids[]" multiple size="6">{foreach $plans as $plan}<option value="{$plan['id']}">{$plan['name_plan']|escape} — {Lang::moneyFormat($plan['price'])}</option>{/foreach}</select></div></div></div></div>
    <div class="form-group text-center"><button class="btn btn-primary" type="submit"><i class="fa fa-save"></i> Save Reseller</button> &nbsp; <a href="{Text::url('reseller/dashboard')}">Cancel</a></div>
</form>
{else}
<div class="text-right" style="margin-bottom:15px"><a class="btn btn-primary" href="{Text::url('reseller/resellers/add')}"><i class="fa fa-plus"></i> Add Reseller</a></div>
<div class="panel panel-default panel-hovered panel-stacked mb30">
    <div class="panel-heading"><i class="fa fa-users"></i> Existing Resellers</div>
    <div class="panel-body table-responsive">
        <table class="table table-bordered table-hover"><thead><tr><th>Reseller</th><th>Agent username</th><th>Customers</th><th>Unapproved</th><th>Approval Mode</th><th>Status</th><th></th></tr></thead><tbody>
        {foreach $resellers as $reseller}<tr><td>{$reseller['name']|escape}</td><td>{$reseller['username']|escape}</td><td>{$reseller['customer_count']}</td><td>{if $reseller['pending_count']>0}<span class="label label-warning">{$reseller['pending_count']} pending</span>{else}<span class="label label-success">0</span>{/if}</td><td>{if $reseller['auto_approve_customers']}Auto{else}Admin approval{/if}</td><td>{$reseller['status']|escape}</td><td class="text-right"><a class="btn btn-xs btn-success" href="{Text::url('reseller/customers/')}{$reseller['id']}"><i class="fa fa-users"></i> View Customers</a> {if $reseller['pending_count']>0}<a class="btn btn-xs btn-warning" href="{Text::url('reseller/customers/')}{$reseller['id']}&approval=pending"><i class="fa fa-clock-o"></i> Review Pending</a>{/if} <a class="btn btn-xs btn-primary" href="{Text::url('reseller/resellers/')}{$reseller['user_id']}"><i class="fa fa-pencil"></i> Configure</a></td></tr>{foreachelse}<tr><td colspan="7" class="text-center text-muted">No reseller profile exists yet.</td></tr>{/foreach}
        </tbody></table>
    </div>
</div>
{/if}
{literal}<script>function resellerAccountMode(mode){document.getElementById('new-agent').style.display=mode==='new'?'block':'none';document.getElementById('existing-agent').style.display=mode==='existing'?'block':'none';}</script>{/literal}
{include file="sections/footer.tpl"}
