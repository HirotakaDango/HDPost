<?php
if (session_status() === PHP_SESSION_NONE) {
  $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
  session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax'
  ]);
  ini_set('session.use_strict_mode', '1');
  session_start();
}

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$baseDir = str_replace('\\', '/', __DIR__);

$config = [
  'app_name'        => 'HDPost',
  'data_dir'        => $baseDir . '/hdpost_data',
  'upload_dir'      => $baseDir . '/hdpost_data/uploads',
  'thumb_dir'       => $baseDir . '/hdpost_data/thumbs',
  'chunk_dir'       => $baseDir . '/hdpost_data/chunks',
  'version_dir'     => $baseDir . '/hdpost_data/versions',
  'db_file'         => $baseDir . '/hdpost_data/hdpost.sqlite',
  'max_chunk_size'  => 2 * 1024 * 1024,
  'thumb_width'     => 480,
  'thumb_quality'   => 88,
  'allow_r18'       => true,
  'allowed_exts'    => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'mp4', 'webm', 'mov', 'mkv', 'ogg']
];

foreach ([$config['data_dir'], $config['upload_dir'], $config['thumb_dir'], $config['chunk_dir'], $config['version_dir']] as $dir) {
  if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
  }
  $htaccessFile = $dir . '/.htaccess';
  if (!file_exists($htaccessFile)) {
    @file_put_contents($htaccessFile, "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order Deny,Allow\n  Deny from all\n</IfModule>");
  }
}

function migrateArtworkAssetsFormat($db, $config) {
  try {
    $stmt = $db->query("
      SELECT ai.id as img_id, ai.artwork_id, ai.file_name, ai.sort_order, a.user_id
      FROM artwork_images ai
      JOIN artworks a ON ai.artwork_id = a.id
      WHERE ai.file_name NOT LIKE 'uid_%'
      ORDER BY ai.artwork_id ASC, ai.sort_order ASC
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) return;

    $db->beginTransaction();
    $updateStmt = $db->prepare("UPDATE artwork_images SET file_name = ? WHERE id = ?");

    foreach ($rows as $row) {
      $oldRel = $row['file_name'];
      $oldFullPath = $config['upload_dir'] . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $oldRel);
      $ext = strtolower(pathinfo($oldRel, PATHINFO_EXTENSION)) ?: 'jpg';
      $sortOrder = (int)$row['sort_order'];
      $userId = (int)$row['user_id'];
      $artId = (int)$row['artwork_id'];

      $newRel = "uid_" . $userId . "/data/imageid-" . $sortOrder . "/imageassets_" . $artId . "/" . $artId . "_i" . $sortOrder . "." . $ext;
      $newFullPath = $config['upload_dir'] . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $newRel);

      if (file_exists($oldFullPath) && is_file($oldFullPath)) {
        $newDir = dirname($newFullPath);
        if (!is_dir($newDir)) @mkdir($newDir, 0755, true);
        @rename($oldFullPath, $newFullPath);

        $oldThumb = $config['thumb_dir'] . DIRECTORY_SEPARATOR . 'thumb_' . basename($oldRel) . '.jpg';
        if (!file_exists($oldThumb)) $oldThumb = $config['thumb_dir'] . DIRECTORY_SEPARATOR . 'thumb_' . basename($oldRel);
        $newThumb = $config['thumb_dir'] . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $newRel) . '.jpg';
        $newThumbDir = dirname($newThumb);
        if (!is_dir($newThumbDir)) @mkdir($newThumbDir, 0755, true);
        if (file_exists($oldThumb)) {
          @rename($oldThumb, $newThumb);
        }
      }

      $updateStmt->execute([$newRel, $row['img_id']]);
    }
    $db->commit();
  } catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
  }
}

function getDB($config) {
  static $db = null;
  if ($db === null) {
    try {
      $db = new PDO('sqlite:' . $config['db_file'], null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 30
      ]);
      $db->exec('PRAGMA journal_mode = WAL;');
      $db->exec('PRAGMA synchronous = NORMAL;');
      $db->exec('PRAGMA foreign_keys = ON;');
      $db->exec('PRAGMA cache_size = -64000;');
      $db->exec('PRAGMA temp_store = MEMORY;');
      $db->exec('PRAGMA mmap_size = 268435456;');
    } catch (Exception $e) {
      die('Database connection error: ' . htmlspecialchars($e->getMessage()));
    }

    $db->exec("
      CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        artist_name TEXT NOT NULL,
        email_hash TEXT NOT NULL UNIQUE,
        password TEXT NOT NULL,
        bio TEXT DEFAULT '',
        avatar TEXT DEFAULT '',
        banner TEXT DEFAULT '',
        twitter TEXT DEFAULT '',
        website TEXT DEFAULT '',
        is_admin INTEGER DEFAULT 0,
        created_at INTEGER NOT NULL
      );

      CREATE TABLE IF NOT EXISTS artworks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        title TEXT NOT NULL,
        description TEXT DEFAULT '',
        type TEXT DEFAULT 'illust',
        rating TEXT DEFAULT 'all',
        is_ai INTEGER DEFAULT 0,
        is_original INTEGER DEFAULT 1,
        tools TEXT DEFAULT '',
        parodies TEXT DEFAULT '',
        characters TEXT DEFAULT '',
        tags TEXT DEFAULT '',
        source_url TEXT DEFAULT '',
        phash TEXT DEFAULT '',
        view_count INTEGER DEFAULT 0,
        like_count INTEGER DEFAULT 0,
        bookmark_count INTEGER DEFAULT 0,
        created_at INTEGER NOT NULL,
        updated_at INTEGER NOT NULL,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
      );

      CREATE TABLE IF NOT EXISTS artwork_images (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        artwork_id INTEGER NOT NULL,
        file_name TEXT NOT NULL,
        file_size INTEGER DEFAULT 0,
        width INTEGER DEFAULT 0,
        height INTEGER DEFAULT 0,
        mime_type TEXT DEFAULT '',
        phash TEXT DEFAULT '',
        sort_order INTEGER DEFAULT 0,
        created_at INTEGER NOT NULL,
        FOREIGN KEY(artwork_id) REFERENCES artworks(id) ON DELETE CASCADE
      );

      CREATE TABLE IF NOT EXISTS tags (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        artwork_id INTEGER NOT NULL,
        tag_name TEXT NOT NULL,
        FOREIGN KEY(artwork_id) REFERENCES artworks(id) ON DELETE CASCADE
      );

      CREATE TABLE IF NOT EXISTS likes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        artwork_id INTEGER NOT NULL,
        user_id INTEGER DEFAULT 0,
        ip TEXT DEFAULT '',
        created_at INTEGER NOT NULL,
        UNIQUE(artwork_id, user_id, ip),
        FOREIGN KEY(artwork_id) REFERENCES artworks(id) ON DELETE CASCADE
      );

      CREATE TABLE IF NOT EXISTS follows (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        follower_id INTEGER NOT NULL,
        following_id INTEGER NOT NULL,
        created_at INTEGER NOT NULL,
        UNIQUE(follower_id, following_id),
        FOREIGN KEY(follower_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY(following_id) REFERENCES users(id) ON DELETE CASCADE
      );

      CREATE TABLE IF NOT EXISTS comments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        artwork_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        parent_id INTEGER DEFAULT 0,
        comment TEXT NOT NULL,
        created_at INTEGER NOT NULL,
        updated_at INTEGER DEFAULT 0,
        FOREIGN KEY(artwork_id) REFERENCES artworks(id) ON DELETE CASCADE,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
      );

      CREATE TABLE IF NOT EXISTS activity_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        action TEXT NOT NULL,
        target_id INTEGER DEFAULT 0,
        details TEXT DEFAULT '',
        created_at INTEGER NOT NULL,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
      );

      CREATE INDEX IF NOT EXISTS idx_artworks_user ON artworks(user_id);
      CREATE INDEX IF NOT EXISTS idx_artworks_type ON artworks(type);
      CREATE INDEX IF NOT EXISTS idx_artworks_rating ON artworks(rating);
      CREATE INDEX IF NOT EXISTS idx_artworks_created ON artworks(created_at DESC);
      CREATE INDEX IF NOT EXISTS idx_artworks_likes ON artworks(like_count DESC);
      CREATE INDEX IF NOT EXISTS idx_artwork_images_art ON artwork_images(artwork_id, sort_order ASC);
      CREATE INDEX IF NOT EXISTS idx_tags_name ON tags(tag_name);
      CREATE INDEX IF NOT EXISTS idx_tags_artwork ON tags(artwork_id);
      CREATE INDEX IF NOT EXISTS idx_follows_pair ON follows(follower_id, following_id);
      CREATE INDEX IF NOT EXISTS idx_comments_art ON comments(artwork_id, created_at ASC);

      CREATE TABLE IF NOT EXISTS rate_limits (
        rate_key TEXT PRIMARY KEY,
        hits INTEGER DEFAULT 1,
        expires_at INTEGER NOT NULL
      );

      CREATE TABLE IF NOT EXISTS encyclopedias (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        category TEXT NOT NULL,
        name TEXT NOT NULL,
        body TEXT DEFAULT '',
        updated_at INTEGER NOT NULL,
        updated_by INTEGER DEFAULT 0,
        UNIQUE(category, name)
      );
      CREATE INDEX IF NOT EXISTS idx_encyclopedias_cat_name ON encyclopedias(category, name);

      CREATE TABLE IF NOT EXISTS encyclopedia_revisions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        category TEXT NOT NULL,
        name TEXT NOT NULL,
        body TEXT DEFAULT '',
        edit_summary TEXT DEFAULT '',
        user_id INTEGER NOT NULL,
        created_at INTEGER NOT NULL
      );
      CREATE INDEX IF NOT EXISTS idx_enc_rev_cat_name ON encyclopedia_revisions(category, name, created_at DESC);
    ");

    $artCols = $db->query("PRAGMA table_info(artworks)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('source_url', $artCols)) @$db->exec("ALTER TABLE artworks ADD COLUMN source_url TEXT DEFAULT ''");
    if (!in_array('phash', $artCols)) @$db->exec("ALTER TABLE artworks ADD COLUMN phash TEXT DEFAULT ''");

    $commCols = $db->query("PRAGMA table_info(comments)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('parent_id', $commCols)) @$db->exec("ALTER TABLE comments ADD COLUMN parent_id INTEGER DEFAULT 0");
    if (!in_array('updated_at', $commCols)) @$db->exec("ALTER TABLE comments ADD COLUMN updated_at INTEGER DEFAULT 0");

    $imgCols = $db->query("PRAGMA table_info(artwork_images)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('mime_type', $imgCols)) @$db->exec("ALTER TABLE artwork_images ADD COLUMN mime_type TEXT DEFAULT ''");
    if (!in_array('phash', $imgCols)) @$db->exec("ALTER TABLE artwork_images ADD COLUMN phash TEXT DEFAULT ''");

    $userCols = $db->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('is_banned', $userCols)) @$db->exec("ALTER TABLE users ADD COLUMN is_banned INTEGER DEFAULT 0");

    migrateArtworkAssetsFormat($db, $config);
  }
  return $db;
}

function jsonResponse($data, $status = 200) {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}

function getCurrentUser($db) {
  if (session_status() === PHP_SESSION_NONE) {
    @session_start();
  }

  $uid = (int)($_SESSION['user_id'] ?? 0);
  if ($uid <= 0) {
    unset($_SESSION['user_id']);
    return null;
  }

  try {
    $stmt = $db->prepare("SELECT id, username, artist_name, email_hash, bio, avatar, banner, twitter, website, is_admin, is_banned, created_at FROM users WHERE id = ?");
    $stmt->execute([$uid]);
    $user = $stmt->fetch() ?: null;
    if (!$user || !empty($user['is_banned'])) {
      unset($_SESSION['user_id']);
      return null;
    }
    $_SESSION['user_id'] = (int)$user['id'];
    return $user;
  } catch (Exception $e) {
    return null;
  }
}

function requireAuth($db) {
  $user = getCurrentUser($db);
  if (!$user) {
    jsonResponse(['error' => 'Authentication required'], 401);
  }
  return $user;
}

function requireAdmin($db) {
  $user = requireAuth($db);
  if ((int)$user['is_admin'] < 1) {
    jsonResponse(['error' => 'Admin privileges required'], 403);
  }
  return $user;
}

function verifyCsrfToken() {
  $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
  if (empty($_SESSION['csrf_token']) || empty($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
    jsonResponse(['error' => 'Security token invalid or expired. Please refresh the page.'], 403);
  }
}

function checkRateLimit($db, $actionKey, $maxHits = 15, $windowSeconds = 60) {
  $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
  $rateKey = hash('sha256', $ip . ':' . $actionKey);
  $now = time();

  $stmt = $db->prepare("SELECT hits, expires_at FROM rate_limits WHERE rate_key = ?");
  $stmt->execute([$rateKey]);
  $record = $stmt->fetch();

  if ($record) {
    if ($now > (int)$record['expires_at']) {
      $db->prepare("UPDATE rate_limits SET hits = 1, expires_at = ? WHERE rate_key = ?")->execute([$now + $windowSeconds, $rateKey]);
      return true;
    }
    if ((int)$record['hits'] >= $maxHits) {
      return false;
    }
    $db->prepare("UPDATE rate_limits SET hits = hits + 1 WHERE rate_key = ?")->execute([$rateKey]);
    return true;
  }

  $db->prepare("INSERT INTO rate_limits (rate_key, hits, expires_at) VALUES (?, 1, ?)")->execute([$rateKey, $now + $windowSeconds]);
  return true;
}

function cleanupStaleChunks($chunkDir, $maxAge = 7200) {
  if (!is_dir($chunkDir)) return;
  $now = time();
  $items = @scandir($chunkDir) ?: [];
  foreach ($items as $item) {
    if ($item === '.' || $item === '..' || $item === '.htaccess') continue;
    $targetPath = $chunkDir . DIRECTORY_SEPARATOR . $item;
    if (is_dir($targetPath)) {
      $dirMtime = @filemtime($targetPath) ?: 0;
      if (($now - $dirMtime) > $maxAge) {
        $files = @scandir($targetPath) ?: [];
        foreach ($files as $f) {
          if ($f !== '.' && $f !== '..') @unlink($targetPath . DIRECTORY_SEPARATOR . $f);
        }
        @rmdir($targetPath);
      }
    }
  }
}

function logActivity($db, $userId, $action, $targetId = 0, $details = '') {
  $stmt = $db->prepare("INSERT INTO activity_log (user_id, action, target_id, details, created_at) VALUES (?, ?, ?, ?, ?)");
  $stmt->execute([$userId, $action, $targetId, $details, time()]);
}

function hashEmail($email) {
  return hash('sha256', strtolower(trim($email)));
}

function getArtworkThumbnailPath($fileName, $config) {
  if (empty($fileName)) return '';
  $osRel = str_replace('/', DIRECTORY_SEPARATOR, $fileName);
  $candidates = [
    $config['thumb_dir'] . DIRECTORY_SEPARATOR . $osRel . '.jpg',
    $config['thumb_dir'] . DIRECTORY_SEPARATOR . $osRel,
    $config['thumb_dir'] . DIRECTORY_SEPARATOR . 'thumb_' . basename($fileName) . '.jpg',
    $config['thumb_dir'] . DIRECTORY_SEPARATOR . 'thumb_' . basename($fileName)
  ];
  foreach ($candidates as $cand) {
    if (file_exists($cand) && is_file($cand)) return $cand;
  }
  $rawPath = $config['upload_dir'] . DIRECTORY_SEPARATOR . $osRel;
  if (file_exists($rawPath) && is_file($rawPath)) {
    $targetThumb = $config['thumb_dir'] . DIRECTORY_SEPARATOR . $osRel . '.jpg';
    if (!is_dir(dirname($targetThumb))) @mkdir(dirname($targetThumb), 0755, true);
    if (createThumbnail($rawPath, $targetThumb, $config['thumb_width'], $config['thumb_quality'])) {
      return $targetThumb;
    }
    return $rawPath;
  }
  return '';
}

function compute_phash($path) {
  if (!file_exists($path)) return '';
  $info = @getimagesize($path);
  if (!$info) return '';
  $mime = $info['mime'];
  $src = null;
  switch ($mime) {
    case 'image/jpeg': $src = @imagecreatefromjpeg($path); break;
    case 'image/png':  $src = @imagecreatefrompng($path); break;
    case 'image/gif':  $src = @imagecreatefromgif($path); break;
    case 'image/webp': $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : null; break;
    case 'image/avif': $src = function_exists('imagecreatefromavif') ? @imagecreatefromavif($path) : null; break;
    case 'image/bmp':  $src = function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($path) : null; break;
  }
  if (!$src) return '';

  // Difference Hash (dHash): 9x8 matrix tracking horizontal gradients
  $small = imagecreatetruecolor(9, 8);
  imagecopyresampled($small, $src, 0, 0, 0, 0, 9, 8, imagesx($src), imagesy($src));
  imagedestroy($src);

  $hash = '';
  for ($y = 0; $y < 8; $y++) {
    $rowGrays = [];
    for ($x = 0; $x < 9; $x++) {
      $rgb = imagecolorat($small, $x, $y);
      $rowGrays[] = (int)((($rgb >> 16 & 0xFF) * 0.299) + (($rgb >> 8 & 0xFF) * 0.587) + (($rgb & 0xFF) * 0.114));
    }
    for ($x = 0; $x < 8; $x++) {
      $hash .= ($rowGrays[$x + 1] > $rowGrays[$x]) ? '1' : '0';
    }
  }
  imagedestroy($small);
  return 'd:' . $hash;
}

function hamming_distance($h1, $h2) {
  if (is_string($h1) && strpos($h1, 'd:') === 0) $h1 = substr($h1, 2);
  if (is_string($h2) && strpos($h2, 'd:') === 0) $h2 = substr($h2, 2);
  if (strlen($h1) !== 64 || strlen($h2) !== 64) return 64;
  $dist = 0;
  for ($i = 0; $i < 64; $i++) {
    if ($h1[$i] !== $h2[$i]) $dist++;
  }
  return $dist;
}

function createThumbnail($src, $dest, $targetWidth = 480, $quality = 88) {
  if (!file_exists($src)) return false;
  $info = @getimagesize($src);
  if (!$info) return false;

  list($w, $h) = $info;
  $mime = $info['mime'];
  $srcImg = null;

  switch ($mime) {
    case 'image/jpeg': $srcImg = @imagecreatefromjpeg($src); break;
    case 'image/png':  $srcImg = @imagecreatefrompng($src); break;
    case 'image/gif':  $srcImg = @imagecreatefromgif($src); break;
    case 'image/webp': $srcImg = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : null; break;
    case 'image/avif': $srcImg = function_exists('imagecreatefromavif') ? @imagecreatefromavif($src) : null; break;
    case 'image/bmp':  $srcImg = function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($src) : null; break;
  }
  if (!$srcImg) return false;

  if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
    $exif = @exif_read_data($src);
    if (!empty($exif['Orientation'])) {
      switch ($exif['Orientation']) {
        case 3: $srcImg = imagerotate($srcImg, 180, 0); break;
        case 6:
          $srcImg = imagerotate($srcImg, -90, 0);
          list($w, $h) = [$h, $w];
          break;
        case 8:
          $srcImg = imagerotate($srcImg, 90, 0);
          list($w, $h) = [$h, $w];
          break;
      }
    }
  }

  $ratio = min($targetWidth / $w, 1.0);
  $targetHeight = max(1, (int)round($h * $ratio));
  $finalWidth = max(1, (int)round($w * $ratio));

  $destImg = imagecreatetruecolor($finalWidth, $targetHeight);
  if ($mime === 'image/png' || $mime === 'image/webp') {
    imagealphablending($destImg, false);
    imagesavealpha($destImg, true);
    $transparent = imagecolorallocatealpha($destImg, 255, 255, 255, 127);
    imagefilledrectangle($destImg, 0, 0, $finalWidth, $targetHeight, $transparent);
  }
  imagecopyresampled($destImg, $srcImg, 0, 0, 0, 0, $finalWidth, $targetHeight, $w, $h);

  $ok = imagejpeg($destImg, $dest, $quality);
  imagedestroy($srcImg);
  imagedestroy($destImg);
  return $ok;
}

function getFileMime($path, $fallback = 'image/jpeg') {
  $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
  $map = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
    'avif' => 'image/avif',
    'bmp'  => 'image/bmp',
    'svg'  => 'image/svg+xml',
    'mp4'  => 'video/mp4',
    'webm' => 'video/webm',
    'mov'  => 'video/quicktime',
    'mkv'  => 'video/x-matroska',
    'ogg'  => 'video/ogg'
  ];
  if (isset($map[$ext])) return $map[$ext];
  if (function_exists('mime_content_type')) {
    $detected = @mime_content_type($path);
    if ($detected && $detected !== 'application/octet-stream') return $detected;
  }
  return $fallback;
}

function streamRangeFile($path, $mime) {
  if (empty($mime) || $mime === 'application/octet-stream') {
    $mime = getFileMime($path);
  }
  if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
  @ini_set('zlib.output_compression', 'Off');
  while (ob_get_level() > 0) @ob_end_clean();

  $filesize = @filesize($path);
  if ($filesize === false || $filesize <= 0) {
    header('HTTP/1.1 404 Not Found');
    exit;
  }

  $start = 0;
  $end = $filesize - 1;
  $isRange = false;

  if (isset($_SERVER['HTTP_RANGE'])) {
    $range = $_SERVER['HTTP_RANGE'];
    if (preg_match('/bytes=\s*(\d+)?\s*-\s*(\d+)?/i', $range, $matches)) {
      if (isset($matches[1]) && $matches[1] !== '') {
        $start = floatval($matches[1]);
        if (isset($matches[2]) && $matches[2] !== '') {
          $end = min($filesize - 1, floatval($matches[2]));
        }
      } elseif (isset($matches[2]) && $matches[2] !== '') {
        $start = max(0, $filesize - floatval($matches[2]));
      }
      if ($start > $end || $start >= $filesize) {
        header('HTTP/1.1 416 Requested Range Not Satisfiable');
        header('Content-Range: bytes */' . sprintf('%.0f', $filesize));
        exit;
      }
      $isRange = true;
    }
  }

  $length = $end - $start + 1;
  if ($isRange) {
    header('HTTP/1.1 206 Partial Content', true, 206);
    header('Content-Range: bytes ' . sprintf('%.0f-%.0f/%.0f', $start, $end, $filesize));
  } else {
    header('HTTP/1.1 200 OK', true, 200);
  }

  header('Content-Type: ' . $mime);
  header('Accept-Ranges: bytes');
  header('Content-Length: ' . sprintf('%.0f', $length));
  header('X-Content-Type-Options: nosniff');
  header('Cache-Control: public, max-age=31536000, immutable');
  header('Access-Control-Allow-Origin: *');

  $fp = @fopen($path, 'rb');
  if ($fp) {
    if ($start > 0) @fseek($fp, (int)$start, SEEK_SET);
    $bytesLeft = $length;
    $bufferSize = 256 * 1024;
    while (!feof($fp) && $bytesLeft > 0) {
      if (connection_aborted()) break;
      $read = (int)min($bufferSize, $bytesLeft);
      $buff = fread($fp, $read);
      if ($buff === false || $buff === '') break;
      echo $buff;
      @flush();
      $bytesLeft -= strlen($buff);
    }
    fclose($fp);
  }
  exit;
}

if (isset($_GET['pwa'])) {
  $pwaMode = $_GET['pwa'];
  if ($pwaMode === 'manifest') {
    header('Content-Type: application/manifest+json; charset=utf-8');
    echo json_encode([
      "name"             => $config['app_name'],
      "short_name"       => "HDPost",
      "start_url"        => "./",
      "scope"            => "./",
      "display"          => "standalone",
      "orientation"      => "any",
      "background_color" => "#121216",
      "theme_color"      => "#0096fa",
      "description"      => "High-definition creative illustration studio and artwork cloud archive.",
      "icons"            => [
        ["src" => "?action=icon", "sizes" => "192x192", "type" => "image/svg+xml", "purpose" => "any maskable"],
        ["src" => "?action=icon", "sizes" => "512x512", "type" => "image/svg+xml", "purpose" => "any maskable"]
      ]
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
  }
  if ($pwaMode === 'sw') {
    header('Content-Type: application/javascript; charset=utf-8');
    echo <<<SW
const CACHE_NAME = 'hdpost-cache-v2';
const STATIC_ASSETS = [
  './',
  '?action=icon',
  'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap',
  'https://cdn.jsdelivr.net/npm/marked/marked.min.js',
  'https://cdnjs.cloudflare.com/ajax/libs/dompurify/3.0.8/purify.min.js'
];
self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE_NAME).then(c => c.addAll(STATIC_ASSETS.map(u => new Request(u, {mode:'cors'})))).then(() => self.skipWaiting()));
});
self.addEventListener('activate', e => {
  e.waitUntil(caches.keys().then(ks => Promise.all(ks.map(k => k !== CACHE_NAME ? caches.delete(k) : null))).then(() => self.clients.claim()));
});
self.addEventListener('fetch', e => {
  const url = new URL(e.request.url);
  if (e.request.method !== 'GET' || url.searchParams.has('action') || (url.pathname.endsWith('.php') && url.searchParams.size > 0 && !url.searchParams.has('pwa'))) return;
  if (url.hostname.includes('fonts.googleapis.com') || url.hostname.includes('fonts.gstatic.com') || url.hostname.includes('cdn.jsdelivr.net') || url.hostname.includes('cdnjs.cloudflare.com')) {
    e.respondWith(caches.match(e.request).then(r => r || fetch(e.request).then(nr => {
      if (nr && nr.status === 200) { const cl = nr.clone(); caches.open(CACHE_NAME).then(c => c.put(e.request, cl)); }
      return nr;
    })));
  }
});
SW;
    exit;
  }
}

try {
  $db = getDB($config);
  $currentUser = getCurrentUser($db);
  $isInitialSetup = ((int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn() === 0);
} catch (Exception $e) {
  die('Startup Error: ' . htmlspecialchars($e->getMessage()));
}
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

if ($isInitialSetup && $action && !in_array($action, ['system_setup', 'icon'])) {
  jsonResponse(['error' => 'Initial setup required.', 'needs_setup' => true], 403);
}

if ($action === 'icon') {
  header('Content-Type: image/svg+xml; charset=utf-8');
  header('Cache-Control: public, max-age=604800, immutable');
  echo <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="512" height="512" fill="#0096fa">
  <path d="M13.73 15l-3.9 6.76c.7.15 1.42.24 2.17.24 2.4 0 4.6-.85 6.32-2.25l-3.66-6.35m-12.2 1.6c.92 2.92 3.15 5.26 5.99 6.34l3.67-6.34m-3.58-3l-3.9-6.75C2.99 7 2 9.39 2 12c0 .68.07 1.35.2 2h7.49m12.11-4h-7.49l.29.5 4.76 8.25c1.64-1.78 2.64-4.15 2.64-6.75 0-.69-.07-1.36-.2-2m-.26-1c-.92-2.93-3.15-5.26-5.99-6.34l-3.67 6.34m-2.48 1.5l4.77-8.26C13.47 2.09 12.75 2 12 2c-2.4 0-4.6.84-6.32 2.25l3.66 6.35.06-.1z"/>
</svg>
SVG;
  exit;
}

if ($action) {
  if ($action === 'check_url') {
    $rawUrls = trim($_GET['url'] ?? '');
    $urls = preg_split('/[\r\n,\s]+/u', $rawUrls, -1, PREG_SPLIT_NO_EMPTY);
    $dup = null;
    $hasValid = false;

    foreach ($urls as $u) {
      if (filter_var($u, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $u)) {
        $hasValid = true;
        $stmt = $db->prepare("SELECT id, title FROM artworks WHERE source_url LIKE ? LIMIT 1");
        $stmt->execute(['%' . $u . '%']);
        $found = $stmt->fetch();
        if ($found) {
          $dup = $found;
          break;
        }
      }
    }

    jsonResponse([
      'valid'     => $hasValid,
      'duplicate' => $dup ?: null
    ]);
  }

  if ($action === 'auth_register') {
    verifyCsrfToken();
    if (!checkRateLimit($db, 'auth_register', 5, 300)) {
      jsonResponse(['error' => 'Too many registration attempts. Please wait 5 minutes.'], 429);
    }

    $artistName = trim($_POST['artist_name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    if (empty($artistName) || empty($email) || empty($password)) {
      jsonResponse(['error' => 'Display name, email, and password are required.'], 400);
    }
    if (mb_strlen($artistName) > 60) {
      jsonResponse(['error' => 'Artist name must not exceed 60 characters.'], 400);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      jsonResponse(['error' => 'Invalid email address.'], 400);
    }
    if (strlen($password) < 8 || strlen($password) > 128) {
      jsonResponse(['error' => 'Password must be between 8 and 128 characters.'], 400);
    }

    $emailHash = hashEmail($email);
    $stmt = $db->prepare("SELECT id FROM users WHERE email_hash = ?");
    $stmt->execute([$emailHash]);
    if ($stmt->fetch()) {
      jsonResponse(['error' => 'Email is already registered.'], 400);
    }

    $username = 'u_' . bin2hex(random_bytes(8));
    $hashPass = password_hash($password, PASSWORD_BCRYPT);
    $now = time();
    $stmt = $db->prepare("INSERT INTO users (username, artist_name, email_hash, password, created_at) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$username, $artistName, $emailHash, $hashPass, $now]);

    $newId = (int)$db->lastInsertId();
    session_regenerate_id(true);
    $_SESSION['user_id'] = $newId;
    logActivity($db, $newId, 'register', $newId, 'Joined the artist community');
    jsonResponse(['success' => true, 'user' => getCurrentUser($db)]);
  }

  if ($action === 'system_setup') {
    verifyCsrfToken();
    $count = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($count > 0) {
      jsonResponse(['error' => 'System has already been configured.'], 400);
    }
    $artistName = trim($_POST['artist_name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    if (empty($artistName) || empty($email) || empty($password)) {
      jsonResponse(['error' => 'Display name, email, and password are required.'], 400);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      jsonResponse(['error' => 'Valid email address required.'], 400);
    }
    if (strlen($password) < 8 || strlen($password) > 128) {
      jsonResponse(['error' => 'Password must be between 8 and 128 characters.'], 400);
    }

    $username = 'admin_' . bin2hex(random_bytes(6));
    $emailHash = hashEmail($email);
    $hashPass = password_hash($password, PASSWORD_BCRYPT);
    $now = time();

    // The first created account is permanently assigned Super Admin (is_admin = 2)
    $stmt = $db->prepare("INSERT INTO users (username, artist_name, email_hash, password, is_admin, bio, created_at) VALUES (?, ?, ?, ?, 2, 'Primary Super Administrator', ?)");
    $stmt->execute([$username, $artistName, $emailHash, $hashPass, $now]);
    $superAdminId = (int)$db->lastInsertId();

    session_regenerate_id(true);
    $_SESSION['user_id'] = $superAdminId;
    logActivity($db, $superAdminId, 'setup', $superAdminId, 'System initialized with Super Administrator account');
    jsonResponse(['success' => true, 'user' => getCurrentUser($db)]);
  }

  if ($action === 'auth_login') {
    verifyCsrfToken();
    if (!checkRateLimit($db, 'auth_login', 6, 60)) {
      jsonResponse(['error' => 'Too many login attempts. Please wait 1 minute.'], 429);
    }

    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    $stmt = $db->prepare("SELECT * FROM users WHERE email_hash = ?");
    $stmt->execute([hashEmail($email)]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
      if (!empty($user['is_banned'])) {
        jsonResponse(['error' => 'This account has been banned/suspended by administration.'], 403);
      }
      session_regenerate_id(true);
      $_SESSION['user_id'] = (int)$user['id'];
      unset($user['password']);
      logActivity($db, $user['id'], 'login', $user['id'], 'Logged into dashboard');
      jsonResponse(['success' => true, 'user' => $user]);
    }
    jsonResponse(['error' => 'Invalid email or password.'], 401);
  }

  if ($action === 'auth_logout') {
    verifyCsrfToken();
    if ($currentUser) logActivity($db, $currentUser['id'], 'logout', $currentUser['id'], 'Logged out');
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
      $params = session_get_cookie_params();
      setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    jsonResponse(['success' => true]);
  }

  if ($action === 'auth_me') {
    jsonResponse(['user' => $currentUser]);
  }

  if ($action === 'update_profile') {
    verifyCsrfToken();
    $user = requireAuth($db);

    // Guard against Mass Assignment: strictly disallow modification of internal/security columns
    $forbiddenParams = ['is_admin', 'is_banned', 'password', 'email_hash', 'email', 'id', 'created_at', 'role'];
    foreach ($forbiddenParams as $param) {
      if (array_key_exists($param, $_POST)) {
        jsonResponse(['error' => "Modification of restricted field '{$param}' is prohibited."], 400);
      }
    }

    $artistName = mb_substr(trim($_POST['artist_name'] ?? $user['artist_name']), 0, 60);
    $bio = mb_substr(trim($_POST['bio'] ?? $user['bio']), 0, 1000);
    $avatar = trim($_POST['avatar'] ?? $user['avatar']);
    $banner = trim($_POST['banner'] ?? $user['banner']);
    $twitter = preg_replace('/[^a-zA-Z0-9_@]/', '', trim($_POST['twitter'] ?? $user['twitter']));
    $website = trim($_POST['website'] ?? $user['website']);

    if ($website !== '' && (!filter_var($website, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $website))) {
      $website = '';
    }

    if (strpos($avatar, 'data:image') === 0) {
      $aData = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $avatar));
      if ($aData !== false && strlen($aData) <= 5 * 1024 * 1024) {
        $info = @getimagesizefromstring($aData);
        if ($info && in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
          $aFile = 'avatar_' . $user['id'] . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.jpg';
          file_put_contents($config['upload_dir'] . DIRECTORY_SEPARATOR . $aFile, $aData);
          $avatar = '?action=raw&f=' . $aFile;
        }
      }
    } elseif ($avatar !== '' && (!filter_var($avatar, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $avatar)) && strpos($avatar, '?action=raw&f=') !== 0) {
      $avatar = '';
    }

    if (strpos($banner, 'data:image') === 0) {
      $bData = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $banner));
      if ($bData !== false && strlen($bData) <= 8 * 1024 * 1024) {
        $info = @getimagesizefromstring($bData);
        if ($info && in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
          $bFile = 'banner_' . $user['id'] . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.jpg';
          file_put_contents($config['upload_dir'] . DIRECTORY_SEPARATOR . $bFile, $bData);
          $banner = '?action=raw&f=' . $bFile;
        }
      }
    } elseif ($banner !== '' && (!filter_var($banner, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $banner)) && strpos($banner, '?action=raw&f=') !== 0) {
      $banner = '';
    }

    $stmt = $db->prepare("UPDATE users SET artist_name = ?, bio = ?, avatar = ?, banner = ?, twitter = ?, website = ? WHERE id = ?");
    $stmt->execute([$artistName, $bio, $avatar, $banner, $twitter, $website, $user['id']]);
    logActivity($db, $user['id'], 'profile_update', $user['id'], 'Updated profile details');
    jsonResponse(['success' => true, 'user' => getCurrentUser($db)]);
  }

  if ($action === 'account_delete') {
    verifyCsrfToken();
    $user = requireAuth($db);
    $password = $_POST['password'] ?? '';

    if ((int)$user['is_admin'] === 2) {
      jsonResponse(['error' => 'The Super Administrator account cannot be deleted.'], 403);
    }

    if (empty($password)) {
      jsonResponse(['error' => 'Password confirmation is required.'], 400);
    }

    $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$user['id']]);
    $realPass = $stmt->fetchColumn();

    if (!$realPass || !password_verify($password, $realPass)) {
      jsonResponse(['error' => 'Incorrect password. Account deletion cancelled.'], 401);
    }

    // Delete uploaded artwork files from disk
    $stmtFiles = $db->prepare("
      SELECT ai.file_name FROM artwork_images ai
      JOIN artworks a ON ai.artwork_id = a.id
      WHERE a.user_id = ?
    ");
    $stmtFiles->execute([$user['id']]);
    $files = $stmtFiles->fetchAll(PDO::FETCH_COLUMN);

    foreach ($files as $fName) {
      @unlink($config['upload_dir'] . DIRECTORY_SEPARATOR . $fName);
      @unlink($config['thumb_dir'] . DIRECTORY_SEPARATOR . 'thumb_' . $fName . '.jpg');
      @unlink($config['thumb_dir'] . DIRECTORY_SEPARATOR . 'thumb_' . $fName);
    }

    // Delete custom uploaded avatar/banner files
    if (!empty($user['avatar']) && strpos($user['avatar'], '?action=raw&f=') === 0) {
      $aFile = basename(str_replace('?action=raw&f=', '', $user['avatar']));
      @unlink($config['upload_dir'] . DIRECTORY_SEPARATOR . $aFile);
    }
    if (!empty($user['banner']) && strpos($user['banner'], '?action=raw&f=') === 0) {
      $bFile = basename(str_replace('?action=raw&f=', '', $user['banner']));
      @unlink($config['upload_dir'] . DIRECTORY_SEPARATOR . $bFile);
    }

    // Delete user record (foreign key ON DELETE CASCADE clears artworks, images, comments, likes, follows)
    $db->prepare("DELETE FROM users WHERE id = ?")->execute([$user['id']]);

    // Terminate session
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
      $params = session_get_cookie_params();
      setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();

    jsonResponse(['success' => true, 'message' => 'Your account and all associated data have been permanently deleted.']);
  }

  if ($action === 'change_password') {
    verifyCsrfToken();
    $user = requireAuth($db);
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';

    $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$user['id']]);
    $realPass = $stmt->fetchColumn();

    if (!password_verify($current, $realPass)) {
      jsonResponse(['error' => 'Current password incorrect.'], 400);
    }
    if (strlen($new) < 8 || strlen($new) > 128) {
      jsonResponse(['error' => 'New password must be between 8 and 128 characters.'], 400);
    }

    $hash = password_hash($new, PASSWORD_BCRYPT);
    $db->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hash, $user['id']]);
    jsonResponse(['success' => true, 'message' => 'Password updated successfully.']);
  }

  if ($action === 'upload_chunk') {
    verifyCsrfToken();
    $user = requireAuth($db);
    if (!checkRateLimit($db, 'upload_chunk', 400, 60)) {
      jsonResponse(['error' => 'Upload rate limit exceeded. Please wait.'], 429);
    }

    // Opportunistically garbage collect abandoned chunks older than 2 hours
    if (mt_rand(1, 15) === 1) {
      cleanupStaleChunks($config['chunk_dir'], 7200);
    }

    $uploadId = preg_replace('/[^\w\-]/', '', $_POST['upload_id'] ?? '');
    $chunkIndex = intval($_POST['chunk_index'] ?? 0);
    $totalChunks = intval($_POST['total_chunks'] ?? 1);
    $fileName = trim($_POST['file_name'] ?? '');
    $thumbData = $_POST['thumb_data'] ?? null;

    if ($totalChunks < 1 || $totalChunks > 500 || $chunkIndex < 0 || $chunkIndex >= $totalChunks) {
      jsonResponse(['error' => 'Invalid chunk parameters.'], 400);
    }

    if (!$uploadId || strlen($uploadId) > 64 || !$fileName || empty($_FILES['chunk']['tmp_name'])) {
      jsonResponse(['error' => 'Missing chunk payload'], 400);
    }

    // Cap pending staging directories to prevent storage exhaustion attacks
    $tempDir = $config['chunk_dir'] . DIRECTORY_SEPARATOR . $uploadId;
    if (!is_dir($tempDir)) {
      $stagedUploads = glob($config['chunk_dir'] . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [];
      if (count($stagedUploads) >= 80) {
        cleanupStaleChunks($config['chunk_dir'], 3600);
        $stagedUploads = glob($config['chunk_dir'] . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [];
        if (count($stagedUploads) >= 80) {
          jsonResponse(['error' => 'Temporary upload capacity full. Please wait a moment.'], 503);
        }
      }
    }

    if ($_FILES['chunk']['size'] > ($config['max_chunk_size'] + 65536)) {
      jsonResponse(['error' => 'Chunk exceeds maximum allowed chunk size.'], 400);
    }

    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if (!in_array($ext, $config['allowed_exts'], true)) {
      jsonResponse(['error' => 'Invalid file format.'], 400);
    }

    $tempDir = $config['chunk_dir'] . DIRECTORY_SEPARATOR . $uploadId;
    if (!is_dir($tempDir)) @mkdir($tempDir, 0755, true);

    $chunkFile = $tempDir . DIRECTORY_SEPARATOR . "chunk_{$chunkIndex}";
    if (!@move_uploaded_file($_FILES['chunk']['tmp_name'], $chunkFile)) {
      jsonResponse(['error' => 'Failed to save chunk.'], 500);
    }
    @touch($tempDir);

    $allReady = true;
    for ($i = 0; $i < $totalChunks; $i++) {
      if (!file_exists($tempDir . DIRECTORY_SEPARATOR . "chunk_{$i}")) {
        $allReady = false;
        break;
      }
    }

    if ($allReady) {
      $finalName = 'art_' . date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
      $finalPath = $config['upload_dir'] . DIRECTORY_SEPARATOR . $finalName;
      $out = fopen($finalPath, 'wb');

      for ($i = 0; $i < $totalChunks; $i++) {
        $cPath = $tempDir . DIRECTORY_SEPARATOR . "chunk_{$i}";
        $in = fopen($cPath, 'rb');
        while ($buff = fread($in, 65536)) fwrite($out, $buff);
        fclose($in);
        @unlink($cPath);
      }
      fclose($out);
      @rmdir($tempDir);

      $mimeType = mime_content_type($finalPath) ?: 'application/octet-stream';
      $disallowedMimes = ['text/html', 'application/x-php', 'application/xhtml+xml', 'text/javascript', 'application/javascript', 'application/x-httpd-php'];
      if (in_array($mimeType, $disallowedMimes, true) || preg_match('/\.(php|phtml|phar|cgi|pl|sh)$/i', $finalName)) {
        @unlink($finalPath);
        jsonResponse(['error' => 'Disallowed file payload detected.'], 400);
      }
      $isVideo = strpos($mimeType, 'video/') === 0;

      $imgInfo = @getimagesize($finalPath);
      $w = $imgInfo ? $imgInfo[0] : 0;
      $h = $imgInfo ? $imgInfo[1] : 0;
      $sz = filesize($finalPath);

      $thumbName = 'thumb_' . $finalName . '.jpg';
      $thumbPath = $config['thumb_dir'] . DIRECTORY_SEPARATOR . $thumbName;

      if ($isVideo && $thumbData && strpos($thumbData, 'data:image') === 0) {
        $base64 = preg_replace('#^data:image/\w+;base64,#i', '', $thumbData);
        file_put_contents($thumbPath, base64_decode($base64));
      } else {
        createThumbnail($finalPath, $thumbPath, $config['thumb_width'], $config['thumb_quality']);
      }

      $phash = compute_phash($thumbPath);

      jsonResponse([
        'success'    => true,
        'completed'  => true,
        'file_name'  => $finalName,
        'original'   => $fileName,
        'file_size'  => $sz,
        'mime_type'  => $mimeType,
        'is_video'   => $isVideo,
        'phash'      => $phash,
        'width'      => $w,
        'height'     => $h,
        'thumb_name' => $thumbName
      ]);
    }

    jsonResponse(['success' => true, 'completed' => false, 'chunk' => $chunkIndex]);
  }

  if ($action === 'artwork_save') {
    verifyCsrfToken();
    $user = requireAuth($db);
    $artworkId = intval($_POST['id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $type = in_array($_POST['type'] ?? '', ['illust', 'video', 'manga']) ? $_POST['type'] : 'illust';
    $rating = in_array($_POST['rating'] ?? '', ['all', 'r18']) ? $_POST['rating'] : 'all';
    $isAi = !empty($_POST['is_ai']) ? 1 : 0;
    $isOriginal = isset($_POST['is_original']) ? (!empty($_POST['is_original']) ? 1 : 0) : 1;
    // Separate by comma only (never by space)
    $cleanCommaList = function($str) {
      $parts = preg_split('/[,，、]+/u', $str, -1, PREG_SPLIT_NO_EMPTY);
      return implode(', ', array_unique(array_filter(array_map('trim', $parts))));
    };

    $tools = $cleanCommaList($_POST['tools'] ?? '');
    $parodies = $cleanCommaList($_POST['parodies'] ?? '');
    $characters = $cleanCommaList($_POST['characters'] ?? '');

    // Allow multiple original source URLs (validated per line or comma)
    $rawSourceUrls = trim($_POST['source_url'] ?? '');
    $cleanSourceUrls = [];
    if ($rawSourceUrls !== '') {
      $urlCandidates = preg_split('/[\r\n,\s]+/u', $rawSourceUrls, -1, PREG_SPLIT_NO_EMPTY);
      foreach ($urlCandidates as $u) {
        $u = trim($u);
        if ($u === '') continue;
        if (!filter_var($u, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $u)) {
          jsonResponse(['error' => "Invalid source URL '{$u}'. Only valid HTTP and HTTPS URLs are permitted."], 400);
        }
        $cleanSourceUrls[] = $u;
      }
    }
    $sourceUrl = implode("\n", array_unique($cleanSourceUrls));
    $rawTags = trim($_POST['tags'] ?? '');
    $postMode = trim($_POST['post_mode'] ?? 'single');
    $imagesJson = $_POST['images'] ?? '[]';
    $images = json_decode($imagesJson, true);

    if (empty($title)) {
      jsonResponse(['error' => 'Title is required.'], 400);
    }
    if (!is_array($images) || empty($images)) {
      jsonResponse(['error' => 'At least one media file is required.'], 400);
    }

    // Rule 1: Maximum 500 images per post
    if (count($images) > 500) {
      jsonResponse(['error' => 'Maximum upload limit is 500 images per post.'], 400);
    }

    // Rule 2: Maximum 10 images/day for separate individual posts (bypassed for Admins)
    $isSeparateIndividual = ($postMode === 'batch' && count($images) > 1) || count($images) === 1;
    if (empty($user['is_admin']) && $artworkId === 0 && $isSeparateIndividual) {
      $since24h = time() - 86400;

      $stmtDaily = $db->prepare("
        SELECT COUNT(*) FROM artworks a
        WHERE a.user_id = ? AND a.created_at >= ?
        AND (SELECT COUNT(*) FROM artwork_images WHERE artwork_id = a.id) = 1
      ");
      $stmtDaily->execute([$user['id'], $since24h]);
      $dailyIndividualCount = (int)$stmtDaily->fetchColumn();

      if ($dailyIndividualCount >= 10) {
        jsonResponse([
          'error' => "Daily limit reached for separate individual posts (10/10 published in the last 24 hours). Please wait for the daily reset or publish as a single multi-page post."
        ], 429);
      }
    }

    $db->beginTransaction();
    try {
      $now = time();
      $tagsArray = array_values(array_unique(array_filter(array_map('trim', preg_split('/[,，、]+/u', $rawTags)))));
      $cleanTagsStr = implode(', ', $tagsArray);

      if ($artworkId === 0 && $postMode === 'batch' && count($images) > 1) {
        $createdIds = [];
        $totalImgs = count($images);

        foreach ($images as $idx => $img) {
          $fName = $img['file_name'] ?? ($img['file_key'] ?? '');
          if (!$fName) continue;

          $postTitle = $totalImgs > 1 ? "{$title} #" . ($idx + 1) : $title;
          $fPath = $config['upload_dir'] . DIRECTORY_SEPARATOR . $fName;
          $mimeType = $img['mime_type'] ?? (file_exists($fPath) ? (mime_content_type($fPath) ?: 'application/octet-stream') : '');
          $itemType = (strpos($mimeType, 'video/') === 0) ? 'video' : $type;
          $pHash = $img['phash'] ?? '';

          $stmt = $db->prepare("
            INSERT INTO artworks (
              user_id, title, description, type, rating, is_ai, is_original,
              tools, parodies, characters, tags, source_url, phash, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
          ");
          $stmt->execute([
            $user['id'], $postTitle, $description, $itemType, $rating, $isAi, $isOriginal,
            $tools, $parodies, $characters, $cleanTagsStr, $sourceUrl, $pHash, $now + $idx, $now + $idx
          ]);
          $newArtId = (int)$db->lastInsertId();
          $createdIds[] = $newArtId;

          $sz = file_exists($fPath) ? filesize($fPath) : ($img['size'] ?? 0);
          $dim = @getimagesize($fPath);
          $w = $dim ? $dim[0] : ($img['width'] ?? 0);
          $h = $dim ? $dim[1] : ($img['height'] ?? 0);

          $ext = strtolower(pathinfo($fName, PATHINFO_EXTENSION)) ?: 'jpg';
          $destRel = "uid_" . $user['id'] . "/data/imageid-0/imageassets_" . $newArtId . "/" . $newArtId . "_i0." . $ext;
          $destFullPath = $config['upload_dir'] . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $destRel);

          if ($fName !== $destRel && file_exists($fPath)) {
            if (!is_dir(dirname($destFullPath))) @mkdir(dirname($destFullPath), 0755, true);
            @rename($fPath, $destFullPath);

            $oldThumb = $config['thumb_dir'] . DIRECTORY_SEPARATOR . 'thumb_' . basename($fName) . '.jpg';
            if (!file_exists($oldThumb)) $oldThumb = $config['thumb_dir'] . DIRECTORY_SEPARATOR . 'thumb_' . basename($fName);
            $newThumb = $config['thumb_dir'] . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $destRel) . '.jpg';
            if (!is_dir(dirname($newThumb))) @mkdir(dirname($newThumb), 0755, true);
            if (file_exists($oldThumb)) @rename($oldThumb, $newThumb);

            $fName = $destRel;
            $fPath = $destFullPath;
          }

          $stmtImg = $db->prepare("
            INSERT INTO artwork_images (artwork_id, file_name, file_size, width, height, mime_type, phash, sort_order, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
          ");
          $stmtImg->execute([$newArtId, $fName, $sz, $w, $h, $mimeType, $pHash, 0, $now]);

          $tagStmt = $db->prepare("INSERT INTO tags (artwork_id, tag_name) VALUES (?, ?)");
          foreach ($tagsArray as $tName) {
            if ($tName !== '') $tagStmt->execute([$newArtId, mb_substr($tName, 0, 40)]);
          }

          logActivity($db, $user['id'], 'artwork_create', $newArtId, "Published artwork '{$postTitle}'");
        }

        $db->commit();
        jsonResponse(['success' => true, 'batch' => true, 'count' => count($createdIds), 'first_id' => $createdIds[0] ?? 0]);
      }

      $leadHash = $images[0]['phash'] ?? '';
      $firstPath = $config['upload_dir'] . DIRECTORY_SEPARATOR . ($images[0]['file_name'] ?? '');
      $leadMime = $images[0]['mime_type'] ?? (file_exists($firstPath) ? mime_content_type($firstPath) : '');
      if (strpos($leadMime, 'video/') === 0) $type = 'video';

      if ($artworkId > 0) {
        $stmt = $db->prepare("SELECT id, user_id FROM artworks WHERE id = ?");
        $stmt->execute([$artworkId]);
        $existing = $stmt->fetch();
        if (!$existing || ($existing['user_id'] != $user['id'] && !$user['is_admin'])) {
          jsonResponse(['error' => 'Unauthorized or artwork not found.'], 403);
        }

        $stmt = $db->prepare("
          UPDATE artworks SET 
            title = ?, description = ?, type = ?, rating = ?, is_ai = ?, is_original = ?,
            tools = ?, parodies = ?, characters = ?, tags = ?, source_url = ?, phash = ?, updated_at = ?
          WHERE id = ?
        ");
        $stmt->execute([
          $title, $description, $type, $rating, $isAi, $isOriginal,
          $tools, $parodies, $characters, $cleanTagsStr, $sourceUrl, $leadHash, $now, $artworkId
        ]);
        logActivity($db, $user['id'], 'artwork_update', $artworkId, "Updated artwork '{$title}'");
      } else {
        $stmt = $db->prepare("
          INSERT INTO artworks (
            user_id, title, description, type, rating, is_ai, is_original,
            tools, parodies, characters, tags, source_url, phash, created_at, updated_at
          ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
          $user['id'], $title, $description, $type, $rating, $isAi, $isOriginal,
          $tools, $parodies, $characters, $cleanTagsStr, $sourceUrl, $leadHash, $now, $now
        ]);
        $artworkId = (int)$db->lastInsertId();
        logActivity($db, $user['id'], 'artwork_create', $artworkId, "Published new {$type} '{$title}'");
      }

      $stmtOld = $db->prepare("SELECT id, file_name FROM artwork_images WHERE artwork_id = ?");
      $stmtOld->execute([$artworkId]);
      $existingImages = $stmtOld->fetchAll();
      $existingMap = [];
      foreach ($existingImages as $eImg) {
        $existingMap[$eImg['file_name']] = $eImg['id'];
      }

      $keptFiles = [];
      $stmtInsertImg = $db->prepare("
        INSERT INTO artwork_images (artwork_id, file_name, file_size, width, height, mime_type, phash, sort_order, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
      ");
      $stmtUpdateImg = $db->prepare("UPDATE artwork_images SET sort_order = ? WHERE id = ?");

      foreach ($images as $order => $img) {
        $fName = $img['file_name'] ?? ($img['file_key'] ?? '');
        if (!$fName) continue;

        $ext = strtolower(pathinfo($fName, PATHINFO_EXTENSION)) ?: 'jpg';
        $destRel = "uid_" . $user['id'] . "/data/imageid-" . $order . "/imageassets_" . $artworkId . "/" . $artworkId . "_i" . $order . "." . $ext;
        $oldFullPath = $config['upload_dir'] . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $fName);
        $destFullPath = $config['upload_dir'] . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $destRel);

        if ($fName !== $destRel && file_exists($oldFullPath)) {
          if (!is_dir(dirname($destFullPath))) @mkdir(dirname($destFullPath), 0755, true);
          @rename($oldFullPath, $destFullPath);

          $oldThumb = $config['thumb_dir'] . DIRECTORY_SEPARATOR . 'thumb_' . basename($fName) . '.jpg';
          if (!file_exists($oldThumb)) $oldThumb = $config['thumb_dir'] . DIRECTORY_SEPARATOR . 'thumb_' . basename($fName);
          $newThumb = $config['thumb_dir'] . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $destRel) . '.jpg';
          if (!is_dir(dirname($newThumb))) @mkdir(dirname($newThumb), 0755, true);
          if (file_exists($oldThumb)) @rename($oldThumb, $newThumb);

          $fName = $destRel;
        }

        $keptFiles[] = $fName;

        if (isset($existingMap[$fName])) {
          $stmtUpdateImg->execute([$order, $existingMap[$fName]]);
        } else {
          $fPath = $config['upload_dir'] . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $fName);
          $sz = file_exists($fPath) ? filesize($fPath) : ($img['size'] ?? 0);
          $dim = @getimagesize($fPath);
          $w = $dim ? $dim[0] : ($img['width'] ?? 0);
          $h = $dim ? $dim[1] : ($img['height'] ?? 0);
          $mimeType = $img['mime_type'] ?? (file_exists($fPath) ? mime_content_type($fPath) : 'application/octet-stream');
          $imgHash = $img['phash'] ?? '';
          $stmtInsertImg->execute([$artworkId, $fName, $sz, $w, $h, $mimeType, $imgHash, $order, $now]);
        }
      }

      foreach ($existingImages as $eImg) {
        if (!in_array($eImg['file_name'], $keptFiles)) {
          $stmtDel = $db->prepare("DELETE FROM artwork_images WHERE id = ?");
          $stmtDel->execute([$eImg['id']]);
          @unlink($config['upload_dir'] . DIRECTORY_SEPARATOR . $eImg['file_name']);
          @unlink($config['thumb_dir'] . DIRECTORY_SEPARATOR . 'thumb_' . $eImg['file_name'] . '.jpg');
        }
      }

      $db->prepare("DELETE FROM tags WHERE artwork_id = ?")->execute([$artworkId]);
      $tagStmt = $db->prepare("INSERT INTO tags (artwork_id, tag_name) VALUES (?, ?)");
      foreach ($tagsArray as $tName) {
        if ($tName !== '') $tagStmt->execute([$artworkId, mb_substr($tName, 0, 40)]);
      }

      $db->commit();
      jsonResponse(['success' => true, 'batch' => false, 'artwork_id' => $artworkId]);
    } catch (Exception $e) {
      $db->rollBack();
      jsonResponse(['error' => 'Failed to save artwork: ' . $e->getMessage()], 500);
    }
  }

  if ($action === 'artwork_delete') {
    verifyCsrfToken();
    $user = requireAuth($db);
    $artworkId = intval($_POST['id'] ?? 0);

    $stmt = $db->prepare("SELECT * FROM artworks WHERE id = ?");
    $stmt->execute([$artworkId]);
    $art = $stmt->fetch();
    if (!$art || ($art['user_id'] != $user['id'] && !$user['is_admin'])) {
      jsonResponse(['error' => 'Unauthorized or artwork not found.'], 403);
    }

    $stmtImgs = $db->prepare("SELECT file_name FROM artwork_images WHERE artwork_id = ?");
    $stmtImgs->execute([$artworkId]);
    $imgs = $stmtImgs->fetchAll();
    foreach ($imgs as $img) {
      @unlink($config['upload_dir'] . DIRECTORY_SEPARATOR . $img['file_name']);
      @unlink($config['thumb_dir'] . DIRECTORY_SEPARATOR . 'thumb_' . $img['file_name'] . '.jpg');
    }

    $db->prepare("DELETE FROM artworks WHERE id = ?")->execute([$artworkId]);
    logActivity($db, $user['id'], 'artwork_delete', $artworkId, "Deleted artwork '{$art['title']}'");
    jsonResponse(['success' => true]);
  }

  if ($action === 'artworks_list') {
    $feed = $_GET['feed'] ?? 'all';
    $type = $_GET['type'] ?? 'all';
    $rating = $_GET['rating'] ?? 'all';
    $sort = $_GET['sort'] ?? 'newest';
    $query = trim($_GET['q'] ?? '');
    $tag = trim($_GET['tag'] ?? '');
    $character = trim($_GET['character'] ?? '');
    $parody = trim($_GET['parody'] ?? '');
    $sourceUrl = trim($_GET['source_url'] ?? '');
    $userId = intval($_GET['user_id'] ?? 0);
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = 24;
    $offset = ($page - 1) * $limit;

    $curUserId = $currentUser ? (int)$currentUser['id'] : 0;

    $where = ["1=1"];
    $params = [];

    if ($feed === 'following') {
      if ($curUserId > 0) {
        $where[] = "a.user_id IN (SELECT following_id FROM follows WHERE follower_id = ?)";
        $params[] = $curUserId;
      } else {
        $where[] = "1=0";
      }
    } elseif ($feed === 'favorites') {
      $targetUid = $userId > 0 ? $userId : $curUserId;
      if ($targetUid > 0) {
        $where[] = "a.id IN (SELECT artwork_id FROM likes WHERE user_id = ?)";
        $params[] = $targetUid;
      } else {
        $where[] = "1=0";
      }
    }

    if ($userId > 0 && $feed !== 'favorites') {
      $where[] = "a.user_id = ?";
      $params[] = $userId;
    }

    if ($type !== 'all' && in_array($type, ['illust', 'video', 'manga'])) {
      $where[] = "a.type = ?";
      $params[] = $type;
    }

    if ($rating === 'r18') {
      $where[] = "a.rating = 'r18'";
    } elseif ($rating === 'safe') {
      $where[] = "a.rating = 'all'";
    }

    if ($tag !== '') {
      $where[] = "EXISTS (SELECT 1 FROM tags t WHERE t.artwork_id = a.id AND t.tag_name = ?)";
      $params[] = $tag;
    }

    if ($character !== '') {
      $where[] = "a.characters LIKE ?";
      $params[] = '%' . $character . '%';
    }

    if ($parody !== '') {
      $where[] = "a.parodies LIKE ?";
      $params[] = '%' . $parody . '%';
    }

    if ($sourceUrl !== '') {
      $where[] = "a.source_url LIKE ?";
      $params[] = '%' . $sourceUrl . '%';
    }

    if ($query !== '') {
      if (filter_var($query, FILTER_VALIDATE_URL) || strpos($query, 'http') === 0) {
        $where[] = "a.source_url LIKE ?";
        $params[] = '%' . $query . '%';
      } else {
        $where[] = "(a.title LIKE ? OR a.description LIKE ? OR a.tags LIKE ? OR a.characters LIKE ? OR a.parodies LIKE ? OR a.source_url LIKE ? OR u.artist_name LIKE ?)";
        $term = "%{$query}%";
        $params = array_merge($params, [$term, $term, $term, $term, $term, $term, $term]);
      }
    }

    $orderSql = "a.created_at DESC";
    if ($sort === 'popular') $orderSql = "a.like_count DESC, a.view_count DESC, a.created_at DESC";
    elseif ($sort === 'views') $orderSql = "a.view_count DESC, a.created_at DESC";
    elseif ($sort === 'oldest') $orderSql = "a.created_at ASC";

    if ($feed === 'rankings') {
      $rankingPeriod = $_GET['period'] ?? 'daily';
      $now = time();
      $timeLimit = $now - (86400 * 30);
      if ($rankingPeriod === 'daily') $timeLimit = $now - 86400;
      elseif ($rankingPeriod === 'weekly') $timeLimit = $now - (86400 * 7);
      elseif ($rankingPeriod === 'monthly') $timeLimit = $now - (86400 * 30);

      $where[] = "a.created_at >= ?";
      $params[] = $timeLimit;
      $orderSql = "(a.like_count * 3 + a.view_count * 0.1) DESC";
    }

    $whereSql = implode(' AND ', $where);

    $countStmt = $db->prepare("
      SELECT COUNT(DISTINCT a.id) as total 
      FROM artworks a 
      LEFT JOIN users u ON a.user_id = u.id 
      WHERE {$whereSql}
    ");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $likedSubquery = $curUserId > 0 ? "(SELECT COUNT(*) FROM likes WHERE artwork_id = a.id AND user_id = {$curUserId})" : "0";

    $stmt = $db->prepare("
      SELECT 
        a.*, 
        u.username, u.artist_name, u.email_hash, u.avatar,
        (SELECT file_name FROM artwork_images WHERE artwork_id = a.id ORDER BY sort_order ASC, id ASC LIMIT 1) as cover_file,
        (SELECT mime_type FROM artwork_images WHERE artwork_id = a.id ORDER BY sort_order ASC, id ASC LIMIT 1) as cover_mime,
        (SELECT width FROM artwork_images WHERE artwork_id = a.id ORDER BY sort_order ASC, id ASC LIMIT 1) as cover_width,
        (SELECT height FROM artwork_images WHERE artwork_id = a.id ORDER BY sort_order ASC, id ASC LIMIT 1) as cover_height,
        (SELECT COUNT(*) FROM artwork_images WHERE artwork_id = a.id) as page_count,
        {$likedSubquery} as user_liked
      FROM artworks a
      LEFT JOIN users u ON a.user_id = u.id
      WHERE {$whereSql}
      ORDER BY {$orderSql}
      LIMIT {$limit} OFFSET {$offset}
    ");
    $stmt->execute($params);
    $artworks = $stmt->fetchAll();

    foreach ($artworks as &$artItem) {
      $artItem['user_liked'] = !empty($artItem['user_liked']);
      $artItem['page_count'] = (int)($artItem['page_count'] ?? 1);
      $artItem['view_count'] = (int)($artItem['view_count'] ?? 0);
      $artItem['like_count'] = (int)($artItem['like_count'] ?? 0);
    }
    unset($artItem);

    jsonResponse([
      'artworks' => $artworks,
      'total'    => $total,
      'page'     => $page,
      'pages'    => ceil($total / $limit)
    ]);
  }

  if ($action === 'similar_search') {
    $sourceId = intval($_GET['source_id'] ?? ($_POST['source_id'] ?? 0));
    $imageId = intval($_GET['image_id'] ?? ($_POST['image_id'] ?? 0));
    $sortOrder = isset($_GET['sort_order']) ? intval($_GET['sort_order']) : (isset($_GET['image_index']) ? intval($_GET['image_index']) : -1);
    $targetHash = '';
    $sourceArt = null;
    $targetImage = null;

    if ($sourceId > 0) {
      $st = $db->prepare("SELECT a.* FROM artworks a WHERE a.id = ?");
      $st->execute([$sourceId]);
      $sourceArt = $st->fetch();

      if ($sourceArt) {
        $imgsSt = $db->prepare("
          SELECT id, file_name, sort_order, mime_type, phash
          FROM artwork_images
          WHERE artwork_id = ?
          ORDER BY sort_order ASC, id ASC
        ");
        $imgsSt->execute([$sourceId]);
        $rawImgs = $imgsSt->fetchAll();
        $imagePages = array_values(array_filter($rawImgs, function($im) {
          return empty($im['mime_type']) || strpos($im['mime_type'], 'video/') !== 0;
        }));

        if (empty($imagePages)) {
          jsonResponse(['error' => 'Visual similarity search is only available for images.'], 400);
        }

        if ($imageId > 0) {
          foreach ($imagePages as $im) {
            if ((int)$im['id'] === $imageId) { $targetImage = $im; break; }
          }
        } elseif ($sortOrder >= 0 && isset($imagePages[$sortOrder])) {
          $targetImage = $imagePages[$sortOrder];
        }

        if (!$targetImage) {
          $targetImage = $imagePages[0];
        }

        $sourceArt['cover_file'] = $targetImage['file_name'];
        $sourceArt['selected_sort_order'] = (int)$targetImage['sort_order'];
        $sourceArt['selected_image_id'] = (int)$targetImage['id'];
        $sourceArt['all_images'] = $imagePages;

        $tPath = getArtworkThumbnailPath($targetImage['file_name'], $config);
        if ($tPath) {
          $targetHash = compute_phash($tPath);
          if ($targetHash) {
            $db->prepare("UPDATE artwork_images SET phash = ? WHERE id = ?")->execute([$targetHash, $targetImage['id']]);
            if ((int)$targetImage['sort_order'] === 0) {
              $db->prepare("UPDATE artworks SET phash = ? WHERE id = ?")->execute([$targetHash, $sourceId]);
            }
          }
        }
      }
    } elseif (isset($_FILES['similar_file']) && $_FILES['similar_file']['error'] === 0) {
      $tmpUpload = $_FILES['similar_file']['tmp_name'];
      $tempThumb = $config['chunk_dir'] . DIRECTORY_SEPARATOR . 'sim_tmp_' . bin2hex(random_bytes(6)) . '.jpg';
      if (createThumbnail($tmpUpload, $tempThumb, $config['thumb_width'], $config['thumb_quality'])) {
        $targetHash = compute_phash($tempThumb);
        @unlink($tempThumb);
      } else {
        $targetHash = compute_phash($tmpUpload);
      }
    }

    if (empty($targetHash)) {
      jsonResponse(['error' => 'Could not compute visual perceptual hash for target thumbnail.'], 400);
    }

    $allImages = $db->query("
      SELECT ai.id as image_id, ai.artwork_id, ai.file_name, ai.sort_order, ai.phash,
             a.id as art_id, a.title, a.type, a.rating, u.artist_name, u.username
      FROM artwork_images ai
      JOIN artworks a ON ai.artwork_id = a.id
      JOIN users u ON a.user_id = u.id
      WHERE a.type != 'video' AND (ai.mime_type IS NULL OR ai.mime_type NOT LIKE 'video/%')
    ")->fetchAll();

    $matchedPosts = [];
    foreach ($allImages as $item) {
      $artId = (int)($item['artwork_id'] ?? $item['art_id']);
      if ($sourceId > 0 && $artId === $sourceId) continue;

      $imgHash = $item['phash'] ?? '';
      if (empty($imgHash) || strpos($imgHash, 'd:') !== 0 || strlen($imgHash) !== 66) {
        $tPath = getArtworkThumbnailPath($item['file_name'], $config);
        if ($tPath) {
          $imgHash = compute_phash($tPath);
          if ($imgHash) {
            $db->prepare("UPDATE artwork_images SET phash = ? WHERE id = ?")->execute([$imgHash, $item['image_id']]);
            if ((int)$item['sort_order'] === 0) {
              $db->prepare("UPDATE artworks SET phash = ? WHERE id = ?")->execute([$imgHash, $artId]);
            }
          }
        }
      }

      if (empty($imgHash) || strpos($imgHash, 'd:') !== 0) continue;

      $dist = hamming_distance($targetHash, $imgHash);
      if ($dist <= 14) {
        $similarity = round((1 - ($dist / 64)) * 100, 1);
        if (!isset($matchedPosts[$artId]) || $dist < $matchedPosts[$artId]['distance']) {
          $matchedPosts[$artId] = [
            'id'          => $artId,
            'title'       => $item['title'],
            'type'        => $item['type'],
            'rating'      => $item['rating'],
            'cover_file'  => $item['file_name'],
            'artist_name' => $item['artist_name'],
            'distance'    => $dist,
            'similarity'  => $similarity
          ];
        }
      }
    }

    $results = array_values($matchedPosts);
    usort($results, fn($a, $b) => $a['distance'] <=> $b['distance']);

    jsonResponse([
      'target_hash' => $targetHash,
      'source_art'  => $sourceArt,
      'matches'     => array_slice($results, 0, 25)
    ]);
  }

  if ($action === 'artwork_get') {
    $id = intval($_GET['id'] ?? 0);
    $curUserId = $currentUser ? (int)$currentUser['id'] : 0;

    $likedSub = $curUserId > 0 ? "(SELECT COUNT(*) FROM likes WHERE artwork_id = a.id AND user_id = {$curUserId})" : "0";
    $followSub = $curUserId > 0 ? "(SELECT COUNT(*) FROM follows WHERE follower_id = {$curUserId} AND following_id = u.id)" : "0";

    $stmt = $db->prepare("
      SELECT 
        a.*, 
        u.username, u.artist_name, u.email_hash, u.avatar, u.bio, u.twitter, u.website,
        {$followSub} as is_following,
        {$likedSub} as user_liked
      FROM artworks a
      LEFT JOIN users u ON a.user_id = u.id
      WHERE a.id = ?
    ");
    $stmt->execute([$id]);
    $art = $stmt->fetch();
    if (!$art) jsonResponse(['error' => 'Artwork not found.'], 404);

    $db->prepare("UPDATE artworks SET view_count = view_count + 1 WHERE id = ?")->execute([$id]);
    $art['view_count']++;

    $stmtImgs = $db->prepare("SELECT * FROM artwork_images WHERE artwork_id = ? ORDER BY sort_order ASC, id ASC");
    $stmtImgs->execute([$id]);
    $art['images'] = $stmtImgs->fetchAll();

    $tagRows = $db->prepare("SELECT tag_name FROM tags WHERE artwork_id = ?");
    $tagRows->execute([$id]);
    $art['tag_list'] = $tagRows->fetchAll(PDO::FETCH_COLUMN, 0);

    $stmtComments = $db->prepare("
      SELECT c.*, u.username, u.artist_name, u.email_hash, u.avatar
      FROM comments c
      LEFT JOIN users u ON c.user_id = u.id
      WHERE c.artwork_id = ?
      ORDER BY c.created_at ASC
    ");
    $stmtComments->execute([$id]);
    $rawComments = $stmtComments->fetchAll();

    $threaded = [];
    $replyMap = [];
    foreach ($rawComments as $c) {
      $c['replies'] = [];
      if ($c['parent_id'] == 0) {
        $threaded[$c['id']] = $c;
      } else {
        $replyMap[$c['parent_id']][] = $c;
      }
    }
    foreach ($replyMap as $pId => $reps) {
      if (isset($threaded[$pId])) {
        $threaded[$pId]['replies'] = $reps;
      } else {
        foreach ($reps as $r) $threaded[$r['id']] = $r;
      }
    }
    $art['comments'] = array_values($threaded);
    $art['raw_comments_count'] = count($rawComments);
    $art['user_liked'] = !empty($art['user_liked']);
    $art['is_following'] = !empty($art['is_following']);
    $art['page_count'] = (int)count($art['images']);
    $art['view_count'] = (int)($art['view_count'] ?? 0);
    $art['like_count'] = (int)($art['like_count'] ?? 0);

    $stmtRelated = $db->prepare("
      SELECT a.id, a.title, a.type, a.rating, a.like_count,
        (SELECT file_name FROM artwork_images WHERE artwork_id = a.id ORDER BY sort_order ASC LIMIT 1) as cover_file,
        (SELECT mime_type FROM artwork_images WHERE artwork_id = a.id ORDER BY sort_order ASC LIMIT 1) as cover_mime,
        (SELECT COUNT(*) FROM artwork_images WHERE artwork_id = a.id) as page_count
      FROM artworks a
      WHERE a.user_id = ? AND a.id != ?
      ORDER BY a.created_at DESC LIMIT 6
    ");
    $stmtRelated->execute([$art['user_id'], $id]);
    $art['artist_other'] = $stmtRelated->fetchAll();

    $prevStmt = $db->prepare("SELECT id FROM artworks WHERE id < ? ORDER BY id DESC LIMIT 1");
    $prevStmt->execute([$id]);
    $art['prev_id'] = $prevStmt->fetchColumn() ?: null;

    $nextStmt = $db->prepare("SELECT id FROM artworks WHERE id > ? ORDER BY id ASC LIMIT 1");
    $nextStmt->execute([$id]);
    $art['next_id'] = $nextStmt->fetchColumn() ?: null;

    if ($art['type'] === 'manga') {
      $parodyTrim = trim($art['parodies'] ?? '');
      $seriesWhere = "a.user_id = ? AND a.type = 'manga'";
      $seriesParams = [(int)$art['user_id']];
      if ($parodyTrim !== '') {
        $seriesWhere .= " AND a.parodies = ?";
        $seriesParams[] = $parodyTrim;
      }
      $seriesStmt = $db->prepare("
        SELECT a.id, a.title, a.created_at,
          (SELECT file_name FROM artwork_images WHERE artwork_id = a.id ORDER BY sort_order ASC, id ASC LIMIT 1) as cover_file,
          (SELECT COUNT(*) FROM artwork_images WHERE artwork_id = a.id) as page_count
        FROM artworks a
        WHERE {$seriesWhere}
        ORDER BY a.created_at ASC, a.id ASC
      ");
      $seriesStmt->execute($seriesParams);
      $seriesList = $seriesStmt->fetchAll();

      $currIdx = 0;
      foreach ($seriesList as $k => $item) {
        if ((int)$item['id'] === (int)$art['id']) {
          $currIdx = $k;
          break;
        }
      }

      $art['manga_series'] = $seriesList;
      $art['series_title'] = $parodyTrim !== '' ? $parodyTrim : 'Manga Series';
      $art['series_index'] = $currIdx + 1;
      $art['series_total'] = count($seriesList);
      $art['series_prev'] = ($currIdx > 0) ? $seriesList[$currIdx - 1] : null;
      $art['series_next'] = ($currIdx < count($seriesList) - 1) ? $seriesList[$currIdx + 1] : null;
    }

    jsonResponse($art);
  }

  if ($action === 'artwork_export') {
    $user = requireAuth($db);
    $artworkId = intval($_GET['id'] ?? 0);
    $stmt = $db->prepare("SELECT * FROM artworks WHERE id = ?");
    $stmt->execute([$artworkId]);
    $art = $stmt->fetch();
    if (!$art) jsonResponse(['error' => 'Artwork not found.'], 404);

    // Security check: Only post owner or administrators can export full raw packages
    if ((int)$art['user_id'] !== (int)$user['id'] && empty($user['is_admin'])) {
      jsonResponse(['error' => 'Access denied: Only the author or an admin can export this package.'], 403);
    }

    if (!class_exists('ZipArchive')) {
      jsonResponse(['error' => 'Server ZipArchive extension is required.'], 500);
    }

    // Prevent server execution timeouts during large package compilation
    @set_time_limit(0);
    @ini_set('max_execution_time', '0');
    if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');

    $stmtImgs = $db->prepare("SELECT * FROM artwork_images WHERE artwork_id = ? ORDER BY sort_order ASC, id ASC");
    $stmtImgs->execute([$artworkId]);
    $rawImages = $stmtImgs->fetchAll();

    $tempZip = $config['chunk_dir'] . DIRECTORY_SEPARATOR . 'export_' . $artworkId . '_' . bin2hex(random_bytes(6)) . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($tempZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
      jsonResponse(['error' => 'Failed to initialize export package.'], 500);
    }

    $itemsList = [];
    foreach ($rawImages as $idx => $img) {
      $fPath = $config['upload_dir'] . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $img['file_name']);
      if (file_exists($fPath) && is_file($fPath)) {
        $ext = strtolower(pathinfo($img['file_name'], PATHINFO_EXTENSION)) ?: 'jpg';
        $entryName = $idx . '.' . $ext;
        $zip->addFile($fPath, 'items/' . $entryName);
        $itemsList[] = [
          'sort_order' => (int)$img['sort_order'],
          'file'       => $entryName,
          'mime_type'  => $img['mime_type'],
          'width'      => (int)$img['width'],
          'height'     => (int)$img['height'],
          'phash'      => $img['phash']
        ];
      }
    }

    $infoPayload = [
      'hdpost_special_key' => 'HDPOST_EXPORT_KEY_POST_v1_VALIDATED',
      'version'            => '1.0',
      'exported_at'        => time(),
      'artwork'            => [
        'title'       => $art['title'],
        'description' => $art['description'],
        'type'        => $art['type'],
        'rating'      => $art['rating'],
        'is_ai'       => (int)$art['is_ai'],
        'is_original' => (int)$art['is_original'],
        'tools'       => $art['tools'],
        'parodies'    => $art['parodies'],
        'characters'  => $art['characters'],
        'tags'        => $art['tags'],
        'source_url'  => $art['source_url']
      ],
      'items'              => $itemsList
    ];

    $zip->addFromString('info.json', json_encode($infoPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $zip->close();

    if (!file_exists($tempZip) || filesize($tempZip) === 0) {
      @unlink($tempZip);
      jsonResponse(['error' => 'Failed to build export package.'], 500);
    }

    $safeTitle = preg_replace('/[^\w\-]+/u', '_', trim($art['title'])) ?: 'artwork';
    $downloadName = "{$safeTitle}_{$art['id']}_export.zip";
    $zipSize = filesize($tempZip);

    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    @ini_set('zlib.output_compression', 'Off');
    while (ob_get_level() > 0) @ob_end_clean();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
    header('Content-Length: ' . $zipSize);
    header('Cache-Control: no-cache, no-store, must-revalidate');

    $fp = @fopen($tempZip, 'rb');
    if ($fp) {
      while (!feof($fp)) {
        echo fread($fp, 256 * 1024);
        @flush();
      }
      fclose($fp);
    }
    @unlink($tempZip);
    exit;
  }

  if ($action === 'artwork_import') {
    verifyCsrfToken();
    $user = requireAuth($db);

    if (empty($_FILES['import_file']['tmp_name']) || !file_exists($_FILES['import_file']['tmp_name'])) {
      jsonResponse(['error' => 'Please choose an export file to import.'], 400);
    }

    $tmpPath = $_FILES['import_file']['tmp_name'];
    $clientName = $_FILES['import_file']['name'] ?? '';
    $ext = strtolower(pathinfo($clientName, PATHINFO_EXTENSION));

    $infoData = null;
    $zipArchive = null;

    if ($ext === 'zip' && class_exists('ZipArchive')) {
      $zip = new ZipArchive();
      if ($zip->open($tmpPath) === true) {
        $infoRaw = $zip->getFromName('info.json');
        if ($infoRaw !== false) {
          $infoData = json_decode($infoRaw, true);
          $zipArchive = $zip;
        } else {
          $zip->close();
        }
      }
    }

    if (!$infoData) {
      $rawContent = @file_get_contents($tmpPath);
      $infoData = json_decode($rawContent, true);
    }

    if (!is_array($infoData) || empty($infoData['hdpost_special_key']) || $infoData['hdpost_special_key'] !== 'HDPOST_EXPORT_KEY_POST_v1_VALIDATED') {
      if ($zipArchive) $zipArchive->close();
      jsonResponse(['error' => 'Import rejected: missing or invalid special key in info.json.'], 400);
    }

    $art = $infoData['artwork'] ?? [];
    $title = trim($art['title'] ?? 'Imported Artwork');
    $description = trim($art['description'] ?? '');
    $type = in_array($art['type'] ?? '', ['illust', 'video', 'manga']) ? $art['type'] : 'illust';
    $rating = in_array($art['rating'] ?? '', ['all', 'r18']) ? $art['rating'] : 'all';
    $isAi = !empty($art['is_ai']) ? 1 : 0;
    $isOriginal = isset($art['is_original']) ? (!empty($art['is_original']) ? 1 : 0) : 1;
    $tools = trim($art['tools'] ?? '');
    $parodies = trim($art['parodies'] ?? '');
    $characters = trim($art['characters'] ?? '');
    $sourceUrl = trim($art['source_url'] ?? '');
    $rawTags = trim($art['tags'] ?? '');
    $items = $infoData['items'] ?? ($infoData['images'] ?? []);

    if (empty($items) || !is_array($items)) {
      if ($zipArchive) $zipArchive->close();
      jsonResponse(['error' => 'No media items found in the imported package.'], 400);
    }

    $db->beginTransaction();
    try {
      $now = time();
      $stmt = $db->prepare("
        INSERT INTO artworks (
          user_id, title, description, type, rating, is_ai, is_original,
          tools, parodies, characters, tags, source_url, phash, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '', ?, ?)
      ");
      $stmt->execute([
        $user['id'], $title, $description, $type, $rating, $isAi, $isOriginal,
        $tools, $parodies, $characters, $rawTags, $sourceUrl, $now, $now
      ]);
      $newArtId = (int)$db->lastInsertId();

      $stmtImg = $db->prepare("
        INSERT INTO artwork_images (artwork_id, file_name, file_size, width, height, mime_type, phash, sort_order, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
      ");

      $leadHash = '';
      foreach ($items as $idx => $item) {
        $binData = '';
        $itemExt = 'jpg';
        $mime = $item['mime_type'] ?? 'image/jpeg';

        if ($zipArchive && !empty($item['file'])) {
          $binData = $zipArchive->getFromName('items/' . $item['file']);
          $itemExt = strtolower(pathinfo($item['file'], PATHINFO_EXTENSION)) ?: 'jpg';
        } elseif (!empty($item['data_base64'])) {
          $binData = base64_decode($item['data_base64']);
          if (strpos($mime, 'png') !== false) $itemExt = 'png';
          elseif (strpos($mime, 'gif') !== false) $itemExt = 'gif';
          elseif (strpos($mime, 'webp') !== false) $itemExt = 'webp';
          elseif (strpos($mime, 'mp4') !== false) $itemExt = 'mp4';
          elseif (strpos($mime, 'webm') !== false) $itemExt = 'webm';
        }

        if ($binData === false || strlen($binData) === 0) continue;

        $destRel = "uid_" . $user['id'] . "/data/imageid-" . $idx . "/imageassets_" . $newArtId . "/" . $newArtId . "_i" . $idx . "." . $itemExt;
        $destFull = $config['upload_dir'] . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $destRel);

        if (!is_dir(dirname($destFull))) @mkdir(dirname($destFull), 0755, true);
        file_put_contents($destFull, $binData);

        $thumbRel = $destRel . '.jpg';
        $thumbFull = $config['thumb_dir'] . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $thumbRel);
        if (!is_dir(dirname($thumbFull))) @mkdir(dirname($thumbFull), 0755, true);
        createThumbnail($destFull, $thumbFull, $config['thumb_width'], $config['thumb_quality']);

        $pHash = compute_phash($thumbFull);
        if ($idx === 0) $leadHash = $pHash;

        $sz = strlen($binData);
        $dim = @getimagesize($destFull);
        $w = $dim ? $dim[0] : (int)($item['width'] ?? 0);
        $h = $dim ? $dim[1] : (int)($item['height'] ?? 0);

        $stmtImg->execute([$newArtId, $destRel, $sz, $w, $h, $mime, $pHash, $idx, $now]);
      }

      if ($leadHash) {
        $db->prepare("UPDATE artworks SET phash = ? WHERE id = ?")->execute([$leadHash, $newArtId]);
      }

      $tagsArray = array_values(array_unique(array_filter(array_map('trim', preg_split('/[,#、\s]+/u', $rawTags)))));
      $tagStmt = $db->prepare("INSERT INTO tags (artwork_id, tag_name) VALUES (?, ?)");
      foreach ($tagsArray as $tName) {
        if ($tName !== '') $tagStmt->execute([$newArtId, mb_substr($tName, 0, 40)]);
      }

      if ($zipArchive) $zipArchive->close();
      $db->commit();
      logActivity($db, $user['id'], 'artwork_import', $newArtId, "Imported artwork '{$title}'");
      jsonResponse(['success' => true, 'artwork_id' => $newArtId]);
    } catch (Exception $e) {
      if ($zipArchive) $zipArchive->close();
      $db->rollBack();
      jsonResponse(['error' => 'Failed to import post: ' . $e->getMessage()], 500);
    }
  }

  if ($action === 'artwork_zip') {
    $artworkId = intval($_GET['id'] ?? 0);
    if ($artworkId <= 0) {
      jsonResponse(['error' => 'Invalid artwork ID.'], 400);
    }

    if (!class_exists('ZipArchive')) {
      jsonResponse(['error' => 'Server ZipArchive extension is not available.'], 500);
    }

    $stmt = $db->prepare("SELECT id, title FROM artworks WHERE id = ?");
    $stmt->execute([$artworkId]);
    $art = $stmt->fetch();
    if (!$art) {
      jsonResponse(['error' => 'Artwork not found.'], 404);
    }

    $stmtImgs = $db->prepare("SELECT file_name FROM artwork_images WHERE artwork_id = ? ORDER BY sort_order ASC, id ASC");
    $stmtImgs->execute([$artworkId]);
    $images = $stmtImgs->fetchAll();
    if (empty($images)) {
      jsonResponse(['error' => 'No images found for this artwork.'], 404);
    }

    $tempZip = $config['chunk_dir'] . DIRECTORY_SEPARATOR . 'zip_' . $artworkId . '_' . bin2hex(random_bytes(6)) . '.tmp';
    $zip = new ZipArchive();
    if ($zip->open($tempZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
      jsonResponse(['error' => 'Failed to initialize ZIP archive.'], 500);
    }

    $padLen = max(2, strlen((string)count($images)));
    foreach ($images as $idx => $img) {
      $fName = $img['file_name'];
      $fPath = $config['upload_dir'] . DIRECTORY_SEPARATOR . $fName;
      if (file_exists($fPath) && is_file($fPath)) {
        $ext = pathinfo($fName, PATHINFO_EXTENSION);
        $entryName = str_pad((string)($idx + 1), $padLen, '0', STR_PAD_LEFT) . '.' . $ext;
        $zip->addFile($fPath, $entryName);
      }
    }
    $zip->close();

    if (!file_exists($tempZip) || filesize($tempZip) === 0) {
      @unlink($tempZip);
      jsonResponse(['error' => 'Failed to build ZIP file.'], 500);
    }

    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    @ini_set('zlib.output_compression', 'Off');
    while (ob_get_level() > 0) @ob_end_clean();

    $zipSize = filesize($tempZip);
    $safeTitle = preg_replace('/[^\w\-\.]+/u', '_', trim($art['title'])) ?: 'artwork';
    $downloadName = "{$safeTitle}_{$art['id']}.zip";

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
    header('Content-Length: ' . $zipSize);
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('X-Content-Type-Options: nosniff');

    $fp = @fopen($tempZip, 'rb');
    if ($fp) {
      while (!feof($fp)) {
        echo fread($fp, 256 * 1024);
        @flush();
      }
      fclose($fp);
    }
    @unlink($tempZip);
    exit;
  }

  if ($action === 'artwork_like') {
    verifyCsrfToken();
    $artworkId = intval($_POST['artwork_id'] ?? 0);
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $userId = $currentUser ? (int)$currentUser['id'] : 0;

    if ($userId > 0) {
      $stmt = $db->prepare("SELECT id FROM likes WHERE artwork_id = ? AND user_id = ?");
      $stmt->execute([$artworkId, $userId]);
    } else {
      $stmt = $db->prepare("SELECT id FROM likes WHERE artwork_id = ? AND user_id = 0 AND ip = ?");
      $stmt->execute([$artworkId, $ip]);
    }
    $liked = $stmt->fetch();

    if ($liked) {
      $db->prepare("DELETE FROM likes WHERE id = ?")->execute([$liked['id']]);
      $db->prepare("UPDATE artworks SET like_count = MAX(0, like_count - 1) WHERE id = ?")->execute([$artworkId]);
      $isLiked = false;
    } else {
      $stmtAdd = $db->prepare("INSERT INTO likes (artwork_id, user_id, ip, created_at) VALUES (?, ?, ?, ?)");
      $stmtAdd->execute([$artworkId, $userId, $ip, time()]);
      $db->prepare("UPDATE artworks SET like_count = like_count + 1 WHERE id = ?")->execute([$artworkId]);
      $isLiked = true;
      if ($userId > 0) logActivity($db, $userId, 'like', $artworkId, 'Liked artwork');
    }

    $count = (int)$db->query("SELECT like_count FROM artworks WHERE id = {$artworkId}")->fetchColumn();
    jsonResponse(['success' => true, 'liked' => $isLiked, 'like_count' => $count]);
  }

  if ($action === 'user_follow') {
    verifyCsrfToken();
    $user = requireAuth($db);
    $targetId = intval($_POST['user_id'] ?? 0);

    if ($user['id'] == $targetId) {
      jsonResponse(['error' => 'You cannot follow yourself.'], 400);
    }

    $stmt = $db->prepare("SELECT id FROM follows WHERE follower_id = ? AND following_id = ?");
    $stmt->execute([$user['id'], $targetId]);
    $f = $stmt->fetch();

    if ($f) {
      $db->prepare("DELETE FROM follows WHERE id = ?")->execute([$f['id']]);
      $isFollowing = false;
    } else {
      $db->prepare("INSERT INTO follows (follower_id, following_id, created_at) VALUES (?, ?, ?)")->execute([$user['id'], $targetId, time()]);
      $isFollowing = true;
      logActivity($db, $user['id'], 'follow', $targetId, 'Followed artist');
    }
    jsonResponse(['success' => true, 'following' => $isFollowing]);
  }

  if ($action === 'comment_add') {
    verifyCsrfToken();
    $user = requireAuth($db);
    if (!checkRateLimit($db, 'comment_add', 15, 60)) {
      jsonResponse(['error' => 'Posting comments too rapidly. Please slow down.'], 429);
    }
    $artworkId = intval($_POST['artwork_id'] ?? 0);
    $parentId = intval($_POST['parent_id'] ?? 0);
    $comment = mb_substr(trim($_POST['comment'] ?? ''), 0, 2000);

    if (empty($comment)) {
      jsonResponse(['error' => 'Comment cannot be empty.'], 400);
    }

    $now = time();
    $stmt = $db->prepare("INSERT INTO comments (artwork_id, user_id, parent_id, comment, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$artworkId, $user['id'], $parentId, $comment, $now, $now]);
    $commentId = (int)$db->lastInsertId();

    logActivity($db, $user['id'], 'comment', $artworkId, 'Commented on artwork');

    $stmtNew = $db->prepare("
      SELECT c.*, u.username, u.artist_name, u.email_hash, u.avatar
      FROM comments c
      LEFT JOIN users u ON c.user_id = u.id
      WHERE c.id = ?
    ");
    $stmtNew->execute([$commentId]);
    $created = $stmtNew->fetch();
    $created['replies'] = [];
    jsonResponse(['success' => true, 'comment' => $created]);
  }

  if ($action === 'comment_edit') {
    verifyCsrfToken();
    $user = requireAuth($db);
    $commentId = intval($_POST['comment_id'] ?? 0);
    $text = trim($_POST['comment'] ?? '');

    if (empty($text)) jsonResponse(['error' => 'Comment text cannot be empty'], 400);

    $stmt = $db->prepare("SELECT * FROM comments WHERE id = ?");
    $stmt->execute([$commentId]);
    $c = $stmt->fetch();
    if (!$c || ($c['user_id'] != $user['id'] && !$user['is_admin'])) {
      jsonResponse(['error' => 'Unauthorized or comment not found'], 403);
    }

    $db->prepare("UPDATE comments SET comment = ?, updated_at = ? WHERE id = ?")->execute([$text, time(), $commentId]);
    jsonResponse(['success' => true, 'comment' => $text]);
  }

  if ($action === 'comment_delete') {
    verifyCsrfToken();
    $user = requireAuth($db);
    $commentId = intval($_POST['comment_id'] ?? 0);

    $stmt = $db->prepare("SELECT * FROM comments WHERE id = ?");
    $stmt->execute([$commentId]);
    $c = $stmt->fetch();
    if (!$c || ($c['user_id'] != $user['id'] && !$user['is_admin'])) {
      jsonResponse(['error' => 'Unauthorized or comment not found'], 403);
    }

    $db->prepare("DELETE FROM comments WHERE id = ? OR parent_id = ?")->execute([$commentId, $commentId]);
    jsonResponse(['success' => true]);
  }

  if ($action === 'user_profile') {
    $targetId = intval($_GET['user'] ?? 0);
    $curUserId = $currentUser ? (int)$currentUser['id'] : 0;
    $followSub = $curUserId > 0 ? "(SELECT COUNT(*) FROM follows WHERE follower_id = {$curUserId} AND following_id = u.id)" : "0";

    $stmt = $db->prepare("
      SELECT u.id, u.artist_name, u.email_hash, u.bio, u.avatar, u.banner, u.twitter, u.website, u.created_at, u.is_admin,
        (SELECT COUNT(*) FROM follows WHERE following_id = u.id) as follower_count,
        (SELECT COUNT(*) FROM follows WHERE follower_id = u.id) as following_count,
        (SELECT COUNT(*) FROM artworks WHERE user_id = u.id) as artwork_count,
        {$followSub} as is_following
      FROM users u
      WHERE u.id = ?
    ");
    $stmt->execute([$targetId]);
    $prof = $stmt->fetch();
    if (!$prof) jsonResponse(['error' => 'Artist not found.'], 404);
    $prof['is_following'] = !empty($prof['is_following']);
    $prof['artwork_count'] = (int)($prof['artwork_count'] ?? 0);
    $prof['follower_count'] = (int)($prof['follower_count'] ?? 0);
    $prof['following_count'] = (int)($prof['following_count'] ?? 0);
    jsonResponse($prof);
  }

  if ($action === 'popular_tags') {
    $stmt = $db->query("
      SELECT tag_name, COUNT(*) as tag_count
      FROM tags
      GROUP BY tag_name
      ORDER BY tag_count DESC
      LIMIT 25
    ");
    jsonResponse(['tags' => $stmt->fetchAll()]);
  }

  if ($action === 'tags_all') {
    $stmt = $db->query("
      SELECT t.tag_name, COUNT(DISTINCT t.artwork_id) as tag_count,
        (SELECT ai.file_name FROM artwork_images ai 
         JOIN artworks a ON a.id = ai.artwork_id 
         JOIN tags t2 ON t2.artwork_id = a.id 
         WHERE t2.tag_name = t.tag_name 
         ORDER BY a.created_at DESC, ai.sort_order ASC LIMIT 1) as cover_file
      FROM tags t
      GROUP BY t.tag_name
      ORDER BY tag_count DESC, t.tag_name ASC
    ");
    jsonResponse(['tags' => $stmt->fetchAll()]);
  }

  if ($action === 'encyclopedia_captcha') {
    if (session_status() === PHP_SESSION_NONE) @session_start();
    $num1 = mt_rand(4, 25);
    $num2 = mt_rand(1, 15);
    $ops = ['+', '-'];
    $op = $ops[array_rand($ops)];
    if ($op === '-' && $num2 > $num1) {
      $t = $num1; $num1 = $num2; $num2 = $t;
    }
    $ans = ($op === '+') ? ($num1 + $num2) : ($num1 - $num2);
    $_SESSION['enc_captcha'] = (string)$ans;

    $lines = '';
    for ($i = 0; $i < 3; $i++) {
      $x1 = mt_rand(0, 140); $y1 = mt_rand(0, 38);
      $x2 = mt_rand(0, 140); $y2 = mt_rand(0, 38);
      $lines .= "<line x1='{$x1}' y1='{$y1}' x2='{$x2}' y2='{$y2}' stroke='rgba(255,255,255,0.1)' stroke-width='1.5'/>";
    }

    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="140" height="38" viewBox="0 0 140 38">
      <rect width="100%" height="100%" fill="#14141a" rx="8"/>
      ' . $lines . '
      <text x="50%" y="56%" dominant-baseline="middle" text-anchor="middle" font-family="monospace" font-size="18" font-weight="800" fill="#0096fa" letter-spacing="2">' . $num1 . ' ' . $op . ' ' . $num2 . ' = ?</text>
    </svg>';

    header('Content-Type: image/svg+xml');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo $svg;
    exit;
  }

  if ($action === 'encyclopedia_get') {
    $category = trim($_GET['category'] ?? '');
    $name = trim($_GET['name'] ?? '');
    if (!in_array($category, ['tag', 'character', 'parody']) || $name === '') {
      jsonResponse(['error' => 'Invalid category or name.'], 400);
    }

    $stmt = $db->prepare("
      SELECT e.*, u.artist_name as editor_name,
        (SELECT COUNT(*) FROM encyclopedia_revisions WHERE category = e.category AND name = e.name) as revision_count
      FROM encyclopedias e
      LEFT JOIN users u ON e.updated_by = u.id
      WHERE e.category = ? AND e.name = ?
    ");
    $stmt->execute([$category, $name]);
    $entry = $stmt->fetch();

    if (!$entry) {
      $entry = [
        'id'             => 0,
        'category'       => $category,
        'name'           => $name,
        'body'           => '',
        'updated_at'     => 0,
        'updated_by'     => 0,
        'editor_name'    => '',
        'revision_count' => 0
      ];
    } else {
      $entry['revision_count'] = max(1, (int)$entry['revision_count']);
    }

    // Resolve most viewed 1:1 image representation
    $topArt = null;
    if ($category === 'tag') {
      $stTop = $db->prepare("
        SELECT a.id, a.title, a.view_count,
          (SELECT file_name FROM artwork_images WHERE artwork_id = a.id ORDER BY sort_order ASC, id ASC LIMIT 1) as cover_file
        FROM artworks a
        JOIN tags t ON t.artwork_id = a.id
        WHERE t.tag_name = ? AND a.type != 'video'
        ORDER BY a.view_count DESC, a.like_count DESC, a.created_at DESC
        LIMIT 1
      ");
      $stTop->execute([$name]);
      $topArt = $stTop->fetch();
    } elseif ($category === 'character') {
      $stTop = $db->prepare("
        SELECT a.id, a.title, a.view_count,
          (SELECT file_name FROM artwork_images WHERE artwork_id = a.id ORDER BY sort_order ASC, id ASC LIMIT 1) as cover_file
        FROM artworks a
        WHERE a.characters LIKE ? AND a.type != 'video'
        ORDER BY a.view_count DESC, a.like_count DESC, a.created_at DESC
        LIMIT 1
      ");
      $stTop->execute(['%' . $name . '%']);
      $topArt = $stTop->fetch();
    } elseif ($category === 'parody') {
      $stTop = $db->prepare("
        SELECT a.id, a.title, a.view_count,
          (SELECT file_name FROM artwork_images WHERE artwork_id = a.id ORDER BY sort_order ASC, id ASC LIMIT 1) as cover_file
        FROM artworks a
        WHERE a.parodies LIKE ? AND a.type != 'video'
        ORDER BY a.view_count DESC, a.like_count DESC, a.created_at DESC
        LIMIT 1
      ");
      $stTop->execute(['%' . $name . '%']);
      $topArt = $stTop->fetch();
    }

    $entry['top_artwork_id'] = $topArt ? (int)$topArt['id'] : 0;
    $entry['top_artwork_title'] = $topArt ? $topArt['title'] : '';
    $entry['top_artwork_views'] = $topArt ? (int)$topArt['view_count'] : 0;
    $entry['top_artwork_cover'] = $topArt ? $topArt['cover_file'] : '';

    jsonResponse($entry);
  }

  if ($action === 'encyclopedia_save') {
    verifyCsrfToken();
    $user = requireAuth($db);

    $captchaInput = trim($_POST['captcha'] ?? '');
    $expectedCaptcha = $_SESSION['enc_captcha'] ?? '';
    unset($_SESSION['enc_captcha']);

    if ($expectedCaptcha === '' || $captchaInput !== $expectedCaptcha) {
      jsonResponse(['error' => 'Incorrect security captcha. Please solve the calculation and try again.'], 400);
    }

    // Limit: 10 encyclopedia edits per 24 hours (Bypassed for Admins)
    if (empty($user['is_admin'])) {
      $since24h = time() - 86400;
      $stmtEdits = $db->prepare("
        SELECT COUNT(*) FROM encyclopedia_revisions
        WHERE user_id = ? AND created_at >= ?
      ");
      $stmtEdits->execute([$user['id'], $since24h]);
      $dailyEditCount = (int)$stmtEdits->fetchColumn();

      if ($dailyEditCount >= 10) {
        jsonResponse([
          'error' => "Daily limit reached: You can edit the encyclopedia up to 10 times per 24 hours ({$dailyEditCount}/10 used). Please try again later."
        ], 429);
      }
    }

    $category = trim($_POST['category'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $body = trim($_POST['body'] ?? '');
    $summary = mb_substr(trim($_POST['edit_summary'] ?? ''), 0, 255);
    if ($summary === '') $summary = 'Updated article content';

    if (!in_array($category, ['tag', 'character', 'parody']) || $name === '') {
      jsonResponse(['error' => 'Invalid category or name.'], 400);
    }

    $now = time();
    $stmt = $db->prepare("
      REPLACE INTO encyclopedias (category, name, body, updated_at, updated_by)
      VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$category, $name, $body, $now, $user['id']]);

    $revStmt = $db->prepare("
      INSERT INTO encyclopedia_revisions (category, name, body, edit_summary, user_id, created_at)
      VALUES (?, ?, ?, ?, ?, ?)
    ");
    $revStmt->execute([$category, $name, $body, $summary, $user['id'], $now]);

    logActivity($db, $user['id'], 'encyclopedia_edit', 0, "Edited {$category} encyclopedia: {$name}");
    jsonResponse([
      'success'     => true,
      'category'    => $category,
      'name'        => $name,
      'body'        => $body,
      'updated_at'  => $now,
      'editor_name' => $user['artist_name']
    ]);
  }

  if ($action === 'encyclopedia_history') {
    $category = trim($_GET['category'] ?? '');
    $name = trim($_GET['name'] ?? '');
    if (!in_array($category, ['tag', 'character', 'parody']) || $name === '') {
      jsonResponse(['error' => 'Invalid category or name.'], 400);
    }

    $stmt = $db->prepare("
      SELECT r.id, r.edit_summary, r.created_at, u.id as user_id, u.artist_name as editor_name
      FROM encyclopedia_revisions r
      LEFT JOIN users u ON r.user_id = u.id
      WHERE r.category = ? AND r.name = ?
      ORDER BY r.created_at DESC
      LIMIT 30
    ");
    $stmt->execute([$category, $name]);
    $history = $stmt->fetchAll();

    jsonResponse([
      'category' => $category,
      'name'     => $name,
      'history'  => $history ?: []
    ]);
  }

  if ($action === 'characters_all') {
    $rows = $db->query("
      SELECT a.id, a.characters, a.created_at,
        (SELECT file_name FROM artwork_images WHERE artwork_id = a.id ORDER BY sort_order ASC LIMIT 1) as cover_file
      FROM artworks a
      WHERE a.characters != ''
      ORDER BY a.created_at DESC
    ")->fetchAll();

    $counts = [];
    $covers = [];
    foreach ($rows as $r) {
      $items = preg_split('/[,，、]+/u', $r['characters'], -1, PREG_SPLIT_NO_EMPTY);
      foreach ($items as $item) {
        $t = trim($item);
        if ($t !== '') {
          $counts[$t] = ($counts[$t] ?? 0) + 1;
          if (empty($covers[$t]) && !empty($r['cover_file'])) {
            $covers[$t] = $r['cover_file'];
          }
        }
      }
    }
    arsort($counts);
    $list = [];
    foreach ($counts as $name => $count) {
      $list[] = ['name' => $name, 'count' => $count, 'cover_file' => $covers[$name] ?? ''];
    }
    jsonResponse(['characters' => $list]);
  }

  if ($action === 'series_all') {
    $rows = $db->query("
      SELECT a.id, a.parodies, a.created_at,
        (SELECT file_name FROM artwork_images WHERE artwork_id = a.id ORDER BY sort_order ASC LIMIT 1) as cover_file
      FROM artworks a
      WHERE a.parodies != ''
      ORDER BY a.created_at DESC
    ")->fetchAll();

    $counts = [];
    $covers = [];
    foreach ($rows as $r) {
      $items = preg_split('/[,，、]+/u', $r['parodies'], -1, PREG_SPLIT_NO_EMPTY);
      foreach ($items as $item) {
        $t = trim($item);
        if ($t !== '') {
          $counts[$t] = ($counts[$t] ?? 0) + 1;
          if (empty($covers[$t]) && !empty($r['cover_file'])) {
            $covers[$t] = $r['cover_file'];
          }
        }
      }
    }
    arsort($counts);
    $list = [];
    foreach ($counts as $name => $count) {
      $list[] = ['name' => $name, 'count' => $count, 'cover_file' => $covers[$name] ?? ''];
    }
    jsonResponse(['series' => $list]);
  }

  if ($action === 'artists_all') {
    $stmt = $db->query("
      SELECT u.id, u.username, u.artist_name, u.email_hash, u.avatar, u.bio,
        (SELECT COUNT(*) FROM artworks WHERE user_id = u.id) as artwork_count
      FROM users u
      ORDER BY artwork_count DESC, u.id ASC
    ");
    jsonResponse(['artists' => $stmt->fetchAll()]);
  }

  if ($action === 'admin_stats') {
    requireAdmin($db);
    $artCount = (int)$db->query("SELECT COUNT(*) FROM artworks")->fetchColumn();
    $userCount = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $commCount = (int)$db->query("SELECT COUNT(*) FROM comments")->fetchColumn();
    $likeCount = (int)$db->query("SELECT COUNT(*) FROM likes")->fetchColumn();

    $uploadDirSize = 0;
    foreach (glob($config['upload_dir'] . '/*') as $f) {
      if (is_file($f)) $uploadDirSize += filesize($f);
    }
    foreach (glob($config['thumb_dir'] . '/*') as $f) {
      if (is_file($f)) $uploadDirSize += filesize($f);
    }

    jsonResponse([
      'artworks'    => $artCount,
      'users'       => $userCount,
      'comments'    => $commCount,
      'likes'       => $likeCount,
      'disk_usage'  => round($uploadDirSize / 1024 / 1024, 2) . ' MB',
      'php_version' => PHP_VERSION,
      'sqlite_wal'  => 'WAL Enabled'
    ]);
  }

  if ($action === 'admin_users') {
    requireAdmin($db);
    $q = trim($_GET['q'] ?? '');
    $sort = $_GET['sort'] ?? 'id_asc';
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = 25;
    $offset = ($page - 1) * $limit;

    $where = "1=1";
    $params = [];
    if ($q !== '') {
      $where = "artist_name LIKE ?";
      $params = ["%$q%"];
    }

    $orderSql = "id ASC";
    if ($sort === 'id_desc') $orderSql = "id DESC";
    elseif ($sort === 'name_asc') $orderSql = "artist_name COLLATE NOCASE ASC";
    elseif ($sort === 'name_desc') $orderSql = "artist_name COLLATE NOCASE DESC";
    elseif ($sort === 'posts_desc') $orderSql = "artwork_count DESC, id ASC";
    elseif ($sort === 'created_desc') $orderSql = "created_at DESC";

    $countStmt = $db->prepare("SELECT COUNT(*) FROM users WHERE {$where}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $db->prepare("
      SELECT id, artist_name, email_hash, is_admin, is_banned, created_at, 
        (SELECT COUNT(*) FROM artworks WHERE user_id = users.id) as artwork_count 
      FROM users 
      WHERE {$where} 
      ORDER BY {$orderSql} 
      LIMIT {$limit} OFFSET {$offset}
    ");
    $stmt->execute($params);
    jsonResponse([
      'users' => $stmt->fetchAll(),
      'total' => $total,
      'page'  => $page,
      'pages' => max(1, (int)ceil($total / $limit))
    ]);
  }

  if ($action === 'admin_toggle_ban') {
    verifyCsrfToken();
    $admin = requireAdmin($db);
    $targetId = intval($_POST['user_id'] ?? 0);
    if ($targetId === (int)$admin['id']) jsonResponse(['error' => 'Cannot ban your own account'], 400);

    $stmt = $db->prepare("SELECT is_admin, is_banned FROM users WHERE id = ?");
    $stmt->execute([$targetId]);
    $target = $stmt->fetch();
    if (!$target) jsonResponse(['error' => 'Target user not found'], 404);

    if ((int)$target['is_admin'] === 2) {
      jsonResponse(['error' => 'The Super Administrator account cannot be banned'], 403);
    }
    if ((int)$target['is_admin'] >= 1 && (int)$admin['is_admin'] !== 2) {
      jsonResponse(['error' => 'Only the Super Administrator can ban another administrator'], 403);
    }

    $newBan = ((int)$target['is_banned']) ? 0 : 1;
    $db->prepare("UPDATE users SET is_banned = ? WHERE id = ?")->execute([$newBan, $targetId]);
    logActivity($db, $admin['id'], 'user_ban', $targetId, $newBan ? 'Banned user account' : 'Unbanned user account');
    jsonResponse(['success' => true, 'is_banned' => $newBan]);
  }

  if ($action === 'admin_toggle_role') {
    verifyCsrfToken();
    $admin = requireAdmin($db);
    $targetId = intval($_POST['user_id'] ?? 0);
    if ($targetId === (int)$admin['id']) jsonResponse(['error' => 'Cannot alter own admin role'], 400);

    $stmt = $db->prepare("SELECT is_admin FROM users WHERE id = ?");
    $stmt->execute([$targetId]);
    $targetRole = $stmt->fetchColumn();
    if ($targetRole === false) jsonResponse(['error' => 'Target user not found'], 404);
    $targetRole = (int)$targetRole;

    if ($targetRole === 2) {
      jsonResponse(['error' => 'The Super Administrator role cannot be changed or demoted'], 403);
    }
    if ((int)$admin['is_admin'] !== 2) {
      jsonResponse(['error' => 'Only the Super Administrator can promote or demote administrators'], 403);
    }

    $new = $targetRole === 1 ? 0 : 1;
    $db->prepare("UPDATE users SET is_admin = ? WHERE id = ?")->execute([$new, $targetId]);
    logActivity($db, $admin['id'], 'role_change', $targetId, $new ? 'Promoted user to Admin' : 'Demoted user to standard artist');
    jsonResponse(['success' => true, 'is_admin' => $new]);
  }

  if ($action === 'admin_search_candidates') {
    requireAdmin($db);
    $q = trim($_GET['q'] ?? '');
    $where = "is_admin = 0 AND is_banned = 0";
    $params = [];
    if ($q !== '') {
      $where .= " AND artist_name LIKE ?";
      $params[] = "%$q%";
    }
    $stmt = $db->prepare("
      SELECT id, artist_name, email_hash, avatar,
        (SELECT COUNT(*) FROM artworks WHERE user_id = users.id) as artwork_count
      FROM users
      WHERE {$where}
      ORDER BY artist_name COLLATE NOCASE ASC
      LIMIT 25
    ");
    $stmt->execute($params);
    jsonResponse(['users' => $stmt->fetchAll()]);
  }

  if ($action === 'admin_add_admin') {
    verifyCsrfToken();
    $admin = requireAdmin($db);
    if ((int)$admin['is_admin'] !== 2) {
      jsonResponse(['error' => 'Only the Super Administrator can appoint administrators.'], 403);
    }
    $targetId = intval($_POST['user_id'] ?? 0);
    if ($targetId <= 0) jsonResponse(['error' => 'Valid user ID is required.'], 400);

    $stmt = $db->prepare("SELECT id, artist_name, is_admin FROM users WHERE id = ?");
    $stmt->execute([$targetId]);
    $target = $stmt->fetch();
    if (!$target) jsonResponse(['error' => 'Target user not found.'], 404);
    if ((int)$target['is_admin'] >= 1) jsonResponse(['error' => 'User is already an administrator.'], 400);

    $db->prepare("UPDATE users SET is_admin = 1 WHERE id = ?")->execute([$targetId]);
    logActivity($db, $admin['id'], 'role_change', $targetId, "Appointed {$target['artist_name']} as Administrator");
    jsonResponse(['success' => true, 'artist_name' => $target['artist_name']]);
  }

  if ($action === 'admin_delete_user') {
    verifyCsrfToken();
    $admin = requireAdmin($db);
    $targetId = intval($_POST['user_id'] ?? 0);
    if ($targetId === (int)$admin['id']) jsonResponse(['error' => 'Cannot delete active administrator account'], 400);

    $stmt = $db->prepare("SELECT is_admin FROM users WHERE id = ?");
    $stmt->execute([$targetId]);
    $targetRole = $stmt->fetchColumn();
    if ($targetRole === false) jsonResponse(['error' => 'User not found'], 404);
    $targetRole = (int)$targetRole;

    if ($targetRole === 2) {
      jsonResponse(['error' => 'The Super Administrator account cannot be deleted'], 403);
    }
    if ($targetRole >= 1 && (int)$admin['is_admin'] !== 2) {
      jsonResponse(['error' => 'Only the Super Administrator can delete an administrator'], 403);
    }

    $db->prepare("DELETE FROM users WHERE id = ?")->execute([$targetId]);
    logActivity($db, $admin['id'], 'user_delete', $targetId, "Deleted user #{$targetId}");
    jsonResponse(['success' => true]);
  }

  if ($action === 'admin_comments') {
    requireAdmin($db);
    $q = trim($_GET['q'] ?? '');
    $sort = $_GET['sort'] ?? 'newest';
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = 25;
    $offset = ($page - 1) * $limit;

    $where = "1=1";
    $params = [];
    if ($q !== '') {
      $where = "(c.comment LIKE ? OR u.artist_name LIKE ? OR a.title LIKE ?)";
      $params = ["%$q%", "%$q%", "%$q%"];
    }

    $orderSql = ($sort === 'oldest') ? "c.created_at ASC" : "c.created_at DESC";

    $countStmt = $db->prepare("
      SELECT COUNT(*) 
      FROM comments c
      JOIN users u ON c.user_id = u.id
      JOIN artworks a ON c.artwork_id = a.id
      WHERE {$where}
    ");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $db->prepare("
      SELECT c.*, u.artist_name, a.title as art_title
      FROM comments c
      JOIN users u ON c.user_id = u.id
      JOIN artworks a ON c.artwork_id = a.id
      WHERE {$where}
      ORDER BY {$orderSql}
      LIMIT {$limit} OFFSET {$offset}
    ");
    $stmt->execute($params);
    jsonResponse([
      'comments' => $stmt->fetchAll(),
      'total'    => $total,
      'page'     => $page,
      'pages'    => max(1, (int)ceil($total / $limit))
    ]);
  }

  if ($action === 'activity_list') {
    $user = requireAuth($db);
    $stmt = $db->prepare("
      SELECT a.*, u.artist_name, u.avatar, u.email_hash
      FROM activity_log a
      JOIN users u ON a.user_id = u.id
      WHERE a.user_id = ?
      ORDER BY a.created_at DESC
      LIMIT 25
    ");
    $stmt->execute([$user['id']]);
    jsonResponse(['activities' => $stmt->fetchAll()]);
  }

  if ($action === 'thumb' || $action === 'raw') {
    $rawFile = str_replace('\\', '/', trim($_GET['f'] ?? ''));
    if (strpos($rawFile, '..') !== false || strpos($rawFile, ':') !== false) {
      header('HTTP/1.0 403 Forbidden');
      exit;
    }
    $file = preg_replace('/[^a-zA-Z0-9_\.\/-]/', '', $rawFile);
    $file = ltrim($file, '/');
    if (!$file || !preg_match('/\.(jpg|jpeg|png|gif|webp|avif|bmp|mp4|webm|mov|mkv|ogg)$/i', $file)) {
      header('HTTP/1.0 404 Not Found');
      exit;
    }

    $isThumb = ($action === 'thumb');
    $osRelPath = str_replace('/', DIRECTORY_SEPARATOR, $file);
    $rawPath = $config['upload_dir'] . DIRECTORY_SEPARATOR . $osRelPath;

    if ($isThumb) {
      $thumbCandidate = $config['thumb_dir'] . DIRECTORY_SEPARATOR . $osRelPath . '.jpg';
      if (!file_exists($thumbCandidate)) {
        $thumbCandidate = $config['thumb_dir'] . DIRECTORY_SEPARATOR . $osRelPath;
      }
      if (!file_exists($thumbCandidate)) {
        $thumbCandidate = $config['thumb_dir'] . DIRECTORY_SEPARATOR . 'thumb_' . basename($file) . '.jpg';
      }
      if (!file_exists($thumbCandidate) && file_exists($rawPath)) {
        $targetThumb = $config['thumb_dir'] . DIRECTORY_SEPARATOR . $osRelPath . '.jpg';
        if (!is_dir(dirname($targetThumb))) @mkdir(dirname($targetThumb), 0755, true);
        if (createThumbnail($rawPath, $targetThumb, $config['thumb_width'], $config['thumb_quality'])) {
          $thumbCandidate = $targetThumb;
        }
      }
      if (file_exists($thumbCandidate)) {
        $mime = getFileMime($thumbCandidate, 'image/jpeg');
        streamRangeFile($thumbCandidate, $mime);
      } elseif (file_exists($rawPath)) {
        $mime = getFileMime($rawPath, 'image/jpeg');
        streamRangeFile($rawPath, $mime);
      }
    } else {
      if (file_exists($rawPath)) {
        $mime = getFileMime($rawPath, 'image/jpeg');
        streamRangeFile($rawPath, $mime);
      }
    }

    header('HTTP/1.0 404 Not Found');
    exit;
  }

  jsonResponse(['error' => 'Invalid action'], 400);
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title><?= htmlspecialchars($config['app_name']) ?> &ndash; Creative Studio &amp; Artwork Cloud Archive</title>
    <link rel="icon" type="image/svg+xml" href="?action=icon">
    <link rel="manifest" href="?pwa=manifest">
    <meta name="theme-color" content="#121216">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/dompurify/3.0.8/purify.min.js"></script>
    <style>
      :root[data-theme="dark"] {
        --bg-base: #0f0f13;
        --bg-surface: #17171f;
        --bg-surface-elevated: #22222d;
        --bg-surface-hover: #2c2c3a;
        --border-subtle: #2c2c3c;
        --border-strong: #3e3e54;
        --text-primary: #f2f2fa;
        --text-secondary: #9ea0b8;
        --text-muted: #6f7188;
        --accent: #0096fa;
        --accent-hover: #1eabff;
        --accent-alpha: rgba(0, 150, 250, 0.16);
        --like: #ff4772;
        --like-alpha: rgba(255, 71, 114, 0.16);
        --bookmark: #ffb800;
        --r18: #ff334b;
        --video: #0ea5e9;
        --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.35);
        --shadow-md: 0 8px 24px rgba(0, 0, 0, 0.45);
        --shadow-lg: 0 16px 48px rgba(0, 0, 0, 0.65);
      }
      :root[data-theme="light"] {
        --bg-base: #f5f6fa;
        --bg-surface: #ffffff;
        --bg-surface-elevated: #edf0f7;
        --bg-surface-hover: #e2e6f2;
        --border-subtle: #e0e3ee;
        --border-strong: #c8cde0;
        --text-primary: #1a1b24;
        --text-secondary: #585a72;
        --text-muted: #8b8eab;
        --accent: #0084e6;
        --accent-hover: #0073cb;
        --accent-alpha: rgba(0, 132, 230, 0.12);
        --like: #ff4772;
        --like-alpha: rgba(255, 71, 114, 0.12);
        --bookmark: #e6a100;
        --r18: #e62238;
        --video: #0284c7;
        --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.06);
        --shadow-md: 0 8px 24px rgba(0, 0, 0, 0.08);
        --shadow-lg: 0 16px 48px rgba(0, 0, 0, 0.12);
      }

      /* Beautiful Smooth Custom Scrollbar */
      * {
        scrollbar-width: thin;
        scrollbar-color: var(--border-strong) transparent;
      }
      ::-webkit-scrollbar {
        width: 8px;
        height: 8px;
      }
      ::-webkit-scrollbar-track {
        background: transparent;
      }
      ::-webkit-scrollbar-thumb {
        background: var(--border-strong);
        border-radius: 9999px;
        border: 2px solid transparent;
        background-clip: content-box;
        transition: background-color 0.2s ease;
      }
      ::-webkit-scrollbar-thumb:hover {
        background: var(--accent);
        background-clip: content-box;
      }
      ::-webkit-scrollbar-corner {
        background: transparent;
      }

      *, *::before, *::after {
        box-sizing: border-box;
        margin: 0;
        padding: 0;
        -webkit-tap-highlight-color: transparent;
      }
      body {
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        background-color: var(--bg-base);
        color: var(--text-primary);
        min-height: 100dvh;
        height: 100dvh;
        display: flex;
        flex-direction: column;
        overflow: hidden;
        user-select: none;
        -webkit-font-smoothing: antialiased;
      }
      a { color: inherit; text-decoration: none; }
      button, input, select, textarea {
        font-family: inherit;
        color: inherit;
        border: none;
        background: none;
        outline: none;
      }
      button { cursor: pointer; display: inline-flex; align-items: center; justify-content: center; }
      svg { width: 20px; height: 20px; fill: currentColor; flex-shrink: 0; }
  
      .app-header {
        height: 58px;
        background: var(--bg-surface);
        border-bottom: 1px solid var(--border-subtle);
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0 1.25rem;
        z-index: 100;
        gap: 0.8rem;
        flex-shrink: 0;
      }
      .header-left, .header-right { display: flex; align-items: center; gap: 0.55rem; height: 100%; }
      .header-center { flex: 1; max-width: 520px; display: flex; justify-content: center; align-items: center; }
      #user-nav-slot { display: flex; align-items: center; justify-content: center; height: 100%; }
      #user-nav-slot img { display: block; vertical-align: middle; }
      .brand-logo {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-weight: 800;
        font-size: 1.15rem;
        letter-spacing: -0.5px;
        color: var(--accent);
      }
      .brand-logo svg { width: 28px; height: 28px; fill: var(--accent); }
  
      .search-bar {
        position: relative;
        width: 100%;
        height: 38px;
        background: var(--bg-surface-elevated);
        border: 1px solid var(--border-subtle);
        border-radius: 20px;
        display: flex;
        align-items: center;
        padding: 0 0.85rem;
        gap: 0.5rem;
        transition: all 0.2s ease;
      }
      .search-bar:focus-within {
        border-color: var(--accent);
        background: var(--bg-surface);
        box-shadow: 0 0 0 3px var(--accent-alpha);
      }
      .search-bar input {
        flex: 1;
        height: 100%;
        font-size: 0.85rem;
      }
      .search-bar svg { color: var(--text-muted); }
  
      .btn-icon {
        width: 38px;
        height: 38px;
        border-radius: 19px;
        color: var(--text-secondary);
        transition: background 0.15s, color 0.15s;
      }
      .btn-icon:hover { background: var(--bg-surface-hover); color: var(--text-primary); }
  
      .btn-primary {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: var(--accent);
        color: #ffffff;
        padding: 0 1.1rem;
        height: 36px;
        border-radius: 10px;
        font-size: 0.85rem;
        font-weight: 600;
        gap: 0.45rem;
        text-decoration: none;
        cursor: pointer;
        box-sizing: border-box;
        transition: background 0.15s, transform 0.1s;
      }
      .btn-primary:hover { background: var(--accent-hover); }
      .btn-primary:active { transform: scale(0.97); }
  
      .btn-subtle {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: var(--bg-surface-elevated);
        color: var(--text-primary);
        border: 1px solid var(--border-subtle);
        padding: 0 0.9rem;
        height: 36px;
        border-radius: 10px;
        font-size: 0.85rem;
        font-weight: 500;
        gap: 0.4rem;
        text-decoration: none;
        cursor: pointer;
        box-sizing: border-box;
        transition: background 0.15s, border-color 0.15s;
      }
      .btn-subtle:hover { background: var(--bg-surface-hover); border-color: var(--border-strong); }
  
      .app-body {
        display: flex;
        flex: 1;
        overflow: hidden;
        position: relative;
      }
  
      .sidebar-backdrop {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.65);
        backdrop-filter: blur(4px);
        z-index: 950;
        display: none;
        opacity: 0;
        transition: opacity 0.25s ease;
      }
      .sidebar-backdrop.active {
        display: block;
        opacity: 1;
      }
  
      .nav-sidebar {
        width: 240px;
        background: var(--bg-surface);
        border-right: 1px solid var(--border-subtle);
        display: flex;
        flex-direction: column;
        overflow-y: auto;
        flex-shrink: 0;
        padding: 1rem 0.6rem;
        gap: 0.2rem;
        z-index: 90;
      }

      /* Desktop: Hamburger menu is hidden, sidebar is permanently visible */
      @media (min-width: 769px) {
        #btn-toggle-menu {
          display: none !important;
        }
        .nav-sidebar {
          margin-left: 0 !important;
          transform: none !important;
        }
      }
  
      @media (max-width: 768px) {
        #btn-toggle-menu {
          display: inline-flex;
        }
        .nav-sidebar {
          position: fixed;
          top: 0;
          bottom: 0;
          left: 0;
          width: 280px;
          max-width: 85vw;
          height: 100dvh;
          transform: translateX(-100%);
          box-shadow: var(--shadow-lg);
          z-index: 1000;
          transition: transform 0.25s cubic-bezier(0.2, 0, 0, 1);
        }
        .nav-sidebar.open {
          transform: translateX(0);
        }
        .sidebar-mobile-header {
          display: flex !important;
        }
      }
  
      .sidebar-mobile-header {
        display: none;
        align-items: center;
        justify-content: space-between;
        padding: 0.2rem 0.4rem 0.8rem 0.4rem;
        border-bottom: 1px solid var(--border-subtle);
        margin-bottom: 0.4rem;
      }
  
      .nav-item {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.6rem 0.9rem;
        border-radius: 12px;
        color: var(--text-secondary);
        font-weight: 600;
        font-size: 0.88rem;
        cursor: pointer;
        transition: all 0.15s ease;
      }
      .nav-item:hover { background: var(--bg-surface-hover); color: var(--text-primary); }
      .nav-item.active { background: var(--accent-alpha); color: var(--accent); }
      .nav-divider { height: 1px; background: var(--border-subtle); margin: 0.6rem 0.5rem; }
      .nav-heading { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted); padding: 0.3rem 0.9rem; letter-spacing: 0.6px; }
  
      .main-viewport {
        flex: 1;
        overflow-y: auto;
        overflow-x: hidden;
        display: flex;
        flex-direction: column;
        position: relative;
        background: var(--bg-base);
        scroll-behavior: smooth;
        -webkit-overflow-scrolling: touch;
      }
  
      .page-container {
        width: 100%;
        max-width: 1600px;
        margin: 0 auto;
        padding: 1.5rem;
        flex: 1;
        opacity: 1;
        transform: translateY(0);
        transition: opacity 0.2s cubic-bezier(0.2, 0, 0, 1), transform 0.2s cubic-bezier(0.2, 0, 0, 1);
      }
      .page-container.transitioning {
        opacity: 0;
        transform: translateY(10px);
      }
  
      .art-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
        gap: 1.15rem;
      }
      .form-grid-2 {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.9rem;
      }
      .studio-card {
        width: 100%;
        max-width: 880px;
        margin: 0 auto;
        background: var(--bg-surface);
        border: 1px solid var(--border-subtle);
        border-radius: 18px;
        padding: 1.8rem;
      }

      /* Beautified Import Drop Zone */
      .import-drop-zone {
        border: 2px dashed var(--border-strong);
        background: var(--bg-surface-elevated);
        border-radius: 12px;
        padding: 1.5rem 1rem;
        text-align: center;
        cursor: pointer;
        transition: border-color 0.15s, background 0.15s;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 0.45rem;
      }
      .import-drop-zone:hover, .import-drop-zone.dragover {
        border-color: var(--accent);
        background: var(--accent-alpha);
      }

      @media (max-width: 768px) {
        .art-grid {
          grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
          gap: 0.75rem;
        }
        .page-container {
          padding: 0.6rem;
        }
        .app-header {
          height: 52px;
          padding: 0 0.65rem;
          gap: 0.5rem;
        }
        .app-header .header-center {
          flex: 1;
          max-width: none;
          margin: 0;
        }
        .header-left {
          gap: 0;
        }
        .brand-logo {
          display: none !important;
        }
        .search-bar {
          height: 34px;
          padding: 0 0.7rem;
          gap: 0.4rem;
          border-radius: 17px;
        }
        .search-bar svg {
          width: 16px;
          height: 16px;
        }
        .search-bar input {
          font-size: 0.8rem;
        }
        .search-bar input::placeholder {
          font-size: 0.76rem;
        }
        .btn-icon {
          width: 34px;
          height: 34px;
        }
        #user-nav-slot img {
          width: 34px;
          height: 34px;
        }
        .feed-header-wrap {
          flex-direction: column;
          align-items: stretch !important;
          gap: 0.75rem !important;
        }
        .feed-header-controls {
          width: 100%;
          display: grid !important;
          grid-template-columns: 1fr 1fr;
          gap: 0.5rem !important;
        }
        .feed-header-controls>* {
          width: 100% !important;
          min-width: 0;
        }
        .feed-header-controls .btn-subtle {
          grid-column: span 2;
        }
        .pagination-bar {
          gap: 0.35rem !important;
        }
        .pagination-bar .btn-subtle {
          height: 32px;
          padding: 0 0.55rem;
          font-size: 0.75rem;
        }

        /* Studio & Form Mobile Optimization */
        .studio-card {
          padding: 1.1rem !important;
          border-radius: 14px !important;
        }
        .form-grid-2 {
          grid-template-columns: 1fr !important;
          gap: 0.85rem !important;
        }
        .studio-actions {
          flex-direction: column-reverse;
          gap: 0.6rem !important;
        }
        .studio-actions button {
          width: 100%;
          height: 42px !important;
          font-size: 0.9rem !important;
        }

        /* Viewer Mobile Optimization */
        .viewer-layout {
          gap: 0.75rem !important;
        }
        .viewer-info-card, .viewer-comments-card, .author-card {
          padding: 0.95rem !important;
          border-radius: 12px !important;
        }
        .viewer-info-card h1 {
          font-size: 1.2rem !important;
          line-height: 1.3;
        }
        .viewer-media-wrap {
          border-radius: 12px !important;
        }
        .viewer-media-wrap img, .viewer-media-wrap video {
          max-height: 72dvh !important;
        }
        .artwork-owner-actions {
          width: 100%;
          display: flex;
          flex-wrap: wrap;
          gap: 0.4rem;
          margin-top: 0.35rem;
        }
        .artwork-owner-actions .btn-subtle {
          flex: 1;
          height: 32px;
          font-size: 0.75rem;
          padding: 0 0.6rem;
        }
      }

      .art-card {
        background: var(--bg-surface);
        border: 1px solid var(--border-subtle);
        border-radius: 10px;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        cursor: pointer;
        position: relative;
        transition: transform 0.2s ease, box-shadow 0.2s, border-color 0.2s;
      }
      .art-card:hover {
        transform: translateY(-4px);
        box-shadow: var(--shadow-md);
        border-color: var(--border-strong);
      }
      .art-thumb-wrap {
        width: 100%;
        aspect-ratio: 1 / 1;
        background: #08080a url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 50 50'%3E%3Ccircle cx='25' cy='25' r='18' fill='none' stroke='%230096fa' stroke-width='3.5' stroke-linecap='round' stroke-dasharray='75' stroke-dashoffset='25'%3E%3CanimateTransform attributeName='transform' type='rotate' from='0 25 25' to='360 25 25' dur='0.8s' repeatCount='indefinite'/%3E%3C/circle%3E%3C/svg%3E") no-repeat center center;
        background-size: 32px 32px;
        position: relative;
        overflow: hidden;
      }
      .art-thumb-wrap img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
        transition: transform 0.3s ease;
      }
      .art-card:hover .art-thumb-wrap img { transform: scale(1.04); }
  
      .badge-page-count {
        position: absolute;
        top: 8px;
        right: 8px;
        background: rgba(0, 0, 0, 0.72);
        backdrop-filter: blur(4px);
        color: #ffffff;
        font-size: 0.7rem;
        font-weight: 700;
        padding: 0.15rem 0.45rem;
        border-radius: 6px;
        display: flex;
        align-items: center;
        gap: 0.25rem;
        z-index: 2;
      }
      .badge-flag {
        position: absolute;
        top: 8px;
        left: 8px;
        background: var(--r18);
        color: #fff;
        font-size: 0.65rem;
        font-weight: 800;
        padding: 0.15rem 0.45rem;
        border-radius: 4px;
        z-index: 2;
      }
      .badge-flag.ai { background: #8b5cf6; }
      .badge-flag.video { background: var(--video); }
      .badge-flag.manga { background: #f97316; }

      /* Encyclopedia Components with 1:1 Preview */
      .encyclopedia-card {
        background: var(--bg-surface);
        border: 1px solid var(--border-subtle);
        border-radius: 14px;
        padding: 1.2rem 1.4rem;
        margin-bottom: 1.4rem;
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
        box-shadow: var(--shadow-sm);
      }
      .encyclopedia-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 0.8rem;
      }
      .encyclopedia-main-row {
        display: flex;
        gap: 1.2rem;
        align-items: flex-start;
      }
      .encyclopedia-preview-thumb {
        width: 120px;
        height: 120px;
        aspect-ratio: 1 / 1;
        border-radius: 10px;
        overflow: hidden;
        background: #08080a;
        border: 1px solid var(--border-subtle);
        position: relative;
        cursor: pointer;
        flex-shrink: 0;
        transition: transform 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
      }
      .encyclopedia-preview-thumb:hover {
        transform: translateY(-2px);
        border-color: var(--accent);
        box-shadow: var(--shadow-md);
      }
      .encyclopedia-preview-thumb img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
        transition: transform 0.3s ease;
      }
      .encyclopedia-preview-thumb:hover img {
        transform: scale(1.06);
      }
      .encyclopedia-preview-badge {
        position: absolute;
        bottom: 4px;
        left: 4px;
        right: 4px;
        background: rgba(0, 0, 0, 0.75);
        backdrop-filter: blur(4px);
        color: #ffffff;
        font-size: 0.64rem;
        font-weight: 800;
        padding: 2px 4px;
        border-radius: 4px;
        text-align: center;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
      }
      @media (max-width: 680px) {
        .encyclopedia-main-row {
          flex-direction: column;
          align-items: stretch;
        }
        .encyclopedia-preview-thumb {
          width: 100%;
          max-width: 140px;
          height: auto;
          margin: 0 auto;
        }
      }
      .encyclopedia-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        font-size: 0.72rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        padding: 0.2rem 0.6rem;
        border-radius: 6px;
      }
      .encyclopedia-badge.tag { background: rgba(16, 185, 129, 0.15); color: #10b981; }
      .encyclopedia-badge.character { background: rgba(168, 85, 247, 0.15); color: #a855f7; }
      .encyclopedia-badge.parody { background: rgba(56, 189, 248, 0.15); color: #38bdf8; }
      .encyclopedia-body {
        font-size: 0.9rem;
        line-height: 1.65;
        color: var(--text-primary);
      }
      .encyclopedia-body p { margin-bottom: 0.6rem; }
      .encyclopedia-body p:last-child { margin-bottom: 0; }
      .encyclopedia-meta {
        font-size: 0.75rem;
        color: var(--text-muted);
        display: flex;
        align-items: center;
        gap: 0.5rem;
      }

      /* Directory Visual Cards with Background Art */
      .directory-card-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
        gap: 1rem;
      }
      .dir-bg-card {
        position: relative;
        height: 110px;
        border-radius: 12px;
        overflow: hidden;
        border: 1px solid var(--border-subtle);
        background: var(--bg-surface-elevated);
        cursor: pointer;
        display: flex;
        flex-direction: column;
        justify-content: flex-end;
        padding: 0.85rem;
        transition: transform 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
      }
      .dir-bg-card:hover {
        transform: translateY(-3px);
        border-color: var(--accent);
        box-shadow: var(--shadow-md);
      }
      .dir-bg-img {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        object-fit: cover;
        filter: brightness(0.45);
        transition: transform 0.3s ease, filter 0.3s ease;
      }
      .dir-bg-card:hover .dir-bg-img {
        transform: scale(1.06);
        filter: brightness(0.6);
      }
      .dir-bg-overlay {
        position: absolute;
        inset: 0;
        background: linear-gradient(180deg, rgba(0,0,0,0.1) 0%, rgba(0,0,0,0.85) 100%);
        pointer-events: none;
      }
      .dir-bg-info {
        position: relative;
        z-index: 2;
        display: flex;
        flex-direction: column;
        gap: 0.15rem;
      }
      .dir-bg-title {
        font-weight: 800;
        font-size: 0.95rem;
        color: #ffffff;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        text-shadow: 0 1px 3px rgba(0, 0, 0, 0.8);
      }
      .dir-bg-count {
        font-size: 0.75rem;
        color: rgba(255, 255, 255, 0.7);
        font-weight: 600;
      }

      /* Manga Series Component */
      .manga-series-card {
        background: var(--bg-surface);
        border: 1px solid var(--border-subtle);
        border-radius: 10px;
        padding: 0.6rem 0.75rem;
        display: flex;
        flex-direction: column;
        gap: 0.45rem;
      }
      .manga-series-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.4rem;
      }
      .manga-series-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        background: rgba(249, 115, 22, 0.15);
        color: #f97316;
        font-weight: 800;
        font-size: 0.65rem;
        padding: 0.15rem 0.4rem;
        border-radius: 4px;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        flex-shrink: 0;
      }
      .manga-series-nav-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.4rem;
      }
      .manga-series-nav-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        height: 28px;
        padding: 0 0.5rem;
        background: var(--bg-surface-elevated);
        border: 1px solid var(--border-subtle);
        border-radius: 6px;
        color: var(--text-primary);
        font-size: 0.75rem;
        font-weight: 600;
        text-decoration: none;
        cursor: pointer;
        transition: all 0.15s;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
      }
      .manga-series-nav-btn:hover {
        border-color: var(--border-strong);
        background: var(--bg-surface-hover);
      }
      .manga-series-nav-btn.disabled {
        opacity: 0.35;
        cursor: not-allowed;
        pointer-events: none;
      }
      .manga-series-nav-meta {
        display: flex;
        flex-direction: column;
        min-width: 0;
        gap: 0.15rem;
      }
      .manga-series-nav-dir {
        font-size: 0.72rem;
        font-weight: 700;
        color: var(--text-muted);
        text-transform: uppercase;
      }
      .manga-series-nav-title {
        font-size: 0.84rem;
        font-weight: 600;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
      }
      .manga-series-episodes-sheet {
        display: flex;
        flex-direction: column;
        gap: 0.6rem;
        max-height: 380px;
        overflow-y: auto;
        padding-right: 0.3rem;
      }
      .manga-series-episode-item {
        display: flex;
        align-items: center;
        gap: 0.85rem;
        padding: 0.6rem 0.8rem;
        border-radius: 10px;
        background: var(--bg-surface-elevated);
        border: 1px solid var(--border-subtle);
        cursor: pointer;
        transition: all 0.15s;
      }
      .manga-series-episode-item:hover {
        border-color: var(--accent);
        background: var(--bg-surface-hover);
      }
      .manga-series-episode-item.current {
        border-color: #f97316;
        background: rgba(249, 115, 22, 0.1);
      }
      .manga-series-episode-thumb {
        width: 50px;
        height: 50px;
        border-radius: 6px;
        object-fit: cover;
        background: #000;
        flex-shrink: 0;
      }
  
      .art-card-info {
        padding: 0.8rem 0.9rem;
        display: flex;
        flex-direction: column;
        gap: 0.35rem;
      }
      .art-card-title {
        font-size: 0.9rem;
        font-weight: 700;
        color: var(--text-primary);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
      }
      .art-card-author {
        display: flex;
        align-items: center;
        gap: 0.45rem;
        font-size: 0.78rem;
        color: var(--text-secondary);
      }
      .art-card-avatar {
        width: 22px;
        height: 22px;
        border-radius: 50%;
        object-fit: cover;
        background: var(--bg-surface-elevated);
      }
      .art-card-tags-preview {
        display: flex;
        gap: 0.3rem;
        overflow: hidden;
        margin-top: 0.15rem;
      }
      .art-card-tag-badge {
        font-size: 0.68rem;
        color: var(--text-muted);
        background: var(--bg-surface-elevated);
        padding: 0.1rem 0.4rem;
        border-radius: 4px;
        white-space: nowrap;
      }
      .art-card-stats {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-top: 0.2rem;
        font-size: 0.74rem;
        color: var(--text-muted);
      }
      .art-card-actions { display: flex; align-items: center; gap: 0.65rem; }
      .stat-btn {
        display: flex;
        align-items: center;
        gap: 0.25rem;
        cursor: pointer;
        color: var(--text-muted);
        transition: color 0.15s, transform 0.1s;
      }
      .stat-btn:hover { color: var(--text-primary); }
      .stat-btn.active.like { color: var(--like); }
      .stat-btn.active.bookmark { color: var(--bookmark); }
      .stat-btn svg { width: 15px; height: 15px; }
  
      .form-select, select.custom-select {
        position: relative;
        appearance: none;
        -webkit-appearance: none;
        -moz-appearance: none;
        background-color: var(--bg-surface-elevated);
        border: 1px solid var(--border-subtle);
        border-radius: 10px;
        padding: 0.6rem 2.8rem 0.6rem 0.85rem !important;
        color: var(--text-primary);
        cursor: pointer;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='%239ea0b8'%3E%3Cpath d='M7.41 8.59L12 13.17l4.59-4.58L18 10l-6 6-6-6z'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 18px center !important;
        background-size: 16px 16px;
      }
  
      .viewer-layout {
        display: flex;
        gap: 1.5rem;
        align-items: flex-start;
        max-width: 1400px;
        margin: 0 auto;
        width: 100%;
      }
      .viewer-main {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: 1.2rem;
      }
      .viewer-sidebar {
        width: 360px;
        flex-shrink: 0;
        display: flex;
        flex-direction: column;
        gap: 1.2rem;
      }
      @media (max-width: 990px) {
        .viewer-layout {
          display: flex;
          flex-direction: column;
          gap: 1.2rem;
        }
        .viewer-main {
          display: contents;
        }
        .viewer-media-wrap {
          order: 1;
          width: 100%;
          position: relative;
        }
        .viewer-info-card {
          order: 2;
          width: 100%;
        }
        .viewer-sidebar {
          order: 3;
          width: 100%;
        }
        .viewer-comments-card {
          order: 4;
          width: 100%;
        }
      }
  
      .sample-bar {
        background: var(--bg-surface-elevated);
        border: 1px solid var(--border-subtle);
        padding: 0.6rem 1rem;
        border-radius: 10px;
        font-size: 0.82rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        color: var(--text-secondary);
      }

      /* Multi-Page Expanded Stack & Thumb Reel */
      .multi-page-expanded-stack {
        display: flex;
        flex-direction: column;
        gap: 1.2rem;
        width: 100%;
      }
      .multi-page-item {
        width: 100%;
        background: #000;
        border-radius: 14px;
        overflow: hidden;
        border: 1px solid var(--border-subtle);
        box-shadow: var(--shadow-sm);
        display: flex;
        flex-direction: column;
        align-items: center;
      }
      .multi-page-item img, .multi-page-item video {
        width: 100%;
        height: auto;
        display: block;
      }
      .thumb-reel {
        display: flex;
        gap: 0.6rem;
        overflow-x: auto;
        overflow-y: hidden;
        width: 100%;
        max-width: 100%;
        padding: 0.5rem 0.2rem;
        scroll-behavior: smooth;
        -webkit-overflow-scrolling: touch;
      }
      .thumb-reel-item {
        position: relative;
        width: 72px;
        height: 72px;
        border-radius: 8px;
        overflow: hidden;
        cursor: pointer;
        border: 2px solid transparent;
        flex-shrink: 0;
        opacity: 0.7;
        transition: opacity 0.15s ease;
      }
      .thumb-reel-item:hover {
        opacity: 0.95;
      }
      .thumb-reel-item.active {
        opacity: 1;
        border-color: rgba(255, 255, 255, 0.3);
      }
      .thumb-reel-item img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
      }
      .thumb-eye-overlay {
        position: absolute;
        inset: 0;
        background: rgba(0, 0, 0, 0.45);
        display: flex;
        align-items: center;
        justify-content: center;
        opacity: 0;
        pointer-events: none;
        transition: opacity 0.15s ease;
      }
      .thumb-eye-overlay svg {
        width: 22px;
        height: 22px;
        color: #ffffff;
        filter: drop-shadow(0 1px 3px rgba(0, 0, 0, 0.8));
      }
      .thumb-reel-item.active .thumb-eye-overlay {
        opacity: 1;
      }
  
      .author-card {
        background: var(--bg-surface);
        border: 1px solid var(--border-subtle);
        border-radius: 14px;
        padding: 1.2rem;
        display: flex;
        flex-direction: column;
        gap: 0.9rem;
      }
      .author-header {
        display: flex;
        align-items: center;
        gap: 0.75rem;
      }
      .author-avatar-lg {
        width: 54px;
        height: 54px;
        border-radius: 50%;
        object-fit: cover;
        border: 2px solid var(--accent);
        background: var(--bg-surface-elevated);
      }
      .author-names { display: flex; flex-direction: column; gap: 0.15rem; min-width: 0; }
      .author-artist-name { font-weight: 700; font-size: 1rem; }
      .author-handle { font-size: 0.78rem; color: var(--text-muted); }
  
      .tag-cloud { display: flex; flex-wrap: wrap; gap: 0.45rem; }
      .tag-pill {
        background: var(--bg-surface-elevated);
        border: 1px solid var(--border-subtle);
        padding: 0.32rem 0.75rem;
        border-radius: 10px;
        font-size: 0.78rem;
        font-weight: 500;
        color: var(--text-secondary);
        transition: all 0.15s;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        max-width: min(100%, 280px);
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        min-width: 0;
      }
      .tag-pill span {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        min-width: 0;
      }
      .tag-pill svg { width: 13px; height: 13px; flex-shrink: 0; }
      .tag-pill:hover { background: var(--accent-alpha); color: var(--accent); border-color: var(--accent); }
      .tag-pill.special-parody { color: #38bdf8; border-color: rgba(56, 189, 248, 0.25); }
      .tag-pill.special-character { color: #a855f7; border-color: rgba(168, 85, 247, 0.25); }
      .tag-pill.special-tool { color: #10b981; border-color: rgba(16, 185, 129, 0.25); }
  
      .mode-card-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.75rem;
      }
      @media (max-width: 768px) {
        .mode-card-grid { grid-template-columns: 1fr; }
      }
      .mode-card {
        border: 1.5px solid var(--border-subtle);
        background: var(--bg-surface-elevated);
        border-radius: 12px;
        padding: 0.9rem;
        cursor: pointer;
        transition: all 0.2s ease;
        display: flex;
        align-items: flex-start;
        gap: 0.75rem;
      }
      .mode-card:hover { border-color: var(--border-strong); }
      .mode-card.selected {
        border-color: var(--accent);
        background: var(--accent-alpha);
      }
      .mode-card input[type="radio"] {
        margin-top: 3px;
        accent-color: var(--accent);
        width: 16px;
        height: 16px;
      }
      .mode-card-info { display: flex; flex-direction: column; }
      .mode-card-title { font-weight: 700; font-size: 0.88rem; display: flex; align-items: center; gap: 0.4rem; color: var(--text-primary); }
      .mode-card-desc { font-size: 0.75rem; color: var(--text-muted); margin-top: 0.25rem; line-height: 1.35; }
  
      .modal-backdrop {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.78);
        backdrop-filter: blur(8px);
        z-index: 2000;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 1rem;
        height: 100dvh;
        overflow-y: auto;
      }
      .modal-backdrop.active { display: flex; }
      .modal-dialog {
        background: var(--bg-surface);
        border: 1px solid var(--border-strong);
        border-radius: 18px;
        width: 100%;
        max-width: 680px;
        max-height: calc(100dvh - 2rem);
        display: flex;
        flex-direction: column;
        box-shadow: var(--shadow-lg);
        overflow: hidden;
        animation: modalSlide 0.2s cubic-bezier(0.2, 0, 0, 1);
      }
      .modal-dialog > form {
        display: flex;
        flex-direction: column;
        flex: 1;
        min-height: 0;
        overflow: hidden;
      }
      .modal-dialog.large { max-width: 1040px; height: 90dvh; }
      @keyframes modalSlide {
        from { transform: translateY(16px) scale(0.98); opacity: 0; }
        to { transform: translateY(0) scale(1); opacity: 1; }
      }
      .modal-header {
        padding: 1rem 1.3rem;
        border-bottom: 1px solid var(--border-subtle);
        display: flex;
        align-items: center;
        justify-content: space-between;
        font-weight: 700;
        font-size: 1.1rem;
        flex-shrink: 0;
      }
      .modal-body {
        padding: 1.3rem;
        overflow-y: auto;
        -webkit-overflow-scrolling: touch;
        display: flex;
        flex-direction: column;
        gap: 1rem;
        flex: 1;
      }
      .modal-footer {
        padding: 0.9rem 1.3rem;
        border-top: 1px solid var(--border-subtle);
        display: flex;
        justify-content: flex-end;
        gap: 0.6rem;
        background: var(--bg-surface-elevated);
        flex-shrink: 0;
      }
  
      .form-group { display: flex; flex-direction: column; gap: 0.4rem; }
      .form-label { font-size: 0.8rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.5px; }
      .form-input, .form-textarea {
        background: var(--bg-surface-elevated);
        border: 1px solid var(--border-subtle);
        border-radius: 10px;
        padding: 0.65rem 0.9rem;
        font-size: 0.9rem;
        color: var(--text-primary);
        width: 100%;
        transition: border-color 0.15s, background 0.15s;
      }
      .form-input:focus, .form-textarea:focus, .form-select:focus {
        border-color: var(--accent);
        background: var(--bg-surface);
      }
      .form-textarea { resize: vertical; min-height: 90px; }
  
      .upload-zone {
        border: 2px dashed var(--border-strong);
        border-radius: 14px;
        padding: 2rem 1rem;
        text-align: center;
        background: var(--bg-surface-elevated);
        cursor: pointer;
        transition: all 0.15s;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 0.5rem;
      }
      .upload-zone:hover, .upload-zone.dragover {
        border-color: var(--accent);
        background: var(--accent-alpha);
      }
      .upload-preview-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
        gap: 0.75rem;
        margin-top: 0.5rem;
      }
      .upload-preview-item {
        position: relative;
        aspect-ratio: 1 / 1;
        border-radius: 10px;
        overflow: hidden;
        background: var(--bg-base);
        border: 1px solid var(--border-subtle);
      }
      .upload-preview-item img {
        width: 100%;
        height: 100%;
        object-fit: cover;
      }
      .upload-item-del {
        position: absolute;
        top: 4px;
        right: 4px;
        width: 24px;
        height: 24px;
        border-radius: 50%;
        background: rgba(0, 0, 0, 0.75);
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        z-index: 3;
      }
      .upload-item-order {
        position: absolute;
        top: 4px;
        left: 4px;
        background: rgba(0, 0, 0, 0.75);
        color: #fff;
        font-size: 0.7rem;
        font-weight: 700;
        padding: 0.15rem 0.45rem;
        border-radius: 4px;
        z-index: 3;
      }
      .upload-item-name {
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        background: rgba(0, 0, 0, 0.82);
        color: #fff;
        font-size: 0.68rem;
        font-weight: 500;
        padding: 0.22rem 0.4rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        z-index: 2;
        pointer-events: none;
      }
  
      .comment-tree-node {
        display: flex;
        gap: 0.8rem;
        border-top: 1px solid var(--border-subtle);
        padding-top: 0.85rem;
      }
      .comment-replies-list {
        margin-left: 2rem;
        margin-top: 0.5rem;
        display: flex;
        flex-direction: column;
        gap: 0.6rem;
        border-left: 2px solid var(--border-subtle);
        padding-left: 0.9rem;
      }
  
      .admin-tab-nav {
        display: flex;
        gap: 0.4rem;
        border-bottom: 1px solid var(--border-subtle);
        padding-bottom: 0.6rem;
        margin-bottom: 1.2rem;
        overflow-x: auto;
      }
      .admin-tab-btn {
        padding: 0.5rem 1rem;
        border-radius: 10px;
        font-weight: 600;
        font-size: 0.85rem;
        color: var(--text-secondary);
        background: var(--bg-surface-elevated);
        cursor: pointer;
      }
      .admin-tab-btn.active {
        background: var(--accent);
        color: #fff;
      }
      .stat-card-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
        gap: 1rem;
        margin-bottom: 1.4rem;
      }
      .stat-card {
        background: var(--bg-surface-elevated);
        border: 1px solid var(--border-subtle);
        border-radius: 12px;
        padding: 1rem;
        display: flex;
        flex-direction: column;
        gap: 0.3rem;
      }
      .stat-card-num {
        font-size: 1.6rem;
        font-weight: 800;
        color: var(--accent);
      }
      .stat-card-lbl {
        font-size: 0.75rem;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: 0.5px;
        font-weight: 700;
      }
  
      .data-table-wrap {
        width: 100%;
        overflow-x: auto;
        border: 1px solid var(--border-subtle);
        border-radius: 12px;
        background: var(--bg-surface);
      }
      .data-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.85rem;
        text-align: left;
      }
      .data-table th {
        background: var(--bg-surface-elevated);
        padding: 0.75rem 0.9rem;
        font-weight: 700;
        color: var(--text-secondary);
        border-bottom: 1px solid var(--border-subtle);
      }
      .data-table td {
        padding: 0.75rem 0.9rem;
        border-bottom: 1px solid var(--border-subtle);
        color: var(--text-primary);
      }
  
      .toast-box {
        position: fixed;
        bottom: calc(1.5rem + env(safe-area-inset-bottom, 0));
        left: 50%;
        transform: translateX(-50%);
        background: var(--bg-surface-elevated);
        color: var(--text-primary);
        border: 1px solid var(--border-strong);
        padding: 0.65rem 1.25rem;
        border-radius: 20px;
        font-size: 0.85rem;
        font-weight: 500;
        box-shadow: var(--shadow-md);
        z-index: 9999;
        pointer-events: none;
        animation: toastIn 0.2s cubic-bezier(0.2, 0, 0, 1);
      }
      @keyframes toastIn {
        from { transform: translate(-50%, 15px); opacity: 0; }
        to { transform: translate(-50%, 0); opacity: 1; }
      }
  
      .spinner {
        width: 36px;
        height: 36px;
        border: 3px solid var(--border-subtle);
        border-top-color: var(--accent);
        border-radius: 50%;
        animation: spin 0.8s linear infinite;
        margin: 3rem auto;
      }
      @keyframes spin { 100% { transform: rotate(360deg); } }
  
      .center-msg {
        text-align: center;
        padding: 4rem 1rem;
        color: var(--text-muted);
        font-size: 0.95rem;
      }
    </style>
  </head>
  <body>
    <header class="app-header">
      <div class="header-left">
        <button class="btn-icon" id="btn-toggle-menu" title="Toggle Navigation">
          <svg viewBox="0 0 24 24"><path d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z"/></svg>
        </button>
        <a href="#/" class="brand-logo">
          <svg viewBox="0 0 24 24"><path d="M13.73 15l-3.9 6.76c.7.15 1.42.24 2.17.24 2.4 0 4.6-.85 6.32-2.25l-3.66-6.35m-12.2 1.6c.92 2.92 3.15 5.26 5.99 6.34l3.67-6.34m-3.58-3l-3.9-6.75C2.99 7 2 9.39 2 12c0 .68.07 1.35.2 2h7.49m12.11-4h-7.49l.29.5 4.76 8.25c1.64-1.78 2.64-4.15 2.64-6.75 0-.69-.07-1.36-.2-2m-.26-1c-.92-2.93-3.15-5.26-5.99-6.34l-3.67 6.34m-2.48 1.5l4.77-8.26C13.47 2.09 12.75 2 12 2c-2.4 0-4.6.84-6.32 2.25l3.66 6.35.06-.1z"/></svg>
          <span><?= htmlspecialchars($config['app_name']) ?></span>
        </a>
      </div>
  
      <div class="header-center">
        <div class="search-bar">
          <svg viewBox="0 0 24 24"><path d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
          <input type="text" id="global-search" placeholder="Search...">
        </div>
      </div>
  
      <div class="header-right">
        <div id="user-nav-slot"></div>
      </div>
    </header>
  
    <div class="app-body">
      <div class="sidebar-backdrop" id="sidebar-backdrop" onclick="app.closeOffcanvas()"></div>
  
      <aside class="nav-sidebar" id="app-sidebar">
        <div class="sidebar-mobile-header">
          <span style="font-weight:800; font-size:1.05rem; color:var(--accent);">HDPost</span>
          <button class="btn-icon" onclick="app.closeOffcanvas()"><svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg></button>
        </div>

        <div style="padding:0 0.4rem 0.6rem 0.4rem;">
          <button class="btn-primary" style="width:100%; height:38px; border-radius:12px; font-weight:700; gap:0.5rem;" onclick="app.nav('#/submit'); app.closeOffcanvas();">
            <svg viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
            <span>Submit Work</span>
          </button>
        </div>
  
        <div class="nav-item active" data-nav="/"><svg viewBox="0 0 24 24"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg> Home Feed</div>
        <div class="nav-item" data-nav="/rankings"><svg viewBox="0 0 24 24"><path d="M16 6l2.29 2.29-4.88 4.88-4-4L2 16.59 3.41 18l6-6 4 4 6.3-6.29L22 12V6z"/></svg> Rankings</div>
        <div class="nav-item" data-nav="/r18"><svg viewBox="0 0 24 24" style="color:var(--r18);"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg> R-18 Mature</div>
        <div class="nav-item" data-nav="/similar"><svg viewBox="0 0 24 24"><path d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z"/></svg> Similar Search</div>
  
        <div class="nav-divider"></div>
        <div class="nav-heading">Directories</div>
        <div class="nav-item" data-nav="/tags"><svg viewBox="0 0 24 24"><path d="M21.41 11.58l-9-9C12.05 2.22 11.55 2 11 2H4c-1.1 0-2 .9-2 2v7c0 .55.22 1.05.59 1.42l9 9c.36.36.86.58 1.41.58.55 0 1.05-.22 1.41-.59l7-7c.37-.36.59-.86.59-1.41 0-.55-.23-1.06-.59-1.42zM5.5 7C4.67 7 4 6.33 4 5.5S4.67 4 5.5 4 7 4.67 7 5.5 6.33 7 5.5 7z"/></svg> Tags Directory</div>
        <div class="nav-item" data-nav="/characters"><svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 3c1.66 0 3 1.34 3 3s-1.34 3-3 3-3-1.34-3-3 1.34-3 3-3zm0 14.2c-2.5 0-4.71-1.28-6-3.22.03-1.99 4-3.08 6-3.08 1.99 0 5.97 1.09 6 3.08-1.29 1.94-3.5 3.22-6 3.22z"/></svg> Characters Directory</div>
        <div class="nav-item" data-nav="/series"><svg viewBox="0 0 24 24"><path d="M4 6H2v14c0 1.1.9 2 2 2h14v-2H4V6zm16-4H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H8V4h12v12z"/></svg> Series Directory</div>
        <div class="nav-item" data-nav="/artists"><svg viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg> Artists Directory</div>
  
        <div id="sidebar-studio-slot"></div>
        <div class="nav-item" id="btn-theme-toggle" style="cursor:pointer;"><svg viewBox="0 0 24 24"><path d="M12 3c-4.97 0-9 4.03-9 9s4.03 9 9 9 9-4.03 9-9c0-.46-.04-.92-.1-1.36-.98 1.37-2.58 2.26-4.4 2.26-2.98 0-5.4-2.42-5.4-5.4 0-1.81.89-3.42 2.26-4.4-.44-.06-.9-.1-1.36-.1z"/></svg> <span id="theme-toggle-label">Theme: Dark</span></div>
  
        <div id="admin-nav-slot"></div>
  
        </aside>
  
      <main class="main-viewport" id="viewport">
        <div class="page-container" id="page-container"></div>
      </main>
    </div>
  
    <div class="modal-backdrop" id="modal-backdrop">
      <div class="modal-dialog" id="modal-dialog"></div>
    </div>
  
    <div id="toast-slot"></div>
  
    <script>
       class HDPostClient {
        constructor() {
          this.user = <?= json_encode($currentUser) ?>;
          this.appName = <?= json_encode($config['app_name']) ?>;
          this.needsSetup = <?= $isInitialSetup ? 'true' : 'false' ?>;
          this.csrfToken = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>;
          this.theme = localStorage.getItem('hd_theme') || 'dark';
          this.chunkSize = <?= (int)$config['max_chunk_size'] ?>;
          this.uploadQueue = [];
          this.currentLeadIndex = 0;
          this.adminState = { tab: 'users', page: 1, q: '', sort: 'id_asc' };
          this.initTheme();
          this.bindEvents();
          this.renderUserSlot();

          window.addEventListener('hashchange', () => this.handleRoute());
          this.handleRoute();
        }
  
        setTitle(pageTitle) {
          document.title = pageTitle ? `${pageTitle} \u2013 ${this.appName}` : `${this.appName} \u2013 Creative Studio`;
        }

        initTheme() {
          document.documentElement.setAttribute('data-theme', this.theme);
          const updateLabel = () => {
            const lbl = document.getElementById('theme-toggle-label');
            if (lbl) lbl.textContent = `Theme: ${this.theme === 'dark' ? 'Dark' : 'Light'}`;
          };
          updateLabel();
          const btn = document.getElementById('btn-theme-toggle');
          if (btn) {
            btn.onclick = () => {
              this.theme = this.theme === 'dark' ? 'light' : 'dark';
              localStorage.setItem('hd_theme', this.theme);
              document.documentElement.setAttribute('data-theme', this.theme);
              updateLabel();
            };
          }
        }
  
        toggleSidebar() {
          const sidebar = document.getElementById('app-sidebar');
          const backdrop = document.getElementById('sidebar-backdrop');
          const isOpen = sidebar.classList.toggle('open');
          backdrop.classList.toggle('active', isOpen);
        }
  
        closeOffcanvas() {
          document.getElementById('app-sidebar').classList.remove('open');
          document.getElementById('sidebar-backdrop').classList.remove('active');
        }
  
        bindEvents() {
          const btnMenu = document.getElementById('btn-toggle-menu');
          if (btnMenu) btnMenu.onclick = () => this.toggleSidebar();
  
          document.querySelectorAll('.nav-item[data-nav]').forEach(el => {
            el.onclick = () => {
              this.nav('#' + el.dataset.nav);
              this.closeOffcanvas();
            };
          });
  
          const searchInput = document.getElementById('global-search');
          searchInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
              const q = searchInput.value.trim();
              if (q) this.nav(`#/?q=${encodeURIComponent(q)}`);
            }
          });

          window.addEventListener('keydown', (e) => {
            if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
            const tag = (e.target.tagName || '').toUpperCase();
            if (tag === 'INPUT' || tag === 'TEXTAREA' || e.target.isContentEditable) return;
            const modal = document.getElementById('modal-backdrop');
            if (modal && modal.classList.contains('active')) return;

            const hash = window.location.hash || '';
            if (!hash.startsWith('#/artwork/')) return;

            if (e.key === 'ArrowLeft') {
              // Arrow Left -> Next Post / Episode
              e.preventDefault();
              if (this.currentNextPostId) {
                this.navigateToArtwork(this.currentNextPostId);
              } else {
                this.toast('No next post.');
              }
            } else if (e.key === 'ArrowRight') {
              // Arrow Right -> Previous Post / Episode
              e.preventDefault();
              if (this.currentPrevPostId) {
                this.navigateToArtwork(this.currentPrevPostId);
              } else {
                this.toast('No previous post.');
              }
            }
          });
        }

        getAvatar(avatarUrl, artistName = 'Artist', emailHash = '') {
          if (avatarUrl && avatarUrl.trim() !== '') {
            return avatarUrl;
          }
          const initial = (artistName || 'A').trim().charAt(0).toUpperCase();
          const colors = ['#0096fa', '#ff4772', '#10b981', '#f59e0b', '#8b5cf6', '#06b6d4', '#ec4899'];
          let sum = 0;
          for (let i = 0; i < (artistName || '').length; i++) sum += artistName.charCodeAt(i);
          const color = colors[sum % colors.length];
          const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect width="100" height="100" fill="${color}"/><text x="50" y="55" font-family="Inter,-apple-system,sans-serif" font-size="44" font-weight="700" fill="#ffffff" text-anchor="middle" dominant-baseline="middle">${initial}</text></svg>`;
          return `data:image/svg+xml;utf8,${encodeURIComponent(svg)}`;
        }

        handleAvatarError(img, name) {
          img.onerror = null;
          const fallbackName = name || img.getAttribute('data-artist-name') || 'Artist';
          img.src = this.getAvatar('', fallbackName);
        }
  
        renderUserSlot() {
          const slot = document.getElementById('user-nav-slot');
          const adminSlot = document.getElementById('admin-nav-slot');
          const studioSlot = document.getElementById('sidebar-studio-slot');

          if (this.user) {
            const avatarUrl = this.getAvatar(this.user.avatar, this.user.artist_name, this.user.email_hash);
            const isAdmin = Number(this.user.is_admin) >= 1;
            slot.innerHTML = `
              <div style="cursor:pointer; display:flex; align-items:center; justify-content:center; line-height:0;" onclick="app.nav('#/user/${this.user.id}')" title="${this.escape(this.user.artist_name)}">
                <img src="${avatarUrl}" alt="" data-artist-name="${this.escape(this.user.artist_name)}" onerror="app.handleAvatarError(this)" style="width:34px; height:34px; border-radius:50%; object-fit:cover; border:2px solid var(--accent); background:var(--bg-surface-elevated); display:block;">
              </div>
            `;

            if (studioSlot) {
              studioSlot.innerHTML = `
                <div class="nav-divider"></div>
                <div class="nav-heading">My Studio</div>
                <div class="nav-item" data-nav="/favorites" onclick="app.nav('#/favorites'); app.closeOffcanvas();">
                  <svg viewBox="0 0 24 24"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>
                  <span>Favorites</span>
                </div>
                <div class="nav-item" data-nav="/following" onclick="app.nav('#/following'); app.closeOffcanvas();">
                  <svg viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
                  <span>Following Artists</span>
                </div>
                <div class="nav-item" data-nav="/settings" onclick="app.nav('#/settings'); app.closeOffcanvas();">
                  <svg viewBox="0 0 24 24"><path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.09.63-.09.94s.02.64.07.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/></svg>
                  <span>Studio Settings</span>
                </div>
                <div class="nav-item" data-nav="/activity" onclick="app.nav('#/activity'); app.closeOffcanvas();">
                  <svg viewBox="0 0 24 24"><path d="M13 3a9 9 0 0 0-9 9H1l3.89 3.89.07.14L9 12H6c0-3.87 3.13-7 7-7s7 3.13 7 7-3.13 7-7 7c-1.93 0-3.68-.79-4.94-2.06l-1.42 1.42A8.954 8.954 0 0 0 13 21a9 9 0 0 0 0-18zm-1 5v5l4.28 2.54.72-1.21-3.5-2.08V8H12z"/></svg>
                  <span>Activity History</span>
                </div>
              `;
            }

            if (isAdmin) {
              adminSlot.innerHTML = `
                <div class="nav-divider"></div>
                <div class="nav-heading" style="color:var(--r18);">Administration</div>
                <div class="nav-item" data-nav="/admin"><svg viewBox="0 0 24 24" style="color:var(--r18);"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 10.99h7c-.53 4.12-3.28 7.79-7 8.94V12H5V6.3l7-3.11v8.8z"/></svg> Admin Control Panel</div>
              `;
              const adminBtn = adminSlot.querySelector('.nav-item[data-nav]');
              if (adminBtn) adminBtn.onclick = () => { this.nav('#/admin'); this.closeOffcanvas(); };
            } else {
              adminSlot.innerHTML = '';
            }
          } else {
            slot.innerHTML = '';
            adminSlot.innerHTML = '';

            if (studioSlot) {
              studioSlot.innerHTML = `
                <div class="nav-divider"></div>
                <div class="nav-heading">My Studio</div>
                <div class="nav-item" onclick="app.showAuthModal('login'); app.closeOffcanvas();">
                  <svg viewBox="0 0 24 24"><path d="M10 17l5-5-5-5v3H3v4h7v3zm9-14H5c-1.1 0-2 .9-2 2v4h2V5h14v14H5v-4H3v4c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/></svg>
                  <span>Log In</span>
                </div>
                <div class="nav-item" style="color:var(--accent);" onclick="app.showAuthModal('register'); app.closeOffcanvas();">
                  <svg viewBox="0 0 24 24"><path d="M15 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm-9-2V7H4v3H1v2h3v3h2v-3h3v-2H6zm9 4c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
                  <span>Sign Up</span>
                </div>
              `;
            }
          }
        }
  
        nav(hash) {
          window.location.hash = hash;
        }
  
        async handleRoute() {
          this.closeOffcanvas();
          const rawHash = window.location.hash || '#/';
          const [routePath, queryStr] = rawHash.replace(/^#/, '').split('?');
          const params = new URLSearchParams(queryStr || '');

          if (this.needsSetup) {
            await this.renderSetupPage();
            return;
          }
  
          document.querySelectorAll('.nav-item').forEach(el => {
            el.classList.toggle('active', el.dataset.nav === routePath);
          });
  
          const container = document.getElementById('page-container');
          container.classList.add('transitioning');
          await new Promise(r => setTimeout(r, 120));
  
          if (routePath === '/' || routePath === '') {
            await this.renderFeedPage('home', params);
          } else if (routePath === '/explore') {
            await this.renderFeedPage('explore', params);
          } else if (routePath === '/rankings') {
            await this.renderFeedPage('rankings', params);
          } else if (routePath === '/r18') {
            await this.renderFeedPage('r18', params);
          } else if (routePath === '/favorites') {
            await this.renderFeedPage('favorites', params);
          } else if (routePath === '/following') {
            await this.renderFeedPage('following', params);
          } else if (routePath === '/settings') {
            await this.renderSettingsPage();
          } else if (routePath === '/similar') {
            await this.renderSimilarPage(params);
          } else if (routePath === '/tags') {
            await this.renderTagsDirectory();
          } else if (routePath === '/characters') {
            await this.renderCharactersDirectory();
          } else if (routePath === '/series') {
            await this.renderSeriesDirectory();
          } else if (routePath === '/artists') {
            await this.renderArtistsDirectory();
          } else if (routePath === '/activity') {
            await this.renderActivityPage();
          } else if (routePath === '/admin') {
            await this.renderAdminPanel();
          } else if (routePath.startsWith('/artwork/')) {
            const id = routePath.split('/')[2];
            await this.renderArtworkView(id);
          } else if (routePath.startsWith('/user/')) {
            const id = routePath.split('/')[2];
            await this.renderUserProfile(id);
          } else if (routePath === '/submit') {
            await this.renderStudio();
          } else if (routePath.startsWith('/edit/')) {
            const id = routePath.split('/')[2];
            await this.renderStudio(id);
          } else if (routePath === '/me') {
            if (!this.user) {
              this.showAuthModal('login');
              this.nav('#/');
            } else {
              this.nav(`#/user/${this.user.id}`);
            }
          }
  
          container.classList.remove('transitioning');
          document.getElementById('viewport').scrollTop = 0;
        }
  
        async api(action, data = {}, method = 'GET') {
          const options = { method, headers: {} };
          let url = `?action=${action}`;

          if (method === 'POST') {
            options.headers['X-CSRF-Token'] = this.csrfToken;
            if (data instanceof FormData) {
              if (!data.has('csrf_token')) data.append('csrf_token', this.csrfToken);
              options.body = data;
            } else {
              const fd = new FormData();
              for (let k in data) fd.append(k, data[k]);
              fd.append('csrf_token', this.csrfToken);
              options.body = fd;
            }
          } else {
            const p = new URLSearchParams(data).toString();
            if (p) url += `&${p}`;
          }

          const res = await fetch(url, options);
          const json = await res.json();
          if (!res.ok || json.error) throw new Error(json.error || 'Request failed');
          return json;
        }
  
        toast(msg) {
          const slot = document.getElementById('toast-slot');
          const el = document.createElement('div');
          el.className = 'toast-box';
          el.innerText = msg;
          slot.appendChild(el);
          setTimeout(() => el.remove(), 2800);
        }
  
        showModal(html, isLarge = false) {
          const backdrop = document.getElementById('modal-backdrop');
          const dialog = document.getElementById('modal-dialog');
          dialog.className = isLarge ? 'modal-dialog large' : 'modal-dialog';
          dialog.innerHTML = html;
          backdrop.classList.add('active');
          backdrop.onclick = (e) => { if (e.target === backdrop) this.closeModal(); };
        }
  
        closeModal() {
          document.getElementById('modal-backdrop').classList.remove('active');
        }
  
        extractVideoThumbnail(file) {
          return new Promise((resolve) => {
            const video = document.createElement('video');
            video.preload = 'metadata';
            video.muted = true;
            video.playsInline = true;
            const url = URL.createObjectURL(file);
            video.src = url;
            video.onloadeddata = () => {
              video.currentTime = Math.min(1.0, (video.duration || 1) / 2);
            };
            video.onseeked = () => {
              const canvas = document.createElement('canvas');
              canvas.width = 480;
              canvas.height = Math.round(480 / ((video.videoWidth || 480) / (video.videoHeight || 320)));
              const ctx = canvas.getContext('2d');
              ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
              URL.revokeObjectURL(url);
              resolve(canvas.toDataURL('image/jpeg', 0.85));
            };
            video.onerror = () => {
              URL.revokeObjectURL(url);
              resolve('');
            };
          });
        }
  
        async loadSidebarTags() {
          try {
            const res = await this.api('popular_tags');
            const box = document.getElementById('sidebar-popular-tags');
            box.innerHTML = res.tags.map(t => `
              <div class="nav-item" style="padding:0.4rem 0.9rem; font-size:0.82rem;" onclick="app.nav('#/explore?tag=' + encodeURIComponent('${this.escape(t.tag_name)}'))">
                <span style="display:flex; align-items:center; gap:0.35rem;">
                  <svg viewBox="0 0 24 24" style="width:14px; height:14px;"><path d="M21.41 11.58l-9-9C12.05 2.22 11.55 2 11 2H4c-1.1 0-2 .9-2 2v7c0 .55.22 1.05.59 1.42l9 9c.36.36.86.58 1.41.58.55 0 1.05-.22 1.41-.59l7-7c.37-.36.59-.86.59-1.41 0-.55-.23-1.06-.59-1.42zM5.5 7C4.67 7 4 6.33 4 5.5S4.67 4 5.5 4 7 4.67 7 5.5 6.33 7 5.5 7z"/></svg>
                  ${this.escape(t.tag_name)}
                </span>
                <span style="margin-left:auto; font-size:0.75rem; color:var(--text-muted);">${t.tag_count}</span>
              </div>
            `).join('');
          } catch(e) {}
        }
  
        async renderSetupPage() {
          this.setTitle('System Setup');
          const container = document.getElementById('page-container');
          container.innerHTML = `
            <div style="max-width:520px; margin:2.5rem auto; background:var(--bg-surface); border:1px solid var(--border-strong); border-radius:18px; padding:2rem; box-shadow:var(--shadow-lg);">
              <div style="text-align:center; margin-bottom:1.5rem;">
                <div class="brand-logo" style="justify-content:center; margin-bottom:0.6rem; font-size:1.6rem;">
                  <svg viewBox="0 0 24 24" style="width:36px;height:36px;"><path d="M13.73 15l-3.9 6.76c.7.15 1.42.24 2.17.24 2.4 0 4.6-.85 6.32-2.25l-3.66-6.35m-12.2 1.6c.92 2.92 3.15 5.26 5.99 6.34l3.67-6.34m-3.58-3l-3.9-6.75C2.99 7 2 9.39 2 12c0 .68.07 1.35.2 2h7.49m12.11-4h-7.49l.29.5 4.76 8.25c1.64-1.78 2.64-4.15 2.64-6.75 0-.69-.07-1.36-.2-2m-.26-1c-.92-2.93-3.15-5.26-5.99-6.34l-3.67 6.34m-2.48 1.5l4.77-8.26C13.47 2.09 12.75 2 12 2c-2.4 0-4.6.84-6.32 2.25l3.66 6.35.06-.1z"/></svg>
                  <span>HDPost Setup</span>
                </div>
                <h1 style="font-size:1.25rem; font-weight:800;">Welcome to Your Studio</h1>
                <p style="font-size:0.82rem; color:var(--text-muted); margin-top:0.3rem;">No existing database detected. Set up the primary Super Administrator account to initialize HDPost.</p>
              </div>

              <form onsubmit="app.handleSetupSubmit(event)" style="display:flex; flex-direction:column; gap:0.95rem;">
                <div class="form-group">
                  <label class="form-label">Display / Artist Name</label>
                  <input type="text" name="artist_name" class="form-input" placeholder="e.g. Master Admin" required>
                </div>
                <div class="form-group">
                  <label class="form-label">Super Admin Email</label>
                  <input type="email" name="email" class="form-input" placeholder="admin@hdpost.local" required>
                </div>
                <div class="form-group">
                  <label class="form-label">Master Password (Min. 6 characters)</label>
                  <input type="password" name="password" class="form-input" placeholder="••••••••" minlength="6" required>
                </div>
                <div style="margin-top:0.5rem;">
                  <button type="submit" class="btn-primary" style="width:100%; height:40px; border-radius:12px; font-weight:700;">Initialize Super Admin &amp; Studio</button>
                </div>
              </form>
            </div>
          `;
        }

        async handleSetupSubmit(e) {
          e.preventDefault();
          const fd = new FormData(e.target);
          try {
            const res = await this.api('system_setup', fd, 'POST');
            this.user = res.user;
            this.needsSetup = false;
            this.toast('Super Admin configured! Launching studio...');
            setTimeout(() => location.reload(), 600);
          } catch(err) {
            this.toast(err.message);
          }
        }
  
        showAuthModal(mode = 'login') {
          const isLogin = mode === 'login';
          const html = `
            <div class="modal-header">
              <span>${isLogin ? 'Artist Log In' : 'Create Artist Studio Account'}</span>
              <button class="btn-icon" onclick="app.closeModal()"><svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg></button>
            </div>
            <form onsubmit="app.handleAuthSubmit(event, '${mode}')">
              <div class="modal-body">
                ${!isLogin ? `
                  <div class="form-group">
                    <label class="form-label">Artist / Display Name</label>
                    <input type="text" name="artist_name" class="form-input" placeholder="Akari Tachibana" required>
                  </div>
                ` : ''}
                <div class="form-group">
                  <label class="form-label">Email Address</label>
                  <input type="email" name="email" class="form-input" placeholder="artist@hdpost.local" required>
                </div>
                <div class="form-group">
                  <label class="form-label">Password</label>
                  <input type="password" name="password" class="form-input" placeholder="••••••••" required>
                </div>
                <div style="font-size:0.8rem; color:var(--text-muted); text-align:center;">
                  ${isLogin ? `Don't have an artist account? <a href="javascript:;" style="color:var(--accent);" onclick="app.showAuthModal('register')">Sign Up</a>` : `Already registered? <a href="javascript:;" style="color:var(--accent);" onclick="app.showAuthModal('login')">Log In</a>`}
                </div>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn-subtle" onclick="app.closeModal()">Cancel</button>
                <button type="submit" class="btn-primary">${isLogin ? 'Log In' : 'Sign Up'}</button>
              </div>
            </form>
          `;
          this.showModal(html);
        }
  
        async handleAuthSubmit(e, mode) {
          e.preventDefault();
          const fd = new FormData(e.target);
          const action = mode === 'login' ? 'auth_login' : 'auth_register';
  
          try {
            const res = await this.api(action, fd, 'POST');
            this.user = res.user;
            this.renderUserSlot();
            this.closeModal();
            this.toast(mode === 'login' ? `Welcome back, ${this.user.artist_name}!` : 'Artist account created!');
            location.reload();
          } catch(err) {
            this.toast(err.message);
          }
        }
  
        async renderFeedPage(feedType, params) {
          const container = document.getElementById('page-container');
          container.innerHTML = '<div class="spinner"></div>';

          const query = params.get('q') || '';
          const tag = params.get('tag') || '';
          const character = params.get('character') || '';
          const parody = params.get('parody') || '';
          const sourceUrl = params.get('source_url') || '';
          const rating = feedType === 'r18' ? 'r18' : (params.get('rating') || 'all');
          const type = params.get('type') || 'all';
          const sort = feedType === 'rankings' ? 'popular' : (params.get('sort') || 'newest');
          const period = params.get('period') || 'daily';
          const page = Math.max(1, parseInt(params.get('page') || '1', 10));

          try {
            const reqData = {
              feed: feedType,
              rating: rating,
              type: type,
              sort: sort,
              period: period,
              page: page,
              limit: 24
            };
            if (query) reqData.q = query;
            if (tag) reqData.tag = tag;
            if (character) reqData.character = character;
            if (parody) reqData.parody = parody;
            if (sourceUrl) reqData.source_url = sourceUrl;

            const res = await this.api('artworks_list', reqData);

            let heading = 'Discover Artworks & Illustrations';
            if (feedType === 'rankings') heading = 'Hall of Fame & Top Rankings';
            if (feedType === 'r18') heading = 'R-18 Mature Creations';
            if (feedType === 'following') heading = 'Followed Artists Feed';
            if (feedType === 'favorites') heading = 'My Favorite Creations';
            if (query) heading = `Search: "${query}"`;
            if (tag) heading = `Tag: ${tag}`;
            if (character) heading = `Character: ${character}`;
            if (parody) heading = `Series: ${parody}`;
            this.setTitle(heading);

            let html = `
              <div class="feed-header-wrap" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.4rem; flex-wrap:wrap; gap:0.8rem;">
                <div>
                  <h1 style="font-size:1.4rem; font-weight:800; letter-spacing:-0.5px;">${heading}</h1>
                  <p style="font-size:0.82rem; color:var(--text-muted); margin-top:0.2rem;">${res.total} works available</p>
                </div>
                <div class="feed-header-controls" style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
                  <button class="btn-subtle" onclick="app.toggleAdvSearch()" style="gap:0.4rem;">
                    <svg viewBox="0 0 24 24" style="width:15px;height:15px;"><path d="M10 18h4v-2h-4v2zM3 6v2h18V6H3zm3 7h12v-2H6v2z"/></svg>
                    <span>Advanced Search</span>
                  </button>
                  ${feedType === 'rankings' ? `
                    <select class="form-select" style="font-size:0.8rem; height:36px;" onchange="app.updateParam('period', this.value)">
                      <option value="daily" ${period === 'daily' ? 'selected' : ''}>Daily Top</option>
                      <option value="weekly" ${period === 'weekly' ? 'selected' : ''}>Weekly Ranking</option>
                      <option value="monthly" ${period === 'monthly' ? 'selected' : ''}>Monthly Best</option>
                    </select>
                  ` : ''}
                  <select class="form-select" style="font-size:0.8rem; height:36px;" onchange="app.updateParam('sort', this.value)">
                    <option value="newest" ${sort === 'newest' ? 'selected' : ''}>Newest First</option>
                    <option value="popular" ${sort === 'popular' ? 'selected' : ''}>Most Popular</option>
                    <option value="views" ${sort === 'views' ? 'selected' : ''}>Most Views</option>
                    <option value="oldest" ${sort === 'oldest' ? 'selected' : ''}>Oldest</option>
                  </select>
                  ${feedType !== 'r18' ? `
                    <select class="form-select" style="font-size:0.8rem; height:36px;" onchange="app.updateParam('rating', this.value)">
                      <option value="all" ${rating === 'all' ? 'selected' : ''}>All Ratings</option>
                      <option value="safe" ${rating === 'safe' ? 'selected' : ''}>All Ages Only</option>
                      <option value="r18" ${rating === 'r18' ? 'selected' : ''}>R-18 Only</option>
                    </select>
                  ` : ''}
                </div>
              </div>

              <div id="adv-search-panel" style="display:none; background:var(--bg-surface); border:1px solid var(--border-subtle); border-radius:14px; padding:1.2rem; margin-bottom:1.4rem;">
                <div style="font-weight:700; font-size:0.95rem; margin-bottom:0.8rem;">Advanced Query Filter</div>
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:0.8rem;">
                  <input type="text" id="adv-tags" class="form-input" placeholder="General Tags" value="${this.escape(tag || query)}">
                  <input type="text" id="adv-char" class="form-input" placeholder="Character Depicted" value="${this.escape(character)}">
                  <input type="text" id="adv-parody" class="form-input" placeholder="Series / Parody" value="${this.escape(parody)}">
                  <input type="text" id="adv-url" class="form-input" placeholder="Source URL Link" value="${this.escape(sourceUrl)}">
                </div>
                <div style="display:flex; justify-content:flex-end; gap:0.6rem; margin-top:0.9rem;">
                  <button type="button" class="btn-subtle" onclick="app.clearAdvSearch()">Reset Filters</button>
                  <button type="button" class="btn-primary" onclick="app.executeAdvSearch()">Apply Filter</button>
                </div>
              </div>
            `;

            const encCategory = tag ? 'tag' : (character ? 'character' : (parody ? 'parody' : null));
            const encName = tag || character || parody || null;

            if (encCategory && encName) {
              html += `<div id="feed-encyclopedia-slot" style="margin-bottom:1.4rem;"><div class="spinner" style="margin:1rem auto; width:26px; height:26px;"></div></div>`;
              setTimeout(() => this.loadFeedEncyclopedia(encCategory, encName), 20);
            }

            if (!res.artworks || !res.artworks.length) {
              html += `<div class="center-msg">No illustrations or videos found for this criteria.</div>`;
            } else {
              html += `<div class="art-grid">`;
              res.artworks.forEach(art => {
                const coverFileName = art.cover_file || '';
                const coverUrl = coverFileName ? `?action=thumb&f=${encodeURIComponent(coverFileName)}` : '';
                const avatarUrl = this.getAvatar(art.avatar, art.artist_name, art.email_hash);
                const isVid = art.type === 'video' || (art.cover_mime && art.cover_mime.startsWith('video/'));
                const pageCount = Number(art.page_count || 1);
                const viewCount = Number(art.view_count || 0);
                const likeCount = Number(art.like_count || 0);

                const rawTags = (art.tags || '').split(/[,#、\s]+/).filter(Boolean);
                const previewTags = rawTags.slice(0, 2);

                html += `
                  <div class="art-card" onclick="app.nav('#/artwork/${art.id}')">
                    <div class="art-thumb-wrap">
                      ${coverUrl ? `<img src="${coverUrl}" alt="" loading="lazy" onerror="this.onerror=null; this.src='?action=raw&f=${encodeURIComponent(coverFileName)}'">` : '<div style="display:flex; align-items:center; justify-content:center; height:100%; color:var(--text-muted);">No Media</div>'}
                      ${pageCount > 1 ? `<div class="badge-page-count"><svg viewBox="0 0 24 24" style="width:13px;height:13px;"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V5h14v14z"/></svg> ${pageCount}P</div>` : ''}
                      ${art.type === 'manga' ? `<div class="badge-flag manga">MANGA</div>` : ''}
                      ${isVid ? `<div class="badge-flag video">VIDEO</div>` : ''}
                      ${art.rating === 'r18' ? `<div class="badge-flag">R-18</div>` : ''}
                      ${art.is_ai ? `<div class="badge-flag ai">AI</div>` : ''}
                    </div>
                    <div class="art-card-info">
                      <div class="art-card-title">${this.escape(art.title)}</div>
                      <div class="art-card-author">
                        <img src="${avatarUrl}" class="art-card-avatar" alt="" data-artist-name="${this.escape(art.artist_name)}" onerror="app.handleAvatarError(this)">
                        <span>${this.escape(art.artist_name)}</span>
                  </div>
                      ${previewTags.length ? `
                        <div class="art-card-tags-preview">
                          ${previewTags.map(t => `<span class="art-card-tag-badge">#${this.escape(t)}</span>`).join('')}
                        </div>
                      ` : ''}
                      <div class="art-card-stats">
                        <span>${viewCount.toLocaleString()} views</span>
                        <div class="art-card-actions">
                          <span class="stat-btn ${art.user_liked ? 'active like' : ''}" onclick="event.stopPropagation(); app.toggleLike(${art.id}, this)">
                            <svg viewBox="0 0 24 24"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>
                            <span>${likeCount}</span>
                          </span>
                        </div>
                      </div>
                    </div>
                  </div>
                `;
              });
              html += `</div>`;

              if (res.pages > 1) {
                html += `
                  <div class="pagination-bar" style="display:flex; justify-content:center; align-items:center; gap:0.5rem; margin-top:2.5rem; margin-bottom:1.5rem; flex-wrap:wrap;">
                    <button class="btn-subtle" style="width:36px; height:36px; padding:0;" title="First Page" ${res.page <= 1 ? 'disabled style="opacity:0.4; cursor:not-allowed;"' : ''} onclick="app.updateParam('page', 1)">
                      <svg viewBox="0 0 24 24" style="width:16px; height:16px;"><path d="M18.41 16.59L13.82 12l4.59-4.59L17 6l-6 6 6 6zM6 6h2v12H6z"/></svg>
                    </button>
                    <button class="btn-subtle" style="width:36px; height:36px; padding:0;" title="Previous Page" ${res.page <= 1 ? 'disabled style="opacity:0.4; cursor:not-allowed;"' : ''} onclick="app.updateParam('page', ${res.page - 1})">
                      <svg viewBox="0 0 24 24" style="width:16px; height:16px;"><path d="M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z"/></svg>
                    </button>
                    <button class="btn-subtle" style="font-weight:700; color:var(--accent); border-color:var(--accent-alpha); background:var(--accent-alpha);" title="Click to jump to page" onclick="app.showJumpPageModal(${res.page}, ${res.pages})">Page ${res.page} of ${res.pages}</button>
                    <button class="btn-subtle" style="width:36px; height:36px; padding:0;" title="Next Page" ${res.page >= res.pages ? 'disabled style="opacity:0.4; cursor:not-allowed;"' : ''} onclick="app.updateParam('page', ${res.page + 1})">
                      <svg viewBox="0 0 24 24" style="width:16px; height:16px;"><path d="M10 6L8.59 7.41 13.17 12l-4.58 4.59L10 18l6-6z"/></svg>
                    </button>
                    <button class="btn-subtle" style="width:36px; height:36px; padding:0;" title="Latest Page" ${res.page >= res.pages ? 'disabled style="opacity:0.4; cursor:not-allowed;"' : ''} onclick="app.updateParam('page', ${res.pages})">
                      <svg viewBox="0 0 24 24" style="width:16px; height:16px;"><path d="M5.59 7.41L10.18 12l-4.59 4.59L7 18l6-6-6-6zM16 6h2v12h-2z"/></svg>
                    </button>
                  </div>
                `;
              }
            }

            container.innerHTML = html;
          } catch(err) {
            container.innerHTML = `<div class="center-msg">${err.message}</div>`;
          }
        }
  
        async loadFeedEncyclopedia(category, name) {
          const slot = document.getElementById('feed-encyclopedia-slot');
          if (!slot) return;
          try {
            const res = await this.api('encyclopedia_get', { category, name });
            let parsedHtml = '';
            if (res.body) {
              try {
                if (typeof marked !== 'undefined' && typeof DOMPurify !== 'undefined') {
                  parsedHtml = DOMPurify.sanitize(marked.parse(res.body));
                } else {
                  parsedHtml = this.escape(res.body).replace(/\n/g, '<br>');
                }
              } catch(e) {
                parsedHtml = this.escape(res.body).replace(/\n/g, '<br>');
              }
            }

            const catLabels = { tag: 'Tag Encyclopedia', character: 'Character Lore', parody: 'Series Lore' };
            const label = catLabels[category] || 'Encyclopedia';
            const revCount = Number(res.revision_count || (res.body ? 1 : 0));
            const topThumbUrl = res.top_artwork_cover ? `?action=thumb&f=${encodeURIComponent(res.top_artwork_cover)}` : '';

            slot.innerHTML = `
              <div class="encyclopedia-card">
                <div class="encyclopedia-header">
                  <div style="display:flex; align-items:center; gap:0.6rem; flex-wrap:wrap;">
                    <span class="encyclopedia-badge ${category}">${label}</span>
                    <h2 style="font-size:1.15rem; font-weight:800; margin:0;">${this.escape(name)}</h2>
                    <span style="font-size:0.75rem; color:var(--text-muted); background:var(--bg-surface-elevated); padding:0.15rem 0.5rem; border-radius:4px; font-weight:600;">
                      Community Wiki
                    </span>
                  </div>
                  <div style="display:flex; gap:0.4rem; align-items:center;">
                    ${revCount > 0 ? `
                      <button type="button" class="btn-subtle" style="height:30px; font-size:0.75rem; gap:0.3rem;" onclick="app.openEncyclopediaHistoryModal('${category}', '${this.escape(name)}')" title="View edit history">
                        <svg viewBox="0 0 24 24" style="width:13px;height:13px;"><path d="M13 3a9 9 0 0 0-9 9H1l3.89 3.89.07.14L9 12H6c0-3.87 3.13-7 7-7s7 3.13 7 7-3.13 7-7 7c-1.93 0-3.68-.79-4.94-2.06l-1.42 1.42A8.954 8.954 0 0 0 13 21a9 9 0 0 0 0-18zm-1 5v5l4.28 2.54.72-1.21-3.5-2.08V8H12z"/></svg>
                        <span>History (${revCount})</span>
                      </button>
                    ` : ''}
                    <button type="button" class="btn-primary" style="height:30px; font-size:0.75rem; gap:0.35rem;" onclick="app.openEncyclopediaEditModal('${category}', '${this.escape(name)}')">
                      <svg viewBox="0 0 24 24" style="width:13px;height:13px;"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                      <span>${res.body ? 'Edit Article' : 'Write Article'}</span>
                    </button>
                  </div>
                </div>

                <div class="encyclopedia-main-row">
                  ${res.top_artwork_id > 0 && topThumbUrl ? `
                    <div class="encyclopedia-preview-thumb" onclick="app.nav('#/artwork/${res.top_artwork_id}')" title="Most Viewed Post: ${this.escape(res.top_artwork_title)} (${res.top_artwork_views.toLocaleString()} views) - Click to view">
                      <img src="${topThumbUrl}" alt="${this.escape(res.top_artwork_title)}" onerror="this.onerror=null; this.src='?action=raw&f=${encodeURIComponent(res.top_artwork_cover)}'">
                      <div class="encyclopedia-preview-badge">&#9733; ${(res.top_artwork_views || 0).toLocaleString()} views</div>
                    </div>
                  ` : ''}

                  <div style="flex:1; min-width:0; display:flex; flex-direction:column; gap:0.6rem;">
                    ${res.body ? `
                      <div class="encyclopedia-body">${parsedHtml}</div>
                    ` : `
                      <div style="font-size:0.84rem; color:var(--text-muted); line-height:1.5;">
                        No wiki article has been written for "${this.escape(name)}" yet. Anyone with an account can write and edit lore freely!
                      </div>
                    `}
                  </div>
                </div>

                <div class="encyclopedia-meta" style="justify-content:space-between; flex-wrap:wrap; gap:0.4rem; border-top:1px solid var(--border-subtle); padding-top:0.6rem; margin-top:0.2rem;">
                  <div>
                    ${res.editor_name ? `<span>Last edited by <strong>${this.escape(res.editor_name)}</strong></span> &bull; ` : ''}
                    <span>${res.updated_at ? new Date(res.updated_at * 1000).toLocaleDateString() : 'Community Wiki'}</span>
                  </div>
                  <span style="font-size:0.72rem; color:var(--text-muted); font-style:italic;">
                    Open for all registered members to edit &bull; Crowdsourced lore
                  </span>
                </div>
              </div>
            `;
          } catch(e) {
            slot.innerHTML = '';
          }
        }

        async openEncyclopediaModal(category, name) {
          try {
            const res = await this.api('encyclopedia_get', { category, name });
            let parsedHtml = '';
            if (res.body) {
              try {
                parsedHtml = (typeof marked !== 'undefined' && typeof DOMPurify !== 'undefined')
                  ? DOMPurify.sanitize(marked.parse(res.body))
                  : this.escape(res.body).replace(/\n/g, '<br>');
              } catch(e) {
                parsedHtml = this.escape(res.body).replace(/\n/g, '<br>');
              }
            }

            const revCount = Number(res.revision_count || (res.body ? 1 : 0));
            const topThumbUrl = res.top_artwork_cover ? `?action=thumb&f=${encodeURIComponent(res.top_artwork_cover)}` : '';

            const html = `
              <div class="modal-header">
                <div style="display:flex; align-items:center; gap:0.5rem;">
                  <span class="encyclopedia-badge ${category}">${category}</span>
                  <span>${this.escape(name)} Encyclopedia</span>
                </div>
                <button class="btn-icon" onclick="app.closeModal()"><svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg></button>
              </div>
              <div class="modal-body" style="gap:1rem;">
                <div class="encyclopedia-main-row">
                  ${res.top_artwork_id > 0 && topThumbUrl ? `
                    <div class="encyclopedia-preview-thumb" onclick="app.closeModal(); app.nav('#/artwork/${res.top_artwork_id}')" title="Most Viewed: ${this.escape(res.top_artwork_title)} (${res.top_artwork_views.toLocaleString()} views) - Click to open post">
                      <img src="${topThumbUrl}" alt="${this.escape(res.top_artwork_title)}" onerror="this.onerror=null; this.src='?action=raw&f=${encodeURIComponent(res.top_artwork_cover)}'">
                      <div class="encyclopedia-preview-badge">&#9733; ${(res.top_artwork_views || 0).toLocaleString()} views</div>
                    </div>
                  ` : ''}
                  <div style="flex:1; min-width:0;">
                    ${res.body ? `<div class="encyclopedia-body">${parsedHtml}</div>` : `<p style="font-size:0.88rem; color:var(--text-muted);">No article written yet. Registered members can write lore anytime!</p>`}
                  </div>
                </div>

                <div style="font-size:0.75rem; color:var(--text-muted); border-top:1px solid var(--border-subtle); padding-top:0.6rem; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.4rem;">
                  <span>${res.editor_name ? `Last editor: <strong>${this.escape(res.editor_name)}</strong>` : 'Community Encyclopedia'}</span>
                  <span>All registered members can edit</span>
                </div>
              </div>
              <div class="modal-footer" style="justify-content:space-between;">
                <div>
                  ${revCount > 0 ? `
                    <button type="button" class="btn-subtle" onclick="app.openEncyclopediaHistoryModal('${category}', '${this.escape(name)}')">History (${revCount})</button>
                  ` : ''}
                </div>
                <div style="display:flex; gap:0.5rem;">
                  <button type="button" class="btn-subtle" onclick="app.closeModal()">Close</button>
                  <button type="button" class="btn-primary" onclick="app.openEncyclopediaEditModal('${category}', '${this.escape(name)}')">
                    ${res.body ? 'Edit Article' : 'Write Article'}
                  </button>
                </div>
              </div>
            `;
            this.showModal(html);
          } catch(err) {
            this.toast(err.message);
          }
        }

        async openEncyclopediaEditModal(category, name) {
          if (!this.user) {
            this.toast('Please log in. All registered accounts can edit the encyclopedia!');
            this.showAuthModal();
            return;
          }
          try {
            const res = await this.api('encyclopedia_get', { category, name });
            const captchaUrl = `?action=encyclopedia_captcha&t=${Date.now()}`;

            const html = `
              <div class="modal-header">
                <div style="display:flex; align-items:center; gap:0.5rem;">
                  <span class="encyclopedia-badge ${category}">${category}</span>
                  <span>Edit Article: ${this.escape(name)}</span>
                </div>
                <button class="btn-icon" onclick="app.closeModal()"><svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg></button>
              </div>
              <form onsubmit="app.handleEncyclopediaSubmit(event)">
                <input type="hidden" name="category" value="${this.escape(category)}">
                <input type="hidden" name="name" value="${this.escape(name)}">
                <div class="modal-body" style="gap:0.9rem;">
                  <div style="background:var(--bg-surface-elevated); border:1px solid var(--border-subtle); border-radius:10px; padding:0.75rem 0.95rem; font-size:0.8rem; color:var(--text-secondary); line-height:1.5;">
                    <strong style="color:var(--text-primary);">&#128214; Community Encyclopedia:</strong> Anyone with an account can freely contribute and improve this article (limit: 10 edits/day). Your pseudonym (<strong>${this.escape(this.user.artist_name)}</strong>) will be recorded in the revision history.
                  </div>

                  <div class="form-group">
                    <label class="form-label">Article Lore &amp; Information (Markdown Enabled)</label>
                    <textarea name="body" class="form-textarea" style="min-height:210px; font-size:0.88rem; line-height:1.5;" placeholder="Document character backstory, world lore, origin, personality, or tag explanation..." required>${this.escape(res.body || '')}</textarea>
                  </div>

                  <div class="form-group">
                    <label class="form-label">Edit Summary (Optional)</label>
                    <input type="text" name="edit_summary" class="form-input" placeholder="e.g., Added character background, fixed typo, expanded lore..." maxlength="200">
                  </div>

                  <div class="form-group">
                    <label class="form-label">Security Verification (Anti-Spam Captcha) *</label>
                    <div style="display:flex; align-items:center; gap:0.6rem;">
                      <img id="enc-captcha-img" src="${captchaUrl}" style="height:38px; border-radius:8px; border:1px solid var(--border-subtle); cursor:pointer; background:#14141a;" onclick="this.src='?action=encyclopedia_captcha&t='+Date.now()" title="Click to refresh captcha">
                      <input type="text" name="captcha" class="form-input" style="max-width:130px; text-align:center; font-family:'JetBrains Mono',monospace; font-weight:700; font-size:1rem;" placeholder="Answer" required autocomplete="off">
                      <button type="button" class="btn-subtle" style="height:38px; padding:0 0.75rem; font-size:0.85rem;" onclick="document.getElementById('enc-captcha-img').src='?action=encyclopedia_captcha&t='+Date.now()" title="Refresh Captcha">&#x21bb;</button>
                    </div>
                  </div>
                </div>
                <div class="modal-footer">
                  <button type="button" class="btn-subtle" onclick="app.closeModal()">Cancel</button>
                  <button type="submit" class="btn-primary" id="btn-save-encyclopedia">Publish to Encyclopedia</button>
                </div>
              </form>
            `;
            this.showModal(html);
          } catch(e) {
            this.toast(e.message);
          }
        }

        async handleEncyclopediaSubmit(e) {
          e.preventDefault();
          const btn = document.getElementById('btn-save-encyclopedia');
          if (btn) { btn.disabled = true; btn.innerText = 'Publishing...'; }
          const fd = new FormData(e.target);
          try {
            const res = await this.api('encyclopedia_save', fd, 'POST');
            this.closeModal();
            this.toast('Article published to community encyclopedia!');
            this.loadFeedEncyclopedia(res.category, res.name);
          } catch(err) {
            this.toast(err.message);
            const captchaImg = document.getElementById('enc-captcha-img');
            if (captchaImg) captchaImg.src = `?action=encyclopedia_captcha&t=${Date.now()}`;
            const captchaInput = e.target.querySelector('input[name="captcha"]');
            if (captchaInput) { captchaInput.value = ''; captchaInput.focus(); }
            if (btn) { btn.disabled = false; btn.innerText = 'Publish to Encyclopedia'; }
          }
        }

        async openEncyclopediaHistoryModal(category, name) {
          try {
            const res = await this.api('encyclopedia_history', { category, name });
            const history = res.history || [];
            const html = `
              <div class="modal-header">
                <div style="display:flex; align-items:center; gap:0.5rem;">
                  <span class="encyclopedia-badge ${category}">${category}</span>
                  <span>${this.escape(name)} &ndash; Revision History</span>
                </div>
                <button class="btn-icon" onclick="app.closeModal()"><svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg></button>
              </div>
              <div class="modal-body" style="gap:0.75rem; max-height:60vh; overflow-y:auto;">
                ${!history.length ? `<p style="font-size:0.85rem; color:var(--text-muted);">No recorded past revisions yet.</p>` : `
                  <div style="display:flex; flex-direction:column; gap:0.55rem;">
                    ${history.map(item => `
                      <div style="background:var(--bg-surface-elevated); border:1px solid var(--border-subtle); border-radius:10px; padding:0.7rem 0.9rem; display:flex; justify-content:space-between; align-items:center; gap:0.75rem;">
                        <div style="min-width:0;">
                          <div style="font-weight:700; font-size:0.85rem; color:var(--text-primary); margin-bottom:0.15rem;">
                            ${this.escape(item.editor_name || 'Anonymous Artist')}
                          </div>
                          <div style="font-size:0.78rem; color:var(--text-secondary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                            ${this.escape(item.edit_summary || 'Updated article content')}
                          </div>
                        </div>
                        <span style="font-size:0.72rem; color:var(--text-muted); flex-shrink:0;">
                          ${new Date(item.created_at * 1000).toLocaleString()}
                        </span>
                      </div>
                    `).join('')}
                  </div>
                `}
              </div>
              <div class="modal-footer">
                <button type="button" class="btn-subtle" onclick="app.openEncyclopediaModal('${category}', '${this.escape(name)}')">&laquo; Back to Article</button>
                <button type="button" class="btn-primary" onclick="app.openEncyclopediaEditModal('${category}', '${this.escape(name)}')">Contribute Edit</button>
              </div>
            `;
            this.showModal(html);
          } catch(e) {
            this.toast(e.message);
          }
        }

        async exportArtworkPost(artworkId) {
          const modalHtml = `
            <div class="modal-header">
              <span>Exporting Post Package (.zip)</span>
              <button class="btn-icon" onclick="app.closeModal()"><svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg></button>
            </div>
            <div class="modal-body" style="gap:1rem;">
              <div style="display:flex; align-items:center; gap:0.75rem;">
                <div class="spinner" id="export-spinner" style="margin:0; width:26px; height:26px; flex-shrink:0;"></div>
                <div style="min-width:0; flex:1;">
                  <div id="export-status-title" style="font-weight:700; font-size:0.95rem;">Preparing Export Package...</div>
                  <div id="export-status-subtitle" style="font-size:0.8rem; color:var(--text-muted); margin-top:0.2rem;">Compiling post metadata and media items on server...</div>
                </div>
              </div>

              <div style="width:100%; background:var(--bg-base); height:10px; border-radius:5px; overflow:hidden; border:1px solid var(--border-subtle);">
                <div id="export-progress-fill" style="width:0%; height:100%; background:var(--accent); border-radius:5px; transition:width 0.15s ease;"></div>
              </div>

              <div style="display:flex; justify-content:space-between; font-size:0.8rem; color:var(--text-secondary);">
                <span id="export-bytes-text">0 MB / 0 MB</span>
                <span id="export-percent-text" style="font-weight:700; color:var(--accent);">0%</span>
              </div>
            </div>
          `;
          this.showModal(modalHtml);

          try {
            const res = await fetch(`?action=artwork_export&id=${artworkId}`);
            if (!res.ok) {
              let errMsg = 'Failed to export post package.';
              try {
                const errJson = await res.json();
                if (errJson.error) errMsg = errJson.error;
              } catch(e) {}
              throw new Error(errMsg);
            }

            const contentLength = res.headers.get('content-length');
            const totalBytes = contentLength ? parseInt(contentLength, 10) : 0;
            const reader = res.body.getReader();
            const chunks = [];
            let receivedBytes = 0;

            const statusTitle = document.getElementById('export-status-title');
            const statusSubtitle = document.getElementById('export-status-subtitle');
            const progressFill = document.getElementById('export-progress-fill');
            const bytesText = document.getElementById('export-bytes-text');
            const percentText = document.getElementById('export-percent-text');

            if (statusTitle) statusTitle.innerText = 'Transferring export package...';

            while (true) {
              const { done, value } = await reader.read();
              if (done) break;
              chunks.push(value);
              receivedBytes += value.length;

              if (totalBytes > 0) {
                const pct = Math.min(100, Math.round((receivedBytes / totalBytes) * 100));
                if (progressFill) progressFill.style.width = `${pct}%`;
                if (percentText) percentText.innerText = `${pct}%`;
                const recMB = (receivedBytes / (1024 * 1024)).toFixed(1);
                const totMB = (totalBytes / (1024 * 1024)).toFixed(1);
                if (bytesText) bytesText.innerText = `${recMB} MB / ${totMB} MB`;
                if (statusSubtitle) statusSubtitle.innerText = `${pct}% downloaded (${recMB} MB of ${totMB} MB)`;
              } else {
                const recMB = (receivedBytes / (1024 * 1024)).toFixed(1);
                if (bytesText) bytesText.innerText = `${recMB} MB transferred`;
                if (progressFill) progressFill.style.width = '100%';
                if (statusSubtitle) statusSubtitle.innerText = `${recMB} MB received`;
              }
            }

            if (statusTitle) statusTitle.innerText = 'Finalizing package...';
            const blob = new Blob(chunks, { type: 'application/zip' });

            let downloadName = `artwork_${artworkId}_export.zip`;
            const dispo = res.headers.get('content-disposition');
            if (dispo) {
              const match = dispo.match(/filename\*?=(?:UTF-8'')?["']?([^"';]+)["']?/i);
              if (match && match[1]) downloadName = decodeURIComponent(match[1]);
            }

            const blobUrl = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = blobUrl;
            link.download = downloadName;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            setTimeout(() => URL.revokeObjectURL(blobUrl), 10000);

            if (statusTitle) statusTitle.innerText = 'Export Completed!';
            if (statusSubtitle) statusSubtitle.innerText = 'Package downloaded successfully.';
            const spinner = document.getElementById('export-spinner');
            if (spinner) spinner.style.display = 'none';

            setTimeout(() => this.closeModal(), 1200);
          } catch(err) {
            const statusTitle = document.getElementById('export-status-title');
            const statusSubtitle = document.getElementById('export-status-subtitle');
            const progressFill = document.getElementById('export-progress-fill');
            if (statusTitle) {
              statusTitle.innerText = 'Export Failed';
              statusTitle.style.color = 'var(--r18)';
            }
            if (statusSubtitle) statusSubtitle.innerText = err.message;
            if (progressFill) progressFill.style.background = 'var(--r18)';
            this.toast(err.message);
          }
        }

        toggleAdvSearch() {
          const el = document.getElementById('adv-search-panel');
          if (el) el.style.display = el.style.display === 'none' ? 'block' : 'none';
        }
  
        executeAdvSearch() {
          const tag = document.getElementById('adv-tags').value.trim();
          const char = document.getElementById('adv-char').value.trim();
          const parody = document.getElementById('adv-parody').value.trim();
          const url = document.getElementById('adv-url').value.trim();
  
          const params = new URLSearchParams();
          if (tag) params.set('tag', tag);
          if (char) params.set('character', char);
          if (parody) params.set('parody', parody);
          if (url) params.set('source_url', url);
  
          this.nav(`#/explore?${params.toString()}`);
        }
  
        clearAdvSearch() {
          this.nav('#/explore');
        }
  
        updateParam(key, val) {
          const hash = window.location.hash || '#/';
          const [base, queryStr] = hash.split('?');
          const p = new URLSearchParams(queryStr || '');
          if (val === '' || val === null || val === undefined) {
            p.delete(key);
          } else {
            p.set(key, val);
          }
          if (key !== 'page') p.delete('page');
          const qs = p.toString();
          this.nav(qs ? `${base}?${qs}` : base);
        }

        showJumpPageModal(currentPage, totalPages) {
          const html = `
            <div class="modal-header">
              <span>Jump to Page</span>
              <button class="btn-icon" onclick="app.closeModal()"><svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg></button>
            </div>
            <form onsubmit="app.handleJumpPageSubmit(event, ${totalPages})">
              <div class="modal-body">
                <div class="form-group">
                  <label class="form-label">Page Number (1 &ndash; ${totalPages})</label>
                  <input type="number" id="jump-page-input" class="form-input" min="1" max="${totalPages}" value="${currentPage}" required autoFocus>
                </div>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn-subtle" onclick="app.closeModal()">Cancel</button>
                <button type="submit" class="btn-primary">Go to Page</button>
              </div>
            </form>
          `;
          this.showModal(html);
          setTimeout(() => {
            const input = document.getElementById('jump-page-input');
            if (input) { input.focus(); input.select(); }
          }, 80);
        }

        handleJumpPageSubmit(e, totalPages) {
          e.preventDefault();
          const targetPage = parseInt(document.getElementById('jump-page-input').value, 10);
          if (isNaN(targetPage) || targetPage < 1 || targetPage > totalPages) {
            this.toast(`Please enter a page between 1 and ${totalPages}`);
            return;
          }
          this.closeModal();
          this.updateParam('page', targetPage);
        }
  
        async renderSimilarPage(params) {
          this.setTitle('Visual Similarity Search');
          const container = document.getElementById('page-container');
          container.innerHTML = '<div class="spinner"></div>';
          const sourceId = params.get('source_id') || 0;
  
          try {
            let html = `
              <div style="max-width:1100px; margin:0 auto; width:100%;">
                <h1 style="font-size:1.4rem; font-weight:800; margin-bottom:0.4rem;">Perceptual Visual Similarity Search</h1>
                <p style="font-size:0.85rem; color:var(--text-muted); margin-bottom:1.4rem;">Match visual features and compositions across HDPost using 64-bit image hashes.</p>
  
                <div style="background:var(--bg-surface); border:1px solid var(--border-subtle); border-radius:14px; padding:1.2rem; margin-bottom:1.6rem;">
                  <form id="similar-upload-form" onsubmit="app.handleSimilarUpload(event)" style="display:flex; gap:0.8rem; align-items:center; flex-wrap:wrap;">
                    <label class="btn-subtle" style="cursor:pointer;">
                      <span>Choose Image File to Match</span>
                      <input type="file" name="similar_file" accept="image/*" style="display:none;" onchange="document.getElementById('sim-file-name').innerText = this.files[0]?.name || ''">
                    </label>
                    <span id="sim-file-name" style="font-size:0.82rem; color:var(--text-secondary);"></span>
                    <button type="submit" class="btn-primary">Find Visually Similar</button>
                  </form>
                </div>
            `;
  
            if (sourceId > 0) {
              const imgIndex = parseInt(params.get('image_index') || params.get('sort_order') || '0', 10);
              const res = await this.api('similar_search', { source_id: sourceId, image_index: imgIndex });
              if (res.source_art) {
                const currentSort = res.source_art.selected_sort_order || 0;
                const allImgs = res.source_art.all_images || [];

                html += `
                  <div style="background:var(--bg-surface-elevated); padding:1rem 1.25rem; border-radius:14px; border:1px solid var(--border-subtle); margin-bottom:1.4rem; display:flex; flex-direction:column; gap:0.85rem;">
                    <div style="display:flex; align-items:center; gap:1rem; justify-content:space-between; flex-wrap:wrap;">
                      <div style="display:flex; align-items:center; gap:1rem;">
                        <img src="?action=thumb&f=${encodeURIComponent(res.source_art.cover_file)}" style="width:64px; height:64px; border-radius:8px; object-fit:cover; border:2px solid var(--accent); background:#000;" alt="" onerror="this.onerror=null; this.src='?action=raw&f=${encodeURIComponent(res.source_art.cover_file)}'">
                        <div>
                          <span style="font-size:0.75rem; color:var(--text-muted); text-transform:uppercase; font-weight:800; letter-spacing:0.5px;">Matching Thumbnail:</span>
                          <h3 style="font-size:1.1rem; font-weight:800; margin-top:0.15rem;">${this.escape(res.source_art.title)}</h3>
                          <span style="font-size:0.8rem; color:var(--accent); font-weight:700;">Page #${currentSort + 1}${allImgs.length > 1 ? ` of ${allImgs.length}` : ''}</span>
                        </div>
                      </div>
                      <button type="button" class="btn-subtle" style="height:32px; font-size:0.78rem;" onclick="app.nav('#/artwork/${res.source_art.id}')">View Full Post</button>
                    </div>

                    ${allImgs.length > 1 ? `
                      <div style="border-top:1px solid var(--border-subtle); padding-top:0.75rem; display:flex; flex-direction:column; gap:0.45rem;">
                        <span style="font-size:0.75rem; font-weight:800; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.4px;">
                          Switch Image Page to Match:
                        </span>
                        <div style="display:flex; gap:0.6rem; overflow-x:auto; padding-bottom:0.3rem; scroll-behavior:smooth;">
                          ${allImgs.map((im, idx) => {
                            const isSelected = (idx === currentSort);
                            return `
                              <div class="thumb-reel-item ${isSelected ? 'active' : ''}" style="width:56px; height:56px; border-radius:8px; flex-shrink:0; cursor:pointer; position:relative; border:2px solid ${isSelected ? 'var(--accent)' : 'var(--border-subtle)'}; background:#000;" onclick="app.updateParam('image_index', ${idx})" title="Match Page #${idx + 1}">
                                <img src="?action=thumb&f=${encodeURIComponent(im.file_name)}" style="width:100%; height:100%; object-fit:cover; border-radius:6px;" alt="">
                                <span style="position:absolute; bottom:2px; right:2px; font-size:0.62rem; font-weight:800; background:rgba(0,0,0,0.8); color:#fff; padding:1px 4px; border-radius:3px;">#${idx + 1}</span>
                              </div>
                            `;
                          }).join('')}
                        </div>
                      </div>
                    ` : ''}
                  </div>
                `;
              }
  
              if (!res.matches || !res.matches.length) {
                html += `<div class="center-msg">No visually similar artworks matched in database.</div>`;
              } else {
                html += `<h3 style="font-size:1.1rem; font-weight:700; margin-bottom:1rem;">Visual Matches (${res.matches.length})</h3><div class="art-grid">`;
                res.matches.forEach(m => {
                  html += `
                    <div class="art-card" onclick="app.nav('#/artwork/${m.id}')">
                      <div class="art-thumb-wrap">
                        <img src="?action=thumb&f=${encodeURIComponent(m.cover_file)}" alt="" loading="lazy" onerror="this.onerror=null; this.src='?action=raw&f=${encodeURIComponent(m.cover_file)}'">
                        <div class="badge-flag" style="background:#10b981;">${m.similarity}% Match</div>
                      </div>
                      <div class="art-card-info">
                        <div class="art-card-title">${this.escape(m.title)}</div>
                        <div class="art-card-author">
                          <span>${this.escape(m.artist_name)}</span>
                        </div>
                      </div>
                    </div>
                  `;
                });
                html += `</div>`;
              }
            }
            html += `</div>`;
            container.innerHTML = html;
          } catch(e) {
            container.innerHTML = `<div class="center-msg">${e.message}</div>`;
          }
        }
  
        async handleSimilarUpload(e) {
          e.preventDefault();
          const form = e.target;
          const file = form.similar_file.files[0];
          if (!file) return this.toast('Select an image file first.');
  
          const container = document.getElementById('page-container');
          container.innerHTML = '<div class="spinner"></div>';
  
          const fd = new FormData();
          fd.append('similar_file', file);
          try {
            const res = await this.api('similar_search', fd, 'POST');
            let html = `
              <div style="max-width:1100px; margin:0 auto; width:100%;">
                <button class="btn-subtle" onclick="app.nav('#/similar')" style="margin-bottom:1rem;">&laquo; Back to Similar Search</button>
                <h2 style="font-size:1.3rem; font-weight:800; margin-bottom:1rem;">Matches for "${this.escape(file.name)}" (${res.matches.length})</h2>
            `;
            if (!res.matches.length) {
              html += `<div class="center-msg">No visually similar artworks matched in database.</div>`;
            } else {
              html += `<div class="art-grid">`;
              res.matches.forEach(m => {
                html += `
                  <div class="art-card" onclick="app.nav('#/artwork/${m.id}')">
                    <div class="art-thumb-wrap">
                      <img src="?action=thumb&f=${encodeURIComponent(m.cover_file)}" alt="" loading="lazy" onerror="this.onerror=null; this.src='?action=raw&f=${encodeURIComponent(m.cover_file)}'">
                      <div class="badge-flag" style="background:#10b981;">${m.similarity}% Match</div>
                    </div>
                    <div class="art-card-info">
                      <div class="art-card-title">${this.escape(m.title)}</div>
                      <div class="art-card-author"><span>${this.escape(m.artist_name)}</span></div>
                    </div>
                  </div>
                `;
              });
              html += `</div>`;
            }
            html += `</div>`;
            container.innerHTML = html;
          } catch(err) {
            this.toast(err.message);
            this.nav('#/similar');
          }
        }
  
        async renderTagsDirectory() {
          this.setTitle('Tags Directory');
          const container = document.getElementById('page-container');
          container.innerHTML = '<div class="spinner"></div>';
          try {
            const res = await this.api('tags_all');
            let html = `
              <div style="max-width:1100px; margin:0 auto; width:100%;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.4rem; flex-wrap:wrap; gap:0.8rem;">
                  <div>
                    <h1 style="font-size:1.4rem; font-weight:800;">Tags Directory</h1>
                    <p style="font-size:0.82rem; color:var(--text-muted);">${res.tags.length} unique tags in archive</p>
                  </div>
                  <div style="display:flex; align-items:center; gap:0.6rem; flex-wrap:wrap;">
                    <input type="text" class="form-input" style="max-width:220px;" placeholder="Filter tags..." oninput="app.filterDirectory(this.value, '.tag-dir-item')">
                    <select class="form-select" style="height:36px; font-size:0.82rem;" onchange="app.sortDirectory('#tags-dir-container', '.tag-dir-item', this.value)">
                      <option value="name_asc" selected>Name (A-Z)</option>
                      <option value="name_desc">Name (Z-A)</option>
                      <option value="count_desc">Most Used</option>
                      <option value="count_asc">Least Used</option>
                    </select>
                  </div>
                </div>

                <div id="tags-dir-container" style="display:flex; flex-wrap:wrap; gap:0.6rem; background:var(--bg-surface); padding:1.4rem; border:1px solid var(--border-subtle); border-radius:14px;">
                  ${res.tags.map(t => `
                    <div class="tag-dir-item tag-pill" data-label="${this.escape(t.tag_name).toLowerCase()}" data-name="${this.escape(t.tag_name).toLowerCase()}" data-count="${t.tag_count}" onclick="app.nav('#/explore?tag=' + encodeURIComponent('${this.escape(t.tag_name)}'))">
                      <span>${this.escape(t.tag_name)}</span>
                      <span style="opacity:0.6; font-size:0.75rem;">(${t.tag_count})</span>
                    </div>
                  `).join('')}
                </div>
              </div>
            `;
            container.innerHTML = html;
          } catch(e) {
            container.innerHTML = `<div class="center-msg">${e.message}</div>`;
          }
        }
  
        async renderArtistsDirectory() {
          this.setTitle('Artists Directory');
          const container = document.getElementById('page-container');
          container.innerHTML = '<div class="spinner"></div>';
          try {
            const res = await this.api('artists_all');
            let html = `
              <div style="max-width:1100px; margin:0 auto; width:100%;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.4rem; flex-wrap:wrap; gap:0.8rem;">
                  <div>
                    <h1 style="font-size:1.4rem; font-weight:800;">Artists Directory</h1>
                    <p style="font-size:0.82rem; color:var(--text-muted);">${res.artists.length} creators</p>
                  </div>
                  <div style="display:flex; align-items:center; gap:0.6rem; flex-wrap:wrap;">
                    <input type="text" class="form-input" style="max-width:220px;" placeholder="Search artists..." oninput="app.filterDirectory(this.value, '.artist-dir-card')">
                    <select class="form-select" style="height:36px; font-size:0.82rem;" onchange="app.sortDirectory('#artists-dir-container', '.artist-dir-card', this.value)">
                      <option value="count_desc" selected>Most Creations</option>
                      <option value="count_asc">Least Creations</option>
                      <option value="name_asc">Name (A-Z)</option>
                      <option value="name_desc">Name (Z-A)</option>
                    </select>
                  </div>
                </div>

                <div id="artists-dir-container" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(240px, 1fr)); gap:1rem;">
                  ${res.artists.map(a => {
                    const avatarUrl = this.getAvatar(a.avatar, a.artist_name, a.email_hash);
                    return `
                      <div class="artist-dir-card" data-label="${this.escape(a.artist_name).toLowerCase()}" data-name="${this.escape(a.artist_name).toLowerCase()}" data-count="${a.artwork_count}" style="background:var(--bg-surface); border:1px solid var(--border-subtle); border-radius:14px; padding:1rem; display:flex; align-items:center; gap:0.9rem; cursor:pointer;" onclick="app.nav('#/user/${a.id}')">
                        <img src="${avatarUrl}" style="width:48px; height:48px; border-radius:50%; object-fit:cover; border:2px solid var(--accent); background:var(--bg-surface-elevated);" alt="" onerror="app.handleAvatarError(this, '${this.escape(a.artist_name)}')">
                        <div style="min-width:0;">
                          <div style="font-weight:700; font-size:0.95rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${this.escape(a.artist_name)}</div>
                          <div style="font-size:0.75rem; color:var(--text-secondary); margin-top:0.2rem;">${a.artwork_count} creations</div>
                        </div>
                      </div>
                    `;
                  }).join('')}
                </div>
              </div>
            `;
            container.innerHTML = html;
          } catch(e) {
            container.innerHTML = `<div class="center-msg">${e.message}</div>`;
          }
        }
  
        sortDirectory(containerSelector, itemSelector, sortBy) {
          const container = document.querySelector(containerSelector);
          if (!container) return;
          const items = Array.from(container.querySelectorAll(itemSelector));
          items.sort((a, b) => {
            const countA = parseInt(a.dataset.count || '0', 10);
            const countB = parseInt(b.dataset.count || '0', 10);
            const nameA = a.dataset.name || '';
            const nameB = b.dataset.name || '';
            if (sortBy === 'count_desc') return countB - countA || nameA.localeCompare(nameB);
            if (sortBy === 'count_asc') return countA - countB || nameA.localeCompare(nameB);
            if (sortBy === 'name_asc') return nameA.localeCompare(nameB);
            if (sortBy === 'name_desc') return nameB.localeCompare(nameA);
            return 0;
          });
          items.forEach(el => container.appendChild(el));
        }

        filterDirectory(val, selector) {
          const query = val.toLowerCase().trim();
          document.querySelectorAll(selector).forEach(el => {
            const match = el.dataset.label.includes(query);
            el.style.display = match ? '' : 'none';
          });
        }
  
        async renderAdminPanel() {
          if (!this.user || Number(this.user.is_admin) < 1) {
            this.toast('Admin access restricted.');
            this.nav('#/');
            return;
          }
          this.setTitle('Administration Studio');
  
          const container = document.getElementById('page-container');
          container.innerHTML = '<div class="spinner"></div>';

          try {
            const stats = await this.api('admin_stats');
            const isSuper = Number(this.user.is_admin) === 2;
            let html = `
              <div style="max-width:1200px; margin:0 auto; width:100%;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.2rem; flex-wrap:wrap; gap:0.6rem;">
                  <div>
                    <h1 style="font-size:1.5rem; font-weight:800; color:var(--r18);">HDPost Administration Studio</h1>
                    <div style="font-size:0.8rem; color:var(--text-muted); margin-top:0.2rem;">Logged in as: <strong>${this.escape(this.user.artist_name)}</strong></div>
                  </div>
                  <span class="badge-flag" style="position:static; font-size:0.8rem; padding:0.35rem 0.75rem; border-radius:8px; background:${isSuper ? 'var(--r18)' : 'var(--accent)'};">
                    ${isSuper ? 'Super Administrator' : 'Administrator'}
                  </span>
                </div>

                <div class="stat-card-grid">
                  <div class="stat-card"><span class="stat-card-num">${stats.artworks}</span><span class="stat-card-lbl">Artworks</span></div>
                  <div class="stat-card"><span class="stat-card-num">${stats.users}</span><span class="stat-card-lbl">Users</span></div>
                  <div class="stat-card"><span class="stat-card-num">${stats.comments}</span><span class="stat-card-lbl">Comments</span></div>
                  <div class="stat-card"><span class="stat-card-num">${stats.likes}</span><span class="stat-card-lbl">Likes</span></div>
                  <div class="stat-card"><span class="stat-card-num">${stats.disk_usage}</span><span class="stat-card-lbl">Media Storage</span></div>
                </div>

                <div class="admin-tab-nav">
                  <button class="admin-tab-btn active" id="atb-users" onclick="app.adminSwitchTab('users')">Manage Users</button>
                  <button class="admin-tab-btn" id="atb-art" onclick="app.adminSwitchTab('art')">Manage Posts</button>
                  <button class="admin-tab-btn" id="atb-add_admin" onclick="app.adminSwitchTab('add_admin')">Appoint Admin</button>
                  <button class="admin-tab-btn" id="atb-comments" onclick="app.adminSwitchTab('comments')">Comment Moderation</button>
                  <button class="admin-tab-btn" id="atb-sys" onclick="app.adminSwitchTab('sys')">System Diagnostics</button>
                </div>

                <div id="admin-tab-content"></div>
              </div>
            `;
            container.innerHTML = html;
            this.switchAdminTab('users', 1);
          } catch(e) {
            container.innerHTML = `<div class="center-msg">${e.message}</div>`;
          }
        }

        adminSwitchTab(tab) {
          this.adminState.tab = tab;
          this.adminState.page = 1;
          this.adminState.q = '';
          this.adminState.sort = (tab === 'users') ? 'id_asc' : 'newest';
          this.switchAdminTab(tab, 1);
        }

        adminApplySearch(query) {
          this.adminState.q = query.trim();
          this.adminState.page = 1;
          this.switchAdminTab(this.adminState.tab, 1);
        }

        adminApplySort(sortVal) {
          this.adminState.sort = sortVal;
          this.adminState.page = 1;
          this.switchAdminTab(this.adminState.tab, 1);
        }

        adminGoPage(p) {
          this.adminState.page = p;
          this.switchAdminTab(this.adminState.tab, p);
        }

        renderAdminPagination(page, pages, total) {
          if (pages <= 1) return `<div style="font-size:0.8rem; color:var(--text-muted); margin-top:1rem;">Showing all ${total} record(s).</div>`;
          return `
            <div style="display:flex; justify-content:space-between; align-items:center; margin-top:1.2rem; flex-wrap:wrap; gap:0.6rem;">
              <span style="font-size:0.82rem; color:var(--text-muted);">Total: <strong>${total}</strong> records</span>
              <div style="display:flex; align-items:center; gap:0.5rem;">
                <button class="btn-subtle" style="height:32px; font-size:0.8rem;" ${page <= 1 ? 'disabled style="opacity:0.4; cursor:not-allowed;"' : ''} onclick="app.adminGoPage(${page - 1})">&laquo; Previous</button>
                <span style="font-size:0.85rem; font-weight:600; padding:0 0.5rem;">Page ${page} of ${pages}</span>
                <button class="btn-subtle" style="height:32px; font-size:0.8rem;" ${page >= pages ? 'disabled style="opacity:0.4; cursor:not-allowed;"' : ''} onclick="app.adminGoPage(${page + 1})">Next &raquo;</button>
              </div>
            </div>
          `;
        }

        renderAdminToolbar(placeholder, sortOptionsHtml) {
          return `
            <div style="display:flex; justify-content:space-between; align-items:center; gap:0.8rem; margin-bottom:1rem; flex-wrap:wrap;">
              <div style="display:flex; align-items:center; gap:0.5rem; flex:1; max-width:380px;">
                <input type="text" id="admin-search-input" class="form-input" placeholder="${placeholder}" value="${this.escape(this.adminState.q)}" onkeydown="if(event.key==='Enter') app.adminApplySearch(this.value)">
                <button class="btn-subtle" style="height:36px; padding:0 0.9rem;" onclick="app.adminApplySearch(document.getElementById('admin-search-input').value)">Search</button>
              </div>
              ${sortOptionsHtml ? `
                <div style="display:flex; align-items:center; gap:0.5rem;">
                  <span style="font-size:0.78rem; font-weight:700; color:var(--text-muted); text-transform:uppercase;">Sort:</span>
                  <select class="form-select" style="height:36px; font-size:0.82rem;" onchange="app.adminApplySort(this.value)">
                    ${sortOptionsHtml}
                  </select>
                </div>
              ` : ''}
            </div>
          `;
        }

        async switchAdminTab(tab, page = 1) {
          document.querySelectorAll('.admin-tab-btn').forEach(b => b.classList.remove('active'));
          const activeBtn = document.getElementById(`atb-${tab}`);
          if (activeBtn) activeBtn.classList.add('active');

          const box = document.getElementById('admin-tab-content');
          box.innerHTML = '<div class="spinner"></div>';

          const isSuper = Number(this.user.is_admin) === 2;

          if (tab === 'users') {
            const res = await this.api('admin_users', {
              q: this.adminState.q,
              sort: this.adminState.sort,
              page: page
            });
            const sortOptions = `
              <option value="id_asc" ${this.adminState.sort === 'id_asc' ? 'selected' : ''}>ID (Ascending)</option>
              <option value="id_desc" ${this.adminState.sort === 'id_desc' ? 'selected' : ''}>ID (Descending)</option>
              <option value="name_asc" ${this.adminState.sort === 'name_asc' ? 'selected' : ''}>Artist Name (A-Z)</option>
              <option value="name_desc" ${this.adminState.sort === 'name_desc' ? 'selected' : ''}>Artist Name (Z-A)</option>
              <option value="posts_desc" ${this.adminState.sort === 'posts_desc' ? 'selected' : ''}>Most Artworks</option>
              <option value="created_desc" ${this.adminState.sort === 'created_desc' ? 'selected' : ''}>Newest Registered</option>
            `;
            box.innerHTML = `
              ${this.renderAdminToolbar('Search users by artist name...', sortOptions)}
              <div class="data-table-wrap">
                <table class="data-table">
                  <thead>
                    <tr>
                      <th>ID</th>
                      <th>Artist Name</th>
                      <th>Role</th>
                      <th>Status</th>
                      <th>Creations</th>
                      <th>Joined</th>
                      <th>Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    ${res.users.map(u => {
                      const uRole = Number(u.is_admin);
                      const isTargetSuper = uRole === 2;
                      const isTargetAdmin = uRole >= 1;
                      const isBanned = Number(u.is_banned) === 1;
                      const isSelf = Number(u.id) === Number(this.user.id);

                      const canBan = !isSelf && !isTargetSuper && (isSuper || !isTargetAdmin);
                      const canDelete = !isSelf && !isTargetSuper && (isSuper || !isTargetAdmin);
                      const canChangeRole = !isSelf && !isTargetSuper && isSuper;

                      let roleBadge = '<span style="color:var(--text-muted);">Artist</span>';
                      if (isTargetSuper) roleBadge = '<span style="color:var(--r18); font-weight:800;">★ Super Admin</span>';
                      else if (isTargetAdmin) roleBadge = '<span style="color:var(--accent); font-weight:700;">Admin</span>';

                      let statusBadge = isBanned ? '<span style="color:var(--r18); font-weight:700;">Banned</span>' : '<span style="color:#10b981; font-weight:600;">Active</span>';

                      return `
                        <tr>
                          <td>#${u.id}</td>
                          <td><strong>${this.escape(u.artist_name)}</strong></td>
                          <td>${roleBadge}</td>
                          <td>${statusBadge}</td>
                          <td>${u.artwork_count}</td>
                          <td>${new Date(u.created_at * 1000).toLocaleDateString()}</td>
                          <td>
                            <div style="display:flex; gap:0.35rem; align-items:center;">
                              ${isSelf ? '<span style="color:var(--text-muted); font-size:0.75rem;">(Self)</span>' : ''}
                              ${canBan ? `
                                <button class="btn-subtle" style="height:26px; font-size:0.7rem; color:${isBanned ? '#10b981' : 'var(--r18)'};" onclick="app.adminToggleBan(${u.id})">
                                  ${isBanned ? 'Unban' : 'Ban'}
                                </button>
                              ` : ''}
                              ${canChangeRole ? `
                                <button class="btn-subtle" style="height:26px; font-size:0.7rem;" onclick="app.adminToggleRole(${u.id})">
                                  ${isTargetAdmin ? 'Demote' : 'Make Admin'}
                                </button>
                              ` : ''}
                              ${canDelete ? `
                                <button class="btn-subtle" style="height:26px; font-size:0.7rem; color:var(--r18);" onclick="app.adminDeleteUser(${u.id})">
                                  Delete
                                </button>
                              ` : ''}
                              ${isTargetSuper && !isSelf ? '<span style="font-size:0.72rem; color:var(--text-muted);">Protected</span>' : ''}
                            </div>
                          </td>
                        </tr>
                      `;
                    }).join('')}
                  </tbody>
                </table>
              </div>
              ${this.renderAdminPagination(res.page, res.pages, res.total)}
            `;
          } else if (tab === 'art') {
            const res = await this.api('artworks_list', {
              q: this.adminState.q,
              sort: this.adminState.sort || 'newest',
              page: page,
              limit: 24
            });
            const sortOptions = `
              <option value="newest" ${this.adminState.sort === 'newest' ? 'selected' : ''}>Newest First</option>
              <option value="popular" ${this.adminState.sort === 'popular' ? 'selected' : ''}>Most Popular</option>
              <option value="views" ${this.adminState.sort === 'views' ? 'selected' : ''}>Most Views</option>
              <option value="oldest" ${this.adminState.sort === 'oldest' ? 'selected' : ''}>Oldest</option>
            `;
            box.innerHTML = `
              ${this.renderAdminToolbar('Search posts by title or artist...', sortOptions)}
              <div class="data-table-wrap">
                <table class="data-table">
                  <thead>
                    <tr>
                      <th>ID</th>
                      <th>Cover</th>
                      <th>Title</th>
                      <th>Artist</th>
                      <th>Type</th>
                      <th>Rating</th>
                      <th>Views</th>
                      <th>Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    ${res.artworks.map(a => `
                      <tr>
                        <td>#${a.id}</td>
                        <td><img src="?action=thumb&f=${encodeURIComponent(a.cover_file)}" style="width:36px;height:36px;object-fit:cover;border-radius:4px;" alt="" onerror="this.onerror=null; this.src='?action=raw&f=${encodeURIComponent(a.cover_file)}'"></td>
                        <td><strong>${this.escape(a.title)}</strong></td>
                        <td>${this.escape(a.artist_name)}</td>
                        <td>${a.type}</td>
                        <td>${a.rating === 'r18' ? '<span style="color:var(--r18);font-weight:700;">R-18</span>' : 'Safe'}</td>
                        <td>${a.view_count || 0}</td>
                        <td>
                          <button class="btn-subtle" style="height:26px;font-size:0.7rem;" onclick="app.nav('#/artwork/${a.id}')">View</button>
                          <button class="btn-subtle" style="height:26px;font-size:0.7rem;color:var(--r18);" onclick="app.adminDeletePost(${a.id})">Delete</button>
                        </td>
                      </tr>
                    `).join('')}
                  </tbody>
                </table>
              </div>
              ${this.renderAdminPagination(res.page, res.pages, res.total)}
            `;
          } else if (tab === 'add_admin') {
            const res = await this.api('admin_search_candidates', { q: this.adminState.q });
            box.innerHTML = `
              <div style="background:var(--bg-surface); padding:1.6rem; border:1px solid var(--border-subtle); border-radius:14px; width:100%;">
                <h3 style="font-size:1.15rem; font-weight:700; margin-bottom:0.4rem;">Appoint Existing User as Administrator</h3>
                <p style="font-size:0.82rem; color:var(--text-muted); margin-bottom:1.2rem;">Select an existing active artist to grant elevated administrative moderation privileges.</p>
                <div style="display:flex; align-items:center; gap:0.5rem; margin-bottom:1.2rem; max-width:400px;">
                  <input type="text" id="cand-search-input" class="form-input" placeholder="Filter candidates by name..." value="${this.escape(this.adminState.q)}" onkeydown="if(event.key==='Enter') app.adminApplySearch(this.value)">
                  <button class="btn-subtle" style="height:36px; padding:0 0.9rem;" onclick="app.adminApplySearch(document.getElementById('cand-search-input').value)">Search</button>
                </div>
                ${!res.users.length ? `<div class="center-msg" style="padding:2rem 0;">No eligible artist accounts found.</div>` : `
                  <div class="data-table-wrap">
                    <table class="data-table">
                      <thead>
                        <tr>
                          <th>ID</th>
                          <th>Artist</th>
                          <th>Artworks</th>
                          <th>Action</th>
                        </tr>
                      </thead>
                      <tbody>
                        ${res.users.map(u => `
                          <tr>
                            <td>#${u.id}</td>
                            <td>
                              <div style="display:flex; align-items:center; gap:0.6rem;">
                                <img src="${this.getAvatar(u.avatar, u.artist_name, u.email_hash)}" style="width:30px; height:30px; border-radius:50%; object-fit:cover;" alt="" onerror="app.handleAvatarError(this, '${this.escape(u.artist_name)}')">
                                <strong>${this.escape(u.artist_name)}</strong>
                              </div>
                            </td>
                            <td>${u.artwork_count} creations</td>
                            <td>
                              <button class="btn-primary" style="height:28px; font-size:0.75rem; padding:0 0.85rem;" onclick="app.adminAppointExistingUser(${u.id})">Appoint Admin</button>
                            </td>
                          </tr>
                        `).join('')}
                      </tbody>
                    </table>
                  </div>
                `}
              </div>
            `;
          } else if (tab === 'comments') {
            const res = await this.api('admin_comments', {
              q: this.adminState.q,
              sort: this.adminState.sort || 'newest',
              page: page
            });
            const sortOptions = `
              <option value="newest" ${this.adminState.sort === 'newest' ? 'selected' : ''}>Newest Comments</option>
              <option value="oldest" ${this.adminState.sort === 'oldest' ? 'selected' : ''}>Oldest Comments</option>
            `;
            box.innerHTML = `
              ${this.renderAdminToolbar('Search comments, artists, or artworks...', sortOptions)}
              <div class="data-table-wrap">
                <table class="data-table">
                  <thead>
                    <tr>
                      <th>ID</th>
                      <th>Author</th>
                      <th>Artwork Post</th>
                      <th>Comment Content</th>
                      <th>Date</th>
                      <th>Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    ${res.comments.map(c => `
                      <tr>
                        <td>#${c.id}</td>
                        <td>${this.escape(c.artist_name)}</td>
                        <td><a href="#/artwork/${c.artwork_id}" style="color:var(--accent);font-weight:600;">${this.escape(c.art_title)}</a></td>
                        <td><div style="max-width:320px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${this.escape(c.comment)}</div></td>
                        <td>${new Date(c.created_at * 1000).toLocaleDateString()}</td>
                        <td>
                          <button class="btn-subtle" style="height:26px;font-size:0.7rem;color:var(--r18);" onclick="app.deleteComment(${c.id})">Delete</button>
                        </td>
                      </tr>
                    `).join('')}
                  </tbody>
                </table>
              </div>
              ${this.renderAdminPagination(res.page, res.pages, res.total)}
            `;
          } else if (tab === 'sys') {
            box.innerHTML = `
              <div style="background:var(--bg-surface); padding:1.4rem; border:1px solid var(--border-subtle); border-radius:14px; font-size:0.88rem; line-height:1.8;">
                <div><strong>Application Suite:</strong> HDPost</div>
                <div><strong>Server PHP Environment:</strong> <?= PHP_VERSION ?></div>
                <div><strong>SQLite Database Mode:</strong> SQLite3 (WAL Journal Mode)</div>
                <div><strong>Perceptual Hash Algorithm:</strong> 64-bit DCT/Average Hash (Hamming distance threshold 18)</div>
                <div><strong>Current Admin Level:</strong> ${isSuper ? 'Super Administrator (Master)' : 'Administrator'}</div>
                <div><strong>Storage Directory:</strong> <?= htmlspecialchars(str_replace('\\', '/', $config['data_dir'])) ?></div>
              </div>
            `;
          }
        }

        async adminAppointExistingUser(userId) {
          if (!confirm('Promote this artist account to Administrator?')) return;
          try {
            const res = await this.api('admin_add_admin', { user_id: userId }, 'POST');
            this.toast(`Successfully appointed ${res.artist_name} as Administrator.`);
            this.adminSwitchTab('users');
          } catch(e) {
            this.toast(e.message);
          }
        }

        async adminToggleBan(userId) {
          try {
            const res = await this.api('admin_toggle_ban', { user_id: userId }, 'POST');
            this.toast(res.is_banned ? 'User account banned.' : 'User account unbanned.');
            this.switchAdminTab('users', this.adminState.page);
          } catch(e) {
            this.toast(e.message);
          }
        }

        async adminToggleRole(userId) {
          try {
            const res = await this.api('admin_toggle_role', { user_id: userId }, 'POST');
            this.toast(res.is_admin ? 'User promoted to administrator.' : 'User demoted.');
            this.switchAdminTab('users', this.adminState.page);
          } catch(e) {
            this.toast(e.message);
          }
        }

        async adminDeleteUser(userId) {
          if (!confirm('Are you sure you want to delete this user and all associated artworks?')) return;
          try {
            await this.api('admin_delete_user', { user_id: userId }, 'POST');
            this.toast('User removed.');
            this.switchAdminTab('users', this.adminState.page);
          } catch(e) {
            this.toast(e.message);
          }
        }

        async adminDeletePost(artworkId) {
          if (!confirm('Permanently delete this artwork?')) return;
          try {
            await this.api('artwork_delete', { id: artworkId }, 'POST');
            this.toast('Post deleted.');
            this.switchAdminTab('art', this.adminState.page);
          } catch(e) {
            this.toast(e.message);
          }
        }
  
        async renderArtworkView(id) {
          const container = document.getElementById('page-container');
          container.innerHTML = '<div class="spinner"></div>';
  
          try {
            const art = await this.api('artwork_get', { id });
            this.setTitle(`${art.title} by ${art.artist_name}`);
            this.currentArt = art;
            this.currentLeadIndex = 0;
            const isOwner = this.user && (this.user.id == art.user_id || this.user.is_admin);
            const avatarUrl = this.getAvatar(art.avatar, art.artist_name, art.email_hash);
  
            // Separate by comma only (spaces preserved inside names)
            const tagsArr = (art.tag_list && art.tag_list.length) ? art.tag_list : (art.tags ? art.tags.split(/[,，、]+/).map(s => s.trim()).filter(Boolean) : []);
            const charArr = art.characters ? art.characters.split(/[,，、]+/).map(s => s.trim()).filter(Boolean) : [];
            const parodyArr = art.parodies ? art.parodies.split(/[,，、]+/).map(s => s.trim()).filter(Boolean) : [];
            const toolsArr = art.tools ? art.tools.split(/[,，、]+/).map(s => s.trim()).filter(Boolean) : [];
  
            const images = art.images || [];
            const leadImg = images[0] || {};
            const isLeadVid = art.type === 'video' || (leadImg.mime_type && leadImg.mime_type.startsWith('video/'));
  
            // Previous/next post IDs for touch swipe and keyboard navigation
            const prevPostId = (art.type === 'manga' && art.series_prev) ? art.series_prev.id : (art.prev_id || null);
            const nextPostId = (art.type === 'manga' && art.series_next) ? art.series_next.id : (art.next_id || null);
            this.currentPrevPostId = prevPostId;
            this.currentNextPostId = nextPostId;

            let mediaHtml = '';
            if (images.length > 1) {
              const firstIsVid = (leadImg.mime_type && leadImg.mime_type.startsWith('video/')) || /\.(mp4|webm|mov|mkv|ogg)$/i.test(leadImg.file_name || '');
              mediaHtml = `
                <div class="viewer-media-wrap" id="artwork-media-container" style="display:flex; flex-direction:column; gap:0.75rem;">
                  <div style="display:flex; justify-content:space-between; align-items:center; padding:0.1rem 0.2rem; flex-wrap:wrap; gap:0.5rem;">
                    <span id="page-indicator-text" style="font-size:0.85rem; font-weight:600; color:var(--text-secondary);">Page 1 of ${images.length}</span>
                    <div style="display:flex; gap:0.45rem; align-items:center;">
                      <button type="button" class="btn-subtle" style="height:32px; font-size:0.78rem; gap:0.35rem;" onclick="app.downloadArtworkZip(${art.id})">
                        <svg viewBox="0 0 24 24" style="width:14px;height:14px;"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>
                        <span>Download ZIP</span>
                      </button>
                      <button class="btn-primary" id="btn-see-all-toggle" style="height:32px; font-size:0.78rem; gap:0.4rem;" onclick="app.toggleSeeAllPages()">
                        <svg viewBox="0 0 24 24" style="width:15px;height:15px;"><path d="M4 6H2v14c0 1.1.9 2 2 2h14v-2H4V6zm16-4H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H8V4h12v12z"/></svg>
                        <span>See All (${images.length} Pages)</span>
                      </button>
                    </div>
                  </div>

                  <div id="single-page-preview-box" style="display:flex; flex-direction:column; background:#000; border-radius:16px; overflow:hidden; border:1px solid var(--border-subtle); box-shadow:var(--shadow-md); width:100%; position:relative;">
                    <div id="preview-media-inner" style="display:flex; justify-content:center; position:relative; align-items:center; width:100%; background:#08080a; ${firstIsVid ? '' : 'cursor:pointer;'}" data-file="${this.escape(leadImg.file_name || '')}" ${firstIsVid ? '' : 'onclick="app.toggleHdOriginal(this)"'}>
                      ${firstIsVid ? `
                        <video controls autoplay loop playsinline style="width:100%; height:auto; display:block; background:#000;">
                          <source src="?action=raw&f=${encodeURIComponent(leadImg.file_name)}" type="${leadImg.mime_type || 'video/mp4'}">
                        </video>
                      ` : `
                        <div class="spinner" id="preview-loading-spinner" style="position:absolute; margin:auto; display:none;"></div>
                        <img id="main-artwork-display" src="?action=thumb&f=${encodeURIComponent(leadImg.file_name || '')}"
                             data-raw="?action=raw&f=${encodeURIComponent(leadImg.file_name || '')}"
                             data-loaded="0"
                             onerror="this.onerror=null; this.src='?action=raw&f=${encodeURIComponent(leadImg.file_name || '')}';"
                             style="width:100%; height:auto; display:block; opacity:1; transition:opacity 0.2s ease-in-out;" alt="">
                        <div id="hd-indicator-badge" style="position:absolute; bottom:12px; right:12px; background:rgba(0,0,0,0.72); backdrop-filter:blur(4px); color:#fff; font-size:0.72rem; font-weight:700; padding:0.25rem 0.6rem; border-radius:6px; border:1px solid rgba(255,255,255,0.2); pointer-events:none;">
                          Tap for Original HD
                        </div>
                      `}
                    </div>
                  </div>

                  <div class="thumb-reel" id="thumb-reel-strip">
                    ${images.map((img, idx) => `
                      <div class="thumb-reel-item ${idx === 0 ? 'active' : ''}" onclick="app.switchLeadImage(${idx})" id="reel-item-${idx}">
                        <img src="?action=thumb&f=${encodeURIComponent(img.file_name)}" alt="" onerror="this.onerror=null; this.src='?action=raw&f=${encodeURIComponent(img.file_name)}'">
                        <div class="thumb-eye-overlay">
                          <svg viewBox="0 0 24 24"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>
                        </div>
                      </div>
                    `).join('')}
                  </div>

                  <div id="multi-page-expanded-container" class="multi-page-expanded-stack" style="display:none;">
                    ${images.map((img, idx) => {
                      const isV = (img.mime_type && img.mime_type.startsWith('video/')) || /\.(mp4|webm|mov|mkv|ogg)$/i.test(img.file_name);
                      return `
                        <div class="multi-page-item">
                          <div style="width:100%; padding:0.5rem 1rem; background:var(--bg-surface-elevated); font-size:0.8rem; font-weight:700; color:var(--text-secondary); display:flex; justify-content:space-between; align-items:center;">
                            <span>Page #${idx + 1}</span>
                            <a href="?action=raw&f=${encodeURIComponent(img.file_name)}" download class="btn-subtle" style="height:28px; font-size:0.75rem; padding:0 0.65rem;">Download</a>
                          </div>
                          ${isV ? `
                            <video controls preload="metadata" loop playsinline style="width:100%; height:auto; display:block; background:#000;">
                              <source src="?action=raw&f=${encodeURIComponent(img.file_name)}" type="${img.mime_type || 'video/mp4'}">
                            </video>
                          ` : `
                            <a href="?action=raw&f=${encodeURIComponent(img.file_name)}" target="_blank" rel="noopener noreferrer" style="display:block; width:100%; cursor:zoom-in;" title="Tap to view full original image">
                              <img src="?action=raw&f=${encodeURIComponent(img.file_name)}" alt="" loading="lazy" style="width:100%; height:auto; display:block;">
                            </a>
                          `}
                        </div>
                      `;
                    }).join('')}
                  </div>
                </div>
              `;
            } else if (isLeadVid) {
              mediaHtml = `
                <div class="viewer-media-wrap" id="artwork-media-container" style="display:flex; flex-direction:column; background:#000; border-radius:16px; overflow:hidden; border:1px solid var(--border-subtle); box-shadow:var(--shadow-md); width:100%; position:relative;">
                  <video controls autoplay loop playsinline style="width:100%; height:auto; display:block; background:#000;">
                    <source src="?action=raw&f=${encodeURIComponent(leadImg.file_name)}" type="${leadImg.mime_type || 'video/mp4'}">
                    Your browser does not support HTML5 video playback.
                  </video>
                </div>
              `;
            } else {
              mediaHtml = `
                <div class="viewer-media-wrap" id="artwork-media-container" style="display:flex; flex-direction:column; background:#000; border-radius:16px; overflow:hidden; border:1px solid var(--border-subtle); box-shadow:var(--shadow-md); width:100%; position:relative;">
                  <div style="display:flex; justify-content:center; position:relative; align-items:center; width:100%; background:#08080a; cursor:pointer;" data-file="${this.escape(leadImg.file_name || '')}" onclick="app.toggleHdOriginal(this)">
                    <div class="spinner" id="preview-loading-spinner" style="position:absolute; margin:auto; display:none;"></div>
                    <img id="main-artwork-display" src="?action=thumb&f=${encodeURIComponent(leadImg.file_name || '')}"
                         data-raw="?action=raw&f=${encodeURIComponent(leadImg.file_name || '')}"
                         data-loaded="0"
                         onerror="this.onerror=null; this.src='?action=raw&f=${encodeURIComponent(leadImg.file_name || '')}';"
                         style="width:100%; height:auto; display:block; opacity:1; transition:opacity 0.2s ease-in-out;"
                         alt="">
                    <div id="hd-indicator-badge" style="position:absolute; bottom:12px; right:12px; background:rgba(0,0,0,0.72); backdrop-filter:blur(4px); color:#fff; font-size:0.72rem; font-weight:700; padding:0.25rem 0.6rem; border-radius:6px; border:1px solid rgba(255,255,255,0.2); pointer-events:none;">
                      Tap for Original HD
                    </div>
                  </div>
                </div>
              `;
            }
  
            let renderedDescription = '';
            if (art.description) {
              try {
                if (typeof marked !== 'undefined' && typeof DOMPurify !== 'undefined') {
                  renderedDescription = DOMPurify.sanitize(marked.parse(art.description));
                } else {
                  renderedDescription = this.escape(art.description).replace(/\n/g, '<br>');
                }
              } catch (e) {
                renderedDescription = this.escape(art.description).replace(/\n/g, '<br>');
              }
            }
  
            let mangaSeriesHtml = '';
            if (art.type === 'manga' && art.manga_series && art.manga_series.length) {
              const prevEp = art.series_prev;
              const nextEp = art.series_next;
              mangaSeriesHtml = `
                <div class="manga-series-card">
                  <div class="manga-series-header">
                    <div style="display:flex; align-items:center; gap:0.4rem; min-width:0; flex:1;">
                      <span class="manga-series-badge">Series</span>
                      <span style="font-weight:700; font-size:0.82rem; color:var(--text-primary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="${this.escape(art.series_title)}">${this.escape(art.series_title)}</span>
                      <span style="font-size:0.75rem; color:var(--text-muted); font-weight:600; flex-shrink:0;">(${art.series_index}/${art.series_total})</span>
                    </div>
                    <button type="button" class="btn-subtle" style="height:24px; padding:0 0.5rem; font-size:0.7rem; gap:0.25rem; flex-shrink:0;" onclick="app.showMangaSeriesModal()" title="View All Episodes">
                      <svg viewBox="0 0 24 24" style="width:11px;height:11px;"><path d="M4 6H2v14c0 1.1.9 2 2 2h14v-2H4V6zm16-4H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H8V4h12v12z"/></svg>
                      <span>All (${art.series_total})</span>
                    </button>
                  </div>

                  <div class="manga-series-nav-row">
                    ${nextEp ? `
                      <a href="#/artwork/${nextEp.id}" class="manga-series-nav-btn" title="Next: ${this.escape(nextEp.title)}">&larr; Next</a>
                    ` : `
                      <div class="manga-series-nav-btn disabled">&larr; Next</div>
                    `}

                    ${prevEp ? `
                      <a href="#/artwork/${prevEp.id}" class="manga-series-nav-btn" title="Previous: ${this.escape(prevEp.title)}">Prev &rarr;</a>
                    ` : `
                      <div class="manga-series-nav-btn disabled">Prev &rarr;</div>
                    `}
                  </div>
                </div>
              `;
            }

            let html = `
              <div class="viewer-layout">
                <div class="viewer-main">
                  ${mediaHtml}
  
                  <div class="viewer-info-card" style="background:var(--bg-surface); border:1px solid var(--border-subtle); border-radius:14px; padding:1.4rem; display:flex; flex-direction:column; gap:0.9rem;">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:0.8rem; flex-wrap:wrap;">
                      <div style="min-width:0; flex:1;">
                        <h1 style="font-size:1.5rem; font-weight:800; letter-spacing:-0.5px;">${this.escape(art.title)}</h1>
                        <div style="font-size:0.8rem; color:var(--text-muted); margin-top:0.3rem;">
                          Posted ${new Date(art.created_at * 1000).toLocaleDateString()} &bull; ${(art.view_count || 0).toLocaleString()} views &bull; ${art.like_count || 0} likes
                        </div>
                      </div>
                      ${isOwner ? `
                        <div class="artwork-owner-actions">
                          <button type="button" class="btn-subtle" style="gap:0.35rem;" onclick="app.exportArtworkPost(${art.id})" title="Export Post Package (.zip) with live progress">
                            <svg viewBox="0 0 24 24" style="width:14px;height:14px;"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>
                            <span>Export Post (.zip)</span>
                          </button>
                          <button class="btn-subtle" onclick="app.nav('#/edit/${art.id}')">Edit Post</button>
                          <button class="btn-subtle" style="color:var(--r18);" onclick="app.deleteArtwork(${art.id})">Delete</button>
                        </div>
                    ` : ''}
                    </div>

                    ${art.source_url ? (() => {
                      const urls = art.source_url.split(/[\r\n,\s]+/).map(u => u.trim()).filter(Boolean);
                      if (!urls.length) return '';
                      return `
                        <div style="font-size:0.85rem; color:var(--text-muted); display:flex; flex-wrap:wrap; gap:0.55rem; align-items:center;">
                          <strong>Original Source${urls.length > 1 ? 's' : ''}:</strong>
                          ${urls.map((u, i) => {
                            let domain = '';
                            try { domain = new URL(u).hostname.replace(/^www\./, ''); } catch(e) { domain = `Link #${i + 1}`; }
                            return `
                              <a href="${this.safeUrl(u)}" target="_blank" rel="noopener noreferrer" style="color:var(--accent); text-decoration:underline; display:inline-flex; align-items:center; gap:0.25rem;" title="${this.escape(u)}">
                                <svg viewBox="0 0 24 24" style="width:13px;height:13px;"><path d="M19 19H5V5h7V3H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2v-7h-2v7zM14 3v2h3.59l-9.83 9.83 1.41 1.41L19 6.41V10h2V3h-7z"/></svg>
                                <span>${urls.length > 1 ? `${domain} (#${i + 1})` : this.escape(u)}</span>
                              </a>
                            `;
                          }).join('')}
                        </div>
                      `;
                    })() : ''}

                    ${renderedDescription ? `<div style="font-size:0.92rem; line-height:1.6; color:var(--text-primary);">${renderedDescription}</div>` : ''}

                    <div class="tag-cloud">
                      ${parodyArr.map(p => `
                        <span class="tag-pill special-parody" onclick="app.nav('#/explore?parody=${encodeURIComponent(p)}')">
                          <svg viewBox="0 0 24 24"><path d="M21 3H3c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h5v2h8v-2h5c1.1 0 1.99-.9 1.99-2L23 5c0-1.1-.9-2-2-2zm0 14H3V5h18v12z"/></svg>
                          <span>Series: ${this.escape(p)}</span>
                          <span style="opacity:0.6; padding-left:2px;" onclick="event.stopPropagation(); app.openEncyclopediaModal('parody', '${this.escape(p)}')" title="View Encyclopedia">&#128214;</span>
                        </span>
                      `).join('')}
                      ${charArr.map(c => `
                        <span class="tag-pill special-character" onclick="app.nav('#/explore?character=${encodeURIComponent(c)}')">
                          <svg viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
                          <span>Character: ${this.escape(c)}</span>
                          <span style="opacity:0.6; padding-left:2px;" onclick="event.stopPropagation(); app.openEncyclopediaModal('character', '${this.escape(c)}')" title="View Encyclopedia">&#128214;</span>
                        </span>
                      `).join('')}
                      ${tagsArr.map(t => `
                        <span class="tag-pill" onclick="app.nav('#/explore?tag=${encodeURIComponent(t)}')">
                          <svg viewBox="0 0 24 24"><path d="M21.41 11.58l-9-9C12.05 2.22 11.55 2 11 2H4c-1.1 0-2 .9-2 2v7c0 .55.22 1.05.59 1.42l9 9c.36.36.86.58 1.41.58.55 0 1.05-.22 1.41-.59l7-7c.37-.36.59-.86.59-1.41 0-.55-.23-1.06-.59-1.42zM5.5 7C4.67 7 4 6.33 4 5.5S4.67 4 5.5 4 7 4.67 7 5.5 6.33 7 5.5 7z"/></svg>
                          <span>#${this.escape(t)}</span>
                          <span style="opacity:0.6; padding-left:2px;" onclick="event.stopPropagation(); app.openEncyclopediaModal('tag', '${this.escape(t)}')" title="View Encyclopedia">&#128214;</span>
                        </span>
                      `).join('')}
                      ${toolsArr.map(tl => `<span class="tag-pill special-tool"><svg viewBox="0 0 24 24"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg> <span>Tool: ${this.escape(tl)}</span></span>`).join('')}
                    </div>

                    <div style="display:flex; gap:0.6rem; margin-top:0.4rem; flex-wrap:wrap;">
                      <button class="btn-subtle ${art.user_liked ? 'active like' : ''}" style="gap:0.4rem;" onclick="app.toggleLike(${art.id}, this)">
                        <svg viewBox="0 0 24 24"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>
                        <span>Like (${art.like_count || 0})</span>
                      </button>
                      ${!isLeadVid && art.type !== 'video' ? `
                        <button type="button" class="btn-subtle" style="gap:0.4rem;" onclick="app.nav('#/similar?source_id=${art.id}&image_index=' + app.currentLeadIndex)" title="Find visually similar artworks using this image">
                          <svg viewBox="0 0 24 24" style="width:16px;height:16px;"><path d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z"/></svg>
                          <span>Find Similar</span>
                        </button>
                      ` : ''}
                      <button class="btn-subtle" style="gap:0.4rem;" data-id="${art.id}" data-title="${this.escape(art.title)}" data-artist="${this.escape(art.artist_name)}" onclick="app.showShareModal(this.dataset.id, this.dataset.title, this.dataset.artist)">
                        <svg viewBox="0 0 24 24" style="width:16px;height:16px;"><path d="M18 16.08c-.76 0-1.44.3-1.96.77L8.91 12.7c.05-.23.09-.46.09-.7s-.04-.47-.09-.7l7.05-4.11c.54.5 1.25.81 2.04.81 1.66 0 3-1.34 3-3s-1.34-3-3-3-3 1.34-3 3c0 .24.04.47.09.7L8.04 9.81C7.5 9.31 6.79 9 6 9c-1.66 0-3 1.34-3 3s1.34 3 3 3c.79 0 1.5-.31 2.04-.81l7.12 4.16c-.05.21-.08.43-.08.65 0 1.61 1.31 2.92 2.92 2.92s2.92-1.31 2.92-2.92c0-1.61-1.31-2.92-2.92-2.92z"/></svg>
                        <span>Share</span>
                      </button>
                      ${images.length > 1 ? `
                        <button type="button" class="btn-subtle" style="gap:0.4rem;" onclick="app.downloadArtworkZip(${art.id})">
                          <svg viewBox="0 0 24 24" style="width:16px;height:16px;"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>
                          <span>Download All (${images.length}P ZIP)</span>
                        </button>
                      ` : `
                        <a href="?action=raw&f=${encodeURIComponent(leadImg.file_name || '')}" download class="btn-subtle" style="gap:0.4rem;">
                          <svg viewBox="0 0 24 24" style="width:16px;height:16px;"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>
                          <span>Download</span>
                        </a>
                      `}
                    </div>
                  </div>
  
                  <div class="viewer-comments-card" style="background:var(--bg-surface); border:1px solid var(--border-subtle); border-radius:14px; padding:1.4rem; display:flex; flex-direction:column; gap:1rem;">
                    <h3 style="font-size:1.1rem; font-weight:700;">Artist Commentary &amp; Responses (${art.raw_comments_count || 0})</h3>
                    ${this.user ? `
                      <form onsubmit="app.handleCommentSubmit(event, ${art.id}, 0)" style="display:flex; flex-direction:column; gap:0.6rem;">
                        <textarea name="comment" class="form-textarea" placeholder="Leave constructive praise and thoughts for the artist..." required></textarea>
                        <div style="display:flex; justify-content:flex-end;">
                          <button type="submit" class="btn-primary">Post Response</button>
                        </div>
                      </form>
                    ` : `<div style="font-size:0.85rem; color:var(--text-muted);"><a href="javascript:;" style="color:var(--accent);" onclick="app.showAuthModal('login')">Log in</a> to leave commentary.</div>`}
  
                    <div style="display:flex; flex-direction:column; gap:0.85rem;" id="comments-list">
                      ${art.comments.map(c => this.renderCommentNode(c, art.id)).join('')}
                    </div>
                  </div>
                </div>
  
                <div class="viewer-sidebar">
                  <div class="author-card">
                    <div class="author-header">
                      <img src="${avatarUrl}" class="author-avatar-lg" alt="" data-artist-name="${this.escape(art.artist_name)}" onerror="app.handleAvatarError(this)">
                      <div class="author-names">
                        <span class="author-artist-name">${this.escape(art.artist_name)}</span>
                      </div>
                    </div>
                    ${art.bio ? `<p style="font-size:0.82rem; color:var(--text-secondary); line-height:1.45;">${this.escape(art.bio)}</p>` : ''}
                    ${this.user && this.user.id == art.user_id ? '' : `
                      <button class="btn-primary" style="width:100%; background:${art.is_following ? 'var(--bg-surface-hover)' : 'var(--accent)'}; color:${art.is_following ? 'var(--text-primary)' : '#fff'};" onclick="app.toggleFollow(${art.user_id}, this)">
                        ${art.is_following ? '<svg viewBox="0 0 24 24" style="width:16px;height:16px;margin-right:4px;"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg> Following' : '<svg viewBox="0 0 24 24" style="width:16px;height:16px;margin-right:4px;"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg> Follow Artist'}
                      </button>
                    `}
                    <button class="btn-subtle" style="width:100%; margin-top:0.35rem;" onclick="app.nav('#/user/${art.user_id}')">View All Works</button>
                  </div>

                  ${mangaSeriesHtml}

                  <div style="background:var(--bg-surface); border:1px solid var(--border-subtle); border-radius:14px; padding:1rem; display:flex; flex-direction:column; gap:0.6rem;">
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                      <span style="font-weight:700; font-size:0.88rem;">More from ${this.escape(art.artist_name)}</span>
                      <span style="font-size:0.75rem; color:var(--accent); cursor:pointer;" onclick="app.nav('#/user/${art.user_id}')">All &rarr;</span>
                    </div>
                    <div id="artist-carousel-track" style="display:flex; gap:0.65rem; overflow-x:auto; padding:0.3rem 0.1rem; scroll-behavior:smooth; -webkit-overflow-scrolling:touch;">
                      <div class="spinner" style="margin:1.5rem auto; width:28px; height:28px;"></div>
                    </div>
                  </div>
                </div>
              </div>
            `;
            container.innerHTML = html;
            this.initArtistCarousel(art.user_id, art.id);
            this.initMediaSwipe(prevPostId, nextPostId);
          } catch(err) {
            container.innerHTML = `<div class="center-msg">${err.message}</div>`;
          }
        }
  
        showMangaSeriesModal() {
          if (!this.currentArt || !this.currentArt.manga_series) return;
          const art = this.currentArt;
          const series = art.manga_series;
          const html = `
            <div class="modal-header">
              <div style="display:flex; align-items:center; gap:0.6rem;">
                <span class="manga-series-badge">Series</span>
                <span>${this.escape(art.series_title)}</span>
                <span style="font-size:0.8rem; color:var(--text-muted); font-weight:600;">(${series.length} works)</span>
              </div>
              <button class="btn-icon" onclick="app.closeModal()"><svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg></button>
            </div>
            <div class="modal-body">
              <div class="manga-series-episodes-sheet">
                ${series.map((item, idx) => {
                  const isCurrent = Number(item.id) === Number(art.id);
                  return `
                    <div class="manga-series-episode-item ${isCurrent ? 'current' : ''}" onclick="app.closeModal(); app.nav('#/artwork/${item.id}')">
                      <img src="?action=thumb&f=${encodeURIComponent(item.cover_file || '')}" class="manga-series-episode-thumb" alt="" onerror="this.onerror=null; this.src='?action=raw&f=${encodeURIComponent(item.cover_file || '')}'">
                      <div style="flex:1; min-width:0;">
                        <div style="display:flex; align-items:center; gap:0.5rem; margin-bottom:0.2rem;">
                          <span style="font-weight:700; font-size:0.75rem; color:${isCurrent ? '#f97316' : 'var(--text-muted)'};">#${idx + 1}</span>
                          ${isCurrent ? '<span style="background:#f97316; color:#fff; font-size:0.65rem; font-weight:800; padding:0.1rem 0.4rem; border-radius:4px;">READING</span>' : ''}
                        </div>
                        <div style="font-weight:700; font-size:0.9rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${this.escape(item.title)}</div>
                        <div style="font-size:0.75rem; color:var(--text-muted); margin-top:0.2rem;">${item.page_count || 1}P &bull; ${new Date(item.created_at * 1000).toLocaleDateString()}</div>
                      </div>
                    </div>
                  `;
                }).join('')}
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn-subtle" onclick="app.closeModal()">Close</button>
            </div>
          `;
          this.showModal(html);
        }

        showImportModal() {
          const html = `
            <div class="modal-header">
              <span>Import Post Package</span>
              <button class="btn-icon" onclick="app.closeModal()"><svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg></button>
            </div>
            <form onsubmit="app.handleImportSubmit(event)">
              <div class="modal-body" style="gap:1rem;">
                <p style="font-size:0.84rem; color:var(--text-secondary); line-height:1.5;">
                  Select an exported post package (containing <code>info.json</code> and <code>items/</code>) to restore all artwork metadata, multi-page media, and tags.
                </p>

                <div class="import-drop-zone" id="import-drop-box" onclick="document.getElementById('import-file-elem').click()">
                  <svg viewBox="0 0 24 24" style="width:36px; height:36px; color:var(--accent);"><path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM14 13v4h-4v-4H7l5-5 5 5h-3z"/></svg>
                  <span style="font-weight:700; font-size:0.9rem;">Click to choose or drag &amp; drop file</span>
                  <span style="font-size:0.75rem; color:var(--text-muted);">Supports .zip packages &amp; .json exports</span>
                  <input type="file" id="import-file-elem" name="import_file" accept=".zip,.json,application/zip,application/json" style="display:none;" onchange="app.handleImportFileSelect(this)" required>
                </div>

                <div id="import-file-details" style="display:none; align-items:center; justify-content:space-between; background:var(--bg-surface-elevated); border:1px solid var(--border-subtle); border-radius:10px; padding:0.6rem 0.85rem;">
                  <div style="display:flex; align-items:center; gap:0.55rem; min-width:0;">
                    <svg viewBox="0 0 24 24" style="width:18px; height:18px; color:#10b981; flex-shrink:0;"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-5 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/></svg>
                    <span id="import-chosen-name" style="font-weight:600; font-size:0.82rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"></span>
                  </div>
                  <span id="import-chosen-size" style="font-size:0.75rem; color:var(--text-muted); flex-shrink:0;"></span>
                </div>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn-subtle" onclick="app.closeModal()">Cancel</button>
                <button type="submit" class="btn-primary" id="btn-import-submit">Import Post</button>
              </div>
            </form>
          `;
          this.showModal(html);

          const dropBox = document.getElementById('import-drop-box');
          const fileInput = document.getElementById('import-file-elem');
          if (dropBox && fileInput) {
            dropBox.ondragover = (e) => { e.preventDefault(); dropBox.classList.add('dragover'); };
            dropBox.ondragleave = () => dropBox.classList.remove('dragover');
            dropBox.ondrop = (e) => {
              e.preventDefault();
              dropBox.classList.remove('dragover');
              if (e.dataTransfer.files.length) {
                fileInput.files = e.dataTransfer.files;
                this.handleImportFileSelect(fileInput);
              }
            };
          }
        }

        handleImportFileSelect(input) {
          const file = input.files && input.files[0];
          const detailsBox = document.getElementById('import-file-details');
          const nameSpan = document.getElementById('import-chosen-name');
          const sizeSpan = document.getElementById('import-chosen-size');

          if (file && detailsBox && nameSpan && sizeSpan) {
            nameSpan.innerText = file.name;
            const sizeKB = (file.size / 1024).toFixed(1);
            sizeSpan.innerText = file.size > 1048576 ? `${(file.size / 1048576).toFixed(1)} MB` : `${sizeKB} KB`;
            detailsBox.style.display = 'flex';
          }
        }

        async handleImportSubmit(e) {
          e.preventDefault();
          const btn = document.getElementById('btn-import-submit');
          if (btn) {
            btn.disabled = true;
            btn.innerText = 'Importing...';
          }
          const fd = new FormData(e.target);
          try {
            const res = await this.api('artwork_import', fd, 'POST');
            this.closeModal();
            this.toast('Post imported successfully!');
            this.nav(`#/artwork/${res.artwork_id}`);
          } catch(err) {
            this.toast(err.message);
            if (btn) {
              btn.disabled = false;
              btn.innerText = 'Import Post';
            }
          }
        }

        renderMediaContent(imgObj) {
          if (!imgObj || !imgObj.file_name) return '';
          const isV = (imgObj.mime_type && imgObj.mime_type.startsWith('video/')) || /\.(mp4|webm|mov|mkv|ogg)$/i.test(imgObj.file_name);
          if (isV) {
            return `
              <video controls autoplay loop playsinline style="width:100%; max-height:85dvh; object-fit:contain; background:#000;">
                <source src="?action=raw&f=${encodeURIComponent(imgObj.file_name)}" type="${imgObj.mime_type || 'video/mp4'}">
              </video>
            `;
          }
          return `
            <img id="main-artwork-display" src="?action=raw&f=${encodeURIComponent(imgObj.file_name)}" style="max-width:100%; max-height:85dvh; object-fit:contain;" alt="">
          `;
        }

        switchLeadImage(idx) {
          if (!this.currentArt || !this.currentArt.images || !this.currentArt.images[idx]) return;
          this.currentLeadIndex = idx;
          const imgObj = this.currentArt.images[idx];
          const previewInner = document.getElementById('preview-media-inner');
          const isV = (imgObj.mime_type && imgObj.mime_type.startsWith('video/')) || /\.(mp4|webm|mov|mkv|ogg)$/i.test(imgObj.file_name);

          if (previewInner) {
            previewInner.dataset.file = imgObj.file_name || '';
            if (isV) {
              previewInner.onclick = null;
              previewInner.style.cursor = 'default';
              previewInner.innerHTML = `
                <video controls autoplay loop playsinline style="width:100%; height:auto; display:block; background:#000;">
                  <source src="?action=raw&f=${encodeURIComponent(imgObj.file_name)}" type="${imgObj.mime_type || 'video/mp4'}">
                </video>
              `;
            } else {
              previewInner.onclick = () => this.toggleHdOriginal(previewInner);
              previewInner.style.cursor = 'pointer';
              previewInner.innerHTML = `
                <div class="spinner" id="preview-loading-spinner" style="position:absolute; margin:auto; display:none;"></div>
                <img id="main-artwork-display" src="?action=thumb&f=${encodeURIComponent(imgObj.file_name)}"
                     data-raw="?action=raw&f=${encodeURIComponent(imgObj.file_name)}"
                     data-loaded="0"
                     style="width:100%; height:auto; display:block; opacity:1; transition:opacity 0.2s ease-in-out;"
                     onerror="this.onerror=null; this.src='?action=raw&f=${encodeURIComponent(imgObj.file_name)}';" alt="">
                <div id="hd-indicator-badge" style="position:absolute; bottom:12px; right:12px; background:rgba(0,0,0,0.72); backdrop-filter:blur(4px); color:#fff; font-size:0.72rem; font-weight:700; padding:0.25rem 0.6rem; border-radius:6px; border:1px solid rgba(255,255,255,0.2); pointer-events:none;">
                  Tap for Original HD
                </div>
              `;
            }
          }

          const textIndicator = document.getElementById('page-indicator-text');
          if (textIndicator) {
            textIndicator.innerHTML = `Page ${idx + 1} of ${this.currentArt.images.length}`;
          }
          document.querySelectorAll('.thumb-reel-item').forEach((el, i) => {
            el.classList.toggle('active', i === idx);
          });
          const activeReelEl = document.getElementById(`reel-item-${idx}`);
          if (activeReelEl) {
            activeReelEl.scrollIntoView({ inline: 'center', block: 'nearest', behavior: 'smooth' });
          }
        }

        toggleHdOriginal(containerEl, fileName) {
          const targetFile = fileName || containerEl?.dataset?.file || this.getCurrentLeadFileName() || '';
          const img = document.getElementById('main-artwork-display');
          const badge = document.getElementById('hd-indicator-badge');
          const spinner = document.getElementById('preview-loading-spinner');
          if (!img || !targetFile) return;

          if (img.dataset.loaded === '1') {
            this.toast('Already viewing original resolution.');
            return;
          }

          if (spinner) spinner.style.display = 'block';
          if (badge) badge.innerText = 'Loading HD...';

          const fullImg = new Image();
          fullImg.src = `?action=raw&f=${encodeURIComponent(targetFile)}`;
          fullImg.onload = () => {
            img.src = fullImg.src;
            img.dataset.loaded = '1';
            if (spinner) spinner.style.display = 'none';
            if (badge) {
              badge.innerText = 'Original HD';
              badge.style.borderColor = 'var(--accent)';
              badge.style.color = 'var(--accent)';
              setTimeout(() => { if (badge) badge.style.opacity = '0'; }, 2000);
            }
            this.toast('Loaded original resolution.');
          };
          fullImg.onerror = () => {
            if (spinner) spinner.style.display = 'none';
            if (badge) badge.innerText = 'Tap for Original HD';
            this.toast('Failed to load original image.');
          };
        }

        async downloadArtworkZip(artworkId) {
          const modalHtml = `
            <div class="modal-header">
              <span>Downloading Artwork ZIP</span>
              <button class="btn-icon" onclick="app.closeModal()"><svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg></button>
            </div>
            <div class="modal-body" style="gap:1rem;">
              <div style="display:flex; align-items:center; gap:0.75rem;">
                <div class="spinner" id="zip-spinner" style="margin:0; width:26px; height:26px; flex-shrink:0;"></div>
                <div style="min-width:0; flex:1;">
                  <div id="zip-status-title" style="font-weight:700; font-size:0.95rem;">Preparing ZIP Archive...</div>
                  <div id="zip-status-subtitle" style="font-size:0.8rem; color:var(--text-muted); margin-top:0.2rem;">Connecting to server...</div>
                </div>
              </div>

              <div style="width:100%; background:var(--bg-base); height:10px; border-radius:5px; overflow:hidden; border:1px solid var(--border-subtle);">
                <div id="zip-progress-fill" style="width:0%; height:100%; background:var(--accent); border-radius:5px; transition:width 0.15s ease;"></div>
              </div>

              <div style="display:flex; justify-content:space-between; font-size:0.8rem; color:var(--text-secondary);">
                <span id="zip-bytes-text">0 MB / 0 MB</span>
                <span id="zip-percent-text" style="font-weight:700; color:var(--accent);">0%</span>
              </div>
            </div>
          `;
          this.showModal(modalHtml);

          try {
            const res = await fetch(`?action=artwork_zip&id=${artworkId}`);
            if (!res.ok) {
              let errMsg = 'Failed to generate ZIP archive.';
              try {
                const errJson = await res.json();
                if (errJson.error) errMsg = errJson.error;
              } catch(e) {}
              throw new Error(errMsg);
            }

            const contentLength = res.headers.get('content-length');
            const totalBytes = contentLength ? parseInt(contentLength, 10) : 0;
            const reader = res.body.getReader();
            const chunks = [];
            let receivedBytes = 0;

            const statusTitle = document.getElementById('zip-status-title');
            const statusSubtitle = document.getElementById('zip-status-subtitle');
            const progressFill = document.getElementById('zip-progress-fill');
            const bytesText = document.getElementById('zip-bytes-text');
            const percentText = document.getElementById('zip-percent-text');

            if (statusTitle) statusTitle.innerText = 'Downloading images...';

            while (true) {
              const { done, value } = await reader.read();
              if (done) break;
              chunks.push(value);
              receivedBytes += value.length;

              if (totalBytes > 0) {
                const pct = Math.min(100, Math.round((receivedBytes / totalBytes) * 100));
                if (progressFill) progressFill.style.width = `${pct}%`;
                if (percentText) percentText.innerText = `${pct}%`;
                const recMB = (receivedBytes / (1024 * 1024)).toFixed(1);
                const totMB = (totalBytes / (1024 * 1024)).toFixed(1);
                if (bytesText) bytesText.innerText = `${recMB} MB / ${totMB} MB`;
                if (statusSubtitle) statusSubtitle.innerText = `${pct}% received`;
              } else {
                const recMB = (receivedBytes / (1024 * 1024)).toFixed(1);
                if (bytesText) bytesText.innerText = `${recMB} MB`;
                if (progressFill) progressFill.style.width = '100%';
              }
            }

            if (statusTitle) statusTitle.innerText = 'Finalizing package...';
            const blob = new Blob(chunks, { type: 'application/zip' });

            let downloadName = `artwork_${artworkId}.zip`;
            const dispo = res.headers.get('content-disposition');
            if (dispo) {
              const match = dispo.match(/filename\*?=(?:UTF-8'')?["']?([^"';]+)["']?/i);
              if (match && match[1]) downloadName = decodeURIComponent(match[1]);
            }

            const blobUrl = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = blobUrl;
            link.download = downloadName;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            setTimeout(() => URL.revokeObjectURL(blobUrl), 10000);

            if (statusTitle) statusTitle.innerText = 'Download Completed!';
            if (statusSubtitle) statusSubtitle.innerText = 'ZIP file saved to your device.';
            const spinner = document.getElementById('zip-spinner');
            if (spinner) spinner.style.display = 'none';

            setTimeout(() => this.closeModal(), 1200);
          } catch(err) {
            const statusTitle = document.getElementById('zip-status-title');
            const statusSubtitle = document.getElementById('zip-status-subtitle');
            const progressFill = document.getElementById('zip-progress-fill');
            if (statusTitle) {
              statusTitle.innerText = 'Download Failed';
              statusTitle.style.color = 'var(--r18)';
            }
            if (statusSubtitle) statusSubtitle.innerText = err.message;
            if (progressFill) progressFill.style.background = 'var(--r18)';
            this.toast(err.message);
          }
        }

        navigateToArtwork(targetId) {
          if (!targetId) return;
          this.nav(`#/artwork/${targetId}`);
          const vp = document.getElementById('viewport');
          if (vp) vp.scrollTop = 0;
        }

        initMediaSwipe(prevId, nextId) {
          const container = document.getElementById('artwork-media-container');
          if (!container) return;

          let startX = 0;
          let startY = 0;
          let startTime = 0;

          container.ontouchstart = (e) => {
            if (!e.changedTouches || !e.changedTouches[0]) return;
            startX = e.changedTouches[0].clientX;
            startY = e.changedTouches[0].clientY;
            startTime = Date.now();
          };

          container.ontouchend = (e) => {
            if (!e.changedTouches || !e.changedTouches[0]) return;
            const endX = e.changedTouches[0].clientX;
            const endY = e.changedTouches[0].clientY;
            const diffX = endX - startX;
            const diffY = endY - startY;
            const elapsed = Date.now() - startTime;

            // Swipe threshold: 45px horizontal, predominantly horizontal, within 600ms
            if (Math.abs(diffX) > 45 && Math.abs(diffX) > Math.abs(diffY) * 1.3 && elapsed < 600) {
              if (diffX < 0) {
                // Swipe Left -> Previous Post
                if (prevId) this.navigateToArtwork(prevId);
                else this.toast('No previous post.');
              } else if (diffX > 0) {
                // Swipe Right -> Next Post
                if (nextId) this.navigateToArtwork(nextId);
                else this.toast('No next post.');
              }
            }
          };
        }

        getCurrentLeadFileName() {
          if (this.currentArt && this.currentArt.images && this.currentArt.images[this.currentLeadIndex]) {
            return this.currentArt.images[this.currentLeadIndex].file_name;
          }
          return '';
        }
  
        toggleSeeAllPages() {
          const expandedStack = document.getElementById('multi-page-expanded-container');
          const singlePreview = document.getElementById('single-page-preview-box');
          const thumbReel = document.getElementById('thumb-reel-strip');
          const btn = document.getElementById('btn-see-all-toggle');
          if (!expandedStack || !btn) return;

          const isCurrentlyExpanded = expandedStack.style.display !== 'none';
          if (isCurrentlyExpanded) {
            expandedStack.querySelectorAll('video').forEach(v => v.pause());
            expandedStack.style.display = 'none';
            if (singlePreview) singlePreview.style.display = 'flex';
            if (thumbReel) thumbReel.style.display = 'flex';
            btn.innerHTML = `<svg viewBox="0 0 24 24" style="width:15px;height:15px;"><path d="M4 6H2v14c0 1.1.9 2 2 2h14v-2H4V6zm16-4H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H8V4h12v12z"/></svg> <span>See All (${this.currentArt.images.length} Pages)</span>`;
          } else {
            if (singlePreview) {
              singlePreview.querySelectorAll('video').forEach(v => v.pause());
              singlePreview.style.display = 'none';
            }
            expandedStack.querySelectorAll('video').forEach(v => v.pause());
            expandedStack.style.display = 'flex';
            if (thumbReel) thumbReel.style.display = 'none';
            btn.innerHTML = `<svg viewBox="0 0 24 24" style="width:15px;height:15px;"><path d="M19 13H5v-2h14v2z"/></svg> <span>Show First Page Only</span>`;
          }
        }
  
        toggleFullResolution(encodedFilename) {
          const img = document.getElementById('main-artwork-display');
          if (img) {
            img.src = `?action=raw&f=${encodedFilename}&t=${Date.now()}`;
            this.toast('Loading full uncompressed resolution...');
          }
        }
  
        renderCommentNode(c, artId) {
          const cAvatar = this.getAvatar(c.avatar, c.artist_name, c.email_hash);
          const canManage = this.user && (this.user.id == c.user_id || this.user.is_admin);
  
          return `
            <div class="comment-tree-node" id="comm-${c.id}">
              <img src="${cAvatar}" style="width:36px; height:36px; border-radius:50%; object-fit:cover; background:var(--bg-surface-elevated);" alt="" data-artist-name="${this.escape(c.artist_name)}" onerror="app.handleAvatarError(this)">
              <div style="flex:1;">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                  <span style="font-weight:700; font-size:0.85rem; cursor:pointer;" onclick="app.nav('#/user/${c.user_id}')">${this.escape(c.artist_name)}</span>
                  <span style="font-size:0.75rem; color:var(--text-muted);">${new Date(c.created_at * 1000).toLocaleDateString()}</span>
                </div>
                <p style="font-size:0.85rem; margin-top:0.25rem; color:var(--text-primary); white-space:pre-wrap;" id="comm-text-${c.id}">${this.escape(c.comment)}</p>
  
                <div style="display:flex; gap:0.6rem; margin-top:0.4rem; font-size:0.75rem; color:var(--text-secondary);">
                  ${this.user ? `<a href="javascript:;" style="color:var(--accent);" onclick="app.toggleReplyBox(${c.id})">Reply</a>` : ''}
                  ${canManage ? `
                    <a href="javascript:;" onclick="app.editCommentModal(${c.id})">Edit</a>
                    <a href="javascript:;" style="color:var(--r18);" onclick="app.deleteComment(${c.id})">Delete</a>
                  ` : ''}
                </div>
  
                <div id="reply-box-${c.id}" style="display:none; margin-top:0.6rem;">
                  <form onsubmit="app.handleCommentSubmit(event, ${artId}, ${c.id})" style="display:flex; flex-direction:column; gap:0.4rem;">
                    <textarea name="comment" class="form-textarea" placeholder="Write reply..." style="min-height:60px;" required></textarea>
                    <div style="display:flex; justify-content:flex-end; gap:0.4rem;">
                      <button type="button" class="btn-subtle" style="height:28px; font-size:0.75rem;" onclick="app.toggleReplyBox(${c.id})">Cancel</button>
                      <button type="submit" class="btn-primary" style="height:28px; font-size:0.75rem;">Reply</button>
                    </div>
                  </form>
                </div>
  
                ${c.replies && c.replies.length ? `
                  <div class="comment-replies-list">
                    ${c.replies.map(r => this.renderCommentNode(r, artId)).join('')}
                  </div>
                ` : ''}
              </div>
            </div>
          `;
        }
  
        toggleReplyBox(id) {
          const box = document.getElementById(`reply-box-${id}`);
          if (box) box.style.display = box.style.display === 'none' ? 'block' : 'none';
        }
  
        async handleCommentSubmit(e, artworkId, parentId = 0) {
          e.preventDefault();
          const form = e.target;
          const text = form.comment.value.trim();
          if (!text) return;
  
          try {
            await this.api('comment_add', { artwork_id: artworkId, parent_id: parentId, comment: text }, 'POST');
            this.toast('Response posted!');
            this.renderArtworkView(artworkId);
          } catch(err) {
            this.toast(err.message);
          }
        }
  
        editCommentModal(commentId) {
          const text = document.getElementById(`comm-text-${commentId}`)?.innerText || '';
          const html = `
            <div class="modal-header">
              <span>Edit Commentary</span>
              <button class="btn-icon" onclick="app.closeModal()"><svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg></button>
            </div>
            <form onsubmit="app.handleCommentEdit(event, ${commentId})">
              <div class="modal-body">
                <div class="form-group">
                  <textarea name="comment" class="form-textarea" required>${this.escape(text)}</textarea>
                </div>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn-subtle" onclick="app.closeModal()">Cancel</button>
                <button type="submit" class="btn-primary">Save Changes</button>
              </div>
            </form>
          `;
          this.showModal(html);
        }
  
        async handleCommentEdit(e, commentId) {
          e.preventDefault();
          const text = e.target.comment.value.trim();
          try {
            await this.api('comment_edit', { comment_id: commentId, comment: text }, 'POST');
            const el = document.getElementById(`comm-text-${commentId}`);
            if (el) el.innerText = text;
            this.closeModal();
            this.toast('Comment updated.');
          } catch(err) {
            this.toast(err.message);
          }
        }
  
        async deleteComment(commentId) {
          if (!confirm('Permanently delete comment?')) return;
          try {
            await this.api('comment_delete', { comment_id: commentId }, 'POST');
            const el = document.getElementById(`comm-${commentId}`);
            if (el) el.remove();
            this.toast('Comment removed.');
          } catch(e) {
            this.toast(e.message);
          }
        }
  
        async renderUserProfile(userId) {
          const container = document.getElementById('page-container');
          container.innerHTML = '<div class="spinner"></div>';

          try {
            const rawHash = window.location.hash || '';
            const [_, profQueryStr] = rawHash.split('?');
            const profParams = new URLSearchParams(profQueryStr || '');
            const profQuery = profParams.get('q') || '';
            const profSort = profParams.get('sort') || 'newest';
            const profRating = profParams.get('rating') || 'all';
            const profPage = Math.max(1, parseInt(profParams.get('page') || '1', 10));

            const prof = await this.api('user_profile', { user: userId });
            this.setTitle(prof.artist_name);
            const isOwner = this.user && this.user.id == prof.id;
            const avatarUrl = this.getAvatar(prof.avatar, prof.artist_name, prof.email_hash);
            const bannerStyle = prof.banner ? `background-image: url('${prof.banner}'); background-size: cover; background-position: center;` : `background: linear-gradient(135deg, #0096fa, #ff4772);`;

            const reqData = {
              user_id: prof.id,
              limit: 24,
              page: profPage,
              sort: profSort,
              rating: profRating
            };
            if (profQuery) reqData.q = profQuery;

            const arts = await this.api('artworks_list', reqData);

            let html = `
              <div style="width:100%; height:190px; border-radius:18px; ${bannerStyle} position:relative; margin-bottom:3.8rem; box-shadow:var(--shadow-sm);">
                <div style="position:absolute; bottom:-38px; left:1.8rem; display:flex; align-items:flex-end; gap:1rem;">
                  <img src="${avatarUrl}" style="width:92px; height:92px; border-radius:50%; object-fit:cover; border:4px solid var(--bg-surface); background:var(--bg-surface-elevated);" alt="" data-artist-name="${this.escape(prof.artist_name)}" onerror="app.handleAvatarError(this)">
                </div>
              </div>

              <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:1.6rem; flex-wrap:wrap; gap:1rem;">
                <div>
                  <h1 style="font-size:1.6rem; font-weight:800; letter-spacing:-0.5px;">${this.escape(prof.artist_name)}</h1>
                  ${prof.bio ? `<p style="font-size:0.9rem; color:var(--text-secondary); max-width:650px; margin-top:0.6rem; line-height:1.5;">${this.escape(prof.bio)}</p>` : ''}
                  <div style="display:flex; gap:1.4rem; font-size:0.85rem; color:var(--text-muted); margin-top:0.6rem;">
                    <span><strong>${prof.artwork_count}</strong> Creations</span>
                    <span><strong>${prof.follower_count}</strong> Followers</span>
                    <span><strong>${prof.following_count}</strong> Following</span>
                  </div>
                </div>
                <div style="display:flex; gap:0.6rem;">
                  ${isOwner ? `
                    <button class="btn-subtle" onclick="app.nav('#/settings')">Settings</button>
                    <button class="btn-subtle" style="color:var(--r18);" onclick="app.logout()">Log Out</button>
                  ` : `
                    <button class="btn-primary" style="background:${prof.is_following ? 'var(--bg-surface-hover)' : 'var(--accent)'}; color:${prof.is_following ? 'var(--text-primary)' : '#fff'};" onclick="app.toggleFollow(${prof.id}, this)">
                      ${prof.is_following ? '<svg viewBox="0 0 24 24" style="width:16px;height:16px;margin-right:4px;"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg> Following' : '<svg viewBox="0 0 24 24" style="width:16px;height:16px;margin-right:4px;"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg> Follow Artist'}
                    </button>
                  `}
                </div>
              </div>

              <div class="feed-header-wrap" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.4rem; flex-wrap:wrap; gap:0.8rem;">
                <div>
                  <h3 style="font-size:1.2rem; font-weight:800; margin:0;">Artworks &amp; Creations</h3>
                  <p style="font-size:0.8rem; color:var(--text-muted); margin-top:0.2rem;">${arts.total} work${arts.total === 1 ? '' : 's'} available</p>
                </div>
                <div class="feed-header-controls" style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
                  <div class="search-bar" style="width:210px; height:36px;">
                    <svg viewBox="0 0 24 24" style="width:16px;height:16px;"><path d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
                    <input type="text" placeholder="Search creations..." value="${this.escape(profQuery)}" onkeydown="if(event.key==='Enter') app.updateParam('q', this.value.trim())">
                  </div>
                  <select class="form-select" style="font-size:0.8rem; height:36px;" onchange="app.updateParam('sort', this.value)">
                    <option value="newest" ${profSort === 'newest' ? 'selected' : ''}>Newest First</option>
                    <option value="popular" ${profSort === 'popular' ? 'selected' : ''}>Most Popular</option>
                    <option value="views" ${profSort === 'views' ? 'selected' : ''}>Most Views</option>
                    <option value="oldest" ${profSort === 'oldest' ? 'selected' : ''}>Oldest</option>
                  </select>
                  <select class="form-select" style="font-size:0.8rem; height:36px;" onchange="app.updateParam('rating', this.value)">
                    <option value="all" ${profRating === 'all' ? 'selected' : ''}>All Ratings</option>
                    <option value="safe" ${profRating === 'safe' ? 'selected' : ''}>All Ages Only</option>
                    <option value="r18" ${profRating === 'r18' ? 'selected' : ''}>R-18 Only</option>
                  </select>
                </div>
              </div>
            `;

            if (!arts.artworks || !arts.artworks.length) {
              html += `<div class="center-msg">${profQuery || profRating !== 'all' ? 'No creations matched your search or filter.' : 'No submissions from this artist yet.'}</div>`;
            } else {
              html += `<div class="art-grid">`;
              arts.artworks.forEach(art => {
                const coverFileName = art.cover_file || '';
                const coverUrl = coverFileName ? `?action=thumb&f=${encodeURIComponent(coverFileName)}` : '';
                const isVid = art.type === 'video' || (art.cover_mime && art.cover_mime.startsWith('video/'));
                const pageCount = Number(art.page_count || 1);
                const viewCount = Number(art.view_count || 0);
                const likeCount = Number(art.like_count || 0);

                html += `
                  <div class="art-card" onclick="app.nav('#/artwork/${art.id}')">
                    <div class="art-thumb-wrap">
                      ${coverUrl ? `<img src="${coverUrl}" alt="" loading="lazy" onerror="this.onerror=null; this.src='?action=raw&f=${encodeURIComponent(coverFileName)}'">` : '<div style="display:flex; align-items:center; justify-content:center; height:100%; color:var(--text-muted);">No Media</div>'}
                      ${pageCount > 1 ? `<div class="badge-page-count"><svg viewBox="0 0 24 24" style="width:13px;height:13px;"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V5h14v14z"/></svg> ${pageCount}P</div>` : ''}
                      ${art.type === 'manga' ? `<div class="badge-flag manga">MANGA</div>` : ''}
                      ${isVid ? `<div class="badge-flag video">VIDEO</div>` : ''}
                      ${art.rating === 'r18' ? `<div class="badge-flag">R-18</div>` : ''}
                      ${art.is_ai ? `<div class="badge-flag ai">AI</div>` : ''}
                    </div>
                    <div class="art-card-info">
                      <div class="art-card-title">${this.escape(art.title)}</div>
                      <div class="art-card-stats">
                        <span>${viewCount.toLocaleString()} views</span>
                        <div class="art-card-actions">
                          <span class="stat-btn ${art.user_liked ? 'active like' : ''}" onclick="event.stopPropagation(); app.toggleLike(${art.id}, this)">
                            <svg viewBox="0 0 24 24"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>
                            <span>${likeCount}</span>
                          </span>
                        </div>
                      </div>
                    </div>
                  </div>
                `;
              });
              html += `</div>`;

              if (arts.pages > 1) {
                html += `
                  <div class="pagination-bar" style="display:flex; justify-content:center; align-items:center; gap:0.5rem; margin-top:2.5rem; margin-bottom:1.5rem; flex-wrap:wrap;">
                    <button class="btn-subtle" style="width:36px; height:36px; padding:0;" title="First Page" ${arts.page <= 1 ? 'disabled style="opacity:0.4; cursor:not-allowed;"' : ''} onclick="app.updateParam('page', 1)">
                      <svg viewBox="0 0 24 24" style="width:16px; height:16px;"><path d="M18.41 16.59L13.82 12l4.59-4.59L17 6l-6 6 6 6zM6 6h2v12H6z"/></svg>
                    </button>
                    <button class="btn-subtle" style="width:36px; height:36px; padding:0;" title="Previous Page" ${arts.page <= 1 ? 'disabled style="opacity:0.4; cursor:not-allowed;"' : ''} onclick="app.updateParam('page', ${arts.page - 1})">
                      <svg viewBox="0 0 24 24" style="width:16px; height:16px;"><path d="M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z"/></svg>
                    </button>
                    <button class="btn-subtle" style="font-weight:700; color:var(--accent); border-color:var(--accent-alpha); background:var(--accent-alpha);" title="Click to jump to page" onclick="app.showJumpPageModal(${arts.page}, ${arts.pages})">Page ${arts.page} of ${arts.pages}</button>
                    <button class="btn-subtle" style="width:36px; height:36px; padding:0;" title="Next Page" ${arts.page >= arts.pages ? 'disabled style="opacity:0.4; cursor:not-allowed;"' : ''} onclick="app.updateParam('page', ${arts.page + 1})">
                      <svg viewBox="0 0 24 24" style="width:16px; height:16px;"><path d="M10 6L8.59 7.41 13.17 12l-4.58 4.59L10 18l6-6z"/></svg>
                    </button>
                    <button class="btn-subtle" style="width:36px; height:36px; padding:0;" title="Latest Page" ${arts.page >= arts.pages ? 'disabled style="opacity:0.4; cursor:not-allowed;"' : ''} onclick="app.updateParam('page', ${arts.pages})">
                      <svg viewBox="0 0 24 24" style="width:16px; height:16px;"><path d="M5.59 7.41L10.18 12l-4.59 4.59L7 18l6-6-6-6zM16 6h2v12h-2z"/></svg>
                    </button>
                  </div>
                `;
              }
            }

            container.innerHTML = html;
          } catch(err) {
            container.innerHTML = `<div class="center-msg">${err.message}</div>`;
          }
        }
  
        async renderActivityPage() {
          if (!this.user) {
            this.showAuthModal('login');
            this.nav('#/');
            return;
          }
          this.setTitle('Activity History');

          const container = document.getElementById('page-container');
          container.innerHTML = '<div class="spinner"></div>';
  
          try {
            const res = await this.api('activity_list');
            let html = `
              <div style="max-width:900px; margin:0 auto; width:100%;">
                <h1 style="font-size:1.4rem; font-weight:800; margin-bottom:1rem;">My Studio Activity History</h1>
                <div style="display:flex; flex-direction:column; gap:0.6rem;">
            `;
            if (!res.activities || !res.activities.length) {
              html += `<div class="center-msg">No recent activity recorded for your studio.</div>`;
            } else {
              html += res.activities.map(act => `
                <div style="background:var(--bg-surface); border:1px solid var(--border-subtle); border-radius:12px; padding:0.85rem 1.1rem; display:flex; align-items:center; justify-content:space-between; gap:1rem;">
                  <div style="display:flex; align-items:center; gap:0.75rem;">
                    <img src="${this.getAvatar(act.avatar, act.artist_name, act.email_hash)}" style="width:34px; height:34px; border-radius:50%; object-fit:cover; background:var(--bg-surface-elevated);" alt="" onerror="app.handleAvatarError(this, '${this.escape(act.artist_name)}')">
                    <div>
                      <span style="font-weight:700; font-size:0.88rem;">${this.escape(act.artist_name)}</span>
                      <span style="font-size:0.82rem; color:var(--text-secondary);"> ${this.escape(act.details)}</span>
                    </div>
                  </div>
                  <span style="font-size:0.75rem; color:var(--text-muted); white-space:nowrap;">${new Date(act.created_at * 1000).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'})}</span>
                </div>
              `).join('');
            }
            html += `
                </div>
              </div>
            `;
            container.innerHTML = html;
          } catch(e) {
            container.innerHTML = `<div class="center-msg">${e.message}</div>`;
          }
        }

        async renderStudio(editId = null) {
          if (!this.user) {
            this.showAuthModal('login');
            return;
          }
          this.setTitle(editId ? 'Edit Artwork' : 'Publish Artwork');
  
          const container = document.getElementById('page-container');
          container.innerHTML = '<div class="spinner"></div>';
  
          let artData = {
            id: 0,
            title: '',
            description: '',
            type: 'illust',
            rating: 'all',
            is_ai: 0,
            is_original: 1,
            tools: '',
            parodies: '',
            characters: '',
            tags: '',
            source_url: '',
            images: []
          };
  
          if (editId) {
            try {
              const existing = await this.api('artwork_get', { id: editId });
              if (existing.user_id != this.user.id && !this.user.is_admin) {
                this.toast('You can only edit your own artworks.');
                this.nav('#/');
                return;
              }
              artData = existing;
            } catch(e) {
              this.toast('Artwork not found.');
              this.nav('#/');
              return;
            }
          }
  
          this.uploadQueue = [...(artData.images || [])];
  
          const html = `
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.2rem; flex-wrap:wrap; gap:0.6rem;">
              <h2 style="font-size:1.4rem; font-weight:800; margin:0;">${editId ? 'Edit Artwork Studio' : 'Publish Artwork or Video'}</h2>
              <div style="display:flex; gap:0.5rem; align-items:center;">
                ${editId ? `
                  <button type="button" class="btn-subtle" style="gap:0.4rem;" onclick="app.exportArtworkPost(${editId})" title="Export Post Package (.zip) with progress">
                    <svg viewBox="0 0 24 24" style="width:15px;height:15px;"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>
                    <span>Export Post (.zip)</span>
                  </button>
                ` : ''}
                ${!editId ? `
                  <button type="button" class="btn-primary" onclick="app.showImportModal()">
                    <svg viewBox="0 0 24 24" style="width:16px; height:16px;"><path d="M9 16h6v-6h4l-7-7-7 7h4v6zm-4 2h14v2H5v-2z"/></svg>
                    <span>Import Post</span>
                  </button>
                ` : ''}
              </div>
            </div>
          
            <form onsubmit="app.handleArtworkSubmit(event)">
              <input type="hidden" name="id" value="${artData.id || 0}">
          
              ${!editId ? `
                <div class="form-group" style="margin-bottom:1.2rem;">
                  <label class="form-label">Upload Mode Configuration</label>
                  <div class="mode-card-grid">
                    <label class="mode-card selected" id="label-mode-single">
                      <input type="radio" name="post_mode" value="single" checked onchange="app.setPostMode('single')">
                      <div class="mode-card-info">
                        <span class="mode-card-title">
                          <svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V5h14v14z"/></svg>
                          Single Post (Multi-Page Series)
                        </span>
                        <span class="mode-card-desc">All files become consecutive high-res pages of a single post with a "See All" expander.</span>
                      </div>
                    </label>
          
                    <label class="mode-card" id="label-mode-batch">
                      <input type="radio" name="post_mode" value="batch" onchange="app.setPostMode('batch')">
                      <div class="mode-card-info">
                        <span class="mode-card-title">
                          <svg viewBox="0 0 24 24"><path d="M4 6H2v14c0 1.1.9 2 2 2h14v-2H4V6zm16-4H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H8V4h12v12z"/></svg>
                          One Artwork per Image (Batch)
                        </span>
                        <span class="mode-card-desc">Each uploaded file creates its own standalone artwork post with shared title and metadata.</span>
                      </div>
                    </label>
                  </div>
                </div>
              ` : ''}
          
              <div class="form-group">
                <label class="form-label">Upload Files (Chunked multi-file &amp; video support)</label>
                <div class="upload-zone" id="studio-dropzone" onclick="document.getElementById('studio-file-input').click()">
                  <svg viewBox="0 0 24 24" style="width:40px; height:40px; color:var(--accent);"><path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM14 13v4h-4v-4H7l5-5 5 5h-3z"/></svg>
                  <span style="font-weight:700; font-size:0.95rem;">Drop multiple files here or click to browse</span>
                  <span style="font-size:0.75rem; color:var(--text-muted);">Max 500 images per post &bull; 10 images/day for separate individual posts</span>
                </div>
                <input type="file" id="studio-file-input" multiple style="display:none;" accept="image/*,video/*" onchange="app.handleStudioFiles(this.files)">
          
                <div id="studio-upload-progress" style="display:none; margin-top:0.85rem; background:var(--bg-surface-elevated); border:1px solid var(--border-subtle); border-radius:12px; padding:0.85rem 1rem;">
                  <div style="display:flex; justify-content:space-between; align-items:center; font-size:0.82rem; font-weight:600; margin-bottom:0.45rem;">
                    <span id="upload-status-text" style="color:var(--text-primary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:80%;">Preparing upload...</span>
                    <span id="upload-percent-text" style="color:var(--accent); font-weight:700;">0%</span>
                  </div>
                  <div style="width:100%; height:6px; background:var(--bg-base); border-radius:3px; overflow:hidden;">
                    <div id="upload-progress-bar" style="width:0%; height:100%; background:var(--accent); border-radius:3px; transition:width 0.15s ease;"></div>
                  </div>
                </div>
          
                <div class="upload-preview-grid" id="studio-preview-grid"></div>
              </div>
          
              <div class="form-group" style="margin-top:1.2rem;">
                <label class="form-label">Original Source URLs (Optional - multiple URLs allowed, one per line or separated by comma)</label>
                <textarea name="source_url" id="studio-source-url" class="form-textarea" style="min-height:56px; font-family:'JetBrains Mono',monospace; font-size:0.82rem;" placeholder="https://x.com/...&#10;https://pixiv.net/..." oninput="app.checkDuplicateUrl(this.value)">${this.escape(artData.source_url || '')}</textarea>
                <div id="source-check-status" style="font-size:0.78rem; margin-top:0.25rem;"></div>
              </div>
          
              <div class="form-group" style="margin-top:1.2rem;">
                <label class="form-label">Artwork Title *</label>
                <input type="text" name="title" class="form-input" placeholder="Give your creation an evocative title" value="${this.escape(artData.title)}" required>
              </div>
          
              <div class="form-group" style="margin-top:1.2rem;">
                <label class="form-label">Caption / Description (Markdown enabled)</label>
                <textarea name="description" class="form-textarea" placeholder="Describe the lore, brushes used, or artist commentary...">${this.escape(artData.description)}</textarea>
              </div>
          
              <div class="form-grid-2" style="margin-top:1.2rem;">
                <div class="form-group">
                  <label class="form-label">Category</label>
                  <select name="type" class="form-select">
                    <option value="illust" ${artData.type === 'illust' ? 'selected' : ''}>Illustration / Picture</option>
                    <option value="manga" ${artData.type === 'manga' ? 'selected' : ''}>Manga / Comic</option>
                    <option value="video" ${artData.type === 'video' ? 'selected' : ''}>Animation / Video Clip</option>
                  </select>
                </div>
                <div class="form-group">
                  <label class="form-label">Age Rating</label>
                  <select name="rating" class="form-select">
                    <option value="all" ${artData.rating === 'all' ? 'selected' : ''}>All Ages (General)</option>
                    <option value="r18" ${artData.rating === 'r18' ? 'selected' : ''}>R-18 (Mature Only)</option>
                  </select>
                </div>
              </div>
          
              <div class="form-group" style="margin-top:1.2rem;">
                <label class="form-label">Tags (comma separated &bull; spaces allowed inside names)</label>
                <input type="text" name="tags" class="form-input" placeholder="Wuthering Waves, Anime, Fantasy Landscape" value="${this.escape(artData.tags)}">
              </div>
          
              <div class="form-grid-2" style="margin-top:1.2rem;">
                <div class="form-group">
                  <label class="form-label">Characters Depicted (comma separated &bull; spaces allowed)</label>
                  <input type="text" name="characters" class="form-input" placeholder="Hatsune Miku, Rover, Yangyang" value="${this.escape(artData.characters)}">
                </div>
                <div class="form-group">
                  <label class="form-label">Series / Parody (comma separated &bull; spaces allowed)</label>
                  <input type="text" name="parodies" class="form-input" placeholder="Wuthering Waves, Genshin Impact" value="${this.escape(artData.parodies)}">
                </div>
              </div>
          
              <div class="form-grid-2" style="margin-top:1.2rem; align-items:flex-end;">
                <div class="form-group">
                  <label class="form-label">Tools Used (comma separated)</label>
                  <input type="text" name="tools" class="form-input" placeholder="Clip Studio Paint, Photoshop, Blender" value="${this.escape(artData.tools)}">
                </div>
                <div class="form-group" style="min-height:38px; justify-content:center;">
                  <label style="display:inline-flex; align-items:center; gap:0.55rem; cursor:pointer; font-size:0.88rem; font-weight:600; margin:0;">
                    <input type="checkbox" name="is_ai" value="1" ${artData.is_ai ? 'checked' : ''} style="width:18px; height:18px; accent-color:var(--accent); margin:0;">
                    <span>AI-Generated Creation</span>
                  </label>
                </div>
              </div>
          
              <div class="studio-actions" style="display:flex; justify-content:flex-end; gap:0.6rem; margin-top:1.8rem;">
                <button type="button" class="btn-subtle" onclick="window.history.back()">Cancel</button>
                <button type="submit" class="btn-primary" id="btn-publish-art">${editId ? 'Save Modifications' : 'Publish Work'}</button>
              </div>
            </form>
          `;
  
          container.innerHTML = html;
          this.renderStudioPreviews();
  
          const dropzone = document.getElementById('studio-dropzone');
          dropzone.ondragover = (e) => { e.preventDefault(); dropzone.classList.add('dragover'); };
          dropzone.ondragleave = () => dropzone.classList.remove('dragover');
          dropzone.ondrop = (e) => {
            e.preventDefault();
            dropzone.classList.remove('dragover');
            if (e.dataTransfer.files.length) this.handleStudioFiles(e.dataTransfer.files);
          };
        }
  
        checkDuplicateUrl(val) {
          clearTimeout(this.dupCheckTimer);
          const statusBox = document.getElementById('source-check-status');
          if (!statusBox) return;
  
          const url = val.trim();
          if (!url.startsWith('http')) {
            statusBox.innerHTML = '';
            return;
          }
  
          this.dupCheckTimer = setTimeout(async () => {
            try {
              const res = await this.api('check_url', { url });
              if (res.duplicate) {
                statusBox.innerHTML = `<span style="color:var(--r18); font-weight:700;">Duplicate: Already exists in Artwork #${res.duplicate.id}: "${this.escape(res.duplicate.title)}"</span>`;
              } else if (res.valid) {
                statusBox.innerHTML = `<span style="color:#10b981; font-weight:600;">Valid URL. No duplicate detected.</span>`;
              } else {
                statusBox.innerHTML = `<span style="color:var(--text-muted);">Please enter a valid HTTP/HTTPS URL.</span>`;
              }
            } catch(e) {
              statusBox.innerHTML = '';
            }
          }, 400);
        }
  
        setPostMode(mode) {
          const lSingle = document.getElementById('label-mode-single');
          const lBatch = document.getElementById('label-mode-batch');
          if (lSingle && lBatch) {
            lSingle.classList.toggle('selected', mode === 'single');
            lBatch.classList.toggle('selected', mode === 'batch');
          }
        }
  
        renderStudioPreviews() {
          const grid = document.getElementById('studio-preview-grid');
          if (!grid) return;
          grid.innerHTML = this.uploadQueue.map((item, idx) => {
            const thumbUrl = `?action=thumb&f=${encodeURIComponent(item.file_name)}`;
            const displayName = item.original || item.file_name || `Media #${idx + 1}`;
            return `
              <div class="upload-preview-item">
                <img src="${thumbUrl}" alt="" onerror="this.onerror=null; this.src='?action=raw&f=${encodeURIComponent(item.file_name)}'">
                <span class="upload-item-order">#${idx + 1}</span>
                ${item.is_video ? `<span class="badge-flag video" style="top:26px;left:4px;font-size:0.6rem;z-index:3;">VIDEO</span>` : ''}
                <span class="upload-item-del" onclick="app.removeStudioImage(${idx})">
                  <svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
                </span>
                <div class="upload-item-name" title="${this.escape(displayName)}">${this.escape(displayName)}</div>
              </div>
            `;
          }).join('');
        }
  
        removeStudioImage(idx) {
          this.uploadQueue.splice(idx, 1);
          this.renderStudioPreviews();
        }
  
        async handleStudioFiles(files) {
          if (!files || !files.length) return;

          if (this.uploadQueue.length + files.length > 500) {
            this.toast(`Upload limit exceeded: A post can have at most 500 images (current: ${this.uploadQueue.length}, added: ${files.length}).`);
            return;
          }

          // Automatically sort incoming files by natural name order (e.g. 1, 2, 10)
          const sortedFiles = Array.from(files).sort((a, b) => {
            return a.name.localeCompare(b.name, undefined, { numeric: true, sensitivity: 'base' });
          });
          const total = sortedFiles.length;

          const progBox = document.getElementById('studio-upload-progress');
          const statusText = document.getElementById('upload-status-text');
          const percentText = document.getElementById('upload-percent-text');
          const progBar = document.getElementById('upload-progress-bar');
          const pubBtn = document.getElementById('btn-publish-art');

          if (progBox) {
            progBox.style.display = 'block';
            if (progBar) {
              progBar.style.width = '0%';
              progBar.style.background = 'var(--accent)';
            }
          }
          if (pubBtn) {
            pubBtn.disabled = true;
            pubBtn.innerText = 'Uploading Media...';
          }

          try {
            for (let i = 0; i < total; i++) {
              const file = files[i];
              let videoThumb = '';

              if (file.type.startsWith('video/')) {
                if (statusText) statusText.innerText = `Extracting video preview (${i + 1}/${total}): ${file.name}`;
                videoThumb = await this.extractVideoThumbnail(file);
              }

              const uploadId = 'up_' + Date.now() + '_' + Math.random().toString(36).substr(2, 6);
              const totalChunks = Math.max(1, Math.ceil(file.size / this.chunkSize));

              let completed = false;
              let fileRes = null;

              for (let c = 0; c < totalChunks; c++) {
                const currentOverall = Math.round(((i + (c / totalChunks)) / total) * 100);
                if (statusText) statusText.innerText = `Uploading (${i + 1}/${total}): ${file.name} (Chunk ${c + 1}/${totalChunks})`;
                if (percentText) percentText.innerText = `${currentOverall}%`;
                if (progBar) progBar.style.width = `${currentOverall}%`;

                const start = c * this.chunkSize;
                const end = Math.min(file.size, start + this.chunkSize);
                const chunkBlob = file.slice(start, end);

                const fd = new FormData();
                fd.append('action', 'upload_chunk');
                fd.append('upload_id', uploadId);
                fd.append('chunk_index', c);
                fd.append('total_chunks', totalChunks);
                fd.append('file_name', file.name);
                if (videoThumb) fd.append('thumb_data', videoThumb);
                fd.append('chunk', chunkBlob, file.name);

                const res = await this.api('upload_chunk', fd, 'POST');
                if (res.completed) {
                  completed = true;
                  fileRes = res;
                }
              }

              if (completed && fileRes) {
                this.uploadQueue.push({
                  file_name: fileRes.file_name,
                  original: fileRes.original || file.name,
                  thumb_name: fileRes.thumb_name,
                  mime_type: fileRes.mime_type,
                  phash: fileRes.phash,
                  is_video: fileRes.is_video,
                  width: fileRes.width,
                  height: fileRes.height,
                  size: fileRes.file_size
                });

                // Keep upload queue automatically sorted by file name order
                this.uploadQueue.sort((a, b) => {
                  const nameA = a.original || a.file_name || '';
                  const nameB = b.original || b.file_name || '';
                  return nameA.localeCompare(nameB, undefined, { numeric: true, sensitivity: 'base' });
                });

                this.renderStudioPreviews();
              }
            }

            if (statusText) statusText.innerText = `All ${total} file(s) uploaded successfully!`;
            if (percentText) percentText.innerText = `100%`;
            if (progBar) progBar.style.width = `100%`;
            this.toast('Media uploaded and staged.');

            setTimeout(() => {
              if (progBox) progBox.style.display = 'none';
            }, 1400);
          } catch (err) {
            if (statusText) statusText.innerText = `Upload failed: ${err.message}`;
            if (progBar) progBar.style.background = 'var(--r18)';
            this.toast(err.message);
          } finally {
            if (pubBtn) {
              pubBtn.disabled = false;
              pubBtn.innerText = 'Publish Work';
            }
            const fileInput = document.getElementById('studio-file-input');
            if (fileInput) fileInput.value = '';
          }
        }
  
        async handleArtworkSubmit(e) {
          e.preventDefault();
          const form = e.target;
          const btn = document.getElementById('btn-publish-art');
  
          if (!this.uploadQueue.length) {
            this.toast('Please upload at least one image or video.');
            return;
          }
  
          btn.disabled = true;
          btn.innerText = 'Publishing...';
  
          const fd = new FormData(form);
          fd.append('action', 'artwork_save');
          fd.append('images', JSON.stringify(this.uploadQueue));
  
          try {
            const res = await this.api('artwork_save', fd, 'POST');
            if (res.batch) {
              this.toast(`Published ${res.count} artworks successfully!`);
              this.nav(`#/user/${this.user.id}`);
            } else {
              this.toast('Artwork published successfully!');
              this.nav(`#/artwork/${res.artwork_id}`);
            }
          } catch(err) {
            this.toast(err.message);
            btn.disabled = false;
            btn.innerText = 'Publish Work';
          }
        }
  
        async deleteArtwork(id) {
          if (!confirm('Are you sure you want to permanently delete this artwork?')) return;
          try {
            await this.api('artwork_delete', { id }, 'POST');
            this.toast('Artwork deleted.');
            this.nav('#/');
          } catch(e) {
            this.toast(e.message);
          }
        }
  
        async toggleLike(artworkId, btn) {
          try {
            const res = await this.api('artwork_like', { artwork_id: artworkId }, 'POST');
            if (btn) {
              btn.classList.toggle('active', res.liked);
              btn.classList.toggle('like', res.liked);
              const countSpan = btn.querySelector('span');
              if (countSpan) countSpan.innerText = countSpan.innerText.includes('Like') ? `Like (${res.like_count})` : res.like_count;
            }
          } catch(e) {
            this.toast(e.message);
          }
        }
  
        async toggleFollow(targetUserId, btn) {
          if (!this.user) {
            this.showAuthModal('login');
            return;
          }
          try {
            const res = await this.api('user_follow', { user_id: targetUserId }, 'POST');
            if (btn) {
              btn.innerHTML = res.following ? '<svg viewBox="0 0 24 24" style="width:16px;height:16px;margin-right:4px;"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg> Following' : '<svg viewBox="0 0 24 24" style="width:16px;height:16px;margin-right:4px;"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg> Follow Artist';
              btn.style.background = res.following ? 'var(--bg-surface-hover)' : 'var(--accent)';
              btn.style.color = res.following ? 'var(--text-primary)' : '#fff';
            }
          } catch(e) {
            this.toast(e.message);
          }
        }
  
        showShareModal(artworkId, title, artistName) {
          const shareUrl = `${window.location.origin}${window.location.pathname}#/artwork/${artworkId}`;
          const shareText = `Check out "${title}" by ${artistName} on HDPost!`;
          const encodedUrl = encodeURIComponent(shareUrl);
          const encodedText = encodeURIComponent(shareText);
          const hasNativeShare = typeof navigator !== 'undefined' && !!navigator.share;

          const html = `
            <div class="modal-header">
              <span>Share Artwork</span>
              <button class="btn-icon" onclick="app.closeModal()"><svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg></button>
            </div>
            <div class="modal-body" style="gap:1.1rem;">
              <div class="form-group">
                <label class="form-label">Post Link</label>
                <div style="display:flex; gap:0.5rem; align-items:center;">
                  <input type="text" id="share-link-input" class="form-input" value="${this.escape(shareUrl)}" readonly style="background:var(--bg-base); font-size:0.82rem;">
                  <button type="button" class="btn-primary" style="height:38px; padding:0 1.1rem; flex-shrink:0;" onclick="app.copyShareLink()">Copy</button>
                </div>
              </div>

              <div>
                <label class="form-label" style="display:block; margin-bottom:0.6rem;">Share To</label>
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(130px, 1fr)); gap:0.6rem;">
                  <a href="https://twitter.com/intent/tweet?text=${encodedText}&url=${encodedUrl}" target="_blank" rel="noopener noreferrer" class="btn-subtle" style="gap:0.45rem; height:38px; font-size:0.82rem;">
                    <svg viewBox="0 0 24 24" style="width:16px;height:16px;"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
                    <span>Twitter / X</span>
                  </a>
                  <a href="https://t.me/share/url?url=${encodedUrl}&text=${encodedText}" target="_blank" rel="noopener noreferrer" class="btn-subtle" style="gap:0.45rem; height:38px; font-size:0.82rem;">
                    <svg viewBox="0 0 24 24" style="width:16px;height:16px;"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.64 6.8c-.15 1.58-.8 5.42-1.13 7.19-.14.75-.42 1-.68 1.03-.58.05-1.02-.38-1.58-.75-.88-.58-1.38-.94-2.23-1.5-.99-.65-.35-1.01.22-1.59.15-.15 2.71-2.48 2.76-2.69a.2.2 0 00-.05-.18c-.06-.05-.14-.03-.21-.02-.09.02-1.49.95-4.22 2.79-.4.27-.76.41-1.08.4-.36-.01-1.04-.2-1.55-.37-.63-.2-1.12-.31-1.08-.66.02-.18.27-.36.75-.55 2.92-1.27 4.86-2.11 5.83-2.51 2.78-1.16 3.35-1.36 3.73-1.36.08 0 .27.02.39.12.1.08.13.19.14.27-.01.06.01.24 0 .38z"/></svg>
                    <span>Telegram</span>
                  </a>
                  <a href="https://api.whatsapp.com/send?text=${encodedText}%20${encodedUrl}" target="_blank" rel="noopener noreferrer" class="btn-subtle" style="gap:0.45rem; height:38px; font-size:0.82rem;">
                    <svg viewBox="0 0 24 24" style="width:16px;height:16px;"><path d="M16.75 13.96c.25.13.41.2.46.3.06.11.04.61-.21 1.18-.25.56-.93 1.05-1.48 1.15-.49.09-1.05.1-1.7-.13-.41-.14-.94-.33-1.63-.63-2.88-1.25-4.75-4.15-4.9-4.34-.14-.2-1.17-1.56-1.17-2.98 0-1.42.74-2.12 1.01-2.41.26-.29.58-.36.77-.36.19 0 .39.01.56.02.18.01.42-.07.66.5.25.6.86 2.09.93 2.24.07.15.12.33.02.53-.1.2-.15.32-.3.49-.15.17-.32.39-.45.52-.15.15-.31.31-.13.62.18.31.8 1.31 1.71 2.12 1.17 1.04 2.15 1.36 2.46 1.51.31.15.49.13.67-.08.19-.21.79-.92 1-.1.24.12.44.25.68.37zM12 2a10 10 0 00-8.66 15L2 22l5.17-1.34A10 10 0 1012 2z"/></svg>
                    <span>WhatsApp</span>
                  </a>
                  <a href="https://reddit.com/submit?url=${encodedUrl}&title=${encodedText}" target="_blank" rel="noopener noreferrer" class="btn-subtle" style="gap:0.45rem; height:38px; font-size:0.82rem;">
                    <svg viewBox="0 0 24 24" style="width:16px;height:16px;"><path d="M12 2a10 10 0 1010 10A10 10 0 0012 2zm6.2 11.2a1.88 1.88 0 01-.8.25 5.56 5.56 0 01-5.4 3.35 5.56 5.56 0 01-5.4-3.35 1.88 1.88 0 01-.8-.25 1.38 1.38 0 01-.6-1.13 1.39 1.39 0 011.39-1.39c.2 0 .4.04.58.12a6.38 6.38 0 014.83-2.22l.83-3.9 2.7.57a1.32 1.32 0 11.1 1l-2.07-.44-.64 3a6.4 6.4 0 014.81 2.22 1.34 1.34 0 01.59-.13 1.39 1.39 0 011.39 1.39 1.38 1.38 0 01-.58 1.12z"/></svg>
                    <span>Reddit</span>
                  </a>
                </div>
              </div>

              ${hasNativeShare ? `
                <div>
                  <button type="button" class="btn-primary" style="width:100%; height:38px; gap:0.45rem;" data-text="${this.escape(shareText)}" data-url="${this.escape(shareUrl)}" onclick="app.triggerNativeShare(this.dataset.text, this.dataset.url)">
                    <svg viewBox="0 0 24 24" style="width:16px;height:16px;"><path d="M18 16.08c-.76 0-1.44.3-1.96.77L8.91 12.7c.05-.23.09-.46.09-.7s-.04-.47-.09-.7l7.05-4.11c.54.5 1.25.81 2.04.81 1.66 0 3-1.34 3-3s-1.34-3-3-3-3 1.34-3 3c0 .24.04.47.09.7L8.04 9.81C7.5 9.31 6.79 9 6 9c-1.66 0-3 1.34-3 3s1.34 3 3 3c.79 0 1.5-.31 2.04-.81l7.12 4.16c-.05.21-.08.43-.08.65 0 1.61 1.31 2.92 2.92 2.92s2.92-1.31 2.92-2.92c0-1.61-1.31-2.92-2.92-2.92z"/></svg>
                    <span>Share via Device...</span>
                  </button>
                </div>
              ` : ''}
            </div>
            <div class="modal-footer">
              <button type="button" class="btn-subtle" onclick="app.closeModal()">Close</button>
            </div>
          `;
          this.showModal(html);
        }

        async copyShareLink() {
          const input = document.getElementById('share-link-input');
          if (!input) return;
          try {
            if (navigator.clipboard && navigator.clipboard.writeText) {
              await navigator.clipboard.writeText(input.value);
            } else {
              input.select();
              document.execCommand('copy');
            }
            this.toast('Link copied to clipboard!');
          } catch(e) {
            input.select();
            this.toast('Please copy the highlighted link.');
          }
        }

        async triggerNativeShare(text, url) {
          if (navigator.share) {
            try {
              await navigator.share({ title: text, text: text, url: url });
              this.closeModal();
            } catch(e) {}
          }
        }
  
        showEditProfileModal() {
          if (!this.user) return;
          const html = `
            <div class="modal-header">
              <span>Edit Artist Studio Profile</span>
              <button class="btn-icon" onclick="app.closeModal()"><svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg></button>
            </div>
            <form onsubmit="app.handleProfileUpdate(event)">
              <div class="modal-body">
                <div class="form-group">
                  <label class="form-label">Artist Pseudonym</label>
                  <input type="text" name="artist_name" class="form-input" value="${this.escape(this.user.artist_name)}" required>
                </div>
                <div class="form-group">
                  <label class="form-label">Bio / Studio Statement</label>
                  <textarea name="bio" class="form-textarea" placeholder="Tell fans and commissioners about your art...">${this.escape(this.user.bio || '')}</textarea>
                </div>
                <div class="form-group">
                  <label class="form-label">Custom Avatar URL (Optional)</label>
                  <input type="url" name="avatar" class="form-input" placeholder="https://..." value="${this.escape(this.user.avatar || '')}">
                </div>
                <div class="form-group">
                  <label class="form-label">Header Banner URL (Optional)</label>
                  <input type="url" name="banner" class="form-input" placeholder="https://..." value="${this.escape(this.user.banner || '')}">
                </div>
                <div class="form-group">
                  <label class="form-label">Twitter / X Handle</label>
                  <input type="text" name="twitter" class="form-input" placeholder="@artist" value="${this.escape(this.user.twitter || '')}">
                </div>
                <div class="form-group">
                  <label class="form-label">Website / Portfolio</label>
                  <input type="url" name="website" class="form-input" placeholder="https://..." value="${this.escape(this.user.website || '')}">
                </div>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn-subtle" onclick="app.closeModal()">Cancel</button>
                <button type="submit" class="btn-primary">Save Profile</button>
              </div>
            </form>
          `;
          this.showModal(html);
        }
  
        async handleProfileUpdate(e) {
          e.preventDefault();
          const fd = new FormData(e.target);
          fd.append('action', 'update_profile');
          try {
            const res = await this.api('update_profile', fd, 'POST');
            this.user = res.user;
            this.renderUserSlot();
            this.closeModal();
            this.toast('Studio profile updated.');
            this.handleRoute();
          } catch(err) {
            this.toast(err.message);
          }
        }
  
        showChangePasswordModal() {
          if (!this.user) return;
          const html = `
            <div class="modal-header">
              <span>Change Account Password</span>
              <button class="btn-icon" onclick="app.closeModal()"><svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg></button>
            </div>
            <form onsubmit="app.handleChangePasswordSubmit(event)">
              <div class="modal-body">
                <div class="form-group">
                  <label class="form-label">Current Password</label>
                  <input type="password" name="current_password" class="form-input" required>
                </div>
                <div class="form-group">
                  <label class="form-label">New Password (Min. 6 chars)</label>
                  <input type="password" name="new_password" class="form-input" required>
                </div>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn-subtle" onclick="app.closeModal()">Cancel</button>
                <button type="submit" class="btn-primary">Update Password</button>
              </div>
            </form>
          `;
          this.showModal(html);
        }
  
        async handleChangePasswordSubmit(e) {
          e.preventDefault();
          const fd = new FormData(e.target);
          try {
            const res = await this.api('change_password', fd, 'POST');
            this.toast(res.message);
            this.closeModal();
          } catch(err) {
            this.toast(err.message);
          }
        }
  
        async logout() {
          await this.api('auth_logout', {}, 'POST');
          location.reload();
        }
  
        async initArtistCarousel(artistUserId, currentArtId) {
          const track = document.getElementById('artist-carousel-track');
          if (!track) return;

          let page = 1;
          let loading = false;
          let hasMore = true;

          const loadBatch = async () => {
            if (loading || !hasMore) return;
            loading = true;
            try {
              const res = await this.api('artworks_list', { user_id: artistUserId, limit: 25, page: page });
              if (page === 1) track.innerHTML = '';
              if (!res.artworks || !res.artworks.length) {
                if (page === 1) track.innerHTML = '<div style="color:var(--text-muted); font-size:0.85rem; padding:1rem;">No other works found.</div>';
                hasMore = false;
                return;
              }

              res.artworks.forEach(item => {
                if (document.getElementById(`carousel-item-${item.id}`)) return;
                const isCurrent = (item.id == currentArtId);
                const el = document.createElement('div');
                el.id = `carousel-item-${item.id}`;
                el.style.cssText = `flex:0 0 110px; aspect-ratio:1/1; border-radius:8px; overflow:hidden; position:relative; cursor:pointer; border:2px solid ${isCurrent ? 'var(--accent)' : 'var(--border-subtle)'}; background:#08080a url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 50 50'%3E%3Ccircle cx='25' cy='25' r='18' fill='none' stroke='%230096fa' stroke-width='3.5' stroke-linecap='round' stroke-dasharray='75' stroke-dashoffset='25'%3E%3CanimateTransform attributeName='transform' type='rotate' from='0 25 25' to='360 25 25' dur='0.8s' repeatCount='indefinite'/%3E%3C/circle%3E%3C/svg%3E") no-repeat center center; background-size:24px 24px;`;
                el.onclick = () => {
                  app.nav(`#/artwork/${item.id}`);
                  const vp = document.getElementById('viewport');
                  if (vp) vp.scrollTop = 0;
                };
                el.innerHTML = `
                  <img src="?action=thumb&f=${encodeURIComponent(item.cover_file)}" style="width:100%; height:100%; object-fit:cover; display:block;" alt="" onerror="this.onerror=null; this.src='?action=raw&f=${encodeURIComponent(item.cover_file)}'">
                  <div style="position:absolute; bottom:0; left:0; right:0; padding:0.25rem 0.35rem; background:linear-gradient(transparent, rgba(0,0,0,0.85)); font-size:0.68rem; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${this.escape(item.title)}</div>
                  ${isCurrent ? `<div style="position:absolute; top:4px; left:4px; background:var(--accent); color:#fff; font-size:0.62rem; font-weight:800; padding:0.1rem 0.35rem; border-radius:4px;">CURRENT</div>` : ''}
                `;
                track.appendChild(el);
              });

              page++;
              if (page > res.pages) hasMore = false;
            } catch(e) {
              hasMore = false;
            } finally {
              loading = false;
            }
          };

          await loadBatch();

          // If current post was posted earlier and resides on next pages, keep loading until it's loaded in carousel
          while (!document.getElementById(`carousel-item-${currentArtId}`) && hasMore) {
            await loadBatch();
          }

          // Accurately center the carousel track on the current post using relative bounding rects
          setTimeout(() => {
            const targetEl = document.getElementById(`carousel-item-${currentArtId}`);
            if (targetEl && track) {
              const trackRect = track.getBoundingClientRect();
              const targetRect = targetEl.getBoundingClientRect();
              const relativeOffset = targetRect.left - trackRect.left + track.scrollLeft;
              const scrollPos = relativeOffset - (track.clientWidth / 2) + (targetEl.clientWidth / 2);
              track.scrollTo({ left: Math.max(0, scrollPos), behavior: 'smooth' });
            }
          }, 80);

          track.onscroll = () => {
            if (track.scrollLeft + track.clientWidth >= track.scrollWidth - 160) {
              loadBatch();
            }
          };
        }

        async renderCharactersDirectory() {
          this.setTitle('Characters Directory');
          const container = document.getElementById('page-container');
          container.innerHTML = '<div class="spinner"></div>';
          try {
            const res = await this.api('characters_all');
            let html = `
              <div style="max-width:1100px; margin:0 auto; width:100%;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.4rem; flex-wrap:wrap; gap:0.8rem;">
                  <div>
                    <h1 style="font-size:1.4rem; font-weight:800;">Characters Directory</h1>
                    <p style="font-size:0.82rem; color:var(--text-muted);">${res.characters.length} characters depicted in studio</p>
                  </div>
                  <div style="display:flex; align-items:center; gap:0.6rem; flex-wrap:wrap;">
                    <input type="text" class="form-input" style="max-width:220px;" placeholder="Filter characters..." oninput="app.filterDirectory(this.value, '.char-dir-item')">
                    <select class="form-select" style="height:36px; font-size:0.82rem;" onchange="app.sortDirectory('#chars-dir-container', '.char-dir-item', this.value)">
                      <option value="count_desc" selected>Most Artworks</option>
                      <option value="count_asc">Least Artworks</option>
                      <option value="name_asc">Name (A-Z)</option>
                      <option value="name_desc">Name (Z-A)</option>
                    </select>
                  </div>
                </div>
                <div id="chars-dir-container" style="display:flex; flex-wrap:wrap; gap:0.6rem; background:var(--bg-surface); padding:1.4rem; border:1px solid var(--border-subtle); border-radius:14px;">
                  ${res.characters.map(c => `
                    <div class="char-dir-item tag-pill special-character" data-label="${this.escape(c.name).toLowerCase()}" data-name="${this.escape(c.name).toLowerCase()}" data-count="${c.count}" onclick="app.nav('#/explore?character=' + encodeURIComponent('${this.escape(c.name)}'))">
                      <span>${this.escape(c.name)}</span>
                      <span style="opacity:0.6; font-size:0.75rem;">(${c.count})</span>
                    </div>
                  `).join('')}
                </div>
              </div>
            `;
            container.innerHTML = html;
          } catch(e) {
            container.innerHTML = `<div class="center-msg">${e.message}</div>`;
          }
        }

        async renderSeriesDirectory() {
          this.setTitle('Series & Parodies Directory');
          const container = document.getElementById('page-container');
          container.innerHTML = '<div class="spinner"></div>';
          try {
            const res = await this.api('series_all');
            let html = `
              <div style="max-width:1100px; margin:0 auto; width:100%;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.4rem; flex-wrap:wrap; gap:0.8rem;">
                  <div>
                    <h1 style="font-size:1.4rem; font-weight:800;">Series &amp; Parodies Directory</h1>
                    <p style="font-size:0.82rem; color:var(--text-muted);">${res.series.length} series in studio archive</p>
                  </div>
                  <div style="display:flex; align-items:center; gap:0.6rem; flex-wrap:wrap;">
                    <input type="text" class="form-input" style="max-width:220px;" placeholder="Filter series..." oninput="app.filterDirectory(this.value, '.series-dir-item')">
                    <select class="form-select" style="height:36px; font-size:0.82rem;" onchange="app.sortDirectory('#series-dir-container', '.series-dir-item', this.value)">
                      <option value="count_desc" selected>Most Artworks</option>
                      <option value="count_asc">Least Artworks</option>
                      <option value="name_asc">Name (A-Z)</option>
                      <option value="name_desc">Name (Z-A)</option>
                    </select>
                  </div>
                </div>
                <div id="series-dir-container" style="display:flex; flex-wrap:wrap; gap:0.6rem; background:var(--bg-surface); padding:1.4rem; border:1px solid var(--border-subtle); border-radius:14px;">
                  ${res.series.map(s => `
                    <div class="series-dir-item tag-pill special-parody" data-label="${this.escape(s.name).toLowerCase()}" data-name="${this.escape(s.name).toLowerCase()}" data-count="${s.count}" onclick="app.nav('#/explore?parody=' + encodeURIComponent('${this.escape(s.name)}'))">
                      <span>${this.escape(s.name)}</span>
                      <span style="opacity:0.6; font-size:0.75rem;">(${s.count})</span>
                    </div>
                  `).join('')}
                </div>
              </div>
            `;
            container.innerHTML = html;
          } catch(e) {
            container.innerHTML = `<div class="center-msg">${e.message}</div>`;
          }
        }

        startImageCrop(inputEl, mode) {
          const file = inputEl.files && inputEl.files[0];
          if (!file) return;

          const reader = new FileReader();
          reader.onload = (e) => {
            const img = new Image();
            img.onload = () => {
              this.showCropModal(img, mode);
            };
            img.src = e.target.result;
          };
          reader.readAsDataURL(file);
          inputEl.value = '';
        }

        showCropModal(img, mode) {
          const isAvatar = mode === 'avatar';
          const targetW = isAvatar ? 400 : 960;
          const targetH = isAvatar ? 400 : 320;

          const html = `
            <div class="modal-header">
              <span>Crop ${isAvatar ? 'Avatar' : 'Banner'} Image</span>
              <button class="btn-icon" onclick="app.closeModal()"><svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg></button>
            </div>
            <div class="modal-body" style="align-items:center;">
              <div style="font-size:0.8rem; color:var(--text-muted);">Use the slider to zoom and center preview.</div>
              <canvas id="crop-canvas" width="${targetW}" height="${targetH}" style="max-width:100%; max-height:55dvh; border-radius:${isAvatar ? '50%' : '12px'}; border:2px solid var(--accent); background:#000;"></canvas>
              <div style="display:flex; align-items:center; gap:0.8rem; width:100%; max-width:400px; margin-top:0.4rem;">
                <span style="font-size:0.75rem; color:var(--text-muted);">Zoom</span>
                <input type="range" id="crop-zoom" min="1" max="3" step="0.05" value="1" style="flex:1;">
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn-subtle" onclick="app.closeModal()">Cancel</button>
              <button type="button" class="btn-primary" id="btn-apply-crop">Apply Crop</button>
            </div>
          `;
          this.showModal(html);

          const canvas = document.getElementById('crop-canvas');
          const ctx = canvas.getContext('2d');
          let zoom = 1;

          const draw = () => {
            ctx.clearRect(0, 0, targetW, targetH);
            const scale = Math.max(targetW / img.width, targetH / img.height) * zoom;
            const drawW = img.width * scale;
            const drawH = img.height * scale;
            const drawX = (targetW - drawW) / 2;
            const drawY = (targetH - drawH) / 2;
            ctx.drawImage(img, drawX, drawY, drawW, drawH);
          };

          draw();
          const zoomSlider = document.getElementById('crop-zoom');
          zoomSlider.oninput = (e) => {
            zoom = parseFloat(e.target.value);
            draw();
          };

          document.getElementById('btn-apply-crop').onclick = () => {
            const dataUrl = canvas.toDataURL('image/jpeg', 0.9);
            if (isAvatar) {
              const input = document.getElementById('settings-avatar-input');
              const preview = document.getElementById('settings-avatar-preview');
              if (input) input.value = dataUrl;
              if (preview) preview.src = dataUrl;
            } else {
              const input = document.getElementById('settings-banner-input');
              const preview = document.getElementById('settings-banner-preview-wrap');
              if (input) input.value = dataUrl;
              if (preview) preview.style.backgroundImage = `url('${dataUrl}')`;
            }
            this.closeModal();
            this.toast(`${isAvatar ? 'Avatar' : 'Banner'} cropped. Click Save Profile to apply.`);
          };
        }

        async renderSettingsPage() {
          if (!this.user) {
            this.showAuthModal('login');
            this.nav('#/');
            return;
          }
          this.setTitle('Settings');

          const container = document.getElementById('page-container');
          container.innerHTML = `
            <div style="max-width:760px; margin:0 auto; display:flex; flex-direction:column; gap:1.5rem;">
              <div style="display:flex; align-items:center; gap:0.75rem;">
                <button type="button" class="btn-subtle" onclick="window.history.back()" style="gap:0.4rem; height:34px;">
                  <svg viewBox="0 0 24 24" style="width:16px;height:16px;"><path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/></svg>
                  <span>Back</span>
                </button>
                <h1 style="font-size:1.5rem; font-weight:800; letter-spacing:-0.5px; margin:0;">Settings</h1>
              </div>

              <div style="background:var(--bg-surface); border:1px solid var(--border-subtle); border-radius:16px; padding:1.5rem;">
                <h2 style="font-size:1.1rem; font-weight:700; margin-bottom:1.2rem;">Artist Profile</h2>
                <form onsubmit="app.handleSettingsProfileSubmit(event)" style="display:flex; flex-direction:column; gap:1.1rem;">
                  <div class="form-group">
                    <label class="form-label">Artist Pseudonym</label>
                    <input type="text" name="artist_name" class="form-input" value="${this.escape(this.user.artist_name)}" required>
                  </div>
                  <div class="form-group">
                    <label class="form-label">Bio / Studio Statement</label>
                    <textarea name="bio" class="form-textarea" placeholder="Tell fans and commissioners about your art...">${this.escape(this.user.bio || '')}</textarea>
                  </div>

                  <div class="form-group">
                    <label class="form-label">Custom Avatar</label>
                    <div style="display:flex; align-items:center; gap:0.9rem; flex-wrap:wrap;">
                      <img id="settings-avatar-preview" src="${this.getAvatar(this.user.avatar, this.user.artist_name, this.user.email_hash)}" style="width:52px; height:52px; border-radius:50%; object-fit:cover; border:2px solid var(--accent); background:var(--bg-surface-elevated);" alt="">
                      <input type="hidden" name="avatar" id="settings-avatar-input" value="${this.escape(this.user.avatar || '')}">
                      <label class="btn-subtle" style="cursor:pointer; height:34px; font-size:0.8rem;">
                        <span>Upload &amp; Crop Avatar</span>
                        <input type="file" accept="image/*" style="display:none;" onchange="app.startImageCrop(this, 'avatar')">
                      </label>
                    </div>
                  </div>

                  <div class="form-group">
                    <label class="form-label">Header Banner</label>
                    <div style="display:flex; flex-direction:column; gap:0.55rem;">
                      <div id="settings-banner-preview-wrap" style="width:100%; height:74px; border-radius:10px; background-size:cover; background-position:center; border:1px solid var(--border-subtle); ${this.user.banner ? `background-image:url('${this.escape(this.user.banner)}');` : 'background:linear-gradient(135deg, #0096fa, #ff4772);'}"></div>
                      <input type="hidden" name="banner" id="settings-banner-input" value="${this.escape(this.user.banner || '')}">
                      <label class="btn-subtle" style="cursor:pointer; height:34px; font-size:0.8rem; align-self:flex-start;">
                        <span>Upload &amp; Crop Banner</span>
                        <input type="file" accept="image/*" style="display:none;" onchange="app.startImageCrop(this, 'banner')">
                      </label>
                    </div>
                  </div>

                  <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.9rem;">
                    <div class="form-group">
                      <label class="form-label">Twitter / X Handle</label>
                      <input type="text" name="twitter" class="form-input" placeholder="@artist" value="${this.escape(this.user.twitter || '')}">
                    </div>
                    <div class="form-group">
                      <label class="form-label">Website / Portfolio</label>
                      <input type="url" name="website" class="form-input" placeholder="https://..." value="${this.escape(this.user.website || '')}">
                    </div>
                  </div>
                  <div style="display:flex; justify-content:flex-end; margin-top:0.4rem;">
                    <button type="submit" class="btn-primary">Save Profile</button>
                  </div>
                </form>
              </div>

              <div style="background:var(--bg-surface); border:1px solid var(--border-subtle); border-radius:16px; padding:1.5rem;">
                <h2 style="font-size:1.1rem; font-weight:700; margin-bottom:1.2rem;">Account Security</h2>
                <form onsubmit="app.handleSettingsPasswordSubmit(event)" style="display:flex; flex-direction:column; gap:1rem;">
                  <div class="form-group">
                    <label class="form-label">Current Password</label>
                    <input type="password" name="current_password" class="form-input" required>
                  </div>
                  <div class="form-group">
                    <label class="form-label">New Password (Min. 8 chars)</label>
                    <input type="password" name="new_password" class="form-input" minlength="8" required>
                  </div>
                  <div style="display:flex; justify-content:flex-end; margin-top:0.4rem;">
                    <button type="submit" class="btn-primary">Update Password</button>
                  </div>
                </form>
              </div>

              ${Number(this.user.is_admin) !== 2 ? `
                <div style="background:var(--bg-surface); border:1px solid rgba(255, 51, 75, 0.45); border-radius:16px; padding:1.5rem;">
                  <h2 style="font-size:1.1rem; font-weight:700; color:var(--r18); margin-bottom:0.4rem;">Danger Zone</h2>
                  <p style="font-size:0.82rem; color:var(--text-muted); margin-bottom:1.1rem; line-height:1.5;">
                    Permanently delete your artist account and all associated artworks, images, comments, and favorites. This action cannot be undone.
                  </p>
                  <button type="button" class="btn-subtle" style="color:var(--r18); border-color:var(--r18); font-weight:700;" onclick="app.showDeleteAccountModal()">
                    Delete Artist Account
                  </button>
                </div>
              ` : ''}
            </div>
          `;
        }

        showDeleteAccountModal() {
          const html = `
            <div class="modal-header">
              <span style="color:var(--r18); font-weight:800;">Delete Account Confirmation</span>
              <button class="btn-icon" onclick="app.closeModal()"><svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg></button>
            </div>
            <form onsubmit="app.handleDeleteAccountSubmit(event)">
              <div class="modal-body">
                <p style="font-size:0.85rem; color:var(--text-secondary); line-height:1.5;">
                  Are you sure you want to delete your account? All your uploaded artworks, comments, likes, and profile settings will be permanently erased.
                </p>
                <div class="form-group" style="margin-top:0.6rem;">
                  <label class="form-label">Enter your password to confirm</label>
                  <input type="password" name="password" class="form-input" placeholder="••••••••" required autofocus>
                </div>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn-subtle" onclick="app.closeModal()">Cancel</button>
                <button type="submit" class="btn-primary" style="background:var(--r18);">Permanently Delete Account</button>
              </div>
            </form>
          `;
          this.showModal(html);
        }

        async handleDeleteAccountSubmit(e) {
          e.preventDefault();
          const fd = new FormData(e.target);
          try {
            const res = await this.api('account_delete', fd, 'POST');
            this.closeModal();
            this.toast(res.message || 'Account deleted.');
            setTimeout(() => {
              window.location.hash = '#/';
              location.reload();
            }, 700);
          } catch(err) {
            this.toast(err.message);
          }
        }

        async handleSettingsProfileSubmit(e) {
          e.preventDefault();
          const fd = new FormData(e.target);
          fd.append('action', 'update_profile');
          try {
            const res = await this.api('update_profile', fd, 'POST');
            this.user = res.user;
            this.renderUserSlot();
            this.toast('Studio profile updated successfully.');
          } catch(err) {
            this.toast(err.message);
          }
        }

        async handleSettingsPasswordSubmit(e) {
          e.preventDefault();
          const form = e.target;
          const fd = new FormData(form);
          try {
            const res = await this.api('change_password', fd, 'POST');
            this.toast(res.message || 'Password updated.');
            form.reset();
          } catch(err) {
            this.toast(err.message);
          }
        }

        safeUrl(url) {
          if (!url) return '#';
          const trimmed = String(url).trim();
          if (/^https?:\/\//i.test(trimmed)) {
            return this.escape(trimmed);
          }
          return '#';
        }

        escape(str) {
          return (str || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
            .replace(/`/g, '&#96;');
        }
      }
  
      window.app = new HDPostClient();
      if ('serviceWorker' in navigator) navigator.serviceWorker.register('?pwa=sw').catch(() => {});
    </script>
  </body>
</html>