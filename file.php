<?php
/**
 * ██████╗ ██╗  ██╗███████╗██╗     ███████╗
 * ██╔══██╗██║  ██║██╔════╝██║     ██╔════╝
 * ██████╔╝███████║█████╗  ██║     ███████╗
 * ██╔══██╗██╔══██║██╔══╝  ██║     ╚════██║
 * ██║  ██║██║  ██║███████╗███████╗███████║
 * ╚═╝  ╚═╝╚═╝  ╚═╝╚══════╝╚══════╝╚══════╝
 * Single-File PHP File Manager + Web Terminal
 * Version: 3.0 | Security: High
 */

// ============================================================
//  CONFIGURATION — edit these before deploying
// ============================================================
define('FM_PASSWORD',     'changeme123');          // Login password
define('FM_USERNAME',     'admin');                  // Login username
define('FM_ROOT',         __DIR__);                  // Root directory (restrict to this)
define('FM_SESSION_NAME', 'fm_secure_sess');         // Custom session name
define('FM_MAX_UPLOAD',   100 * 1024 * 1024);        // Max upload: 100 MB
define('FM_LANG',         'en');                     // Language (en/my)
define('FM_SELF',         basename(__FILE__));        // This file's name
define('FM_TERMINAL',     true);                     // Enable web terminal
define('FM_VERSION',      '3.0');

// ============================================================
//  SECURITY BOOTSTRAP
// ============================================================
// Prevent direct output of errors
ini_set('display_errors', 0);
error_reporting(0);

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: same-origin');
header('Content-Security-Policy: default-src \'self\'; style-src \'self\' \'unsafe-inline\' https://fonts.googleapis.com https://fonts.gstatic.com; font-src \'self\' https://fonts.gstatic.com; script-src \'self\' \'unsafe-inline\'; img-src \'self\' data:;');

// Session security
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Strict');
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    ini_set('session.cookie_secure', 1);
}
session_name(FM_SESSION_NAME);
session_start();

// Regenerate session ID periodically
if (!isset($_SESSION['_last_regen']) || time() - $_SESSION['_last_regen'] > 300) {
    session_regenerate_id(true);
    $_SESSION['_last_regen'] = time();
}

// CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Rate limiting (login brute-force protection)
if (!isset($_SESSION['login_attempts'])) $_SESSION['login_attempts'] = 0;
if (!isset($_SESSION['lockout_time']))   $_SESSION['lockout_time']   = 0;

// ============================================================
//  HELPER FUNCTIONS
// ============================================================

function fm_is_logged_in() {
    return isset($_SESSION['fm_authenticated']) && $_SESSION['fm_authenticated'] === true;
}

function fm_login($user, $pass) {
    // Rate limiting: max 5 attempts, 15-minute lockout
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
    // Sanitize and resolve path, ensuring it stays within FM_ROOT
    $path     = FM_ROOT . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
    $real     = realpath($path);
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
            'mp4'=>'video/mp4','webm'=>'video/webm','mp3'=>'audio/mpeg',
            'wav'=>'audio/wav','ogg'=>'audio/ogg'];
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
    $state_actions = ['mkdir','rename','delete','upload','save','chmod','extract','compress','terminal'];
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

        // --- Create directory ---
        case 'mkdir':
            $parent = $_POST['path'] ?? '/';
            $name   = basename(trim($_POST['name'] ?? ''));
            if (!$name || preg_match('/[\\/:*?"<>|]/', $name)) { echo json_encode(['error' => 'Invalid name']); break; }
            $real = fm_real_path($parent . '/' . $name);
            if ($real && file_exists($real)) { echo json_encode(['error' => 'Already exists']); break; }
            $target = fm_real_path($parent);
            if (!$target) { echo json_encode(['error' => 'Invalid path']); break; }
            $new_dir = $target . DIRECTORY_SEPARATOR . $name;
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

        // --- Preview image ---
        case 'preview':
            $path = $_GET['path'] ?? '';
            $real = fm_real_path($path);
            if (!$real || !is_file($real) || !fm_is_image($real)) { http_response_code(404); exit; }
            header('Content-Type: ' . fm_mime_type($real));
            header('Cache-Control: max-age=3600');
            ob_end_clean();
            readfile($real);
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
            fm_verify_csrf();
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
            // Validate cwd stays in root
            $real_cwd = realpath($cwd);
            if (!$real_cwd || strpos($real_cwd, realpath(FM_ROOT)) !== 0) {
                $real_cwd = FM_ROOT;
            }
            if (!$cmd) { echo json_encode(['output' => '', 'cwd' => $real_cwd]); break; }
            // Handle 'cd' manually
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
            // Block dangerous commands
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
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>RhelsFS — File Manager</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@300;400;500;600&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
/* ============================================================
   DESIGN SYSTEM — Dark Forge Aesthetic
   Palette: near-black, steel, amber accent, error red
   Signature: scanline overlay + terminal-style UI
   ============================================================ */
