{literal}<style>
  .jm-usage-wrap{margin:18px 0 8px;padding:16px 16px 4px;background:#f8fafc;border:1px solid #e7edf3;border-radius:12px;box-shadow:0 4px 18px rgba(31,45,61,.06)}.jm-usage-head{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:14px}.jm-usage-head h4{margin:0;color:#40566d;font-size:20px;font-weight:600}.jm-cycle{font-size:12px;color:#52677d;background:#e7eef7;padding:6px 11px;border-radius:16px;white-space:nowrap}
  .jm-usage-card{border-radius:10px;color:#fff;padding:15px 18px;min-height:116px;box-shadow:0 7px 18px rgba(31,45,61,.14);position:relative;overflow:hidden;margin-bottom:14px}.jm-usage-card:after{content:"";position:absolute;width:110px;height:110px;border-radius:50%;background:rgba(255,255,255,.12);right:-28px;top:-42px}.jm-usage-card h3{font-size:27px;margin:3px 0 5px;font-weight:700}.jm-usage-card p{margin:0 0 7px;font-size:14px;font-weight:600}.jm-usage-detail{font-size:12px;opacity:.94;display:flex;flex-wrap:wrap;gap:5px 14px}.jm-isp1{background:linear-gradient(135deg,#1769e0,#00a8e8)}.jm-isp2{background:linear-gradient(135deg,#6a35d4,#a243e6)}.jm-total{background:linear-gradient(135deg,#008f72,#13c6a3)}
  .jm-usage-chart{background:#fff;border:1px solid #e8edf2;border-radius:10px;padding:14px 16px;margin-bottom:14px}.jm-usage-chart h4{margin:0 0 3px;color:#40566d;font-size:17px}.jm-chart-note{font-size:12px;color:#718096;margin-bottom:8px}.jm-chart-frame{position:relative;width:100%;height:220px;max-height:220px}.jm-chart-frame canvas{width:100%!important;height:100%!important;max-height:220px!important}
  @media(max-width:767px){.jm-usage-wrap{padding:12px 10px 2px}.jm-usage-head{align-items:flex-start;flex-direction:column}.jm-cycle{white-space:normal}.jm-chart-frame{height:190px;max-height:190px}.jm-chart-frame canvas{max-height:190px!important}}
</style>{/literal}
<div class="jm-usage-wrap">
  <div class="jm-usage-head"><h4><i class="fa fa-exchange"></i> Monthly ISP Data Usage</h4><span class="jm-cycle">Billing cycle: {$jmIspUsage.cycle_start} — {$jmIspUsage.cycle_end}</span></div>
  <div class="row">
    <div class="col-md-4"><div class="jm-usage-card jm-isp1"><p>ISP 1 · ORBIT</p><h3>{$jmIspUsage.isp1}</h3><div class="jm-usage-detail"><span>Download {$jmIspUsage.isp1_rx}</span><span>Upload {$jmIspUsage.isp1_tx}</span></div></div></div>
    <div class="col-md-4"><div class="jm-usage-card jm-isp2"><p>ISP 2 · LINK-3</p><h3>{$jmIspUsage.isp2}</h3><div class="jm-usage-detail"><span>Download {$jmIspUsage.isp2_rx}</span><span>Upload {$jmIspUsage.isp2_tx}</span></div></div></div>
    <div class="col-md-4"><div class="jm-usage-card jm-total"><p>Total WAN Usage</p><h3>{$jmIspUsage.total}</h3><div class="jm-usage-detail"><span>RADIUS subscriber total: {$jmIspUsage.radius_total}</span></div></div></div>
  </div>
  <div class="jm-usage-chart">
    <h4><i class="fa fa-bar-chart"></i> Monthly Data Usage</h4>
    <div class="jm-chart-note">RADIUS accounting totals · reset day {$jmIspUsage.cycle_start|date_format:"%d"} of each month</div>
    <div class="jm-chart-frame"><canvas id="jmMonthlyUsageChart"></canvas></div>
  </div>
</div>
<script>
{literal}
document.addEventListener('DOMContentLoaded', function () {
  var canvas = document.getElementById('jmMonthlyUsageChart');
  if (!canvas || typeof Chart === 'undefined') return;
  new Chart(canvas.getContext('2d'), {
    type: 'bar',
    data: { labels: {/literal}{$jmIspUsage.history_labels|json_encode nofilter}{literal}, datasets: [{ label: 'Total usage (GiB)', data: {/literal}{$jmIspUsage.history_gb|json_encode nofilter}{literal}, backgroundColor: 'rgba(19, 198, 163, .78)', borderColor: '#008f72', borderWidth: 1, borderRadius: 5 }] },
    options: { responsive:true, maintainAspectRatio:false, plugins:{legend:{display:true}}, scales:{x:{grid:{display:false}},y:{beginAtZero:true,ticks:{callback:function(v){return v+' GiB'}}}} }
  });
});
{/literal}
</script>
