# vidiQ — 3q.video Integration for Statamic

[![Latest Version on Packagist](https://img.shields.io/packagist/v/takepart-media/vidiq.svg?style=flat-square)](https://packagist.org/packages/takepart-media/vidiq)
[![License](https://img.shields.io/packagist/l/takepart-media/vidiq.svg?style=flat-square)](https://packagist.org/packages/takepart-media/vidiq)
[![PHP Version](https://img.shields.io/packagist/php-v/takepart-media/vidiq.svg?style=flat-square)](https://packagist.org/packages/takepart-media/vidiq)
[![Statamic](https://img.shields.io/badge/Statamic-6.x-FF269E?style=flat-square)](https://statamic.com)

A Statamic addon that integrates the [3q.video](https://3q.video) hosting platform into the Statamic Control Panel asset
browser. Videos hosted on 3q appear as browsable, deletable assets with thumbnail previews. A Blade component is
provided for embedding videos in frontend templates.

This addon has only been tested with **Statamic 6** and **3Q's SDN API v2**.

## Features

- **Asset browser integration** — 3Q videos appear in Statamic's CP file browser with thumbnail previews and release-status colour strips (published / unpublished / draft)
- **Asset editor player** — opening a video in the CP asset editor replaces the default `<video>` element with the 3Q player iframe
- **Fieldtype support** — videos selected via an Assets fieldtype display thumbnails and status indicators in entry forms
- **Read-only (for now)** — browse videos directly from the CP; write operations (upload, delete, rename, move) are
  currently unsupported — manage content in 3Q's own dashboard. Full write support may be added in a future release.
- **CP Cache Utility** — manage the vidiq cache directly from the Statamic Control Panel (Utilities → vidiQ Cache) with Warm and Clear buttons
- **Cache warm-up command** — `vidiq:warm-cache` Artisan command pre-populates listing and embed-code caches during deployment
- **Flysystem v3 adapter** — custom `ThreeQAdapter` implements the Flysystem interface backed by the 3Q SDN API
- **Embed codes** — fetches the full `FileEmbedCodes` array per video via the 3Q playout API (cached)
- **Virtual meta files** — generates `.meta/` YAML on the fly so Statamic stores title, thumbnail URL, video ID and
  release status without writing to disk
- **Blade component** — `<x-vidiq::embed>` for embedding the 3Q player in frontend templates

## Requirements

- PHP 8.3+
- Statamic 6.x
- GuzzleHTTP 7.x
- A [3q.video](https://3q.video) account with API access

### Version compatibility

| Addon version | Statamic |
|---------------|----------|
| 3.x           | 6.x      |
| 2.x           | 5.x      |

> **Statamic 5 support was dropped in 3.0.0.** Statamic 6 rebuilt the Control Panel on Inertia/Vue and removed the
> Blade chrome the CP Cache Utility relied on (`statamic::partials.breadcrumb`, the `card`/`btn`/`badge-pill-sm`
> classes). The utility view is now a fragment built from Statamic 6's global `ui-*` components, which do not exist
> in Statamic 5. Projects still on Statamic 5 should stay on `^2.0`.

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

# Optional — extra 3Q metadata to expose and search (see "Searchable 3Q metadata")
VIDIQ_METADATA_FIELDS=Category,Tags,Source,ProgramId
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
| `listContents`                                       | Fetches all files from `GET /v2/projects/{id}/files` and yields `FileAttributes`. Listing is cached for 1 hour by default (configurable via `VIDIQ_CACHE_TTL`). |
| `fileExists`                                         | Resolved from the listing cache.                                                                                                |
| `read`                                               | For `.meta/` paths: returns generated YAML. Direct reads of asset paths are not supported and throw `UnableToReadFile`.          |
| `readStream`                                         | For `.meta/` paths: streams the generated YAML. For asset paths: downloads the video thumbnail from 3Q for Statamic/Glide to cache. |
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
  release_status: 'published'
```

Statamic reads this file automatically; because the file exists virtually, Statamic skips calling `writeMeta()` and
leaves the adapter's read-only behaviour intact.

### CP thumbnails (server-side)

Statamic 6 renders a thumbnail for any asset whose CP payload carries a `thumbnail` key. 3Q videos have no local
file Glide could work on, so the addon hooks the three CP asset resources and passes the 3Q poster URL through:

```php
// ServiceProvider::bootAssetThumbnails()
FolderAsset::hook('asset', fn ($payload, $next) => …);   // browser grid + table
Asset::hook('asset', …);                                  // editor modal
AssetsFieldtypeAsset::hook('asset', …);                   // assets fieldtype
```

The poster URL comes from the asset's own meta data (`thumbnail_url`, written by the adapter's virtual `.meta/`
file), so no extra request and no client-side patching is involved.

### CP player (`vidiq_player` fieldtype)

The asset editor renders `<video :src="asset.url">` for video assets, and a read-only 3Q container has no asset
URL. The addon ships a `vidiq_player` fieldtype instead: it resolves the 3Q player URL server-side in `preload()`
and its Vue component teleports an iframe into the editor's preview column, hiding the core `<video>`.

Add it to the container's asset blueprint to activate it — see below. Outside the asset editor the field renders
inline.

### Asset blueprint

Create `resources/blueprints/assets/{container}.yaml` in your Statamic project:

```yaml
title: Video
fields:
  -
    handle: vidiq_player
    field:
      type: vidiq_player
      display: Player
      hide_display: true
      listable: false
  -
    handle: alt
    field:
      type: text
      display: 'Alt Text'
  -
    handle: release_status
    field:
      type: vidiq_status
      display: Status
      listable: true
```

Blueprint fields are listed *before* Statamic's own asset columns (`File`, `Size`, `Last Modified`, …), so to
move the status column to the end, set the container's column preference — either by reordering the columns in
the CP and saving them as default, or in `resources/preferences.yaml`:

```yaml
assets:
  {container}:
    columns:
      - basename
      - alt
      - size
      - last_modified
      - release_status
```

Columns missing from that list are hidden.

`release_status` is filled from the 3Q metadata (`published` / `unpublished` / `draft`). The `vidiq_status`
fieldtype renders it as a coloured, translated badge — green for published, amber for unpublished, grey for
draft — both as a table column in the asset browser and in the asset editor. Labels come from the addon's
translations (`lang/{locale}/messages.php`).

### Searchable 3Q metadata

By default Statamic's asset browser only searches the file path, which for 3Q assets means the video title.
`VIDIQ_METADATA_FIELDS` pulls additional fields out of the 3Q `Metadata` block, stores them as asset data and
matches them in the CP search alongside the filename — in the asset browser as well as in the assets fieldtype.

```env
VIDIQ_METADATA_FIELDS=Category,Tags,Source,ProgramId
```

Each key is stored under its snake_cased name, so `Category` becomes `category` and `ProgramId` becomes
`program_id`. Values that 3Q returns as lists of objects (`Category`, `People`, …) are flattened to their
labels and joined with commas. Empty values are omitted, an empty variable disables the feature.

The fields are captured while the 3Q listing is fetched, so **changing the list requires a cache refresh**:

```bash
php artisan vidiq:warm-cache --fresh   # re-fetch the listing with the new fields
php artisan cache:clear                # drop Statamic's cached asset meta
```

Add a field to the blueprint to also show it as a column. Mark it `read_only`, because edits to asset meta are
persisted by the adapter and would then shadow the value coming from 3Q:

```yaml
  -
    handle: category
    field:
      type: text
      display: Category
      read_only: true
      listable: true
```

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
| `cache.ttl`             | `VIDIQ_CACHE_TTL`      | `3600`                      | Cache TTL in seconds for listings and embed codes          |
| `cache.permanent`       | `VIDIQ_CACHE_PERMANENT`| `false`                     | When `true`, caches are stored forever (ignores TTL)       |
| `cache.prefix`          | —                      | `vidiq`                     | Prefix for all cache keys (scoped per project ID)          |
| `embed_fallback_method` | `VIDIQ_FALLBACK_METHOD`| `JavaScript`                | Default embed method (`JavaScript`, `iFrame`, `PlayerURL`) |
| `metadata_fields`       | `VIDIQ_METADATA_FIELDS`| —                           | Comma-separated 3Q metadata keys exposed as asset data and searched in the CP |

### `config/vidiq-disk.php` (addon)

Configures the `3q` Laravel filesystem disk that is registered automatically by the ServiceProvider. The disk uses the
custom `3q` driver backed by `ThreeQAdapter`.

| Key          | Env variable         | Default                      | Description             |
|--------------|----------------------|------------------------------|--------------------------|
| `api_token`  | `VIDIQ_API_TOKEN`    | —                            | 3Q API token             |
| `project_id` | `VIDIQ_PROJECT_ID`   | —                            | 3Q project identifier    |
| `endpoint`   | `VIDIQ_API_ENDPOINT` | `https://sdn.3qsdn.com/api` | 3Q API base URL          |
| `timeout`    | `VIDIQ_API_TIMEOUT`  | `30`                         | HTTP timeout in seconds  |

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

#### CP Utility

The addon registers a **vidiQ Cache** utility in the Statamic Control Panel under **Utilities → vidiQ Cache**. It
displays the number of cached videos and the current cache mode (TTL or Permanent). Two actions are available:

- **Warm** — dispatches a background queue job (`WarmCacheJob`) that flushes all vidiq caches, re-fetches the listing
  from the 3Q API, and pre-fetches embed codes for every video.
- **Clear** — immediately flushes all vidiq-specific cache entries (listing, embed codes, meta edits).

#### Warm the cache (CLI)

Pre-populate the video listing cache (and optionally embed codes) so the first frontend request avoids an API round-trip:

```bash
php artisan vidiq:warm-cache           # Warm listing cache only
php artisan vidiq:warm-cache --embed   # Also pre-fetch embed codes for every video
```

This is intended to run once during deployment (e.g. in a post-deploy script or CI pipeline).

#### Flush caches

```bash
php artisan cache:clear                # Flush all Laravel caches (including vidiq)
php artisan statamic:stache:clear      # Clear Statamic file cache
php artisan config:clear               # Clear Laravel config cache
```

The `vidiq:warm-cache` command automatically flushes all vidiq-specific cache entries (listing, embed codes, meta edits) before re-populating.

## License

The MIT License (MIT). Please see the [LICENSE](LICENSE) file for more information.