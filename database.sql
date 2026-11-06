CREATE DATABASE IF NOT EXISTS lms_walikelas
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE lms_walikelas;

CREATE TABLE IF NOT EXISTS teacher_profiles (
    id TINYINT UNSIGNED PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) DEFAULT NULL,
    teacher_name VARCHAR(120) NOT NULL,
    class_name VARCHAR(50) NOT NULL,
    school_name VARCHAR(150) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_single_teacher CHECK (id = 1)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS students (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nis VARCHAR(30) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL,
    gender ENUM('L', 'P') NOT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    parent_name VARCHAR(120) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS attendance (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    attendance_date DATE NOT NULL,
    status ENUM('Hadir', 'Terlambat', 'Izin', 'Sakit', 'Alpa') NOT NULL,
    notes VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_student_attendance (student_id, attendance_date),
    CONSTRAINT fk_attendance_student FOREIGN KEY (student_id)
        REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS grades (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    subject VARCHAR(100) NOT NULL,
    semester ENUM('Ganjil', 'Genap') NOT NULL,
    score DECIMAL(5, 2) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_student_subject_semester (student_id, subject, semester),
    CONSTRAINT fk_grades_student FOREIGN KEY (student_id)
        REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS announcements (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    body TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO teacher_profiles (id, username, teacher_name, class_name, school_name)
VALUES (1, 'agus.triyono', 'Agus Triyono', 'XII TKJ', 'SMKN 2 SELAYAR');
