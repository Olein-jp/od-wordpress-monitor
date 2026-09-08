# Release workflow

The Monitor and Agent are developed together in this monorepo and published independently to dedicated public distribution repositories.

| Plugin | Source directory | Distribution repository | Source tag |
| --- | --- | --- | --- |
| OD WordPress Monitor | `plugins/monitor` | `Olein-jp/od-wordpress-monitor-release` | `monitor-vX.Y.Z` |
| OD Monitor Agent | `plugins/agent` | `Olein-jp/od-monitor-agent-release` | `agent-vX.Y.Z` |

The distribution repositories are generated output. Do not edit their plugin files manually.

Both plugins must keep `inc2734/wp-github-plugin-updater` on the same locked version. Update both plugin lock files together whenever that dependency changes, even when only one plugin is being released.

## One-time GitHub App setup

Create a GitHub App owned by `Olein-jp` with repository `Contents: Read and write` permission and no broader permission than required. Install it only on:

- `Olein-jp/od-monitor-agent-release`
- `Olein-jp/od-wordpress-monitor-release`

Configure the following Actions values on `Olein-jp/od-wordpress-monitor`:

- Repository variable `RELEASE_APP_CLIENT_ID`: the GitHub App client ID
- Repository secret `RELEASE_APP_PRIVATE_KEY`: the complete private key downloaded for the App

After creating the App and downloading its private key, an administrator can register the values with GitHub CLI:

```bash
gh variable set RELEASE_APP_CLIENT_ID \
  --repo Olein-jp/od-wordpress-monitor \
  --body 'YOUR_APP_CLIENT_ID'

gh secret set RELEASE_APP_PRIVATE_KEY \
  --repo Olein-jp/od-wordpress-monitor \
  < /absolute/path/to/github-app-private-key.pem
```

The source repository's normal `GITHUB_TOKEN` remains read-only. The workflow requests a short-lived App token restricted to the two distribution repositories.

## Prepare a release

1. Update the target plugin's `Version` header and version constant to the same `X.Y.Z` value.
2. Update its changelog or README when relevant.
3. Run the normal lint and PHPUnit suite.
4. Merge the release preparation change to `main`.
5. Create and push exactly one source tag:

```bash
git tag monitor-v1.0.1
git push origin monitor-v1.0.1
```

or:

```bash
git tag agent-v1.0.1
git push origin agent-v1.0.1
```

The source tag version must match both the plugin header and the version constant. A mismatch stops the workflow before publishing.

The workflow can also be started manually from GitHub Actions by selecting a plugin and version. Manual releases must run from `main`. Tag releases must point to a commit contained in `main`.

## What the workflow publishes

The workflow runs PHPCS and PHPUnit, creates a production-only Composer install, and generates one ZIP with the WordPress plugin slug as its top-level directory. Tests and development dependencies are excluded; runtime dependencies under `vendor` are included.

It then replaces the selected distribution repository's `main` contents with the built plugin, creates `vX.Y.Z`, and publishes the ZIP as the only Release asset. The Release notes record the source commit and ZIP SHA-256 digest.

Publishing stops instead of overwriting when the target Release already exists or a version tag points to different distribution content. If repository and tag publication succeeded but Release creation failed, rerunning the same source release resumes safely from the existing matching tag. Published version tags are immutable and must not be moved.

## Local package verification

Build either package without publishing:

```bash
./scripts/build-plugin-release.sh monitor 1.0.0 /tmp/odm-monitor-release
./scripts/build-plugin-release.sh agent 1.0.0 /tmp/odm-agent-release
```

Inspect the ZIP and checksum before installation:

```bash
unzip -l /tmp/odm-monitor-release/od-wordpress-monitor.zip
cd /tmp/odm-monitor-release
shasum -a 256 -c od-wordpress-monitor.zip.sha256
```

## Compatibility and rollback

Monitor and Agent versions may differ. Protocol changes must remain backward compatible with `schema_version: 1.0`; incompatible changes require a new schema version and a staged Agent-then-Monitor rollout.

Do not replace an existing Release asset or move an existing tag. To roll back, publish a new patch version containing the reverted code so WordPress receives a version greater than the faulty release.
