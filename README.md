# vidiQ — 3q.video Integration for Statamic

[![Latest Version on Packagist](https://img.shields.io/packagist/v/takepart-media/vidiq.svg?style=flat-square)](https://packagist.org/packages/takepart-media/vidiq)
[![License](https://img.shields.io/packagist/l/takepart-media/vidiq.svg?style=flat-square)](https://packagist.org/packages/takepart-media/vidiq)
[![PHP Version](https://img.shields.io/packagist/php-v/takepart-media/vidiq.svg?style=flat-square)](https://packagist.org/packages/takepart-media/vidiq)
[![Statamic](https://img.shields.io/badge/Statamic-5.x-FF269E?style=flat-square)](https://statamic.com)

A Statamic addon that integrates the [3q.video](https://3q.video) hosting platform into the Statamic Control Panel asset
browser. Videos hosted on 3q appear as browsable, deletable assets with thumbnail previews. A Blade component is
provided for embedding videos in frontend templates.

This addon has only been tested with **Statamic 5** and **3Q's SDN API v2**.

## Features

- **Asset browser integration** — 3Q videos appear in Statamic's CP file browser with thumbnail previews
- **Read-only (for now)** — browse videos directly from the CP; write operations (upload, delete, rename, move) are
  currently unsupported — manage content in 3Q's own dashboard. Full write support may be added in a future release.
- **Flysystem v3 adapter** — custom `ThreeQAdapter` implements the Flysystem interface backed by the 3Q SDN API
- **Embed codes** — fetches the full `FileEmbedCodes` array per video via the 3Q playout API (cached)
- **Virtual meta files** — generates `.meta/` YAML on the fly so Statamic stores title, thumbnail URL and video ID
  without writing to disk
- **Blade component** — `<x-vidiq::embed>` for embedding the 3Q player in frontend templates

## Requirements

- PHP 8.2+
- Statamic 5.x
- GuzzleHTTP 7.x
- A [3q.video](https://3q.video) account with API access

## Installation

Install the package via Composer:

```bash
composer require takepart-media/vidiq
```

Statamic will auto-discover the addon's service provider. No additional registration steps are needed.

### Environment variables

Add the following to your `.env` file:

```env
VIDIQ_API_TOKEN=your-api-token-here
VIDIQ_PROJECT_ID=your-project-id-here

# Optional — defaults shown
VIDIQ_API_ENDPOINT=https://sdn.3qsdn.com/api
VIDIQ_API_TIMEOUT=30
```

### Asset container

Create `content/assets/vidiq.yaml` in your Statamic project:

```yaml
title: '3Q Videos'
disk: 3q
allow_uploads: false
allow_downloading: false
allow_renaming: false
allow_moving: false
create_folders: false
```

Statamic will display this container as **"3Q Videos"** in the CP under **Assets**.

## How It Works

### Flysystem adapter (`ThreeQAdapter`)

The adapter connects to the 3Q SDN REST API and implements `League\Flysystem\FilesystemAdapter`:

| Operation                                            | Behaviour                                                                                                                       |
|------------------------------------------------------|---------------------------------------------------------------------------------------------------------------------------------|
| `listContents`                                       | Fetches all files from `GET /v2/projects/{id}/files` and yields `FileAttributes`. Listing is cached for 10 minutes.             |
| `fileExists`                                         | Resolved from the listing cache.                                                                                                |
| `read` / `readStream`                                | For `.meta/` paths: returns generated YAML. For asset paths: streams the video thumbnail from 3Q for Statamic/Glide to cache.   |
| `delete`                                             | Accepted only for `.meta/` paths (no-op, virtual files have no remote counterpart). All other paths throw `UnableToDeleteFile`. |
| `getUrl`                                             | Calls `GET /v2/projects/{id}/files/{fileId}/playouts/default/embed` and returns the full `FileEmbedCodes` array (e.g. `JavaScript`, `PlayerURL`). Cached per file ID via `VIDIQ_CACHE_TTL`. |
| `write` / `writeStream`                              | Accepted only for `.meta/` paths (Statamic metadata edits stored in Laravel cache). All other writes throw `UnableToWriteFile`. |
| `move`, `copy`, `createDirectory`, `deleteDirectory` | Not supported; throw the corresponding Flysystem exceptions.                                                                    |

#### Path convention

Each video's path is its sanitized display title (e.g. `My Interview.mp4`). If two videos share the same title, the last
6 characters of the FileId are appended to avoid collisions. The FileId→path mapping is stored in the listing cache.

#### Virtual meta files

`listContents` also yields a virtual `.meta/{path}.yaml` entry for every video. This YAML contains:

```yaml
size: 12345678
last_modified: 1700000000
mime_type: 'video/mp4'
data:
  alt: 'Video title'
  thumbnail_url: 'https://...'
  video_id: '12345678'
```

Statamic reads this file automatically; because the file exists virtually, Statamic skips calling `writeMeta()` and
leaves the adapter's read-only behaviour intact.

### Thumbnail injection (CP JavaScript)

Statamic's asset browser only renders thumbnail images for assets where `is_image: true`. The addon registers an Axios
response interceptor (via `Statamic.booted()`) that:

1. Intercepts the folder API response for the `vidiq` container (`GET /cp/assets/browse/folders/vidiq`).
2. Fetches the thumbnail map from the addon's own endpoint (`GET /cp/vidiq/assets`), once per page load.
3. Injects `is_image: true` and `thumbnail: <url>` into each asset object before Vue renders the list.

## Blade Component

### `<x-vidiq::embed>`

Embeds a 3Q video using one of three output methods. The method defaults to the config value
`vidiq.embed_fallback_method` (default: `JavaScript`).

```blade
{{-- Default method from config --}}
<x-vidiq::embed :asset="$asset" />

{{-- Explicit method --}}
<x-vidiq::embed :asset="$asset" method="iFrame" />
```

**Props**

| Prop     | Type                           | Default                            | Description                                                                      |
|----------|--------------------------------|------------------------------------|----------------------------------------------------------------------------------|
| `asset`  | `Statamic\Assets\Asset\|null`  | `null`                             | The asset object. Renders nothing if `null` or if no player URL can be resolved. |
| `method` | `string\|null`                 | `config('vidiq.embed_fallback_method')` | Embed output method: `JavaScript`, `iFrame`, or `PlayerURL`.                |

**Methods**

| Value        | Output                                                                                  |
|--------------|-----------------------------------------------------------------------------------------|
| `JavaScript` | Renders the JS embed snippet (`{!! $embedCodes['JavaScript'] !!}`) provided by 3Q.     |
| `iFrame`     | Renders an `<iframe>` with the `PlayerURL` as `src` and the `video-embed` CSS class.   |
| `PlayerURL`  | Outputs only the plain player URL string (useful for custom markup or JS integration). |

## Configuration

### `config/vidiq.php` (addon)

| Key                     | Env variable           | Default                     | Description                                                |
|-------------------------|------------------------|-----------------------------|-------------------------------------------------------------|
| `api.token`             | `VIDIQ_API_TOKEN`      | —                           | 3Q API token                                               |
| `api.endpoint`          | `VIDIQ_API_ENDPOINT`   | `https://sdn.3qsdn.com/api` | 3Q API base URL                                            |
| `api.timeout`           | `VIDIQ_API_TIMEOUT`    | `30`                        | HTTP timeout in seconds                                    |
| `cache.ttl`             | `VIDIQ_CACHE_TTL`      | `3600`                      | Cache TTL in seconds for listings and embed codes          |
| `embed_fallback_method` | `VIDIQ_FALLBACK_METHOD`| `JavaScript`                | Default embed method (`JavaScript`, `iFrame`, `PlayerURL`) |

### `config/vidiq-disk.php` (addon)

Configures the `3q` Laravel filesystem disk that is registered automatically by the ServiceProvider. The disk uses the
custom `3q` driver backed by `ThreeQAdapter`.

## Multiple Projects

To connect a second 3Q project, add another disk entry in `config/filesystems.php` and a matching asset container YAML:

```php
// config/filesystems.php
'3q-project-b' => [
    'driver'     => '3q',
    'api_token'  => env('VIDIQ_PROJECT_B_API_TOKEN', ''), // or use your default token VIDIQ_API_TOKEN
    'project_id' => env('VIDIQ_PROJECT_B_PROJECT_ID', ''),
    'endpoint'   => env('VIDIQ_API_ENDPOINT', 'https://sdn.3qsdn.com/api'),
    'timeout'    => env('VIDIQ_API_TIMEOUT', 30),
],
```

```yaml
# content/assets/vidiq-project-b.yaml
title: '3Q Videos — Project B'
disk: 3q-project-b
allow_uploads: false
allow_downloading: false
allow_renaming: false
allow_moving: false
create_folders: false
```

After that update `.env` with the new credentials and clear or refresh caches:

```bash
php artisan cache:clear
php artisan config:clear
```

## Development

Clone the repository and install dependencies:

```bash
git clone https://github.com/takepart-media/vidiq.git
cd vidiq
composer install
npm install
```

### Build CP assets

```bash
npm run dev      # Vite dev server
npm run build    # Production build
```

### Cache management

```bash
php artisan statamic:stache:clear   # Clear Statamic file cache
php artisan config:clear            # Clear Laravel config cache
```

## License

The MIT License (MIT). Please see the [LICENSE](LICENSE) file for more information.