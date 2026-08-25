<?php
/**
 * ██████╗ ██╗  ██╗███████╗██╗     ███████╗
 * ██╔══██╗██║  ██║██╔════╝██║     ██╔════╝
 * ██████╔╝███████║█████╗  ██║     ╚════██║
 * ██╔══██╗██╔══██║██╔══╝  ██║     ╚════██║
 * ██║  ██║██║  ██║███████╗███████╗███████║
 * ╚═╝  ╚═╝╚═╝  ╚═╝╚══════╝╚══════╝╚══════╝
 * Single-File PHP File Manager + Web Terminal
 * Version: 4.0 | UI: Aurora Glass | Security: High
 */

// ============================================================
//  CONFIGURATION — edit these before deploying
// ============================================================
define('FM_PASSWORD',     'changeme123');          // Login password
define('FM_USERNAME',     'admin');                // Login username
define('FM_ROOT',         __DIR__);                // Root directory (restrict to this)
define('FM_SESSION_NAME', 'fm_secure_sess');       // Custom session name
define('FM_MAX_UPLOAD',   100 * 1024 * 1024);      // Max upload: 100 MB
define('FM_SELF',         basename(__FILE__));     // This file's name
define('FM_TERMINAL',     true);                   // Enable web terminal
define('FM_VERSION',      '4.0');

// ============================================================
//  SECURITY BOOTSTRAP
// ============================================================
ini_set('display_errors', 0);
error_reporting(0);

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: same-origin');
header('Content-Security-Policy: default-src \'self\'; style-src \'self\' \'unsafe-inline\' https://fonts.googleapis.com https://fonts.gstatic.com; font-src \'self\' https://fonts.gstatic.com; script-src \'self\' \'unsafe-inline\'; img-src \'self\' data:; media-src \'self\'; frame-src \'self\';');

ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Strict');
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    ini_set('session.cookie_secure', 1);
}
session_name(FM_SESSION_NAME);
session_start();

