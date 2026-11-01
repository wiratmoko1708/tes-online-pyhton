<?php
declare(strict_types=1);

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $location): never
{
    header('Location: ' . $location);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function verify_csrf(): void
{
    if (!hash_equals((string) ($_SESSION['_csrf'] ?? ''), (string) ($_POST['_csrf'] ?? ''))) {
        http_response_code(419);
        render_error('Sesi kedaluwarsa', 'Silakan kembali dan coba lagi.');
        exit;
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

function consume_flashes(): array
{
    $flashes = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $flashes;
}

function render_error(string $title, string $message): void
{
    $appName = e(env_value('APP_NAME', 'PyTest SMA'));
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $title . '</title><link rel="stylesheet" href="' . e(asset_url('assets/app.css')) . '"></head><body><main class="error-page"><div class="brand-mark">⌁</div><h1>' . e($title) . '</h1><p>' . e($message) . '</p><a class="button" href="?page=dashboard">Kembali</a></main></body></html>';
}

function asset_url(string $path): string
{
    $path = ltrim($path, '/');
    $file = dirname(__DIR__) . '/public/' . $path;
    $version = is_file($file) ? (string) filemtime($file) : '1';
    return $path . '?v=' . $version;
}

function layout(string $title, callable $content, bool $appShell = true): void
{
    $user = Auth::user();
    $flashes = consume_flashes();
    $appName = env_value('APP_NAME', 'PyTest SMA');
    $activePage = $_GET['page'] ?? 'dashboard';
    require dirname(__DIR__) . '/views/partials/header.php';
    $content();
    require dirname(__DIR__) . '/views/partials/footer.php';
}

function role_label(string $role): string
{
    return ['siswa' => 'Siswa', 'guru' => 'Guru', 'super_admin' => 'Super Admin'][$role] ?? $role;
}

function format_date(?string $value): string
{
    return $value ? date('d M Y, H:i', strtotime($value)) : '-';
}

function normalize_output(string $output): string
{
    $output = str_replace(["\r\n", "\r"], "\n", $output);
    $lines = array_map(static fn(string $line): string => rtrim($line), explode("\n", trim($output)));
    return trim(implode("\n", $lines));
}

function run_python_code(string $code, string $input, string $pythonBin, int $timeout = 5): array
{
    if (strlen($code) > 100000) {
        return ['ok' => false, 'output' => '', 'error' => 'Kode terlalu panjang (maksimal 100 KB).', 'time_ms' => 0];
    }
    $blocked = '/\b(?:os|sys|subprocess|socket|shutil|pathlib|ctypes|multiprocessing|resource)\b|(?:__import__|open|eval|exec|compile)\s*\(/i';
    if (preg_match($blocked, $code)) {
        return ['ok' => false, 'output' => '', 'error' => 'Kode mengandung modul atau fungsi yang tidak diizinkan untuk ujian.', 'time_ms' => 0];
    }
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pytest_' . bin2hex(random_bytes(8));
    if (!mkdir($dir, 0700, true)) {
        return ['ok' => false, 'output' => '', 'error' => 'Tidak dapat membuat ruang eksekusi sementara.', 'time_ms' => 0];
    }
    $file = $dir . DIRECTORY_SEPARATOR . 'main.py';
    file_put_contents($file, $code);
    $command = escapeshellarg($pythonBin) . ' -I -S ' . escapeshellarg($file);
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $started = microtime(true);
    $process = @proc_open($command, $descriptors, $pipes, $dir);
    if (!is_resource($process)) {
        @unlink($file); @rmdir($dir);
        return ['ok' => false, 'output' => '', 'error' => 'Python tidak tersedia di server. Atur PYTHON_BIN di .env.', 'time_ms' => 0];
    }
    fwrite($pipes[0], substr($input, 0, 10000));
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $output = '';
    $error = '';
    $timedOut = false;
    while (true) {
        $output .= stream_get_contents($pipes[1]);
        $error .= stream_get_contents($pipes[2]);
        $status = proc_get_status($process);
        if (!$status['running']) break;
        if (microtime(true) - $started > $timeout) {
            proc_terminate($process, 9);
            $timedOut = true;
            break;
        }
        usleep(20000);
    }
    $output .= stream_get_contents($pipes[1]);
    $error .= stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exitCode = proc_close($process);
    @unlink($file); @rmdir($dir);
    return [
        'ok' => !$timedOut && $exitCode === 0,
        'output' => substr($output, 0, 50000),
        'error' => $timedOut ? 'Waktu eksekusi melebihi batas ' . $timeout . ' detik.' : trim(substr($error, 0, 5000)),
        'time_ms' => (int) round((microtime(true) - $started) * 1000),
    ];
}

function ai_review(array $config, string $question, string $code, array $results): ?string
{
    if (!$config['ai']['api_key']) return null;
    $payload = [
        'model' => $config['ai']['model'],
        'temperature' => 0.2,
        'messages' => [
            ['role' => 'system', 'content' => 'Anda adalah pemeriksa kode Python untuk ujian SMA. Beri umpan balik singkat dalam Bahasa Indonesia, fokus pada logika dan cara memperbaiki kesalahan. Jangan memberikan nilai berbeda dari hasil test case.'],
            ['role' => 'user', 'content' => "Soal:\n{$question}\n\nKode siswa:\n{$code}\n\nHasil test case:\n" . json_encode($results, JSON_UNESCAPED_UNICODE)],
        ],
    ];
    $ch = curl_init($config['ai']['base_url'] . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $config['ai']['api_key']],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!$response || $status >= 400) return null;
    $json = json_decode($response, true);
    return $json['choices'][0]['message']['content'] ?? null;
}

/**
 * Ujian dengan restrict_participants = 1 hanya boleh dikerjakan siswa yang
 * tercatat di tabel exam_participants.
 */
function exam_restricts_participants(array $exam): bool
{
    return (int) ($exam['restrict_participants'] ?? 0) === 1;
}

/** Daftar id siswa yang menjadi peserta ujian. */
function exam_participant_ids(PDO $db, int $examId): array
{
    $stmt = $db->prepare('SELECT user_id FROM exam_participants WHERE exam_id=?');
    $stmt->execute([$examId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** Apakah siswa boleh mengerjakan ujian ini (ujian dibatasi atau tidak). */
function student_can_take_exam(PDO $db, array $exam, int $userId): bool
{
    if (!exam_restricts_participants($exam)) {
        return true;
    }
    $stmt = $db->prepare('SELECT 1 FROM exam_participants WHERE exam_id=? AND user_id=? LIMIT 1');
    $stmt->execute([(int) $exam['id'], $userId]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Tambahkan peserta ujian; baris yang sudah ada dilewati sehingga aman
 * dipanggil berulang (per kelas, per siswa, atau hasil import).
 *
 * @param  int[]  $userIds
 * @return int jumlah peserta baru yang benar-benar ditambahkan
 */
function add_exam_participants(PDO $db, int $examId, array $userIds, string $source = 'manual'): int
{
    $source = in_array($source, ['manual', 'class', 'import'], true) ? $source : 'manual';
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn(int $id): bool => $id > 0)));
    if ($userIds === []) {
        return 0;
    }
    $stmt = $db->prepare('INSERT IGNORE INTO exam_participants(exam_id,user_id,source) VALUES(?,?,?)');
    $added = 0;
    foreach ($userIds as $userId) {
        $stmt->execute([$examId, $userId, $source]);
        $added += $stmt->rowCount();
    }
    return $added;
}

/** Peserta ujian lengkap dengan data siswa, diurutkan per kelas lalu nama. */
function exam_participant_rows(PDO $db, int $examId): array
{
    $stmt = $db->prepare("SELECT u.id,u.name,u.username,u.class_name,u.is_active,ep.source,ep.created_at FROM exam_participants ep JOIN users u ON u.id=ep.user_id WHERE ep.exam_id=? ORDER BY u.class_name IS NULL,u.class_name,u.name");
    $stmt->execute([$examId]);
    return $stmt->fetchAll();
}

/** Batas waktu pengerjaan attempt (timestamp Unix) berdasarkan durasi ujian. */
function attempt_deadline(array $attempt, int $durationMinutes): int
{
    $startedAt = (string) ($attempt['started_at'] ?? '');
    $start = $startedAt !== '' && strtotime($startedAt) !== false ? strtotime($startedAt) : time();
    return $start + max(1, $durationMinutes) * 60;
}

/**
 * Attempt masih berstatus in_progress tetapi waktu pengerjaannya sudah habis.
 * Dipakai untuk menandai siswa yang kehabisan waktu sebelum submit.
 */
function attempt_is_expired(array $attempt, int $durationMinutes): bool
{
    return ($attempt['status'] ?? '') === 'in_progress' && time() >= attempt_deadline($attempt, $durationMinutes);
}

/** Label sisa waktu sebuah attempt untuk tampilan pengajar. */
function attempt_time_label(array $attempt, int $durationMinutes): string
{
    if (($attempt['status'] ?? '') === 'submitted') {
        $submittedAt = (string) ($attempt['submitted_at'] ?? '');
        if ($submittedAt === '' || strtotime($submittedAt) === false) {
            return 'Selesai';
        }
        return strtotime($submittedAt) > attempt_deadline($attempt, $durationMinutes) ? 'Selesai (waktu habis)' : 'Selesai';
    }
    $left = attempt_deadline($attempt, $durationMinutes) - time();
    if ($left <= 0) {
        return 'Waktu habis';
    }
    return 'Sisa ' . (int) floor($left / 60) . ' menit';
}

/**
 * Reset satu pengerjaan agar siswa dapat mengulang ujian dari awal.
 *
 * Baris attempt dihapus (jawaban ikut terhapus lewat ON DELETE CASCADE) sehingga
 * siswa melihat tombol "Mulai ujian" lagi dan durasi baru mulai dihitung saat
 * ujian dibuka — bukan saat tombol reset ditekan. Dengan begitu waktu tidak
 * habis lebih dulu bila siswa baru kembali ke komputer beberapa saat kemudian.
 */
function reset_attempt(PDO $db, int $attemptId): bool
{
    $check = $db->prepare('SELECT id FROM attempts WHERE id=?');
    $check->execute([$attemptId]);
    if (!$check->fetchColumn()) {
        return false;
    }
    $db->beginTransaction();
    try {
        $db->prepare('DELETE FROM answers WHERE attempt_id=?')->execute([$attemptId]);
        $db->prepare('DELETE FROM attempts WHERE id=?')->execute([$attemptId]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
    return true;
}

/**
 * Reset seluruh attempt pada satu ujian.
 *
 * @return int jumlah attempt yang berhasil direset
 */
function reset_exam_attempts(PDO $db, int $examId): int
{
    $stmt = $db->prepare('SELECT id FROM attempts WHERE exam_id=?');
    $stmt->execute([$examId]);
    $reset = 0;
    foreach (array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)) as $attemptId) {
        if (reset_attempt($db, $attemptId)) {
            $reset++;
        }
    }
    return $reset;
}

/** Attempt pada sebuah ujian beserta data siswa, untuk panel reset pengajar. */
function exam_attempt_rows(PDO $db, int $examId): array
{
    $stmt = $db->prepare('SELECT a.id attempt_id,a.status,a.score,a.started_at,a.submitted_at,u.id user_id,u.name,u.username,u.class_name FROM attempts a JOIN users u ON u.id=a.user_id WHERE a.exam_id=? ORDER BY u.class_name IS NULL,u.class_name,u.name');
    $stmt->execute([$examId]);
    return $stmt->fetchAll();
}

/** Daftar kelas beserta jumlah siswa aktif, untuk pilihan tambah peserta per kelas. */
function student_class_summary(PDO $db): array
{
    return $db->query("SELECT COALESCE(NULLIF(TRIM(class_name),''),'(tanpa kelas)') class_label, COUNT(*) total FROM users WHERE role='siswa' AND is_active=1 GROUP BY class_label ORDER BY class_label")->fetchAll();
}

function parse_import_file(string $path, string $extension): array
{
    if ($extension === 'csv') {
        $handle = fopen($path, 'rb');
        $rows = [];
        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) $rows[] = $row;
        fclose($handle);
        // Excel menyimpan CSV sebagai UTF-8 BOM; buang agar header "nama" tetap terbaca.
        if (isset($rows[0][0]) && is_string($rows[0][0])) {
            $rows[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', $rows[0][0]) ?? $rows[0][0];
        }
        return $rows;
    }
    if ($extension !== 'xlsx' || !class_exists('ZipArchive')) throw new RuntimeException('Format harus CSV atau XLSX. Extension ZipArchive belum aktif.');
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('File XLSX tidak dapat dibaca.');
    $shared = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml) {
        $xml = simplexml_load_string($sharedXml);
        foreach ($xml->si as $si) {
            $text = isset($si->t) ? (string) $si->t : '';
            if ($text === '') foreach ($si->r as $run) $text .= (string) ($run->t ?? '');
            $shared[] = $text;
        }
    }
    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    if (!$sheet) throw new RuntimeException('Sheet pertama tidak ditemukan.');
    $xml = simplexml_load_string($sheet);
    $rows = [];
    foreach ($xml->sheetData->row as $row) {
        $values = [];
        foreach ($row->c as $cell) {
            $ref = (string) $cell['r'];
            preg_match('/[A-Z]+/', $ref, $match);
            $col = 0;
            foreach (str_split($match[0] ?? 'A') as $letter) $col = $col * 26 + ord($letter) - 64;
            $type = (string) $cell['t'];
            $value = (string) ($cell->v ?? '');
            if ($type === 's') {
                $value = $shared[(int) $value] ?? '';
            } elseif ($type === 'inlineStr') {
                $value = isset($cell->is->t) ? (string) $cell->is->t : '';
                if ($value === '') foreach ($cell->is->r as $run) $value .= (string) ($run->t ?? '');
            }
            $values[$col - 1] = $value;
        }
        // Sel kosong dilewati oleh Excel: isi dengan string kosong agar kolom tidak bergeser.
        $row = $values === [] ? [] : array_fill(0, max(array_keys($values)) + 1, '');
        foreach ($values as $index => $value) $row[$index] = $value;
        $rows[] = $row;
    }
    $zip->close();
    return $rows;
}
