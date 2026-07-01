# Web Push (VAPID) + Real-Time (Reverb) — Setup & Run

Standard Web Push protocol (Web Push API + Service Worker + Push API + VAPID).
**No Firebase.** Real-time Notification Center via **Laravel Reverb** (no polling).

## 1. One-time setup

### VAPID keys
```bash
php artisan webpush:vapid
```
Copy the printed lines into the backend `.env`:
```
VAPID_SUBJECT=mailto:admin@alpha.com
VAPID_PUBLIC_KEY=...
VAPID_PRIVATE_KEY=...
```
The Angular app fetches the public key from `GET /api/notifications/vapid-key` —
nothing to paste on the frontend.

### Reverb (broadcasting)
Already configured. Ensure `.env` has:
```
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=...    REVERB_APP_KEY=...    REVERB_APP_SECRET=...
REVERB_HOST=127.0.0.1   REVERB_PORT=8080   REVERB_SCHEME=http
REVERB_SERVER_HOST=0.0.0.0   REVERB_SERVER_PORT=8080
```
The Angular `src/app/enviroment.ts` → `reverb.key` must equal `REVERB_APP_KEY`.

### Migrate
```bash
php artisan migrate     # creates push_subscriptions
```

### Windows note (OpenSSL)
Web Push payload encryption uses EC keys via PHP OpenSSL, which needs an
`openssl.cnf`. The app auto-points `OPENSSL_CONF` to `config/openssl-min.cnf`
when unset. If you still see `configuration file routines::no such file`, export
`OPENSSL_CONF` to a config file at a path **without spaces** *before* starting PHP
(PHP reads it at process start):
```powershell
$env:OPENSSL_CONF = "C:\Users\<you>\AppData\Local\Temp\openssl.cnf"
```
(Linux/macOS need nothing — the system openssl.cnf is used.)

## 2. Run (3 processes)

```bash
php artisan serve              # API        :8000
php artisan reverb:start       # websocket  :8080   (real-time)
# frontend:
npm start                      # Angular    :4200
```

No queue worker is required (the broadcast event is `ShouldBroadcastNow`).

## 3. How it works

| Piece | Location |
|-------|----------|
| VAPID config | `config/webpush.php`, `.env` |
| Subscription storage | `push_subscriptions` table, `App\Models\PushSubscription` |
| Subscribe / Unsubscribe API | `POST /api/notifications/subscribe`, `POST /api/notifications/unsubscribe` |
| VAPID public key API | `GET /api/notifications/vapid-key` |
| Send service | `App\Modules\Convention\Services\WebPushService` (minishlink/web-push) |
| Trigger | `ConventionService::syncLowBalanceNotification()` — manager withdrawal, balance ≤ 100, once per cycle |
| Real-time event | `App\Events\NotificationCreated` → private channel `notifications.{userId}` |
| Channel auth | `routes/channels.php` + `/api/broadcasting/auth` (JWT api guard) |
| Frontend SW | `public/sw.js` (push + notificationclick) |
| Frontend subscribe | `src/app/services/push.service.ts` (after login) |
| Frontend real-time | `src/app/services/realtime.service.ts` (laravel-echo + Reverb) |

## 4. Test

```bash
php artisan push:test           # sends a sample push to all admins
php artisan push:test --user=1  # to a specific user
```
Or click **تجربة** in the notification bell dropdown (admin), after allowing
notifications.

## 5. Browser support
Chrome, Edge, Firefox (modern). Requires `https` or `http://localhost`. The user
must grant notification permission for push delivery; the Notification Center +
real-time updates work regardless.
