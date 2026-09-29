# Launch runbook: 2 October 2026

Production: `ssh -i ~/.ssh/opesinsure_deploy opesinsure@187.77.110.114`. The app is served from `/srv/opesinsure/current`, which is a symlink to `releases/r<UTC timestamp>`. The shared `.env` is at `/srv/opesinsure/shared/.env` and shared storage is at `/srv/opesinsure/shared/storage`.
The source of truth for "are we ready" is **`php artisan launch:preflight`**. The same checks appear on screen at Admin → Operations → **Launch readiness** (platform tenant only).
The command exits with code 1 when any check FAILs. Each WARN and FAIL line is followed by its exact fix.

```
cd /srv/opesinsure/current
php artisan launch:preflight                 # live queue probe (waits up to 20 s for a heartbeat job)
php artisan launch:preflight --send          # also e-mails SUPPORT_EMAIL (else MAIL_FROM_ADDRESS)
php artisan launch:preflight --json          # machine-readable
php artisan launch:preflight --locale=fr
```

After every `.env` change, run `php artisan config:cache`. Then restart the worker so it picks up the new config: `php artisan queue:restart`. systemd `opesinsure-queue` restarts it.

## Production state on 2026-09-29 (read-only run, release r20260929-142515)

The command itself was not yet deployed, so each check was run by hand over ssh.

| Check | Result | Fix |
|---|---|---|
| APP_ENV / APP_DEBUG / APP_KEY | PASS (production, debug off, key set, config cached) | — |
| HTTPS / APP_URL | PASS (`https://insurance.opesdatacenter.tech`, secure cookie, `/up` 200) | — |
| Database / migrations | PASS (pgsql 16, no pending migrations) | — |
| Queue worker | PASS (`opesinsure-queue.service` running `queue:work` on redis, depth 0) | — |
| Scheduler | PASS (cron `schedule:run` every minute, heartbeat fresh) | — |
| Cache / session | PASS (redis / redis) | — |
| **E-mail** | **FAIL** (`MAIL_MAILER=log`) | Owner: SMTP credentials (see A1) |
| **SMS / OTP** | **FAIL** (no eTech, Twilio or SMS-provider connection) | Owner: see A2 |
| **Demo mode** | **FAIL** (`DEMO_MODE_ENABLED=true`) | See A3. Do this only after A2 works, or nobody can log in by OTP |
| **MTN MoMo** | **FAIL** (sandbox host `sandbox.momodeveloper.mtn.com`, target `sandbox`, no keys in .env, no payment-provider connection) | Owner: see A4 |
| Orange Money | WARN (not configured) | Optional; see A5 |
| **ClamAV** | **FAIL** (no `CLAMAV_HOST`/`CLAMAV_SOCKET`, no clamav service installed) | See A6 |
| Storage / disk | PASS (88 GB free, 9 % used) | — |
| Backups | PASS (newest `opesinsure-20260929-1406.sql.gz`, 3.2 MB; nightly cron 02:30) | — |
| Passport keys | PASS | — |
| Signing key | WARN (key and kid present; no `DOCUMENT_TSA_URL`) | Optional RFC 3161 TSA |
| Verify URL | PASS (`https://insurance.opesdatacenter.tech/verify`, HTTP 200) | — |
| Activa connector | WARN (no connection: connector idle) | Only if Activa is sold via the API at launch; see A7 |
| Templates | PASS (660 PUBLISHED, 12 RETIRED) | — |
| RBAC | PASS (`rbac:sync-role-permissions --dry-run`: 0 to add) | — |
| Failed jobs | PASS (0) | — |
| **Log errors (last hour)** | **WARN** (18 ERROR lines: 17 × "MTN MoMo authentication failed" from `payments:poll-pending` every 5 minutes, 1 OAuth denial) | These go away with A4 |

## T-1 day (1 October): owner actions

Every `.env` edit goes in `/srv/opesinsure/shared/.env`. Keep a copy first:

```
cp /srv/opesinsure/shared/.env /srv/opesinsure/shared/.env.bak-launch
```

**A1 E-mail.** Set:

```
MAIL_MAILER=smtp
MAIL_HOST=…
MAIL_PORT=587
MAIL_USERNAME=…
MAIL_PASSWORD=…
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=no-reply@opesdatacenter.tech
SUPPORT_EMAIL=…
```

Then:

```
php artisan config:cache && php artisan launch:preflight --send
```

Confirm the e-mail arrives.

**A2 SMS / OTP.** Choose one:
- Add a PRIMARY ACTIVE provider in Admin → Integrations → SMS providers.
- Or set `ETECH_SMS_LOGIN`, `ETECH_SMS_PASSWORD` and `ETECH_SMS_SENDER` in `.env`. The Twilio equivalent is `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN` and `TWILIO_SMS_FROM`.

Then run `php artisan config:cache`. Request a real OTP on a real phone to confirm.

**A3 Demo mode off.** Do this only after A2 is confirmed. Set:

```
DEMO_MODE_ENABLED=false
DEMO_ALLOW_IN_PRODUCTION=false
```

