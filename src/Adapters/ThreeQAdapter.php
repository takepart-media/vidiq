<?php

namespace TakepartMedia\Vidiq\Adapters;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemException;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\Visibility;
use Statamic\Facades\YAML;
use Statamic\Support\Str;
use TakepartMedia\Vidiq\Jobs\WarmCacheJob;

/**
 * Flysystem v3 adapter for the 3q. Video API.
 *
 * Path convention: the sanitized 3q display title (e.g. "My Interview.mp4"),
 * disambiguated with the last 6 characters of the FileId on collisions.
 *   - readStream() downloads the poster image from 3q, so Glide can serve it
 *     if anything ever asks for a generated thumbnail.
 *   - The CP thumbnail itself is the plain 3q poster URL, injected server-side
 *     by ServiceProvider::bootAssetThumbnails().
 *   - Virtual .meta/ files are yielded from listContents() so Statamic reads
 *     video metadata (title, thumbnail_url) without writing to disk. Edits are
 *     kept as a diff against the 3q values and merged back in on read.
 *   - The FileId→path mapping is cached in Laravel cache keyed by project ID.
 */
class ThreeQAdapter implements FilesystemAdapter
{
    private const string META_PREFIX = '.meta/';

    private const string META_SUFFIX = '.yaml';

    private const int CACHE_TTL = 3600; // 1 hour

    private const string CACHE_KEY_PREFIX = 'vidiq';

    private Client $client;

    private Client $downloadClient;

    /** @var array<string, array<string, mixed>>|null In-memory listing cache to avoid repeated $this->cache()->get() deserialization. */
    private ?array $listingMemory = null;