:root {
  --bg0:      #0d0f14;
  --bg1:      #13161e;
  --bg2:      #1a1e2a;
  --bg3:      #222737;
  --border:   #2e3447;
  --border-hi: #404866;
  --text0:    #e8ebf5;
  --text1:    #9ba3bf;
  --text2:    #5c647d;
  --amber:    #f5a623;
  --amber-dim:#8a5d10;
  --green:    #34d399;
  --red:      #f87171;
  --blue:     #60a5fa;
  --purple:   #a78bfa;
  --cyan:     #22d3ee;
  --mono:     'JetBrains Mono', monospace;
  --sans:     'Inter', system-ui, sans-serif;
  --radius:   6px;
  --shadow:   0 4px 24px rgba(0,0,0,.5);
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

html, body {
  height: 100%;
  background: var(--bg0);
  color: var(--text0);
  font-family: var(--sans);
  font-size: 13px;
  line-height: 1.5;
  overflow: hidden;
}

/* Scanline signature */
body::before {
  content: '';
  position: fixed;
  inset: 0;
  background: repeating-linear-gradient(
    0deg,
    transparent,
    transparent 2px,
    rgba(0,0,0,.06) 2px,
    rgba(0,0,0,.06) 4px
  );
  pointer-events: none;
  z-index: 9999;
}

/* ── SCROLLBAR ── */
::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-track { background: var(--bg1); }
::-webkit-scrollbar-thumb { background: var(--border-hi); border-radius: 3px; }

/* ── LOGIN PAGE ── */
.login-wrap {
  display: flex; align-items: center; justify-content: center;
  min-height: 100vh;
  background: radial-gradient(ellipse at 50% 30%, #1a1e2a 0%, #0d0f14 70%);
}
.login-box {
  width: 360px;
  background: var(--bg1);
  border: 1px solid var(--border);
  border-radius: 12px;
  padding: 40px 36px;
  box-shadow: var(--shadow), 0 0 60px rgba(245,166,35,.05);
}
.login-logo {
  text-align: center;
  margin-bottom: 32px;
}
.login-logo .icon {
  font-size: 32px;
  display: block;
  margin-bottom: 8px;
}
.login-logo h1 {
  font-family: var(--mono);
  font-size: 20px;
  font-weight: 600;
  color: var(--amber);
  letter-spacing: .05em;
}
.login-logo p {
  color: var(--text2);
  font-size: 11px;
  margin-top: 4px;
  font-family: var(--mono);
}
.form-group { margin-bottom: 16px; }
.form-group label {
  display: block;
  font-size: 11px;
  font-family: var(--mono);
  color: var(--text1);
  text-transform: uppercase;
  letter-spacing: .08em;
  margin-bottom: 6px;
}
.form-control {
  width: 100%;
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  color: var(--text0);
  font-family: var(--mono);
  font-size: 13px;
  padding: 9px 12px;
  outline: none;
  transition: border-color .15s;
}
.form-control:focus { border-color: var(--amber); }
.btn-login {
  width: 100%;
  background: var(--amber);
  color: #0d0f14;
  border: none;
  border-radius: var(--radius);
  font-family: var(--mono);
  font-size: 13px;
  font-weight: 600;
  padding: 10px;
  cursor: pointer;
  margin-top: 8px;
  letter-spacing: .05em;
  transition: opacity .15s;
}
.btn-login:hover { opacity: .88; }
.login-error {
  background: rgba(248,113,113,.12);
  border: 1px solid rgba(248,113,113,.3);
  border-radius: var(--radius);
  color: var(--red);
  font-family: var(--mono);
  font-size: 12px;
  padding: 8px 12px;
  margin-bottom: 16px;
}

/* ── LAYOUT ── */
#app { display: flex; flex-direction: column; height: 100vh; }

.topbar {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 0 16px;
  height: 44px;
  background: var(--bg1);
  border-bottom: 1px solid var(--border);
  flex-shrink: 0;
  user-select: none;
}
.topbar-logo {
  font-family: var(--mono);
  font-weight: 600;
  font-size: 14px;
  color: var(--amber);
  letter-spacing: .06em;
}
.topbar-logo span { color: var(--text2); font-weight: 400; }
.topbar-sep { flex: 1; }
.topbar-actions { display: flex; gap: 6px; align-items: center; }

.btn {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  background: var(--bg3);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  color: var(--text1);
  font-family: var(--mono);
  font-size: 11px;
  padding: 5px 10px;
  cursor: pointer;
  transition: border-color .15s, color .15s;
  white-space: nowrap;
}
.btn:hover { border-color: var(--border-hi); color: var(--text0); }
.btn.amber { border-color: var(--amber-dim); color: var(--amber); }
.btn.amber:hover { background: rgba(245,166,35,.1); }
.btn.danger { color: var(--red); border-color: rgba(248,113,113,.3); }
.btn.danger:hover { background: rgba(248,113,113,.1); }
.btn.sm { padding: 3px 7px; font-size: 10px; }
.btn.icon-only { padding: 5px 8px; }

.main {
  display: flex;
  flex: 1;
  overflow: hidden;
}

/* ── SIDEBAR ── */
.sidebar {
  width: 220px;
  background: var(--bg1);
  border-right: 1px solid var(--border);
  display: flex;
  flex-direction: column;
  flex-shrink: 0;
  overflow-y: auto;
}
.sidebar-section { padding: 14px 0 8px; }
.sidebar-heading {
  font-family: var(--mono);
  font-size: 9px;
  text-transform: uppercase;
  letter-spacing: .12em;
  color: var(--text2);
  padding: 0 14px 6px;
}
.sidebar-link {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 14px;
  color: var(--text1);
  font-size: 12px;
  cursor: pointer;
  border-left: 2px solid transparent;
  transition: all .12s;
}
.sidebar-link:hover { background: var(--bg2); color: var(--text0); }
.sidebar-link.active { border-left-color: var(--amber); color: var(--amber); background: rgba(245,166,35,.06); }
.sidebar-link .icon { font-size: 14px; width: 18px; text-align: center; }

.disk-meter { padding: 14px; border-top: 1px solid var(--border); }
.disk-meter-label {
  font-family: var(--mono);
  font-size: 10px;
  color: var(--text2);
  margin-bottom: 6px;
  display: flex;
  justify-content: space-between;
}
.disk-bar { height: 4px; background: var(--bg3); border-radius: 2px; overflow: hidden; }
.disk-fill { height: 100%; background: var(--amber); border-radius: 2px; transition: width .4s; }
.disk-fill.warn { background: var(--red); }

/* ── CONTENT AREA ── */
.content { flex: 1; display: flex; flex-direction: column; overflow: hidden; }

/* ── TOOLBAR ── */
.toolbar {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 14px;
  background: var(--bg2);
  border-bottom: 1px solid var(--border);
  flex-shrink: 0;
  flex-wrap: wrap;
}

.breadcrumb {
  display: flex;
  align-items: center;
  gap: 0;
  font-family: var(--mono);
  font-size: 11px;
  flex: 1;
  min-width: 0;
  overflow: hidden;
}
.breadcrumb-item {
  color: var(--text2);
  cursor: pointer;
  padding: 2px 4px;
  border-radius: 3px;
  white-space: nowrap;
}
.breadcrumb-item:hover { color: var(--amber); }
.breadcrumb-item.last { color: var(--text0); cursor: default; }
.breadcrumb-sep { color: var(--text2); margin: 0 1px; }

.search-box {
  display: flex;
  align-items: center;
  gap: 0;
  background: var(--bg1);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 0 8px;
}
.search-box input {
  background: none;
  border: none;
  outline: none;
  color: var(--text0);
  font-family: var(--mono);
  font-size: 11px;
  padding: 4px 4px;
  width: 160px;
}
.search-box input::placeholder { color: var(--text2); }
.search-btn { background: none; border: none; color: var(--text2); cursor: pointer; font-size: 13px; padding: 2px; }

/* ── FILE LIST ── */
.file-area { flex: 1; overflow-y: auto; padding: 0; }

.file-table {
  width: 100%;
  border-collapse: collapse;
}
.file-table thead th {
  position: sticky;
  top: 0;
  background: var(--bg2);
  border-bottom: 1px solid var(--border);
  padding: 7px 14px;
  text-align: left;
  font-family: var(--mono);
  font-size: 10px;
  text-transform: uppercase;
  letter-spacing: .08em;
  color: var(--text2);
  font-weight: 500;
  cursor: pointer;
  user-select: none;
  white-space: nowrap;
}
.file-table thead th:hover { color: var(--text1); }
.file-table tbody tr {
  border-bottom: 1px solid rgba(46,52,71,.5);
  transition: background .08s;
  cursor: pointer;
}
.file-table tbody tr:hover { background: var(--bg2); }
.file-table tbody tr.selected { background: rgba(245,166,35,.08); }
.file-table td {
  padding: 6px 14px;
  font-size: 12px;
  color: var(--text1);
  white-space: nowrap;
}
.file-table td:first-child { color: var(--text0); max-width: 300px; overflow: hidden; text-overflow: ellipsis; }
.file-name {
  display: flex;
  align-items: center;
  gap: 8px;
}
.file-icon { font-size: 15px; flex-shrink: 0; }
.file-perms { font-family: var(--mono); font-size: 11px; color: var(--text2); }
.file-date { font-family: var(--mono); font-size: 11px; }
.file-size { font-family: var(--mono); font-size: 11px; }
.dir-icon { color: var(--amber); }
.col-check { width: 36px; }
.check { accent-color: var(--amber); cursor: pointer; }

/* ── EMPTY STATE ── */
.empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 12px;
  height: 200px;
  color: var(--text2);
  font-family: var(--mono);
}
.empty-state .icon { font-size: 36px; }

/* ── STATUS BAR ── */
.statusbar {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 4px 14px;
  background: var(--bg1);
  border-top: 1px solid var(--border);
  font-family: var(--mono);
  font-size: 10px;
  color: var(--text2);
  flex-shrink: 0;
}
.statusbar span { color: var(--text1); }
#status-msg { color: var(--green); transition: opacity .4s; }
#status-msg.error { color: var(--red); }

/* ── TERMINAL PANEL ── */
.terminal-panel {
  height: 260px;
  background: #080a10;
  border-top: 2px solid var(--amber-dim);
  display: flex;
  flex-direction: column;
  flex-shrink: 0;
  font-family: var(--mono);
  font-size: 12px;
}
.terminal-panel.hidden { display: none; }
.terminal-header {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 5px 12px;
  background: var(--bg0);
  border-bottom: 1px solid var(--border);
  flex-shrink: 0;
}
.terminal-header-title { font-size: 11px; color: var(--amber); letter-spacing: .08em; }
.terminal-dots { display: flex; gap: 5px; }
.terminal-dot { width: 10px; height: 10px; border-radius: 50%; }
.td-red { background: #f87171; }
.td-yellow { background: var(--amber); }
.td-green { background: var(--green); }
.terminal-output {
  flex: 1;
  overflow-y: auto;
  padding: 8px 14px;
  color: #c9d1e6;
  white-space: pre-wrap;
  word-break: break-all;
}
.term-line-out { color: #c9d1e6; }
.term-line-cmd { color: var(--amber); }
.term-line-err { color: var(--red); }
.terminal-input-row {
  display: flex;
  align-items: center;
  gap: 0;
  padding: 6px 14px;
  border-top: 1px solid var(--border);
  background: #080a10;
  flex-shrink: 0;
}
.term-prompt { color: var(--green); margin-right: 6px; white-space: nowrap; }
.term-input {
  flex: 1;
  background: none;
  border: none;
  outline: none;
  color: var(--text0);
  font-family: var(--mono);
  font-size: 12px;
  caret-color: var(--amber);
}

/* ── MODALS ── */
.overlay {
  position: fixed; inset: 0;
  background: rgba(8,10,16,.75);
  backdrop-filter: blur(2px);
  z-index: 1000;
  display: flex;
  align-items: center;
  justify-content: center;
}
.overlay.hidden { display: none; }
.modal {
  background: var(--bg1);
  border: 1px solid var(--border-hi);
  border-radius: 10px;
  width: 540px;
  max-width: 95vw;
  max-height: 90vh;
  display: flex;
  flex-direction: column;
  box-shadow: var(--shadow);
}
.modal.modal-lg { width: 860px; }
.modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 20px;
  border-bottom: 1px solid var(--border);
  flex-shrink: 0;
}
.modal-title { font-family: var(--mono); font-size: 13px; color: var(--text0); font-weight: 600; }
.modal-close {
  background: none; border: none; color: var(--text2);
  cursor: pointer; font-size: 18px; line-height: 1; padding: 2px;
}
.modal-close:hover { color: var(--red); }
.modal-body { padding: 20px; overflow-y: auto; flex: 1; }
.modal-footer { padding: 14px 20px; border-top: 1px solid var(--border); display: flex; gap: 8px; justify-content: flex-end; flex-shrink: 0; }
.btn-primary {
  background: var(--amber);
  color: #0d0f14;
  border: none;
  border-radius: var(--radius);
  font-family: var(--mono);
  font-size: 12px;
  font-weight: 600;
  padding: 7px 16px;
  cursor: pointer;
}
.btn-primary:hover { opacity: .88; }
.btn-cancel {
  background: var(--bg3);
  border: 1px solid var(--border);
  color: var(--text1);
  border-radius: var(--radius);
  font-family: var(--mono);
  font-size: 12px;
  padding: 7px 14px;
  cursor: pointer;
}
.btn-cancel:hover { border-color: var(--border-hi); }

/* Editor */
#editor-textarea {
  width: 100%;
  background: #080a10;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  color: #c9d1e6;
  font-family: var(--mono);
  font-size: 12px;
  line-height: 1.6;
  padding: 12px;
  resize: vertical;
  min-height: 360px;
  outline: none;
  tab-size: 4;
}
#editor-textarea:focus { border-color: var(--amber-dim); }

/* Image preview */
.preview-img { max-width: 100%; max-height: 60vh; display: block; margin: 0 auto; border-radius: var(--radius); }

/* Upload drop zone */
.drop-zone {
  border: 2px dashed var(--border-hi);
  border-radius: 10px;
  padding: 36px;
  text-align: center;
  cursor: pointer;
  transition: border-color .15s, background .15s;
  font-family: var(--mono);
  color: var(--text2);
}
.drop-zone:hover, .drop-zone.drag-over {
  border-color: var(--amber);
  background: rgba(245,166,35,.04);
  color: var(--text1);
}
.drop-zone .drop-icon { font-size: 32px; margin-bottom: 10px; display: block; }
#upload-file-input { display: none; }
.upload-list { margin-top: 14px; }
.upload-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 6px 10px;
  background: var(--bg2);
  border-radius: 4px;
  margin-bottom: 4px;
  font-size: 11px;
  font-family: var(--mono);
  color: var(--text1);
}
.upload-item .ok { color: var(--green); }
.upload-item .fail { color: var(--red); }

