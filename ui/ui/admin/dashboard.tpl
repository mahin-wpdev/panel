{include file="sections/header.tpl"}

{function showWidget pos=0}
    {foreach $widgets as $w}
        {if $w['position'] == $pos}
            {$w['content']}
        {/if}
    {/foreach}
{/function}

{assign dtipe value="dashboard_`$tipeUser`"}

{if $tipeUser eq 'Admin'}
<div class="row">
    <div class="col-md-12"><h4 class="page-header" style="margin-top:0"><i class="fa fa-line-chart"></i> Reseller Overview</h4></div>
    <div class="col-lg-3 col-md-6"><div class="small-box bg-purple"><div class="inner"><h3>{$reseller_total}</h3><p>Resellers <small>({$reseller_active_total} Active)</small></p></div><div class="icon"><i class="fa fa-handshake-o"></i></div><a href="{Text::url('reseller/resellers')}" class="small-box-footer">Manage resellers <i class="fa fa-arrow-circle-right"></i></a></div></div>
    <div class="col-lg-3 col-md-6"><div class="small-box bg-aqua"><div class="inner"><h3>{$reseller_customer_total}</h3><p>Reseller Customers</p></div><div class="icon"><i class="fa fa-users"></i></div><a href="{Text::url('reseller/ownership')}" class="small-box-footer">View ownership <i class="fa fa-arrow-circle-right"></i></a></div></div>
    <div class="col-lg-3 col-md-6"><div class="small-box bg-green"><div class="inner"><h3>{Lang::moneyFormat($reseller_profit_due)}</h3><p>Reseller Profit Payable</p></div><div class="icon"><i class="fa fa-money"></i></div><a href="{Text::url('reseller/settlements')}" class="small-box-footer">View settlements <i class="fa fa-arrow-circle-right"></i></a></div></div>
    <div class="col-lg-3 col-md-6"><div class="small-box bg-yellow"><div class="inner"><h3>{Lang::moneyFormat($reseller_month_profit)}</h3><p>Reseller Profit This Month</p></div><div class="icon"><i class="fa fa-bar-chart"></i></div><a href="{Text::url('reseller/earnings')}" class="small-box-footer">View earnings <i class="fa fa-arrow-circle-right"></i></a></div></div>
</div>
<div class="row">
    <div class="col-md-12"><h4 class="page-header"><i class="fa fa-sitemap"></i> Network &amp; Service Overview <small>ONU values are refreshed by OLT sync</small></h4></div>
    <div class="col-lg-3 col-md-6"><div class="small-box bg-light-blue"><div class="inner"><h3>{$service_active_total}/{$service_inactive_total}</h3><p>Service Active / Inactive</p></div><div class="icon"><i class="fa fa-user"></i></div><a href="{Text::url('customers/list')}" class="small-box-footer">View customers <i class="fa fa-arrow-circle-right"></i></a></div></div>
    <div class="col-lg-3 col-md-6"><div class="small-box bg-teal"><div class="inner"><h3>{$onu_online_total}/{$onu_offline_total}</h3><p>ONU Online / Offline</p></div><div class="icon"><i class="fa fa-wifi"></i></div><a href="{Text::url('onus')}" class="small-box-footer">View ONU status <i class="fa fa-arrow-circle-right"></i></a></div></div>
    <div class="col-lg-3 col-md-6"><div class="small-box bg-red"><div class="inner"><h3>{$onu_los_total}</h3><p>ONU LOS Alert</p></div><div class="icon"><i class="fa fa-warning"></i></div><a href="{Text::url('onus')}" class="small-box-footer">Review ONU alerts <i class="fa fa-arrow-circle-right"></i></a></div></div>
    <div class="col-lg-3 col-md-6"><div class="small-box bg-orange"><div class="inner"><h3>{$olt_online_total}/{$olt_total}</h3><p>OLT Online / Total</p><p style="font-size:12px;margin:4px 0 0">Last Sync: {if $olt_last_sync_at}<span class="label {if $olt_last_sync_status eq 'success'}label-success{else}label-danger{/if}" style="text-transform:uppercase">{$olt_last_sync_status}</span> {$olt_last_sync_at}{else}Never{/if}</p></div><div class="icon"><i class="fa fa-server"></i></div><a href="{Text::url('olts')}" class="small-box-footer">View OLT health <i class="fa fa-arrow-circle-right"></i></a></div></div>
</div>
<div class="row">
    <div class="col-lg-3 col-md-6"><div class="small-box bg-maroon"><div class="inner"><h3>{$onu_unassigned_total}</h3><p>Unassigned ONU</p></div><div class="icon"><i class="fa fa-link"></i></div><a href="{Text::url('onus')}" class="small-box-footer">Assign ONU <i class="fa fa-arrow-circle-right"></i></a></div></div>
</div>
{/if}

{if $tipeUser eq 'Admin' && $ispUsageWidget}
    {$ispUsageWidget}
{/if}

{assign rows explode(".", $_c[$dtipe])}
{assign pos 1}
{foreach $rows as $cols}
    {if $cols == 12}
        <div class="row">
            <div class="col-md-12">
                {showWidget widgets=$widgets pos=$pos}
            </div>
        </div>
        {assign pos value=$pos+1}
    {else}
        {assign colss explode(",", $cols)}
        <div class="row">
            {foreach $colss as $c}
                <div class="col-md-{$c}">
                    {showWidget widgets=$widgets pos=$pos}
                </div>
                {assign pos value=$pos+1}
            {/foreach}
        </div>
    {/if}
{/foreach}

{if $_c['new_version_notify'] != 'disable'}
    <script>
        window.addEventListener('DOMContentLoaded', function() {
            $.getJSON("./version.json?" + Math.random(), function(data) {
                var localVersion = data.version;
                $('#version').html('Version: ' + localVersion);
                $.getJSON(
                    "https://raw.githubusercontent.com/mahin-wpdev/panel/next-release/version.json?" +
                    Math
                    .random(),
                    function(data) {
                        var latestVersion = data.version;
                        if (localVersion !== latestVersion) {
                            $('#version').html('Latest Version: ' + latestVersion);
                            if (getCookie(latestVersion) != 'done') {
                                Swal.fire({
                                    icon: 'info',
                                    title: "New Version Available\nVersion: " + latestVersion,
                                    toast: true,
                                    position: 'bottom-right',
                                    showConfirmButton: true,
                                    showCloseButton: true,
                                    timer: 30000,
                                    confirmButtonText: '<a href="{Text::url('community')}#latestVersion" style="color: white;">Update Now</a>',
                                    timerProgressBar: true,
                                    didOpen: (toast) => {
                                        toast.addEventListener('mouseenter', Swal.stopTimer)
                                        toast.addEventListener('mouseleave', Swal
                                            .resumeTimer)
                                    }
                                });
                                setCookie(latestVersion, 'done', 7);
                            }
                        }
                    });
            });

        });
    </script>
{/if}

{include file="sections/footer.tpl"}
