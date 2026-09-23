# JM Broadband — self-hosted Android APK updates

## Deployment
Copy the changed panel files into the **matching paths of your running phpNuxBill panel root**:
- system/plugin/mobileApp.php and system/plugin/ui/mobileApp.tpl
- mobile-app-version.php and mobile-app-download.php
- system/autoload/Message.php
- ui/ui/admin/settings/notifications.tpl and ui/ui/admin/message/single.tpl

The module stores metadata in existing tbl_appconfig under setting jm_mobile_release.
No new database table or migration is required; existing payment, recharge and customer
code remains unchanged. Do not overwrite the live config.php or runtime directories.

Set PHP upload_max_filesize >= 256M and post_max_size >= 260M.
Set Nginx client_max_body_size >= 260m.
Ensure the panel web-server user can create/write mobile-app-releases/
and uploaded APK files are publicly readable. Use trusted HTTPS with the
panel base URL as APP_URL; keep the file download on that same origin.

## Publish and test
1. Install the first updater-capable APK through the existing manual method.
2. Sign later release APKs with the SAME release certificate and application ID.
3. Increase Android versionCode (build number) on every subsequent release.
4. Admin > Mobile App: upload your signed APK, enter true version/build,
   release notes, select Required update if intended, and Publish.
5. Verify /mobile-app-version.php returns release JSON and /mobile-app-download.php
   resolves to the uploaded APK from an Android browser.
6. Test old-to-new upgrade with login and local app data preserved.
7. Enable Required update only after checking a real device can upgrade.

[[app_download_link]] is replaced by the stable latest-download URL in
SMS, WhatsApp and email routed through phpNuxBill's Message class.
This link remains stable across future releases. Android always asks users
to approve installation; the app cannot silently install a new APK.
On app resume it rechecks the installed build before removing the update gate.

Server checks may fail when offline; an app cannot enforce an update it
has not yet learned about. A valid HTTPS certificate is mandatory.
