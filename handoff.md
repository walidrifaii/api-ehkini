# api-ehkini — Developer Handoff

> Laravel **10** / PHP **^8.2** REST API for **Ehkini** (mobile client: sibling `ehkini/TaarufApp`).  
> Auth: **Laravel Sanctum** Bearer tokens.  
> Production host (Easypanel): `https://amctag-ehkini.38f0fz.easypanel.host`  
> Prefer **`/api/v2/*`** for all new work (Flutter `ApiConfig` points at v2).

`.env.example` is the source of truth for env vars — copy it, then fill secrets (never commit `.env`).

---

## 1. Repo snapshot (as of handoff)

| Item | Value |
|------|--------|
| Local path | `ehkini/api-ehkini` |
| Branch | `main` (tracks `origin/main`, clean) |
| Recent focus | Face ID auth (v3 routes, duplicate-face blocking) + earlier OTP Lebanon UnoSMS fixes |
| Mobile client | `ehkini/TaarufApp` → `lib/config/api_config.dart` |

---

## 2. Quick start (local)

```bash
cd api-ehkini
cp .env.example .env
composer install
php artisan key:generate

# Configure MySQL in .env (DB_*), then:
php artisan migrate
# optional seeds / translation SQL from TaarufApp root insert_*.sql if needed

php artisan serve   # http://127.0.0.1:8000
```

Set `APP_URL=http://127.0.0.1:8000` locally so generated media/Swagger links match.

### Docker / Easypanel

- `Dockerfile` — PHP 8.2 CLI Alpine, Composer, ffmpeg, `artisan serve` on `${PORT:-8000}`
- `docker/entrypoint.sh` — storage link + config/route/view cache when `APP_KEY` is set
- `nixpacks.toml` — alternate deploy path

On production: set **exact** public `APP_URL` (https, no trailing slash). Wrong `APP_URL` breaks image URLs returned by the API.

---

## 3. API versions

Routes live in `routes/api.php`, mounted under prefix `api` (`RouteServiceProvider`).

| Version | Prefix | Notes |
|---------|--------|--------|
| **v1** | `/api/v1/...` | Shared route closure; controllers in `App\Http\Controllers\Api\V1` |
| **v2** | `/api/v2/...` | Same shared routes + **v2-only** extras; controllers in `Api\V2` (many thin `extends` of V1) |

### V2-only (not on v1)

**Public**

- `GET /api/v2/countries`
- `POST /api/v2/register/send-otp`
- `POST /api/v2/register/verify-otp`
- `POST /api/v2/register/complete`

**Auth (Sanctum)**

- Discover / nearby: `GET .../users/discover-by-country`, `GET .../users/nearby`
- Search prefs: `GET|DELETE .../users/search/last`, `POST|DELETE .../users/search/click`

### Auth

- Protected routes: `middleware('auth:sanctum')`
- Locale per request: header `X-App-Language` or `?lang=` (see `.env.example`; not persisted as the user’s only language source for API errors)

### Docs (Swagger)

- L5-Swagger: OpenAPI under `app/OpenApi/V2/`, generated JSON in `storage/api-docs/`
- UI typically at `/api/documentation`
- Regenerate: `php artisan l5-swagger:generate`

---

## 4. Domain map (where code lives)

| Domain | Controllers (V2) | Services / notes |
|--------|------------------|------------------|
| Auth / profile / OTP | `AuthController` | `OtpDeliveryService`, UnoSMS / Message Central / WhatsApp node |
| Users / discover / nearby | `UserController`, `CountryController` | `NearbyUsersService`, `UserLocationService`, `DiscoverableUsersQuery` |
| Friends | `FriendshipController` | Model `Friendship` |
| Posts / stories | `PostController`, `StoryController` | Cron: `php artisan stories:expire` |
| Gifts / wallet | `Gift*`, `WalletController` | Models `Gift`, `GiftCategory`, `GiftTransaction`, `UserWallet` |
| Safety | `UserSafetyController` | block / unblock / report |
| Media uploads | `MediaController`, `VoiceController` | `RemoteUploadService`, `ImageKitService`, `ImageCompressionService` |
| Push / calls notify | `*NotificationController`, FCM services | `FcmService`, `FcmTokenService` |
| Agora RTC token | `AgoraTokenController` | Env: `AGORA_APP_ID`, `AGORA_APP_CERTIFICATE`, `AGORA_TOKEN_EXPIRE_SECONDS` |
| i18n | `TranslationController`, `LanguageController` | DB `languages` + `translation_*`; also `lang/{en,ar,fr}/api.php` for server messages |
| Misc | `PageController`, `AppVersionController`, `SupportContactController`, `InterestController` | |

