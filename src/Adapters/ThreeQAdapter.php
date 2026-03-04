<?php

namespace TakepartMedia\Vidiq\Adapters;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
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

/**
 * Flysystem v3 adapter for the 3q. Video API.
 *
 * Path convention: "{sanitized-name}.jpg"
 *   - Using .jpg extension makes Statamic treat assets as images, enabling
 *     thumbnail display in the asset browser.
 *   - readStream() for .jpg paths downloads the actual thumbnail from 3q,
 *     which Statamic/Glide caches for subsequent requests.
 *   - Virtual .meta/ files are yielded from listContents() so Statamic reads
 *     video metadata (title, thumbnail_url) without writing to disk.
 *   - The FileId→path mapping is cached in Laravel cache keyed by project ID.
 */
class ThreeQAdapter implements FilesystemAdapter
{
    private const string META_PREFIX = '.meta/';

    private const string META_SUFFIX = '.yaml';

    private const int CACHE_TTL = 600; // 10 minutes

    private const string CACHE_KEY_PREFIX = 'vidiq';

    private Client $client;

    private Client $downloadClient;

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
     * @param  string  $path  The sanitized asset path (e.g. "my-video.jpg"), not the FileId.
     *
     * @throws FilesystemException
     */
    public function getUrl(string $path): array
    {
        // Resolve FileId from listing cache (path is the sanitized display name,
        // not the FileId, so we must look it up).
        $fileId = $this->getCachedFileData($path)['id'] ?? null;

        if (! $fileId) {
            return [];
        }

        $cacheKey = $this->cacheKey("embed_codes.{$fileId}");
        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            return $cached;
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

        Cache::put($cacheKey, $embedCodes, config('vidiq.cache.ttl', self::CACHE_TTL));

        return $embedCodes;
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
     * @param  string  $path  The .meta/ path to read (format: ".meta/{name}.jpg.yaml").
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
     * Accept .meta/ writes by persisting the content to the cache; throws for all other paths.
     *
     * @param  string  $path  The .meta/ path to write (format: ".meta/{name}.jpg.yaml").
     * @param  string  $contents  The YAML content to persist.
     * @param  Config  $config  Additional Flysystem configuration (unused).
     *
     * @throws UnableToWriteFile
     */
    public function write(string $path, string $contents, Config $config): void
    {
        if ($this->isMetaPath($path)) {
            // Persist edited meta so subsequent reads return the updated data.
            Cache::put($this->metaEditCacheKey($path), $contents, self::CACHE_TTL * 24 * 7);

            return;
        }

        throw UnableToWriteFile::atLocation($path, 'write is not supported by the 3q adapter');
    }

    /**
     * Accept .meta/ stream writes by persisting the content to the cache; throws for all other paths.
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
            $content = is_resource($contents) ? stream_get_contents($contents) : $contents;
            Cache::put($this->metaEditCacheKey($path), $content, self::CACHE_TTL * 24 * 7);

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

        return new FileAttributes($path, $data['size'] ?? null);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Fetch the full file listing from the API (or return from cache).
     *
     * Returns an array keyed by the sanitized asset path ("{name}.jpg").
     *
     * @return array<string, array{id: string, name: string, title: string, thumbnail_url: string|null, size: int|null, timestamp: int|null}>
     */
    private function fetchListing(): array
    {
        $cached = Cache::get($this->cacheKey('listing'));

        if ($cached !== null) {
            return $cached;
        }

        try {
            $response = $this->client->get("v2/projects/{$this->projectId}/files", [
                'query' => [
                    'IncludeMetadata' => 'true',
                    'IncludeProperties' => 'true',
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $files = $data['Files'] ?? [];

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

                $filePath = $this->makeUniquePath($title ?: $name, $fileId, $usedPaths);
                $usedPaths[] = $filePath;

                $listing[$filePath] = [
                    'id' => $fileId,
                    'name' => $name,
                    'title' => $title,
                    'thumbnail_url' => $thumbnailUrl,
                    'size' => $size,
                    'timestamp' => $timestamp,
                    'release_status' => $releaseStatus,
                ];
            }

            Cache::put($this->cacheKey('listing'), $listing, self::CACHE_TTL);

            return $listing;
        } catch (GuzzleException $e) {
            throw UnableToRetrieveMetadata::create('/', 'listContents', $e->getMessage(), $e);
        }
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
        $listing = Cache::get($this->cacheKey('listing'));

        if ($listing === null) {
            // Populate cache as a side effect of consuming the generator.
            iterator_to_array($this->listContents('/', false));
            $listing = Cache::get($this->cacheKey('listing')) ?? [];
        }

        return $listing[$path] ?? [];
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
     */
    private function buildMetaYaml(array $fileData): string
    {
        $lines = [];

        if (isset($fileData['size'])) {
            $lines[] = 'size: '.$fileData['size'];
        }

        if (isset($fileData['timestamp'])) {
            $lines[] = 'last_modified: '.$fileData['timestamp'];
        }

        $lines[] = "mime_type: 'video/mp4'";
        $lines[] = 'data:';

        $title = $fileData['title'] ?: ($fileData['name'] ?? '');
        $lines[] = "  alt: '".str_replace("'", "''", $title)."'";

        if (! empty($fileData['thumbnail_url'])) {
            $lines[] = "  thumbnail_url: '".str_replace("'", "''", $fileData['thumbnail_url'])."'";
        }

        if (! empty($fileData['id'])) {
            $lines[] = "  video_id: '".$fileData['id']."'";
        }

        if (isset($fileData['release_status'])) {
            $lines[] = "  release_status: '".$fileData['release_status']."'";
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Return the YAML for a .meta/ path, preferring any user-edited version.
     *
     * @param  string  $metaPath  The .meta/ path (format: ".meta/{name}.jpg.yaml").
     *
     * @throws FilesystemException
     */
    private function readMetaYaml(string $metaPath): string
    {
        $edited = Cache::get($this->metaEditCacheKey($metaPath));

        if ($edited !== null) {
            return $edited;
        }

        $assetPath = $this->assetPathFromMeta($metaPath);
        $data = $this->getCachedFileData($assetPath);

        if ($data === []) {
            throw UnableToReadFile::fromLocation($metaPath, 'asset not found in listing cache');
        }

        return $this->buildMetaYaml($data);
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
     * Convert ".meta/{name}.jpg.yaml" → "{name}.jpg".
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
     * Build a namespaced cache key scoped to this adapter's project ID.
     *
     * @param  string  $key  The key suffix to namespace.
     */
    private function cacheKey(string $key): string
    {
        $cachePrefix = config('vidiq.cache.prefix', self::CACHE_KEY_PREFIX);

        return "{$cachePrefix}.{$this->projectId}.{$key}";
    }

    /**
     * Build the cache key used to store user-edited .meta/ content for a given path.
     *
     * @param  string  $metaPath  The .meta/ path whose user-edited content should be cached.
     */
    private function metaEditCacheKey(string $metaPath): string
    {
        return $this->cacheKey('meta_edit.'.md5($metaPath));
    }
}