    /**
     * @param  string  $apiToken  3Q API authentication token.
     * @param  string  $projectId  3Q project identifier.
     * @param  string  $apiEndpoint  Base URL for the 3Q SDN API.
     * @param  int  $timeout  HTTP request timeout in seconds.
     */
    public function __construct(
        private readonly string $apiToken,
        private readonly string $projectId,
        private readonly string $apiEndpoint = 'https://sdn.3qsdn.com/api',
        private readonly int $timeout = 30,
    ) {
        $this->client = new Client([
            'base_uri' => rtrim($this->apiEndpoint, '/').'/',
            'timeout' => $this->timeout,
            'headers' => [
                'X-AUTH-APIKEY' => $this->apiToken,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);

        $this->downloadClient = new Client(['timeout' => 30]);
    }

    // =========================================================================
    // URL generation (called by Laravel FilesystemAdapter::url())
    // =========================================================================

    /**
     * Return the embed codes for the given asset path.
     *
     * @param  string  $path  The sanitized asset path (e.g. "My Interview.mp4"), not the FileId.
     *
     * @throws FilesystemException
     */
    public function getUrl(string $path, bool $force = false): array
    {
        // Resolve FileId from listing cache (path is the sanitized display name,
        // not the FileId, so we must look it up).
        $fileId = $this->getCachedFileData($path)['id'] ?? null;

        if (! $fileId) {
            return [];
        }

        $cacheKey = $this->cacheKey("embed_codes.{$fileId}");

        if (! $force) {
            $cached = $this->cache()->get($cacheKey);

            if ($cached !== null) {
                return $cached;
            }
        }

        try {
            $response = $this->client->get(
                "v2/projects/{$this->projectId}/files/{$fileId}/playouts/default/embed"
            );
            $data = json_decode($response->getBody()->getContents(), true);
            $embedCodes = $data['FileEmbedCodes'] ?? '';
        } catch (GuzzleException) {
            $embedCodes = [];
        }

        $this->cacheStore($cacheKey, $embedCodes);

        return $embedCodes;
    }

    /**
     * Fetch and cache embed codes for every video in the listing, in parallel.
     *
     * Embed codes are stable per file, so already-cached codes are kept and
     * only missing (or previously empty — the video may have finished encoding
     * since) ones are fetched. Pass $fresh to refetch everything, e.g. after
     * a playout configuration change on 3q.
     *
     * @param  bool  $fresh  Refetch embed codes that are already cached.
     * @param  (callable(int, int): void)|null  $onProgress  Called with (done, total) after each completed request.
     * @return array{fetched: int, kept: int, failed: int}
     *
     * @throws FilesystemException
     */
    public function warmEmbedCodes(bool $fresh = false, ?callable $onProgress = null): array
    {
        $fileIds = array_column($this->fetchListing(), 'id');

        $pending = $fresh ? $fileIds : array_values(array_filter(
            $fileIds,
            fn (string $fileId) => empty($this->cache()->get($this->cacheKey("embed_codes.{$fileId}"))),
        ));

        $total = count($pending);
        $done = 0;
        $failed = 0;

        $requests = function () use ($pending) {
            foreach ($pending as $fileId) {
                yield new Request(
                    'GET',
                    "v2/projects/{$this->projectId}/files/{$fileId}/playouts/default/embed"
                );
            }
        };

        (new Pool($this->client, $requests(), [
            'concurrency' => max(1, (int) config('vidiq.cache.fetch_concurrency', 6)),
            'fulfilled' => function ($response, int $index) use ($pending, $total, &$done, &$failed, $onProgress) {
                $data = json_decode($response->getBody()->getContents(), true);
                $embedCodes = $data['FileEmbedCodes'] ?? [];

                if ($embedCodes === []) {
                    $failed++;
                }

                $this->cacheStore($this->cacheKey("embed_codes.{$pending[$index]}"), $embedCodes);

                if ($onProgress) {
                    $onProgress(++$done, $total);
                }
            },
            // Rejected requests are not cached, so they stay pending for the next run.
            'rejected' => function ($reason, int $index) use ($total, &$done, &$failed, $onProgress) {
                $failed++;

                if ($onProgress) {
                    $onProgress(++$done, $total);
                }
            },
        ]))->promise()->wait();

        return ['fetched' => $total, 'kept' => count($fileIds) - $total, 'failed' => $failed];
    }

    // =========================================================================
    // Directory listing
    // =========================================================================

    /**
     * Yield FileAttributes for every video file and its paired virtual .meta/ entry.
     *
     * @param  string  $path  The directory path to list (ignored — this adapter is flat).
     * @param  bool  $deep  Whether to recurse into subdirectories (ignored — no directories exist).
     * @return iterable<FileAttributes>
     */
    public function listContents(string $path, bool $deep): iterable
    {
        $listing = $this->fetchListing();

        foreach ($listing as $filePath => $fileData) {
            yield new FileAttributes(
                path: $filePath,
                fileSize: $fileData['size'] ?? null,
                visibility: Visibility::PUBLIC,
                lastModified: $fileData['timestamp'] ?? null,
                mimeType: 'video/mp4',
            );

            // Virtual meta file so Statamic reads our generated YAML
            // and skips calling writeMeta() (which would fail on our read-only disk).
            $metaPath = self::META_PREFIX.$filePath.self::META_SUFFIX;
            $metaYaml = $this->buildMetaYaml($fileData);

            yield new FileAttributes(
                path: $metaPath,
                fileSize: strlen($metaYaml),
                mimeType: 'application/x-yaml',
            );
        }
    }

    // =========================================================================
    // Existence checks
    // =========================================================================

    /**
     * Determine whether a file (or its virtual .meta/ counterpart) exists.
     *
     * @param  string  $path  The asset path or .meta/ path to check.
     *
     * @throws FilesystemException
     */
    public function fileExists(string $path): bool
    {
        if ($this->isMetaPath($path)) {
            $assetPath = $this->assetPathFromMeta($path);

            return $this->getCachedFileData($assetPath) !== [];
        }

        return $this->getCachedFileData($path) !== [];
    }

    /**
     * Always returns false — this adapter does not support directories.
     *
     * @param  string  $path  The directory path to check (ignored).
     */
    public function directoryExists(string $path): bool
    {
        return false;
    }

    // =========================================================================
    // Reading — meta files + thumbnail download for image display
    // =========================================================================

    /**
     * Read the content of a .meta/ file; direct reads of asset paths are unsupported.
     *
     * @param  string  $path  The .meta/ path to read (format: ".meta/{name}.mp4.yaml").
     *
     * @throws UnableToReadFile
     * @throws FilesystemException
     */
    public function read(string $path): string
    {
        if ($this->isMetaPath($path)) {
            return $this->readMetaYaml($path);
        }

        throw UnableToReadFile::fromLocation($path, 'direct read is not supported; use readStream for thumbnails');
    }

    /**
     * Open a readable stream for a .meta/ file or download a video thumbnail as a stream.
     *
     * @param  string  $path  The asset path (streams thumbnail) or .meta/ path (streams YAML).
     * @return resource
     *
     * @throws UnableToReadFile
     * @throws FilesystemException
     */
    public function readStream(string $path)
    {
        if ($this->isMetaPath($path)) {
            $content = $this->readMetaYaml($path);
            $stream = fopen('php://temp', 'r+');
            fwrite($stream, $content);
            rewind($stream);

            return $stream;
        }

        // Download the thumbnail image so Statamic/Glide can generate its own cached preview.
        $thumbnailUrl = $this->getCachedFileData($path)['thumbnail_url'] ?? null;

        if (! $thumbnailUrl) {
            throw UnableToReadFile::fromLocation($path, 'no thumbnail URL found for this video');
        }

        try {
            $response = $this->downloadClient->get($thumbnailUrl, ['stream' => true]);

            return $response->getBody()->detach();
        } catch (GuzzleException $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
        }
    }

    // =========================================================================
    // Writing — silently accept .meta/ writes (Statamic may try to update them)
    // =========================================================================

    /**
     * Accept .meta/ writes by persisting the edited data fields; throws for all other paths.
     *
     * @param  string  $path  The .meta/ path to write (format: ".meta/{name}.mp4.yaml").
     * @param  string  $contents  The YAML content to persist.
     * @param  Config  $config  Additional Flysystem configuration (unused).
     *
     * @throws UnableToWriteFile
     */
    public function write(string $path, string $contents, Config $config): void
    {
        if ($this->isMetaPath($path)) {
            $this->storeMetaOverrides($path, $contents);

            return;
        }

        throw UnableToWriteFile::atLocation($path, 'write is not supported by the 3q adapter');
    }

    /**
     * Accept .meta/ stream writes by persisting the edited data fields; throws for all other paths.
     *
     * @param  string  $path  The .meta/ path to write.
     * @param  resource|string  $contents  The stream or string content to persist.
     * @param  Config  $config  Additional Flysystem configuration (unused).
     *
     * @throws UnableToWriteFile
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        if ($this->isMetaPath($path)) {
            $this->storeMetaOverrides($path, is_resource($contents) ? stream_get_contents($contents) : $contents);

            return;
        }

        throw UnableToWriteFile::atLocation($path, 'writeStream is not supported by the 3q adapter');
    }

    // =========================================================================
    // Deletion
    // =========================================================================

    /**
     * Silently ignore deletion of virtual .meta/ files; throws for all other paths.
     *
     * @param  string  $path  The file path to delete.
     *
     * @throws UnableToDeleteFile
     */
    public function delete(string $path): void
    {
        if ($this->isMetaPath($path)) {
            // Virtual files — nothing to delete on the remote API.
            return;
        }

        throw UnableToDeleteFile::atLocation($path, 'delete is not supported by the 3q adapter');
    }

    /**
     * Always throws — directories are not supported by this adapter.
     *
     * @param  string  $path  The directory path to delete (unused).
     *
     * @throws UnableToDeleteDirectory
     */
    public function deleteDirectory(string $path): void
    {
        throw UnableToDeleteDirectory::atLocation($path, 'directories are not supported by the 3q adapter');
    }

    // =========================================================================
    // Other unsupported operations
    // =========================================================================

    /**
     * Always throws — directories are not supported by this adapter.
     *
     * @param  string  $path  The directory path to create (unused).
     * @param  Config  $config  Additional Flysystem configuration (unused).
     *
     * @throws UnableToCreateDirectory
     */
    public function createDirectory(string $path, Config $config): void
    {
        throw UnableToCreateDirectory::atLocation($path, 'directories are not supported by the 3q adapter');
    }

    /**
     * No-op: all 3Q assets are inherently public; visibility cannot be changed.
     *
     * @param  string  $path  The asset path (unused).
     * @param  string  $visibility  The requested visibility value (unused).
     */
    public function setVisibility(string $path, string $visibility): void
    {
        // No-op: all 3q assets are public.
    }

    /**
     * Always throws — move is not supported by this adapter.
     *
     * @param  string  $source  The source path (unused).
     * @param  string  $destination  The destination path (unused).
     * @param  Config  $config  Additional Flysystem configuration (unused).
     *
     * @throws UnableToMoveFile
     */
    public function move(string $source, string $destination, Config $config): void
    {
        throw UnableToMoveFile::fromLocationTo(
            $source,
            $destination,
            new \RuntimeException('move is not supported by the 3q adapter'),
        );
    }

    /**
     * Always throws — copy is not supported by this adapter.
     *
     * @param  string  $source  The source path (unused).
     * @param  string  $destination  The destination path (unused).
     * @param  Config  $config  Additional Flysystem configuration (unused).
     *
     * @throws UnableToCopyFile
     */
    public function copy(string $source, string $destination, Config $config): void
    {
        throw UnableToCopyFile::fromLocationTo(
            $source,
            $destination,
            new \RuntimeException('copy is not supported by the 3q adapter'),
        );
    }

    // =========================================================================
    // Metadata (served from listing cache to avoid extra API calls)
    // =========================================================================

    /**
     * Return file attributes with PUBLIC visibility for the given path.
     *
     * @param  string  $path  The asset path to retrieve visibility for.
     */
    public function visibility(string $path): FileAttributes
    {
        return new FileAttributes($path, null, Visibility::PUBLIC);
    }

    /**
     * Return file attributes carrying the MIME type for the given path.
     * .meta/ paths return application/x-yaml; all others return video/mp4.
     *
     * @param  string  $path  The asset or .meta/ path to retrieve the MIME type for.
     */
    public function mimeType(string $path): FileAttributes
    {
        if ($this->isMetaPath($path)) {
            return new FileAttributes($path, null, null, null, 'application/x-yaml');
        }

        return new FileAttributes($path, null, null, null, 'video/mp4');
    }

    /**
     * Return file attributes with the last-modified timestamp from the listing cache.
     *
     * @param  string  $path  The asset path to retrieve the timestamp for.
     *
     * @throws FilesystemException
     */
    public function lastModified(string $path): FileAttributes
    {
        $data = $this->getCachedFileData($path);

        return new FileAttributes($path, null, null, $data['timestamp'] ?? null);
    }

    /**
     * Return file attributes with the file size from the listing cache.
     *
     * @param  string  $path  The asset path to retrieve the file size for.
     *
     * @throws FilesystemException
     */
    public function fileSize(string $path): FileAttributes
    {
        $data = $this->getCachedFileData($path);
        $size = $data['size'] ?? null;

        if ($size === null) {
            throw UnableToRetrieveMetadata::fileSize($path, 'file is still being encoded on 3q.video');
        }

        return new FileAttributes($path, $size);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Fetch the full file listing from the API (or return from cache).
     *
     * Returns an array keyed by the sanitized asset path (the 3q display title).
     *
     * @param  bool  $force  When true, bypass the cache and always fetch from the API.
     * @return array<string, array{id: string, name: string, title: string, thumbnail_url: string|null, size: int|null, timestamp: int|null}>
     */
    public function fetchListing(bool $force = false): array
    {
        if (! $force) {
            if ($this->listingMemory !== null) {
                return $this->listingMemory;
            }

            $cached = $this->cache()->get($this->cacheKey('listing'));

            if ($cached !== null) {
                $this->listingMemory = $cached;
                $this->revalidateIfStale();

                return $cached;
            }
        }

        try {
            $limit = 500;

            // Cheap count-only request (no metadata) so every data page can be
            // fetched in parallel, rather than fetching the first one serially.
            // For a large catalogue this is the difference between a few seconds
            // and tens of seconds (which would otherwise time out a CP request
            // before the listing is cached).
            $totalCount = (int) ($this->fetchFilesPage(1, 0, includeData: false)['TotalCount'] ?? 0);

            $files = [];

            if ($totalCount > 0) {
                $offsets = range(0, $totalCount - 1, $limit);
                $failed = false;

                $requests = function () use ($offsets, $limit) {
                    foreach ($offsets as $offset) {
                        yield new Request(
                            'GET',
                            "v2/projects/{$this->projectId}/files?IncludeMetadata=true&IncludeProperties=true&Limit={$limit}&Offset={$offset}"
                        );
                    }
                };

                (new Pool($this->client, $requests(), [
                    'concurrency' => max(1, (int) config('vidiq.cache.fetch_concurrency', 6)),
                    'fulfilled' => function ($response) use (&$files) {
                        $data = json_decode($response->getBody()->getContents(), true);
                        $files = array_merge($files, $data['Files'] ?? []);
                    },
                    'rejected' => function () use (&$failed) {
                        $failed = true;
                    },
                ]))->promise()->wait();

                // Don't cache a partial listing as if it were complete.
                if ($failed) {
                    throw new \RuntimeException('one or more 3q listing pages failed to load');
                }
            }

            $listing = [];
            $usedPaths = [];

            foreach ($files as $file) {
                $metadata = $file['Metadata'] ?? [];
                $properties = $file['Properties'] ?? [];
                $standardPicture = $metadata['StandardFilePicture'] ?? [];

                $fileId = (string) $file['Id'];
                $name = $file['Name'] ?? '';
                $thumbnailUrl = $standardPicture['ThumbURI'] ?? $standardPicture['URI'] ?? null;
                $timestamp = isset($file['LastUpdateAt']) ? (strtotime($file['LastUpdateAt']) ?: null) : null;
                $size = $properties['Size'] ?? null;
                $title = $metadata['Title'] ?? $metadata['DisplayTitle'] ?? $name;
                $releaseStatus = $metadata['ReleaseStatus'] ?? null;
                $duration = isset($properties['Length']) ? (int) round((float) $properties['Length']) : null;
                $width = $properties['VideoWidth'] ?? null;
                $height = $properties['VideoHeight'] ?? null;

                $filePath = $this->makeUniquePath($title ?: $name, $fileId, $usedPaths);
                $usedPaths[] = $filePath;

                if ($size === null) {
                    continue;
                }

                $listing[$filePath] = [
                    'id' => $fileId,
                    'name' => $name,
                    'title' => $title,
                    'thumbnail_url' => $thumbnailUrl,
                    'size' => $size,
                    'timestamp' => $timestamp,
                    'release_status' => $releaseStatus,
                    'duration' => $duration,
                    'width' => $width,
                    'height' => $height,
                    'metadata' => $this->extraMetadata($metadata),
                ];
            }

            $this->pruneRemovedFiles($listing);

            $this->cacheStore($this->cacheKey('listing'), $listing);
            $this->cache()->forever($this->cacheKey('listing.refreshed_at'), time());
            $this->listingMemory = $listing;

            return $listing;
        } catch (GuzzleException|\RuntimeException $e) {
            throw UnableToRetrieveMetadata::create('/', 'listContents', $e->getMessage(), $e);
        }
    }

    /**
     * Forget per-file cache keys (embed codes, meta edits) for videos that are
     * no longer present in the fresh listing, so they don't accumulate forever
     * in a permanent cache store.
     *
     * @param  array<string, array<string, mixed>>  $fresh  The newly fetched listing, keyed by path.
     */
    private function pruneRemovedFiles(array $fresh): void
    {
        $previous = $this->cache()->get($this->cacheKey('listing')) ?? [];

        // Embed codes are keyed by FileId (survives renames)...
        $staleIds = array_diff(array_column($previous, 'id'), array_column($fresh, 'id'));

        foreach ($staleIds as $staleId) {
            $this->cache()->forget($this->cacheKey("embed_codes.{$staleId}"));
        }

        // ...while meta edits are keyed by path.
        foreach (array_keys(array_diff_key($previous, $fresh)) as $stalePath) {
            $this->cache()->forget($this->metaOverridesCacheKey(self::META_PREFIX.$stalePath.self::META_SUFFIX));
        }
    }

    /**
     * Fetch a single page of the 3q file listing.
     *
     * @return array<string, mixed>
     */
    private function fetchFilesPage(int $limit, int $offset, bool $includeData = true): array
    {
        $query = ['Limit' => $limit, 'Offset' => $offset];

        if ($includeData) {
            $query['IncludeMetadata'] = 'true';
            $query['IncludeProperties'] = 'true';
        }

        $response = $this->client->get("v2/projects/{$this->projectId}/files", ['query' => $query]);

        return json_decode($response->getBody()->getContents(), true) ?? [];
    }

    /**
     * Return cached data for a single asset path, loading from API if needed.
     *
     * @param  string  $path  The sanitized asset path to look up.
     * @return array<string, mixed>
     *
     * @throws FilesystemException
     */
    private function getCachedFileData(string $path): array
    {
        if ($this->listingMemory === null) {
            $this->listingMemory = $this->cache()->get($this->cacheKey('listing'));

            if ($this->listingMemory === null) {
                // Populate cache as a side effect of consuming the generator.
                iterator_to_array($this->listContents('/', false));
                $this->listingMemory = $this->cache()->get($this->cacheKey('listing')) ?? [];
            }
        }

        return $this->listingMemory[$path] ?? [];
    }

    /**
     * Generate a filesystem-safe, unique path for a video.
     * Falls back to appending the last 6 digits of the FileId on name collisions.
     *
     * @param  string  $name  The original video name/title from 3q metadata.
     * @param  string  $fileId  The unique FileId from 3q, used for disambiguation if needed.
     * @param  array<int, string>  $usedPaths  Already-assigned paths in the current listing pass.
     */
    private function makeUniquePath(string $name, string $fileId, array $usedPaths): string
    {
        $safe = $this->sanitizeName($name);

        if (! in_array($safe, $usedPaths, true)) {
            return $safe;
        }

        return $safe.'-'.substr($fileId, -6);
    }

    /**
     * Strip characters that are invalid in filesystem paths.
     *
     * @param  string  $name  The raw video name to sanitize.
     */
    private function sanitizeName(string $name): string
    {
        $safe = preg_replace('/[\/:*?"<>|]/', '-', $name);
        $safe = trim($safe ?? '', ' .');

        return $safe !== '' ? $safe : 'video';
    }

    /**
     * Build the YAML content for a virtual .meta/ file.
     * Statamic reads this to get size, last_modified, mime_type, and user data fields.
     *
     * @param  array<string, mixed>  $fileData  The file data entry from the listing cache.
     * @param  array<string, mixed>  $overrides  Edited data fields that win over the 3q values.
     */
    private function buildMetaYaml(array $fileData, array $overrides = []): string
    {
        // Statamic reads size/last_modified/width/height/duration as top-level meta
        // keys (Asset::size(), ::width(), ...); everything else belongs under data.
        $meta = array_filter([
            'size' => $fileData['size'] ?? null,
            'last_modified' => $fileData['timestamp'] ?? null,
            'width' => isset($fileData['width']) ? (int) $fileData['width'] : null,
            'height' => isset($fileData['height']) ? (int) $fileData['height'] : null,
            'duration' => isset($fileData['duration']) ? (int) $fileData['duration'] : null,
        ]);

        $meta['mime_type'] = 'video/mp4';

        // Overrides are merged, not substituted, so an edited alt text survives
        // while everything else keeps following the 3q listing.
        $meta['data'] = array_merge($this->buildMetaData($fileData), $overrides);

        return YAML::dump($meta);
    }

    /**
     * Build the data fields a .meta/ file exposes from a 3q listing entry.
     *
     * @param  array<string, mixed>  $fileData  The file data entry from the listing cache.
     * @return array<string, mixed>
     */
    private function buildMetaData(array $fileData): array
    {
        return array_filter([
            'alt' => $fileData['title'] ?: ($fileData['name'] ?? ''),
            'thumbnail_url' => $fileData['thumbnail_url'] ?? null,
            'video_id' => $fileData['id'] ?? null,
            'release_status' => $fileData['release_status'] ?? null,
            ...$fileData['metadata'] ?? [],
        ]);
    }

    /**
     * Persist the data fields of a written .meta/ file that differ from the 3q
     * values. Statamic rewrites the whole file on every asset save (and whenever
     * it regenerates meta on its own), so storing it verbatim would freeze the
     * asset: 3q changes and newly configured metadata fields would stop coming
     * through. Keeping just the difference lets both coexist, permanently.
     *
     * @param  string  $metaPath  The .meta/ path being written.
     * @param  string  $contents  The YAML content Statamic wrote.
     */
    private function storeMetaOverrides(string $metaPath, string $contents): void
    {
        $written = YAML::parse($contents)['data'] ?? [];
        $generated = $this->buildMetaData($this->getCachedFileData($this->assetPathFromMeta($metaPath)));

        $overrides = [];

        foreach ($written as $key => $value) {
            if (! array_key_exists($key, $generated) || $generated[$key] !== $value) {
                $overrides[$key] = $value;
            }
        }

        $cacheKey = $this->metaOverridesCacheKey($metaPath);

        if ($overrides === []) {
            $this->cache()->forget($cacheKey);

            return;
        }

        $this->cache()->forever($cacheKey, $overrides);
    }

    /**
     * Read the stored data overrides for a .meta/ path.
     *
     * @param  string  $metaPath  The .meta/ path to look up.
     * @return array<string, mixed>
     */
    private function metaOverrides(string $metaPath): array
    {
        $overrides = $this->cache()->get($this->metaOverridesCacheKey($metaPath));

        return is_array($overrides) ? $overrides : [];
    }

    /**
     * Pick the 3q metadata fields configured in `vidiq.metadata_fields` and
     * flatten them into searchable asset data keyed by their snake_cased name.
     *
     * @param  array<string, mixed>  $metadata  The "Metadata" block of a 3q file.
     * @return array<string, string>
     */
    private function extraMetadata(array $metadata): array
    {
        $fields = [];

        foreach (config('vidiq.metadata_fields', []) as $key) {
            if ($value = $this->flattenMetadata($metadata[$key] ?? null)) {
                $fields[Str::snake($key)] = $value;
            }
        }

        return $fields;
    }

    /**
     * Flatten a 3q metadata value to a string. 3q returns plain scalars (Source,
     * ProgramId, Tags) as well as lists of labelled objects (Category, People).
     *
     * @param  mixed  $value  The raw metadata value.
     */
    private function flattenMetadata(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = implode(', ', array_filter(array_map(
                fn ($item) => is_array($item) ? ($item['Label'] ?? $item['Title'] ?? $item['Name'] ?? null) : $item,
                $value
            ), fn ($item) => is_scalar($item) && (string) $item !== ''));
        }

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /**
     * Return the YAML for a .meta/ path, preferring any user-edited version.
     *
     * @param  string  $metaPath  The .meta/ path (format: ".meta/{name}.mp4.yaml").
     *
     * @throws FilesystemException
     */
    private function readMetaYaml(string $metaPath): string
    {
        $assetPath = $this->assetPathFromMeta($metaPath);
        $data = $this->getCachedFileData($assetPath);

        if ($data === []) {
            throw UnableToReadFile::fromLocation($metaPath, 'asset not found in listing cache');
        }

        return $this->buildMetaYaml($data, $this->metaOverrides($metaPath));
    }

    /**
     * Determine whether the given path refers to a virtual .meta/ file.
     *
     * @param  string  $path  The path to inspect.
     */
    private function isMetaPath(string $path): bool
    {
        return str_starts_with($path, self::META_PREFIX);
    }

    /**
     * Convert ".meta/{name}.yaml" → "{name}".
     *
     * @param  string  $metaPath  The .meta/ path to convert.
     */
    private function assetPathFromMeta(string $metaPath): string
    {
        $withoutPrefix = substr($metaPath, strlen(self::META_PREFIX));

        return str_ends_with($withoutPrefix, self::META_SUFFIX)
            ? substr($withoutPrefix, 0, -strlen(self::META_SUFFIX))
            : $withoutPrefix;
    }

    /**
     * Flush all vidiq cache entries for this project (listing, embed codes, meta edits).
     *
     * @return int The number of cache keys that were flushed.
     */
    public function flushCache(): int
    {
        $flushed = 0;
        $this->listingMemory = null;

        // Get the current listing to discover all per-file cache keys.
        $listing = $this->cache()->get($this->cacheKey('listing')) ?? [];

        // Forget the embed codes derived from the listing. Meta overrides are
        // deliberately kept: they are editor input, not cached 3q data.
        foreach ($listing as $fileData) {
            if ($fileId = $fileData['id'] ?? null) {
                $this->cache()->forget($this->cacheKey("embed_codes.{$fileId}"));
                $flushed++;
            }
        }

        // Forget the listing itself.
        $this->cache()->forget($this->cacheKey('listing'));
        $flushed++;

        return $flushed;
    }

    /**
     * The cache repository vidiq uses — an isolated store (see config) so a
     * global `cache:clear` doesn't wipe the listing and force a blocking refetch.
     */
    private function cache(): Repository
    {
        return Cache::store(config('vidiq.cache.store') ?: null);
    }

    /**
     * Stale-while-revalidate: when the cached listing is older than the
     * configured window, dispatch a background refresh *after the response* so
     * the current request keeps serving the cached (stale) data without blocking.
     */
    private function revalidateIfStale(): void
    {
        $refreshAfter = (int) config('vidiq.cache.refresh_after', 0);

        if ($refreshAfter <= 0) {
            return;
        }

        $refreshedAt = (int) $this->cache()->get($this->cacheKey('listing.refreshed_at'), 0);

        if ($refreshedAt > 0 && (time() - $refreshedAt) < $refreshAfter) {
            return;
        }

        // Dedupe concurrent revalidations; the lock clears once the refresh runs.
        if ($this->cache()->add($this->cacheKey('listing.revalidating'), 1, 300)) {
            WarmCacheJob::dispatch()->afterResponse();
        }
    }

    /**
     * Store a value in cache, using forever or TTL depending on config.
     *
     * @param  string  $key  The cache key.
     * @param  mixed  $value  The value to cache.
     */
    private function cacheStore(string $key, mixed $value): void
    {
        if (config('vidiq.cache.permanent', true)) {
            $this->cache()->forever($key, $value);
        } else {
            $this->cache()->put($key, $value, config('vidiq.cache.ttl', self::CACHE_TTL));
        }
    }

    /**
     * Build a namespaced cache key scoped to this adapter's project ID.
     *
     * @param  string  $key  The key suffix to namespace.
     */
    public function cacheKey(string $key): string
    {
        $cachePrefix = config('vidiq.cache.prefix', self::CACHE_KEY_PREFIX);

        return "{$cachePrefix}.{$this->projectId}.{$key}";
    }

    /**
     * Build the cache key used to store user-edited .meta/ content for a given path.
     *
     * @param  string  $metaPath  The .meta/ path whose user-edited content should be cached.
     */
    private function metaOverridesCacheKey(string $metaPath): string
    {
        return $this->cacheKey('meta_overrides.'.md5($metaPath));
    }
}
