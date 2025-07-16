<?php

die("Remove this when you understand how horrible this script is.\n" .
    "This will delete stuff. Make sure the directory you pass matches \n" .
    "where your torrents save to. Use at your own risk.\n");

// Get vars
$host = getenv("QBT_HOST") or die("QBT_HOST environment variable not set");
$user = getenv("QBT_USER") or die("QBT_USER environment variable not set");
$pass = getenv("QBT_PASS") or die("QBT_PASS environment variable not set");
if (!isset($argv[1])) {
    die("Usage: php clean.php \"/path/to/downloads/folder\"\n");
}

$directory = $argv[1];
if (!is_dir($directory)) {
    die("Invalid download folder specified.\n");
}

// Get new api
try {
    $api = new qBtAPI($host, $user, $pass);

    // Query all torrents
    $torrents = $api->getAllTorrents();
    echo 'Retrieved ' . count($torrents) . ' torrents from API' . PHP_EOL;

    // Query all files from torrents
    $filesInqBitTorrent = $api->getAllTorrentsFiles($torrents);
    echo 'Retrieved ' . count($filesInqBitTorrent) . ' files from API' . PHP_EOL;

    // Query all files on disk
    $filesOnDisk = Utils::listFilesInDirectory($directory);
    echo 'Retrieved ' . count($filesOnDisk) . ' files from path ' . $directory . PHP_EOL;

    $i = 0;
    $filesize = 0;
    sleep(5);
    foreach ($filesOnDisk as $file) {
        // Check if file is in qBittorrent
        if (!array_key_exists($file, $filesInqBitTorrent)) {
            $filesize += filesize($file) ?: 0;
            if (!unlink($file)) {
                echo "Failed to delete file: $file\n";
            }
            $i++;
        }
    }

    $base = log($filesize) / log(1024);
    $suffix = array("", "k", "M", "G", "T")[floor($base)];
    $sizeies = pow(1024, $base - floor($base)) . $suffix;
    echo 'Deleted ' . $i . ' files (' . $sizeies . ') that are not in qBittorrent.' . PHP_EOL;
} catch (Exception $e) {
    die("Error: " . $e->getMessage() . "\n");
}


class qBtAPI {
    private $host;
    private $user;
    private $pass;
    private $cookie;

    /**
     * Constructor for qBtAPI
     * 
     * @param string $host
     * @param string $user
     * @param string $pass
     * 
     * @throws Exception if login fails
     */
    public function __construct(string $host, string $user, string $pass) {
        $this->host = rtrim($host, '/');
        $this->user = $user;
        $this->pass = $pass;

        if (!$this->login()) {
            throw new Exception("Failed to authenticate with qBittorrent API. Check your credentials.");
        }
    }

    /** @var string API endpoint for login */
    private const pathLogin = '/api/v2/auth/login';

    /** @var string Key for content path in torrent data */
    public const contentPathKey = 'content_path';

    /** @var string Key for root path in torrent data */
    public const rootPathKey = 'root_path';

    /** @var string Key for save path in torrent data */
    public const savePathKey = 'save_path';

    /** @var string Key for file path in torrent data */
    public const filePathKey = 'name';

    /**
     * Login to qBittorrent API
     * 
     * @param string $host
     * @param string $user
     * @param string $pass
     * 
     *  @return bool
     */
    private function login(): bool
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->host . static::pathLogin);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['username' => $this->user, 'password' => $this->pass]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);;
        curl_setopt($ch, CURLOPT_HEADER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded',
            'User-Agent: qBittorrent-Cleaner'
        ]);
        
        $response = curl_exec($ch);
        
        // Get login cookie if set
        $cookies = [];
        preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $response,  $matches);
        foreach($matches[1] as $item) {
            parse_str($item,  $cookie);
            $cookies = array_merge($cookies,  $cookie);
        }
        if (!empty($cookies)) {
            $this->cookie = http_build_query($cookies, '', '; ');
        } else {
            return false;
        }

        curl_close($ch);

        return true;
    }

    /**
     * Get all torrents from qBittorrent API
     * 
     * @return array
     */
    public function getAllTorrents(): array
    {
        return $this->queryAPI('GET', '/api/v2/torrents/info');
    }

    /**
     * Get all torrents from qBittorrent API
     * 
     * @return array
     */
    public function getAllTorrentsFiles(array $torrents): array
    {
        $files = [];
        foreach ($torrents as $torrent) {
            // Single file torrent
            if (empty($torrent[static::rootPathKey])) {
                $files[$torrent[static::contentPathKey]] = true;
                continue; // Skip torrents without content path
            }

            // Torrent is folder
            $torrentFiles = $this->queryAPI('GET', '/api/v2/torrents/files?hash=' . $torrent['hash']);
            foreach ($torrentFiles as $torrentFile) {
                $files[$torrent[static::savePathKey] . '/' . $torrentFile[static::filePathKey]] = true;
            }
        }

        return $files;
    }

    /**
     * Get torrent files from qBittorrent API
     * 
     * @param string $method
     * @param string $path
     * @param array|null $data
     *
     * @return array
     */
    private function queryAPI(string $method = 'GET', string $path = '', ?array $data = null): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->host . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_COOKIE, $this->cookie);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded',
            'User-Agent: qBittorrent-Cleaner'
        ]);

        
        // Add data if set
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }
    
        $response = curl_exec($ch);
        // get http response code
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception("API request failed with HTTP code: $httpCode. Response: $response");
        }

        $dataArray = json_decode($response, true) ?? [];
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Failed to decode JSON response: " . json_last_error_msg());
        }

        return $dataArray;
    }
}

class Utils {
    /**
     * List all files in a directory recursively
     * 
     * @param string $folder
     * 
     * @return array
     */
    public static function listFilesInDirectory(string $folder): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($folder, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            } else if ($file->isDir()) {
                $files = array_merge($files, static::listFilesInDirectory($file->getPathname()));
            }
        }

        return $files;
    }
}