if (!isset($_SESSION['_last_regen']) || time() - $_SESSION['_last_regen'] > 300) {
    session_regenerate_id(true);
    $_SESSION['_last_regen'] = time();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

if (!isset($_SESSION['login_attempts'])) $_SESSION['login_attempts'] = 0;
if (!isset($_SESSION['lockout_time']))   $_SESSION['lockout_time']   = 0;

// ============================================================
//  HELPER FUNCTIONS
// ============================================================

function fm_is_logged_in() {
    return isset($_SESSION['fm_authenticated']) && $_SESSION['fm_authenticated'] === true;
}

function fm_login($user, $pass) {
    if ($_SESSION['login_attempts'] >= 5) {
        if (time() - $_SESSION['lockout_time'] < 900) {
            return ['ok' => false, 'msg' => 'Too many failed attempts. Try again in 15 minutes.'];
        } else {
            $_SESSION['login_attempts'] = 0;
        }
    }
    if (hash_equals(FM_USERNAME, $user) && hash_equals(FM_PASSWORD, $pass)) {
        session_regenerate_id(true);
        $_SESSION['fm_authenticated'] = true;
        $_SESSION['fm_user']          = $user;
        $_SESSION['login_attempts']   = 0;
        $_SESSION['_login_time']      = time();
        return ['ok' => true];
    }
    $_SESSION['login_attempts']++;
    $_SESSION['lockout_time'] = time();
    return ['ok' => false, 'msg' => 'Invalid credentials.'];
}

function fm_logout() {
    $_SESSION = [];
    session_destroy();
}

function fm_verify_csrf() {
    $token = $_POST['csrf_token'] ?? ($_GET['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        die(json_encode(['error' => 'CSRF token mismatch']));
    }
}

function fm_real_path($path) {
    $path      = FM_ROOT . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
    $real      = realpath($path);
    $root_real = realpath(FM_ROOT);
    if ($real === false || strpos($real, $root_real) !== 0) {
        return false; // Path traversal blocked
    }
    return $real;
}

function fm_rel_path($abs_path) {
    $root = realpath(FM_ROOT);
    $rel  = ltrim(substr($abs_path, strlen($root)), DIRECTORY_SEPARATOR);
    return $rel === '' ? '/' : '/' . str_replace('\\', '/', $rel);
}

function fm_human_size($bytes) {
    if ($bytes === false) return '-';
    $units = ['B','KB','MB','GB','TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < 4) { $bytes /= 1024; $i++; }
    return round($bytes, 2) . ' ' . $units[$i];
}

function fm_mime_type($file) {
    if (function_exists('mime_content_type')) return mime_content_type($file);
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $map = ['php'=>'text/x-php','html'=>'text/html','htm'=>'text/html','css'=>'text/css',
            'js'=>'application/javascript','json'=>'application/json','xml'=>'text/xml',
            'txt'=>'text/plain','md'=>'text/markdown','py'=>'text/x-python',
            'sh'=>'text/x-shellscript','c'=>'text/x-c','cpp'=>'text/x-c++',
            'java'=>'text/x-java','rb'=>'text/x-ruby','go'=>'text/x-go',
            'jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png',
            'gif'=>'image/gif','webp'=>'image/webp','svg'=>'image/svg+xml',
            'ico'=>'image/x-icon','pdf'=>'application/pdf','zip'=>'application/zip',
            'tar'=>'application/x-tar','gz'=>'application/gzip',
            'mp4'=>'video/mp4','webm'=>'video/webm','mov'=>'video/quicktime','mkv'=>'video/x-matroska',
            'mp3'=>'audio/mpeg','m4a'=>'audio/mp4','wav'=>'audio/wav','ogg'=>'audio/ogg','flac'=>'audio/flac'];
    return $map[$ext] ?? 'application/octet-stream';
}

function fm_is_text_file($file) {
    $mime = fm_mime_type($file);
    return strpos($mime, 'text/') === 0
        || in_array($mime, ['application/javascript','application/json','application/xml','image/svg+xml']);
}

function fm_is_image($file) {
    $mime = fm_mime_type($file);
    return strpos($mime, 'image/') === 0 && $mime !== 'image/svg+xml';
}

/** Media types the streaming `preview` endpoint will serve inline. */
function fm_is_streamable($file) {
    $mime = fm_mime_type($file);
    return strpos($mime, 'image/') === 0
        || strpos($mime, 'video/') === 0
        || strpos($mime, 'audio/') === 0
        || $mime === 'application/pdf';
}

function fm_list_dir($path) {
    $real = fm_real_path($path);
    if (!$real || !is_dir($real)) return false;
    $items = [];
    $entries = @scandir($real);
    if (!$entries) return $items;
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        if ($entry === FM_SELF) continue; // hide self
        $full = $real . DIRECTORY_SEPARATOR . $entry;
        $is_dir = is_dir($full);
        $items[] = [
            'name'     => $entry,
            'is_dir'   => $is_dir,
            'size'     => $is_dir ? 0 : @filesize($full),
            'mtime'    => @filemtime($full),
            'perms'    => substr(sprintf('%o', @fileperms($full)), -4),
            'writable' => is_writable($full),
            'path'     => fm_rel_path($full),
        ];
    }
    usort($items, function($a,$b) {
        if ($a['is_dir'] !== $b['is_dir']) return $b['is_dir'] <=> $a['is_dir'];
        return strcasecmp($a['name'], $b['name']);
    });
    return $items;
}

function fm_breadcrumbs($path) {
    $parts = explode('/', trim($path, '/'));
    $crumbs = [['name' => 'Root', 'path' => '/']];
    $acc = '';
    foreach ($parts as $p) {
        if ($p === '') continue;
        $acc .= '/' . $p;
        $crumbs[] = ['name' => $p, 'path' => $acc];
    }
    return $crumbs;
}

function fm_zip_dir($src, $dst) {
    if (!class_exists('ZipArchive')) return false;
    $zip = new ZipArchive();
    if ($zip->open($dst, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return false;
    $src = str_replace('\\', '/', realpath($src));
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($files as $file) {
        $file = str_replace('\\', '/', $file);
        $rel  = substr($file, strlen($src) + 1);
        if (is_dir($file)) $zip->addEmptyDir($rel);
        else                $zip->addFile($file, $rel);
    }
    $zip->close();
    return true;
}

// ============================================================
//  AJAX / ACTION HANDLER
// ============================================================
if (isset($_GET['ajax']) && fm_is_logged_in()) {
    header('Content-Type: application/json');
    $action = $_GET['action'] ?? '';

    // Actions that modify state require CSRF
    $state_actions = ['mkdir','rename','delete','upload','save','chmod','extract','compress','terminal','touch'];
    if (in_array($action, $state_actions)) {
        fm_verify_csrf();
    }

    switch ($action) {

        // --- List directory ---
        case 'ls':
            $path  = $_GET['path'] ?? '/';
            $items = fm_list_dir($path);
            if ($items === false) { echo json_encode(['error' => 'Cannot access directory']); break; }
            $crumbs = fm_breadcrumbs($path);
            echo json_encode(['items' => $items, 'crumbs' => $crumbs, 'path' => $path]);
            break;

        // --- Read file ---
        case 'read':
            $path = $_GET['path'] ?? '';
            $real = fm_real_path($path);
            if (!$real || !is_file($real)) { echo json_encode(['error' => 'File not found']); break; }
            if (!fm_is_text_file($real)) { echo json_encode(['error' => 'Binary file — not editable']); break; }
            $size = filesize($real);
            if ($size > 2 * 1024 * 1024) { echo json_encode(['error' => 'File too large to edit (>2MB)']); break; }
            echo json_encode(['content' => file_get_contents($real), 'path' => $path, 'name' => basename($real)]);
            break;

        // --- Save file ---
        case 'save':
            $path    = $_POST['path'] ?? '';
            $content = $_POST['content'] ?? '';
            $real    = fm_real_path($path);
            if (!$real || !is_file($real)) { echo json_encode(['error' => 'File not found']); break; }
            if (!is_writable($real))        { echo json_encode(['error' => 'File not writable']); break; }
            $backup = $real . '.bak';
            copy($real, $backup);
            if (file_put_contents($real, $content) !== false) {
                @unlink($backup);
                echo json_encode(['ok' => true]);
            } else {
                copy($backup, $real);
                @unlink($backup);
                echo json_encode(['error' => 'Write failed']);
            }
            break;

        // --- Create empty file (new in v4 — no more terminal hack) ---
        case 'touch':
            $relPath = (string) ($_POST['path'] ?? '');
            $name    = basename(trim($relPath));
            if (!$name || $relPath !== '/' . ltrim(str_replace('\\', '/', $relPath), '/') || strpos($relPath, '..') !== false
                || preg_match('/[\\/:*?"<>|]/', $name)) {
                echo json_encode(['error' => 'Invalid name or path']); break;
            }
            $realDir = fm_real_path(dirname($relPath));
            if (!$realDir || !is_dir($realDir) || !is_writable($realDir)) { echo json_encode(['error' => 'Directory not writable']); break; }
            $target = $realDir . DIRECTORY_SEPARATOR . $name;
            if (file_exists($target)) { echo json_encode(['error' => 'Already exists']); break; }
            echo json_encode(@file_put_contents($target, '') !== false ? ['ok' => true] : ['error' => 'Create failed']);
            break;

        // --- Create directory ---
        case 'mkdir':
            $parent = $_POST['path'] ?? '/';
            $name   = basename(trim($_POST['name'] ?? ''));
            if (!$name || preg_match('/[\\/:*?"<>|]/', $name)) { echo json_encode(['error' => 'Invalid name']); break; }
            $target = fm_real_path($parent);
            if (!$target) { echo json_encode(['error' => 'Invalid path']); break; }
            $new_dir = $target . DIRECTORY_SEPARATOR . $name;
            if (file_exists($new_dir)) { echo json_encode(['error' => 'Already exists']); break; }
            if (@mkdir($new_dir, 0755)) echo json_encode(['ok' => true]);
            else echo json_encode(['error' => 'Failed to create directory']);
            break;

        // --- Rename ---
        case 'rename':
            $path    = $_POST['path'] ?? '';
            $newname = basename(trim($_POST['newname'] ?? ''));
            if (!$newname || preg_match('/[\\/:*?"<>|]/', $newname)) { echo json_encode(['error' => 'Invalid name']); break; }
            $real    = fm_real_path($path);
            if (!$real || !file_exists($real)) { echo json_encode(['error' => 'Source not found']); break; }
            $new_path = dirname($real) . DIRECTORY_SEPARATOR . $newname;
            if (file_exists($new_path)) { echo json_encode(['error' => 'Target already exists']); break; }
            if (@rename($real, $new_path)) echo json_encode(['ok' => true]);
            else echo json_encode(['error' => 'Rename failed']);
            break;

        // --- Delete ---
        case 'delete':
            $path = $_POST['path'] ?? '';
            $real = fm_real_path($path);
            if (!$real || !file_exists($real)) { echo json_encode(['error' => 'Not found']); break; }
            function fm_rmrf($path) {
                if (is_dir($path)) {
                    foreach (scandir($path) as $f) {
                        if ($f !== '.' && $f !== '..') fm_rmrf($path . DIRECTORY_SEPARATOR . $f);
                    }
                    return @rmdir($path);
                }
                return @unlink($path);
            }
            if (fm_rmrf($real)) echo json_encode(['ok' => true]);
            else echo json_encode(['error' => 'Delete failed']);
            break;

        // --- Upload ---
        case 'upload':
            $dir = $_POST['path'] ?? '/';
            $real_dir = fm_real_path($dir);
            if (!$real_dir || !is_dir($real_dir) || !is_writable($real_dir)) {
                echo json_encode(['error' => 'Target directory not writable']); break;
            }
            $results = [];
            if (!empty($_FILES['files'])) {
                $files = $_FILES['files'];
                $count = is_array($files['name']) ? count($files['name']) : 1;
                for ($i = 0; $i < $count; $i++) {
                    $tmp  = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
                    $name = is_array($files['name'])     ? $files['name'][$i]     : $files['name'];
                    $err  = is_array($files['error'])    ? $files['error'][$i]    : $files['error'];
                    $size = is_array($files['size'])     ? $files['size'][$i]     : $files['size'];
                    if ($err !== UPLOAD_ERR_OK) { $results[] = ['name' => $name, 'ok' => false, 'msg' => 'Upload error']; continue; }
                    if ($size > FM_MAX_UPLOAD)  { $results[] = ['name' => $name, 'ok' => false, 'msg' => 'File too large']; continue; }
                    $safe_name = preg_replace('/[^a-zA-Z0-9._\-]/', '_', basename($name));
                    if ($safe_name === '' || $safe_name === '.' || $safe_name === '..') {
                        $results[] = ['name' => $name, 'ok' => false, 'msg' => 'Invalid filename']; continue;
                    }
                    $dest = $real_dir . DIRECTORY_SEPARATOR . $safe_name;
                    if (move_uploaded_file($tmp, $dest)) {
                        $results[] = ['name' => $safe_name, 'ok' => true];
                    } else {
                        $results[] = ['name' => $name, 'ok' => false, 'msg' => 'Move failed'];
                    }
                }
            }
            echo json_encode(['results' => $results]);
            break;

        // --- Download ---
        case 'download':
            $path = $_GET['path'] ?? '';
            $real = fm_real_path($path);
            if (!$real || !is_file($real)) { echo json_encode(['error' => 'Not found']); break; }
            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . addslashes(basename($real)) . '"');
            header('Content-Length: ' . filesize($real));
            header('Cache-Control: no-cache');
            ob_end_clean();
            readfile($real);
            exit;

        // --- Stream preview (images, video w/ seeking, audio, PDF) ---
        case 'preview':
            $path = $_GET['path'] ?? '';
            $real = fm_real_path($path);
            if (!$real || !is_file($real) || !fm_is_streamable($real)) { http_response_code(404); exit; }

            fm_verify_csrf();
            $mime = fm_mime_type($real);
            $size = filesize($real);
            $start = 0; $end = $size - 1;

            if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
                if ($m[1] !== '') $start = (int) $m[1];
                if ($m[2] !== '') $end   = min((int) $m[2], $size - 1);
                http_response_code(206);
                header("Content-Range: bytes $start-$end/$size");
            }
            header('Accept-Ranges: bytes');
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . ($end - $start + 1));
            header('Cache-Control: max-age=600');
            ob_end_clean();

            $fp = fopen($real, 'rb');
            fseek($fp, $start);
            $remaining = $end - $start + 1;
            while ($remaining > 0 && !feof($fp)) {
                $chunk = fread($fp, min(8192, $remaining));
                if ($chunk === false) break;
                echo $chunk;
                $remaining -= strlen($chunk);
            }
            fclose($fp);
            exit;

        // --- Chmod ---
        case 'chmod':
            $path  = $_POST['path']  ?? '';
            $perms = $_POST['perms'] ?? '';
            if (!preg_match('/^[0-7]{3,4}$/', $perms)) { echo json_encode(['error' => 'Invalid permissions']); break; }
            $real = fm_real_path($path);
            if (!$real || !file_exists($real)) { echo json_encode(['error' => 'Not found']); break; }
            if (@chmod($real, octdec($perms))) echo json_encode(['ok' => true]);
            else echo json_encode(['error' => 'chmod failed — check permissions']);
            break;

        // --- Extract ZIP ---
        case 'extract':
            $path = $_POST['path'] ?? '';
            $real = fm_real_path($path);
            if (!$real || !is_file($real) || !class_exists('ZipArchive')) {
                echo json_encode(['error' => 'Cannot extract — ZipArchive not available']); break;
            }
            $zip = new ZipArchive();
            if ($zip->open($real) !== true) { echo json_encode(['error' => 'Cannot open ZIP']); break; }
            $dest = dirname($real) . DIRECTORY_SEPARATOR . pathinfo($real, PATHINFO_FILENAME);
            @mkdir($dest, 0755);
            $zip->extractTo($dest);
            $zip->close();
            echo json_encode(['ok' => true]);
            break;

        // --- Compress to ZIP ---
        case 'compress':
            $path  = $_POST['path'] ?? '';
            $real  = fm_real_path($path);
            if (!$real || !file_exists($real)) { echo json_encode(['error' => 'Not found']); break; }
            $zip_name = $real . '.zip';
            if (is_dir($real)) {
                $ok = fm_zip_dir($real, $zip_name);
            } else {
                if (!class_exists('ZipArchive')) { echo json_encode(['error' => 'ZipArchive not available']); break; }
                $zip = new ZipArchive();
                $ok  = ($zip->open($zip_name, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
                if ($ok) { $zip->addFile($real, basename($real)); $zip->close(); }
            }
            if ($ok) echo json_encode(['ok' => true, 'zip' => fm_rel_path($zip_name)]);
            else     echo json_encode(['error' => 'Compression failed']);
            break;

        // --- Copy/Move ---
        case 'copy':
        case 'move':
            $src  = $_POST['src']  ?? '';
            $dest = $_POST['dest'] ?? '';
            $real_src  = fm_real_path($src);
            $real_dest = fm_real_path($dest);
            if (!$real_src || !file_exists($real_src))  { echo json_encode(['error' => 'Source not found']); break; }
            if (!$real_dest || !is_dir($real_dest))     { echo json_encode(['error' => 'Destination invalid']); break; }
            $target = $real_dest . DIRECTORY_SEPARATOR . basename($real_src);
            if ($action === 'copy') {
                function fm_copy_r($src, $dst) {
                    if (is_dir($src)) {
                        @mkdir($dst, 0755, true);
                        foreach (scandir($src) as $f) {
                            if ($f !== '.' && $f !== '..') fm_copy_r("$src/$f", "$dst/$f");
                        }
                        return true;
                    }
                    return copy($src, $dst);
                }
                $ok = fm_copy_r($real_src, $target);
            } else {
                $ok = @rename($real_src, $target);
            }
            if ($ok) echo json_encode(['ok' => true]);
            else     echo json_encode(['error' => ucfirst($action) . ' failed']);
            break;

        // --- Search ---
        case 'search':
            $path    = $_GET['path']  ?? '/';
            $query   = trim($_GET['q'] ?? '');
            $real    = fm_real_path($path);
            if (!$query || !$real) { echo json_encode(['results' => []]); break; }
            $results = [];
            $rit = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($real, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            $count = 0;
            foreach ($rit as $f) {
                if ($count > 500) break;
                if (stripos($f->getFilename(), $query) !== false) {
                    $results[] = [
                        'name'   => $f->getFilename(),
                        'path'   => fm_rel_path($f->getPathname()),
                        'is_dir' => $f->isDir(),
                        'size'   => $f->isDir() ? 0 : $f->getSize(),
                    ];
                    $count++;
                }
            }
            echo json_encode(['results' => $results]);
            break;

        // --- Web Terminal ---
        case 'terminal':
            if (!FM_TERMINAL) { echo json_encode(['error' => 'Terminal disabled']); break; }
            $cmd = trim($_POST['cmd'] ?? '');
            $cwd = trim($_POST['cwd'] ?? FM_ROOT);
            $real_cwd = realpath($cwd);
            if (!$real_cwd || strpos($real_cwd, realpath(FM_ROOT)) !== 0) {
                $real_cwd = FM_ROOT;
            }
            if (!$cmd) { echo json_encode(['output' => '', 'cwd' => $real_cwd]); break; }
            if (preg_match('/^cd\s+(.+)$/', $cmd, $m)) {
                $target = $m[1] === '~' ? FM_ROOT : (
                    $m[1][0] === '/' ? $m[1] : $real_cwd . '/' . $m[1]
                );
                $new_cwd = realpath($target);
                if ($new_cwd && strpos($new_cwd, realpath(FM_ROOT)) === 0 && is_dir($new_cwd)) {
                    echo json_encode(['output' => '', 'cwd' => $new_cwd]);
                } else {
                    echo json_encode(['output' => "cd: $target: No such directory or outside root\n", 'cwd' => $real_cwd]);
                }
                break;
            }
            $blocked = ['rm -rf /', 'mkfs', 'dd if=', ':(){ :|:& };:', 'chmod 777 /', 'shutdown', 'reboot', 'halt', 'init 0'];
            foreach ($blocked as $b) {
                if (stripos($cmd, $b) !== false) {
                    echo json_encode(['output' => "⛔ Command blocked for security.\n", 'cwd' => $real_cwd]);
                    break 2;
                }
            }
            $descriptor = [['pipe','r'],['pipe','w'],['pipe','w']];
            $env = ['HOME' => FM_ROOT, 'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'];
            $proc = proc_open($cmd, $descriptor, $pipes, $real_cwd, $env);
            if (!is_resource($proc)) { echo json_encode(['output' => "Failed to execute command\n", 'cwd' => $real_cwd]); break; }
            fclose($pipes[0]);
            stream_set_timeout($pipes[1], 10);
            stream_set_timeout($pipes[2], 10);
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
            $output = ($out ?? '') . ($err ?? '');
            echo json_encode(['output' => $output, 'cwd' => $real_cwd]);
            break;

        // --- Disk info ---
        case 'diskinfo':
            $root  = FM_ROOT;
            $total = @disk_total_space($root);
            $free  = @disk_free_space($root);
            echo json_encode([
                'total'   => $total,
                'free'    => $free,
                'used'    => $total - $free,
                'total_h' => fm_human_size($total),
                'free_h'  => fm_human_size($free),
                'used_h'  => fm_human_size($total - $free),
                'pct'     => $total > 0 ? round(($total - $free) / $total * 100, 1) : 0,
                'php'     => PHP_VERSION,
                'os'      => PHP_OS,
            ]);
            break;

        default:
            echo json_encode(['error' => 'Unknown action']);
    }
    exit;
}

// ============================================================
//  LOGIN HANDLER
// ============================================================
$login_error = '';
if (!fm_is_logged_in()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fm_login'])) {
        $result = fm_login($_POST['username'] ?? '', $_POST['password'] ?? '');
        if ($result['ok']) {
            header('Location: ' . FM_SELF);
            exit;
        }
        $login_error = $result['msg'];
    }
}

// Logout
if (isset($_GET['logout'])) {
    fm_logout();
    header('Location: ' . FM_SELF);
    exit;
}

// Auto-session expiry: 2 hours
if (fm_is_logged_in() && isset($_SESSION['_login_time']) && time() - $_SESSION['_login_time'] > 7200) {
    fm_logout();
    header('Location: ' . FM_SELF . '?expired=1');
    exit;
}

?><!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="color-scheme" content="dark light">
<title>RhelsFS — File Manager</title>
<script>try{document.documentElement.dataset.theme=localStorage.getItem('fm_theme')||'dark'}catch(e){}</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
/* ============================================================
   RHELSFS v4 — AURORA GLASS DESIGN SYSTEM
   Dark-first, light theme via [data-theme="light"].
   Fully responsive: drawer sidebar + bottom sheets on mobile.
   ============================================================ */
:root{
  --bg0:#05070d; --bg1:#0a0e18; --bg2:#101627; --bg3:#171f36;
  --glass:rgba(255,255,255,.045); --glass-hi:rgba(255,255,255,.09);
  --border:rgba(148,163,199,.14); --border-hi:rgba(148,163,199,.30);
  --text0:#eef1fa; --text1:#9aa4c0; --text2:#5b6580;
  --acc:#818cf8; --acc2:#22d3ee; --grad:linear-gradient(135deg,#6366f1,#22d3ee);
  --green:#34d399; --red:#fb7185; --amber:#fbbf24;
  --mono:'JetBrains Mono',ui-monospace,monospace;
  --sans:'Inter',system-ui,-apple-system,sans-serif;
  --r-sm:8px; --r-md:12px; --r-lg:18px;
  --shadow:0 10px 40px rgba(0,0,0,.45);
  --shadow-sm:0 2px 12px rgba(0,0,0,.35);
  --cols:34px minmax(0,1fr) 92px 150px 74px 38px;
  --ease:cubic-bezier(.22,.9,.3,1);
}
[data-theme="light"]{
  --bg0:#eef1f8; --bg1:#f7f9fd; --bg2:#ffffff; --bg3:#eceff7;
  --glass:rgba(15,23,42,.035); --glass-hi:rgba(15,23,42,.07);
  --border:rgba(15,23,42,.10); --border-hi:rgba(15,23,42,.22);
  --text0:#101627; --text1:#475069; --text2:#8b94ad;
  --shadow:0 12px 40px rgba(30,41,72,.12);
  --shadow-sm:0 2px 10px rgba(30,41,72,.08);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%}
body{
  background:var(--bg0); color:var(--text0);
  font-family:var(--sans); font-size:13.5px; line-height:1.5;
  overflow:hidden; -webkit-tap-highlight-color:transparent;
}
body::before{ /* aurora glow */
  content:''; position:fixed; inset:-20%; z-index:-1; pointer-events:none;
  background:
    radial-gradient(600px 420px at 12% -8%, rgba(99,102,241,.16), transparent 60%),
    radial-gradient(700px 480px at 105% 8%, rgba(34,211,238,.11), transparent 60%),
    radial-gradient(560px 420px at 55% 115%, rgba(139,92,246,.10), transparent 60%);
}
::-webkit-scrollbar{width:9px;height:9px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:var(--border-hi);border-radius:99px;border:2px solid transparent;background-clip:content-box}
::selection{background:rgba(99,102,241,.35)}
button{font-family:inherit;color:inherit}
input,textarea{font-family:inherit;color:inherit}
:focus-visible{outline:2px solid var(--acc);outline-offset:2px;border-radius:4px}

/* ── GENERIC CONTROLS ─────────────────────────────── */
.btn{
  display:inline-flex;align-items:center;gap:7px;
  background:var(--glass);border:1px solid var(--border);
  border-radius:10px;color:var(--text1);
  font-size:12px;font-weight:600;padding:7px 13px;cursor:pointer;
  transition:all .16s var(--ease);white-space:nowrap;user-select:none;
}
.btn:hover{border-color:var(--border-hi);color:var(--text0);background:var(--glass-hi)}
.btn:active{transform:scale(.97)}
.btn.primary{background:var(--grad);border:none;color:#fff;box-shadow:0 4px 18px rgba(79,70,229,.35)}
.btn.primary:hover{filter:brightness(1.1)}
.btn.danger{color:var(--red);border-color:color-mix(in srgb,var(--red) 35%,transparent)}
.btn.danger:hover{background:color-mix(in srgb,var(--red) 12%,transparent)}
.btn.icon{padding:7px;width:32px;height:32px;justify-content:center;font-size:14px;border-radius:9px}
.btn.sm{padding:4px 9px;font-size:11px}
.btn:disabled{opacity:.4;pointer-events:none}
.kbd{
  font-family:var(--mono);font-size:10px;color:var(--text2);
  border:1px solid var(--border);border-bottom-width:2px;border-radius:5px;
  padding:1px 5px;background:var(--glass);
}

/* ── LOGIN ────────────────────────────────────────── */
.login-wrap{
  min-height:100vh;display:flex;align-items:center;justify-content:center;
  padding:20px;gap:60px;flex-wrap:wrap;
}
.login-brand{max-width:380px}
.login-brand .logo-big{
  width:64px;height:64px;border-radius:20px;background:var(--grad);
  display:flex;align-items:center;justify-content:center;font-size:30px;
  box-shadow:0 10px 34px rgba(79,70,229,.45);margin-bottom:22px;
}
.login-brand h1{
  font-size:34px;font-weight:800;letter-spacing:-.02em;line-height:1.15;
  background:linear-gradient(90deg,var(--text0),var(--acc));-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;
}
.login-brand .tag{color:var(--text1);margin-top:10px;font-size:14px}
.login-feats{margin-top:28px;display:flex;flex-direction:column;gap:12px}
.login-feat{display:flex;gap:12px;align-items:flex-start;color:var(--text1);font-size:13px}
.login-feat b{color:var(--text0);display:block;font-size:13px}
.login-feat .fi{
  width:34px;height:34px;flex-shrink:0;border-radius:10px;background:var(--glass);
  border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:16px;
}
.login-card{
  width:380px;max-width:94vw;background:var(--glass);
  border:1px solid var(--border);border-radius:22px;padding:36px 32px;
  backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px);box-shadow:var(--shadow);
}
.login-card h2{font-size:19px;font-weight:700;margin-bottom:2px}
.login-card .sub{color:var(--text2);font-size:12.5px;margin-bottom:24px}
.fgroup{margin-bottom:14px}
.fgroup label{
  display:block;font-size:10.5px;font-weight:700;letter-spacing:.09em;
  text-transform:uppercase;color:var(--text2);margin-bottom:6px;
}
.input-wrap{position:relative}
.input-wrap .toggle-pw{
  position:absolute;right:6px;top:50%;transform:translateY(-50%);
  background:none;border:none;color:var(--text2);cursor:pointer;font-size:14px;padding:6px;border-radius:6px;
}
.input-wrap .toggle-pw:hover{color:var(--text0)}
.form-control{
  width:100%;background:var(--bg2);border:1px solid var(--border);
  border-radius:11px;color:var(--text0);font-size:13.5px;padding:11px 13px;outline:none;
  transition:border-color .15s,box-shadow .15s;
}
.form-control:focus{border-color:var(--acc);box-shadow:0 0 0 3px rgba(99,102,241,.18)}
.login-error{
  background:color-mix(in srgb,var(--red) 12%,transparent);
  border:1px solid color-mix(in srgb,var(--red) 35%,transparent);
  color:var(--red);font-size:12.5px;padding:9px 13px;border-radius:10px;margin-bottom:16px;
}
.btn-login{
  width:100%;background:var(--grad);color:#fff;border:none;border-radius:11px;
  font-size:13.5px;font-weight:700;padding:12px;cursor:pointer;margin-top:6px;
  transition:filter .15s,transform .1s;letter-spacing:.02em;
}
.btn-login:hover{filter:brightness(1.08)}
.btn-login:active{transform:scale(.985)}
.login-foot{text-align:center;color:var(--text2);font-family:var(--mono);font-size:10.5px;margin-top:22px}

/* ── APP SHELL ────────────────────────────────────── */
#app{display:flex;flex-direction:column;height:100vh;height:100dvh}
.topbar{
  display:flex;align-items:center;gap:10px;height:54px;padding:0 14px;flex-shrink:0;
  background:var(--glass);border-bottom:1px solid var(--border);
  backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);
  user-select:none;z-index:60;
}
.brand{display:flex;align-items:center;gap:9px;font-weight:800;font-size:14.5px;letter-spacing:-.01em}
.brand .logo-mini{
  width:28px;height:28px;border-radius:9px;background:var(--grad);
  display:flex;align-items:center;justify-content:center;font-size:14px;
  box-shadow:0 3px 12px rgba(79,70,229,.4);
}
.brand em{font-style:normal;color:var(--text2);font-weight:600}
.topbar-sep{flex:1}
.hamburger{display:none}
.main{display:flex;flex:1;overflow:hidden}

/* ── SIDEBAR ──────────────────────────────────────── */
.sidebar{
  width:228px;flex-shrink:0;display:flex;flex-direction:column;
  background:var(--glass);border-right:1px solid var(--border);
  overflow-y:auto;z-index:1200;
}
.sb-section{padding:16px 10px 6px}
.sb-heading{
  font-family:var(--mono);font-size:9.5px;text-transform:uppercase;letter-spacing:.16em;
  color:var(--text2);padding:0 10px 8px;
}
.sb-link{
  display:flex;align-items:center;gap:10px;padding:8px 11px;margin:1px 0;
  color:var(--text1);font-size:12.5px;font-weight:500;cursor:pointer;
  border-radius:10px;border:1px solid transparent;transition:all .13s;
}
.sb-link:hover{background:var(--glass-hi);color:var(--text0)}
.sb-link.active{background:linear-gradient(90deg,rgba(99,102,241,.16),rgba(34,211,238,.06));border-color:rgba(99,102,241,.25);color:var(--text0)}
.sb-link .si{width:20px;text-align:center;font-size:14px}
.sb-spacer{flex:1;min-height:20px}
.disk-widget{padding:16px;text-align:center}
.disk-ring{position:relative;width:104px;height:104px;margin:0 auto 10px}
.disk-ring svg{transform:rotate(-90deg)}
.disk-ring .ring-bg{fill:none;stroke:var(--border);stroke-width:8}
.disk-ring .ring-fg{
  fill:none;stroke:url(#ringGrad);stroke-width:8;stroke-linecap:round;
  transition:stroke-dashoffset .8s var(--ease);
}
.disk-ring .ring-txt{
  position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;
}
.disk-ring .ring-txt b{font-size:19px;font-weight:800}
.disk-ring .ring-txt span{font-size:9.5px;color:var(--text2);font-family:var(--mono);text-transform:uppercase;letter-spacing:.1em}
.disk-meta{font-family:var(--mono);font-size:10px;color:var(--text2);line-height:1.8}
.disk-meta b{color:var(--text1);font-weight:600}
.sb-chip{
  margin:10px 14px 16px;padding:8px 10px;border-radius:10px;
  background:var(--glass);border:1px solid var(--border);
  font-family:var(--mono);font-size:9.5px;color:var(--text2);
  display:flex;align-items:center;justify-content:space-between;
}
.sb-backdrop{position:fixed;inset:0;background:rgba(3,5,10,.6);z-index:1100;backdrop-filter:blur(2px)}

/* ── CONTENT / TOOLBAR ────────────────────────────── */
.content{flex:1;display:flex;flex-direction:column;overflow:hidden;min-width:0}
.toolbar{
  display:flex;align-items:center;gap:8px;padding:9px 14px;flex-shrink:0;flex-wrap:wrap;
  border-bottom:1px solid var(--border);background:var(--glass);
}
.nav-arrows{display:flex;gap:4px}
.crumbwrap{flex:1;min-width:140px;overflow-x:auto;scrollbar-width:none}
.crumbwrap::-webkit-scrollbar{display:none}
.crumbs{display:inline-flex;align-items:center;font-family:var(--mono);font-size:11.5px;white-space:nowrap;padding:2px 0}
.crumb{color:var(--text2);cursor:pointer;padding:3px 5px;border-radius:6px;transition:all .12s}
.crumb:hover{color:var(--acc2);background:var(--glass-hi)}
.crumb.last{color:var(--text0);cursor:default;font-weight:600}
.crumb-sep{color:var(--text2);opacity:.5;margin:0 1px}
.filter-box{
  display:flex;align-items:center;gap:6px;background:var(--bg2);
  border:1px solid var(--border);border-radius:10px;padding:0 10px;transition:border-color .15s;
}
.filter-box:focus-within{border-color:var(--acc)}
.filter-box input{
  background:none;border:none;outline:none;color:var(--text0);
  font-size:12.5px;padding:7px 0;width:150px;
}
.filter-box input::placeholder{color:var(--text2)}
.filter-box .fx{background:none;border:none;color:var(--text2);cursor:pointer;font-size:13px;padding:2px;display:none}
.filter-box.has-q .fx{display:block}
.sort-wrap{position:relative}
.pop-menu{
  position:absolute;top:calc(100% + 6px);right:0;min-width:170px;z-index:900;
  background:var(--bg2);border:1px solid var(--border-hi);border-radius:13px;padding:5px;
  box-shadow:var(--shadow);animation:popIn .14s var(--ease);
}
@keyframes popIn{from{opacity:0;transform:translateY(-5px) scale(.98)}to{opacity:1;transform:none}}
.pop-item{
  display:flex;align-items:center;gap:9px;width:100%;text-align:left;
  padding:7px 10px;border:none;background:none;color:var(--text1);
  font-size:12.5px;border-radius:8px;cursor:pointer;
}
.pop-item:hover{background:var(--glass-hi);color:var(--text0)}
.pop-item.on{color:var(--acc);font-weight:600}
.pop-sep{height:1px;background:var(--border);margin:5px 8px}

/* ── FILE AREA ────────────────────────────────────── */
.file-area{flex:1;overflow-y:auto;overflow-x:hidden;position:relative;scroll-behavior:smooth}
.fhead,.frow{
  display:grid;grid-template-columns:var(--cols);align-items:center;
  gap:8px;padding:0 14px;min-width:520px;
}
.fhead{
  position:sticky;top:0;z-index:20;background:var(--bg1);
  border-bottom:1px solid var(--border);
  font-family:var(--mono);font-size:9.5px;text-transform:uppercase;letter-spacing:.12em;
  color:var(--text2);height:36px;
}
.fhead .sortable{cursor:pointer;user-select:none;display:flex;align-items:center;gap:4px}
.fhead .sortable:hover{color:var(--text1)}
.fhead .sortable.on{color:var(--acc)}
.frow{
  height:46px;border-bottom:1px solid var(--border);
  cursor:pointer;transition:background .1s;position:relative;
}
.frow:hover{background:var(--glass)}
.frow.sel{background:linear-gradient(90deg,rgba(99,102,241,.13),rgba(34,211,238,.05))}
.frow.sel::before{content:'';position:absolute;left:0;top:0;bottom:0;width:2.5px;background:var(--grad)}
.chk{display:flex;align-items:center;justify-content:center}
.chk input{accent-color:var(--acc);width:15px;height:15px;cursor:pointer}
.fic{font-size:19px;text-align:center;filter:drop-shadow(0 2px 4px rgba(0,0,0,.3))}
.fname{min-width:0;display:flex;align-items:center;gap:8px}
.fname .nm{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:500}
.fname .lock{font-size:10px;opacity:.7}
.fsize,.fdate,.fperm{font-family:var(--mono);font-size:10.5px;color:var(--text1);white-space:nowrap}
.fperm{color:var(--text2)}
.fmore{
  width:30px;height:30px;border-radius:8px;border:none;background:none;
  color:var(--text2);font-size:17px;cursor:pointer;opacity:0;transition:all .12s;
  display:flex;align-items:center;justify-content:center;
}
.frow:hover .fmore,.gcard:hover .fmore,.fmore:focus{opacity:1}
.fmore:hover{background:var(--glass-hi);color:var(--text0)}
.dir-name{color:var(--text0)}

/* grid view */
.grid-view{
  display:grid;grid-template-columns:repeat(auto-fill,minmax(112px,1fr));
  gap:12px;padding:16px;
}
.gcard{
  display:flex;flex-direction:column;align-items:center;gap:8px;
  padding:14px 8px 10px;border-radius:14px;cursor:pointer;position:relative;
  background:var(--glass);border:1px solid var(--border);transition:all .15s var(--ease);
}
.gcard:hover{border-color:var(--border-hi);transform:translateY(-2px);box-shadow:var(--shadow-sm)}
.gcard.sel{border-color:var(--acc);background:rgba(99,102,241,.10)}
.gcard .thumb{
  width:64px;height:64px;border-radius:11px;object-fit:cover;
  background:var(--bg3);box-shadow:var(--shadow-sm);
}
.gcard .gi{font-size:34px;line-height:64px}
.gcard .gn{
  font-size:11px;color:var(--text1);text-align:center;width:100%;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
}
.gcard .chk{position:absolute;top:7px;left:7px;opacity:0;transition:opacity .12s}
.gcard:hover .chk,.gcard.sel .chk{opacity:1}

/* skeleton + empty */
.skl-row{height:46px;border-bottom:1px solid var(--border);display:grid;grid-template-columns:var(--cols);align-items:center;gap:8px;padding:0 14px;min-width:520px}
.skl-bar{height:11px;border-radius:6px;background:linear-gradient(90deg,var(--glass) 25%,var(--glass-hi) 50%,var(--glass) 75%);background-size:200% 100%;animation:shimmer 1.2s infinite}
@keyframes shimmer{to{background-position:-200% 0}}
.empty-state{
  display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;
  padding:80px 20px;color:var(--text2);text-align:center;
}
.empty-state .big{font-size:52px;filter:grayscale(.3);opacity:.85}
.empty-state h3{color:var(--text1);font-size:15px;font-weight:600}
.empty-state p{font-size:12.5px;max-width:300px}

/* drag overlay */
.drop-overlay{
  position:absolute;inset:8px;z-index:500;border-radius:18px;
  border:2px dashed var(--acc);background:color-mix(in srgb,var(--acc) 8%,var(--bg1));
  display:none;align-items:center;justify-content:center;flex-direction:column;gap:10px;
  color:var(--text0);pointer-events:none;font-weight:600;
}
.drop-overlay.show{display:flex}
.drop-overlay .di{font-size:44px}

/* bulk bar */
.bulkbar{
  position:fixed;left:50%;bottom:52px;transform:translateX(-50%) translateY(20px);
  z-index:800;display:flex;align-items:center;gap:6px;padding:8px 10px;
  background:var(--bg2);border:1px solid var(--border-hi);border-radius:16px;
  box-shadow:var(--shadow);opacity:0;pointer-events:none;transition:all .22s var(--ease);
  max-width:min(94vw,720px);flex-wrap:wrap;justify-content:center;
}
.bulkbar.show{opacity:1;pointer-events:auto;transform:translateX(-50%)}
.bulkbar .count{
  font-family:var(--mono);font-size:11.5px;font-weight:600;color:var(--acc);
  background:rgba(99,102,241,.12);border-radius:8px;padding:5px 10px;margin-right:2px;
}

/* statusbar */
.statusbar{
  display:flex;align-items:center;gap:14px;height:28px;padding:0 14px;flex-shrink:0;
  background:var(--glass);border-top:1px solid var(--border);
  font-family:var(--mono);font-size:10px;color:var(--text2);user-select:none;
}
.statusbar b{color:var(--text1);font-weight:600}
#status-msg{color:var(--green)}
#status-msg.err{color:var(--red)}

/* ── TERMINAL ─────────────────────────────────────── */
.term-panel{
  height:280px;background:#04060c;border-top:2px solid rgba(99,102,241,.5);
  display:flex;flex-direction:column;flex-shrink:0;font-family:var(--mono);font-size:12px;
  transition:height .2s var(--ease);
}
.term-panel.fullscreen{height:calc(100vh - 54px - 28px);height:calc(100dvh - 82px)}
.term-head{
  display:flex;align-items:center;gap:8px;padding:6px 12px;flex-shrink:0;
  border-bottom:1px solid var(--border);background:rgba(255,255,255,.02);
}
.term-dots{display:flex;gap:6px}
.tdot{width:11px;height:11px;border-radius:50%;cursor:pointer}
.tdot.r{background:#ff5f57}.tdot.y{background:#febc2e}.tdot.g{background:#28c840}
.term-title{font-size:10.5px;color:var(--acc);letter-spacing:.1em;text-transform:uppercase}
.term-cwd{margin-left:auto;font-size:10px;color:var(--text2);overflow:hidden;text-overflow:ellipsis;max-width:45%;white-space:nowrap}
.term-out{flex:1;overflow-y:auto;padding:10px 14px;color:#c6cfee;white-space:pre-wrap;word-break:break-all}
.tl-cmd{color:var(--acc)}.tl-out{color:#c6cfee}.tl-err{color:var(--red)}
.term-inrow{display:flex;align-items:center;padding:7px 14px;border-top:1px solid var(--border)}
.term-prompt{color:var(--green);margin-right:8px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:40%}
.term-input{flex:1;background:none;border:none;outline:none;color:#eef1fa;font-family:var(--mono);font-size:12px;caret-color:var(--acc)}

/* ── MODALS ───────────────────────────────────────── */
.overlay{
  position:fixed;inset:0;z-index:1500;background:rgba(3,5,12,.66);
  backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);
  display:flex;align-items:center;justify-content:center;padding:18px;
  animation:fadeIn .16s ease;
}
.overlay.hidden,.hiddenx{display:none!important}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
.modal{
  width:540px;max-width:96vw;max-height:88vh;display:flex;flex-direction:column;
  background:var(--bg1);border:1px solid var(--border-hi);border-radius:20px;
  box-shadow:var(--shadow);animation:sheetIn .22s var(--ease);overflow:hidden;
}
.modal.lg{width:900px}
@keyframes sheetIn{from{opacity:0;transform:translateY(16px) scale(.97)}to{opacity:1;transform:none}}
.m-head{display:flex;align-items:center;gap:10px;padding:15px 20px;border-bottom:1px solid var(--border);flex-shrink:0}
.m-title{font-size:14px;font-weight:700;flex:1;display:flex;align-items:center;gap:8px;min-width:0}
.m-title small{color:var(--text2);font-weight:500;font-family:var(--mono);font-size:10.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.m-close{background:none;border:none;color:var(--text2);font-size:19px;cursor:pointer;padding:4px;border-radius:8px;line-height:1}
.m-close:hover{color:var(--red);background:var(--glass-hi)}
.m-body{padding:20px;overflow-y:auto;flex:1;min-height:0}
.m-foot{padding:13px 20px;border-top:1px solid var(--border);display:flex;gap:8px;justify-content:flex-end;flex-shrink:0}
.dirty-dot{width:8px;height:8px;border-radius:50%;background:var(--amber);display:none;box-shadow:0 0 8px var(--amber)}
.dirty .dirty-dot{display:block}

/* editor */
.ed-shell{display:flex;background:#04060c;border:1px solid var(--border);border-radius:12px;overflow:hidden;height:min(56vh,520px)}
[data-theme="light"] .ed-shell{background:#fbfcff}
.ed-gutter{
  padding:12px 8px 12px 14px;text-align:right;color:var(--text2);
  font-family:var(--mono);font-size:11.5px;line-height:1.65;user-select:none;
  overflow:hidden;background:rgba(255,255,255,.03);border-right:1px solid var(--border);
  min-width:46px;white-space:pre;
}
#ed-ta{
  flex:1;background:none;border:none;outline:none;resize:none;
  color:#dbe2f5;font-family:var(--mono);font-size:12.5px;line-height:1.65;
  padding:12px 14px;white-space:pre;overflow:auto;tab-size:4;
}
.ed-status{display:flex;gap:14px;font-family:var(--mono);font-size:10px;color:var(--text2);padding:8px 2px 0}

/* media preview */
.pv-body{display:flex;align-items:center;justify-content:center;min-height:220px;background:#04060c;border-radius:12px;overflow:hidden;border:1px solid var(--border)}
[data-theme="light"] .pv-body{background:#0e1220}
.pv-body img{max-width:100%;max-height:62vh;display:block}
.pv-body video{max-width:100%;max-height:62vh;outline:none}
.pv-body iframe{width:100%;height:62vh;border:none;background:#fff}
.pv-body audio{width:92%;margin:34px 0}

/* upload */
.drop-zone{
  border:2px dashed var(--border-hi);border-radius:16px;padding:34px 20px;
  text-align:center;cursor:pointer;transition:all .16s;color:var(--text1);
}
.drop-zone:hover,.drop-zone.over{border-color:var(--acc);background:rgba(99,102,241,.06);color:var(--text0)}
.drop-zone .dz-i{font-size:34px;display:block;margin-bottom:8px}
.upl-progress{margin-top:14px}
.pbar{height:7px;border-radius:99px;background:var(--bg3);overflow:hidden}
.pbar>div{height:100%;width:0%;background:var(--grad);border-radius:99px;transition:width .2s}
.pline{
  display:flex;justify-content:space-between;gap:10px;font-family:var(--mono);
  font-size:11px;color:var(--text1);padding:6px 10px;background:var(--glass);
  border-radius:8px;margin-top:6px;
}
.pline .ok{color:var(--green)}.pline .fail{color:var(--red)}
.chips{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px}
.chip{
  font-family:var(--mono);font-size:11px;color:var(--text1);cursor:pointer;
  border:1px solid var(--border);background:var(--glass);border-radius:99px;padding:4px 11px;
}
.chip:hover{border-color:var(--acc);color:var(--acc)}
.props-tbl{width:100%;border-collapse:collapse}
.props-tbl td{padding:8px 10px;font-size:12.5px;border-bottom:1px solid var(--border)}
.props-tbl td:first-child{color:var(--text2);font-family:var(--mono);font-size:10.5px;width:118px;text-transform:uppercase;letter-spacing:.05em}
.props-tbl td:last-child{font-family:var(--mono);word-break:break-all}

/* search results */
.sr-item{display:flex;align-items:center;gap:12px;padding:9px 16px;cursor:pointer;border-bottom:1px solid var(--border);transition:background .1s}
.sr-item:hover{background:var(--glass-hi)}
.sr-path{font-family:var(--mono);font-size:10px;color:var(--text2)}

/* context menu / action sheet */
.ctx{
  position:fixed;z-index:2500;min-width:190px;
  background:var(--bg2);border:1px solid var(--border-hi);border-radius:14px;
  padding:5px;box-shadow:var(--shadow);animation:popIn .13s var(--ease);
}
.ctx-item{
  display:flex;align-items:center;gap:10px;width:100%;text-align:left;
  padding:8px 12px;border:none;background:none;color:var(--text1);
  font-size:12.5px;border-radius:9px;cursor:pointer;
}
.ctx-item:hover{background:var(--glass-hi);color:var(--text0)}
.ctx-item.danger:hover{background:color-mix(in srgb,var(--red) 12%,transparent);color:var(--red)}
.ctx-sep{height:1px;background:var(--border);margin:4px 9px}

/* command palette */
.palette{
  width:600px;max-width:94vw;background:var(--bg1);border:1px solid var(--border-hi);
  border-radius:18px;box-shadow:var(--shadow);overflow:hidden;
  animation:sheetIn .18s var(--ease);align-self:flex-start;margin-top:9vh;
}
.pal-input{
  width:100%;background:none;border:none;outline:none;
  font-size:15px;padding:17px 20px;color:var(--text0);
  border-bottom:1px solid var(--border);
}
.pal-list{max-height:52vh;overflow-y:auto;padding:6px}
.pal-item{
  display:flex;align-items:center;gap:12px;padding:10px 13px;border-radius:11px;cursor:pointer;
}
.pal-item.on,.pal-item:hover{background:linear-gradient(90deg,rgba(99,102,241,.14),rgba(34,211,238,.05))}
.pal-item .pi{width:26px;text-align:center;font-size:16px}
.pal-item .pt{flex:1;min-width:0}
.pal-item .pt b{display:block;font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pal-item .pt span{font-size:10.5px;color:var(--text2);font-family:var(--mono)}
.pal-foot{
  display:flex;gap:14px;padding:9px 16px;border-top:1px solid var(--border);
  font-size:10.5px;color:var(--text2);align-items:center;
}

/* toasts */
#toasts{position:fixed;top:16px;right:16px;z-index:4000;display:flex;flex-direction:column;gap:8px;max-width:min(92vw,340px)}
.toast{
  display:flex;align-items:center;gap:10px;padding:11px 16px;border-radius:13px;
  background:var(--bg2);border:1px solid var(--border-hi);box-shadow:var(--shadow);
  font-size:12.5px;font-weight:500;animation:toastIn .25s var(--ease);
}
@keyframes toastIn{from{opacity:0;transform:translateX(24px)}to{opacity:1;transform:none}}
.toast.out{opacity:0;transform:translateX(24px);transition:all .3s}
.toast.ok{border-left:3px solid var(--green)}
.toast.err{border-left:3px solid var(--red)}
.toast.info{border-left:3px solid var(--acc)}

.spin{display:inline-block;width:13px;height:13px;border:2px solid var(--border-hi);border-top-color:var(--acc);border-radius:50%;animation:rot .6s linear infinite;vertical-align:-2px}
@keyframes rot{to{transform:rotate(360deg)}}

/* ── RESPONSIVE ───────────────────────────────────── */
@media (max-width:920px){
  .hamburger{display:inline-flex}
  .sidebar{
    position:fixed;left:0;top:0;bottom:0;width:264px;
    transform:translateX(-105%);transition:transform .26s var(--ease);
    background:var(--bg1);box-shadow:var(--shadow);
  }
  .sidebar.open{transform:none}
  .brand em{display:none}
  .btn .blabel{display:none}
  .btn.primary{padding:7px 10px}
}
@media (max-width:640px){
  :root{--cols:30px minmax(0,1fr) 34px}
  .fhead{grid-template-columns:var(--cols)}
  .fhead .h-size,.fhead .h-date,.fhead .h-perm{display:none}
  .fsize,.fdate,.fperm{display:none}
  .toolbar{padding:8px 10px;gap:6px}
  .filter-box input{width:100px}
  .overlay{padding:0;align-items:flex-end}
  .modal{width:100%;max-width:none;max-height:90vh;border-radius:20px 20px 0 0;animation:sheetUp .26s var(--ease)}
  @keyframes sheetUp{from{transform:translateY(60%)}to{transform:none}}
  .palette{width:100%;max-width:none;margin-top:6vh;border-radius:18px 18px 0 0;align-self:flex-end}
  .bulkbar{bottom:44px;width:94vw}
  .ctx{left:50%!important;right:auto!important;bottom:12px;top:auto!important;transform:translateX(-50%);min-width:min(92vw,320px)}
  .term-panel.fullscreen{height:calc(100dvh - 54px)}
  .statusbar{gap:8px}
  #st-php{display:none}
}
</style>
</head>
<body>

<?php if (!fm_is_logged_in()): ?>
<!-- ================= LOGIN ================= -->
<div class="login-wrap">
  <div class="login-brand">
    <div class="logo-big">🗄️</div>
    <h1>RhelsFS<br>File Manager</h1>
    <div class="tag">Single-file PHP file manager &amp; web terminal — nothing to install, drop it in and go.</div>
    <div class="login-feats">
      <div class="login-feat"><div class="fi">🛡️</div><div><b>Hardened by default</b>CSRF protection, brute-force lockout, signed sessions.</div></div>
      <div class="login-feat"><div class="fi">📂</div><div><b>Full file operations</b>Upload, edit, archive, permissions — all from the browser.</div></div>
      <div class="login-feat"><div class="fi">⌨️</div><div><b>Built-in terminal</b>Safelisted shell access with sandboxed working directory.</div></div>
    </div>
  </div>
  <div class="login-card">
    <h2>Welcome back</h2>
    <div class="sub">Sign in to manage your files</div>
    <?php if ($login_error): ?><div class="login-error">⚠️ <?= htmlspecialchars($login_error) ?></div><?php endif; ?>
    <?php if (isset($_GET['expired'])): ?><div class="login-error">⏱ Session expired — sign in again.</div><?php endif; ?>
    <form method="POST" autocomplete="on">
      <div class="fgroup">
        <label>Username</label>
        <input type="text" name="username" class="form-control" autocomplete="username" autofocus required>
      </div>
      <div class="fgroup">
        <label>Password</label>
        <div class="input-wrap">
          <input type="password" name="password" id="pw" class="form-control" style="padding-right:42px" autocomplete="current-password" required>
          <button type="button" class="toggle-pw" onclick="const p=document.getElementById('pw');p.type=p.type==='password'?'text':'password'">👁</button>
        </div>
      </div>
      <button type="submit" name="fm_login" class="btn-login">Sign in →</button>
    </form>
    <div class="login-foot">RhelsFS v<?= FM_VERSION ?> · secured session</div>
  </div>
</div>

<?php else: ?>
<!-- ================= MAIN APP ================= -->
<div id="app">

  <!-- TOPBAR -->
  <div class="topbar">
    <button class="btn icon hamburger" id="btn-menu" aria-label="Menu">☰</button>
    <div class="brand"><span class="logo-mini">🗄️</span>RhelsFS <em>/ <?= htmlspecialchars(FM_USERNAME) ?></em></div>
    <div class="topbar-sep"></div>
    <button class="btn icon" id="btn-theme" title="Toggle theme" aria-label="Toggle theme">🌙</button>
    <button class="btn primary" onclick="openUploadModal()"><span style="font-size:14px">⬆</span><span class="blabel">Upload</span></button>
    <button class="btn" onclick="openMkdirModal()">📁<span class="blabel">&nbsp;Folder</span></button>
    <button class="btn" onclick="openNewFileModal()">📄<span class="blabel">&nbsp;File</span></button>
    <?php if (FM_TERMINAL): ?><button class="btn" id="btn-term" onclick="toggleTerm()">⌨️<span class="blabel">&nbsp;Terminal</span></button><?php endif; ?>
    <button class="btn danger" onclick="location.href='?logout'" title="Logout">⏻</button>
  </div>

  <div class="main">

    <!-- SIDEBAR -->
    <aside class="sidebar" id="sidebar">
      <div class="sb-section">
        <div class="sb-heading">Quick access</div>
        <div class="sb-link active" data-nav="/"><span class="si">🏠</span> Root</div>
        <div class="sb-link" data-nav="/tmp"><span class="si">📦</span> tmp</div>
        <div class="sb-link" onclick="doSearch()"><span class="si">🔍</span> Deep search…</div>
      </div>
      <div class="sb-section">
        <div class="sb-heading">Shortcuts</div>
        <div class="sb-link" onclick="openPalette()"><span class="si">⚡</span> Command palette <span class="kbd" style="margin-left:auto">⌘K</span></div>
        <div class="sb-link" onclick="toggleView()"><span class="si">▦</span> Toggle view</div>
        <div class="sb-link" onclick="compressSelected()"><span class="si">🗜️</span> Compress selected</div>
        <div class="sb-link" onclick="deleteSelected()"><span class="si">🗑️</span> Delete selected</div>
      </div>
      <div class="sb-spacer"></div>
      <div class="disk-widget">
        <div class="disk-ring">
          <svg width="104" height="104" viewBox="0 0 120 120">
            <defs><linearGradient id="ringGrad" x1="0%" y1="0%" x2="100%" y2="100%">
              <stop offset="0%" stop-color="#6366f1"/><stop offset="100%" stop-color="#22d3ee"/>
            </linearGradient></defs>
            <circle class="ring-bg" cx="60" cy="60" r="52"/>
            <circle class="ring-fg" id="ring-fg" cx="60" cy="60" r="52" stroke-dasharray="326.7" stroke-dashoffset="326.7"/>
          </svg>
          <div class="ring-txt"><b id="disk-pct">–</b><span>used</span></div>
        </div>
        <div class="disk-meta">
          <b id="disk-used">–</b> of <b id="disk-total">–</b><br>free <b id="disk-free">–</b>
        </div>
      </div>
      <div class="sb-chip"><span id="sb-php">PHP</span><span id="sb-os">—</span></div>
    </aside>

    <!-- CONTENT -->
    <div class="content">
      <div class="toolbar">
        <div class="nav-arrows">
          <button class="btn icon" id="btn-back" title="Back" disabled>←</button>
          <button class="btn icon" id="btn-fwd" title="Forward" disabled>→</button>
        </div>
        <div class="crumbwrap"><div class="crumbs" id="crumbs"></div></div>
        <div class="filter-box" id="filter-box">
          <span style="color:var(--text2);font-size:12px">🔎</span>
          <input type="text" id="filter-input" placeholder="Filter…" autocomplete="off">
          <button class="fx" onclick="clearFilter()" aria-label="Clear">✕</button>
        </div>
        <button class="btn icon" onclick="doSearch()" title="Deep search">🌐</button>
        <div class="sort-wrap">
          <button class="btn icon" id="btn-sort" title="Sort">⇅</button>
          <div class="pop-menu hiddenx" id="sort-pop"></div>
        </div>
        <button class="btn icon" id="btn-view" onclick="toggleView()" title="List / grid">▦</button>
        <button class="btn icon" onclick="refreshDir()" title="Refresh">↻</button>
      </div>

      <div class="file-area" id="file-area">
        <div class="drop-overlay" id="drop-overlay"><span class="di">📥</span>Drop files to upload them here</div>

        <!-- list header + rows injected here -->
        <div class="fhead" id="list-head">
          <div class="chk"><input type="checkbox" id="check-all" title="Select all"></div>
          <div class="sortable" data-sort="name">Name <span class="arrow"></span></div>
          <div class="sortable h-size" data-sort="size">Size <span class="arrow"></span></div>
          <div class="sortable h-date" data-sort="mtime">Modified <span class="arrow"></span></div>
          <div class="h-perm">Perms</div>
          <div></div>
        </div>
        <div id="file-list"></div>
        <div class="grid-view hiddenx" id="grid-view"></div>
        <div id="skeleton" class="hiddenx"></div>
        <div id="empty-slot"></div>
      </div>

      <!-- BULK ACTION BAR -->
      <div class="bulkbar" id="bulkbar">
        <span class="count" id="bulk-count">0</span>
        <button class="btn sm" onclick="bulkDownload()">⬇ Download</button>
        <button class="btn sm" onclick="compressSelected()">🗜 Zip</button>
        <button class="btn sm" onclick="bulkCopyMove('copy')">📋 Copy</button>
        <button class="btn sm" onclick="bulkCopyMove('move')">✂ Move</button>
        <button class="btn sm danger" onclick="deleteSelected()">🗑 Delete</button>
        <button class="btn sm icon" onclick="clearSelection()" title="Clear selection">✕</button>
      </div>

      <!-- STATUS BAR -->
      <div class="statusbar">
        <b id="st-count">0 items</b>
        <span id="st-sel"></span>
        <span id="status-msg"></span>
        <span style="flex:1"></span>
        <span id="st-php"></span>
      </div>

      <!-- TERMINAL -->
      <?php if (FM_TERMINAL): ?>
      <div class="term-panel hiddenx" id="term-panel">
        <div class="term-head">
          <div class="term-dots"><div class="tdot r" onclick="closeTerm()" title="Close"></div><div class="tdot y"></div><div class="tdot g"></div></div>
          <div class="term-title">Terminal</div>
          <div class="term-cwd" id="term-cwd"></div>
          <button class="btn sm icon" onclick="clearTerm()" title="Clear (Ctrl+L)">🧹</button>
          <button class="btn sm icon" onclick="toggleTermFs()" title="Fullscreen" id="btn-term-fs">⛶</button>
        </div>
        <div class="term-out" id="term-out"></div>
        <div class="term-inrow">
          <span class="term-prompt" id="term-prompt">$ </span>
          <input class="term-input" id="term-input" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="Type a command… ('help' for tips)">
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- SIDEBAR BACKDROP (mobile) -->
<div class="sb-backdrop hiddenx" id="sb-backdrop"></div>

<!-- ══ MODALS ══ -->

<!-- Upload -->
<div class="overlay hiddenx" id="m-upload">
  <div class="modal">
    <div class="m-head"><div class="m-title">⬆ Upload files<small><?= fm_human_size(FM_MAX_UPLOAD) ?> max per file</small></div><button class="m-close" onclick="closeModal('m-upload')">✕</button></div>
    <div class="m-body">
      <div class="drop-zone" id="dz"><span class="dz-i">☁️</span><b>Click to browse</b> or drag &amp; drop<br><small style="color:var(--text2)">multiple files supported</small></div>
      <input type="file" id="file-input" multiple style="display:none">
      <div class="upl-progress hiddenx" id="upl-progress">
        <div class="pbar"><div id="upl-bar"></div></div>
        <div style="display:flex;justify-content:space-between;font-family:var(--mono);font-size:10px;color:var(--text2);margin-top:5px"><span id="upl-label">Uploading…</span><span id="upl-pct">0%</span></div>
      </div>
      <div id="upl-list"></div>
    </div>
    <div class="m-foot">
      <button class="btn" onclick="closeModal('m-upload')">Close</button>
      <button class="btn primary" id="btn-upload" onclick="doUpload()">Upload</button>
    </div>
  </div>
</div>

<!-- New folder -->
<div class="overlay hiddenx" id="m-mkdir">
  <div class="modal">
    <div class="m-head"><div class="m-title">📁 New folder</div><button class="m-close" onclick="closeModal('m-mkdir')">✕</button></div>
    <div class="m-body">
      <div class="fgroup"><label>Name</label><input class="form-control" id="mkdir-name" placeholder="my-folder" onkeydown="if(event.key==='Enter')doMkdir()"></div>
    </div>
    <div class="m-foot"><button class="btn" onclick="closeModal('m-mkdir')">Cancel</button><button class="btn primary" onclick="doMkdir()">Create</button></div>
  </div>
</div>

<!-- New file -->
<div class="overlay hiddenx" id="m-newfile">
  <div class="modal">
    <div class="m-head"><div class="m-title">📄 New file</div><button class="m-close" onclick="closeModal('m-newfile')">✕</button></div>
    <div class="m-body">
      <div class="fgroup"><label>Name</label><input class="form-control" id="newfile-name" placeholder="notes.txt" onkeydown="if(event.key==='Enter')doNewFile()"></div>
    </div>
    <div class="m-foot"><button class="btn" onclick="closeModal('m-newfile')">Cancel</button><button class="btn primary" onclick="doNewFile()">Create &amp; edit</button></div>
  </div>
</div>

<!-- Editor -->
<div class="overlay hiddenx" id="m-editor">
  <div class="modal lg">
    <div class="m-head dirty-dot-host" id="ed-host">
      <div class="m-title" id="ed-title">✏ File<span class="dirty-dot" id="ed-dirty" title="Unsaved changes"></span><small id="ed-info"></small></div>
      <button class="m-close" onclick="closeEditor()">✕</button>
    </div>
    <div class="m-body" style="padding:14px">
      <div class="ed-shell"><pre class="ed-gutter" id="ed-gutter">1</pre><textarea id="ed-ta" spellcheck="false"></textarea></div>
      <div class="ed-status"><span id="ed-pos">Ln 1, Col 1</span><span style="flex:1"></span><span>Ctrl+S save · Tab indents</span></div>
    </div>
    <div class="m-foot">
      <button class="btn" onclick="closeEditor()">Close</button>
      <button class="btn primary" onclick="doSave()">💾 Save <span class="kbd" style="margin-left:4px">^S</span></button>
    </div>
  </div>
</div>

<!-- Media preview -->
<div class="overlay hiddenx" id="m-preview">
  <div class="modal lg">
    <div class="m-head"><div class="m-title" id="pv-title">Preview</div><button class="m-close" onclick="closeModal('m-preview')">✕</button></div>
    <div class="m-body"><div class="pv-body" id="pv-body"></div></div>
    <div class="m-foot">
      <button class="btn" onclick="closeModal('m-preview')">Close</button>
      <button class="btn primary" id="pv-dl">⬇ Download</button>
    </div>
  </div>
</div>

<!-- Rename -->
<div class="overlay hiddenx" id="m-rename">
  <div class="modal">
    <div class="m-head"><div class="m-title">✏ Rename</div><button class="m-close" onclick="closeModal('m-rename')">✕</button></div>
    <div class="m-body"><div class="fgroup"><label id="rename-old" style="text-transform:none;letter-spacing:0;font-size:11px"></label><input class="form-control" id="rename-input" onkeydown="if(event.key==='Enter')doRename()"></div></div>
    <div class="m-foot"><button class="btn" onclick="closeModal('m-rename')">Cancel</button><button class="btn primary" onclick="doRename()">Rename</button></div>
  </div>
</div>

<!-- Chmod -->
<div class="overlay hiddenx" id="m-chmod">
  <div class="modal">
    <div class="m-head"><div class="m-title">🔒 Permissions</div><button class="m-close" onclick="closeModal('m-chmod')">✕</button></div>
    <div class="m-body">
      <div class="fgroup"><label>Path</label><div style="font-family:var(--mono);font-size:11.5px;color:var(--text1)" id="chmod-path"></div></div>
      <div class="fgroup"><label>Octal</label><input class="form-control" id="chmod-input" maxlength="4" placeholder="644" onkeydown="if(event.key==='Enter')doChmod()"></div>
      <div class="chips" id="chmod-chips"></div>
    </div>
    <div class="m-foot"><button class="btn" onclick="closeModal('m-chmod')">Cancel</button><button class="btn primary" onclick="doChmod()">Apply</button></div>
  </div>
</div>

<!-- Properties -->
<div class="overlay hiddenx" id="m-props">
  <div class="modal">
    <div class="m-head"><div class="m-title">ℹ Properties</div><button class="m-close" onclick="closeModal('m-props')">✕</button></div>
    <div class="m-body" id="props-body"></div>
    <div class="m-foot"><button class="btn" onclick="closeModal('m-props')">Close</button></div>
  </div>
</div>

<!-- Copy / move -->
<div class="overlay hiddenx" id="m-copymove">
  <div class="modal">
    <div class="m-head"><div class="m-title" id="cm-title">Copy</div><button class="m-close" onclick="closeModal('m-copymove')">✕</button></div>
    <div class="m-body">
      <div class="fgroup"><label>Destination directory</label><input class="form-control" id="cm-dest" placeholder="/" list="dir-suggest" onkeydown="if(event.key==='Enter')doCopyMove()">
        <datalist id="dir-suggest"></datalist>
      </div>
      <div style="font-size:11.5px;color:var(--text2)" id="cm-src"></div>
    </div>
    <div class="m-foot"><button class="btn" onclick="closeModal('m-copymove')">Cancel</button><button class="btn primary" id="cm-go" onclick="doCopyMove()">Go</button></div>
  </div>
</div>

<!-- Delete confirm -->
<div class="overlay hiddenx" id="m-delete">
  <div class="modal">
    <div class="m-head"><div class="m-title">🗑 Confirm delete</div><button class="m-close" onclick="closeModal('m-delete')">✕</button></div>
    <div class="m-body" id="del-body" style="font-size:12.5px;line-height:1.9"></div>
    <div class="m-foot"><button class="btn" onclick="closeModal('m-delete')">Cancel</button><button class="btn danger" id="del-go">Delete permanently</button></div>
  </div>
</div>

<!-- Search results -->
<div class="overlay hiddenx" id="m-search">
  <div class="modal lg">
    <div class="m-head"><div class="m-title" id="search-title">🔍 Search</div><button class="m-close" onclick="closeModal('m-search')">✕</button></div>
    <div class="m-body" style="padding:0" id="search-body"></div>
  </div>
</div>

<!-- Command palette -->
<div class="overlay hiddenx" id="m-palette" style="align-items:flex-start;justify-content:center">
  <div class="palette">
    <input class="pal-input" id="pal-input" placeholder="Type a command or search this folder…" autocomplete="off">
    <div class="pal-list" id="pal-list"></div>
    <div class="pal-foot"><span><span class="kbd">↑↓</span> navigate</span><span><span class="kbd">↵</span> run</span><span><span class="kbd">esc</span> close</span></div>
  </div>
</div>

<!-- context menu -->
<div class="ctx hiddenx" id="ctx"></div>
<!-- toasts -->
<div id="toasts"></div>

<script>
'use strict';
// ============================================================
//  CONSTANTS & STATE
// ============================================================
const CSRF = <?= json_encode($csrf_token) ?>;
const SELF = <?= json_encode(FM_SELF) ?>;
const TERM_OK = <?= FM_TERMINAL ? 'true' : 'false' ?>;
const ROOT_ABS = <?= json_encode(realpath(FM_ROOT) ?: FM_ROOT) ?>;

let currentPath = '/';
let currentItems = [];
let itemIndex = new Map();       // path -> item
let sortCol = 'name', sortAsc = true;
let viewMode = 'list';
let filterQ = '';
let selected = new Set();
let lastClickIdx = -1;
let histBack = [], histFwd = [];
let ctxItem = null;
let uploadFiles = [];
let edPath = null, edDirty = false;
let termCwd = ROOT_ABS, termHist = [], termHistIdx = -1;
let delTargets = [];
let cmTargets = [], cmAction = 'copy';
let palIdx = 0, palMatches = [];

const $ = id => document.getElementById(id);

// ============================================================
//  UTILITIES
// ============================================================
function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;')}
function humanSize(b){if(b==null)return '-';const u=['B','KB','MB','GB','TB'];let i=0;while(b>=1024&&i<4){b/=1024;i++}return(Math.round(b*10)/10)+' '+u[i]}
function fmtDate(ts){if(!ts)return '-';return new Date(ts*1000).toLocaleString([],{year:'numeric',month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'})}
const IMG_EXT=['jpg','jpeg','png','gif','webp','bmp','ico','svg'];
const MED_EXT={img:IMG_EXT,vid:['mp4','webm','mov','mkv'],aud:['mp3','wav','ogg','m4a','flac'],pdf:['pdf']};
function extOf(n){const i=n.lastIndexOf('.');return i<0?'':n.slice(i+1).toLowerCase()}
function isImg(n){return IMG_EXT.includes(extOf(n))}

function fileIcon(it){
  if(it.is_dir)return '📁';
  const e=extOf(it.name);
  const map={php:'🐘',js:'📜',ts:'📘',html:'🌐',htm:'🌐',css:'🎨',scss:'🎨',
    json:'📋',xml:'📋',yaml:'📋',yml:'📋',md:'📝',txt:'📝',log:'📋',env:'🔑',
    jpg:'🖼️',jpeg:'🖼️',png:'🖼️',gif:'🖼️',webp:'🖼️',svg:'🖼️',ico:'🖼️',
    pdf:'📕',zip:'📦',tar:'📦',gz:'📦',rar:'📦',7z:'📦',
    mp4:'🎬',webm:'🎬',mov:'🎬',mkv:'🎬',mp3:'🎵',wav:'🎵',ogg:'🎵',m4a:'🎵',flac:'🎵',
    py:'🐍',rb:'💎',go:'🐹',rs:'🦀',java:'☕',c:'⚙️',cpp:'⚙️',sh:'💻',bash:'💻',sql:'🗄️',
    doc:'📘',docx:'📘',xls:'📊',xlsx:'📊',csv:'📊',ppt:'📙',pptx:'📙'};
  return map[e]||'📄';
}
function serveUrl(path){return SELF+'?ajax=1&action=preview&path='+encodeURIComponent(path)+'&csrf_token='+CSRF}

async function api(params,method='GET',body=null){
  const url=SELF+'?ajax=1&'+new URLSearchParams(params);
  const opts={method};
  if(body){body.append('csrf_token',CSRF);opts.body=body}
  try{const r=await fetch(url,opts);return await r.json()}
  catch(e){return{error:'Network error: '+e.message}}
}
function apiPost(action,data={}){const fd=new FormData();Object.entries(data).forEach(([k,v])=>fd.append(k,v));return api({action},'POST',fd)}

function toast(msg,type='info'){
  const t=document.createElement('div');t.className='toast '+type;
  t.innerHTML=`<span>${type==='ok'?'✅':type==='err'?'⛔':'💡'}</span><span>${esc(msg)}</span>`;
  $('toasts').appendChild(t);
  setTimeout(()=>{t.classList.add('out');setTimeout(()=>t.remove(),350)},3200);
}
let stTimer;
function flashStatus(msg,err=false){
  const el=$('status-msg');el.textContent=msg;el.className=err?'err':'';
  clearTimeout(stTimer);if(msg)stTimer=setTimeout(()=>el.textContent='',2600);
}
function openModal(id){$(id).classList.remove('hiddenx')}
function closeModal(id){$(id).classList.add('hiddenx')}
function closeAllModals(){document.querySelectorAll('.overlay').forEach(o=>o.classList.add('hiddenx'));hideCtx()}
document.addEventListener('click',e=>{if(e.target.classList&&e.target.classList.contains('overlay'))closeAllModals()});

// ============================================================
//  THEME
// ============================================================
function applyThemeBtn(){const d=document.documentElement.dataset.theme==='light';$('btn-theme').textContent=d?'☀️':'🌙'}
function toggleTheme(){
  const next=document.documentElement.dataset.theme==='light'?'dark':'light';
  document.documentElement.dataset.theme=next;
  try{localStorage.setItem('fm_theme',next)}catch(e){}
  applyThemeBtn();
}
applyThemeBtn();

// ============================================================
//  NAVIGATION
// ============================================================
async function navTo(path,push=true){
  if(push&&path!==currentPath){histBack.push(currentPath);histFwd=[]}
  currentPath=path;
  updateNavBtns();showSkeleton(true);
  const t0=Date.now();
  const data=await api({action:'ls',path});
  const wait=Math.max(0,260-(Date.now()-t0)); // avoid skeleton flicker
  setTimeout(()=>{
    showSkeleton(false);
    if(data.error){toast(data.error,'err');showSkeleton(false);renderEmpty();return}
    currentItems=data.items||[];
    buildIndex();selected.clear();updateSelUI();
    renderCrumbs(data.crumbs||[]);renderFiles();loadDiskInfo();
  },wait);
}
function refreshDir(){navTo(currentPath,false)}
function goBack(){if(!histBack.length)return;histFwd.push(currentPath);navTo(histBack.pop(),false)}
function goForward(){if(!histFwd.length)return;histBack.push(currentPath);navTo(histFwd.pop(),false)}
function updateNavBtns(){$('btn-back').disabled=!histBack.length;$('btn-fwd').disabled=!histFwd.length}

function renderCrumbs(crumbs){
  $('crumbs').innerHTML=crumbs.map((c,i)=>{
    const last=i===crumbs.length-1;
    return (i>0?'<span class="crumb-sep">/</span>':'')
      +`<span class="crumb${last?' last':''}" data-nav="${esc(c.path)}">${esc(c.name)}</span>`;
  }).join('');
  const w=document.querySelector('.crumbwrap');w.scrollLeft=w.scrollWidth;
}
function buildIndex(){itemIndex=new Map(currentItems.map(i=>[i.path,i]))}

function showSkeleton(on){
  const sk=$('skeleton');
  if(!on){sk.classList.add('hiddenx');return}
  sk.classList.remove('hiddenx');
  sk.innerHTML=Array.from({length:8},()=>'<div class="skl-row">'+
    '<div></div><div class="skl-bar" style="width:70%"></div><div class="skl-bar" style="width:80%"></div>'+
    '<div class="skl-bar" style="width:90%"></div><div class="skl-bar" style="width:60%"></div><div></div></div>').join('');
  $('file-list').innerHTML='';$('grid-view').innerHTML='';$('empty-slot').innerHTML='';
}

// ============================================================
//  FILTER / SORT / RENDER
// ============================================================
function clearFilter(){filterQ='';$('filter-input').value='';$('filter-box').classList.remove('has-q');renderFiles()}
$('filter-input').addEventListener('input',e=>{filterQ=e.target.value.trim().toLowerCase();$('filter-box').classList.toggle('has-q',!!filterQ);renderFiles()});

function filteredSorted(){
  let arr=currentItems.filter(i=>!filterQ||i.name.toLowerCase().includes(filterQ));
  arr.sort((a,b)=>{
    if(a.is_dir!==b.is_dir)return b.is_dir-a.is_dir;
    let va=a[sortCol],vb=b[sortCol];
    if(typeof va==='string'){va=va.toLowerCase();vb=vb.toLowerCase()}
    const r=va<vb?-1:va>vb?1:0;
    return sortAsc?r:-r;
  });
  return arr;
}

function renderFiles(){
  const arr=filteredSorted();
  renderSortHead();
  $('st-count').textContent=currentItems.length+' items'+(filterQ?` (${arr.length} match)`:'');
  if(viewMode==='grid'){
    $('list-head').style.display='none';$('file-list').innerHTML='';
    const gv=$('grid-view');gv.classList.remove('hiddenx');
    if(arr.length)gv.innerHTML=arr.map((it,idx)=>gcardHTML(it,idx)).join('');
    else if(currentItems.length)gv.innerHTML='<div class="empty-state" style="grid-column:1/-1;padding:40px"><span class="big">🔎</span><h3>No matches</h3><p>Nothing matches your filter.</p></div>';
    if(!currentItems.length)renderEmpty();else $('empty-slot').innerHTML='';
    return;
  }
  $('grid-view').classList.add('hiddenx');
  $('list-head').style.display='';
  const fl=$('file-list');
  if(!arr.length&&!currentItems.length){fl.innerHTML='';renderEmpty();return}
  $('empty-slot').innerHTML='';
  fl.innerHTML=arr.length?arr.map((it,idx)=>rowHTML(it,idx)).join('')
    :`<div class="empty-state" style="padding:40px"><span class="big">🔎</span><h3>No matches</h3><p>Nothing here matches your filter.</p></div>`;
}

function rowHTML(it,idx){
  const sel=selected.has(it.path)?' sel':'';
  return `<div class="frow${sel}" data-path="${esc(it.path)}" data-idx="${idx}">
    <div class="chk"><input type="checkbox" ${sel?'checked':''} data-check="${esc(it.path)}"></div>
    <div class="fic">${fileIcon(it)}</div>
    <div class="fname ${it.is_dir?'dir-name':''}"><span class="nm">${esc(it.name)}</span>${it.writable?'':'<span class="lock" title="Read-only">🔒</span>'}</div>
    <div class="fsize">${it.is_dir?'—':humanSize(it.size)}</div>
    <div class="fdate">${fmtDate(it.mtime)}</div>
    <div class="fperm">${esc(it.perms||'')}</div>
    <button class="fmore" data-more="${esc(it.path)}" aria-label="Actions">⋯</button>
  </div>`;
}
function gcardHTML(it,idx){
  const sel=selected.has(it.path)?' sel':'';
  const thumb=isImg(it.name)?`<img class="thumb" loading="lazy" src="${serveUrl(it.path)}" alt="">`
    :`<div class="gi">${fileIcon(it)}</div>`;
  return `<div class="gcard${sel}" data-path="${esc(it.path)}" data-idx="${idx}">
    <div class="chk"><input type="checkbox" ${sel?'checked':''} data-check="${esc(it.path)}"></div>
    ${thumb}<div class="gn" title="${esc(it.name)}">${esc(it.name)}</div>
  </div>`;
}
function renderEmpty(){
  $('empty-slot').innerHTML=`<div class="empty-state">
    <span class="big">🗂️</span><h3>This folder is empty</h3>
    <p>Drop files anywhere on this page to upload, or create something new.</p>
    <div style="display:flex;gap:8px">
      <button class="btn primary" onclick="openUploadModal()">⬆ Upload</button>
      <button class="btn" onclick="openNewFileModal()">📄 New file</button>
    </div></div>`;
}

/* sorting UI */
function renderSortHead(){
  document.querySelectorAll('#list-head .sortable').forEach(el=>{
    el.classList.toggle('on',el.dataset.sort===sortCol);
    el.querySelector('.arrow').textContent=el.dataset.sort===sortCol?(sortAsc?'↑':'↓'):'';
  });
}
document.querySelectorAll('#list-head .sortable').forEach(el=>{
  el.addEventListener('click',()=>{
    const c=el.dataset.sort;
    if(sortCol===c)sortAsc=!sortAsc;else{sortCol=c;sortAsc=true}
    renderFiles();
  });
});

/* sort dropdown */
const SORTS=[['name','Name'],['size','Size'],['mtime','Modified']];
$('btn-sort').addEventListener('click',e=>{
  e.stopPropagation();
  const pop=$('sort-pop');
  pop.innerHTML=SORTS.map(([k,l])=>`<button class="pop-item${sortCol===k?' on':''}" data-sort="${k}">${sortAsc?'↑':'↓'} ${l}</button>`).join('')
    +'<div class="pop-sep"></div>'
    +`<button class="pop-item${sortAsc?' on':''}" data-dir="asc">Ascending</button>`
    +`<button class="pop-item${!sortAsc?' on':''}" data-dir="desc">Descending</button>`;
  pop.classList.toggle('hiddenx');
});
$('sort-pop').addEventListener('click',e=>{
  const b=e.target.closest('[data-sort],[data-dir]');if(!b)return;
  if(b.dataset.sort)sortCol=b.dataset.sort;else sortAsc=b.dataset.dir==='asc';
  $('sort-pop').classList.add('hiddenx');renderFiles();
});
document.addEventListener('click',e=>{if(!e.target.closest('.sort-wrap'))$('sort-pop').classList.add('hiddenx')});

function toggleView(){
  viewMode=viewMode==='list'?'grid':'list';
  $('btn-view').textContent=viewMode==='grid'?'☰':'▦';
  renderFiles();
}

// ============================================================
//  SELECTION
// ============================================================
function toggleSelect(path){
  selected.has(path)?selected.delete(path):selected.add(path);
  syncRowChecks();updateSelUI();
}
function selectRange(fromIdx,toIdx){
  const arr=filteredSorted();
  const [a,b]=[Math.min(fromIdx,toIdx),Math.max(fromIdx,toIdx)];
  for(let i=a;i<=b;i++)if(arr[i])selected.add(arr[i].path);
  syncRowChecks();updateSelUI();
}
function selectAll(){
  filteredSorted().forEach(i=>selected.add(i.path));
  syncRowChecks();updateSelUI();
}
function clearSelection(){selected.clear();syncRowChecks();updateSelUI()}
function syncRowChecks(){
  document.querySelectorAll('[data-check]').forEach(cb=>{
    cb.checked=selected.has(cb.dataset.check);
    cb.closest('.frow,.gcard')?.classList.toggle('sel',cb.checked);
  });
  $('check-all').checked=currentItems.length>0&&filteredSorted().every(i=>selected.has(i.path));
}
function getSelectedPaths(){return [...selected]}
function updateSelUI(){
  const n=selected.size;
  $('st-sel').textContent=n?n+' selected':'';
  $('bulk-count').textContent=n+' selected';
  $('bulkbar').classList.toggle('show',n>0);
}
$('check-all').addEventListener('change',e=>{e.target.checked?selectAll():clearSelection()});

/* row interactions (event delegation — quote-safe with any filename) */
$('file-list').addEventListener('click',e=>{
  const more=e.target.closest('.fmore');
  const cb=e.target.closest('input[data-check]');
  if(e.target.closest('.chk')&&!cb)return;
  const row=e.target.closest('.frow');if(!row)return;
  const it=itemIndex.get(row.dataset.path);if(!it)return;
  if(more){e.stopPropagation();openCtxAt(more.getBoundingClientRect(),it);return}
  if(cb){e.stopPropagation();toggleSelect(it.path);lastClickIdx=+row.dataset.idx;return}
  if(e.ctrlKey||e.metaKey){toggleSelect(it.path);lastClickIdx=+row.dataset.idx;return}
  if(e.shiftKey&&lastClickIdx>=0){selectRange(lastClickIdx,+row.dataset.idx);return}
  lastClickIdx=+row.dataset.idx;
  openItem(it);
});
$('grid-view').addEventListener('click',e=>{
  if(e.target.closest('.chk')&&!e.target.closest('input[data-check]'))return;
  const cb=e.target.closest('input[data-check]');
  const card=e.target.closest('.gcard');if(!card)return;
  const it=itemIndex.get(card.dataset.path);if(!it)return;
  if(cb){e.stopPropagation();toggleSelect(it.path);return}
  openItem(it);
});

/* long-press = context menu on touch devices */
let lpTimer=null,lpStart=null;
$('file-area').addEventListener('touchstart',e=>{
  const row=e.target.closest('.frow,.gcard');if(!row)return;
  lpStart={x:e.touches[0].clientX,y:e.touches[0].clientY};
  lpTimer=setTimeout(()=>{
    const it=itemIndex.get(row.dataset.path);
    if(it){navigator.vibrate?.(30);openCtxAt(lpStart,it,true)}
  },480);
},{passive:true});
['touchend','touchmove'].forEach(ev=>$('file-area').addEventListener(ev,()=>clearTimeout(lpTimer),{passive:true}));

/* right-click context menu */
$('file-area').addEventListener('contextmenu',e=>{
  e.preventDefault();
  const row=e.target.closest('.frow,.gcard');if(!row)return;
  const it=itemIndex.get(row.dataset.path);if(it)openCtxAt(e,it);
});

// ============================================================
//  OPEN ITEMS (edit / preview / navigate)
// ============================================================
function openItem(it){
  if(it.is_dir)return navTo(it.path);
  if(isImg(it.name)||MED_EXT.vid.includes(extOf(it.name))||MED_EXT.aud.includes(extOf(it.name))||extOf(it.name)==='pdf')
    return openPreview(it);
  openEditor(it.path,it.name);
}

/* editor */
async function openEditor(path,name){
  showStatusLoading('Opening…');
  const d=await api({action:'read',path});
  flashStatus('');
  if(d.error)return toast(d.error,'err');
  edPath=path;edDirty=false;$('ed-host').classList.remove('dirty');
  $('ed-ta').value=d.content;
  $('ed-title').firstChild.textContent='✏ '+name;
  $('ed-info').textContent=path;
  updateGutter();updateCaretPos();
  openModal('m-editor');
}
function closeEditor(){
  if(edDirty&&!confirm('Discard unsaved changes?'))return;
  edDirty=false;edPath=null;closeModal('m-editor');
}
function updateGutter(){
  const n=$('ed-ta').value.split('\n').length;
  $('ed-gutter').textContent=Array.from({length:n},(_,i)=>i+1).join('\n');
}
function updateCaretPos(){
  const ta=$('ed-ta'),pos=ta.selectionStart,before=ta.value.slice(0,pos);
  const line=before.split('\n').length,col=pos-before.lastIndexOf('\n');
  $('ed-pos').textContent=`Ln ${line}, Col ${col}`;
}
['keyup','click','input'].forEach(ev=>$('ed-ta').addEventListener(ev,e=>{
  if(ev==='input'){edDirty=true;$('ed-host').classList.add('dirty');updateGutter()}
  updateCaretPos();
}));
$('ed-ta').addEventListener('scroll',()=>{$('ed-gutter').scrollTop=$('ed-ta').scrollTop});
$('ed-ta').addEventListener('keydown',e=>{
  if(e.key==='Tab'){e.preventDefault();const ta=e.target,s=ta.selectionStart,en=ta.selectionEnd;
    ta.value=ta.value.slice(0,s)+'    '+ta.value.slice(en);ta.selectionStart=ta.selectionEnd=s+4;
    edDirty=true;$('ed-host').classList.add('dirty')}
});
async function doSave(){
  if(!edPath)return;
  const btn=$('m-editor').querySelector('.m-foot .primary');btn.disabled=true;
  const d=await apiPost('save',{path:edPath,content:$('ed-ta').value});
  btn.disabled=false;
  if(d.ok){edDirty=false;$('ed-host').classList.remove('dirty');toast('Saved ✓','ok')}
  else toast(d.error||'Save failed','err');
}

/* media preview */
function openPreview(it){
  const ext=extOf(it.name),url=serveUrl(it.path),body=$('pv-body');
  let html='';
  if(MED_EXT.img.includes(ext))html=`<img src="${url}" alt="">`;
  else if(MED_EXT.vid.includes(ext))html=`<video src="${url}" controls autoplay playsinline></video>`;
  else if(MED_EXT.aud.includes(ext))html=`<audio src="${url}" controls autoplay></audio>`;
  else html=`<iframe src="${url}" title="preview"></iframe>`;
  body.innerHTML=html;
  $('pv-title').innerHTML=`${fileIcon(it)} ${esc(it.name)}<small>${humanSize(it.size)}</small>`;
  $('pv-dl').onclick=()=>downloadFile(it.path);
  openModal('m-preview');
}

function downloadFile(path){
  window.location.href=SELF+'?ajax=1&action=download&path='+encodeURIComponent(path)+'&csrf_token='+CSRF;
}
function showStatusLoading(m){flashStatus(m)}

// ============================================================
//  MKDIR / NEW FILE
// ============================================================
function openMkdirModal(){openModal('m-mkdir');setTimeout(()=>$('mkdir-name').focus(),80)}
async function doMkdir(){
  const name=$('mkdir-name').value.trim();if(!name)return;
  const d=await apiPost('mkdir',{path:currentPath,name});
  closeModal('m-mkdir');
  if(d.ok){toast('Folder created ✓','ok');refreshDir()}else toast(d.error,'err');
}
function openNewFileModal(){openModal('m-newfile');setTimeout(()=>$('newfile-name').focus(),80)}
async function doNewFile(){
  const name=$('newfile-name').value.trim();if(!name)return;
  const path=(currentPath==='/'?'':currentPath)+'/'+name;
  const d=await apiPost('touch',{path});
  closeModal('m-newfile');
  if(!d.ok)return toast(d.error||'Create failed','err');
  refreshDir();
  $('ed-ta').value='';edDirty=false;edPath=path;
  $('ed-title').firstChild.textContent='✏ '+name;
  $('ed-info').textContent=path;$('ed-host').classList.remove('dirty');
  updateGutter();openModal('m-editor');
}

// ============================================================
//  UPLOAD (with progress)
// ============================================================
function openUploadModal(preset=null){
  uploadFiles=preset?[...preset]:[];
  $('upl-list').innerHTML='';$('upl-progress').classList.add('hiddenx');setBar(0);
  renderUplList();openModal('m-upload');
}
function renderUplList(){
  $('upl-list').innerHTML=uploadFiles.map(f=>`<div class="pline"><span>${esc(f.name)}</span><span>${humanSize(f.size)}</span></div>`).join('');
}
$('dz').addEventListener('click',()=>$('file-input').click());
$('file-input').addEventListener('change',e=>{uploadFiles=[...uploadFiles,...e.target.files];renderUplList()});
['dragover','dragleave','drop'].forEach(ev=>$('dz').addEventListener(ev,e=>{
  e.preventDefault();
  if(ev==='dragover')$('dz').classList.add('over');
  else $('dz').classList.remove('over');
  if(ev==='drop'&&e.dataTransfer.files.length){uploadFiles=[...uploadFiles,...e.dataTransfer.files];renderUplList()}
}));
function setBar(f){$('upl-bar').style.width=Math.round(f*100)+'%';$('upl-pct').textContent=Math.round(f*100)+'%'}
function doUpload(){
  if(!uploadFiles.length)return toast('No files chosen','err');
  $('btn-upload').disabled=true;$('upl-progress').classList.remove('hiddenx');
  const fd=new FormData();fd.append('csrf_token',CSRF);fd.append('path',currentPath);
  uploadFiles.forEach(f=>fd.append('files[]',f));
  const xhr=new XMLHttpRequest();
  xhr.open('POST',SELF+'?ajax=1&action=upload');
  xhr.upload.onprogress=e=>{if(e.lengthComputable)setBar(e.loaded/e.total)};
  xhr.onload=()=>{
    $('btn-upload').disabled=false;setBar(1);$('upl-label').textContent='Done';
    try{
      const d=JSON.parse(xhr.responseText);
      $('upl-list').innerHTML=(d.results||[]).map(r=>
        `<div class="pline"><span>${esc(r.name)}</span><span class="${r.ok?'ok':'fail'}">${r.ok?'✓ uploaded':'✗ '+(r.msg||'failed')}</span></div>`).join('');
      const okN=(d.results||[]).filter(r=>r.ok).length;
      if(okN)toast(`${okN} file${okN>1?'s':''} uploaded ✓`,'ok');
      if(d.error)toast(d.error,'err');
      refreshDir();
    }catch(e){toast('Upload parse error','err')}
    uploadFiles=[];
  };
  xhr.onerror=()=>{$('btn-upload').disabled=false;toast('Upload network error','err')};
  $('upl-label').textContent='Uploading '+uploadFiles.length+' file(s)…';
  xhr.send(fd);
}

/* whole-page drop → instant upload */
let dragDepth=0;
document.addEventListener('dragenter',e=>{
  if(e.dataTransfer&&[...e.dataTransfer.types].includes('Files')){
    dragDepth++;$('drop-overlay').classList.add('show');
  }
});
document.addEventListener('dragleave',()=>{if(--dragDepth<=0){dragDepth=0;$('drop-overlay').classList.remove('show')}});
document.addEventListener('dragover',e=>e.preventDefault());
document.addEventListener('drop',e=>{
  e.preventDefault();dragDepth=0;$('drop-overlay').classList.remove('show');
  if(e.dataTransfer&&e.dataTransfer.files.length){
    openUploadModal(e.dataTransfer.files);
    doUpload();
  }
});

// ============================================================
//  RENAME / DELETE / CHMOD
// ============================================================
let renameTarget=null;
function openRename(item){
  renameTarget=item.path;
  $('rename-old').textContent=item.path;
  $('rename-input').value=item.name;
  openModal('m-rename');setTimeout(()=>{
    const inp=$('rename-input');inp.focus();
    const dot=item.name.lastIndexOf('.');
    inp.setSelectionRange(0,dot>0?dot:item.name.length);
  },80);
}
async function doRename(){
  const nn=$('rename-input').value.trim();if(!nn||!renameTarget)return;
  const d=await apiPost('rename',{path:renameTarget,newname:nn});
  closeModal('m-rename');
  if(d.ok){toast('Renamed ✓','ok');refreshDir()}else toast(d.error,'err');
}

function promptDelete(paths){
  delTargets=paths;
  $('del-body').innerHTML=`<p style="color:var(--red);margin-bottom:10px">⚠ This is <b>permanent</b> — no recycle bin.</p>`
    +paths.map(p=>`<div style="font-family:var(--mono);font-size:11.5px">• ${esc(p)}</div>`).join('');
  openModal('m-delete');
}
$('del-go').addEventListener('click',async()=>{
  closeModal('m-delete');
  let ok=0,fail=0;
  for(const p of delTargets){const d=await apiPost('delete',{path:p});d.ok?ok++:fail++}
  toast(`Deleted ${ok}${fail?' · '+fail+' failed':''}`,fail?'err':'ok');
  selected.clear();updateSelUI();refreshDir();
});
function deleteSelected(){
  const paths=getSelectedPaths();
  if(paths.length)promptDelete(paths);
  else if(ctxItem)promptDelete([ctxItem.path]);
  else toast('Nothing selected','err');
}

let chmodTarget=null;
const CHMOD_CHIPS=['644','755','600','664','775'];
$('chmod-chips').innerHTML=CHMOD_CHIPS.map(c=>`<button class="chip" data-chmod="${c}">${c}</button>`).join('');
$('chmod-chips').addEventListener('click',e=>{
  const c=e.target.closest('[data-chmod]');if(c)$('chmod-input').value=c.dataset.chmod;
});
function openChmod(item){
  chmodTarget=item.path;
  $('chmod-path').textContent=item.path;
  $('chmod-input').value=item.perms||'';
  openModal('m-chmod');setTimeout(()=>$('chmod-input').focus(),80);
}
async function doChmod(){
  const perms=$('chmod-input').value.trim();if(!perms||!chmodTarget)return;
  const d=await apiPost('chmod',{path:chmodTarget,perms});
  closeModal('m-chmod');
  if(d.ok){toast('Permissions updated ✓','ok');refreshDir()}else toast(d.error,'err');
}

// ============================================================
//  COMPRESS / EXTRACT / COPY-MOVE
// ============================================================
async function compressItem(path){
  flashStatus('Compressing…');
  const d=await apiPost('compress',{path});
  flashStatus('');
  if(d.ok){toast('Zipped → '+d.zip,'ok');refreshDir()}else toast(d.error,'err');
}
async function extractItem(path){
  flashStatus('Extracting…');
  const d=await apiPost('extract',{path});
  flashStatus('');
  if(d.ok){toast('Extracted ✓','ok');refreshDir()}else toast(d.error,'err');
}
function compressSelected(){
  const paths=getSelectedPaths();
  paths.forEach(compressItem);
}

let cmQueue=[],cmDone=0;
function openCopyMove(items,action){
  cmTargets=Array.isArray(items)?items:[items];cmAction=action;
  $('cm-title').textContent=(action==='copy'?'📋 Copy ':'✂ Move ')+cmTargets.length+' item(s)';
  $('cm-src').innerHTML=cmTargets.map(p=>'• '+esc(p)).join('<br>');
  $('cm-dest').value=currentPath;
  $('dir-suggest').innerHTML=['/','/tmp'].map(d=>`<option value="${d}"></option>`).join('');
  openModal('m-copymove');setTimeout(()=>$('cm-dest').focus(),80);
}
function bulkCopyMove(action){const p=getSelectedPaths();p.length?openCopyMove(p,action):toast('Nothing selected','err')}
async function doCopyMove(){
  const dest=$('cm-dest').value.trim();if(!dest||!cmTargets.length)return;
  closeModal('m-copymove');
  let ok=0,fail=0;
  for(const src of cmTargets){
    const d=await apiPost(cmAction,{src,dest});
    d.ok?ok++:fail++;
  }
  toast(`${cmAction==='copy'?'Copied':'Moved'} ${ok}${fail?' · '+fail+' failed':''}`,fail?'err':'ok');
  clearSelection();refreshDir();
}

// ============================================================
//  PROPERTIES
// ============================================================
function showProps(it){
  const rows=[
    ['Name',it.name],['Path',it.path],['Type',it.is_dir?'Directory':'File'],
    ['Size',it.is_dir?'—':humanSize(it.size)+' ('+it.size.toLocaleString()+' bytes)'],
    ['Modified',fmtDate(it.mtime)],['Permissions',it.perms],
    ['Writable',it.writable?'Yes':'No'],
  ];
  $('props-body').innerHTML='<table class="props-table">'
    +rows.map(([k,v])=>`<tr><td>${k}</td><td>${esc(String(v))}</td></tr>`).join('')+'</table>';
  openModal('m-props');
}

// ============================================================
//  CONTEXT MENU
// ============================================================
function hideCtx(){$('ctx').classList.add('hiddenx')}
function openCtxAt(anchor,item){
  ctxItem=item;
  const e=extOf(item.name),isZip=e==='zip';
  const acts=[
    item.is_dir?['📂','Open',()=>navTo(item.path)]:['✏️','Open / Edit',()=>openItem(item)],
    ...(!item.is_dir?[['⬇','Download',()=>downloadFile(item.path)]]:[]),
    ...(MED_EXT.img.includes(e)||MED_EXT.vid.includes(e)||MED_EXT.aud.includes(e)||e==='pdf'
      ?[['▶️','Preview',()=>openPreview(item)]]:[]),
    null,
    ['✏️','Rename',()=>openRename(item)],
    null,
    ['📋','Copy to…',()=>openCopyMove([item.path],'copy')],
    ['✂️','Move to…',()=>openCopyMove([item.path],'move')],
    null,
    ['🗜️','Compress to ZIP',()=>compressItem(item.path)],
    ...(isZip?[['📦','Extract here',()=>extractItem(item.path)]]:[]),
    null,
    ['🔒','Permissions',()=>openChmod(item)],
    ['ℹ️','Properties',()=>showProps(item)],
    null,
    ['🗑️','Delete',()=>promptDelete([item.path]),'danger'],
  ];
  $('ctx').innerHTML=acts.map(a=>a?`<button class="ctx-item${a[3]?' danger':''}" data-i="${acts.indexOf(a)}"><span>${a[0]}</span>${a[1]}</button>`:'<div class="ctx-sep"></div>').join('');
  $('ctx').querySelectorAll('[data-i]').forEach(b=>{
    b.addEventListener('click',()=>{hideCtx();acts[+b.dataset.i][2]()});
  });
  const ctx=$('ctx');ctx.classList.remove('hiddenx');
  let x,y;
  if(anchor instanceof MouseEvent||(anchor&&anchor.x!==undefined&&anchor.left===undefined)){
    x=(anchor.clientX??anchor.x);y=(anchor.clientY??anchor.y);
    ctx.style.left=x+'px';ctx.style.top=y+'px';ctx.style.bottom='auto';ctx.style.transform='none';
  }else{ // DOMRect from ⋯ button
    const r=anchor;x=r.right;y=r.bottom;
    ctx.style.left=Math.min(x,innerWidth-210)+'px';ctx.style.top=y+4+'px';ctx.style.bottom='auto';ctx.style.transform='none';
  }
  requestAnimationFrame(()=>{ // clamp into viewport
    const rect=ctx.getBoundingClientRect();
    let nx=parseFloat(ctx.style.left),ny=parseFloat(ctx.style.top);
    if(innerWidth<=640){ctx.style.left='50%';ctx.style.right='auto';ctx.style.top='auto';ctx.style.bottom='12px';ctx.style.transform='translateX(-50%)';return}
    if(nx+rect.width>innerWidth-8)nx=innerWidth-rect.width-8;
    if(ny+rect.height>innerHeight-8)ny=Math.max(8,ny-rect.height-(anchor.bottom?anchor.height:0)-8);
    ctx.style.left=nx+'px';ctx.style.top=ny+'px';
  });
}
document.addEventListener('click',e=>{if(!e.target.closest('#ctx'))hideCtx()});
document.addEventListener('contextmenu',e=>{if(!e.target.closest('#file-area'))hideCtx()});

// ============================================================
//  DEEP SEARCH
// ============================================================
async function doSearch(){
  const q=prompt('Search recursively under '+currentPath+'\nEnter filename:');
  if(q===null)return;
  const query=q.trim();if(!query)return;
  flashStatus('Searching…');
  const d=await api({action:'search',path:currentPath,q:query});
  flashStatus('');
  const res=d.results||[];
  $('search-title').textContent=`🔍 "${query}" — ${res.length} result${res.length===1?'':'s'}`;
  $('search-body').innerHTML=res.length?res.map(r=>`
    <div class="sr-item" data-sp="${esc(r.path)}" data-sd="${r.is_dir?1:0}" data-sn="${esc(r.name)}">
      <span style="font-size:17px">${r.is_dir?'📁':fileIcon(r)}</span>
      <div style="min-width:0"><div style="font-weight:500">${esc(r.name)}</div><div class="sr-path">${esc(r.path)}</div></div>
      <span style="margin-left:auto;font-family:var(--mono);font-size:10px;color:var(--text2)">${r.is_dir?'dir':humanSize(r.size)}</span>
    </div>`).join('')
    :'<div class="empty-state"><span class="big">😕</span><h3>No results</h3></div>';
  openModal('m-search');
}
$('search-body').addEventListener('click',e=>{
  const it=e.target.closest('.sr-item');if(!it)return;
  closeModal('m-search');
  const sd=it.dataset.sd==='1';
  sd?navTo(it.dataset.sp):openItem({path:it.dataset.sp,name:it.dataset.sn,is_dir:false,size:0,mtime:0,perms:'',writable:true});
});

// ============================================================
//  COMMAND PALETTE (Ctrl/⌘+K)
// ============================================================
function paletteActions(){
  return [
    {i:'⬆',t:'Upload files',run:()=>openUploadModal()},
    {i:'📁',t:'New folder',run:openMkdirModal},
    {i:'📄',t:'New file',run:openNewFileModal},
    {i:'▦',t:'Toggle list/grid view',run:toggleView},
    {i:'🌙',t:'Toggle dark/light theme',run:toggleTheme},
    ...(TERM_OK?[{i:'⌨️',t:'Toggle terminal',run:toggleTerm}]:[]),
    {i:'🌐',t:'Deep search in folder',run:doSearch},
    {i:'↻',t:'Refresh',run:refreshDir},
    {i:'🏠',t:'Go to Root',run:()=>navTo('/')},
    {i:'☑️',t:'Select all in view',run:selectAll},
    {i:'🗜️',t:'Compress selection',run:compressSelected},
    {i:'🗑️',t:'Delete selection',run:deleteSelected},
    {i:'⏻',t:'Logout',run:()=>location.href='?logout'},
  ];
}
function openPalette(){
  $('pal-input').value='';palIdx=0;renderPal('');
  openModal('m-palette');setTimeout(()=>$('pal-input').focus(),60);
}
function renderPal(q){
  q=q.toLowerCase();
  const acts=paletteActions().filter(a=>a.t.toLowerCase().includes(q))
    .map(a=>({icon:a.i,title:a.t,sub:'action',run:a.run}));
  const items=currentItems.filter(i=>q&&i.name.toLowerCase().includes(q))
    .slice(0,10).map(i=>({icon:fileIcon(i),title:i.name,sub:(i.is_dir?'folder · ':'')+i.path,run:()=>openItem(i)}));
  palMatches=[...acts,...items].slice(0,14);
  palIdx=Math.min(palIdx,Math.max(0,palMatches.length-1));
  $('pal-list').innerHTML=palMatches.length?palMatches.map((m,i)=>`
    <div class="pal-item${i===palIdx?' on':''}" data-pi="${i}">
      <span class="pi">${m.icon}</span>
      <span class="pt"><b>${esc(m.title)}</b><span>${esc(m.sub)}</span></span>
    </div>`).join('')
    :'<div class="empty-state" style="padding:26px"><span class="big">🤷</span><h3>No matches</h3></div>';
}
$('pal-input').addEventListener('input',e=>{palIdx=0;renderPal(e.target.value)});
$('pal-input').addEventListener('keydown',e=>{
  if(e.key==='ArrowDown'){e.preventDefault();palIdx=Math.min(palIdx+1,palMatches.length-1);renderPal($('pal-input').value)}
  else if(e.key==='ArrowUp'){e.preventDefault();palIdx=Math.max(palIdx-1,0);renderPal($('pal-input').value)}
  else if(e.key==='Enter'){e.preventDefault();const m=palMatches[palIdx];if(m){closeModal('m-palette');m.run()}}
});
$('pal-list').addEventListener('click',e=>{
  const it=e.target.closest('[data-pi]');if(!it)return;
  closeModal('m-palette');palMatches[+it.dataset.pi].run();
});

// ============================================================
//  DISK INFO
// ============================================================
async function loadDiskInfo(){
  const d=await api({action:'diskinfo'});
  if(d.error)return;
  const C=2*Math.PI*52;
  $('disk-pct').textContent=d.pct+'%';
  $('disk-used').textContent=d.used_h;$('disk-total').textContent=d.total_h;$('disk-free').textContent=d.free_h;
  const fg=$('ring-fg');
  fg.style.strokeDashoffset=C*(1-d.pct/100);
  if(d.pct>85)fg.style.stroke='#fb7185';
  $('sb-php').textContent='PHP '+d.php;$('sb-os').textContent=d.os;
  $('st-php').textContent='PHP '+d.php+' · '+d.os+' · free '+d.free_h;
}

// ============================================================
//  TERMINAL
// ============================================================
function toggleTerm(){
  const p=$('term-panel');p.classList.toggle('hiddenx');
  if(!p.classList.contains('hiddenx')){$('term-input').focus();updatePrompt()}
  $('btn-term').classList.toggle('primary',!p.classList.contains('hiddenx'));
}
function closeTerm(){$('term-panel').classList.add('hiddenx');$('btn-term').classList.remove('primary')}
function toggleTermFs(){$('term-panel').classList.toggle('fullscreen');$('btn-term-fs').textContent=$('term-panel').classList.contains('fullscreen')?'🗗':'⛶'}
function clearTerm(){$('term-out').innerHTML=''}
function updatePrompt(){
  let disp=termCwd.startsWith(ROOT_ABS)?termCwd.slice(ROOT_ABS.length)||'/':termCwd;
  disp=disp.replace(/^\/+/,'~/');
  $('term-prompt').textContent=disp+' $ ';
  $('term-cwd').textContent=termCwd;
}
try{termHist=JSON.parse(localStorage.getItem('fm_term_hist')||'[]')}catch(e){}
function pushHist(cmd){termHist.unshift(cmd);termHist=termHist.slice(0,100);try{localStorage.setItem('fm_term_hist',JSON.stringify(termHist))}catch(e){}}

$('term-input').addEventListener('keydown',async e=>{
  if(e.key==='Enter'){
    const cmd=e.target.value.trim();if(!cmd)return;
    pushHist(cmd);termHistIdx=-1;e.target.value='';
    termLine('$ '+cmd,'tl-cmd');
    if(cmd==='clear'||cmd==='cls'){clearTerm();return}
    if(cmd==='help'){
      termLine('Built-ins: clear · cd <dir> · any shell command.\nHistory: ↑/↓ · Clear: Ctrl+L · Fullscreen: ⛶','');
      return;
    }
    e.target.disabled=true;
    const d=await apiPost('terminal',{cmd,cwd:termCwd});
    e.target.disabled=false;e.target.focus();
    if(d.cwd){termCwd=d.cwd;updatePrompt()}
    if(d.output)termLine(d.output,d.output.trim().endsWith('\n')?'tl-out':'tl-out');
    scrollTerm();
  }else if(e.key==='ArrowUp'){e.preventDefault();termHistIdx=Math.min(termHistIdx+1,termHist.length-1);e.target.value=termHist[termHistIdx]||''}
  else if(e.key==='ArrowDown'){e.preventDefault();termHistIdx=Math.max(termHistIdx-1,-1);e.target.value=termHistIdx>=0?termHist[termHistIdx]:''}
  else if(e.key==='l'&&e.ctrlKey){e.preventDefault();clearTerm()}
});
function termLine(text,cls){
  const div=document.createElement('div');div.className=cls;div.textContent=text;
  $('term-out').appendChild(div);scrollTerm();
}
function scrollTerm(){const o=$('term-out');o.scrollTop=o.scrollHeight}

// ============================================================
//  KEYBOARD SHORTCUTS
// ============================================================
document.addEventListener('keydown',e=>{
  const typing=/^(INPUT|TEXTAREA)$/.test(document.activeElement.tagName);
  if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='k'){e.preventDefault();openPalette();return}
  if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='s'){e.preventDefault();if(edPath)doSave();return}
  if(e.key==='Escape'){
    if(!$('m-palette').classList.contains('hiddenx'))closeModal('m-palette');
    else{closeAllModals();clearSelection()}
    return;
  }
  if(typing)return;
  if(e.key==='Delete')deleteSelected();
  if(e.key==='F2'&&ctxItem)openRename(ctxItem);
  if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='a'){e.preventDefault();selectAll()}
  if(e.key==='/' ){e.preventDefault();$('filter-input').focus()}
  if(e.altKey&&e.key==='ArrowLeft')goBack();
  if(e.altKey&&e.key==='ArrowRight')goForward();
});

// ============================================================
//  SIDEBAR DRAWER (mobile)
// ============================================================
$('btn-menu').addEventListener('click',()=>{
  $('sidebar').classList.add('open');$('sb-backdrop').classList.remove('hiddenx');
});
$('sb-backdrop').addEventListener('click',()=>{
  $('sidebar').classList.remove('open');$('sb-backdrop').classList.add('hiddenx');
});
document.querySelectorAll('[data-nav]').forEach(el=>{
  el.addEventListener('click',()=>{
    navTo(el.dataset.nav);
    document.querySelectorAll('.sb-link[data-nav]').forEach(l=>l.classList.toggle('active',l===el));
    $('sidebar').classList.remove('open');$('sb-backdrop').classList.add('hiddenx');
  });
});

// ============================================================
//  INIT
// ============================================================
$('btn-theme').addEventListener('click',toggleTheme);
$('btn-back').addEventListener('click',goBack);
$('btn-fwd').addEventListener('click',goForward);
window.addEventListener('resize',()=>{if(innerWidth>920){$('sidebar').classList.remove('open');$('sb-backdrop').classList.add('hiddenx')}});
navTo('/',false);
</script>
<?php endif; ?>
</body>
</html>
