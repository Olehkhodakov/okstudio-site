<?php
/**
 * OK Studio — auto-deploy from GitHub to CityHost.
 * Run by CRON every few minutes. If the main branch has a new commit,
 * downloads it and updates the site files. WordPress files are never touched.
 *
 * Setup: paste your GitHub token below (Fine-grained, Contents: Read-only,
 * only the okstudio-site repository). Keep this file outside the website folder.
 */

$TOKEN  = 'PASTE_TOKEN_HERE';
$REPO   = 'Olehkhodakov/okstudio-site';
$BRANCH = 'main';
$TARGET = '/var/www/ch0719db6e/www/okstudio.com.ua';

// Files in the repository that never go to the website
$SKIP = ['README.md', '.gitignore', '.github/', 'deploy/'];

// ---------------------------------------------------------------------------
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$DIR      = __DIR__;
$STATE    = $DIR . '/.deploy-sha';
$MANIFEST = $DIR . '/.deploy-manifest.json';
$LOG      = $DIR . '/deploy.log';
$LOCK     = $DIR . '/.deploy.lock';
$FORCE    = in_array('--force', $argv ?? [], true);

function logmsg($m) { global $LOG; file_put_contents($LOG, date('Y-m-d H:i:s') . "  $m\n", FILE_APPEND); echo "$m\n"; }

function gh($url, $accept, $toFile = null) {
    global $TOKEN;
    $ch = curl_init($url);
    $h = ['User-Agent: okstudio-deploy', 'Accept: ' . $accept, 'X-GitHub-Api-Version: 2022-11-28'];
    if ($TOKEN && $TOKEN !== 'PASTE_TOKEN_HERE') $h[] = 'Authorization: Bearer ' . $TOKEN;
    curl_setopt_array($ch, [CURLOPT_HTTPHEADER => $h, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 300, CURLOPT_CONNECTTIMEOUT => 20]);
    if ($toFile) { $fp = fopen($toFile, 'w'); curl_setopt($ch, CURLOPT_FILE, $fp); }
    else curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $out = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if (isset($fp)) fclose($fp);
    if ($code !== 200) throw new Exception("GitHub $code $err for $url");
    return $out;
}

function skipped($path) {
    global $SKIP;
    foreach ($SKIP as $s) {
        if (substr($s, -1) === '/' ? strpos($path, $s) === 0 : $path === $s) return true;
    }
    return false;
}

if (!is_file($DIR . '/.htaccess')) file_put_contents($DIR . '/.htaccess', "Require all denied\n");
$lock = fopen($LOCK, 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) exit;

try {
    $sha = trim(gh("https://api.github.com/repos/$REPO/commits/$BRANCH", 'application/vnd.github.sha'));
    if (!preg_match('/^[0-9a-f]{40}$/', $sha)) throw new Exception('Unexpected answer from GitHub');
    $old = is_file($STATE) ? trim(file_get_contents($STATE)) : '';
    if ($sha === $old && !$FORCE) exit;   // nothing new

    $zipFile = $DIR . '/.deploy.zip';
    gh("https://api.github.com/repos/$REPO/zipball/$sha", 'application/vnd.github+json', $zipFile);

    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) throw new Exception('Cannot open downloaded zip');

    $written = 0; $same = 0; $files = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        $rel = preg_replace('#^[^/]+/#', '', $name);      // strip "owner-repo-sha/"
        if ($rel === '' || substr($rel, -1) === '/') continue;
        if (strpos($rel, '..') !== false || $rel[0] === '/') continue;
        if (skipped($rel)) continue;
        $files[] = $rel;
        $dest = $TARGET . '/' . $rel;
        $data = $zip->getFromIndex($i);
        if (is_file($dest) && md5_file($dest) === md5($data)) { $same++; continue; }
        if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0755, true);
        $tmp = $dest . '.deploytmp';
        file_put_contents($tmp, $data);
        rename($tmp, $dest);
        $written++;
    }
    $zip->close();
    unlink($zipFile);

    // remove files that we deployed earlier and that are gone from the repository
    $removed = 0;
    $prev = is_file($MANIFEST) ? (json_decode(file_get_contents($MANIFEST), true) ?: []) : [];
    foreach (array_diff($prev, $files) as $gone) {
        $p = $TARGET . '/' . $gone;
        if (is_file($p)) { unlink($p); $removed++; }
    }
    file_put_contents($MANIFEST, json_encode(array_values($files)));

    // make the new site the home page, WordPress keeps working behind it
    $ht = $TARGET . '/.htaccess';
    $line = 'DirectoryIndex index.html index.php';
    $cur = is_file($ht) ? file_get_contents($ht) : '';
    if (strpos($cur, $line) === false) file_put_contents($ht, $line . "\n" . $cur);

    file_put_contents($STATE, $sha);
    logmsg("deployed " . substr($sha, 0, 7) . ": $written updated, $same unchanged, $removed removed");
} catch (Exception $e) {
    logmsg('ERROR ' . $e->getMessage());
}
