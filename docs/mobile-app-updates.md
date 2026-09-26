# JM Broadband: GitHub-managed Android updates

GitHub is the single release manager. Arivo ISP Billing reads a validated public GitHub
release snapshot and offers same-origin manifest and stable download endpoints.
The panel does not host APK files or signing keys. The first-run wizard stores
the selected repository, release channel and default update policy in the
persistent secure volume; payment behavior is unchanged.

## Release requirements

Default repository: https://github.com/mahin-wpdev/jm-broadband-android (PUBLIC).
A different public `owner/repository` may be selected during first-run setup.
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
For a beta channel, publish `mobile-release-beta.json`. The panel reads the
selected snapshot from `raw.githubusercontent.com` instead of using shared-IP
GitHub REST API quota. It validates tag, update policy, asset name, size,
SHA-256 digest and a download URL belonging to the configured repository.
Validated metadata is cached for 15 minutes; on temporary CDN failure, a
previously validated snapshot may be used for up to 24 hours. Malformed data
fails closed. GitHub retains the APK; Arivo ISP Billing never hosts or copies it.

## Panel deployment

The self-hosted installer deploys the mobile connector together with the rest
of the panel. First-run setup writes `system/secure/mobile-release.json` in the
persistent secure volume. Stable and beta channels are read-only from the
panel side; publishing, signing and release creation remain GitHub Actions
responsibilities.

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
up to 15 minutes of local caching, plus GitHub's raw-content cache.

Existing 1.0.7 app lacks updater code and must receive the updater-bearing
1.0.8 APK once through the conventional install/update path. Later releases
can use in-app updates. Existing Android installation signatures must match.

## Verification before public release

`php -l` all changed PHP files and run `php tests/mobile/mobile_app_update_test.php`.
Run `flutter analyze` and `flutter test`; verify signed APK package ID,
version code, and certificate; install the candidate over existing app.
Publish first as an **optional** update, test manifest, download, SHA-256,
Android installer and login before publishing a forced update for everyone.
