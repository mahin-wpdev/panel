{include file="sections/header.tpl"}
<div class="row">
  <div class="col-md-8">
    <div class="box box-primary">
      <div class="box-header with-border"><h3 class="box-title"><i class="fa fa-whatsapp"></i> Bundled WhatsApp</h3></div>
      <div class="box-body">
        <p><strong>Status:</strong> <span class="label {if $wa_status.connected}label-success{else}label-warning{/if}">{$wa_status.status|escape}</span></p>
        {if $wa_status.user}<p><strong>Account:</strong> {$wa_status.user|escape}</p>{/if}
        <p><strong>Sent today:</strong> {$wa_status.sentToday|default:0} / {$wa_status.dailyLimit|default:0}</p>
        <p><strong>Pending:</strong> {$wa_status.pendingMessages|default:0}</p>
        {if $wa_status.lastError}<div class="alert alert-warning">{$wa_status.lastError|escape}</div>{/if}
        {if !$wa_status.connected}
          <p>Scan this QR using WhatsApp &gt; Linked devices. It refreshes automatically.</p>
          <div class="text-center"><img id="waQr" src="{Text::url('whatsapp/qr')}?t={$smarty.now}" alt="WhatsApp QR" style="max-width:360px;width:100%;border:1px solid #ddd;padding:8px"></div>
          <script>{literal}setInterval(function(){var q=document.getElementById('waQr');if(q)q.src=appUrl+'/?_route=whatsapp/qr&t='+Date.now();},30000);{/literal}</script>
        {/if}
      </div>
      <div class="box-footer">
        <form method="post" action="{Text::url('whatsapp/reconnect')}" style="display:inline"><input type="hidden" name="csrf_token" value="{$csrf_token}"><button class="btn btn-primary"><i class="fa fa-refresh"></i> Reconnect</button></form>
        <form method="post" action="{Text::url('whatsapp/logout')}" style="display:inline"><input type="hidden" name="csrf_token" value="{$csrf_token}"><button class="btn btn-danger" onclick="return confirm('Logout WhatsApp session and generate a new QR?')"><i class="fa fa-sign-out"></i> Logout</button></form>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="box box-success">
      <div class="box-header with-border"><h3 class="box-title">Send test message</h3></div>
      <form method="post" action="{Text::url('whatsapp/test')}">
        <input type="hidden" name="csrf_token" value="{$csrf_token}">
        <div class="box-body">
          <div class="form-group"><label>Phone</label><input class="form-control" name="phone" placeholder="8801XXXXXXXXX" required></div>
          <div class="form-group"><label>Message</label><textarea class="form-control" name="message" rows="4" required>JM Broadband WhatsApp test</textarea></div>
        </div>
        <div class="box-footer"><button class="btn btn-success btn-block"><i class="fa fa-send"></i> Send Test</button></div>
      </form>
    </div>
  </div>
</div>
<div class="box">
  <div class="box-header with-border"><h3 class="box-title">Recent delivery queue</h3></div>
  <div class="box-body table-responsive">
    <table class="table table-striped table-condensed"><thead><tr><th>ID</th><th>Recipient</th><th>Type</th><th>Status</th><th>Created</th><th>Error</th></tr></thead><tbody>
    {foreach $wa_messages as $m}
      <tr><td>{$m.id|escape}</td><td>{$m.recipient|escape}</td><td>{$m.type|escape}</td><td>{$m.status|escape}</td><td>{$m.createdAt|escape}</td><td>{$m.error|default:''|escape}</td></tr>
    {foreachelse}<tr><td colspan="6" class="text-muted text-center">No messages yet.</td></tr>{/foreach}
    </tbody></table>
  </div>
</div>
{include file="sections/footer.tpl"}
