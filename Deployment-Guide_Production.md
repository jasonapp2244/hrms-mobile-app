# Production deployment

**Closes:** C1.14 — the last open item in Part C.
**Unblocks:** the Flutter mobile app, which cannot be built against a laptop.

Everything here is done once, on the server. Afterwards a new revision ships
with `sudo bash deploy/deploy.sh` and nothing else.

---

## Why this comes before the app

The API, push delivery and the privacy pages are all built and tested, and all
three are unreachable from a phone as things stand. A handset cannot resolve
`127.0.0.1`; FCM will not deliver to a server it cannot call back; and neither
app store will accept a listing whose privacy-policy URL points at localhost.

Work done against a local server has to be redone against a real one, so the
hosting comes first and then the app is built once.

---

## What you need before starting

> **This first-time setup has already been done.** The app is live at
> `https://hrams.devonlinetestserver.com`, on managed webspace with Varnish in
> front and no systemd, at
> `/home/devonlinetestserver-hrams/htdocs/hrams.devonlinetestserver.com`.
> To ship a revision, skip to *Shipping a new revision* — or just run
> `ALLOW_NON_PRODUCTION=1 bash deploy/deploy.sh` from that directory. The rest
> of this section is kept for rebuilding the host, or standing up a second one.

| | |
|---|---|
| A Linux server | 2 vCPU / 4 GB is comfortable for a few hundred employees. Ubuntu 22.04 or 24.04 assumed below. |
| The domain | `hrams.devonlinetestserver.com` — this subdomain, not another. Its A record must already point at the server's IP, and DNS must resolve **before** you request a certificate. |
| An SMTP provider | Postmark, SES, Mailgun or Resend. Not the host's own sendmail — a new server's IP has no sending reputation and its mail lands in spam. |
| SSH root access | For the one-time setup only. |

The Firebase project is **not** needed yet. Push stays off until the app exists;
`Push-Notifications_Setup.md` covers it when you get there.

### On IONOS specifically

IONOS is the chosen host. Everything below works there unchanged — a Cloud VPS is
an ordinary Ubuntu box — but four things are worth knowing before you start.

**Take a Cloud VPS, not "Hosting".** The product picker offers *"Hosting — using
webspace and databases"*, and it cannot run this application. IONOS webspace caps
every cron job at **60 seconds** and will not repeat one more often than every
**5 minutes**. Step 6 needs the scheduler every minute, step 7 needs a queue
worker that stays alive indefinitely, and `db:backup --verify` restores each dump
into a scratch database, which on its own outlives a 60-second budget. None of
that announces itself: on webspace the dashboard looks correct while attendance
is never closed, no reminder is sent, no backup is taken, and no leave decision
ever reaches an employee. 2 vCPU / 4 GB is the size to take.

**Cloud Cubes also works, and is the harder road.** The panel promotes it with a
free trial. Cubes S is 2 vCPU / 4 GB / 120 GB NVMe and Ubuntu 24.04 is available
as `ubuntu-24.04-server-cloudimg-amd64`, so it is a genuine option — but it lives
in the Data Center Designer under a separate IONOS Cloud contract, and you
assemble the NIC, the public IP and the firewall yourself. A Cube created without
a root password or an injected SSH key cannot be reached afterwards. Per-minute
billing earns nothing here either, because the worker and the scheduler run
continuously and so this never scales to zero. Reasonable for rehearsing the
build; put the real install on the VPS. Everything from step 1 onward is
identical either way.

**DNS is in the IONOS panel, not on the server.** Point an A record at the VPS IP
under Domains & SSL and let it resolve before you get anywhere near certbot in
step 5 — a certificate request against a name that does not yet resolve fails and
rate-limits you for an hour. There is no need to buy an SSL product; certbot
issues the certificate in step 5.

**Outbound port 25 is blocked; 587 is not.** Every new IONOS VPS has TCP/25
blocked at the network level, above the server firewall — no toggle exists in the
panel, only a phone call to support. This costs nothing provided the sending
provider from the table above is configured on **587** or **465**, which all of
them support; it only bites if you assume 25 works. The default inbound firewall
already allows 22, 80 and 443, so no firewall change is needed for this
deployment.

### On Hostinger specifically

Hostinger is the alternative these notes were first written for, kept in case
it is used instead. Everything below works there unchanged — it is an
ordinary Ubuntu box — but four things are worth knowing before you start.

