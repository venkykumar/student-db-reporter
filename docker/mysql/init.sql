CREATE DATABASE IF NOT EXISTS student_db;
USE student_db;

-- ----------------------------------------------------------------
-- Schema
-- ----------------------------------------------------------------

CREATE TABLE IF NOT EXISTS students (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  first_name      VARCHAR(50)  NOT NULL,
  last_name       VARCHAR(50)  NOT NULL,
  email           VARCHAR(100) NOT NULL UNIQUE,
  date_of_birth   DATE         NOT NULL,
  enrollment_date DATE         NOT NULL,
  gender          ENUM('male','female','other') NOT NULL
);

CREATE TABLE IF NOT EXISTS subjects (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name           VARCHAR(100) NOT NULL,
  code           VARCHAR(20)  NOT NULL UNIQUE,
  total_lessons  INT UNSIGNED NOT NULL,
  complexity     ENUM('easy','medium','hard') NOT NULL
);

CREATE TABLE IF NOT EXISTS grades (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id  INT UNSIGNED NOT NULL,
  subject_id  INT UNSIGNED NOT NULL,
  score       DECIMAL(5,2) NOT NULL,
  max_score   DECIMAL(5,2) NOT NULL DEFAULT 100.00,
  grade_date  DATE         NOT NULL,
  CONSTRAINT fk_grades_student FOREIGN KEY (student_id) REFERENCES students(id),
  CONSTRAINT fk_grades_subject FOREIGN KEY (subject_id) REFERENCES subjects(id)
);

CREATE TABLE IF NOT EXISTS student_subject_completion (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id        INT UNSIGNED NOT NULL,
  subject_id        INT UNSIGNED NOT NULL,
  status            ENUM('not_started','in_progress','completed') NOT NULL DEFAULT 'not_started',
  lessons_completed INT UNSIGNED NOT NULL DEFAULT 0,
  time_taken_hours  DECIMAL(6,2),
  started_at        DATETIME,
  completed_at      DATETIME,
  CONSTRAINT fk_ssc_student FOREIGN KEY (student_id) REFERENCES students(id),
  CONSTRAINT fk_ssc_subject FOREIGN KEY (subject_id) REFERENCES subjects(id),
  CONSTRAINT uq_ssc UNIQUE (student_id, subject_id)
);

CREATE INDEX idx_students_name ON students(last_name, first_name);

-- ----------------------------------------------------------------
-- Static seed: subjects
-- ----------------------------------------------------------------

INSERT INTO subjects (name, code, total_lessons, complexity) VALUES
  ('Mathematics',        'MATH101', 40, 'hard'),
  ('English Literature', 'ENG102',  30, 'medium'),
  ('Physics',            'PHY103',  35, 'hard'),
  ('History',            'HIS104',  25, 'easy'),
  ('Computer Science',   'CS105',   45, 'hard'),
  ('Biology',            'BIO106',  30, 'medium'),
  ('Chemistry',          'CHEM107', 35, 'hard'),
  ('Geography',          'GEO108',  20, 'easy'),
  ('Art & Design',       'ART109',  15, 'easy'),
  ('Physical Education', 'PE110',   20, 'medium');

-- ----------------------------------------------------------------
-- Procedural seed: students, grades, completion
-- ----------------------------------------------------------------

DELIMITER $$

CREATE PROCEDURE seed_data()
BEGIN
  DECLARE i        INT DEFAULT 1;
  DECLARE j        INT DEFAULT 1;
  DECLARE v_score  DECIMAL(5,2);
  DECLARE v_lessons INT;
  DECLARE v_total   INT;
  DECLARE v_status  VARCHAR(20);
  DECLARE v_dob     DATE;
  DECLARE v_enroll  DATE;
  DECLARE v_fname   VARCHAR(50);
  DECLARE v_lname   VARCHAR(50);

  WHILE i <= 100 DO
    SET v_dob    = DATE_SUB('2006-01-01', INTERVAL FLOOR(365 + RAND() * 2190) DAY);
    SET v_enroll = DATE_SUB(CURDATE(), INTERVAL FLOOR(30 + RAND() * 900) DAY);

    SET v_fname = ELT(1 + FLOOR(RAND() * 20),
      'Alice','Bob','Carlos','Diana','Eve','Frank','Grace','Henry',
      'Iris','Jack','Karen','Leo','Mia','Noah','Olivia','Paul',
      'Quinn','Rachel','Samuel','Tara');

    SET v_lname = ELT(1 + FLOOR(RAND() * 20),
      'Smith','Jones','Brown','Taylor','Wilson','Davies','Evans','Thomas',
      'Roberts','Walker','White','Hall','Green','Lewis','Harris','Clarke',
      'Wood','Moore','King','Scott');

    INSERT INTO students (first_name, last_name, email, date_of_birth, enrollment_date, gender)
    VALUES (
      v_fname,
      v_lname,
      CONCAT('student', i, '@example.com'),
      v_dob,
      v_enroll,
      ELT(1 + FLOOR(RAND() * 3), 'male', 'female', 'other')
    );

    SET j = 1;
    WHILE j <= 10 DO
      SET v_score = ROUND(40 + RAND() * 60, 2);

      SELECT total_lessons INTO v_total FROM subjects WHERE id = j;
      SET v_lessons = FLOOR(RAND() * (v_total + 1));

      SET v_status = ELT(1 + FLOOR(RAND() * 3), 'not_started', 'in_progress', 'completed');

      INSERT INTO grades (student_id, subject_id, score, max_score, grade_date)
      VALUES (
        i, j, v_score, 100.00,
        DATE_SUB(CURDATE(), INTERVAL FLOOR(RAND() * 365) DAY)
      );

      INSERT INTO student_subject_completion
        (student_id, subject_id, status, lessons_completed, time_taken_hours, started_at, completed_at)
      VALUES (
        i, j, v_status, v_lessons,
        IF(v_status != 'not_started', ROUND(v_lessons * (0.5 + RAND()), 1), NULL),
        IF(v_status != 'not_started', DATE_SUB(NOW(), INTERVAL FLOOR(RAND() * 180) DAY), NULL),
        IF(v_status = 'completed',    DATE_SUB(NOW(), INTERVAL FLOOR(RAND() * 90)  DAY), NULL)
      );

      SET j = j + 1;
    END WHILE;

    SET i = i + 1;
  END WHILE;
END$$

DELIMITER ;

CALL seed_data();
DROP PROCEDURE IF EXISTS seed_data;