**Chat content itself is not stored here** — Firestore is on the mobile/Firebase side. This API does FCM notify helpers (`/chat/notify`, `/call/notify`, `/call/end`) and user/social/wallet data.

---

## 5. Critical env groups

Fill from `.env.example`. Secrets must exist on Easypanel (or local `.env`), not in git.

| Group | Keys / purpose |
|-------|----------------|
| **App** | `APP_KEY`, `APP_URL`, `FORCE_HTTPS`, `APP_DEBUG` |
| **DB** | MySQL `DB_*` |
| **Media** | `MEDIA_DISK` (default `remote`), `UPLOAD_*`, optional ImageKit / legacy CDN — see [`handoff-image-upload.md`](./handoff-image-upload.md) |
| **Mail / support** | SMTP + `SUPPORT_ADMIN_EMAIL` |
| **FCM** | `FCM_PROJECT_ID` (e.g. `taaruf-f15c3`), credentials via JSON / base64 / file — then `php artisan fcm:check` |
| **OTP** | Channel routing + WhatsApp node + Message Central + **Lebanon UnoSMS** (`OTP_UNOSMS_*`, country `961`) |
| **Agora** | `AGORA_APP_ID`, `AGORA_APP_CERTIFICATE` (required for `/agora/token`) |
| **Face ID** | `FACE_AI_SERVICE_URL`, `FACE_AI_API_KEY`, `FACE_SIMILARITY_THRESHOLD`, `FACE_DUPLICATE_THRESHOLD`, face login rate-limit vars |
| **Swagger** | `L5_SWAGGER_*` tied to `APP_URL` |

### OTP routing (mental model)

```
WhatsApp channel → WhatsApp node (WHATSAPP_NODE_*)
SMS + country 961 → UnoSMS
SMS + other countries → Message Central
```

**WhatsApp Node OTP (step-by-step for reuse in other projects):** see [`handoff-whatsapp-node-otp.md`](./handoff-whatsapp-node-otp.md).

Pepper: `OTP_PEPPER` or fallback **`APP_KEY`** (important for Lebanon verify consistency across deploys).

Useful artisan commands:

| Command | Use |
|---------|-----|
| `php artisan fcm:check` | Validate FCM credentials |
| `php artisan fcm:env-json {path}` | Emit `.env` JSON lines from service account file |
| `php artisan fcm:env-base64 {path}` | Base64 variant for long Easypanel env values |
| `php artisan otp:test-sms` / `otp:test-whatsapp` | Delivery smoke tests |
| `php artisan sms:send` | Test SMS |
| `php artisan stories:expire` | Expire stories (schedule this in production) |
| `php artisan media:backfill-full-urls` | Migrate relative media paths to full URLs |

---

## 6. Project layout (high signal)

```
app/
  Http/Controllers/Api/V1|V2/   # Route handlers (V2 preferred)
  Services/                     # OTP, FCM, media, nearby, ...
  Services/Agora/               # RTC token builders
  Models/                       # User, Friendship, Story, Gift, Wallet, ...
  OpenApi/V2/                   # Swagger path/schema annotations
  Support/                      # MediaStorage, ApiLocale, GeoDistance
  Query/                        # DiscoverableUsersQuery
config/
  otp.php, media.php, services.php (FCM), locales.php, l5-swagger.php, ...
routes/api.php                  # All versioned HTTP API
database/migrations/
lang/en|ar|fr/api.php           # API error / message strings
docker/, Dockerfile             # Deploy
Tools-master/                   # Upstream Agora sample toolkit (not app runtime)
```

---

## 7. Integrations checklist

| System | Responsibility |
|--------|----------------|
| MySQL | Source of truth for users, friends, gifts, wallet, translations, pages, and encrypted face embeddings (`user_face_embeddings`) |
| Remote upload / ImageKit | Profile/post/story/gift images & video |
| FCM | Push for chat/call/gift (service account required) |
| Agora | Token mint for voice/video (`GET /api/v2/agora/token`) |
| WhatsApp node | OTP via WA (separate Easypanel service) |
| UnoSMS / Message Central | SMS OTP |
| Face AI service (`face-ai-service`) | Embedding extraction / verify / challenge endpoints consumed by Laravel v3 face auth |
| Firebase client | Chat + presence live in the Flutter app, not this DB |

