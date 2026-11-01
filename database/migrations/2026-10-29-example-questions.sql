-- Soal contoh (tidak dinilai) pada ujian id = 1.
-- Jalankan SETELAH 2026-10-28-if-else-questions.sql.
--
-- 1) Menambah kolom is_scored: 1 = soal dinilai, 0 = soal contoh (tidak diambil nilai).
--    Jika kolom sudah ada, lewati perintah ALTER di bawah.
-- 2) Menambah 3 soal contoh: 1 soal grade nilai + 2 soal dengan pola serupa.
--    Aman dijalankan berulang: soal dilewati jika judulnya sudah ada pada ujian tersebut.

USE ujian_python;

SET @has_is_scored = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'questions' AND COLUMN_NAME = 'is_scored'
);
SET @sql = IF(@has_is_scored = 0,
  'ALTER TABLE questions ADD COLUMN is_scored TINYINT(1) NOT NULL DEFAULT 1 AFTER is_enabled',
  'SELECT "Kolom is_scored sudah ada" AS info');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exam_id = 1;

-- Contoh 1. Grade nilai ujian (pola if / elif / else dengan ambang 90 dan 80)
INSERT INTO questions (exam_id, question_text, starter_code, points, difficulty, is_scored, is_enabled, sort_order)
SELECT @exam_id,
'Contoh: Grade nilai ujian

Soal contoh, tidak diambil nilai. Perhatikan pola if, elif, dan else di bawah, lalu coba ubah nilainya pada tombol “Tes kode”.

Buat program yang membaca satu nilai ujian berupa bilangan bulat, kemudian mencetak grade sesuai aturan:

- Jika score >= 90, cetak `Grade: A`.
- Jika score >= 80 tetapi kurang dari 90, cetak `Grade: B`.
- Selain itu, cetak `Study harder!`.

Input hanya berisi satu nilai.',
'score = int(input())

if score >= 90:
    print("Grade: A")
elif score >= 80:
    print("Grade: B")
else:
    print("Study harder!")
', 0, 'entry', 0, 1, -3
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM questions WHERE exam_id = @exam_id AND question_text LIKE 'Contoh: Grade nilai ujian%'
);

SET @qc1 = (SELECT id FROM questions WHERE exam_id = @exam_id AND question_text LIKE 'Contoh: Grade nilai ujian%' ORDER BY id LIMIT 1);

INSERT INTO test_cases (question_id, input_data, expected_output, is_sample, sort_order)
SELECT @qc1, '95', 'Grade: A', 1, 0 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM test_cases WHERE question_id = @qc1);
INSERT INTO test_cases (question_id, input_data, expected_output, is_sample, sort_order)
SELECT @qc1, '85', 'Grade: B', 0, 1 FROM DUAL
WHERE (SELECT COUNT(*) FROM test_cases WHERE question_id = @qc1) = 1;
INSERT INTO test_cases (question_id, input_data, expected_output, is_sample, sort_order)
SELECT @qc1, '70', 'Study harder!', 0, 2 FROM DUAL
WHERE (SELECT COUNT(*) FROM test_cases WHERE question_id = @qc1) = 2;

-- Contoh 2. Kategori nilai huruf (pola serupa, ambang 85 dan 70)
INSERT INTO questions (exam_id, question_text, starter_code, points, difficulty, is_scored, is_enabled, sort_order)
SELECT @exam_id,
'Contoh: Kategori nilai huruf

Soal contoh, tidak diambil nilai. Pola if, elif, dan else sama seperti contoh sebelumnya, hanya ambang batasnya berbeda.

Buat program yang membaca satu nilai ujian berupa bilangan bulat, kemudian mencetak kategori nilai:

- Jika nilai >= 85, cetak `Nilai: A`.
- Jika nilai >= 70 tetapi kurang dari 85, cetak `Nilai: B`.
- Selain itu, cetak `Nilai: C`.

Input hanya berisi satu nilai.',
'nilai = int(input())

if nilai >= 85:
    print("Nilai: A")
elif nilai >= 70:
    print("Nilai: B")
else:
    print("Nilai: C")
', 0, 'entry', 0, 1, -2
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM questions WHERE exam_id = @exam_id AND question_text LIKE 'Contoh: Kategori nilai huruf%'
);

SET @qc2 = (SELECT id FROM questions WHERE exam_id = @exam_id AND question_text LIKE 'Contoh: Kategori nilai huruf%' ORDER BY id LIMIT 1);

INSERT INTO test_cases (question_id, input_data, expected_output, is_sample, sort_order)
SELECT @qc2, '90', 'Nilai: A', 1, 0 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM test_cases WHERE question_id = @qc2);
INSERT INTO test_cases (question_id, input_data, expected_output, is_sample, sort_order)
SELECT @qc2, '75', 'Nilai: B', 0, 1 FROM DUAL
WHERE (SELECT COUNT(*) FROM test_cases WHERE question_id = @qc2) = 1;
INSERT INTO test_cases (question_id, input_data, expected_output, is_sample, sort_order)
SELECT @qc2, '60', 'Nilai: C', 0, 2 FROM DUAL
WHERE (SELECT COUNT(*) FROM test_cases WHERE question_id = @qc2) = 2;

-- Contoh 3. Kategori usia (tiga cabang elif bertingkat)
INSERT INTO questions (exam_id, question_text, starter_code, points, difficulty, is_scored, is_enabled, sort_order)
SELECT @exam_id,
'Contoh: Kategori usia

