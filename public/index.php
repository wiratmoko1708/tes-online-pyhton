<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

$page = $_GET['page'] ?? (Auth::user() ? 'dashboard' : 'login');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Instalasi pertama: arahkan ke wizard pembuatan super admin.
if ($method === 'GET' && $page !== 'setup' && (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
    redirect('?page=setup');
}

// Endpoint ringan untuk mencoba kode tanpa menyimpan jawaban.
if ($method === 'POST' && ($_POST['action'] ?? '') === 'run_code') {
    Auth::requireRole(['siswa']);
    header('Content-Type: application/json; charset=utf-8');
    $question = $db->prepare('SELECT q.question_text, q.exam_id, e.status exam_status, e.restrict_participants, tc.input_data, tc.expected_output FROM questions q JOIN exams e ON e.id=q.exam_id JOIN test_cases tc ON tc.question_id=q.id WHERE q.id=? AND tc.is_sample=1 ORDER BY tc.sort_order LIMIT 1');
    $question->execute([(int) ($_POST['question_id'] ?? 0)]);
    $sample = $question->fetch();
    // Soal dari ujian yang dibatasi hanya boleh diuji oleh peserta ujian tersebut.
    if (!$sample || $sample['exam_status'] !== 'published' || !student_can_take_exam($db, ['id' => (int) $sample['exam_id'], 'restrict_participants' => (int) $sample['restrict_participants']], (int) Auth::user()['id'])) {
        echo json_encode(['ok' => false, 'output' => '', 'error' => 'Soal ini bukan bagian dari ujian yang dapat kamu akses.', 'stdin' => '', 'auto_input' => false, 'time_ms' => 0], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $stdin = (string) ($_POST['stdin'] ?? '');
    $autoInput = false;
    // Kolom input dibiarkan kosong: pakai contoh input publik supaya siswa tetap bisa mencoba.
    if (trim($stdin) === '' && $sample) {
        $stdin = (string) $sample['input_data'];
        $autoInput = true;
    }
    if (trim($stdin) === '') {
        echo json_encode(['ok' => false, 'output' => '', 'error' => 'Contoh test case belum tersedia. Isi input program terlebih dahulu.', 'stdin' => '', 'auto_input' => false, 'time_ms' => 0], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $result = run_python_code((string) ($_POST['code'] ?? ''), $stdin, $config['python_bin']);
    $result['stdin'] = $stdin;
    $result['auto_input'] = $autoInput;
    $result['expected'] = $autoInput ? (string) $sample['expected_output'] : '';
    $result['passed'] = $autoInput && $result['ok'] && normalize_output($result['output']) === normalize_output((string) $sample['expected_output']);
    echo json_encode($result, JSON_UNESCAPED_UNICODE); exit;
}

if ($method === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    try {
        switch ($action) {
            case 'setup':
                if ((int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) redirect('?page=login');
                $name = trim($_POST['name'] ?? ''); $username = trim($_POST['username'] ?? ''); $password = (string) ($_POST['password'] ?? '');
                if ($name === '' || $username === '' || strlen($password) < 8) throw new RuntimeException('Nama, username, dan password minimal 8 karakter wajib diisi.');
                $stmt = $db->prepare("INSERT INTO users(name,username,password,role) VALUES(?,?,?,'super_admin')");
                $stmt->execute([$name, $username, password_hash($password, PASSWORD_DEFAULT)]);
                flash('success', 'Super admin berhasil dibuat. Silakan login.'); redirect('?page=login');
            case 'login':
                $stmt = $db->prepare('SELECT * FROM users WHERE username=? AND is_active=1 LIMIT 1'); $stmt->execute([trim($_POST['username'] ?? '')]); $user = $stmt->fetch();
                if (!$user || !password_verify((string) ($_POST['password'] ?? ''), $user['password'])) throw new RuntimeException('Username atau password salah.');
                Auth::login($user); redirect('?page=dashboard');
            case 'create_exam':
                Auth::requireRole(['guru', 'super_admin']);
                $stmt = $db->prepare('INSERT INTO exams(title,description,duration_minutes,status,created_by) VALUES(?,?,?,?,?)');
                $stmt->execute([trim($_POST['title'] ?? ''), trim($_POST['description'] ?? ''), max(1, (int) ($_POST['duration_minutes'] ?? 60)), $_POST['status'] ?? 'draft', Auth::user()['id']]);
                flash('success', 'Ujian berhasil dibuat. Tambahkan soal dan test case.'); redirect('?page=question&exam_id=' . $db->lastInsertId());
            case 'update_exam':
                Auth::requireRole(['guru', 'super_admin']);
                $stmt = $db->prepare('UPDATE exams SET title=?,description=?,duration_minutes=?,status=? WHERE id=?');
                $stmt->execute([trim($_POST['title'] ?? ''), trim($_POST['description'] ?? ''), max(1, (int) ($_POST['duration_minutes'] ?? 60)), $_POST['status'] ?? 'draft', (int) $_POST['exam_id']]);
                flash('success', 'Ujian diperbarui.'); redirect('?page=exam_manage&id=' . (int) $_POST['exam_id']);
            case 'delete_exam':
                Auth::requireRole(['guru', 'super_admin']); $stmt = $db->prepare('DELETE FROM exams WHERE id=?'); $stmt->execute([(int) $_POST['exam_id']]); flash('success', 'Ujian dihapus.'); redirect('?page=exam_manage');
            case 'create_question':
                Auth::requireRole(['guru', 'super_admin']);
                $examId = (int) $_POST['exam_id'];
                $difficulty = in_array($_POST['difficulty'] ?? '', ['entry', 'middle', 'advanced'], true) ? $_POST['difficulty'] : 'middle';
                $isScored = !empty($_POST['is_scored']) ? 1 : 0;
                $stmt = $db->prepare('INSERT INTO questions(exam_id,question_text,starter_code,points,difficulty,is_scored,sort_order) VALUES(?,?,?,?,?,?,?)');
                $stmt->execute([$examId, trim($_POST['question_text'] ?? ''), (string) ($_POST['starter_code'] ?? ''), $isScored ? max(1, (float) ($_POST['points'] ?? 100)) : 0, $difficulty, $isScored, (int) $db->query('SELECT COALESCE(MAX(sort_order),0)+1 FROM questions WHERE exam_id=' . $examId)->fetchColumn()]);
                $questionId = (int) $db->lastInsertId();
                $inputs = $_POST['case_input'] ?? []; $expected = $_POST['case_expected'] ?? [];
                $case = $db->prepare('INSERT INTO test_cases(question_id,input_data,expected_output,is_sample,sort_order) VALUES(?,?,?,?,?)');
                foreach ($expected as $i => $value) if (trim((string) $value) !== '') $case->execute([$questionId, (string) ($inputs[$i] ?? ''), trim((string) $value), $i === 0 ? 1 : 0, $i]);
                flash('success', 'Soal dan test case berhasil ditambahkan.'); redirect('?page=question&exam_id=' . $examId);
            case 'update_question':
                Auth::requireRole(['guru', 'super_admin']);
                $questionId = (int) ($_POST['question_id'] ?? 0); $examId = (int) ($_POST['exam_id'] ?? 0);
                $check = $db->prepare('SELECT id FROM questions WHERE id=? AND exam_id=?'); $check->execute([$questionId, $examId]);
                if (!$check->fetchColumn()) throw new RuntimeException('Soal tidak ditemukan pada ujian ini.');
                $difficulty = in_array($_POST['difficulty'] ?? '', ['entry', 'middle', 'advanced'], true) ? $_POST['difficulty'] : 'middle';
                $isScored = !empty($_POST['is_scored']) ? 1 : 0;
                $stmt = $db->prepare('UPDATE questions SET question_text=?,starter_code=?,points=?,difficulty=?,is_scored=? WHERE id=? AND exam_id=?');
                $stmt->execute([trim($_POST['question_text'] ?? ''), (string) ($_POST['starter_code'] ?? ''), $isScored ? max(1, (float) ($_POST['points'] ?? 100)) : 0, $difficulty, $isScored, $questionId, $examId]);
                $db->prepare('DELETE FROM test_cases WHERE question_id=?')->execute([$questionId]);
                $inputs = $_POST['case_input'] ?? []; $expected = $_POST['case_expected'] ?? [];
                $case = $db->prepare('INSERT INTO test_cases(question_id,input_data,expected_output,is_sample,sort_order) VALUES(?,?,?,?,?)');
                foreach ($expected as $i => $value) if (trim((string) $value) !== '') $case->execute([$questionId, (string) ($inputs[$i] ?? ''), trim((string) $value), $i === 0 ? 1 : 0, $i]);
                flash('success', 'Soal dan test case berhasil diperbarui.'); redirect('?page=exam_manage&id=' . $examId);
            case 'update_question_selection':
                Auth::requireRole(['guru', 'super_admin']);
                $examId = (int) ($_POST['exam_id'] ?? 0);
                if (!empty($_POST['delete_question_id'])) {
                    $stmt = $db->prepare('DELETE FROM questions WHERE id=? AND exam_id=?');
                    $stmt->execute([(int) $_POST['delete_question_id'], $examId]);
                    flash('success', 'Soal dihapus.'); redirect('?page=exam_manage&id=' . $examId);
                }
                $selected = array_values(array_unique(array_map('intval', (array) ($_POST['visible_questions'] ?? []))));
                if (!$selected && (int) $db->query('SELECT COUNT(*) FROM questions WHERE exam_id=' . $examId)->fetchColumn() > 0) {
                    throw new RuntimeException('Pilih minimal satu soal untuk ditampilkan pada ujian.');
                }
                try {
                    $db->beginTransaction();
                    $db->prepare('UPDATE questions SET is_enabled=0 WHERE exam_id=?')->execute([$examId]);
                    if ($selected) {
                        $placeholders = implode(',', array_fill(0, count($selected), '?'));
                        $stmt = $db->prepare("UPDATE questions SET is_enabled=1 WHERE exam_id=? AND id IN ($placeholders)");
                        $stmt->execute(array_merge([$examId], $selected));
                    }
                    $db->commit();
                } catch (Throwable $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    throw $e;
                }
                flash('success', count($selected) . ' soal dipilih untuk ditampilkan pada ujian.'); redirect('?page=exam_manage&id=' . $examId);
            case 'delete_question':
                Auth::requireRole(['guru', 'super_admin']); $stmt = $db->prepare('DELETE FROM questions WHERE id=?'); $stmt->execute([(int) $_POST['question_id']]); flash('success', 'Soal dihapus.'); redirect('?page=question&exam_id=' . (int) $_POST['exam_id']);
            case 'import_students':
                Auth::requireRole(['guru', 'super_admin']);
                if (empty($_FILES['student_file']['tmp_name']) || $_FILES['student_file']['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('File import belum dipilih atau gagal diupload.');
                $extension = strtolower(pathinfo($_FILES['student_file']['name'], PATHINFO_EXTENSION)); $rows = parse_import_file($_FILES['student_file']['tmp_name'], $extension); if (count($rows) < 2) throw new RuntimeException('File tidak memiliki data siswa.');
                $headers = array_map(static fn($v) => strtolower(trim((string) $v)), array_shift($rows));
                $find = static function(array $names) use ($headers): int { foreach ($names as $name) { $index = array_search($name, $headers, true); if ($index !== false) return $index; } return -1; };
                $nameCol = $find(['nama','name','nama siswa']); $userCol = $find(['username','user name','nis']); $passCol = $find(['password','kata sandi']); $classCol = $find(['kelas','class','rombel']); if ($nameCol < 0 || $userCol < 0 || $passCol < 0) throw new RuntimeException('Header wajib: nama, username, password.');
                $defaultClass = trim((string) ($_POST['class_name'] ?? ''));
                $upsert = $db->prepare('INSERT INTO users(name,class_name,username,password,role) VALUES(?,?,?,?,\'siswa\') ON DUPLICATE KEY UPDATE name=VALUES(name),class_name=VALUES(class_name),password=VALUES(password),role=\'siswa\',is_active=1'); $count = 0;
                foreach ($rows as $row) { $name = trim((string) ($row[$nameCol] ?? '')); $username = trim((string) ($row[$userCol] ?? '')); $password = (string) ($row[$passCol] ?? ''); $className = trim((string) ($row[$classCol] ?? '')) ?: $defaultClass; if ($name && $username && $password) { $upsert->execute([$name, $className ?: null, $username, password_hash($password, PASSWORD_DEFAULT)]); $count++; } }
                flash('success', "$count akun siswa berhasil diimport atau diperbarui."); redirect('?page=import');
            case 'update_exam_access':
                Auth::requireRole(['guru', 'super_admin']);
                $examId = (int) ($_POST['exam_id'] ?? 0);
                $stmt = $db->prepare('SELECT id FROM exams WHERE id=?'); $stmt->execute([$examId]);
                if (!$stmt->fetchColumn()) throw new RuntimeException('Ujian tidak ditemukan.');
                $restrict = !empty($_POST['restrict_participants']) ? 1 : 0;
                $db->prepare('UPDATE exams SET restrict_participants=? WHERE id=?')->execute([$restrict, $examId]);
                flash('success', $restrict ? 'Ujian dibatasi: hanya peserta terpilih yang dapat mengerjakan.' : 'Ujian dibuka untuk semua siswa aktif.');
                redirect('?page=exam_manage&id=' . $examId . '#peserta');
            case 'add_exam_participants':
                Auth::requireRole(['guru', 'super_admin']);
                $examId = (int) ($_POST['exam_id'] ?? 0);
                $stmt = $db->prepare('SELECT id FROM exams WHERE id=?'); $stmt->execute([$examId]);
                if (!$stmt->fetchColumn()) throw new RuntimeException('Ujian tidak ditemukan.');
                $added = 0;
                // Tambah per kelas: seluruh siswa aktif pada kelas tersebut.
                $classLabel = trim((string) ($_POST['class_label'] ?? ''));
                if ($classLabel !== '') {
                    if ($classLabel === '(tanpa kelas)') {
                        $classStmt = $db->query("SELECT id FROM users WHERE role='siswa' AND is_active=1 AND (class_name IS NULL OR TRIM(class_name)='')");
                    } else {
                        $classStmt = $db->prepare("SELECT id FROM users WHERE role='siswa' AND is_active=1 AND TRIM(class_name)=?");
                        $classStmt->execute([$classLabel]);
                    }
                    $added += add_exam_participants($db, $examId, $classStmt->fetchAll(PDO::FETCH_COLUMN), 'class');
                }
                // Tambah per siswa (boleh beberapa sekaligus).
                $studentIds = array_map('intval', (array) ($_POST['student_ids'] ?? []));
                if ($studentIds) {
                    $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
                    $valid = $db->prepare("SELECT id FROM users WHERE role='siswa' AND id IN ($placeholders)");
                    $valid->execute($studentIds);
                    $added += add_exam_participants($db, $examId, $valid->fetchAll(PDO::FETCH_COLUMN), 'manual');
                }
                if ($classLabel === '' && !$studentIds) throw new RuntimeException('Pilih kelas atau centang minimal satu siswa.');
                $total = count(exam_participant_ids($db, $examId));
                flash('success', "$added peserta baru ditambahkan. Total peserta ujian: $total siswa.");
                redirect('?page=exam_manage&id=' . $examId . '#peserta');
            case 'import_exam_participants':
                Auth::requireRole(['guru', 'super_admin']);
                $examId = (int) ($_POST['exam_id'] ?? 0);
                $stmt = $db->prepare('SELECT id FROM exams WHERE id=?'); $stmt->execute([$examId]);
                if (!$stmt->fetchColumn()) throw new RuntimeException('Ujian tidak ditemukan.');
                if (empty($_FILES['participant_file']['tmp_name']) || $_FILES['participant_file']['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('File import peserta belum dipilih atau gagal diupload.');
                $extension = strtolower(pathinfo($_FILES['participant_file']['name'], PATHINFO_EXTENSION));
                $rows = parse_import_file($_FILES['participant_file']['tmp_name'], $extension);
                if (count($rows) < 2) throw new RuntimeException('File tidak memiliki data peserta.');
                $headers = array_map(static fn($v) => strtolower(trim((string) $v)), array_shift($rows));
                $find = static function (array $names) use ($headers): int { foreach ($names as $name) { $index = array_search($name, $headers, true); if ($index !== false) return $index; } return -1; };
                $userCol = $find(['username', 'user name', 'nis']);
                $nameCol = $find(['nama', 'name', 'nama siswa']);
                $classCol = $find(['kelas', 'class', 'rombel']);
                if ($userCol < 0 && $nameCol < 0 && $classCol < 0) throw new RuntimeException('Header wajib salah satu: username, nama, atau kelas.');
                $byUsername = $db->prepare("SELECT id FROM users WHERE role='siswa' AND username=? LIMIT 1");
                $byName = $db->prepare("SELECT id FROM users WHERE role='siswa' AND LOWER(TRIM(name))=LOWER(TRIM(?)) LIMIT 1");
                $byClass = $db->prepare("SELECT id FROM users WHERE role='siswa' AND is_active=1 AND TRIM(class_name)=?");
                $ids = []; $notFound = [];
                foreach ($rows as $row) {
                    $username = $userCol >= 0 ? trim((string) ($row[$userCol] ?? '')) : '';
                    $name = $nameCol >= 0 ? trim((string) ($row[$nameCol] ?? '')) : '';
                    $className = $classCol >= 0 ? trim((string) ($row[$classCol] ?? '')) : '';
                    if ($username !== '') { $byUsername->execute([$username]); $id = (int) $byUsername->fetchColumn(); if ($id) { $ids[] = $id; continue; } }
                    if ($name !== '') { $byName->execute([$name]); $id = (int) $byName->fetchColumn(); if ($id) { $ids[] = $id; continue; } }
                    if ($className !== '') {
                        $byClass->execute([$className]);
                        $classIds = array_map('intval', $byClass->fetchAll(PDO::FETCH_COLUMN));
                        if ($classIds) { $ids = array_merge($ids, $classIds); continue; }
                    }
                    $label = $username !== '' ? $username : ($name !== '' ? $name : $className);
                    if ($label !== '') $notFound[] = $label;
                }
                if (!$ids) throw new RuntimeException('Tidak ada siswa yang cocok dengan isi file. Periksa kolom username/nama/kelas.');
                $added = add_exam_participants($db, $examId, $ids, 'import');
                $total = count(exam_participant_ids($db, $examId));
                $message = "$added peserta baru ditambahkan dari file. Total peserta ujian: $total siswa.";
                $missing = array_values(array_unique($notFound));
                if ($missing) $message .= ' Tidak ditemukan: ' . implode(', ', array_slice($missing, 0, 8)) . (count($missing) > 8 ? '…' : '') . '.';
                flash('success', $message);
                redirect('?page=exam_manage&id=' . $examId . '#peserta');
            case 'remove_exam_participant':
                Auth::requireRole(['guru', 'super_admin']);
                $examId = (int) ($_POST['exam_id'] ?? 0); $userId = (int) ($_POST['user_id'] ?? 0);
                $stmt = $db->prepare('DELETE FROM exam_participants WHERE exam_id=? AND user_id=?'); $stmt->execute([$examId, $userId]);
                flash('success', $stmt->rowCount() ? 'Peserta dikeluarkan dari ujian.' : 'Peserta tidak ditemukan pada ujian ini.');
                redirect('?page=exam_manage&id=' . $examId . '#peserta');
            case 'clear_exam_participants':
                Auth::requireRole(['guru', 'super_admin']);
                $examId = (int) ($_POST['exam_id'] ?? 0);
                $stmt = $db->prepare('DELETE FROM exam_participants WHERE exam_id=?'); $stmt->execute([$examId]);
                flash('success', $stmt->rowCount() . ' peserta dikeluarkan dari ujian.');
                redirect('?page=exam_manage&id=' . $examId . '#peserta');
            case 'reset_student_password':
                Auth::requireRole(['guru', 'super_admin']);
                $userId = (int) ($_POST['user_id'] ?? 0);
                $newPassword = trim((string) ($_POST['new_password'] ?? ''));
                if ($userId <= 0) throw new RuntimeException('Siswa tidak dipilih.');
                if (strlen($newPassword) < 8) throw new RuntimeException('Password baru minimal 8 karakter.');
                $check = $db->prepare("SELECT id FROM users WHERE id=? AND role='siswa'"); $check->execute([$userId]);
                if (!$check->fetchColumn()) throw new RuntimeException('Akun siswa tidak ditemukan.');
                $stmt = $db->prepare('UPDATE users SET password=?,is_active=1 WHERE id=? AND role=\'siswa\'');
                $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
                flash('success', 'Password siswa berhasil direset. Sampaikan password baru kepada siswa, lalu minta segera diganti.');
                redirect('?page=import');
            case 'create_user':
                Auth::requireRole(['super_admin']); $role = in_array($_POST['role'] ?? '', ['siswa','guru','super_admin'], true) ? $_POST['role'] : 'siswa'; $stmt = $db->prepare('INSERT INTO users(name,class_name,username,password,role) VALUES(?,?,?,?,?)'); $stmt->execute([trim($_POST['name'] ?? ''), trim((string) ($_POST['class_name'] ?? '')) ?: null, trim($_POST['username'] ?? ''), password_hash((string) $_POST['password'], PASSWORD_DEFAULT), $role]); flash('success', 'Pengguna berhasil dibuat.'); redirect('?page=users');
            case 'toggle_user':
                Auth::requireRole(['super_admin']); $stmt = $db->prepare('UPDATE users SET is_active=1-is_active WHERE id=? AND id<>?'); $stmt->execute([(int) $_POST['user_id'], Auth::user()['id']]); flash('success', 'Status pengguna diperbarui.'); redirect('?page=users');
            case 'update_user':
                Auth::requireRole(['super_admin']);
                $userId = (int) ($_POST['user_id'] ?? 0); $role = in_array($_POST['role'] ?? '', ['siswa','guru','super_admin'], true) ? $_POST['role'] : 'siswa';
                if ($userId === (int) Auth::user()['id'] && $role !== 'super_admin') throw new RuntimeException('Role akun super admin yang sedang digunakan tidak dapat diturunkan.');
                $username = trim((string) ($_POST['username'] ?? '')); $name = trim((string) ($_POST['name'] ?? '')); if ($name === '' || $username === '') throw new RuntimeException('Nama dan username wajib diisi.');
                if (trim((string) ($_POST['password'] ?? '')) !== '') {
                    $stmt = $db->prepare('UPDATE users SET name=?,class_name=?,username=?,password=?,role=? WHERE id=?'); $stmt->execute([$name, trim((string) ($_POST['class_name'] ?? '')) ?: null, $username, password_hash((string) $_POST['password'], PASSWORD_DEFAULT), $role, $userId]);
                } else {
                    $stmt = $db->prepare('UPDATE users SET name=?,class_name=?,username=?,role=? WHERE id=?'); $stmt->execute([$name, trim((string) ($_POST['class_name'] ?? '')) ?: null, $username, $role, $userId]);
                }
                flash('success', 'Data pengguna berhasil diperbarui.'); redirect('?page=users');
            case 'delete_user':
                Auth::requireRole(['super_admin']);
                $userId = (int) ($_POST['user_id'] ?? 0); if ($userId === (int) Auth::user()['id']) throw new RuntimeException('Akun yang sedang digunakan tidak dapat dihapus.');
                $stmt = $db->prepare('DELETE FROM users WHERE id=?'); $stmt->execute([$userId]); flash('success', 'Pengguna berhasil dihapus.'); redirect('?page=users');
            case 'submit_attempt':
                Auth::requireRole(['siswa']);
                $attemptId = (int) $_POST['attempt_id']; $attemptStmt = $db->prepare('SELECT a.*,e.title,e.restrict_participants FROM attempts a JOIN exams e ON e.id=a.exam_id WHERE a.id=? AND a.user_id=?'); $attemptStmt->execute([$attemptId, Auth::user()['id']]); $attempt = $attemptStmt->fetch(); if (!$attempt) throw new RuntimeException('Attempt tidak ditemukan.');
                if (!student_can_take_exam($db, ['id' => (int) $attempt['exam_id'], 'restrict_participants' => (int) $attempt['restrict_participants']], (int) Auth::user()['id'])) throw new RuntimeException('Kamu tidak terdaftar sebagai peserta ujian ini.');
                $questions = $db->prepare('SELECT * FROM questions WHERE exam_id=? AND is_enabled=1 ORDER BY sort_order,id'); $questions->execute([$attempt['exam_id']]); $questions = $questions->fetchAll(); $answerStmt = $db->prepare('INSERT INTO answers(attempt_id,question_id,code,score,test_results,ai_feedback) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE code=VALUES(code),score=VALUES(score),test_results=VALUES(test_results),ai_feedback=VALUES(ai_feedback)'); $total = 0; $max = 0;
                foreach ($questions as $question) { $code = (string) ($_POST['code'][$question['id']] ?? $question['starter_code'] ?? ''); $tests = $db->prepare('SELECT * FROM test_cases WHERE question_id=? ORDER BY sort_order,id'); $tests->execute([$question['id']]); $tests = $tests->fetchAll(); $passed = 0; $results = []; foreach ($tests as $test) { $run = run_python_code($code, (string) $test['input_data'], $config['python_bin']); $isPassed = $run['ok'] && normalize_output($run['output']) === normalize_output((string) $test['expected_output']); if ($isPassed) $passed++; $results[] = ['passed' => $isPassed, 'input' => $test['input_data'], 'expected' => $test['expected_output'], 'output' => $run['output'], 'error' => $run['error'], 'time_ms' => $run['time_ms']]; } $isScored = (int) ($question['is_scored'] ?? 1) === 1; $score = $isScored && count($tests) ? ((float) $question['points'] * $passed / count($tests)) : 0; $feedback = ai_review($config, $question['question_text'], $code, $results); $answerStmt->execute([$attemptId, $question['id'], $code, $score, json_encode($results, JSON_UNESCAPED_UNICODE), $feedback]); if ($isScored) { $total += $score; $max += (float) $question['points']; } }
                $final = $max > 0 ? round($total / $max * 100, 2) : 0; $stmt = $db->prepare("UPDATE attempts SET status='submitted',score=?,submitted_at=NOW() WHERE id=?"); $stmt->execute([$final, $attemptId]); flash('success', 'Jawaban berhasil dinilai.'); redirect('?page=result&id=' . $attemptId);
            case 'reset_attempt':
                // Reset/ulang ujian seorang siswa: jawaban dihapus dan waktu mulai diatur ulang.
                Auth::requireRole(['super_admin']);
                $attemptId = (int) ($_POST['attempt_id'] ?? 0);
                $stmt = $db->prepare('SELECT a.id,a.exam_id,a.status,e.title,u.name FROM attempts a JOIN exams e ON e.id=a.exam_id JOIN users u ON u.id=a.user_id WHERE a.id=?');
                $stmt->execute([$attemptId]);
                $target = $stmt->fetch();
                if (!$target) throw new RuntimeException('Data pengerjaan ujian tidak ditemukan.');
                reset_attempt($db, $attemptId);
                flash('success', 'Ujian ' . $target['name'] . ' pada "' . $target['title'] . '" berhasil direset. Pengerjaan lama dihapus dan siswa dapat memulai ujian dari awal.');
                $back = trim((string) ($_POST['redirect'] ?? ''));
                // Hanya izinkan kembali ke halaman internal aplikasi.
                if ($back === '' || !str_starts_with($back, '?page=')) $back = '?page=exam_manage&id=' . (int) $target['exam_id'] . '#reset';
                redirect($back);
            case 'reset_exam_attempts':
                // Reset seluruh pengerjaan pada satu ujian sekaligus.
                Auth::requireRole(['super_admin']);
                $examId = (int) ($_POST['exam_id'] ?? 0);
                $stmt = $db->prepare('SELECT title FROM exams WHERE id=?'); $stmt->execute([$examId]);
                $examTitle = (string) $stmt->fetchColumn();
                if ($examTitle === '') throw new RuntimeException('Ujian tidak ditemukan.');
                $reset = reset_exam_attempts($db, $examId);
                flash('success', $reset > 0 ? "$reset pengerjaan pada ujian \"$examTitle\" berhasil direset. Semua siswa dapat mengerjakan ulang dari awal." : 'Belum ada siswa yang mengerjakan ujian ini, jadi tidak ada yang perlu direset.');
                redirect('?page=exam_manage&id=' . $examId . '#reset');
        }
    } catch (Throwable $e) { flash('error', $config['app']['env'] === 'production' ? $e->getMessage() : $e->getMessage()); redirect('?page=' . ($page ?: 'dashboard')); }
}

if ($page === 'logout') { Auth::logout(); redirect('?page=login'); }

if ($page === 'setup') {
    if ((int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) redirect('?page=login');
    layout('Setup Super Admin', function () { ?>
        <div class="login-page"><section class="login-side"><a class="brand" href="?page=login" style="color:#fff"><span class="brand-mark">⌁</span><span>PyTest SMA<small style="color:#cfcaff">coding assessment</small></span></a><h1>Siapkan ruang ujian Anda.</h1><p>Buat akun super admin pertama untuk mulai mengelola kelas dan ujian.</p></section><section class="login-form-side"><div class="login-box"><h2>Setup super admin</h2><p>Akun ini dapat mengelola seluruh pengguna dan ujian.</p><form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="setup"><div class="form-group"><label class="form-label">Nama lengkap</label><input class="input" name="name" required></div><div class="form-group"><label class="form-label">Username</label><input class="input" name="username" required></div><div class="form-group"><label class="form-label">Password</label><input class="input" type="password" name="password" minlength="8" required></div><button class="button full">Buat super admin</button></form></div></section></div>
    <?php }, false); exit;
}
if ($page === 'login') {
    if (Auth::user()) redirect('?page=dashboard');
    layout('Masuk', function () { ?>
        <div class="login-page"><section class="login-side"><a class="brand" href="?page=login" style="color:#fff"><span class="brand-mark">⌁</span><span>PyTest SMA<small style="color:#cfcaff">coding assessment</small></span></a><h1>Belajar kode.<br><em style="color:#a9a0ff;font-style:normal">Uji kemampuan.</em></h1><p>Platform ujian Python yang membuat proses belajar pemrograman lebih nyata, terukur, dan menyenangkan.</p><ul class="feature-list"><li><b>✓</b> Editor kode langsung di browser</li><li><b>✓</b> Penilaian otomatis berbasis test case</li><li><b>✓</b> Feedback pintar untuk berkembang</li></ul></section><section class="login-form-side"><div class="login-box"><div class="brand"><span class="brand-mark">⌁</span><span>PyTest SMA<small>coding assessment</small></span></div><h2>Selamat datang kembali</h2><p>Masuk dengan akun yang diberikan sekolah Anda.</p><form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="login"><div class="form-group"><label class="form-label">Username</label><input class="input" name="username" autocomplete="username" required placeholder="mis. andi.siswa"></div><div class="form-group"><label class="form-label">Password</label><div class="password-wrap"><input id="login-password" class="input" type="password" name="password" autocomplete="current-password" required placeholder="Masukkan password"><button type="button" class="show-password" data-toggle-password="#login-password">◉</button></div></div><button class="button full">Masuk ke dashboard →</button></form></div></section></div>
    <?php }, false); exit;
}

Auth::requireLogin();

if ($page === 'dashboard') {
    if (Auth::user()['role'] === 'siswa') {
        $stmt = $db->prepare("SELECT e.*,a.id attempt_id,a.status attempt_status,a.score,a.started_at attempt_started_at,(SELECT COUNT(*) FROM questions q WHERE q.exam_id=e.id AND q.is_enabled=1) question_count FROM exams e LEFT JOIN attempts a ON a.exam_id=e.id AND a.user_id=? WHERE e.status='published' AND (e.restrict_participants=0 OR EXISTS(SELECT 1 FROM exam_participants ep WHERE ep.exam_id=e.id AND ep.user_id=?)) ORDER BY e.created_at DESC"); $stmt->execute([Auth::user()['id'], Auth::user()['id']]); $exams = $stmt->fetchAll(); $done = count(array_filter($exams, static fn($e) => $e['attempt_status'] === 'submitted'));
        layout('Dashboard', function () use ($exams, $done) { ?>
            <div class="page-heading"><div><div class="eyebrow">Area siswa</div><h1>Halo, <?= e(Auth::user()['name']) ?> 👋</h1><p>Siap mengasah kemampuan Python hari ini?</p></div></div><div class="cards"><div class="stat-card"><span class="label">Ujian tersedia</span><div class="number"><?= count($exams) ?></div><span class="detail">Siap dikerjakan</span></div><div class="stat-card"><span class="label">Sudah selesai</span><div class="number"><?= $done ?></div><span class="detail">Dari <?= count($exams) ?> ujian</span></div><div class="stat-card"><span class="label">Status akun</span><div class="number" style="font-size:20px">Aktif</div><span class="detail">Semangat belajar!</span></div></div><div class="panel"><div class="panel-heading"><div><h2>Ujian untukmu</h2><span class="muted">Kerjakan dengan jujur dan teliti.</span></div></div><?php if (!$exams): ?><div class="empty"><strong>Belum ada ujian tersedia</strong>Guru belum menerbitkan ujian untukmu.</div><?php else: ?><div class="exam-grid"><?php foreach ($exams as $exam): $examExpired = $exam['attempt_status'] === 'in_progress' && attempt_is_expired(['status' => 'in_progress', 'started_at' => $exam['attempt_started_at']], (int) $exam['duration_minutes']); ?><article class="exam-card"><div class="card-icon">⌘</div><h3><?= e($exam['title']) ?></h3><p><?= e($exam['description'] ?: 'Ujian coding Python untuk menguji logika dan kemampuan pemrogramanmu.') ?></p><div class="exam-meta"><span>◷ <?= e($exam['duration_minutes']) ?> menit</span><span>▣ Coding Python</span><?= $examExpired ? '<span>⏱ Waktu habis</span>' : '' ?></div><div class="card-action"><?php if ($exam['attempt_status'] === 'submitted'): ?><a class="button light small full" href="?page=result&id=<?= (int) $exam['attempt_id'] ?>">Lihat hasil · <?= e($exam['score']) ?></a><?php elseif ($examExpired): ?><span class="badge red">Waktu habis</span><a class="button light small full" href="?page=result&id=<?= (int) $exam['attempt_id'] ?>" style="margin-top:10px">Lihat status</a><span class="help" style="display:block">Minta guru atau super admin melakukan reset agar bisa mengerjakan ulang.</span><?php else: ?><a class="button small full" href="?page=exam&id=<?= (int) $exam['id'] ?>"><?= $exam['attempt_id'] ? 'Lanjutkan ujian →' : 'Mulai ujian →' ?></a><?php endif; ?></div></article><?php endforeach; ?></div><?php endif; ?></div>
        <?php }); exit;
    }
    $totalExams = (int) $db->query('SELECT COUNT(*) FROM exams')->fetchColumn(); $published = (int) $db->query("SELECT COUNT(*) FROM exams WHERE status='published'")->fetchColumn(); $students = (int) $db->query("SELECT COUNT(*) FROM users WHERE role='siswa'")->fetchColumn(); $attempts = (int) $db->query("SELECT COUNT(*) FROM attempts WHERE status='submitted'")->fetchColumn(); $exams = $db->query('SELECT e.*,u.name creator,(SELECT COUNT(*) FROM questions q WHERE q.exam_id=e.id AND q.is_enabled=1) question_count FROM exams e JOIN users u ON u.id=e.created_by ORDER BY e.created_at DESC LIMIT 8')->fetchAll(); $recentResults = $db->query("SELECT a.id attempt_id,a.score,a.submitted_at,e.title,u.name student,u.class_name FROM attempts a JOIN exams e ON e.id=a.exam_id JOIN users u ON u.id=a.user_id WHERE a.status='submitted' ORDER BY a.submitted_at DESC LIMIT 10")->fetchAll();
    layout('Dashboard', function () use ($totalExams,$published,$students,$attempts,$exams,$recentResults) { ?>
        <div class="page-heading"><div><div class="eyebrow">Panel pengajar</div><h1>Ringkasan aktivitas</h1><p>Kelola ujian dan pantau progres siswa dalam satu tempat.</p></div><a class="button" href="?page=exam_manage&new=1">＋ Buat ujian baru</a></div><div class="cards"><div class="stat-card"><span class="label">Total ujian</span><div class="number"><?= $totalExams ?></div><span class="detail">Semua status</span></div><div class="stat-card"><span class="label">Ujian diterbitkan</span><div class="number"><?= $published ?></div><span class="detail">Bisa diakses siswa</span></div><div class="stat-card"><span class="label">Siswa terdaftar</span><div class="number"><?= $students ?></div><span class="detail">Akun aktif</span></div><div class="stat-card"><span class="label">Pengumpulan</span><div class="number"><?= $attempts ?></div><span class="detail">Jawaban selesai</span></div></div><div class="panel"><div class="panel-heading"><div><h2>Ujian terbaru</h2><span class="muted">Kelola bank soal dan publikasi ujian.</span></div><a class="button secondary small" href="?page=exam_manage">Lihat semua →</a></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Nama ujian</th><th>Status</th><th>Soal</th><th>Dibuat oleh</th><th></th></tr></thead><tbody><?php foreach ($exams as $exam): ?><tr><td><strong><?= e($exam['title']) ?></strong><br><small class="muted"><?= e(format_date($exam['created_at'])) ?></small></td><td><span class="badge <?= $exam['status']==='published'?'green':($exam['status']==='closed'?'red':'gray') ?>"><?= e(ucfirst($exam['status'])) ?></span></td><td><?= e($exam['question_count']) ?> soal</td><td><?= e($exam['creator']) ?></td><td><a class="button light small" href="?page=exam_manage&id=<?= (int) $exam['id'] ?>">Kelola</a></td></tr><?php endforeach; ?><?php if (!$exams): ?><tr><td colspan="5" class="empty">Belum ada ujian.</td></tr><?php endif; ?></tbody></table></div></div>
        <div class="panel"><div class="panel-heading"><div><h2>Nilai siswa terbaru</h2><span class="muted">Hasil ujian yang sudah dikumpulkan siswa.</span></div><span class="badge green"><?= $attempts ?> terkumpul</span></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Siswa</th><th>Kelas</th><th>Ujian</th><th>Nilai</th><th>Submit</th><th></th></tr></thead><tbody><?php foreach ($recentResults as $result): ?><tr><td><strong><?= e($result['student']) ?></strong></td><td><?= e($result['class_name'] ?: '-') ?></td><td><?= e($result['title']) ?></td><td><span class="badge <?= $result['score'] >= 75 ? 'green' : ($result['score'] >= 60 ? 'orange' : 'red') ?>"><?= e(number_format((float) $result['score'], 2)) ?></span></td><td><small class="muted"><?= e(format_date($result['submitted_at'])) ?></small></td><td><a class="button light small" href="?page=result&id=<?= (int) $result['attempt_id'] ?>">Lihat hasil</a></td></tr><?php endforeach; ?><?php if (!$recentResults): ?><tr><td colspan="6" class="empty"><strong>Belum ada hasil ujian</strong>Nilai akan muncul setelah siswa mengumpulkan jawaban.</td></tr><?php endif; ?></tbody></table></div></div>
    <?php }); exit;
}

if ($page === 'exam_manage') {
    Auth::requireRole(['guru','super_admin']); $id = (int) ($_GET['id'] ?? 0); $exam = $id ? $db->prepare('SELECT * FROM exams WHERE id=?') : null; if ($exam) {$exam->execute([$id]);$exam=$exam->fetch();} else $exam=null;
    if (!$id && !isset($_GET['new'])) {
        $allExams = $db->query('SELECT e.*, (SELECT COUNT(*) FROM questions q WHERE q.exam_id=e.id AND q.is_enabled=1) question_count, (SELECT COUNT(*) FROM exam_participants ep WHERE ep.exam_id=e.id) participant_count FROM exams e ORDER BY e.created_at DESC')->fetchAll();
        layout('Bank Ujian', function () use ($allExams) { ?><div class="page-heading"><div><div class="eyebrow">Bank ujian</div><h1>Semua ujian</h1><p>Kelola draft, soal, dan publikasi ujian.</p></div><a class="button" href="?page=exam_manage&new=1">＋ Buat ujian baru</a></div><div class="panel"><div class="table-wrap"><table class="data-table"><thead><tr><th>Nama ujian</th><th>Status</th><th>Durasi</th><th>Soal</th><th>Peserta</th><th></th></tr></thead><tbody><?php foreach($allExams as $item): ?><tr><td><strong><?= e($item['title']) ?></strong><br><small class="muted"><?= e(format_date($item['created_at'])) ?></small></td><td><span class="badge <?= $item['status']==='published'?'green':($item['status']==='closed'?'red':'gray') ?>"><?= e(ucfirst($item['status'])) ?></span></td><td><?= e($item['duration_minutes']) ?> menit</td><td><?= e($item['question_count']) ?></td><td><?php if ((int) $item['restrict_participants'] === 1): ?><span class="badge green"><?= (int) $item['participant_count'] ?> siswa</span><?php else: ?><span class="badge gray">Semua siswa</span><?php endif; ?></td><td><a class="button light small" href="?page=exam_manage&id=<?= (int)$item['id'] ?>">Kelola</a></td></tr><?php endforeach; ?><?php if(!$allExams): ?><tr><td colspan="6" class="empty">Belum ada ujian.</td></tr><?php endif; ?></tbody></table></div></div><?php }, true); exit;
    }
    if (isset($_GET['new']) || isset($_GET['edit'])) { layout($exam ? 'Edit Ujian' : 'Buat Ujian', function () use ($exam) { ?><div class="page-heading"><div><div class="eyebrow">Bank ujian</div><h1><?= $exam ? 'Edit ujian' : 'Buat ujian baru' ?></h1><p>Atur informasi dasar sebelum menambahkan soal.</p></div><a class="button light" href="?page=exam_manage">← Kembali</a></div><div class="panel"><form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="<?= $exam ? 'update_exam' : 'create_exam' ?>"><?php if($exam): ?><input type="hidden" name="exam_id" value="<?= (int)$exam['id'] ?>"><?php endif; ?><div class="form-grid"><div class="form-group full"><label class="form-label">Judul ujian</label><input class="input" name="title" required value="<?= e($exam['title'] ?? '') ?>" placeholder="Contoh: Ujian Dasar Python - Kelas X"></div><div class="form-group full"><label class="form-label">Deskripsi</label><textarea class="textarea" name="description" placeholder="Jelaskan materi atau instruksi umum ujian..."><?= e($exam['description'] ?? '') ?></textarea></div><div class="form-group"><label class="form-label">Durasi (menit)</label><input class="input" type="number" min="1" name="duration_minutes" required value="<?= e($exam['duration_minutes'] ?? 60) ?>"></div><div class="form-group"><label class="form-label">Status</label><select class="select" name="status"><option value="draft" <?= ($exam['status'] ?? '')==='draft'?'selected':'' ?>>Draft</option><option value="published" <?= ($exam['status'] ?? '')==='published'?'selected':'' ?>>Published — siswa dapat mengakses</option><option value="closed" <?= ($exam['status'] ?? '')==='closed'?'selected':'' ?>>Closed</option></select></div></div><div class="form-actions"><button class="button">Simpan ujian</button></div></form></div><?php }, true); exit; }
    $questions = $db->prepare('SELECT q.*, (SELECT COUNT(*) FROM test_cases tc WHERE tc.question_id=q.id) test_count FROM questions q WHERE q.exam_id=? ORDER BY q.sort_order,q.id'); $questions->execute([$id]); $questions=$questions->fetchAll(); $enabledCount = count(array_filter($questions, static fn($q) => (int) $q['is_enabled'] === 1));
    $participants = exam_participant_rows($db, $id);
    $participantIds = array_map(static fn($p) => (int) $p['id'], $participants);
    $classSummary = student_class_summary($db);
    $allStudents = $db->query("SELECT id,name,class_name,username FROM users WHERE role='siswa' AND is_active=1 ORDER BY class_name IS NULL,class_name,name")->fetchAll();
    $availableStudents = array_values(array_filter($allStudents, static fn($s) => !in_array((int) $s['id'], $participantIds, true)));
    $restricted = exam_restricts_participants($exam);
    $attemptRows = exam_attempt_rows($db, $id);
    layout('Kelola Ujian', function () use ($exam,$questions,$enabledCount,$participants,$participantIds,$classSummary,$availableStudents,$restricted,$attemptRows) { ?><div class="page-heading"><div><div class="eyebrow">Bank ujian · <?= e(ucfirst($exam['status'])) ?></div><h1><?= e($exam['title']) ?></h1><p><?= e($exam['description'] ?: 'Belum ada deskripsi.') ?></p></div><div><a class="button secondary" href="?page=exam_manage&id=<?= (int)$exam['id']?>&edit=1">Edit info</a> <a class="button" href="?page=question&exam_id=<?= (int)$exam['id'] ?>">＋ Tambah soal</a></div></div><form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update_question_selection"><input type="hidden" name="exam_id" value="<?= (int) $exam['id'] ?>"><div class="panel"><div class="panel-heading"><div><h2>Pilih soal yang tampil <span class="muted" style="font-size:13px;font-family:'DM Sans'">(<?= $enabledCount ?>/<?= count($questions) ?> aktif)</span></h2><span class="help">Centang soal yang akan diberikan kepada siswa.</span></div><button class="button small">Simpan pilihan</button></div><?php if(!$questions): ?><div class="empty"><strong>Belum ada soal</strong>Tambahkan soal Python dan test case agar ujian siap diterbitkan.</div><?php else: ?><div class="table-wrap"><table class="data-table"><thead><tr><th>Aktif</th><th>#</th><th>Pertanyaan</th><th>Level</th><th>Bobot</th><th>Test case</th><th>Aksi</th></tr></thead><tbody><?php foreach($questions as $i=>$q): ?><tr><td><input type="checkbox" name="visible_questions[]" value="<?= (int) $q['id'] ?>" <?= (int) $q['is_enabled'] === 1 ? 'checked' : '' ?> aria-label="Tampilkan soal <?= $i + 1 ?>"></td><td><?= $i+1 ?></td><td><strong><?= e(mb_strimwidth($q['question_text'],0,80,'…')) ?></strong></td><td><span class="badge <?= $q['difficulty']==='entry'?'green':($q['difficulty']==='advanced'?'red':'gray') ?>"><?= e(ucfirst($q['difficulty'])) ?></span></td><td><?= (int) ($q['is_scored'] ?? 1) === 1 ? e($q['points']) : '<span class="badge orange">Contoh</span>' ?></td><td><?= e($q['test_count']) ?></td><td><a class="button secondary small" href="?page=question_edit&exam_id=<?= (int) $exam['id'] ?>&question_id=<?= (int) $q['id'] ?>">Edit</a> <button class="button danger small" type="submit" name="delete_question_id" value="<?= (int) $q['id'] ?>" formaction="?page=exam_manage&id=<?= (int) $exam['id'] ?>" data-confirm="Hapus soal ini?">Hapus</button></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div></form><div class="panel import-note">Soal aktif akan tampil saat ujian. Soal bertanda <b>Contoh</b> tidak dihitung ke nilai akhir, sedangkan test case pertama tiap soal dipakai sebagai contoh yang dapat dicoba siswa.</div>
<div class="panel" id="peserta">
    <div class="panel-heading"><div><h2>Peserta ujian <span class="badge <?= $restricted ? 'green' : 'gray' ?>"><?= $restricted ? 'Dibatasi' : 'Semua siswa' ?></span></h2><span class="help">Batasi ujian hanya untuk siswa tertentu: per kelas, per siswa, atau import dari file.</span></div><span class="badge gray"><?= count($participants) ?> peserta</span></div>
    <form method="post" style="display:flex;flex-wrap:wrap;gap:14px;align-items:center;justify-content:space-between">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update_exam_access"><input type="hidden" name="exam_id" value="<?= (int) $exam['id'] ?>">
        <label style="display:flex;align-items:center;gap:9px;font-size:13px;font-weight:600"><input type="checkbox" name="restrict_participants" value="1" <?= $restricted ? 'checked' : '' ?>> Hanya siswa yang ditentukan boleh mengikuti ujian ini</label>
        <button class="button small">Simpan pengaturan akses</button>
    </form>
    <?php if ($restricted && !$participants): ?><div class="import-note" style="margin-top:14px;border-left-color:var(--yellow)">Ujian sudah dibatasi tetapi belum ada peserta, sehingga <b>belum ada siswa</b> yang dapat mengerjakannya. Tambahkan peserta di bawah ini.</div><?php endif; ?>
</div>
<div class="grid-2">
    <div class="panel">
        <div class="panel-heading"><div><h2>Tambah per kelas</h2><span class="help">Semua siswa aktif pada kelas terpilih langsung menjadi peserta.</span></div></div>
        <form method="post">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="add_exam_participants"><input type="hidden" name="exam_id" value="<?= (int) $exam['id'] ?>">
            <div class="form-group"><label class="form-label">Kelas</label>
                <select class="select" name="class_label" required>
                    <option value="">— Pilih kelas —</option>
                    <?php foreach ($classSummary as $class): ?><option value="<?= e($class['class_label']) ?>"><?= e($class['class_label']) ?> · <?= (int) $class['total'] ?> siswa</option><?php endforeach; ?>
                </select>
                <?php if (!$classSummary): ?><p class="help">Belum ada akun siswa aktif. Import siswa terlebih dahulu di menu <b>Import siswa</b>.</p><?php endif; ?>
            </div>
            <button class="button small">＋ Tambahkan kelas</button>
        </form>
    </div>
    <div class="panel">
        <div class="panel-heading"><div><h2>Import peserta</h2><span class="help">File CSV/XLSX dengan kolom <code>username</code>, <code>nama</code>, atau <code>kelas</code>.</span></div></div>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="import_exam_participants"><input type="hidden" name="exam_id" value="<?= (int) $exam['id'] ?>">
            <div class="form-group"><label class="form-label">File CSV atau Excel (.xlsx)</label><input class="input" type="file" name="participant_file" accept=".csv,.xlsx" required><p class="help">Baris pertama = header. Jika kolom <code>username</code>/<code>nama</code> kosong, isi kolom <code>kelas</code> akan didaftarkan.</p></div>
            <button class="button small">⇧ Import peserta</button>
            <a class="button light small" href="<?= e(asset_url('template-import-peserta.csv')) ?>" download>⇩ Template CSV</a>
            <a class="button light small" href="<?= e(asset_url('template-import-peserta.xlsx')) ?>" download>⇩ Template Excel</a>
        </form>
    </div>
</div>
<div class="panel">
    <div class="panel-heading"><div><h2>Tambah per siswa</h2><span class="help">Centang siswa yang boleh mengikuti ujian ini, lalu simpan.</span></div></div>
    <?php if (!$availableStudents): ?>
        <div class="empty"><strong>Semua siswa aktif sudah menjadi peserta</strong>Tambahkan akun siswa baru lewat menu Import siswa bila diperlukan.</div>
    <?php else: ?>
    <form method="post">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="add_exam_participants"><input type="hidden" name="exam_id" value="<?= (int) $exam['id'] ?>">
        <div class="form-group"><label class="form-label">Cari siswa</label><input class="input" type="search" data-participant-filter="#student-picker" placeholder="Ketik nama, username, atau kelas…"><p class="help">Daftar di bawah hanya menampilkan siswa aktif yang belum menjadi peserta.</p></div>
        <div id="student-picker" class="participant-picker">
            <?php foreach ($availableStudents as $student): ?><label class="participant-option" data-search="<?= e(strtolower($student['name'] . ' ' . $student['username'] . ' ' . (string) $student['class_name'])) ?>"><input type="checkbox" name="student_ids[]" value="<?= (int) $student['id'] ?>"><span><strong><?= e($student['name']) ?></strong><small><?= e($student['class_name'] ?: 'Tanpa kelas') ?> · @<?= e($student['username']) ?></small></span></label><?php endforeach; ?>
        </div>
        <div class="form-actions"><button class="button small">＋ Tambahkan siswa terpilih</button></div>
    </form>
    <?php endif; ?>
</div>
<div class="panel">
    <div class="panel-heading"><div><h2>Daftar peserta <span class="muted" style="font-size:13px;font-family:'DM Sans'">(<?= count($participants) ?> siswa)</span></h2><span class="help">Hanya siswa pada daftar ini yang melihat dan dapat mengerjakan ujian saat akses dibatasi.</span></div>
        <?php if ($participants): ?><form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="clear_exam_participants"><input type="hidden" name="exam_id" value="<?= (int) $exam['id'] ?>"><button class="button danger small" data-confirm="Keluarkan semua <?= count($participants) ?> peserta dari ujian ini?">Keluarkan semua</button></form><?php endif; ?>
    </div>
    <?php if (!$participants): ?>
        <div class="empty"><strong>Belum ada peserta terpilih</strong>Tambahkan per kelas, per siswa, atau lewat import file.</div>
    <?php else: ?>
    <div class="table-wrap"><table class="data-table"><thead><tr><th>#</th><th>Nama</th><th>Kelas</th><th>Username</th><th>Sumber</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($participants as $index => $participant): $sourceLabel = ['manual' => 'Dipilih', 'class' => 'Kelas', 'import' => 'Import'][$participant['source']] ?? $participant['source']; ?><tr><td><?= $index + 1 ?></td><td><strong><?= e($participant['name']) ?></strong></td><td><?= e($participant['class_name'] ?: '-') ?></td><td>@<?= e($participant['username']) ?></td><td><span class="badge gray"><?= e($sourceLabel) ?></span></td><td><span class="badge <?= (int) $participant['is_active'] === 1 ? 'green' : 'red' ?>"><?= (int) $participant['is_active'] === 1 ? 'Aktif' : 'Nonaktif' ?></span></td><td><form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="remove_exam_participant"><input type="hidden" name="exam_id" value="<?= (int) $exam['id'] ?>"><input type="hidden" name="user_id" value="<?= (int) $participant['id'] ?>"><button class="button danger small" data-confirm="Keluarkan <?= e($participant['name']) ?> dari peserta ujian?">Keluarkan</button></form></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</div>
<?php if (Auth::user()['role'] === 'super_admin'): ?>
<div class="panel" id="reset">
    <div class="panel-heading">
        <div><h2>Reset / ulang ujian <span class="badge red">Super admin</span></h2><span class="help">Gunakan bila siswa kehabisan waktu, gagal submit, atau perlu mengerjakan ulang. Reset menghapus jawaban dan nilai lama; durasi ujian mulai dihitung lagi saat siswa membuka ujian.</span></div>
        <?php if ($attemptRows): ?>
        <form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="reset_exam_attempts"><input type="hidden" name="exam_id" value="<?= (int) $exam['id'] ?>"><button class="button danger small" data-confirm="Reset pengerjaan <?= count($attemptRows) ?> siswa pada ujian ini? Seluruh jawaban dan nilai akan dihapus.">↺ Reset semua</button></form>
        <?php endif; ?>
    </div>
    <?php if (!$attemptRows): ?>
        <div class="empty"><strong>Belum ada siswa yang mengerjakan</strong>Daftar ini terisi otomatis setelah siswa membuka ujian. Jika siswa kehabisan waktu, barisnya akan muncul di sini dengan status <b>Waktu habis</b>.</div>
    <?php else: ?>
    <div class="table-wrap"><table class="data-table"><thead><tr><th>#</th><th>Siswa</th><th>Kelas</th><th>Status</th><th>Nilai</th><th>Mulai</th><th>Submit</th><th></th></tr></thead><tbody>
    <?php foreach ($attemptRows as $index => $row): $expired = attempt_is_expired($row, (int) $exam['duration_minutes']); $submitted = $row['status'] === 'submitted'; ?>
        <tr>
            <td><?= $index + 1 ?></td>
            <td><strong><?= e($row['name']) ?></strong><br><small class="muted">@<?= e($row['username']) ?></small></td>
            <td><?= e($row['class_name'] ?: '-') ?></td>
            <td><span class="badge <?= $submitted ? 'green' : ($expired ? 'red' : 'orange') ?>"><?= $submitted ? 'Selesai' : ($expired ? 'Waktu habis' : 'Sedang mengerjakan') ?></span><br><small class="muted"><?= e(attempt_time_label($row, (int) $exam['duration_minutes'])) ?></small></td>
            <td><?= $submitted ? '<strong>' . e(number_format((float) $row['score'], 2)) . '</strong>' : '-' ?></td>
            <td><small class="muted"><?= e(format_date($row['started_at'])) ?></small></td>
            <td><small class="muted"><?= e($row['submitted_at'] ? format_date($row['submitted_at']) : '-') ?></small></td>
            <td>
                <a class="button light small" href="?page=result&id=<?= (int) $row['attempt_id'] ?>">Lihat hasil</a>
                <form method="post" style="display:inline"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="reset_attempt"><input type="hidden" name="attempt_id" value="<?= (int) $row['attempt_id'] ?>"><input type="hidden" name="redirect" value="?page=exam_manage&id=<?= (int) $exam['id'] ?>#reset"><button class="button danger small" data-confirm="Reset ujian <?= e($row['name']) ?>? Pengerjaan dan nilai lama akan dihapus, lalu siswa dapat mengerjakan ulang dari awal.">↺ Reset</button></form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <p class="help">Setelah direset, baris siswa hilang dari daftar ini dan tombol <b>Mulai ujian →</b> muncul kembali di dashboard siswa. Durasi baru dihitung saat siswa membuka ujian, jadi reset dapat dilakukan kapan saja. Siswa yang belum pernah membuka ujian tidak perlu direset.</p>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php }, true); exit;
}

if ($page === 'question_edit') {
    Auth::requireRole(['guru','super_admin']);
    $questionId = (int) ($_GET['question_id'] ?? 0); $examId = (int) ($_GET['exam_id'] ?? 0);
    $stmt = $db->prepare('SELECT q.*,e.title exam_title FROM questions q JOIN exams e ON e.id=q.exam_id WHERE q.id=? AND q.exam_id=?'); $stmt->execute([$questionId, $examId]); $question = $stmt->fetch();
    if (!$question) { flash('error', 'Soal tidak ditemukan.'); redirect('?page=exam_manage&id=' . $examId); }
    $casesStmt = $db->prepare('SELECT * FROM test_cases WHERE question_id=? ORDER BY sort_order,id'); $casesStmt->execute([$questionId]); $cases = $casesStmt->fetchAll();
    layout('Edit Soal', function () use ($examId, $question, $cases) { ?>
        <div class="page-heading"><div><div class="eyebrow"><?= e($question['exam_title']) ?></div><h1>Edit soal coding</h1><p>Perbarui instruksi, kode awal, bobot, dan test case.</p></div><a class="button light" href="?page=exam_manage&id=<?= (int) $examId ?>">← Kembali</a></div>
        <div class="panel"><form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update_question"><input type="hidden" name="question_id" value="<?= (int) $question['id'] ?>"><input type="hidden" name="exam_id" value="<?= (int) $examId ?>"><div class="form-group"><label class="form-label">Pertanyaan / instruksi</label><textarea class="textarea" name="question_text" rows="8" required><?= e($question['question_text']) ?></textarea></div><div class="form-grid"><div class="form-group"><label class="form-label">Kode awal</label><textarea class="textarea" name="starter_code" style="min-height:180px;font-family:monospace"><?= e($question['starter_code']) ?></textarea></div><div><div class="form-group"><label class="form-label">Level soal</label><select class="select" name="difficulty"><option value="entry" <?= ($question['difficulty'] ?? 'middle') === 'entry' ? 'selected' : '' ?>>Entry</option><option value="middle" <?= ($question['difficulty'] ?? 'middle') === 'middle' ? 'selected' : '' ?>>Middle</option><option value="advanced" <?= ($question['difficulty'] ?? 'middle') === 'advanced' ? 'selected' : '' ?>>Advanced</option></select></div><div class="form-group"><label class="form-label">Bobot nilai</label><input class="input" type="number" name="points" min="1" value="<?= e($question['points']) ?>"></div><div class="form-group"><label class="form-label">Status penilaian</label><label style="display:flex;align-items:center;gap:8px;font-size:13px"><input type="checkbox" name="is_scored" value="1" <?= (int) ($question['is_scored'] ?? 1) === 1 ? 'checked' : '' ?>> Soal dinilai (hilangkan centang untuk soal contoh)</label><p class="help">Soal contoh tidak dihitung ke nilai akhir siswa.</p></div></div><h3 style="margin-top:25px">Test case</h3><p class="help" style="margin-bottom:14px">Test case pertama menjadi contoh publik untuk siswa.</p><div id="edit-cases"><?php foreach ($cases as $case): ?><div class="case-row" style="margin-bottom:10px"><input class="input" name="case_input[]" value="<?= e($case['input_data']) ?>" placeholder="Input"><input class="input" name="case_expected[]" value="<?= e($case['expected_output']) ?>" placeholder="Output yang diharapkan"></div><?php endforeach; ?><?php if (!$cases): ?><div class="case-row" style="margin-bottom:10px"><input class="input" name="case_input[]" placeholder="Input"><input class="input" name="case_expected[]" placeholder="Output yang diharapkan"></div><?php endif; ?></div><button type="button" class="button light small" onclick="document.querySelector('#edit-cases').insertAdjacentHTML('beforeend', '<div class=&quot;case-row&quot; style=&quot;margin-top:10px&quot;><input class=&quot;input&quot; name=&quot;case_input[]&quot; placeholder=&quot;Input&quot;><input class=&quot;input&quot; name=&quot;case_expected[]&quot; placeholder=&quot;Output yang diharapkan&quot;></div>')">＋ Tambah test case</button><div class="form-actions"><button class="button">Simpan perubahan</button></div></form></div>
    <?php }, true); exit;
}

if ($page === 'question') {
    Auth::requireRole(['guru','super_admin']); $examId=(int)($_GET['exam_id']??0); $stmt=$db->prepare('SELECT * FROM exams WHERE id=?');$stmt->execute([$examId]);$exam=$stmt->fetch(); if(!$exam) {flash('error','Ujian tidak ditemukan.');redirect('?page=exam_manage');} layout('Tambah Soal', function () use ($exam) { ?><div class="page-heading"><div><div class="eyebrow"><?= e($exam['title']) ?></div><h1>Tambah soal coding</h1><p>Tulis instruksi yang jelas dan siapkan input-output yang terukur.</p></div><a class="button light" href="?page=exam_manage&id=<?= (int)$exam['id'] ?>">← Kembali</a></div><div class="panel"><form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create_question"><input type="hidden" name="exam_id" value="<?= (int)$exam['id'] ?>"><div class="form-group"><label class="form-label">Pertanyaan / instruksi</label><textarea class="textarea" name="question_text" rows="6" required placeholder="Contoh: Buat program yang menerima sebuah bilangan bulat n lalu mencetak jumlah ..."></textarea></div><div class="form-grid"><div class="form-group"><label class="form-label">Kode awal (opsional)</label><textarea class="textarea" name="starter_code" style="min-height:150px;font-family:monospace" placeholder="# Tulis kode jawabanmu di sini"></textarea></div><div><div class="form-group"><label class="form-label">Level soal</label><select class="select" name="difficulty"><option value="entry">Entry</option><option value="middle" selected>Middle</option><option value="advanced">Advanced</option></select></div><div class="form-group"><label class="form-label">Bobot nilai</label><input class="input" type="number" name="points" min="1" value="100"><p class="help">Nilai akan dibagi proporsional berdasarkan test case yang lulus.</p></div><div class="form-group"><label class="form-label">Status penilaian</label><label style="display:flex;align-items:center;gap:8px;font-size:13px"><input type="checkbox" name="is_scored" value="1" checked> Soal dinilai (hilangkan centang untuk soal contoh)</label><p class="help">Soal contoh tidak dihitung ke nilai akhir siswa.</p></div></div></div><h3 style="margin-top:25px">Test case</h3><p class="help" style="margin-bottom:14px">Input dikirim ke stdin. Output siswa dibandingkan setelah spasi di ujung dan baris kosong dinormalisasi.</p><div id="cases"><div class="case-row" style="margin-bottom:10px"><input class="input" name="case_input[]" placeholder="Input contoh, mis. 5"><input class="input" name="case_expected[]" required placeholder="Output yang diharapkan, mis. 25"></div><div class="case-row" style="margin-bottom:10px"><input class="input" name="case_input[]" placeholder="Input test 2"><input class="input" name="case_expected[]" placeholder="Output yang diharapkan"></div></div><button type="button" class="button light small" onclick="document.querySelector('#cases').insertAdjacentHTML('beforeend', '<div class=&quot;case-row&quot; style=&quot;margin-top:10px&quot;><input class=&quot;input&quot; name=&quot;case_input[]&quot; placeholder=&quot;Input test&quot;><input class=&quot;input&quot; name=&quot;case_expected[]&quot; placeholder=&quot;Output yang diharapkan&quot;></div>')">＋ Tambah test case</button><div class="form-actions"><button class="button">Simpan soal</button></div></form></div><?php }, true); exit;
}

if ($page === 'import') {
    Auth::requireRole(['guru','super_admin']);
    $students = $db->query("SELECT id,name,class_name,username,is_active FROM users WHERE role='siswa' ORDER BY class_name IS NULL,class_name,name")->fetchAll();
    layout('Import Siswa', function () use ($students) { ?>
        <div class="page-heading"><div><div class="eyebrow">Manajemen siswa</div><h1>Import akun siswa</h1><p>Tambahkan banyak akun sekaligus dari file Excel.</p></div><div><a class="button secondary" href="<?= e(asset_url('template-import-siswa.xlsx')) ?>" download>⇩ Template Excel (.xlsx)</a> <a class="button light" href="<?= e(asset_url('template-import-siswa.csv')) ?>" download>⇩ Template CSV</a></div></div>
        <div class="grid-2"><div class="panel"><div class="panel-heading"><h2>Upload file</h2></div><form method="post" enctype="multipart/form-data"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="import_students"><div class="form-group"><label class="form-label">Kelas default <span class="muted">(opsional)</span></label><input class="input" name="class_name" placeholder="Contoh: X IPA 1"><p class="help">Dipakai untuk semua siswa jika file tidak memiliki kolom kelas.</p></div><div class="form-group"><label class="form-label">File CSV atau Excel (.xlsx)</label><input class="input" type="file" name="student_file" accept=".csv,.xlsx" required><p class="help">Maksimal mengikuti batas upload hosting.</p></div><button class="button">⇧ Import sekarang</button></form></div><div class="panel"><div class="panel-heading"><h2>Format kolom</h2></div><div class="import-note"><strong>Header wajib (baris pertama, jangan diubah):</strong><br><code>nama</code> &nbsp; <code>username</code> &nbsp; <code>password</code><br><strong>Header kelas opsional:</strong> <code>kelas</code><br><br>Contoh: <code>Budi Santoso | X IPA 1 | budi01 | Rahasia123</code><br><br>Jika username sudah ada, data siswa akan diperbarui dan password di-hash ulang.</div><div class="import-note" style="margin-top:12px"><strong>Aturan penulisan:</strong><br>1. Satu baris = satu siswa. Mulai data di baris ke-2.<br>2. Urutan kolom bebas, tapi nama header harus tetap sama.<br>3. <code>username</code> tanpa spasi dan unik (boleh NIS, mis. <code>1921</code>).<br>4. <code>password</code> minimal 8 karakter, tanpa spasi di awal/akhir.<br>5. Kolom <code>kelas</code> boleh dikosongkan &rarr; memakai <em>Kelas default</em> di atas.<br>6. Simpan sebagai <b>.xlsx</b> (sheet pertama) atau <b>.csv</b>. Jangan gabung sel, jangan pakai baris judul di atas header.<br>7. Format sel kolom <code>username</code>/<code>password</code> sebaiknya <b>Teks</b> agar angka seperti <code>1921</code> atau <code>0012</code> tidak berubah.</div></div></div>
        <div class="panel">
            <div class="panel-heading"><div><h2>Reset password siswa</h2><span class="help">Dipakai bila siswa lupa password atau tidak bisa login. Password disimpan sebagai hash baru dan akun otomatis diaktifkan.</span></div></div>
            <form method="post">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="reset_student_password">
                <div class="form-grid">
                    <div class="form-group"><label class="form-label">Pilih siswa</label>
                        <select class="select" name="user_id" required>
                            <option value="">— Pilih siswa —</option>
                            <?php foreach ($students as $student): ?><option value="<?= (int) $student['id'] ?>"><?= e($student['name']) ?> · @<?= e($student['username']) ?><?= $student['class_name'] ? ' · ' . e($student['class_name']) : '' ?><?= (int) $student['is_active'] === 1 ? '' : ' (nonaktif)' ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label">Password baru <span class="muted">(minimal 8 karakter)</span></label><input class="input" type="text" name="new_password" minlength="8" required placeholder="Contoh: BudiKka2026"><p class="help">Tampil sebagai teks agar mudah disalin dan diberikan ke siswa.</p></div>
                </div>
                <button class="button small" data-confirm="Reset password siswa terpilih?">Reset password</button>
            </form>
        </div>
    <?php }, true); exit;
}

if ($page === 'kartu_akun') {
    Auth::requireRole(['guru', 'super_admin']);
    $exportDir = dirname(__DIR__) . '/storage/exports';
    $file = basename((string) ($_GET['file'] ?? ''));
    // Unduh berkas PDF/CSV hasil generate kartu akun (hanya dari folder export).
    if ($file !== '') {
        $path = $exportDir . '/' . $file;
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!is_file($path) || !in_array($extension, ['pdf', 'csv'], true)) {
            flash('error', 'Berkas kartu akun tidak ditemukan.');
            redirect('?page=kartu_akun');
        }
        header('Content-Type: ' . ($extension === 'pdf' ? 'application/pdf' : 'text/csv; charset=utf-8'));
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }
    $files = [];
    foreach (is_dir($exportDir) ? scandir($exportDir) : [] as $entry) {
        $extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
        if (in_array($extension, ['pdf', 'csv'], true) && is_file($exportDir . '/' . $entry)) {
            $files[] = ['name' => $entry, 'type' => $extension, 'size' => filesize($exportDir . '/' . $entry), 'time' => filemtime($exportDir . '/' . $entry)];
        }
    }
    usort($files, static fn($a, $b) => $b['time'] <=> $a['time']);
    $classCounts = $db->query("SELECT COALESCE(NULLIF(TRIM(class_name),''),'(tanpa kelas)') class_label, COUNT(*) total FROM users WHERE role='siswa' GROUP BY class_label ORDER BY class_label")->fetchAll();
    layout('Kartu Akun Siswa', function () use ($files, $classCounts) { ?>
        <div class="page-heading"><div><div class="eyebrow">Manajemen siswa</div><h1>Kartu akun siswa</h1><p>Unduh kartu berisi username dan password untuk dibagikan ke siswa.</p></div></div>
        <div class="panel import-note">Berkas dibuat dengan perintah <code>python3 build_kartu.py</code> pada proyek <b>kartu-akun-siswa</b>. Setiap kali dijalankan, password siswa direset dan kartu baru dicetak — password lama tidak berlaku lagi. Halaman ini hanya menampilkan berkas di <code>storage/exports</code>, jadi tidak bisa diakses publik.</div>
        <div class="grid-2">
            <div class="panel"><div class="panel-heading"><div><h2>Berkas siap unduh</h2><span class="help">PDF berisi kartu 2 kolom × 4 baris per halaman A4, siap dipotong.</span></div></div>
                <?php if (!$files): ?><div class="empty"><strong>Belum ada berkas</strong>Jalankan <code>python3 build_kartu.py</code> untuk membuat kartu akun.</div><?php else: ?>
                <div class="table-wrap"><table class="data-table"><thead><tr><th>Berkas</th><th>Ukuran</th><th>Dibuat</th><th></th></tr></thead><tbody>
                <?php foreach ($files as $item): ?><tr><td><strong><?= e($item['name']) ?></strong><br><small class="muted"><?= strtoupper($item['type']) ?></small></td><td><?= e(number_format($item['size'] / 1024, 1)) ?> KB</td><td><small class="muted"><?= e(format_date(date('Y-m-d H:i:s', $item['time']))) ?></small></td><td><a class="button light small" href="?page=kartu_akun&file=<?= e(urlencode($item['name'])) ?>">⇩ Unduh</a></td></tr><?php endforeach; ?>
                </tbody></table></div>
                <?php endif; ?>
            </div>
            <div class="panel"><div class="panel-heading"><div><h2>Sebaran kelas</h2><span class="help">Jumlah siswa yang mendapat kartu.</span></div></div>
                <div class="table-wrap"><table class="data-table"><thead><tr><th>Kelas</th><th>Siswa</th></tr></thead><tbody>
                <?php foreach ($classCounts as $row): ?><tr><td><strong><?= e($row['class_label']) ?></strong></td><td><?= (int) $row['total'] ?> siswa</td></tr><?php endforeach; ?>
                <?php if (!$classCounts): ?><tr><td colspan="2" class="empty">Belum ada akun siswa.</td></tr><?php endif; ?>
                </tbody></table></div>
                <div class="import-note" style="margin-top:14px">Jangan bagikan berkas CSV/PDF ini ke grup umum. Bagikan kartu satu per satu sesuai nama siswa.</div>
            </div>
        </div>
    <?php }, true); exit;
}

if ($page === 'user_edit') {
    Auth::requireRole(['super_admin']); $userId = (int) ($_GET['id'] ?? 0); $stmt = $db->prepare('SELECT * FROM users WHERE id=?'); $stmt->execute([$userId]); $editUser = $stmt->fetch();
    if (!$editUser) { flash('error', 'Pengguna tidak ditemukan.'); redirect('?page=users'); }
    layout('Edit Pengguna', function () use ($editUser) { ?>
        <div class="page-heading"><div><div class="eyebrow">Super admin · Manajemen pengguna</div><h1>Edit pengguna</h1><p>Perbarui data akun <?= e($editUser['username']) ?>.</p></div><a class="button light" href="?page=users">← Kembali</a></div>
        <div class="panel" style="max-width:760px"><form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update_user"><input type="hidden" name="user_id" value="<?= (int) $editUser['id'] ?>"><div class="form-grid"><div class="form-group"><label class="form-label">Nama lengkap</label><input class="input" name="name" required value="<?= e($editUser['name']) ?>"></div><div class="form-group"><label class="form-label">Kelas <span class="muted">(untuk siswa)</span></label><input class="input" name="class_name" value="<?= e($editUser['class_name'] ?? '') ?>" placeholder="Contoh: X IPA 1"></div><div class="form-group"><label class="form-label">Username</label><input class="input" name="username" required value="<?= e($editUser['username']) ?>"></div><div class="form-group"><label class="form-label">Role</label><select class="select" name="role"><option value="siswa" <?= $editUser['role']==='siswa'?'selected':'' ?>>Siswa</option><option value="guru" <?= $editUser['role']==='guru'?'selected':'' ?>>Guru</option><option value="super_admin" <?= $editUser['role']==='super_admin'?'selected':'' ?>>Super Admin</option></select></div><div class="form-group full"><label class="form-label">Password baru <span class="muted">(kosongkan jika tidak diubah)</span></label><input class="input" name="password" type="password" minlength="8" placeholder="Masukkan password baru minimal 8 karakter"></div></div><div class="form-actions"><button class="button">Simpan perubahan</button></div></form></div>
    <?php }, true); exit;
}

if ($page === 'users') {
    Auth::requireRole(['super_admin']); $users = $db->query('SELECT * FROM users ORDER BY created_at DESC')->fetchAll();
    layout('Pengguna', function () use ($users) { ?>
        <div class="page-heading"><div><div class="eyebrow">Super admin</div><h1>Pengguna sistem</h1><p>Kelola akun siswa, guru, dan administrator.</p></div></div>
        <div class="grid-2"><div class="panel"><div class="panel-heading"><h2>Tambah pengguna</h2></div><form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create_user"><div class="form-group"><label class="form-label">Nama</label><input class="input" name="name" required></div><div class="form-group"><label class="form-label">Kelas <span class="muted">(untuk siswa)</span></label><input class="input" name="class_name" placeholder="Contoh: X IPA 1"></div><div class="form-group"><label class="form-label">Username</label><input class="input" name="username" required></div><div class="form-group"><label class="form-label">Password</label><input class="input" name="password" type="password" minlength="8" required></div><div class="form-group"><label class="form-label">Role</label><select class="select" name="role"><option value="siswa">Siswa</option><option value="guru">Guru</option><option value="super_admin">Super Admin</option></select></div><button class="button">Buat pengguna</button></form></div><div class="panel"><div class="panel-heading"><h2>Daftar akun</h2></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Nama</th><th>Kelas</th><th>Role</th><th>Status</th><th></th></tr></thead><tbody><?php foreach($users as $item): ?><tr><td><strong><?= e($item['name']) ?></strong><br><small class="muted">@<?= e($item['username']) ?></small></td><td><?= e($item['class_name'] ?: '-') ?></td><td><span class="badge gray"><?= e(role_label($item['role'])) ?></span></td><td><span class="badge <?= $item['is_active']?'green':'red' ?>"><?= $item['is_active']?'Aktif':'Nonaktif' ?></span></td><td><?php if($item['id'] !== Auth::user()['id']): ?><form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="toggle_user"><input type="hidden" name="user_id" value="<?= (int)$item['id'] ?>"><button class="button light small">Toggle</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></div></div>
    <?php }, true); exit;
}

if ($page === 'exam') {
    Auth::requireRole(['siswa']); $examId=(int)($_GET['id']??0); $stmt=$db->prepare("SELECT * FROM exams WHERE id=? AND status='published'");$stmt->execute([$examId]);$exam=$stmt->fetch(); if(!$exam){flash('error','Ujian tidak tersedia.');redirect('?page=dashboard');}
    // Ujian yang dibatasi hanya boleh dibuka oleh peserta yang ditentukan guru.
    if (!student_can_take_exam($db, $exam, (int) Auth::user()['id'])) { flash('error', 'Ujian ini hanya untuk peserta yang ditunjuk guru. Hubungi guru pengampu jika kamu merasa seharusnya terdaftar.'); redirect('?page=dashboard'); }
    $attemptStmt=$db->prepare('SELECT * FROM attempts WHERE exam_id=? AND user_id=?');$attemptStmt->execute([$examId,Auth::user()['id']]);$attempt=$attemptStmt->fetch(); if(!$attempt){$stmt=$db->prepare("INSERT INTO attempts(exam_id,user_id) VALUES(?,?)");$stmt->execute([$examId,Auth::user()['id']]);$attemptId=(int)$db->lastInsertId();$attemptStmt->execute([$examId,Auth::user()['id']]);$attempt=$attemptStmt->fetch();} if($attempt['status']==='submitted')redirect('?page=result&id='.$attempt['id']);
    $questions=$db->prepare('SELECT q.*, (SELECT COUNT(*) FROM test_cases tc WHERE tc.question_id=q.id AND tc.is_sample=1) sample_count, (SELECT tc.input_data FROM test_cases tc WHERE tc.question_id=q.id AND tc.is_sample=1 ORDER BY tc.sort_order,tc.id LIMIT 1) sample_input FROM questions q WHERE q.exam_id=? AND q.is_enabled=1 ORDER BY q.sort_order,q.id');$questions->execute([$examId]);$questions=$questions->fetchAll();$answers=$db->prepare('SELECT * FROM answers WHERE attempt_id=?');$answers->execute([$attempt['id']]);$saved=[];foreach($answers as $answer)$saved[$answer['question_id']]=$answer['code'];$endsAt=strtotime($attempt['started_at'])+((int)$exam['duration_minutes']*60);$exampleCount=count(array_filter($questions,static fn($q)=>(int)($q['is_scored']??1)!==1));
    // Waktu habis: siswa tidak dapat melanjutkan. Super admin dapat mereset ujian dari halaman kelola ujian.
    if (time() > $endsAt + 60) {
        layout('Waktu Habis', function () use ($exam) { ?>
            <div class="page-heading"><div><div class="eyebrow">Waktu pengerjaan habis</div><h1><?= e($exam['title']) ?></h1><p>Durasi ujian sudah berakhir sehingga jawaban tidak dapat dilanjutkan.</p></div><a class="button light" href="?page=dashboard">← Kembali ke dashboard</a></div>
            <div class="panel" style="max-width:720px"><div class="panel-heading"><div><h2>Waktu habis</h2><span class="help">Hubungi guru atau pengawas ujian.</span></div><span class="badge red">Waktu habis</span></div><p>Durasi ujian ini <b><?= e($exam['duration_minutes']) ?> menit</b> sudah terlewati, jadi halaman pengerjaan sudah ditutup. Jika kamu merasa berhak mengerjakan ulang, mintalah guru atau super admin untuk melakukan <b>reset ujian</b> agar waktu pengerjaan dimulai ulang dari nol.</p></div>
        <?php }, true); exit;
    }
    layout('Mengerjakan Ujian', function () use ($exam,$attempt,$questions,$saved,$endsAt,$exampleCount) { ?>
        <div class="page-heading"><div><div class="eyebrow">Sedang mengerjakan</div><h1><?= e($exam['title']) ?></h1><p><?= count($questions) ?> soal<?= $exampleCount ? ' (' . $exampleCount . ' soal contoh tidak dinilai)' : '' ?> · Pastikan semua jawaban sudah benar sebelum submit.</p></div><div class="stat-card" style="padding:10px 15px;text-align:center"><span class="label">Sisa waktu</span><div id="countdown" class="number" data-countdown="<?= $endsAt ?>" style="font-size:20px;margin:2px 0">--:--</div></div></div>
        <?php if (!$questions): ?><div class="panel empty"><strong>Soal belum tersedia</strong>Hubungi guru/pengawas ujian.</div><?php else: ?><form method="post" data-exam-form><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="submit_attempt"><input type="hidden" name="attempt_id" value="<?= (int)$attempt['id'] ?>"><?php foreach($questions as $i=>$question): ?><section class="editor-layout" style="margin-bottom:28px"><div class="question-box"><div class="eyebrow">Soal <?= $i+1 ?> · <?= (int) ($question['is_scored'] ?? 1) === 1 ? e($question['points']) . ' poin' : 'Contoh · tidak dinilai' ?></div><?php if ((int) ($question['is_scored'] ?? 1) !== 1): ?><span class="badge orange">Soal contoh</span><?php endif; ?><div class="question-text"><?= e($question['question_text']) ?></div><div class="test-result" style="margin-top:22px"><div class="panel-heading" style="margin-bottom:6px"><h3>Contoh input</h3><span class="badge gray">Test case publik</span></div><pre class="sample-input"><?= e(trim((string) ($question['sample_input'] ?? '')) !== '' ? $question['sample_input'] : 'Belum ada contoh input.') ?></pre><p class="help">Tulis <code>input()</code> tanpa teks prompt seperti <code>input("Masukkan nilai: ")</code>. Ketik nilai uji pada panel <strong>Input program (stdin)</strong> di bawah editor, lalu klik “Tes kode”.</p></div></div><div><div class="code-box"><div class="code-toolbar"><span><i class="code-dots"><i></i><i></i><i></i></i> &nbsp; solution.py</span><span>Python 3</span></div><textarea class="code-editor" name="code[<?= (int)$question['id'] ?>]" spellcheck="false"><?= e($saved[$question['id']] ?? $question['starter_code'] ?? '') ?></textarea><div style="display:flex;justify-content:flex-end;padding:0 14px 14px"><button type="button" class="button secondary small run-code" data-question="<?= (int)$question['id'] ?>">▶ Tes kode</button></div><div class="stdin-panel"><div class="stdin-head"><span class="stdin-label">Input program (stdin)</span><span class="stdin-hint">Satu nilai per baris</span></div><textarea class="stdin-input" rows="2" spellcheck="false" placeholder="Ketik nilai masukan di sini, satu per baris…"></textarea><div class="stdin-hint" style="margin-top:6px">Kosongkan kolom ini untuk memakai contoh input publik secara otomatis.</div></div></div><div class="test-result run-output" id="run-output-<?= (int)$question['id'] ?>" style="display:none"></div></div></section><?php endforeach; ?><div class="panel" style="display:flex;align-items:center;justify-content:space-between;gap:15px"><div><strong>Sudah yakin dengan jawabanmu?</strong><p style="margin:3px 0 0;font-size:12px">Setelah submit, jawaban akan dinilai dan tidak dapat diubah.</p></div><button class="button" data-confirm="Submit semua jawaban dan mulai penilaian?">Submit jawaban →</button></div></form><?php endif; ?>
    <?php }, true); exit;
}

if ($page === 'result') {
    Auth::requireLogin(); $attemptId=(int)($_GET['id']??0);$stmt=$db->prepare('SELECT a.*,e.title,e.description,e.duration_minutes,e.id exam_ref,u.name FROM attempts a JOIN exams e ON e.id=a.exam_id JOIN users u ON u.id=a.user_id WHERE a.id=?');$stmt->execute([$attemptId]);$attempt=$stmt->fetch();if(!$attempt|| (Auth::user()['role']==='siswa' && $attempt['user_id']!==Auth::user()['id'])){flash('error','Hasil tidak ditemukan.');redirect('?page=dashboard');}
    // Pengerjaan yang belum dikumpulkan (mis. waktu habis) belum punya nilai.
    // Tampilkan statusnya apa adanya dan sediakan tombol reset untuk super admin,
    // bukan dialihkan dengan pesan "sudah direset" yang membingungkan.
    if ($attempt['status'] !== 'submitted') {
        $expired = attempt_is_expired($attempt, (int) $attempt['duration_minutes']);
        $deadline = attempt_deadline($attempt, (int) $attempt['duration_minutes']);
        $savedStmt = $db->prepare('SELECT COUNT(*) FROM answers WHERE attempt_id=?'); $savedStmt->execute([$attemptId]); $savedCount = (int) $savedStmt->fetchColumn();
        $isSuperAdmin = Auth::user()['role'] === 'super_admin';
        layout('Pengerjaan Belum Selesai', function () use ($attempt, $expired, $deadline, $savedCount, $isSuperAdmin) { ?>
            <div class="page-heading">
                <div><div class="eyebrow">Belum dikumpulkan</div><h1><?= e($attempt['title']) ?></h1><p>Dikerjakan oleh <?= e($attempt['name']) ?> · mulai <?= e(format_date($attempt['started_at'])) ?></p></div>
                <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
                    <a class="button light" href="<?= $isSuperAdmin ? '?page=exam_manage&amp;id=' . (int) $attempt['exam_ref'] . '#reset' : '?page=dashboard' ?>">← <?= $isSuperAdmin ? 'Kelola ujian' : 'Kembali' ?></a>
                    <?php if ($isSuperAdmin): ?><form method="post" style="display:inline"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="reset_attempt"><input type="hidden" name="attempt_id" value="<?= (int) $attempt['id'] ?>"><input type="hidden" name="redirect" value="?page=exam_manage&id=<?= (int) $attempt['exam_ref'] ?>#reset"><button class="button danger" data-confirm="Reset ujian <?= e($attempt['name']) ?>? Data pengerjaan saat ini dihapus dan siswa dapat mengerjakan ulang dari awal.">↺ Reset &amp; ulang ujian</button></form><?php endif; ?>
                </div>
            </div>
            <div class="grid-2">
                <div class="panel" style="text-align:center">
                    <div class="eyebrow">Status pengerjaan</div>
                    <div class="score-big" style="font-size:34px"><?= $expired ? 'Waktu habis' : 'Sedang berjalan' ?></div>
                    <p>Belum ada nilai karena jawaban belum dikumpulkan.</p>
                    <div style="margin-top:20px"><span class="badge <?= $expired ? 'red' : 'orange' ?>"><?= $expired ? 'Waktu habis' : 'Belum dikumpulkan' ?></span></div>
                </div>
                <div class="panel">
                    <div class="panel-heading"><h2>Ringkasan</h2></div>
                    <div class="result-row"><span>Mulai dikerjakan</span><strong><?= e(format_date($attempt['started_at'])) ?></strong></div>
                    <div class="result-row"><span>Batas waktu</span><strong><?= e(format_date(date('Y-m-d H:i:s', $deadline))) ?></strong></div>
                    <div class="result-row"><span>Durasi ujian</span><strong><?= (int) $attempt['duration_minutes'] ?> menit</strong></div>
                    <div class="result-row"><span>Jawaban tersimpan</span><strong><?= $savedCount ?> soal</strong></div>
                </div>
            </div>
            <div class="panel">
                <div class="panel-heading"><div><h2><?= $expired ? 'Waktu pengerjaan habis' : 'Pengerjaan belum selesai' ?></h2><span class="help"><?= $expired ? 'Siswa tidak dapat melanjutkan ujian ini.' : 'Siswa masih dapat melanjutkan ujian ini.' ?></span></div></div>
                <p><?php if ($expired): ?>Durasi ujian sudah terlewati sehingga halaman pengerjaan ditutup dan jawaban tidak dapat dilanjutkan. <?= $isSuperAdmin ? 'Klik <b>Reset &amp; ulang ujian</b> agar siswa dapat mengerjakan ulang dari awal: pengerjaan lama dihapus dan waktu mulai dihitung lagi saat siswa membuka ujian.' : 'Mintalah guru atau super admin melakukan <b>reset ujian</b> agar kamu dapat mengerjakan ulang dari awal.' ?><?php else: ?>Pengerjaan ini masih berjalan dan belum dikumpulkan, jadi belum ada nilai. <?= $isSuperAdmin ? 'Gunakan tombol <b>Reset &amp; ulang ujian</b> bila siswa perlu mengulang dari awal.' : '<a href="?page=exam&amp;id=' . (int) $attempt['exam_id'] . '">Lanjutkan ujian →</a>' ?><?php endif; ?></p>
            </div>
        <?php }, true); exit;
    }
    $attemptExpired = attempt_time_label($attempt, (int) $attempt['duration_minutes']) === 'Selesai (waktu habis)';
    $answers=$db->prepare('SELECT a.*,q.question_text,q.points,q.is_scored FROM answers a JOIN questions q ON q.id=a.question_id WHERE a.attempt_id=? ORDER BY q.sort_order,q.id');$answers->execute([$attemptId]);$answers=$answers->fetchAll();$scoredCount=count(array_filter($answers,static fn($a)=>(int)($a['is_scored']??1)===1));$exampleCount=count($answers)-$scoredCount;
    layout('Hasil Ujian', function () use ($attempt,$answers,$scoredCount,$exampleCount,$attemptExpired) { ?><div class="page-heading"><div><div class="eyebrow">Hasil penilaian</div><h1><?= e($attempt['title']) ?></h1><p>Dikerjakan oleh <?= e($attempt['name']) ?> pada <?= e(format_date($attempt['submitted_at'])) ?><?= $attemptExpired ? ' · waktu habis sebelum submit' : '' ?>.</p></div><div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center"><a class="button light" href="?page=dashboard">← Kembali</a><?php if (Auth::user()['role'] === 'siswa'): ?><span class="help" style="display:inline-block;margin:0">Perlu mengulang ujian? Minta guru atau super admin melakukan reset.</span><?php endif; ?><?php if (Auth::user()['role'] === 'super_admin'): ?><form method="post" style="display:inline"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="reset_attempt"><input type="hidden" name="attempt_id" value="<?= (int) $attempt['id'] ?>"><input type="hidden" name="redirect" value="?page=exam_manage&id=<?= (int) $attempt['exam_ref'] ?>#reset"><button class="button danger" data-confirm="Reset ujian <?= e($attempt['name']) ?>? Jawaban dan nilai saat ini akan dihapus, lalu siswa dapat mengerjakan ulang dari awal.">↺ Reset &amp; ulang ujian</button></form><?php endif; ?></div></div><div class="grid-2"><div class="panel" style="text-align:center"><div class="eyebrow">Nilai akhir</div><div class="score-big"><?= e(number_format((float)$attempt['score'],2)) ?></div><p>Skala 0–100</p><div class="progress"><span style="width:<?= min(100,max(0,(float)$attempt['score'])) ?>%"></span></div><div style="margin-top:20px"><span class="badge <?= $attempt['score']>=75?'green':($attempt['score']>=60?'orange':'red') ?>"><?= $attempt['score']>=75?'Lulus':($attempt['score']>=60?'Perlu latihan':'Belum lulus') ?></span></div></div><div class="panel"><div class="panel-heading"><h2>Ringkasan</h2></div><div class="result-row"><span>Jumlah soal</span><strong><?= count($answers) ?></strong></div><?php if ($exampleCount): ?><div class="result-row"><span>Soal dinilai</span><strong><?= $scoredCount ?></strong></div><div class="result-row"><span>Soal contoh</span><strong><?= $exampleCount ?> · tidak dinilai</strong></div><?php endif; ?><div class="result-row"><span>Waktu submit</span><strong><?= e(format_date($attempt['submitted_at'])) ?></strong></div><div class="result-row"><span>Status</span><span class="badge <?= $attemptExpired ? 'orange' : 'green' ?>"><?= $attemptExpired ? 'Selesai · waktu habis' : 'Selesai' ?></span></div></div></div><div class="panel"><div class="panel-heading"><h2>Review jawaban</h2></div><?php foreach($answers as $i=>$answer):$results=json_decode($answer['test_results']??'[]',true)?:[];$passed=count(array_filter($results,static fn($r)=>!empty($r['passed']))); ?><div class="question-editor"><div style="display:flex;justify-content:space-between;gap:15px"><div><div class="eyebrow">Soal <?= $i+1 ?><?= (int) ($answer['is_scored'] ?? 1) === 1 ? '' : ' · contoh' ?></div><strong><?= e(mb_strimwidth($answer['question_text'],0,100,'…')) ?></strong></div><div style="text-align:right"><strong style="color:var(--purple)"><?= (int) ($answer['is_scored'] ?? 1) === 1 ? e($answer['score']) . ' / ' . e($answer['points']) : 'Tidak dinilai' ?></strong><br><small class="muted"><?= $passed ?>/<?= count($results) ?> test lulus</small></div></div><?php if($answer['ai_feedback']): ?><div class="import-note" style="margin-top:15px"><strong>Feedback AI</strong><br><?= nl2br(e($answer['ai_feedback'])) ?></div><?php endif; ?></div><?php endforeach; ?></div>
    <?php }, true); exit;
}

redirect('?page=dashboard');