Mobile ↔ API contract notes also live in `TaarufApp/handoff.md` and `TaarufApp/APP_DOCUMENTATION.md`.

---

## 8. Gotchas

1. **v1 vs v2** — Same most endpoints, but discover/nearby/search-history/OTP register complete are v2-only. Don’t “fix” mobile by only patching v1.
2. **`APP_URL`** — Must match the public HTTPS host in prod or media links break.
3. **OTP pepper** — Changing `APP_KEY` / `OTP_PEPPER` invalidates in-flight OTP hashes.
4. **Lebanon SMS** — Use national phone format + UnoSMS; verify path recently fixed for normalization/order.
5. **Agora env missing** — `/agora/token` returns a clear “missing Agora env” error; set both ID + certificate.
6. **FCM** — After env change: `php artisan config:clear` then `fcm:check`.
7. **Public notify/upload routes** — Several chat/call/media endpoints are outside `auth:sanctum` in `routes/api.php`; treat as sensitive when hardening.
8. **`Tools-master/`** — Vendor sample tree for Agora; not required to run the API.
9. **Face auth is v3-only** — mobile Face ID endpoints use `/api/v3/*`; don’t accidentally patch only v1/v2 auth routes.
10. **Duplicate-face blocking is server-side** — if the same face still registers on another account, first confirm the latest Laravel deploy is live, then run `php artisan config:clear` so `FACE_DUPLICATE_THRESHOLD` and new config are loaded.

---

## 9. Face ID handoff notes

Current face auth flow lives in `app/Http/Controllers/Api/V3/FaceController.php` and `app/Services/FaceRecognitionService.php`.

### Current behavior

- Registration: `POST /api/v3/register-face`
- Login: `POST /api/v3/login-face`
- Challenge/status helpers: `/api/v3/face/*`
- Embeddings are stored encrypted in MySQL table `user_face_embeddings`
- Flutter never calls the Python service directly; Laravel proxies all face operations

### Duplicate-face issue and current fix

The product requirement is **one face per account** because Face ID login is now face-only (no phone number during login). A duplicate-face guard was added on registration:

- collect the embeddings returned from all captured registration images
- compare them against all other saved embeddings
- reject with HTTP `409` and code `face_already_registered` when similarity is above the duplicate threshold

Current knobs:

- `FACE_SIMILARITY_THRESHOLD` — login threshold (default `0.80`)
- `FACE_DUPLICATE_THRESHOLD` — duplicate-register threshold (currently defaults/falls back to `FACE_SIMILARITY_THRESHOLD`)

### Important troubleshooting note

User reported that the same face **still registered on another account** after the first duplicate-block patch. The likely causes were:

1. Laravel deploy/cache not refreshed
2. duplicate threshold too strict
3. only one captured registration embedding being checked
4. stored embedding retrieval needing safer raw decryption

Follow-up changes were applied to address that:

- duplicate check now evaluates **all captured registration embeddings**
- duplicate threshold now falls back to the login threshold (`0.80`)
- `UserFaceEmbedding::getEmbeddingVector()` now reads the raw encrypted DB value and handles decrypt failures safely
- extra logging was added around duplicate checks / empty stored embeddings

If the issue reappears in production, inspect Laravel logs for:

- `face_register.duplicate_blocked`
- `face_register.duplicate_check_passed`
- `face_register.empty_stored_embedding`

---

## 10. First-day checklist for a new owner

1. Copy `.env.example` → `.env`; set DB, `APP_URL`, FCM, OTP, Agora, media upload.
2. `composer install` → `migrate` → `artisan serve`.
3. Open Swagger (`/api/documentation`) or hit `GET /api/v2/app/version`.
4. Smoke: register OTP (or login) → `/me` with Bearer → friends → `/agora/token` → wallet balance.
5. `php artisan fcm:check` and one OTP test command for your target country/channel.
6. Confirm Easypanel env parity with local (especially OTP + Agora + `APP_URL`).
7. Schedule `stories:expire` if not already cron’d.

---

## 11. Related

| Path | Why |
|------|-----|
| `.env.example` | Full env documentation |
| `routes/api.php` | Canonical route list |
| `config/otp.php` / `config/media.php` / `config/services.php` | Integration knobs |
| `../TaarufApp/handoff.md` | Mobile client handoff |
| `../TaarufApp/lib/config/api_config.dart` | Client endpoint constants |

---

*Handoff for `api-ehkini`. Update when OTP providers, media disk, or v2-only routes change.*
