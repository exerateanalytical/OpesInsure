# Opes Insure — live deployment

Everything below is already provisioned and verified on the server. You do not
need another session's help to deploy; this document is self-contained.

---

## 1. The server

| | |
|---|---|
| Host | `187.77.110.114` (Hostinger VPS, Ubuntu 24.04.5 LTS, Vilnius) |
| Site | **https://insurance.opesdatacenter.tech** |
| SSH user | `opesinsure` |
| SSH key | `~/.ssh/opesinsure_deploy` (private key is on this machine; public key is already installed on the server) |
| App root | `/srv/opesinsure` |
| Live release | `/srv/opesinsure/current` → symlink into `/srv/opesinsure/releases/` |
| Doc root | `/srv/opesinsure/current/public` |

Connect:

```bash
ssh -i ~/.ssh/opesinsure_deploy opesinsure@187.77.110.114
```

The stack is already installed: PHP 8.3 (FPM), PostgreSQL 16, Redis, nginx,
Composer 2.10, Node 22 / npm 10, certbot. All PHP extensions the app needs
(`pdo_pgsql, redis, mbstring, openssl, gd, zip, bcmath, intl, curl, xml`) are
present — verified, none missing.

TLS is live (Let's Encrypt, valid to **2026-12-21**) and auto-renews via
certbot's systemd timer. HTTP 301-redirects to HTTPS.

---

## 2. Deploying

The environment file and storage live **outside** the release directory in
`/srv/opesinsure/shared/`, and are symlinked into each release. Never commit
`.env` or overwrite the shared one during a deploy — the deploy script wires
them up for you.

### The one-command path

Build locally (so the server never needs your dev toolchain), ship a tarball,
run the deploy script:

```bash
# 1. build locally, in the project root
composer install --no-dev --optimize-autoloader --no-interaction
npm ci && npm run build

# 2. pack what the server needs (vendor/ and public/build/ included,
#    .env and storage/ excluded — the server supplies those)
tar -czf /tmp/opesinsure-release.tar.gz \
  --exclude='.git' --exclude='node_modules' --exclude='.env' \
  --exclude='storage' --exclude='tests' \
  .

# 3. upload and deploy
scp -i ~/.ssh/opesinsure_deploy /tmp/opesinsure-release.tar.gz \
    opesinsure@187.77.110.114:/tmp/
ssh -i ~/.ssh/opesinsure_deploy opesinsure@187.77.110.114 \
    '/srv/opesinsure/deploy.sh /tmp/opesinsure-release.tar.gz'
```

`/srv/opesinsure/deploy.sh` unpacks into a new timestamped release, links the
shared `.env` and `storage`, runs `migrate --force`, runs `optimize`, health
checks, and **only then** flips the `current` symlink and reloads PHP-FPM. The
flip is atomic and last, so a failed deploy leaves the previous release serving
untouched. It keeps the last 5 releases and prunes the rest.

**Demo data on deploy.** `optimize` also runs `php artisan demo:seed`
(registered in `AppServiceProvider` via `optimizes()`). When
`DEMO_MODE_ENABLED=true` it idempotently re-runs `DatabaseSeeder` and
`DemoScenarioSeeder` (demo accounts, roles/permissions, the demo scenario);
when demo mode is off it does nothing. A seeding error is reported and
logged but never fails the deploy. Run it by hand with
`php artisan demo:seed` if needed.

### Rolling back

```bash
ssh -i ~/.ssh/opesinsure_deploy opesinsure@187.77.110.114
ls -1dt /srv/opesinsure/releases/r*        # newest first
ln -sfn /srv/opesinsure/releases/<previous> /srv/opesinsure/current
sudo systemctl reload php8.3-fpm
```

---

## 3. The environment file

`/srv/opesinsure/shared/.env` is already written, with a generated `APP_KEY`,
the real database password, and a generated `DOCUMENT_ENCRYPTION_KEY`. It is
mode `600`, owned by `opesinsure`. Read it on the server when you need a value:

```bash
ssh -i ~/.ssh/opesinsure_deploy opesinsure@187.77.110.114 'cat /srv/opesinsure/shared/.env'
```

Set already: `APP_ENV=production`, `APP_DEBUG=false`,
`APP_URL=https://insurance.opesdatacenter.tech`, `DB_*` (below), Redis,
`SESSION_SECURE_COOKIE=true`, `FILESYSTEM_DISK=local`, `MAIL_MAILER=log`.

**Still placeholders — change before going to real users:**

- `PAYMENT_PROVIDER=fake` and every `*_API_TOKEN` / `*_WEBHOOK_SECRET`
  (Maviance, Campay, MTN MoMo, Orange Money) are unset/fake.
- `MAIL_MAILER=log` — no SMTP configured, so no mail actually leaves the box.
- `FILESYSTEM_DISK=local` — object storage (`AWS_*`) is not configured.

### Platform settings (admin panel), OTP delivery and demo accounts

**Admin → Integrations → Platform settings** (`/admin/platform-settings`,
SYSTEM_ADMIN / PLATFORM_ADMIN only) holds, in the `platform_settings` table
(secrets encrypted with `APP_KEY`, cached in Redis, shared by web and queue
workers). A filled-in admin value overrides `.env`; a blank one falls back to it.

| Section | Fields | `.env` fallback |
|---|---|---|
| Support contacts | support email, phone, WhatsApp number, partner email | `SUPPORT_EMAIL`, `SUPPORT_PHONE`, `SUPPORT_WHATSAPP`, `PARTNER_EMAIL` |
| Twilio | SID, auth token, SMS from, WhatsApp from, enabled | `TWILIO_*` |
| ETECH KEYS | SMS login/password/sender (≤ 11 chars), REST bearer token, WhatsApp template name + language, SMS/WhatsApp enabled | `ETECH_SMS_LOGIN`, `ETECH_SMS_PASSWORD`, `ETECH_SMS_SENDER`, `ETECH_REST_TOKEN`, `ETECH_WHATSAPP_TEMPLATE`, `ETECH_WHATSAPP_TEMPLATE_LANGUAGE` |
| One-time codes | channel priority (default WhatsApp → SMS), provider priority (default ETECH → Twilio), *require contact verification* (default **off**) | `OTP_CHANNEL_PRIORITY`, `OTP_PROVIDER_PRIORITY` |
| Mail (SMTP) | host, port, user, password, encryption, from address/name, enabled | `MAIL_*` |

- The app reads support contacts from `GET /api/v1/public/support-contacts`.
- OTP codes are sent by a **queued** job (`SendOtpJob`) — a queue worker must
  run (`php artisan queue:work redis`). Without one, set
  `OTP_DELIVERY_MODE=after_response` so codes are sent by the web process
  right after the response. When every provider fails, `otp.delivery_failed`
  is logged at CRITICAL.
- ETECH delivery reports: set the DLR callback URL in the ETECH dashboard to
  `https://insurance.opesdatacenter.tech/api/v1/webhooks/etech/dlr`.
- Login is not route-throttled. Built-in ceilings (demo personas exempt):
  10 failed password logins / phone / 15 min, 6 code sends / phone / hour,
  300 auth requests / IP / hour, 5 wrong guesses per code (then the code is dead).

**Demo accounts.** Set `DEMO_PASSWORD=Demo@12345` in the shared `.env`
(there is no default outside `APP_ENV=local`). With `DEMO_MODE_ENABLED=true`,
every deploy (`demo:seed`) re-applies it to the demo **personas** — customer
`+237600000100`, agent `…101`, broker `…102`, insurer `…103`, web broker staff
`…007`, web agent `…008` — who sign in with phone + `Demo@12345`
(`POST /api/v1/auth/mobile/password-login`) or OTP `123456`. Persona passwords
cannot be changed or reset. Admin/finance/compliance/claims demo accounts
(`…000`–`…006`) get neither the fixed OTP nor a password reset on re-seed;
their initial password is `LOCAL_ADMIN_PASSWORD` (else `DEMO_PASSWORD`).
`GET /api/v1/public/demo-accounts` returns the password to the app in demo mode.

---

## 4. Database

| | |
|---|---|
| Engine | PostgreSQL 16 on `127.0.0.1:5432` |
| Database | `opesinsure` |
| Role | `opesinsure` (owner; not superuser, cannot create databases) |
| Password | in `/srv/opesinsure/shared/.env` as `DB_PASSWORD` |

The database is empty — **your migrations have not been run yet.** The first
`deploy.sh` run will apply them.

```bash
# psql on the server
ssh -i ~/.ssh/opesinsure_deploy opesinsure@187.77.110.114
psql "postgresql://opesinsure:$(grep ^DB_PASSWORD /srv/opesinsure/shared/.env | cut -d= -f2-)@127.0.0.1:5432/opesinsure"
```

Backups are **not** yet automated for this database — see §7.

---

## 5. Redis

Shared Redis server, but partitioned so the two projects cannot collide:

| | Opes Insure | (SNCA-ES inspectorate) |
|---|---|---|
| default DB | **2** | 0 |
| cache DB | **3** | 1 |
| key prefix | `opesinsure_database_` / `opesinsure_cache_` | its own |

Keep `REDIS_DB=2` / `REDIS_CACHE_DB=3`. Do not point this app at DB 0 or 1.

---

## 6. Isolation from the SNCA-ES inspectorate

The same VPS also runs the MINESUP inspectorate at
`minisup.opesdatacenter.tech`. **The two are separated at four layers, and each
boundary was tested, not assumed:**

1. **Filesystem** — `/srv/opesinsure` is `750 opesinsure:opesinsure`.
   Verified: as `opesinsure`, `ls /srv/snca/` → *Permission denied*.
2. **PHP** — its own FPM pool runs as `opesinsure` with
   `open_basedir=/srv/opesinsure:/tmp:/usr/share/php`. Verified through nginx:
   reading `/srv/snca/current/.env` and `/etc/shadow` both returned `false`.
3. **Database** — `CONNECT` was revoked from `PUBLIC` on both databases and
   granted only to each owner. Verified both directions:
   `opesinsure → snca` and `snca → opesinsure` both fail with
   *permission denied for database*.
4. **Privileges** — the deploy account's sudo is limited to exactly three
   commands (`systemctl reload php8.3-fpm`, `systemctl reload nginx`,
   `nginx -t`). Verified: `sudo su -` and `sudo cat /srv/snca/current/.env`
   both refused.

**Things to not do**, because they are the ways you *could* still disturb the
other site:

- Do not edit `/etc/nginx/sites-*/snca`, or the `snca` FPM pool.
- Do not run `systemctl restart nginx` — `reload` is enough and is what you have
  sudo for. Reload validates the config first and keeps the old one if the new
  one is broken; restart does not.
- Do not `FLUSHALL` Redis. Use `redis-cli -n 2 FLUSHDB` / `-n 3` for your own
  databases only.
- Do not change PostgreSQL global settings or roles other than `opesinsure`.

---

## 7. Not done yet

Honest list of what is *not* set up, so nothing here is a surprise later:

- **No automated database backups** for `opesinsure`. The inspectorate has
  manual dumps in `/srv/snca/backups`; nothing equivalent is scheduled for this
  app. Worth adding a `pg_dump` cron before real data lands.
- **No queue worker running.** `QUEUE_CONNECTION=redis`, but there is no
  `supervisor`/systemd unit running `queue:work`. Queued jobs will pile up
  unprocessed until one is set up.
- **No scheduler.** No cron entry runs `artisan schedule:run`.
- **No SMTP** (fill *Mail (SMTP)* in Platform settings once a server exists), no payment credentials, no object storage (see §3).
- **No CI/CD** — deploys are the manual tarball path in §2.
- **IPv6**: the A record points at IPv4 only. The server has an IPv6 address
  (`2a02:4780:c:d048::1`) and the inspectorate has an `AAAA` record; this
  subdomain deliberately does not, since IPv4 is currently working fine.

---

## 8. Quick health check

```bash
curl -sS -o /dev/null -w "insurance: %{http_code}\n" https://insurance.opesdatacenter.tech/
ssh -i ~/.ssh/opesinsure_deploy opesinsure@187.77.110.114 \
  'readlink /srv/opesinsure/current; tail -5 /srv/opesinsure/shared/storage/logs/laravel.log 2>/dev/null'
```

Logs: nginx `/var/log/nginx/opesinsure-{access,error}.log`, PHP
`/var/log/php-opesinsure-error.log`, Laravel
`/srv/opesinsure/shared/storage/logs/laravel.log`.

Right now `https://insurance.opesdatacenter.tech/` serves a placeholder page
(`/srv/opesinsure/placeholder/`) and returns 200. Your first deploy replaces it.
