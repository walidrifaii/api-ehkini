# Image / Media Upload — Handoff (reuse in any project)

Step-by-step guide for how this API uploads images (and video/voice) to a **remote upload server**, then returns a public URL.  
Copy this pattern into another backend and keep the same HTTP contract.

**Remote host (Ehkini default):** `https://st79068.ispot.cc`  
**Upload endpoint:** `https://st79068.ispot.cc/upload.php`  
**Laravel facade:** `app/Support/MediaStorage.php`  
**HTTP client:** `app/Services/RemoteUploadService.php`  
**Optional compress:** `app/Services/ImageCompressionService.php`  
**Config:** `config/media.php`  
**API routes:** `POST /api/v2/media/image/upload`, `POST /api/v2/media/video/upload`

---

## 1. Mental model

```
App / API
  → receive multipart file from client
  → (optional) compress / resize image
  → POST multipart to remote upload.php
  → remote returns relative path: "image/<name>.jpg"
  → API builds public URL: https://st79068.ispot.cc/images/<name>.jpg
  → store full URL (or relative path) in DB
  → return { path, url } to client
```

Important:

- Files are **not** stored on the Laravel API disk by default (`MEDIA_DISK=remote`).
- The **remote `upload.php`** stores the file and serves it publicly.
- Laravel only **proxies the upload** and builds the public URL.
- Legacy alternative: `MEDIA_DISK=imagekit` (ImageKit CDN).

---

## 2. What is used

| Piece | Role |
|-------|------|
| `MEDIA_DISK=remote` | Default storage mode |
| `UPLOAD_ENDPOINT` | `POST` target (`upload.php`) |
| `UPLOAD_API_TOKEN` | Auth (Bearer header **and** form field `token`) |
| `UPLOAD_PUBLIC_BASE` | Host root for public URLs |
| `MediaStorage` | Single entry point: store / url / delete |
| `RemoteUploadService` | Actual HTTP upload to `upload.php` |
| `ImageCompressionService` | Resize + JPEG encode (profiles, posts, stories) |
| Intervention Image (GD) | Used by compression service |

Composer packages involved: `intervention/image`, optional `imagekit/imagekit` if you switch disk.

---

## 3. Environment variables

```env
MEDIA_DISK=remote
UPLOAD_ENDPOINT=https://st79068.ispot.cc/upload.php
UPLOAD_API_TOKEN=<secret-token>
UPLOAD_PUBLIC_BASE=https://st79068.ispot.cc

# Prefer full https URLs in DB columns (profile_image, posts.image, …)
MEDIA_STORE_FULL_URL_IN_DB=true
```

| Variable | Meaning |
|----------|---------|
| `MEDIA_DISK` | `remote` (default) \| `imagekit` \| `public` \| `ftp` |
| `UPLOAD_ENDPOINT` | Full URL of `upload.php` |
| `UPLOAD_API_TOKEN` | Shared secret with the upload server |
| `UPLOAD_PUBLIC_BASE` | Base URL where files are served (no trailing slash) |
| `MEDIA_STORE_FULL_URL_IN_DB` | `true` = save `https://…` in DB, not only `images/x.jpg` |

---

## 4. Remote upload HTTP contract (reuse anywhere)

### Request

```http
POST https://st79068.ispot.cc/upload.php
Authorization: Bearer <UPLOAD_API_TOKEN>
Content-Type: multipart/form-data

file=<binary>
folder=images          # optional; e.g. images | videos | voices | profiles
token=<UPLOAD_API_TOKEN>   # also sent as form field
```

Field name for the file **must** be `file`.

### Success response

```json
{
  "success": true,
  "path": "image/uuid-or-name.jpg",
  "category": "image"
}
```

Note: the API returns a **singular** category in `path` (`image/…`, `video/…`, `voice/…`).

### Public URL mapping

| Returned path | Public URL |
|---------------|------------|
| `image/x.jpg` | `{UPLOAD_PUBLIC_BASE}/images/x.jpg` |
| `video/x.mp4` | `{UPLOAD_PUBLIC_BASE}/videos/x.mp4` |
| `voice/x.m4a` | `{UPLOAD_PUBLIC_BASE}/voice/x.m4a` |

Singular → plural for image/video; voice stays singular.

### Allowed MIME types (send explicitly)

The upload server validates the declared Content-Type. Set it from the extension:

| Ext | Content-Type |
|-----|----------------|
| jpg/jpeg | `image/jpeg` |
| png | `image/png` |
| webp | `image/webp` |
| gif | `image/gif` |
| mp4 | `video/mp4` |
| mov | `video/quicktime` |
| webm | `video/webm` |
| mp3 | `audio/mpeg` |
| m4a / aac | `audio/mp4` (not `video/mp4`) |
| wav | `audio/wav` |
| ogg | `audio/ogg` |

---

## 5. Minimal reuse (any language / no Laravel)

### cURL

```bash
curl -X POST "https://st79068.ispot.cc/upload.php" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -F "token=YOUR_TOKEN" \
  -F "folder=images" \
  -F "file=@./photo.jpg;type=image/jpeg"
```

### Node.js (fetch + FormData)