**Take a VPS, not shared hosting.** The KVM 1 plan (1 vCPU / 4 GB) runs a few
hundred employees comfortably. Shared and "Business" web hosting cannot run the
queue worker as a long-lived process and gives you no real per-minute cron, and
those two between them drive every reminder, the end-of-day close, the nightly
backup, leave accrual and every scheduled report. The dashboard would look fine
while none of that ever ran.

**Choose the plain Ubuntu 24.04 template**, not one of the panel images. A VPS
that arrives with CyberPanel, CloudPanel or Plesk already installed has its own
nginx and PHP that fight with the setup below; you would spend longer undoing it
than installing from clean.

**DNS is in hPanel, not on the server.** Point an A record at the VPS IP and let
it resolve before you get anywhere near certbot in step 5 — a certificate request
against a name that does not yet resolve fails and rate-limits you for an hour.

**Do not use Hostinger's mail for sending.** Their outbound SMTP is intended for
low-volume webmail and a new IP has no sending reputation, so password resets and
scheduled reports land in spam. Use a real sending provider as the table above
says. This is the single most common way a working deployment looks broken.

---

## 1. Server packages

```bash
sudo apt update
sudo apt install -y nginx mysql-server certbot python3-certbot-nginx \
  php8.3-fpm php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl \
  php8.3-zip php8.3-gd php8.3-bcmath php8.3-intl \
  git unzip
```

`php8.3-gd` and `php8.3-zip` are not optional here — they are what the Excel and
PDF exports need, and their absence shows up as a report that fails only when
somebody tries to run one.

Composer:

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

---

## 2. Database

```bash
sudo mysql
```

```sql
CREATE DATABASE emp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'emp'@'localhost' IDENTIFIED BY 'a-long-random-password';
GRANT ALL PRIVILEGES ON emp.* TO 'emp'@'localhost';

-- db:backup --verify restores each dump into a scratch database named
-- vrfy_emp_<time> to prove it reads back, then drops it. The same user needs
-- to be able to create and drop those. The backslash escapes the underscore,
-- which is a single-character wildcard in a GRANT pattern.
GRANT ALL PRIVILEGES ON `vrfy\_%`.* TO 'emp'@'localhost';

FLUSH PRIVILEGES;
```

Not `root`. The application account should be able to reach its own schema and
nothing else on the server.

---

## 3. The code

```bash
sudo mkdir -p /var/www
sudo chown -R www-data:www-data /var/www
sudo -u www-data git clone https://github.com/jasonapp2244/hr_-and_attendance_management_web_application.git /var/www/emp-repo

# The Laravel app is the hrms/ subfolder of the repository. The symlink name
# is what nginx and the systemd unit refer to, and stays 'emp' either way.
sudo ln -s /var/www/emp-repo/hrms /var/www/emp

cd /var/www/emp
sudo -u www-data composer install --no-dev --optimize-autoloader
```

Permissions — only these two trees need to be writable, and nothing else should
be:

```bash
sudo chown -R www-data:www-data /var/www/emp/storage /var/www/emp/bootstrap/cache
sudo chmod -R 775 /var/www/emp/storage /var/www/emp/bootstrap/cache
```

Backups live outside the application directory, so that losing the deploy does
not take the backups with it:

```bash
sudo mkdir -p /var/backups/emp
sudo chown www-data:www-data /var/backups/emp
sudo chmod 750 /var/backups/emp
```

---

## 4. Environment

```bash
sudo -u www-data cp /var/www/emp/.env.production.example /var/www/emp/.env
sudo -u www-data php artisan key:generate
sudo -u www-data nano /var/www/emp/.env
```

Every line marked `CHANGE ME` in that file has to be filled in. The four that
break the install silently rather than loudly:

- **`APP_DEBUG=false`** — debug pages print the database password, the mail
  password and `APP_KEY` to anyone who can trigger an error.
- **`MAIL_MAILER`** — left as `log`, leave approvals are written to a file and
  the employee is never told. Nothing errors.
- **`TRUSTED_PROXIES=127.0.0.1`** — without it, nginx is the only address PHP
  sees, so every punch records the proxy's IP instead of the employee's and the
  per-punch IP capture becomes a column of `127.0.0.1`. It also keys the login
  rate limiter, so untrusted the whole company shares one bucket and five wrong
  passwords anywhere locks everybody out. Read from `config/trustedproxy.php`;
  **before 2026-09-15 it was read in `bootstrap/app.php`, which runs before
  .env is loaded, so setting it had no effect at all.** If this box was
  configured before that date, do not assume it took — make a punch and read
  `attendance_logs.ip_address`.
