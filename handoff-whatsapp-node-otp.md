# WhatsApp Node OTP — Handoff (reuse in any project)

Step-by-step guide for sending OTP through the **WhatsApp Node** service (Easypanel).  
Copy this file into another backend and follow the same contract.

**Node host (Ehkini):** `https://amctag-whats.38f0fz.easypanel.host`  
**Laravel service:** `app/Services/WhatsAppNodeCampaignOtpService.php`  
**Router:** `app/Services/OtpDeliveryService.php`  
**Config:** `config/otp.php`

---

## 1. Mental model

```
App / API
  → generate 6-digit code locally
  → POST to WhatsApp Node /api/otp/send
  → Node sends WhatsApp message via a connected client (clientId)
  → API stores encrypted otp_token (hash of code, not the raw code)
  → User enters code → API verifies against otp_token
```

Important:

- The **API generates the OTP**, not the Node.
- The **Node only delivers** the WhatsApp message.
- Verification is **local** (encrypted token + pepper), not a second call to the Node.

---

## 2. Prerequisites (Node side)

Before any send works:

1. WhatsApp Node service is deployed and reachable.
2. A WhatsApp **client is connected** (QR scanned / session online) in the Node dashboard.
3. You know that client’s **`clientId`** (Mongo/ObjectId-style string).
4. You have a JWT **`token`** from the Node auth (Bearer).

If the client is disconnected, Node returns **HTTP 503**:

```json
{
  "ok": false,
  "error": "No connected WhatsApp client available for OTP. Connect a client or set WHATSAPP_NODE_CLIENT_ID / OTP_DEFAULT_CLIENT_ID."
}
```

---

## 3. Environment variables

```env
OTP_WHATSAPP_NODE_ENABLED=true
WHATSAPP_NODE_URL=https://amctag-whats.38f0fz.easypanel.host
WHATSAPP_NODE_TOKEN=<jwt-from-node>
WHATSAPP_NODE_CLIENT_ID=<connected-client-id>
WHATSAPP_NODE_DELIVERY=otp
WHATSAPP_NODE_TIMEOUT=35
WHATSAPP_NODE_CONNECT_TIMEOUT=5
WHATSAPP_NODE_PHONE_FORMAT=DIGITS

# Used to sign otp_token (verify without storing OTP in DB)
OTP_PEPPER=<secret>          # or falls back to APP_KEY in Laravel
OTP_TTL_SECONDS=300
```

| Variable | Meaning |
|----------|---------|
| `WHATSAPP_NODE_URL` | Base URL of Node service (no trailing slash) |
| `WHATSAPP_NODE_TOKEN` | `Authorization: Bearer <token>` |
| `WHATSAPP_NODE_CLIENT_ID` | Which connected WhatsApp session sends the message |
| `WHATSAPP_NODE_DELIVERY` | `otp` (default) \| `send-campaign` \| `campaign` |
| `WHATSAPP_NODE_PHONE_FORMAT` | `DIGITS` = `96170357858` \| `E164` = `+96170357858` |

---

## 4. Step-by-step: send OTP (default `otp` delivery)

### Step A — App/API receives send request

Example: `POST /api/v2/register/send-otp` with:

```json
{
  "country_code": "+961",
  "phone": "70357858",
  "channel": "whatsapp"
}
```

`channel: "whatsapp"` or `"whatsapp_node"` → WhatsApp Node only.

### Step B — Normalize phone to E.164

```
country_code=+961 + phone=70357858  →  +96170357858
```

Then format for Node (`DIGITS`):

```
+96170357858  →  96170357858
```

### Step C — Generate code in the API

```text
code = random 6 digits   e.g. 570509
```

### Step D — Build WhatsApp message text (code already filled in)

Do **not** send the literal `{code}` placeholder to the Node if the Node does not substitute it.

```text
Your verification code for Ehkini App is 570509. Valid for 5 minutes. Do not share this code.
```

### Step E — Call the Node

```http
POST {WHATSAPP_NODE_URL}/api/otp/send
Authorization: Bearer {WHATSAPP_NODE_TOKEN}
Content-Type: application/json
Accept: application/json

{
  "phone": "96170357858",
  "code": "570509",
  "clientId": "b9b652c088dc73783d3973de",
  "message": "Your verification code for Ehkini App is 570509. Valid for 5 minutes. Do not share this code."
}
```

### Step F — Node response (success)

Expect something like:

```json
{
  "ok": true,
  "channel": "whatsapp_node",
  "expires_in": 300
}
```

On failure, Node may return `ok: false` + `error` (wrong client, disconnected session, bad phone, etc.).

### Step G — API returns `otp_token` to the mobile app

After Node success, API encrypts a payload (does **not** put raw code in the response):

```json
{
  "v": 1,
  "purpose": "register",
  "phone_e164": "+96170357858",
  "code_hash": "sha256(code|pepper)",
  "exp": 1710000000
}
```

→ encrypted string = `otp_token` (Laravel `Crypt::encryptString`).

API response to client:

```json
{
  "ok": true,
  "otp_token": "<encrypted>",
  "channel": "whatsapp_node",
  "expires_in": 300
}
```

