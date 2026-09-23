{include file="sections/header.tpl"}
<div class="row"><div class="col-md-10 col-md-offset-1">
  <div class="box box-primary">
    <div class="box-header"><h3 class="box-title"><i class="glyphicon glyphicon-phone"></i> JM Broadband Mobile App</h3></div>
    <div class="box-body">
      {if $jmapp_error}<div class="alert alert-danger">{$jmapp_error|escape}</div>{/if}
      {if $jmapp_success}<div class="alert alert-success">{$jmapp_success|escape}</div>{/if}
      {if $jmapp_release}
      <h4>Published: {$jmapp_release.version|escape} (build {$jmapp_release.build_number|escape})</h4>
      <p>Required update: {if $jmapp_release.required_update}YES{else}NO{/if}</p>
      <p><a href="{$jmapp_link|escape}" class="btn btn-success"><i class="glyphicon glyphicon-download"></i> Download / Install Current APK</a></p>
      <div class="form-group"><label>Permanent link</label><input class="form-control" readonly value="{$jmapp_link|escape}" onclick="this.select()"></div>
      {else}<div class="alert alert-info">No APK published yet. Upload your currently signed release.</div>{/if}
      <p>Use <strong>[[app_download_link]]</strong> in SMS and WhatsApp templates to insert the latest APK link.</p>
      <form method="post" action="{$_url}plugin/mobileApp" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="{$jmapp_csrf|escape}">
        <div class="form-group"><label>Signed APK (leave empty to change settings)</label><input type="file" name="apk" accept=".apk,application/vnd.android.package-archive"></div>
        <div class="row"><div class="col-sm-6"><label>Version name</label><input name="version" class="form-control" placeholder="1.0.8" value="{if $jmapp_release}{$jmapp_release.version|escape}{/if}"></div>
        <div class="col-sm-6"><label>Android build number</label><input name="build_number" type="number" min="1" class="form-control" value="{if $jmapp_release}{$jmapp_release.build_number|escape}{/if}"></div></div>
        <div class="form-group"><label>Release notes</label><textarea class="form-control" name="notes" rows="4" maxlength="4000">{if $jmapp_release}{$jmapp_release.notes|escape}{/if}</textarea></div>
        <div class="checkbox"><label><input name="required_update" type="checkbox" value="1" {if $jmapp_release && $jmapp_release.required_update}checked{/if}><strong>Required update</strong> — older app versions must update before accessing the app.</label></div>
        <p class="help-block">Android asks each user to confirm installation. Uploaded APK must have the same package ID and release signing certificate.</p>
        <button class="btn btn-primary" type="submit">Publish APK / Save Settings</button>
      </form>
    </div>
  </div>
</div></div>
{include file="sections/footer.tpl"}