/* Context menu */
.ctx-menu {
  position: fixed;
  background: var(--bg2);
  border: 1px solid var(--border-hi);
  border-radius: 8px;
  padding: 4px;
  z-index: 2000;
  min-width: 180px;
  box-shadow: var(--shadow);
}
.ctx-menu.hidden { display: none; }
.ctx-item {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 7px 12px;
  color: var(--text1);
  font-size: 12px;
  font-family: var(--mono);
  cursor: pointer;
  border-radius: 5px;
  transition: background .08s, color .08s;
}
.ctx-item:hover { background: var(--bg3); color: var(--text0); }
.ctx-item.danger:hover { background: rgba(248,113,113,.1); color: var(--red); }
.ctx-sep { height: 1px; background: var(--border); margin: 4px 8px; }

/* Input field in modal */
.modal-input {
  width: 100%;
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  color: var(--text0);
  font-family: var(--mono);
  font-size: 13px;
  padding: 9px 12px;
  outline: none;
  margin-top: 8px;
}
.modal-input:focus { border-color: var(--amber-dim); }
.modal-label { font-size: 11px; font-family: var(--mono); color: var(--text1); text-transform: uppercase; letter-spacing: .07em; }

/* Permission badge */
.perm-exec { color: var(--green); }
.perm-write { color: var(--amber); }
.perm-read { color: var(--blue); }
.perm-none { color: var(--text2); }

/* Search results */
.search-results { padding: 8px 0; }
.search-result-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 7px 14px;
  cursor: pointer;
  transition: background .08s;
  border-bottom: 1px solid rgba(46,52,71,.3);
}
.search-result-item:hover { background: var(--bg2); }
.search-result-path { font-family: var(--mono); font-size: 10px; color: var(--text2); }

/* Toast */
#toast {
  position: fixed;
  bottom: 24px;
  right: 24px;
  background: var(--bg2);
  border: 1px solid var(--border-hi);
  border-radius: 8px;
  padding: 12px 18px;
  font-family: var(--mono);
  font-size: 12px;
  color: var(--text0);
  box-shadow: var(--shadow);
  z-index: 3000;
  transition: opacity .3s;
  opacity: 0;
  pointer-events: none;
  max-width: 320px;
}
#toast.show { opacity: 1; }
#toast.ok { border-color: var(--green); color: var(--green); }
#toast.err { border-color: var(--red); color: var(--red); }

/* Properties table */
.props-table { width: 100%; border-collapse: collapse; }
.props-table td { padding: 6px 10px; font-size: 12px; border-bottom: 1px solid var(--border); }
.props-table td:first-child { color: var(--text2); font-family: var(--mono); font-size: 11px; width: 120px; }

/* Spinner */
.spin {
  display: inline-block;
  width: 12px; height: 12px;
  border: 2px solid var(--border);
  border-top-color: var(--amber);
  border-radius: 50%;
  animation: spin .6s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* View toggle */
.view-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(100px, 1fr)); gap: 10px; padding: 14px; }
.grid-item {
  display: flex; flex-direction: column; align-items: center;
  gap: 6px; padding: 12px 8px;
  background: var(--bg2); border: 1px solid var(--border);
  border-radius: 8px; cursor: pointer; transition: all .12s;
  text-align: center;
}
.grid-item:hover { border-color: var(--amber); background: rgba(245,166,35,.04); }
.grid-item.selected { border-color: var(--amber); background: rgba(245,166,35,.08); }
.grid-icon { font-size: 28px; }
.grid-name { font-size: 11px; color: var(--text1); word-break: break-all; line-height: 1.3; }

.hidden { display: none !important; }
</style>
</head>
<body>

<?php if (!fm_is_logged_in()): ?>
<!-- ============================================================
     LOGIN SCREEN
     ============================================================ -->
<div class="login-wrap">
  <div class="login-box">
    <div class="login-logo">
      <span class="icon">🗄️</span>
      <h1>RhelsFS</h1>
      <p>Secure File Manager v<?= FM_VERSION ?></p>
    </div>
    <?php if ($login_error): ?>
      <div class="login-error">⚠️ <?= htmlspecialchars($login_error) ?></div>
    <?php endif; ?>
    <?php if (isset($_GET['expired'])): ?>
      <div class="login-error">⏱ Session expired. Please log in again.</div>
    <?php endif; ?>
    <form method="POST">
      <div class="form-group">
        <label>Username</label>
        <input type="text" name="username" class="form-control" autocomplete="username" autofocus required>
      </div>
      <div class="form-group">
        <label>Password</label>
        <input type="password" name="password" class="form-control" autocomplete="current-password" required>
      </div>
      <button type="submit" name="fm_login" class="btn-login">→ Login</button>
    </form>
  </div>
</div>

<?php else: ?>
<!-- ============================================================
     MAIN APP
     ============================================================ -->