Soal contoh, tidak diambil nilai. Perhatikan urutan pengecekan pada elif: kondisi terbesar diperiksa lebih dulu.

Buat program yang membaca satu usia berupa bilangan bulat, kemudian mencetak kategori usia:

- Jika usia >= 60, cetak `Lansia`.
- Jika usia >= 18 tetapi kurang dari 60, cetak `Dewasa`.
- Selain itu, cetak `Anak-anak`.

Input hanya berisi satu bilangan bulat usia.',
'usia = int(input())

if usia >= 60:
    print("Lansia")
elif usia >= 18:
    print("Dewasa")
else:
    print("Anak-anak")
', 0, 'entry', 0, 1, -1
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM questions WHERE exam_id = @exam_id AND question_text LIKE 'Contoh: Kategori usia%'
);

SET @qc3 = (SELECT id FROM questions WHERE exam_id = @exam_id AND question_text LIKE 'Contoh: Kategori usia%' ORDER BY id LIMIT 1);

INSERT INTO test_cases (question_id, input_data, expected_output, is_sample, sort_order)
SELECT @qc3, '65', 'Lansia', 1, 0 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM test_cases WHERE question_id = @qc3);
INSERT INTO test_cases (question_id, input_data, expected_output, is_sample, sort_order)
SELECT @qc3, '20', 'Dewasa', 0, 1 FROM DUAL
WHERE (SELECT COUNT(*) FROM test_cases WHERE question_id = @qc3) = 1;
INSERT INTO test_cases (question_id, input_data, expected_output, is_sample, sort_order)
SELECT @qc3, '10', 'Anak-anak', 0, 2 FROM DUAL
WHERE (SELECT COUNT(*) FROM test_cases WHERE question_id = @qc3) = 2;

-- Soal dinilai dengan pola serupa (ambang bertingkat), dikerjakan siswa.
-- 4. Level stok gudang
INSERT INTO questions (exam_id, question_text, starter_code, points, difficulty, is_scored, is_enabled, sort_order)
SELECT @exam_id,
'Level stok gudang

Buat program yang membaca jumlah stok barang berupa bilangan bulat. Gunakan percabangan if, elif, dan else untuk mencetak status stok.

- Jika stok >= 100, cetak `Stok aman`.
- Jika stok >= 50 tetapi kurang dari 100, cetak `Stok menipis`.
- Selain itu, cetak `Segera restock`.

Input hanya berisi satu bilangan bulat.',
'stok = int(input())

# Tulis percabangan if, elif, dan else di sini

', 10, 'entry', 1, 1, 20
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM questions WHERE exam_id = @exam_id AND question_text LIKE 'Level stok gudang%'
);

SET @qs1 = (SELECT id FROM questions WHERE exam_id = @exam_id AND question_text LIKE 'Level stok gudang%' ORDER BY id LIMIT 1);

INSERT INTO test_cases (question_id, input_data, expected_output, is_sample, sort_order)
SELECT @qs1, '120', 'Stok aman', 1, 0 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM test_cases WHERE question_id = @qs1);
INSERT INTO test_cases (question_id, input_data, expected_output, is_sample, sort_order)
SELECT @qs1, '60', 'Stok menipis', 0, 1 FROM DUAL
WHERE (SELECT COUNT(*) FROM test_cases WHERE question_id = @qs1) = 1;
INSERT INTO test_cases (question_id, input_data, expected_output, is_sample, sort_order)
SELECT @qs1, '30', 'Segera restock', 0, 2 FROM DUAL
WHERE (SELECT COUNT(*) FROM test_cases WHERE question_id = @qs1) = 2;

-- 5. Kategori kecepatan angin
INSERT INTO questions (exam_id, question_text, starter_code, points, difficulty, is_scored, is_enabled, sort_order)
SELECT @exam_id,
'Kategori kecepatan angin

Buat program yang membaca kecepatan angin dalam km/jam berupa bilangan bulat. Gunakan percabangan if, elif, dan else.

- Jika kecepatan >= 60, cetak `Angin kencang`.
- Jika kecepatan >= 30 tetapi kurang dari 60, cetak `Angin sedang`.
- Selain itu, cetak `Angin lemah`.

Input hanya berisi satu bilangan bulat.',
'kecepatan = int(input())

# Tulis percabangan if, elif, dan else di sini

', 10, 'entry', 1, 1, 21
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM questions WHERE exam_id = @exam_id AND question_text LIKE 'Kategori kecepatan angin%'
);

SET @qs2 = (SELECT id FROM questions WHERE exam_id = @exam_id AND question_text LIKE 'Kategori kecepatan angin%' ORDER BY id LIMIT 1);

INSERT INTO test_cases (question_id, input_data, expected_output, is_sample, sort_order)
SELECT @qs2, '75', 'Angin kencang', 1, 0 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM test_cases WHERE question_id = @qs2);
INSERT INTO test_cases (question_id, input_data, expected_output, is_sample, sort_order)
SELECT @qs2, '45', 'Angin sedang', 0, 1 FROM DUAL
WHERE (SELECT COUNT(*) FROM test_cases WHERE question_id = @qs2) = 1;
INSERT INTO test_cases (question_id, input_data, expected_output, is_sample, sort_order)
SELECT @qs2, '10', 'Angin lemah', 0, 2 FROM DUAL
WHERE (SELECT COUNT(*) FROM test_cases WHERE question_id = @qs2) = 2;
