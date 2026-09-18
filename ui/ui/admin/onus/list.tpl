{include file="sections/header.tpl"}
<div class="row">
    <div class="col-md-4"><div class="small-box bg-aqua"><div class="inner"><h3>{$onus|@count}</h3><p>Filtered ONU</p></div><div class="icon"><i class="fa fa-plug"></i></div></div></div>
</div>
<div class="row"><div class="col-md-12"><div class="box box-primary">
    <div class="box-header"><h3 class="box-title">ONU Management</h3><div class="pull-right">
        <a class="btn btn-xs btn-default" href="{Text::url('onus')}">All</a>
        <a class="btn btn-xs btn-success" href="{Text::url('onus/online')}">Online</a>
        <a class="btn btn-xs btn-warning" href="{Text::url('onus/offline')}">Offline</a>
        <a class="btn btn-xs btn-info" href="{Text::url('onus/unassigned')}">Unassigned</a>
    </div></div>
    <div class="box-body table-responsive"><table class="table table-bordered table-striped">
        <thead><tr><th>Status</th><th>Customer / PPPoE</th><th>OLT / PON / ONU</th><th>MAC</th><th>RX / TX</th><th>Distance</th><th>Last Seen</th><th>Customer Mapping</th></tr></thead>
        <tbody>{foreach $onus as $o}<tr>
            <td>{$o['status']}</td>
            <td>{if $o['customer_id']}{$o['fullname']|escape}<br><small>{$o['username']|escape}</small>{else}—{/if}</td>
            <td>{$o['name']|escape}<br><small>PON {$o['slot_number']}/{$o['pon_port']}, ONU {$o['onu_id']}</small></td>
            <td>{$o['mac_address']}</td>
            <td>{if $o['rx_power']!==null}{$o['rx_power']} / {$o['tx_power']} dBm{else}N/A{/if}</td>
            <td>{if $o['distance']!==null}{$o['distance']} m{else}N/A{/if}</td>
            <td>{$o['last_seen']}</td>
            <td>{if !$o['customer_id']}
                <form method="post" action="{Text::url('onus/assign/', $o['id'])}"><input type="hidden" name="token" value="{$csrf_token}"><select name="customer_id" class="form-control input-sm" required><option value="">Customer…</option>{foreach $customers as $c}<option value="{$c['id']}">{$c['fullname']|escape} ({$c['username']|escape})</option>{/foreach}</select><button class="btn btn-xs btn-primary">Assign</button></form>
            {else}
                <form method="post" action="{Text::url('onus/unassign/', $o['id'])}" onsubmit="return confirm('Remove this ONU from the customer?');"><input type="hidden" name="token" value="{$csrf_token}"><button class="btn btn-xs btn-warning">Unassign</button></form>
            {/if}</td>
        </tr>{foreachelse}<tr><td colspan="8" class="text-center">No ONU data available. Run OLT Sync.</td></tr>{/foreach}</tbody>
    </table></div>
</div></div></div>
{include file="sections/footer.tpl"}
