-- Batasi peserta ujian: hanya siswa yang ditentukan (per kelas atau per siswa)
-- yang boleh melihat dan mengerjakan sebuah ujian.
--
-- restrict_participants = 0  -> semua siswa aktif dapat mengerjakan (perilaku lama)
-- restrict_participants = 1  -> hanya baris pada tabel exam_participants yang boleh
--                               mengerjakan ujian tersebut.

ALTER TABLE exams
  ADD COLUMN restrict_participants TINYINT(1) NOT NULL DEFAULT 0 AFTER status;

CREATE TABLE IF NOT EXISTS exam_participants (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  exam_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  source ENUM('manual','class','import') NOT NULL DEFAULT 'manual',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY one_participant (exam_id, user_id),
  KEY idx_exam_participants_exam (exam_id),
  FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