```js
import fs from "fs";
import FormData from "form-data"; // or built-in FormData + Blob in Node 20+

const endpoint = process.env.UPLOAD_ENDPOINT;
const token = process.env.UPLOAD_API_TOKEN;
const publicBase = process.env.UPLOAD_PUBLIC_BASE; // https://st79068.ispot.cc

async function uploadImage(filePath, folder = "images") {
  const form = new FormData();
  form.append("token", token);
  form.append("folder", folder);
  form.append("file", fs.createReadStream(filePath));

  const res = await fetch(endpoint, {
    method: "POST",
    headers: { Authorization: `Bearer ${token}`, ...form.getHeaders() },
    body: form,
  });

  const json = await res.json();
  if (!res.ok || !json.success || !json.path) {
    throw new Error(json.error || json.message || "Upload failed");
  }

  // image/x.jpg → https://host/images/x.jpg
  const [cat, name] = json.path.replace(/^\//, "").split("/", 2);
  const dir = { image: "images", video: "videos", voice: "voice" }[cat] || cat;
  const url = `${publicBase}/${dir}/${name}`;

  return { path: json.path, url };
}
```

### PHP (plain, without Laravel)

```php
$endpoint = getenv('UPLOAD_ENDPOINT');
$token = getenv('UPLOAD_API_TOKEN');
$publicBase = rtrim(getenv('UPLOAD_PUBLIC_BASE'), '/');

$cfile = new CURLFile($localPath, 'image/jpeg', basename($localPath));
$ch = curl_init($endpoint);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}"],
    CURLOPT_POSTFIELDS => [
        'token' => $token,
        'folder' => 'images',
        'file' => $cfile,
    ],
]);
$body = curl_exec($ch);
$json = json_decode($body, true);
// Build URL: image/x.jpg → {$publicBase}/images/x.jpg
```

---

## 6. How this Laravel API uses it

### Direct media endpoints

```http
POST /api/v2/media/image/upload
Content-Type: multipart/form-data
Authorization: Bearer <sanctum-token>   # if route is behind auth

image=<file>   # field name is "image" on the API (not "file")
```

Validation: jpg/jpeg/png/webp, max **5 MB**.

Response:

```json
{
  "success": true,
  "type": "image",
  "file_name": "uuid.jpg",
  "path": "https://st79068.ispot.cc/images/uuid.jpg",
  "url": "https://st79068.ispot.cc/images/uuid.jpg",
  "size": 12345,
  "mime": "image/jpeg"
}
```

Video: `POST /api/v2/media/video/upload` with field `video` (max 50 MB).

### Inside controllers (profiles / posts / stories)

Prefer compression for photos:

```php
$path = app(ImageCompressionService::class)->storeCompressedJpeg(
    $file,
    MediaStorage::diskName(),
    'profiles',                              // folder
    ImageCompressionService::PROFILE_MAX_SIDE // 1024
);

$url = MediaStorage::url($path);
```

Or raw store (no resize):

```php
$path = MediaStorage::storeUploadedFile($file, 'images', $filename);
$url = MediaStorage::url($path);
```

`MediaStorage` picks remote / ImageKit / local from `MEDIA_DISK`.

---

## 7. Files to copy / mirror from this repo

| File | Role |
|------|------|
| `app/Support/MediaStorage.php` | Disk switch + URL + DB value helpers |
| `app/Services/RemoteUploadService.php` | Multipart POST to `upload.php` |
| `app/Services/ImageCompressionService.php` | Optional resize → JPEG |
| `app/Services/ImageKitService.php` | Only if using ImageKit |
| `config/media.php` | Env → config map |
| `app/Http/Controllers/Api/V1/MediaController.php` | Public upload API |
| `.env` keys in §3 | Endpoint + token + public base |

---

## 8. ImageKit alternative (optional)

```env
MEDIA_DISK=imagekit
IMAGEKIT_PUBLIC_KEY=...
IMAGEKIT_PRIVATE_KEY=...
IMAGEKIT_URL_ENDPOINT=https://ik.imagekit.io/your_id
IMAGEKIT_FOLDER_PREFIX=ehkini
```

Same `MediaStorage::storeUploadedFile()` / `url()` API; only the backend changes.

---

## 9. Common failures

| Symptom | Cause | Fix |
|---------|--------|-----|
| `Remote upload endpoint is not configured` | Missing `UPLOAD_ENDPOINT` | Set env |
| 401 / auth error from upload.php | Bad token | Fix `UPLOAD_API_TOKEN` |
| Upload rejected (MIME) | Wrong Content-Type (e.g. m4a as video/mp4) | Map extension → allowed MIME |
| Broken image links in app | Wrong `UPLOAD_PUBLIC_BASE` or `APP_URL` | Public base must match where files are served |
| Relative path in DB, client needs full URL | `MEDIA_STORE_FULL_URL_IN_DB=false` | Set `true`, or always call `MediaStorage::url()` |
| Large upload fails | PHP / proxy limits | Raise `upload_max_filesize` / nginx body size |

---

## 10. Security notes

- Treat `UPLOAD_API_TOKEN` as a secret; never ship it in mobile apps.
- Mobile/clients should upload **through your API**, not directly to `upload.php`, unless the token is short-lived / scoped.
- Remote delete is **not** implemented (`RemoteUploadService::delete` is a no-op).
- Validate type + size on your API before forwarding (see `MediaController`).

---

## 11. Quick checklist (new project)

- [ ] `UPLOAD_ENDPOINT` reachable
- [ ] `UPLOAD_API_TOKEN` matches the upload server
- [ ] `UPLOAD_PUBLIC_BASE` is the public host (no trailing slash)
- [ ] Multipart field name is `file` toward upload.php
- [ ] Correct MIME by extension
- [ ] Map `image/` → `/images/` when building public URLs
- [ ] Smoke test: upload a JPG, open the returned URL in a browser