User receives WhatsApp message with the real code.

---

## 5. Step-by-step: verify OTP

### Step A — Client sends

```json
{
  "country_code": "+961",
  "phone": "70357858",
  "otp_token": "<from send step>",
  "code": "570509"
}
```

### Step B — API verifies locally (no Node call)

1. Decrypt `otp_token`.
2. Check `purpose`, phone match, not expired.
3. Compare `sha256(code|pepper)` with stored `code_hash`.
4. On success → issue `verified_token` (or complete register / reset password).

---

## 6. Delivery modes (`WHATSAPP_NODE_DELIVERY`)

| Value | Node calls | When to use |
|-------|------------|-------------|
| `otp` (default) | `POST /api/otp/send` | Normal OTP |
| `send-campaign` | `POST /api/otp/send-campaign` | Same body as otp, alternate Node route |
| `campaign` | Create campaign → add contact → start | Legacy multi-step |

**Recommended:** `otp`.

Legacy `campaign` flow (only if Node requires it):

1. `POST /api/campaigns` `{ name, message, clientId }`
2. `POST /api/contacts/{campaignId}/add` `{ phone, code }`
3. `POST /api/campaigns/{campaignId}/start`

---

## 7. Minimal curl (any language / any project)

```bash
NODE_URL="https://amctag-whats.38f0fz.easypanel.host"
TOKEN="YOUR_JWT"
CLIENT_ID="YOUR_CONNECTED_CLIENT_ID"
PHONE="96170357858"   # DIGITS, no +
CODE="123456"
MSG="Your verification code for Ehkini App is ${CODE}. Valid for 5 minutes. Do not share this code."

curl -sS -X POST "$NODE_URL/api/otp/send" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d "{\"phone\":\"$PHONE\",\"code\":\"$CODE\",\"clientId\":\"$CLIENT_ID\",\"message\":\"$MSG\"}"
```

Laravel smoke test in this repo:

```bash
php artisan otp:test-whatsapp +961 70357858
php artisan otp:test-whatsapp +974 71022075
```

---

## 8. Pseudocode for another project

```text
function sendWhatsAppOtp(countryCode, phone, purpose):
  phoneE164 = normalizeToE164(countryCode, phone)
  phoneForNode = digitsOnly(phoneE164)          # if DIGITS format
  code = randomDigits(6)
  message = "Your verification code for Ehkini App is " + code
            + ". Valid for 5 minutes. Do not share this code."

  response = HTTP POST nodeUrl + "/api/otp/send"
    headers: Authorization Bearer token, Content-Type application/json
    body: { phone: phoneForNode, code, clientId, message }

  if not response.ok:
    return failure(response.error)

  otp_token = encrypt({
    purpose,
    phone_e164: phoneE164,
    code_hash: sha256(code + "|" + pepper),
    exp: now + ttl
  })

  return { ok: true, otp_token, channel: "whatsapp_node", expires_in: ttl }


function verifyWhatsAppOtp(otp_token, purpose, countryCode, phone, code):
  payload = decrypt(otp_token)
  assert payload.purpose == purpose
  assert phonesMatch(payload.phone_e164, e164(countryCode, phone))
  assert payload.exp >= now
  assert sha256(code + "|" + pepper) == payload.code_hash
  return success
```

---

## 9. Files to copy / mirror from this repo

| File | Role |
|------|------|
| `app/Services/WhatsAppNodeCampaignOtpService.php` | Node HTTP + token crypto + message text |
| `app/Services/OtpDeliveryService.php` | Channel routing (whatsapp vs sms) |
| `config/otp.php` | Env → config map |
| `app/Console/Commands/TestWhatsAppOtpCommand.php` | `otp:test-whatsapp` |
| `.env` keys listed in §3 | Secrets + client id |

---

## 10. Common failures

| Symptom | Cause | Fix |
|---------|--------|-----|
| `node_not_configured` | Missing URL / token / clientId | Set all three env vars |
| HTTP 503 “No connected WhatsApp client” | Session offline or wrong `CLIENT_ID` | Connect client in Node UI; use that id |
| HTTP 401/403 | Bad JWT | Regenerate `WHATSAPP_NODE_TOKEN` |
| Message shows `{code}` literally | Placeholder not replaced | Send **filled** message (real digits) |
| Verify fails after redeploy | Pepper changed | Keep `OTP_PEPPER` (or `APP_KEY`) stable |
| Wrong number format | Leading 0 / missing country | Use national mobile + country code; Node gets DIGITS |

---

## 11. Security notes

- Never log full OTP codes or JWT tokens in production logs.
- Never return the raw OTP in the HTTP API response.
- `otp_token` is enough for the client; code arrives only via WhatsApp.
- Rotate Node JWT if leaked; treat `.env` as secret.

---

## 12. Quick checklist (new project)

- [ ] Node URL reachable
- [ ] WhatsApp client connected + correct `clientId`
- [ ] Bearer token set
- [ ] Phone format agreed (`DIGITS` recommended)
- [ ] Message text includes **real code**, not `{code}`
- [ ] Local `otp_token` encrypt/verify with stable pepper
- [ ] Smoke test: send to a real phone, verify code works