<div id="app">

  <!-- TOPBAR -->
  <div class="topbar">
    <div class="topbar-logo">RhelsFS <span>/ <?= htmlspecialchars(FM_USERNAME) ?></span></div>
    <div class="topbar-sep"></div>
    <div class="topbar-actions">
      <button class="btn amber" onclick="openUploadModal()">⬆ Upload</button>
      <button class="btn" onclick="openMkdirModal()">📁 New Folder</button>
      <button class="btn" onclick="openNewFileModal()">📄 New File</button>
      <?php if (FM_TERMINAL): ?>
      <button class="btn" onclick="toggleTerminal()" id="term-btn">⌨ Terminal</button>
      <?php endif; ?>
      <button class="btn danger" onclick="location.href='?logout'">⏻ Logout</button>
    </div>
  </div>

  <div class="main">
    <!-- SIDEBAR -->
    <div class="sidebar">
      <div class="sidebar-section">
        <div class="sidebar-heading">Navigation</div>
        <div class="sidebar-link active" onclick="navTo('/')" id="nav-root">
          <span class="icon dir-icon">🏠</span> Root
        </div>
        <div class="sidebar-link" onclick="navTo('/tmp')" id="nav-tmp">
          <span class="icon">📦</span> /tmp
        </div>
      </div>
      <div class="sidebar-section">
        <div class="sidebar-heading">Actions</div>
        <div class="sidebar-link" onclick="deleteSelected()">
          <span class="icon">🗑</span> Delete Selected
        </div>
        <div class="sidebar-link" onclick="compressSelected()">
          <span class="icon">🗜</span> Compress
        </div>
        <div class="sidebar-link" onclick="toggleView()">
          <span class="icon">⊞</span> Toggle View
        </div>
      </div>
      <div style="flex:1"></div>
      <div class="disk-meter" id="disk-meter">
        <div class="disk-meter-label">
          <span>Storage</span>
          <span id="disk-used-pct">…</span>
        </div>
        <div class="disk-bar"><div class="disk-fill" id="disk-fill" style="width:0%"></div></div>
        <div style="font-family:var(--mono);font-size:10px;color:var(--text2);margin-top:5px;">
          <span id="disk-used-txt">-</span> / <span id="disk-total-txt">-</span>
        </div>
      </div>
    </div>

    <!-- CONTENT -->
    <div class="content">
      <!-- TOOLBAR -->
      <div class="toolbar">
        <div class="breadcrumb" id="breadcrumb"></div>
        <div class="search-box">
          <input type="text" id="search-input" placeholder="Search files…" onkeydown="if(event.key==='Enter')doSearch()">
          <button class="search-btn" onclick="doSearch()">🔍</button>
        </div>
        <button class="btn sm" onclick="refreshDir()">↻</button>
        <button class="btn sm icon-only" id="sort-btn" title="Sort" onclick="cycleSortMode()">⇅</button>
      </div>

      <!-- FILE AREA -->
      <div class="file-area" id="file-area" oncontextmenu="return false;">
        <table class="file-table" id="file-table">
          <thead>
            <tr>
              <th class="col-check"><input type="checkbox" id="check-all" class="check" onchange="toggleCheckAll(this)"></th>
              <th onclick="setSortColumn('name')">Name</th>
              <th onclick="setSortColumn('size')">Size</th>
              <th onclick="setSortColumn('mtime')">Modified</th>
              <th onclick="setSortColumn('perms')">Perms</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="file-tbody"></tbody>
        </table>
        <div class="view-grid hidden" id="grid-view"></div>
      </div>

      <!-- STATUS BAR -->
      <div class="statusbar">
        <span id="status-count">0 items</span>
        <span id="status-sel">0 selected</span>
        <span id="status-msg"></span>
        <span style="flex:1"></span>
        <span id="status-php"></span>
      </div>

      <!-- TERMINAL -->
      <?php if (FM_TERMINAL): ?>
      <div class="terminal-panel hidden" id="terminal-panel">
        <div class="terminal-header">
          <div class="terminal-dots">
            <div class="terminal-dot td-red" onclick="closeTerminal()" title="Close" style="cursor:pointer"></div>
            <div class="terminal-dot td-yellow"></div>
            <div class="terminal-dot td-green"></div>
          </div>
          <div class="terminal-header-title">⌨ Web Terminal</div>
          <div style="margin-left:auto;font-family:var(--mono);font-size:10px;color:var(--text2);" id="term-cwd-display"></div>
          <button class="btn sm" onclick="clearTerm()" style="margin-left:8px;">Clear</button>
        </div>
        <div class="terminal-output" id="terminal-output"></div>
        <div class="terminal-input-row">
          <span class="term-prompt" id="term-prompt">$ </span>
          <input type="text" class="term-input" id="term-input"
            placeholder="Enter command…"
            onkeydown="handleTermKey(event)"
            autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false">
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ── MODALS ── -->

<!-- Upload -->
<div class="overlay hidden" id="modal-upload">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">⬆ Upload Files</div>
      <button class="modal-close" onclick="closeModal('modal-upload')">✕</button>
    </div>
    <div class="modal-body">
      <div class="drop-zone" id="drop-zone" onclick="document.getElementById('upload-file-input').click()">
        <span class="drop-icon">☁</span>
        <strong>Click to browse</strong> or drag & drop files here<br>
        <small style="color:var(--text2);margin-top:4px;display:block">Max <?= fm_human_size(FM_MAX_UPLOAD) ?> per file</small>
      </div>
      <input type="file" id="upload-file-input" multiple onchange="handleFileSelect(this.files)">
      <div class="upload-list" id="upload-list"></div>
    </div>
    <div class="modal-footer">
      <button class="btn-cancel" onclick="closeModal('modal-upload')">Close</button>
      <button class="btn-primary" id="upload-btn" onclick="doUpload()">Upload</button>
    </div>
  </div>
</div>

<!-- New folder -->
<div class="overlay hidden" id="modal-mkdir">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">📁 New Folder</div>
      <button class="modal-close" onclick="closeModal('modal-mkdir')">✕</button>
    </div>
    <div class="modal-body">
      <div class="modal-label">Folder Name</div>
      <input type="text" id="mkdir-name" class="modal-input" placeholder="e.g. my-folder" onkeydown="if(event.key==='Enter')doMkdir()">
    </div>
    <div class="modal-footer">
      <button class="btn-cancel" onclick="closeModal('modal-mkdir')">Cancel</button>
      <button class="btn-primary" onclick="doMkdir()">Create</button>
    </div>
  </div>
</div>

<!-- New file -->
<div class="overlay hidden" id="modal-newfile">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">📄 New File</div>
      <button class="modal-close" onclick="closeModal('modal-newfile')">✕</button>
    </div>
    <div class="modal-body">
      <div class="modal-label">File Name</div>
      <input type="text" id="newfile-name" class="modal-input" placeholder="e.g. index.html" onkeydown="if(event.key==='Enter')doNewFile()">
    </div>
    <div class="modal-footer">
      <button class="btn-cancel" onclick="closeModal('modal-newfile')">Cancel</button>
      <button class="btn-primary" onclick="doNewFile()">Create</button>
    </div>
  </div>
</div>

<!-- Editor -->
<div class="overlay hidden" id="modal-editor">
  <div class="modal modal-lg">
    <div class="modal-header">
      <div class="modal-title" id="editor-title">Editor</div>
      <div style="display:flex;gap:8px;align-items:center">
        <span style="font-family:var(--mono);font-size:10px;color:var(--text2);" id="editor-info"></span>
        <button class="modal-close" onclick="closeModal('modal-editor')">✕</button>
      </div>
    </div>
    <div class="modal-body" style="padding:12px;">
      <textarea id="editor-textarea" spellcheck="false" onkeydown="handleEditorKey(event)"></textarea>
    </div>
    <div class="modal-footer">
      <button class="btn-cancel" onclick="closeModal('modal-editor')">Discard</button>
      <button class="btn-primary" onclick="doSave()">💾 Save (Ctrl+S)</button>
    </div>
  </div>
</div>

<!-- Image preview -->
<div class="overlay hidden" id="modal-preview">
  <div class="modal modal-lg">
    <div class="modal-header">
      <div class="modal-title" id="preview-title">Preview</div>
      <button class="modal-close" onclick="closeModal('modal-preview')">✕</button>
    </div>
    <div class="modal-body" style="text-align:center;">
      <img class="preview-img" id="preview-img" src="" alt="">
    </div>
    <div class="modal-footer">
      <button class="btn-cancel" onclick="closeModal('modal-preview')">Close</button>
      <button class="btn-primary" id="preview-dl-btn" onclick="">⬇ Download</button>
    </div>
  </div>
</div>

