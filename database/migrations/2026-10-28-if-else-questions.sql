-- Tambahkan 3 soal logika dasar Python (if / elif / else) ke ujian id = 1.
-- Aman dijalankan berulang: soal dilewati jika judulnya sudah ada pada ujian tersebut.

USE ujian_python;

SET @exam_id = 1;

-- 1. Nilai terbesar dari dua bilangan
INSERT INTO questions (exam_id, question_text, starter_code, points, difficulty, is_enabled, sort_order)
SELECT @exam_id,
'Nilai terbesar dari dua bilangan

Buat program yang membaca dua bilangan bulat, masing-masing pada baris terpisah. Gunakan percabangan if dan else untuk mencetak bilangan yang lebih besar.

- Jika bilangan pertama lebih besar, cetak bilangan pertama.
- Selain itu, cetak bilangan kedua (termasuk saat keduanya sama).

Input berisi dua baris bilangan bulat.',
'a = int(input())
b = int(input())

# Tulis percabangan if dan else di sini

', 10, 'entry', 1, 17
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM questions WHERE exam_id = @exam_id AND question_text LIKE 'Nilai terbesar dari dua bilangan%'
);

SET @q1 = (SELECT id FROM questions WHERE exam_id = @exam_id AND question_text LIKE 'Nilai terbesar dari dua bilangan%' ORDER BY id LIMIT 1);

INSERT INTO test_cases (question_id, input_data, expected_output, is_sample, sort_order)
SELECT @q1, '7\n3', '7', 1, 0 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM test_cases WHERE question_id = @q1);
INSERT INTO test_cases (question_id, input_data, expected_output, is_sample, sort_order)
SELECT @q1, '3\n7', '7', 0, 1 FROM DUAL
WHERE (SELECT COUNT(*) FROM test_cases WHERE question_id = @q1) = 1;
INSERT INTO test_cases (question_id, input_data, expected_output, is_sample, sort_order)
SELECT @q1, '5\n5', '5', 0, 2 FROM DUAL
WHERE (SELECT COUNT(*) FROM test_cases WHERE question_id = @q1) = 2;

-- 2. Vokal atau konsonan
INSERT INTO questions (exam_id, question_text, starter_code, points, difficulty, is_enabled, sort_order)
SELECT @exam_id,
'Vokal atau konsonan

Buat program yang membaca satu huruf, lalu menentukan apakah huruf tersebut vokal atau konsonan. Gunakan percabangan if dan else.

- Jika huruf termasuk a, i, u, e, o (huruf besar maupun kecil), cetak `Vokal`.
- Selain itu, cetak `Konsonan`.

Input berisi satu huruf.',
'huruf = input()

# Tulis percabangan if dan else di sini

', 10, 'entry', 1, 18
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM questions WHERE exam_id = @exam_id AND question_text LIKE 'Vokal atau konsonan%'
);

SET @q2 = (SELECT id FROM questions WHERE exam_id = @exam_id AND question_text LIKE 'Vokal atau konsonan%' ORDER BY id LIMIT 1);

INSERT INTO test_cases (question_id, input_data, expected_output, is_sample, sort_order)
SELECT @q2, 'a', 'Vokal', 1, 0 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM test_cases WHERE question_id = @q2);
INSERT INTO test_cases (question_id, input_data, expected_output, is_sample, sort_order)
SELECT @q2, 'E', 'Vokal', 0, 1 FROM DUAL
WHERE (SELECT COUNT(*) FROM test_cases WHERE question_id = @q2) = 1;
INSERT INTO test_cases (question_id, input_data, expected_output, is_sample, sort_order)
SELECT @q2, 'k', 'Konsonan', 0, 2 FROM DUAL
WHERE (SELECT COUNT(*) FROM test_cases WHERE question_id = @q2) = 2;

-- 3. Tahun kabisat (if / elif / else bertingkat)
INSERT INTO questions (exam_id, question_text, starter_code, points, difficulty, is_enabled, sort_order)
SELECT @exam_id,
'Tahun kabisat

Buat program yang membaca satu tahun, lalu menentukan apakah tahun tersebut tahun kabisat. Gunakan percabangan if, elif, dan else.

- Tahun kabisat adalah tahun yang habis dibagi 400.
- Tahun yang habis dibagi 100 tetapi tidak habis dibagi 400 bukan tahun kabisat.
- Tahun yang habis dibagi 4 (dan bukan kasus di atas) adalah tahun kabisat.
- Selain itu bukan tahun kabisat.

Cetak `Kabisat` atau `Bukan kabisat`. Input berisi satu bilangan bulat tahun.',
'tahun = int(input())

# Tulis percabangan if, elif, dan else di sini

', 15, 'middle', 1, 19
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM questions WHERE exam_id = @exam_id AND question_text LIKE 'Tahun kabisat%'
);

SET @q3 = (SELECT id FROM questions WHERE exam_id = @exam_id AND question_text LIKE 'Tahun kabisat%' ORDER BY id LIMIT 1);

INSERT INTO test_cases (question_id, input_data, expected_output, is_sample, sort_order)
SELECT @q3, '2024', 'Kabisat', 1, 0 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM test_cases WHERE question_id = @q3);
INSERT INTO test_cases (question_id, input_data, expected_output, is_sample, sort_order)
SELECT @q3, '1900', 'Bukan kabisat', 0, 1 FROM DUAL
WHERE (SELECT COUNT(*) FROM test_cases WHERE question_id = @q3) = 1;
INSERT INTO test_cases (question_id, input_data, expected_output, is_sample, sort_order)
SELECT @q3, '2023', 'Bukan kabisat', 0, 2 FROM DUAL
WHERE (SELECT COUNT(*) FROM test_cases WHERE question_id = @q3) = 2;
