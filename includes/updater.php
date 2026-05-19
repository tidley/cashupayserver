<?php
/**
 * CashuPayServer self-updater for standalone installs.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';

if (!defined('CASHUPAY_UPDATE_REPO')) {
    define('CASHUPAY_UPDATE_REPO', 'tidley/cashupayserver');
}

class Updater {
    private const RELEASE_API = 'https://api.github.com/repos/%s/releases/latest';
    private const PACKAGE_ASSET = 'cashupayserver.zip';
    private const STATUS_FILE = 'update-status.json';

    /**
     * Check the latest GitHub release and compare it to this install.
     */
    public static function getUpdateStatus(): array {
        $release = self::getLatestRelease();
        $asset = self::findAsset($release, self::PACKAGE_ASSET);

        $currentVersion = self::normalizeVersion(CASHUPAY_VERSION);
        $latestTag = (string)($release['tag_name'] ?? '');
        $latestVersion = self::normalizeVersion($latestTag);
        $updateAvailable = $latestVersion !== ''
            && version_compare($latestVersion, $currentVersion, '>');

        return [
            'success' => true,
            'currentVersion' => CASHUPAY_VERSION,
            'latestVersion' => $latestVersion,
            'latestTag' => $latestTag,
            'releaseName' => $release['name'] ?? $latestTag,
            'releaseUrl' => $release['html_url'] ?? null,
            'publishedAt' => $release['published_at'] ?? null,
            'updateAvailable' => $updateAvailable,
            'zipAvailable' => class_exists('ZipArchive'),
            'assetName' => self::PACKAGE_ASSET,
            'assetUrl' => $asset['browser_download_url'] ?? null,
            'assetDigest' => $asset['digest'] ?? null,
            'assetSize' => $asset['size'] ?? null,
        ];
    }

    /**
     * Download and install the latest standalone release package.
     */
    public static function installLatest(): array {
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }

        try {
            self::writeInstallStatus('starting', 'Starting update...');

            if (!class_exists('ZipArchive')) {
                throw new Exception('PHP ZipArchive extension is required for updates.');
            }

            self::writeInstallStatus('checking', 'Checking latest release...');
            $status = self::getUpdateStatus();
            if (empty($status['updateAvailable'])) {
                $result = [
                    'success' => true,
                    'updated' => false,
                    'message' => 'Already up to date.',
                    'currentVersion' => $status['currentVersion'],
                    'latestVersion' => $status['latestVersion'],
                ];
                self::writeInstallStatus('current', 'Already up to date.', $result);
                return $result;
            }

            $assetUrl = $status['assetUrl'] ?? '';
            self::validateAssetUrl($assetUrl, $status['latestTag']);

            $dataDir = Database::getDataDir();
            if (!is_dir($dataDir)) {
                mkdir($dataDir, 0750, true);
            }

            $stamp = gmdate('Ymd-His') . '-' . self::randomSuffix();
            $updatesDir = $dataDir . '/updates';
            $backupDir = $dataDir . '/update-backups/' . $stamp;
            $workDir = $updatesDir . '/' . $stamp;
            $zipPath = $workDir . '/release.zip';
            $extractDir = $workDir . '/extract';

            self::ensureDir($workDir);
            self::ensureDir($extractDir);
            self::ensureDir($backupDir);

            self::writeInstallStatus('downloading', 'Downloading update package...', [
                'tag' => $status['latestTag'],
                'assetSize' => $status['assetSize'] ?? null,
            ]);
            self::downloadFile($assetUrl, $zipPath);

            self::writeInstallStatus('verifying', 'Verifying update package...', ['tag' => $status['latestTag']]);
            self::verifyDigest($zipPath, $status['assetDigest'] ?? null);

            self::writeInstallStatus('extracting', 'Extracting update package...', ['tag' => $status['latestTag']]);
            self::extractZip($zipPath, $extractDir);

            $packageRoot = self::findPackageRoot($extractDir);
            $installRoot = dirname(__DIR__);
            $entries = self::listPackageEntries($packageRoot);

            self::writeInstallStatus('checking_files', 'Checking file permissions...', ['tag' => $status['latestTag']]);
            self::preflightWritable($installRoot, $entries);

            self::writeInstallStatus('installing', 'Installing update files...', ['tag' => $status['latestTag']]);
            self::copyPackage($packageRoot, $installRoot, $backupDir, $entries);

            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }

            self::removeDir($workDir);

            $result = [
                'success' => true,
                'updated' => true,
                'version' => $status['latestVersion'],
                'tag' => $status['latestTag'],
                'backupDir' => $backupDir,
            ];
            self::writeInstallStatus('complete', 'Update installed.', $result);
            return $result;
        } catch (Throwable $e) {
            self::writeInstallStatus('failed', $e->getMessage(), [
                'updated' => false,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public static function getInstallStatus(): array {
        $path = self::statusPath();
        if (!is_file($path)) {
            return [
                'success' => true,
                'state' => 'idle',
                'message' => 'No update has been run yet.',
            ];
        }

        $data = json_decode((string)@file_get_contents($path), true);
        if (!is_array($data)) {
            return [
                'success' => true,
                'state' => 'unknown',
                'message' => 'Update status is unavailable.',
            ];
        }

        $data['success'] = true;
        return $data;
    }

    private static function getLatestRelease(): array {
        $url = sprintf(self::RELEASE_API, CASHUPAY_UPDATE_REPO);
        $response = self::httpGet($url, [
            'Accept: application/vnd.github+json',
            'User-Agent: CashuPayServer/' . CASHUPAY_VERSION,
            'X-GitHub-Api-Version: 2022-11-28',
        ]);

        $release = json_decode($response, true);
        if (!is_array($release)) {
            throw new Exception('Invalid GitHub release response.');
        }
        if (isset($release['message']) && !isset($release['tag_name'])) {
            throw new Exception('GitHub release lookup failed: ' . $release['message']);
        }

        return $release;
    }

    private static function findAsset(array $release, string $name): array {
        foreach (($release['assets'] ?? []) as $asset) {
            if (($asset['name'] ?? '') === $name) {
                return $asset;
            }
        }

        throw new Exception("Release asset not found: {$name}");
    }

    private static function normalizeVersion(string $version): string {
        return ltrim(trim($version), "vV \t\n\r\0\x0B");
    }

    private static function validateAssetUrl(string $url, string $tag): void {
        $expectedPrefix = 'https://github.com/' . CASHUPAY_UPDATE_REPO . '/releases/download/' . rawurlencode($tag) . '/';
        if ($url === '' || strncmp($url, $expectedPrefix, strlen($expectedPrefix)) !== 0) {
            throw new Exception('Unexpected release asset URL.');
        }
    }

    private static function httpGet(string $url, array $headers = []): string {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER => $headers,
            ]);

            $body = curl_exec($ch);
            $error = curl_error($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($body === false || $status >= 400) {
                throw new Exception($error ?: "HTTP request failed with status {$status}");
            }
            return $body;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'timeout' => 30,
                'follow_location' => 1,
                'max_redirects' => 5,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new Exception('HTTP request failed.');
        }
        return $body;
    }

    private static function downloadFile(string $url, string $path): void {
        $fp = fopen($path, 'wb');
        if ($fp === false) {
            throw new Exception('Could not write update package.');
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_FILE => $fp,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_LOW_SPEED_LIMIT => 1024,
                CURLOPT_LOW_SPEED_TIME => 20,
                CURLOPT_HTTPHEADER => [
                    'User-Agent: CashuPayServer/' . CASHUPAY_VERSION,
                ],
            ]);
            $ok = curl_exec($ch);
            $error = curl_error($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            fclose($fp);

            if ($ok === false || $status >= 400) {
                @unlink($path);
                throw new Exception($error ?: "Package download failed with status {$status}");
            }
            return;
        }

        fclose($fp);
        $body = self::httpGet($url, ['User-Agent: CashuPayServer/' . CASHUPAY_VERSION]);
        if (file_put_contents($path, $body) === false) {
            throw new Exception('Could not write update package.');
        }
    }

    private static function extractZip(string $zipPath, string $extractDir): void {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new Exception('Could not open update package.');
        }

        if (!$zip->extractTo($extractDir)) {
            $zip->close();
            throw new Exception('Could not extract update package.');
        }

        $zip->close();
    }

    private static function verifyDigest(string $path, ?string $digest): void {
        if (!$digest || !str_starts_with($digest, 'sha256:')) {
            return;
        }

        $expected = substr($digest, strlen('sha256:'));
        $actual = hash_file('sha256', $path);

        if (!hash_equals(strtolower($expected), strtolower($actual))) {
            throw new Exception('Downloaded update package failed checksum verification.');
        }
    }

    private static function findPackageRoot(string $extractDir): string {
        $expected = $extractDir . '/cashupayserver';
        if (is_file($expected . '/admin.php') && is_dir($expected . '/includes')) {
            return $expected;
        }

        foreach (scandir($extractDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $candidate = $extractDir . '/' . $entry;
            if (is_dir($candidate) && is_file($candidate . '/admin.php') && is_dir($candidate . '/includes')) {
                return $candidate;
            }
        }

        throw new Exception('Update package has an unexpected structure.');
    }

    private static function listPackageEntries(string $packageRoot): array {
        $entries = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($packageRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $path = $item->getPathname();
            $relative = str_replace('\\', '/', substr($path, strlen($packageRoot) + 1));

            if (self::shouldSkip($relative) || $item->isLink()) {
                continue;
            }

            $entries[] = [
                'relative' => $relative,
                'isDir' => $item->isDir(),
                'source' => $path,
            ];
        }

        return $entries;
    }

    private static function shouldSkip(string $relative): bool {
        return $relative === 'data'
            || str_starts_with($relative, 'data/')
            || $relative === 'includes/config.local.php';
    }

    private static function preflightWritable(string $installRoot, array $entries): void {
        foreach ($entries as $entry) {
            $destination = $installRoot . '/' . $entry['relative'];
            if (file_exists($destination)) {
                if (!is_writable($destination)) {
                    throw new Exception('Not writable: ' . $entry['relative']);
                }
                continue;
            }

            $parent = dirname($destination);
            while (!is_dir($parent) && $parent !== dirname($parent)) {
                $parent = dirname($parent);
            }

            if (!is_writable($parent)) {
                throw new Exception('Not writable: ' . dirname($entry['relative']));
            }
        }
    }

    private static function copyPackage(string $packageRoot, string $installRoot, string $backupDir, array $entries): void {
        foreach ($entries as $entry) {
            $relative = $entry['relative'];
            $source = $packageRoot . '/' . $relative;
            $destination = $installRoot . '/' . $relative;

            if ($entry['isDir']) {
                self::ensureDir($destination);
                continue;
            }

            self::ensureDir(dirname($destination));

            if (is_file($destination)) {
                $backupPath = $backupDir . '/' . $relative;
                self::ensureDir(dirname($backupPath));
                if (!copy($destination, $backupPath)) {
                    throw new Exception('Could not back up: ' . $relative);
                }
            }

            if (!copy($source, $destination)) {
                throw new Exception('Could not update: ' . $relative);
            }

            @chmod($destination, fileperms($source) & 0777);
        }
    }

    private static function ensureDir(string $dir): void {
        if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
            throw new Exception('Could not create directory: ' . $dir);
        }
    }

    private static function removeDir(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($dir);
    }

    private static function writeInstallStatus(string $state, string $message, array $extra = []): void {
        try {
            $dataDir = Database::getDataDir();
            if (!is_dir($dataDir)) {
                @mkdir($dataDir, 0750, true);
            }

            $status = array_merge($extra, [
                'state' => $state,
                'message' => $message,
                'updatedAt' => Database::timestamp(),
            ]);

            @file_put_contents(self::statusPath(), json_encode($status));
        } catch (Throwable $e) {
            // Status reporting must never block the update itself.
        }
    }

    private static function statusPath(): string {
        return rtrim(Database::getDataDir(), '/\\') . '/' . self::STATUS_FILE;
    }

    private static function randomSuffix(): string {
        try {
            return bin2hex(random_bytes(4));
        } catch (Exception $e) {
            return substr(str_replace('.', '', uniqid('', true)), -8);
        }
    }
}