<!-- Rename -->
<div class="overlay hidden" id="modal-rename">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">✏ Rename</div>
      <button class="modal-close" onclick="closeModal('modal-rename')">✕</button>
    </div>
    <div class="modal-body">
      <div class="modal-label">New Name</div>
      <input type="text" id="rename-input" class="modal-input" onkeydown="if(event.key==='Enter')doRename()">
    </div>
    <div class="modal-footer">
      <button class="btn-cancel" onclick="closeModal('modal-rename')">Cancel</button>
      <button class="btn-primary" onclick="doRename()">Rename</button>
    </div>
  </div>
</div>

<!-- chmod -->
<div class="overlay hidden" id="modal-chmod">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">🔒 Change Permissions</div>
      <button class="modal-close" onclick="closeModal('modal-chmod')">✕</button>
    </div>
    <div class="modal-body">
      <div class="modal-label">Path</div>
      <div style="font-family:var(--mono);font-size:12px;color:var(--text1);margin-bottom:14px;" id="chmod-path-display"></div>
      <div class="modal-label">Octal Permissions</div>
      <input type="text" id="chmod-input" class="modal-input" placeholder="e.g. 755" maxlength="4" onkeydown="if(event.key==='Enter')doChmod()">
      <div style="font-family:var(--mono);font-size:10px;color:var(--text2);margin-top:10px;">
        Common: 644 (file), 755 (dir), 600 (private), 777 (world-writable)
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn-cancel" onclick="closeModal('modal-chmod')">Cancel</button>
      <button class="btn-primary" onclick="doChmod()">Apply</button>
    </div>
  </div>
</div>

<!-- Properties -->
<div class="overlay hidden" id="modal-props">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">ℹ Properties</div>
      <button class="modal-close" onclick="closeModal('modal-props')">✕</button>
    </div>
    <div class="modal-body" id="props-body"></div>
    <div class="modal-footer">
      <button class="btn-cancel" onclick="closeModal('modal-props')">Close</button>
    </div>
  </div>
</div>

<!-- Copy/Move -->
<div class="overlay hidden" id="modal-copymove">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title" id="copymove-title">Copy / Move</div>
      <button class="modal-close" onclick="closeModal('modal-copymove')">✕</button>
    </div>
    <div class="modal-body">
      <div class="modal-label">Destination Path</div>
      <input type="text" id="copymove-dest" class="modal-input" placeholder="e.g. /backups" onkeydown="if(event.key==='Enter')doCopyMove()">
    </div>
    <div class="modal-footer">
      <button class="btn-cancel" onclick="closeModal('modal-copymove')">Cancel</button>
      <button class="btn-primary" onclick="doCopyMove()">Go</button>
    </div>
  </div>
</div>

<!-- Confirm delete -->
<div class="overlay hidden" id="modal-delete">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">🗑 Confirm Delete</div>
      <button class="modal-close" onclick="closeModal('modal-delete')">✕</button>
    </div>
    <div class="modal-body" id="delete-body" style="font-family:var(--mono);font-size:12px;color:var(--text1);line-height:1.8;"></div>
    <div class="modal-footer">
      <button class="btn-cancel" onclick="closeModal('modal-delete')">Cancel</button>
      <button class="btn-primary" style="background:var(--red);color:#fff;" onclick="confirmDelete()">🗑 Delete</button>
    </div>
  </div>
</div>

<!-- Search results -->
<div class="overlay hidden" id="modal-search">
  <div class="modal modal-lg">
    <div class="modal-header">
      <div class="modal-title" id="search-title">🔍 Search Results</div>
      <button class="modal-close" onclick="closeModal('modal-search')">✕</button>
    </div>
    <div class="modal-body" style="padding:0;" id="search-body"></div>
  </div>
</div>

<!-- Context menu -->
<div class="ctx-menu hidden" id="ctx-menu"></div>

<!-- Toast -->
<div id="toast"></div>

<script>
// ============================================================
//  GLOBALS
// ============================================================
const CSRF = <?= json_encode($csrf_token) ?>;
const SELF = '<?= FM_SELF ?>';
let currentPath = '/';
let currentItems = [];
let sortColumn = 'name';
let sortAsc = true;
let viewMode = 'list'; // list | grid
let clipboard = null; // {action:'copy'|'move', path}
let ctxTarget = null;
let renameTarget = null;
let chmodTarget = null;
let copyMoveTarget = null;
let copyMoveAction = null;
let deleteTargets = [];
let termCwd = '<?= addslashes(realpath(FM_ROOT)) ?>';
let termHistory = [];
let termHistIdx = -1;

// ============================================================
//  INIT
// ============================================================
document.addEventListener('DOMContentLoaded', () => {
    navTo('/');
    loadDiskInfo();
    document.addEventListener('click', (e) => {
        if (!e.target.closest('#ctx-menu')) hideCtxMenu();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') { closeAllModals(); hideCtxMenu(); }
        if (e.ctrlKey && e.key === 's') { e.preventDefault(); doSave(); }
        if (e.key === 'F2' && ctxTarget) openRenameModal(ctxTarget.path, ctxTarget.name);
        if (e.key === 'Delete') deleteSelected();
    });
    setupDrop();
});

// ============================================================
//  API HELPER
// ============================================================
async function api(params, method='GET', body=null) {
    const url = SELF + '?ajax=1&' + new URLSearchParams(params);
    const opts = { method };
    if (body) {
        body.append('csrf_token', CSRF);
        opts.body = body;
    }
    try {
        const r = await fetch(url, opts);
        return await r.json();
    } catch(e) {
        return { error: 'Network error: ' + e.message };
    }
}

function apiPost(action, data={}) {
    const fd = new FormData();
    Object.entries(data).forEach(([k,v]) => fd.append(k, v));
    return api({ action }, 'POST', fd);
}

// ============================================================
//  NAVIGATION
// ============================================================
async function navTo(path) {
    currentPath = path;
    showStatus('Loading…');
    const data = await api({ action:'ls', path });
    if (data.error) { showToast(data.error, 'err'); return; }
    currentItems = data.items || [];
    renderBreadcrumb(data.crumbs || []);
    renderFiles();
    clearStatus();
    loadDiskInfo();
}

function refreshDir() { navTo(currentPath); }

function renderBreadcrumb(crumbs) {
    const el = document.getElementById('breadcrumb');
    el.innerHTML = crumbs.map((c, i) => {
        const isLast = i === crumbs.length - 1;
        const cls = isLast ? 'breadcrumb-item last' : 'breadcrumb-item';
        const onclick = isLast ? '' : `onclick="navTo('${esc(c.path)}')"`;
        return (i > 0 ? '<span class="breadcrumb-sep">/</span>' : '')
             + `<span class="${cls}" ${onclick}>${esc(c.name)}</span>`;
    }).join('');
}

// ============================================================
//  FILE RENDERING
// ============================================================
function fileIcon(item) {
    if (item.is_dir) return '📁';
    const ext = item.name.split('.').pop().toLowerCase();
    const map = {
        php:'🐘',js:'📜',ts:'📘',html:'🌐',htm:'🌐',css:'🎨',
        json:'📋',xml:'📋',yaml:'📋',yml:'📋',
        md:'📝',txt:'📝',log:'📋',
        jpg:'🖼',jpeg:'🖼',png:'🖼',gif:'🖼',webp:'🖼',svg:'🖼',ico:'🖼',
        pdf:'📑',zip:'📦',tar:'📦',gz:'📦',rar:'📦',
        mp4:'🎬',webm:'🎬',mov:'🎬',
        mp3:'🎵',wav:'🎵',ogg:'🎵',
        py:'🐍',rb:'💎',go:'🐹',c:'⚙',cpp:'⚙',java:'☕',rs:'🦀',
        sh:'💻',bash:'💻',sql:'🗄',
    };
    return map[ext] || '📄';
}

function formatDate(ts) {
    if (!ts) return '-';
    const d = new Date(ts * 1000);
    return d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'});
}

