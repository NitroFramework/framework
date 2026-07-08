# Release Workflow

NitroPHP ships as two paired, Composer-installable packages:

- **`nitro/framework`** — the engine (this repo).
- **`nitro/nitro`** — the application skeleton (`composer create-project nitro/nitro`).

They are released **together, under the same version tag.** `create-project` serves the skeleton's latest **stable tag**, so a skeleton that lags the framework hands new users a stale starter. Always pair the tags.

## Versioning

While pre-1.0 (`0.x`):

- **`0.MINOR.0`** — a milestone; the `^0.x` compatibility boundary. Consumers opt in by bumping their constraint.
- **`0.MINOR.PATCH`** — fixes and small, non-breaking additions consumers get on `composer update`.

Stay on `0.x` — don't jump to `1.0` without a deliberate decision.

## Cutting a release

1. **Green locally.** `composer test` in the framework passes.

2. **Verify in a consumer against your local working tree — *before* tagging.** Point a consuming app at the local framework via a Composer path repository so it exercises your *uncommitted* code:

   ```jsonc
   // the consumer's composer.json
   "repositories": [
     { "type": "path", "url": "../framework", "options": { "symlink": true } }
   ],
   "require": { "nitro/framework": "dev-main" }
   ```

   Run `composer update nitro/framework`, then run the consumer's suite **and drive the actual feature end-to-end** — under **worker mode (FrankenPHP/Thrust)** for anything touching sessions, CSRF, cookies, or navigation. A green unit suite is not proof; superglobal/native-session assumptions that hold under PHP-FPM can break in a long-lived worker.

3. **Tag the framework.**

   ```sh
   git push origin main
   git tag -a vX.Y.Z -m "…"
   git push origin vX.Y.Z
   ```

   Get the tag right on the first push — **Packagist stable refs are immutable.** A fix means a new patch tag, never a re-tag of a published version.

4. **Wait for Packagist to index the tag** before bumping the skeleton — its `composer update` can't resolve a version Packagist hasn't picked up yet (usually seconds, via the GitHub webhook).

5. **Pair-tag the skeleton** to the same version:

   ```sh
   # in the skeleton: drop the path repo, then
   #   "nitro/framework": "^X.Y.Z"
   composer update nitro/framework
   composer validate
   git commit -am "chore: require framework ^X.Y.Z"
   git push origin main
   git tag -a vX.Y.Z -m "…" && git push origin vX.Y.Z
   ```

Framework and skeleton now sit on the same tag, and `create-project` serves the current pair.
