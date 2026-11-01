-- Tambahkan level soal dan pilihan soal yang ditampilkan pada ujian.
ALTER TABLE questions
  ADD COLUMN difficulty ENUM('entry','middle','advanced') NOT NULL DEFAULT 'middle' AFTER points,
  ADD COLUMN is_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER difficulty;