Then run `php artisan config:cache`. This disables the fixed OTP 123456 and demo sign-in, so scripted demo logins stop working.

**A4 MTN MoMo production.** Choose one:
- Enter the MTN production subscription key, API user, API key and callback token in Admin → Integrations → Payment providers. Use platform scope, environment PRODUCTION, status ACTIVE.
- Or set in `.env`:

  ```
  MTN_MOMO_BASE_URL=https://proxy.momoapi.mtn.com
  MTN_MOMO_TARGET_ENVIRONMENT=mtncameroon
  MTN_MOMO_SUBSCRIPTION_KEY=…
  MTN_MOMO_API_USER=…
  MTN_MOMO_API_KEY=…
  MTN_MOMO_CALLBACK_TOKEN=…
  ```

  Then run `php artisan config:cache`.

MTN must whitelist the callback URL. Test with a 100 FCFA real payment.

**A5 Orange Money (optional).** Set:

```
ORANGE_MONEY_MERCHANT_KEY=…
ORANGE_MONEY_CLIENT_ID=…
ORANGE_MONEY_CLIENT_SECRET=…
ORANGE_MONEY_RETURN_URL=…
ORANGE_MONEY_CANCEL_URL=…
```

Then run `php artisan config:cache`.

**A6 ClamAV.** This needs sudo, so the owner or a server admin runs it:

```
sudo apt install -y clamav-daemon
sudo systemctl enable --now clamav-freshclam clamav-daemon
sudo usermod -aG clamav opesinsure
```

Then set `CLAMAV_SOCKET=/var/run/clamav/clamd.ctl` in `.env` and run:

```
php artisan config:cache && php artisan queue:restart
```

Without ClamAV, uploads are held unscanned (fail-closed): customers' claim and KYC documents are not released.

**A7 Activa (only if sold through the API at launch).** Enter the subscription key and service logins in Admin → Integrations → Activa Assurances (PRODUCTION), then activate the connection. Then run:

```
php artisan activa:reconcile --limit=50
```

## Go-live (2 October)

1. **Backup:**

   ```
   /srv/opesinsure/backup.sh && ls -lt /srv/opesinsure/backups | head -3
   ```

2. **Deploy** the launch release (lead):

   ```
   /srv/opesinsure/deploy.sh /path/to/release.tar.gz
   ```

   The script runs migrate, optimize, the atomic symlink flip, php-fpm reload and queue restart. Note the release name it prints, and the previous one: `ls -1dt /srv/opesinsure/releases/r* | head -2`.

3. **Post-deploy data:**

   ```
   php artisan rbac:sync-role-permissions --dry-run
   php artisan rbac:sync-role-permissions
   php artisan opesinsure:seed-canonical-templates
   ```

   The templates command is idempotent and must leave 660 PUBLISHED.

4. **Preflight:**

   ```
   php artisan launch:preflight --send
   ```

   It must end with **0 FAIL**. For each remaining WARN, record a decision (accept, or fix now).

5. **Smoke test** on a real phone and in a browser:
   - web admin login
   - OTP login on the APK
   - a quote
   - a real MTN MoMo payment → policy issued → PDF downloads → the QR code verifies at `/verify`
   - a document upload that is scanned and released
   - a received e-mail and SMS

6. **Watch for the first hour:**

   ```
   tail -f /srv/opesinsure/shared/storage/logs/laravel.log | grep -E "\.(ERROR|CRITICAL)"
   php artisan queue:failed
   ```

   Also watch Admin → Operations → System health and Launch readiness. Re-run `php artisan launch:preflight` after one hour. The log-errors check covers exactly the last hour.

## Rollback

**App rollback** (no schema change in the release, or its migrations are additive):

```
PREV=$(ls -1dt /srv/opesinsure/releases/r* | sed -n 2p)
ln -sfn "$PREV" /srv/opesinsure/current
sudo -n /usr/bin/systemctl reload php8.3-fpm
cd /srv/opesinsure/current && php artisan optimize && php artisan queue:restart
php artisan launch:preflight --no-queue-probe
```

**Config rollback:**

```
cp /srv/opesinsure/shared/.env.bak-launch /srv/opesinsure/shared/.env
php artisan config:cache && php artisan queue:restart
```

**Payments rollback:** set the Payment-provider connection to DISABLED, or restore the `.env` backup. Pending intents are re-polled by `payments:poll-pending`. Never delete payment rows.

**Database restore** (last resort; loses writes since the backup; the owner decides):

```
php artisan down
gunzip -c /srv/opesinsure/backups/opesinsure-<stamp>.sql.gz | psql -h 127.0.0.1 -U opesinsure -d opesinsure
```

This must restore into an empty database recreated by the DBA. Rehearse it first with `php artisan ops:verify-restore --file=…`, which restores into the scratch database. Then run `php artisan up`.

**Maintenance page** during any rollback:

```
php artisan down --retry=60   # then later: php artisan up
```

Rollback is complete when `php artisan launch:preflight` shows the same results as before the deploy.
