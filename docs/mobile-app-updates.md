# JM Broadband: GitHub-managed Android updates

GitHub is the single release manager. phpNuxBill only reads the latest public GitHub
Release and offers same-origin manifest and stable download endpoints. No panel
APK upload, release settings, database writes, or payment changes are involved.

## Release requirements

Repository: https://github.com/mahin-wpdev/jm-broadband-android (PUBLIC).
The **next-release** branch contains tested production Flutter code.
The workflow `.github/workflows/publish-android-release.yml` must also exist
on the GitHub default branch for its Run workflow button to appear.

In repository Settings > Secrets and variables > Actions > Repository secrets,
configure the four values from the **existing public release signing key**:
- `ANDROID_KEYSTORE_BASE64`: base64 encoding of the original .jks bytes (no wrapping).
- `ANDROID_KEY_ALIAS`: signing key alias.
- `ANDROID_KEY_PASSWORD`: key password.
- `ANDROID_STORE_PASSWORD`: keystore password.

Never commit `.jks`, `android/key.properties`, passwords or a build APK.
GitHub Actions checks that the signed APK has the exact existing signing
certificate fingerprint, and refuses a changed key.

## Publish using GitHub only

1. Merge tested Android changes into `next-release`. Bump `pubspec.yaml`
   version to a previously unused `MAJOR.MINOR.PATCH+BUILD`.
2. Open GitHub > Actions > Publish signed JM Broadband APK to GitHub >
   Run workflow. Set `required_update` and `release_notes`; run.
3. It analyzes and tests the app, builds the signed release APK, checks signer,
   creates the version tag (e.g. `v1.0.8+9`), uploads `jm-broadband.apk` to
   a **draft** Release, then publishes the Release as Latest.
4. GitHub release body begins with
   `<!-- jm-app-update:required=true -->` or `false`. GitHub owns this
   release policy. Do not remove the marker or rename the APK asset.
5. For the next release, commit a higher version/build to `next-release`
   and rerun the GitHub Action. Existing tags cannot be re-used.

The signed release workflow publishes a public `mobile-release.json` snapshot
on the Android repository's `main` branch after the APK Release is live.
The panel reads this snapshot from `raw.githubusercontent.com` instead of
using the shared-IP GitHub REST API quota. It still validates tag, update
policy, asset name, size, SHA-256 digest and GitHub download URL. Validated
metadata is cached for ten minutes; on temporary CDN failure, a previously
validated snapshot may be used for up to 24 hours. Malformed data fails
closed. GitHub retains the APK; phpNuxBill never hosts or copies it.

## Panel deployment

Copy just these changed tracked files from the panel branch:
`system/mobile/github-release.php`, `mobile-app-version.php`,
`mobile-app-download.php`, `system/autoload/Message.php`, and remove the
old `system/plugin/mobileApp.php` and `system/plugin/ui/mobileApp.tpl`.
No new tables, admin menu or settings are required.

Existing endpoint: `https://YOUR-PANEL/panel/mobile-api.php`
Update manifest: `https://YOUR-PANEL/panel/mobile-app-version.php`
Stable APK link: `https://YOUR-PANEL/panel/mobile-app-download.php`

JM Broadband's Link-3 IP has an additional TLS-verified `:8443` entrypoint
forwarded by MikroTik to the **same** HTTPS panel on `10.10.10.7:443`.
LTE users reach `https://27.147.201.165:8443/panel`, while the original
`:443` entrypoint stays unchanged for other networks. Manifest URLs are
same-origin for both entrypoints, even if release metadata was cached by
a request from the other entrypoint. `[[app_download_link]]` uses the public
`:8443` URL for this ISP; no APK or signing key is hosted on the panel.

`[[app_download_link]]` may be used in SMS, WhatsApp and email templates;
it expands to the stable panel download link, which redirects to GitHub.
PHP requires cURL, outbound HTTPS to `raw.githubusercontent.com` and
`github.com`, and a working TLS certificate. Release metadata caching uses
the PHP temporary directory. Newly published metadata becomes visible after
up to ten minutes of local caching, plus GitHub's raw-content cache.

Existing 1.0.7 app lacks updater code and must receive the updater-bearing
1.0.8 APK once through the conventional install/update path. Later releases
can use in-app updates. Existing Android installation signatures must match.

## Verification before public release

`php -l` all changed PHP files and run `php tests/mobile/mobile_app_update_test.php`.
Run `flutter analyze` and `flutter test`; verify signed APK package ID,
version code, and certificate; install the candidate over existing app.
Publish first as an **optional** update, test manifest, download, SHA-256,
Android installer and login before publishing a forced update for everyone.