- **`APP_URL`** — decides the scheme of every generated link, and is the URL
  both app stores will check for the privacy policy.

Keep `APP_KEY` somewhere other than the server. It encrypts sessions and every
encrypted column; regenerating it later logs everyone out and makes existing
encrypted values unreadable.

Then migrate:

```bash
sudo -u www-data php artisan migrate --force
```

Then create the company and the first administrator:

```bash
sudo -u www-data php artisan emp:install
```

It asks for the company name, timezone and currency, then the administrator's
name, email and password, shows you the lot, and creates it in one transaction —
so a failure halfway cannot leave you with a company nobody can sign in to.
It also seeds the roles and permissions, which is the step most often forgotten
when this is done by hand; without it the administrator exists but every page
refuses them, and nothing says why.

For an unattended deploy, pass everything instead:

```bash
sudo -u www-data php artisan emp:install --no-interaction \
    --company="Your Company" \
    --timezone=America/New_York \
    --currency=USD \
    --name="Your Name" \
    --email=you@yourcompany.com \
    --password='a-real-password'
```

The company timezone is the one to get right. Attendance is judged against shift
times in it, so a wrong value marks the entire workforce late every morning —
and produces plausible data rather than an error. The command validates it
against the real IANA list, and `emp:preflight` checks it again later.

### Adding an administrator to a company that already exists

If the install already has staff but no administrator — the account was lost, or
a purge removed the seeded one — attach the new administrator to the existing
company rather than making another:

```bash
sudo -u www-data php artisan emp:install --company-id=1
```

Run it without arguments and it lists the companies with their employee counts
and asks. Getting this wrong is unusually hard to spot: the sign-in works, the
dashboard loads, and it is empty — because every employee is on the other
company and companies cannot see each other. `--force` is what creates a second
company, and it exists only for installs that genuinely run two.

**Do not run `db:seed --class=DemoDataSeeder` on production.** It creates
`admin@emp.test` and `hr@emp.test`, both with the password `password`, plus a
set of fictional employees and a fortnight of attendance they never worked. A
plain `php artisan db:seed` is safe — it now creates roles and permissions only
— but if a demo seeder was ever run here by mistake, clear it out:

```bash
sudo -u www-data php artisan emp:purge-demo --dry-run   # read this first
sudo -u www-data php artisan emp:purge-demo --force
```

Read the dry run properly. Attendance and leave cascade from `employees`, so
deleting a demo employee takes any real rows attached to them as well — on a
server that was tested before go-live, the real punches are usually against a
demo account. The dry run lists those rows individually; `--keep-employees=CODE`
spares the employee they belong to.

---

## 5. nginx and TLS

```bash
sudo cp /var/www/emp-repo/deploy/nginx.conf.example /etc/nginx/sites-available/emp
sudo nano /etc/nginx/sites-available/emp      # server_name is already set; check the php-fpm socket
sudo ln -s /etc/nginx/sites-available/emp /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx

sudo certbot --nginx -d hrams.devonlinetestserver.com
```

The web root is `/var/www/emp/public`, never `/var/www/emp`. Pointing nginx at
the project directory serves `.env`, `storage/` and `vendor/` to anyone who
guesses a path — which is the database password and every backup.

Certbot installs its own renewal timer; confirm with `systemctl list-timers |
grep certbot`.

Leave the `Strict-Transport-Security` header commented out until the site is
confirmed working over https. Browsers remember HSTS per domain for its full
duration and it cannot be withdrawn quickly, so setting it early turns a
misconfiguration into a year-long one.

---

## 6. The scheduler

```bash
sudo cp /var/www/emp-repo/deploy/emp-scheduler.cron /etc/cron.d/emp
sudo nano /etc/cron.d/emp        # set MAILTO to a real inbox
sudo chmod 644 /etc/cron.d/emp
```

One line, every minute; Laravel decides internally what is due. Without it:

| Job | Cadence | What silently stops |
|---|---|---|
| `attendance:remind-checkin` | 5 min | Nobody is reminded their shift is about to start |
| `attendance:remind-checkout` | 15 min | Nobody is reminded they are still clocked in |
| `attendance:close-day` | hourly | Open punches are never closed; recorded hours stay wrong |
| `db:backup --verify` | 02:10 daily | There are no backups at all |
| `crashes:prune` | 03:20 daily | `crash_reports` grows for ever — the one table an unauthenticated caller can add to |

None of them announces its absence. `emp:preflight` infers the scheduler's
health from whether a backup actually appeared, which is the only end-to-end
evidence available.

---

## 7. The queue worker

```bash
sudo cp /var/www/emp-repo/deploy/emp-worker.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now emp-worker
sudo systemctl status emp-worker
```

Email and push are queued, so nothing is delivered without this. What makes it
easy to miss: the in-app notification bell writes directly and keeps working
perfectly, so the dashboard looks correct while no employee is being told
anything.

---

## 8. Verify

```bash
cd /var/www/emp
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache
sudo -u www-data php artisan emp:preflight
```

`emp:preflight` is the actual gate. It checks debug mode, the URL and TLS
settings, proxy trust, database and pending migrations, writable paths, mail,
whether anything is draining the queue, whether the scheduler has produced a
recent backup, the backup path and binaries, push coherence, company timezones,
the mobile app gate, whether any admin or HR account still uses the seeded
password `password`, and **known security advisories against the installed
packages**.

That last one runs `composer audit` inside the command. A **critical or high**
advisory fails the deploy; medium and low warn. It never fails because it could
not look — the advisory database is fetched over the network, and on a box with
no outbound access (or no composer on `PATH`) it warns and says which, rather
than turning "I could not check" into "you may not deploy". If you see that
warning on the live box, run `composer audit` somewhere with a network and read
the result before shipping.

It exits non-zero on a failure, which is why `deploy.sh` runs it last.

**The app gate is the check most likely to fail on a second deploy.**
`MOBILE_MAINTENANCE=true` shows every handset a "back shortly" screen while the
API carries on answering, which is what makes it useful during a migration and
what makes it easy to forget afterwards — nothing on the server complains, and
the only symptom is that nobody can clock in from their phone. Preflight treats
it as a failure for that reason. `MOBILE_MIN_VERSION` is the other half: leave
it empty unless you mean it, and never set it without both store links, which
preflight also refuses.

Then by hand:

- `https://hrams.devonlinetestserver.com` loads over TLS and the padlock is clean
- Log in as the administrator you created
- Check in and out once, and confirm the punch shows **your** IP rather than
  `127.0.0.1` — this is the proxy setting working
- Take a real backup: `sudo -u www-data php artisan db:backup --verify`
- Send a real email: submit a leave request and confirm it arrives
- `https://hrams.devonlinetestserver.com/privacy` and `/data-deletion` load while logged out

That last one matters for the next phase — both stores require a publicly
reachable privacy policy, checked while signed out.

---

## Deploying to managed webspace instead

Everything above assumes a server you have root on. This section is the
alternative for shared webspace — IONOS webspace, cPanel and the like — where
there is no root, no `apt`, no nginx of your own, no certbot and no systemd.

It works, and it is a real downgrade. Read the trade before choosing it.

**What you give up.** The queue stops being a daemon and becomes a cron entry,
so a notification that took a second to go out now takes up to five minutes.
Verified backups are the bigger loss: `db:backup --verify` proves a dump reads
back by restoring it into a scratch database, and a webspace account cannot
create one. The command already degrades to a warning rather than failing, so
you still get dumps — you simply stop getting the proof that they restore. For a
system whose attendance records payroll is calculated from, that is worth
weighing rather than waving through.

**What you do not give up.** The dashboard, the API, reports, exports and the
mobile app all behave identically. No feature is switched off.

### The two platform limits

| | |
|---|---|
| Minimum cron interval | 5 minutes |
| Maximum runtime per cron run | 60 seconds, then killed |

Both are worked around by `deploy/emp-webspace.cron`, which replaces **both**
`deploy/emp-scheduler.cron` and `deploy/emp-worker.service`.

The 5-minute floor costs nothing. Every task in `routes/console.php` already
falls on a 5-minute boundary — `everyFifteenMinutes()` at :00/:15/:30/:45,
`hourly()` at :00, and the five dailies at 01:30, 02:10, 03:20, 06:45 and
10:30 — so a
`*/5` cron fires `schedule:run` at every moment one of them is due. Nothing in
the schedule was changed to fit this host, and nothing should be: moving a task
off a 5-minute boundary would silently stop it running here while continuing to
work on a VPS, which is the worst kind of difference to debug.