function renderFiles() {
    const sorted = [...currentItems].sort((a, b) => {
        if (a.is_dir !== b.is_dir) return b.is_dir - a.is_dir;
        let va = a[sortColumn], vb = b[sortColumn];
        if (typeof va === 'string') va = va.toLowerCase();
        if (typeof vb === 'string') vb = vb.toLowerCase();
        return sortAsc ? (va > vb ? 1 : -1) : (va < vb ? 1 : -1);
    });

    if (viewMode === 'grid') {
        renderGrid(sorted);
        return;
    }

    const tbody = document.getElementById('file-tbody');
    if (sorted.length === 0) {
        tbody.innerHTML = `<tr><td colspan="6"><div class="empty-state"><span class="icon">📂</span><span>Empty directory</span></div></td></tr>`;
        document.getElementById('status-count').textContent = '0 items';
        return;
    }

    tbody.innerHTML = sorted.map(item => {
        const perms = colorPerms(item.perms);
        return `<tr data-path="${esc(item.path)}" data-name="${esc(item.name)}" data-isdir="${item.is_dir?1:0}"
            onclick="handleRowClick(event, '${esc(item.path)}', ${item.is_dir?1:0}, '${esc(item.name)}')"
            ondblclick="handleDblClick('${esc(item.path)}', ${item.is_dir?1:0})"
            oncontextmenu="showCtxMenu(event, '${esc(item.path)}', '${esc(item.name)}', ${item.is_dir?1:0})">
          <td class="col-check"><input type="checkbox" class="check row-check" data-path="${esc(item.path)}" onclick="e=>e.stopPropagation()"></td>
          <td>
            <div class="file-name">
              <span class="file-icon${item.is_dir?' dir-icon':''}">${fileIcon(item)}</span>
              <span>${esc(item.name)}</span>
            </div>
          </td>
          <td class="file-size">${item.is_dir ? '—' : humanSize(item.size)}</td>
          <td class="file-date">${formatDate(item.mtime)}</td>
          <td class="file-perms">${perms}</td>
          <td>
            <div style="display:flex;gap:4px;">
              ${item.is_dir
                ? `<button class="btn sm" onclick="event.stopPropagation();navTo('${esc(item.path)}')">Open</button>`
                : `<button class="btn sm" onclick="event.stopPropagation();openFile('${esc(item.path)}','${esc(item.name)}')">View</button>
                   <button class="btn sm" onclick="event.stopPropagation();downloadFile('${esc(item.path)}')">⬇</button>`
              }
              <button class="btn sm danger" onclick="event.stopPropagation();promptDelete(['${esc(item.path)}'])">✕</button>
            </div>
          </td>
        </tr>`;
    }).join('');

    document.getElementById('status-count').textContent = sorted.length + ' items';
    document.getElementById('file-table').classList.remove('hidden');
    document.getElementById('grid-view').classList.add('hidden');
}

function renderGrid(items) {
    document.getElementById('file-table').classList.add('hidden');
    const gv = document.getElementById('grid-view');
    gv.classList.remove('hidden');
    gv.innerHTML = items.map(item => `
        <div class="grid-item" data-path="${esc(item.path)}"
            ondblclick="handleDblClick('${esc(item.path)}', ${item.is_dir?1:0})"
            oncontextmenu="showCtxMenu(event,'${esc(item.path)}','${esc(item.name)}',${item.is_dir?1:0})">
          <div class="grid-icon">${fileIcon(item)}</div>
          <div class="grid-name">${esc(item.name)}</div>
        </div>`).join('');
    document.getElementById('status-count').textContent = items.length + ' items';
}

function colorPerms(perms) {
    if (!perms) return '-';
    return `<span style="font-family:var(--mono);font-size:11px;">${perms}</span>`;
}

// ============================================================
//  ROW INTERACTIONS
// ============================================================
function handleRowClick(e, path, isDir, name) {
    if (e.target.type === 'checkbox') return;
    // Ctrl+click = select
    if (e.ctrlKey) {
        const row = e.currentTarget;
        const cb = row.querySelector('.row-check');
        if (cb) { cb.checked = !cb.checked; updateSelCount(); }
        return;
    }
    ctxTarget = { path, name, isDir: !!isDir };
}

function handleDblClick(path, isDir) {
    if (isDir) navTo(path);
    else openFile(path, path.split('/').pop());
}

function updateSelCount() {
    const sel = document.querySelectorAll('.row-check:checked').length;
    document.getElementById('status-sel').textContent = sel + ' selected';
}

function toggleCheckAll(cb) {
    document.querySelectorAll('.row-check').forEach(c => c.checked = cb.checked);
    updateSelCount();
}

function getSelectedPaths() {
    return Array.from(document.querySelectorAll('.row-check:checked')).map(c => c.dataset.path);
}

// ============================================================
//  FILE OPERATIONS
// ============================================================
async function openFile(path, name) {
    const ext = name.split('.').pop().toLowerCase();
    const imgExts = ['jpg','jpeg','png','gif','webp','bmp','ico'];
    if (imgExts.includes(ext)) {
        openImagePreview(path, name); return;
    }
    showStatus('Loading…');
    const data = await api({ action:'read', path });
    clearStatus();
    if (data.error) { showToast(data.error,'err'); return; }
    document.getElementById('editor-textarea').value = data.content;
    document.getElementById('editor-title').textContent = '✏ ' + name;
    document.getElementById('editor-info').textContent = path;
    document.getElementById('editor-textarea').dataset.path = path;
    openModal('modal-editor');
}

function openImagePreview(path, name) {
    document.getElementById('preview-title').textContent = '🖼 ' + name;
    document.getElementById('preview-img').src = SELF + '?ajax=1&action=preview&path=' + encodeURIComponent(path) + '&csrf_token=' + CSRF;
    document.getElementById('preview-dl-btn').onclick = () => downloadFile(path);
    openModal('modal-preview');
}

async function doSave() {
    const ta = document.getElementById('editor-textarea');
    const path = ta.dataset.path;
    if (!path) return;
    const data = await apiPost('save', { path, content: ta.value });
    if (data.ok) { showToast('Saved ✓', 'ok'); }
    else showToast(data.error || 'Save failed', 'err');
}

function downloadFile(path) {
    window.location.href = SELF + '?ajax=1&action=download&path=' + encodeURIComponent(path) + '&csrf_token=' + CSRF;
}

// ============================================================
//  MKDIR / NEW FILE
// ============================================================
function openMkdirModal() {
    document.getElementById('mkdir-name').value = '';
    openModal('modal-mkdir');
    setTimeout(() => document.getElementById('mkdir-name').focus(), 100);
}

async function doMkdir() {
    const name = document.getElementById('mkdir-name').value.trim();
    if (!name) return;
    const data = await apiPost('mkdir', { path: currentPath, name });
    closeModal('modal-mkdir');
    if (data.ok) { showToast('Folder created', 'ok'); refreshDir(); }
    else showToast(data.error, 'err');
}

function openNewFileModal() {
    document.getElementById('newfile-name').value = '';
    openModal('modal-newfile');
    setTimeout(() => document.getElementById('newfile-name').focus(), 100);
}

async function doNewFile() {
    const name = document.getElementById('newfile-name').value.trim();
    if (!name) return;
    const path = (currentPath.replace(/\/+$/,'') + '/' + name);
    // Create by saving empty content
    const data = await apiPost('save', { path, content: '' });
    // Actually: create via mkdir fallback — we need a create endpoint
    // Use the save route; backend needs the file to exist first.
    // Use mkdir + rename trick: write to existing via save.
    // Simpler: POST to a 'touch' action (mapped to save with empty content on new file)
    // Our save() requires file_exists. Let's use fetch to save empty.
    // Use a different approach: create via terminal or provide createfile action.
    // We'll handle by checking if save failed (file not found) then do touch.
    if (data.error && data.error.includes('not found')) {
        // touch via terminal if available
        const t = await apiPost('terminal', { cmd: 'touch ' + shellescape(path), cwd: termCwd });
        const d2 = await apiPost('save', { path, content: '' });
        if (!d2.ok) { showToast('Could not create file: ' + (d2.error||''), 'err'); return; }
    }
    closeModal('modal-newfile');
    // Open editor right away
    document.getElementById('editor-textarea').value = '';
    document.getElementById('editor-title').textContent = '✏ ' + name;
    document.getElementById('editor-textarea').dataset.path = path;
    document.getElementById('editor-info').textContent = path;
    refreshDir();
    openModal('modal-editor');
}

function shellescape(s) {
    return "'" + s.replace(/'/g, "'\\''") + "'";
}

// ============================================================
//  UPLOAD
// ============================================================
let uploadFiles = [];

function openUploadModal() {
    uploadFiles = [];
    document.getElementById('upload-list').innerHTML = '';
    openModal('modal-upload');
}

function handleFileSelect(files) {
    uploadFiles = Array.from(files);
    const list = document.getElementById('upload-list');
    list.innerHTML = uploadFiles.map(f =>
        `<div class="upload-item"><span>${esc(f.name)}</span><span>${humanSize(f.size)}</span></div>`
    ).join('');
}

function setupDrop() {
    const dz = document.getElementById('drop-zone');
    if (!dz) return;
    dz.addEventListener('dragover', e => { e.preventDefault(); dz.classList.add('drag-over'); });
    dz.addEventListener('dragleave', () => dz.classList.remove('drag-over'));
    dz.addEventListener('drop', e => {
        e.preventDefault();
        dz.classList.remove('drag-over');
        handleFileSelect(e.dataTransfer.files);
    });
}

async function doUpload() {
    if (!uploadFiles.length) { showToast('No files selected','err'); return; }
    const btn = document.getElementById('upload-btn');
    btn.innerHTML = '<span class="spin"></span> Uploading…';
    btn.disabled = true;
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('path', currentPath);
    uploadFiles.forEach(f => fd.append('files[]', f));
    const list = document.getElementById('upload-list');
    try {
        const r = await fetch(SELF + '?ajax=1&action=upload', { method:'POST', body: fd });
        const data = await r.json();
        list.innerHTML = (data.results || []).map(r =>
            `<div class="upload-item">
              <span>${esc(r.name)}</span>
              <span class="${r.ok?'ok':'fail'}">${r.ok?'✓':'✗ '+r.msg}</span>
            </div>`).join('');
        refreshDir();
    } catch(e) {
        showToast('Upload error: '+e.message,'err');
    }
    btn.innerHTML = 'Upload';
    btn.disabled = false;
    uploadFiles = [];
}

// ============================================================
//  RENAME
// ============================================================
function openRenameModal(path, name) {
    renameTarget = path;
    document.getElementById('rename-input').value = name;
    openModal('modal-rename');
    setTimeout(() => {
        const inp = document.getElementById('rename-input');
        inp.focus();
        const dot = name.lastIndexOf('.');
        inp.setSelectionRange(0, dot > 0 ? dot : name.length);
    }, 100);
}

async function doRename() {
    const newname = document.getElementById('rename-input').value.trim();
    if (!newname || !renameTarget) return;
    const data = await apiPost('rename', { path: renameTarget, newname });
    closeModal('modal-rename');
    if (data.ok) { showToast('Renamed ✓','ok'); refreshDir(); }
    else showToast(data.error,'err');
}

// ============================================================
//  DELETE
// ============================================================
function promptDelete(paths) {
    deleteTargets = paths;
    document.getElementById('delete-body').innerHTML =
        `<p style="color:var(--red);margin-bottom:10px;">⚠ This action is <strong>permanent</strong> and cannot be undone.</p>`
      + paths.map(p => `<div>• ${esc(p)}</div>`).join('');
    openModal('modal-delete');
}

function deleteSelected() {
    const paths = getSelectedPaths();
    if (!paths.length) { showToast('Nothing selected','err'); return; }
    promptDelete(paths);
}

async function confirmDelete() {
    closeModal('modal-delete');
    let ok = 0, fail = 0;
    for (const path of deleteTargets) {
        const data = await apiPost('delete', { path });
        if (data.ok) ok++; else fail++;
    }
    showToast(`Deleted ${ok}${fail?' ('+fail+' failed)':''}`, fail?'err':'ok');
    refreshDir();
}

// ============================================================
//  CHMOD
// ============================================================
function openChmodModal(path, perms) {
    chmodTarget = path;
    document.getElementById('chmod-path-display').textContent = path;
    document.getElementById('chmod-input').value = perms || '';
    openModal('modal-chmod');
    setTimeout(() => document.getElementById('chmod-input').focus(), 100);
}

async function doChmod() {
    const perms = document.getElementById('chmod-input').value.trim();
    if (!perms || !chmodTarget) return;
    const data = await apiPost('chmod', { path: chmodTarget, perms });
    closeModal('modal-chmod');
    if (data.ok) { showToast('Permissions changed ✓','ok'); refreshDir(); }
    else showToast(data.error,'err');
}

// ============================================================
//  COMPRESS / EXTRACT
// ============================================================
async function compressItem(path) {
    showStatus('Compressing…');
    const data = await apiPost('compress', { path });
    clearStatus();
    if (data.ok) { showToast('Compressed → ' + data.zip,'ok'); refreshDir(); }
    else showToast(data.error,'err');
}

function compressSelected() {
    const paths = getSelectedPaths();
    if (!paths.length) { showToast('Nothing selected','err'); return; }
    Promise.all(paths.map(p => compressItem(p))).then(() => refreshDir());
}

async function extractItem(path) {
    showStatus('Extracting…');
    const data = await apiPost('extract', { path });
    clearStatus();
    if (data.ok) { showToast('Extracted ✓','ok'); refreshDir(); }
    else showToast(data.error,'err');
}

// ============================================================
//  COPY / MOVE
// ============================================================
function openCopyMoveModal(action, path) {
    copyMoveAction = action;
    copyMoveTarget = path;
    document.getElementById('copymove-title').textContent = action === 'copy' ? '📋 Copy To' : '✂ Move To';
    document.getElementById('copymove-dest').value = currentPath;
    openModal('modal-copymove');
    setTimeout(() => document.getElementById('copymove-dest').focus(), 100);
}

async function doCopyMove() {
    const dest = document.getElementById('copymove-dest').value.trim();
    if (!dest) return;
    const data = await apiPost(copyMoveAction, { src: copyMoveTarget, dest });
    closeModal('modal-copymove');
    if (data.ok) { showToast(copyMoveAction === 'copy' ? 'Copied ✓' : 'Moved ✓','ok'); refreshDir(); }
    else showToast(data.error,'err');
}

// ============================================================
//  SEARCH
// ============================================================
async function doSearch() {
    const q = document.getElementById('search-input').value.trim();
    if (!q) return;
    showStatus('Searching…');
    const data = await api({ action:'search', path:currentPath, q });
    clearStatus();
    if (data.error) { showToast(data.error,'err'); return; }
    const results = data.results || [];
    document.getElementById('search-title').textContent = `🔍 "${q}" — ${results.length} results`;
    if (!results.length) {
        document.getElementById('search-body').innerHTML = '<div class="empty-state" style="height:100px;"><span>No results</span></div>';
    } else {
        document.getElementById('search-body').innerHTML = '<div class="search-results">' +
            results.map(r => `
                <div class="search-result-item" onclick="handleSearchClick('${esc(r.path)}',${r.is_dir?1:0},'${esc(r.name)}')">
                  <span style="font-size:16px;">${r.is_dir?'📁':'📄'}</span>
                  <div>
                    <div>${esc(r.name)}</div>
                    <div class="search-result-path">${esc(r.path)}</div>
                  </div>
                  <span style="margin-left:auto;font-family:var(--mono);font-size:10px;color:var(--text2);">${r.is_dir?'dir':humanSize(r.size)}</span>
                </div>`).join('') + '</div>';
    }
    openModal('modal-search');
}

function handleSearchClick(path, isDir, name) {
    closeModal('modal-search');
    if (isDir) navTo(path);
    else openFile(path, name);
}

// ============================================================
//  PROPERTIES
// ============================================================
function showProperties(item) {
    const rows = [
        ['Name', item.name],
        ['Path', item.path],
        ['Type', item.is_dir ? 'Directory' : 'File'],
        ['Size', item.is_dir ? '—' : humanSize(item.size) + ' (' + item.size + ' bytes)'],
        ['Modified', formatDate(item.mtime)],
        ['Permissions', item.perms],
        ['Writable', item.writable ? '✓ Yes' : '✗ No'],
    ];
    document.getElementById('props-body').innerHTML =
        '<table class="props-table">' +
        rows.map(([k,v]) => `<tr><td>${k}</td><td style="font-family:var(--mono);font-size:12px;">${esc(String(v))}</td></tr>`).join('') +
        '</table>';
    openModal('modal-props');
}

// ============================================================
//  CONTEXT MENU
// ============================================================
function showCtxMenu(e, path, name, isDir) {
    e.preventDefault();
    e.stopPropagation();
    ctxTarget = { path, name, isDir: !!isDir };
    const item = currentItems.find(i => i.path === path);
    const ext = name.split('.').pop().toLowerCase();
    const isZip = ext === 'zip';
    const isImg = ['jpg','jpeg','png','gif','webp'].includes(ext);

    const menu = document.getElementById('ctx-menu');
    menu.innerHTML = [
        isDir
            ? `<div class="ctx-item" onclick="navTo('${esc(path)}')">📂 Open</div>`
            : `<div class="ctx-item" onclick="openFile('${esc(path)}','${esc(name)}')">✏ Open / Edit</div>`,
        !isDir ? `<div class="ctx-item" onclick="downloadFile('${esc(path)}')">⬇ Download</div>` : '',
        isImg  ? `<div class="ctx-item" onclick="openImagePreview('${esc(path)}','${esc(name)}')">🖼 Preview</div>` : '',
        '<div class="ctx-sep"></div>',
        `<div class="ctx-item" onclick="openRenameModal('${esc(path)}','${esc(name)}')">✏ Rename</div>`,
        `<div class="ctx-item" onclick="openCopyMoveModal('copy','${esc(path)}')">📋 Copy to…</div>`,
        `<div class="ctx-item" onclick="openCopyMoveModal('move','${esc(path)}')">✂ Move to…</div>`,
        '<div class="ctx-sep"></div>',
        `<div class="ctx-item" onclick="compressItem('${esc(path)}')">🗜 Compress to ZIP</div>`,
        isZip ? `<div class="ctx-item" onclick="extractItem('${esc(path)}')">📦 Extract ZIP</div>` : '',
        '<div class="ctx-sep"></div>',
        item ? `<div class="ctx-item" onclick="openChmodModal('${esc(path)}','${item.perms}')">🔒 Permissions</div>` : '',
        item ? `<div class="ctx-item" onclick="showProperties(${JSON.stringify(JSON.stringify(item))})">ℹ Properties</div>` : '',
        '<div class="ctx-sep"></div>',
        `<div class="ctx-item danger" onclick="promptDelete(['${esc(path)}'])">🗑 Delete</div>`,
    ].filter(Boolean).join('');

    // Fix properties onclick (JSON nested)
    if (item) {
        const propBtn = menu.querySelector('[data-prop]');
        // Re-bind via index
    }

    menu.classList.remove('hidden');
    let x = e.clientX, y = e.clientY;
    if (x + 200 > window.innerWidth)  x = window.innerWidth - 210;
    if (y + menu.offsetHeight > window.innerHeight) y = window.innerHeight - menu.offsetHeight - 10;
    menu.style.left = x + 'px';
    menu.style.top  = y + 'px';

    // Re-bind properties properly
    if (item) {
        const propEl = [...menu.querySelectorAll('.ctx-item')].find(el => el.textContent.includes('Properties'));
        if (propEl) propEl.onclick = () => { hideCtxMenu(); showProperties(item); };
    }
    // Bind compress
    const compEl = [...menu.querySelectorAll('.ctx-item')].find(el => el.textContent.includes('Compress'));
    if (compEl) compEl.onclick = () => { hideCtxMenu(); compressItem(path); };
    if (isZip) {
        const extEl = [...menu.querySelectorAll('.ctx-item')].find(el => el.textContent.includes('Extract'));
        if (extEl) extEl.onclick = () => { hideCtxMenu(); extractItem(path); };
    }
}

function hideCtxMenu() {
    document.getElementById('ctx-menu').classList.add('hidden');
}

// ============================================================
//  SORT
// ============================================================
function setSortColumn(col) {
    if (sortColumn === col) sortAsc = !sortAsc;
    else { sortColumn = col; sortAsc = true; }
    renderFiles();
}

function cycleSortMode() {
    const modes = ['name','size','mtime'];
    const idx = modes.indexOf(sortColumn);
    sortColumn = modes[(idx + 1) % modes.length];
    renderFiles();
    showToast('Sorted by ' + sortColumn, 'ok');
}

// ============================================================
//  VIEW TOGGLE
// ============================================================
function toggleView() {
    viewMode = viewMode === 'list' ? 'grid' : 'list';
    renderFiles();
}

// ============================================================
//  DISK INFO
// ============================================================
async function loadDiskInfo() {
    const data = await api({ action:'diskinfo' });
    if (data.error) return;
    document.getElementById('disk-used-pct').textContent = data.pct + '%';
    document.getElementById('disk-used-txt').textContent  = data.used_h;
    document.getElementById('disk-total-txt').textContent = data.total_h;
    const fill = document.getElementById('disk-fill');
    fill.style.width = data.pct + '%';
    if (data.pct > 85) fill.classList.add('warn'); else fill.classList.remove('warn');
    document.getElementById('status-php').textContent = 'PHP ' + data.php + ' / ' + data.os;
}

// ============================================================
//  TERMINAL
// ============================================================
function toggleTerminal() {
    const panel = document.getElementById('terminal-panel');
    panel.classList.toggle('hidden');
    if (!panel.classList.contains('hidden')) {
        document.getElementById('term-input').focus();
        updateTermPrompt();
    }
}

function closeTerminal() {
    document.getElementById('terminal-panel').classList.add('hidden');
}

function clearTerm() {
    document.getElementById('terminal-output').innerHTML = '';
}

function updateTermPrompt() {
    const root = '<?= addslashes(realpath(FM_ROOT)) ?>';
    let display = termCwd.replace(root, '~');
    document.getElementById('term-prompt').textContent = display + ' $ ';
    document.getElementById('term-cwd-display').textContent = termCwd;
}

async function handleTermKey(e) {
    if (e.key === 'Enter') {
        const input = document.getElementById('term-input');
        const cmd   = input.value;
        if (!cmd.trim()) return;
        termHistory.unshift(cmd);
        termHistIdx = -1;
        input.value = '';
        appendTermLine(cmd, 'cmd');
        input.disabled = true;
        const data = await apiPost('terminal', { cmd, cwd: termCwd });
        input.disabled = false;
        input.focus();
        if (data.error) { appendTermLine(data.error, 'err'); return; }
        if (data.cwd) { termCwd = data.cwd; updateTermPrompt(); }
        if (data.output) appendTermLine(data.output, 'out');
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        termHistIdx = Math.min(termHistIdx + 1, termHistory.length - 1);
        document.getElementById('term-input').value = termHistory[termHistIdx] || '';
    } else if (e.key === 'ArrowDown') {
        e.preventDefault();
        termHistIdx = Math.max(termHistIdx - 1, -1);
        document.getElementById('term-input').value = termHistIdx >= 0 ? termHistory[termHistIdx] : '';
    } else if (e.key === 'l' && e.ctrlKey) {
        e.preventDefault(); clearTerm();
    }
}

function appendTermLine(text, type='out') {
    const out = document.getElementById('terminal-output');
    const div = document.createElement('div');
    div.className = 'term-line-' + type;
    if (type === 'cmd') div.textContent = '$ ' + text;
    else div.textContent = text;
    out.appendChild(div);
    out.scrollTop = out.scrollHeight;
}

// ============================================================
//  MODAL HELPERS
// ============================================================
function openModal(id) {
    document.getElementById(id).classList.remove('hidden');
}
function closeModal(id) {
    document.getElementById(id).classList.add('hidden');
}
function closeAllModals() {
    document.querySelectorAll('.overlay').forEach(o => o.classList.add('hidden'));
}

// Close on overlay click
document.addEventListener('click', e => {
    if (e.target.classList.contains('overlay')) closeAllModals();
});

// ============================================================
//  EDITOR HELPERS
// ============================================================
function handleEditorKey(e) {
    if (e.key === 'Tab') {
        e.preventDefault();
        const ta = e.target;
        const s = ta.selectionStart, end = ta.selectionEnd;
        ta.value = ta.value.substring(0, s) + '    ' + ta.value.substring(end);
        ta.selectionStart = ta.selectionEnd = s + 4;
    }
}

// ============================================================
//  STATUS / TOAST
// ============================================================
function showStatus(msg, isErr=false) {
    const el = document.getElementById('status-msg');
    el.textContent = msg;
    el.className = isErr ? 'error' : '';
}
function clearStatus() { document.getElementById('status-msg').textContent = ''; }

let toastTimer;
function showToast(msg, type='ok') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'show ' + type;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { t.classList.remove('show'); }, 3000);
}

// ============================================================
//  UTILITIES
// ============================================================
function esc(s) {
    return String(s)
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;')
        .replace(/'/g,'&#039;');
}

function humanSize(b) {
    if (b === undefined || b === null) return '-';
    const units = ['B','KB','MB','GB','TB'];
    let i = 0;
    while (b >= 1024 && i < 4) { b /= 1024; i++; }
    return Math.round(b * 10) / 10 + ' ' + units[i];
}
</script>
<?php endif; ?>
</body>
</html>