The 60-second kill is why the queue entry carries `--max-time=50`. The worker
retires cleanly and the next run continues; without it the platform kills it
mid-job and the job stays reserved until it times out.

### Steps

Replace steps 1, 5, 6 and 7 above with the following. Steps 2, 3, 4, 8 and 9 are
unchanged apart from dropping `sudo`.

**1. There is nothing to install.** PHP and MySQL are provided. Confirm the CLI
binary and its version — it is often *not* the same PHP the web server uses:

```bash
ssh -p 22 su1962887@access-XXXXXXXXXX.webspace-host.com
which php8.3 || ls /usr/bin/php*
php8.3 -v && php8.3 -m | grep -E 'gd|zip|mbstring|intl|bcmath'
```

`gd` and `zip` are what the Excel and PDF exports need. If they are missing,
switch the PHP version in the hosting panel rather than trying to install them.

**2. The database** is created in the hosting panel, not with `CREATE DATABASE`.
Note the host it gives you — on webspace it is usually *not* `127.0.0.1` — and
put that in `DB_HOST`. Skip the `vrfy\_%` grant; you cannot use it here.

**5. TLS is issued from the panel**, not certbot. Point the document root of
`hrams.devonlinetestserver.com` at the `public/` directory of the checkout — the
subdomain is already created and aimed at the webspace, so this is the only
setting left on it. This is the one setting that
must be right: a document root at the project directory serves `.env` and
`storage/` to anyone who guesses the path.

**6 and 7. Cron replaces both the scheduler and the worker.** Add the two
entries from `deploy/emp-webspace.cron` through the panel's cron manager, and
confirm the mode in `.env` — `.env.production.example` already ships it set,
since this is the deployment in use:

```
HOSTING_MODE=managed
```

Without that line `emp:preflight` judges the install as though a daemon were
running and fails the queue check the moment a job waits more than five
minutes — which on a five-minute cron is simply Tuesday. With it, the tolerance
becomes fifteen minutes, which absorbs a missed run, and the remediation text
names the cron entry instead of telling you to run `systemctl` on a host that
has no systemd.

**8. Verify** exactly as above. `deploy.sh` detects that it is not running as
root and skips the `chown` and the `sudo` prefixes on its own, so it is:

```bash
bash deploy/deploy.sh
```

Preflight will still warn that backups are unverified. That warning is correct
and should stay visible — it is the standing reminder of what this host costs
you.

---

## 9. Shipping a new revision

```bash
cd /var/www/emp-repo
sudo bash deploy/deploy.sh
```

It takes a verified backup before migrating, puts the site in maintenance mode,
fast-forwards, installs, migrates, rebuilds caches, relinks storage, restarts the
worker, hands the files back to the web user and runs preflight — and brings the
site back up even if a step fails.

Nothing touches the database until the new code is on disk: the backup is taken
first, and a `mysqldump` that did not finish stops the deploy rather than being
trusted.

**It works out where it is.** The repository root and the application are the same
directory on the layout above, but on a panel host the site sits at `<domain>/` and
the application in a subdirectory, because the web root has to point at that
subdirectory's `public/`. The repository root, `hrms/` and `emp/` are all tried in
order — the subdirectory has carried both names — so the command is the same either
way and a rename does not break the deploy. The file
owner is read off the checkout rather than assumed to be `www-data` — deploying as
root otherwise leaves root-owned caches that PHP-FPM cannot write, which shows up
as a 500 with nothing in the log.

**It refuses to run against a non-production install**, because guessing wrong
means migrating the wrong database. For a genuine staging or demo box, say so:

```bash
sudo ALLOW_NON_PRODUCTION=1 bash deploy/deploy.sh
```

Preflight is then **selectively** advisory — `MAIL_MAILER=log` and a demo panel
are the point on a demo box, and failing on them would only teach people to skip
the script. Five checks are never downgraded, because none of them can be a
deliberate choice:

| Never advisory | Why |
|---|---|
| `APP_KEY` | Sessions and every encrypted value are broken without it. |
| `Database` | A box that cannot read its own data is an outage, not a staging box. |
| `Demo credentials` | An administrator still on the seeded `password`, on a URL strangers can reach. |
| `Dependency advisories` | A critical or high CVE in an installed package. |
| `TRUSTED_PROXIES` | A proxy is forwarding and is not trusted, so every punch is filing the proxy's address into the audit trail. |

The flag used to cover the whole command, so all five of these printed red and
the deploy finished anyway. If a staging deploy now stops where it used to pass,
that is this change and the failure is real — fix the `.env` rather than
reaching for a way around it.

---

## Ongoing

**Backups leave the server.** `db:backup --verify` proves the dump restores, but
it writes to the same machine. A server that dies takes its backups with it, so
copy `/var/backups/emp` off-box nightly — object storage, or `rsync` to another
host.

**The database dump is not the whole backup.** Uploaded files live on disk, not
in MySQL, and a restored database with no files behind it is a list of documents
that all 404:

- `storage/app/employee-documents/` — contracts, ID scans, right-to-work papers.
  Private, served only through the app. Losing these is a compliance problem,
  not an inconvenience.
- `storage/app/public/avatars/` — employee photos.

Back both up with the database dump, on the same schedule, to the same off-box
location. Restoring one without the other leaves the system quietly wrong.

**`public/storage` must exist.** The deploy script runs `storage:link`, but if
the symlink is ever lost every employee photo 404s with nothing in the log and
no error on screen — the pictures simply stop appearing. `emp:preflight` checks
for it.

**Watch these:**

```bash
tail -f /var/www/emp/storage/logs/laravel-$(date +%F).log
sudo journalctl -u emp-worker -f
php artisan queue:failed
```

**An uptime check on `/up`** — it needs no login and answers from the app rather
than nginx, so it fails if PHP or the database is down rather than only when the
whole box is.

---

## Then: the mobile app

With this done, Part C is complete and Stage 5 can start. The Flutter app has
what it needs:

- `https://hrams.devonlinetestserver.com/api/v1` — 25+ endpoints, documented in
  `API-Reference_v1.md`
- Sanctum token auth, per-user rate limits, one JSON error shape
- Push delivery server-side, waiting only on a Firebase project
- A public privacy policy and data-deletion page for the store listings

The app ships as `com.hrms.attendance` on both platforms — Android
`applicationId` and iOS `PRODUCT_BUNDLE_IDENTIFIER`. Firebase must be registered
against that exact string. The Android notification channel must be
`hrms_default` (`config/fcm.php`) — Android 8+ silently drops any notification
whose channel does not exist.

The app is finished, and has moved a long way since this section was first
written. Beyond login, punching, history, leave, the roster and the manager tab
it now carries the offline punch queue and offline cache, the biometric lock,
the force-update and maintenance gate, crash reporting to this server, the
notification centre, the first-run introduction, an audited accessibility pass,
and English and Spanish throughout.

What it still needs from a deployed host is a real `API_BASE`. A release build
**refuses to start** pointed at a development address — `assertSecureBaseUrl`
throws rather than letting a build ship that would hang on every screen:

```bash
flutter build appbundle --dart-define=API_BASE=https://hrams.devonlinetestserver.com/api/v1
flutter build ipa       --dart-define=API_BASE=https://hrams.devonlinetestserver.com/api/v1
```

Nothing else has to be run first. The app's translations are generated from
`mobile/lib/l10n/*.arb` by `flutter pub get` and by every build, so a fresh
clone needs no extra step.

Two server-side settings decide what a shipped handset does, and both are read
from `.env` by `GET /app/status` (B6.6):

- `MOBILE_MIN_VERSION` stops older builds at an update screen. Leave it empty
  until the replacement is actually live in both stores — the people it stops
  are the ones who clock in with it.
- `MOBILE_STORE_URL_ANDROID` / `MOBILE_STORE_URL_IOS` are where that screen
  sends them. `emp:preflight` refuses a minimum version with no link to go with
  it, because an update screen with a dead button can be neither dismissed nor
  acted on.

See `Store-Submission_Checklist.md` for what the two stores still want — the
screenshots and the data-safety forms both need this server up first, and both
listings should declare English *and* Spanish.

---

*Written 2026-08-03, and re-checked against the live codebase on 2026-09-11:
the `php8.3-*` package list still covers every extension `composer.lock`
requires, the `/var/www/emp` symlink layout matches what `deploy.sh` detects
and what the systemd unit and cron expect, and `artisan down --render` resolves
against the framework's own `errors::503`. Companion files live in `deploy/`.*
