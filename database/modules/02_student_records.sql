-- ============================================================
-- Component: Student Records & Academic Mapping
-- File: 02_student_records.sql
-- Description: Student profiles linked with institutional user accounts
-- ============================================================

CREATE TABLE IF NOT EXISTS `students` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT(10) UNSIGNED DEFAULT NULL,
  `student_number` VARCHAR(50) DEFAULT NULL,
  `first_name` VARCHAR(100) NOT NULL,
  `last_name` VARCHAR(100) NOT NULL,
  `birthday` DATE NOT NULL,
  `course` VARCHAR(150) NOT NULL,
  `year_level` VARCHAR(50) NOT NULL,
  `section` VARCHAR(50) NOT NULL,
  `phone` VARCHAR(20) NOT NULL,
  `status` ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_students_user` (`user_id`),
  KEY `idx_students_number` (`student_number`),
  CONSTRAINT `fk_students_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Official Program Students (from SYSTEM_ACCOUNTS.md)
INSERT INTO `students` (`id`, `user_id`, `student_number`, `first_name`, `last_name`, `birthday`, `course`, `year_level`, `section`, `phone`, `status`) VALUES
(22, 1, '2024-10001', 'Juan', 'Santos', '2004-01-01', 'Bachelor of Science in Information Technology', '2nd Year', 'IT-2A', '', 'Active'),
(23, 2, '2024-10002', 'Maria', 'Cruz', '2004-01-01', 'Bachelor of Science in Hospitality Management', '1st Year', 'HM-1B', '', 'Active'),
(24, 3, '2024-10003', 'Jose', 'Reyes', '2004-01-01', 'Bachelor of Science in Accounting Information System', '3rd Year', 'AIS-3A', '', 'Active'),
(25, 4, '2024-10004', 'Ana', 'Dela Cruz', '2004-01-01', 'Bachelor of Science in Tourism Management', '2nd Year', 'TM-2C', '', 'Active'),
(26, 5, '2024-10005', 'Carlos', 'Garcia', '2004-01-01', 'Bachelor of Science in Office Administration', '1st Year', 'OA-1A', '', 'Active'),
(27, 6, '2024-10006', 'Liza', 'Ramos', '2004-01-01', 'Bachelor of Science in Entrepreneurship', '3rd Year', 'ENT-3B', '', 'Active'),
(28, 7, '2024-10007', 'Ramon', 'Villanueva', '2004-01-01', 'Bachelor of Science in Business Administration', '2nd Year', 'BA-2A', '', 'Active'),
(29, 8, '2024-10008', 'Patricia', 'Aquino', '2004-01-01', 'Bachelor of Science in Information Science', '1st Year', 'IS-1A', '', 'Active'),
(30, 9, '2024-10009', 'Mark', 'Bautista', '2004-01-01', 'Bachelor of Science in Computer Engineering', '3rd Year', 'CPE-3A', '', 'Active'),
(31, 10, '2024-10010', 'Jenny', 'Navarro', '2004-01-01', 'Bachelor of Science in Psychology', '2nd Year', 'PSY-2B', '', 'Active'),
(32, 11, '2024-10011', 'Rico', 'Fernandez', '2004-01-01', 'Bachelor of Science in Criminology', '4th Year', 'CRIM-4A', '', 'Active'),
(33, 12, '2024-10012', 'Sheila', 'Santos', '2004-01-01', 'Bachelor of Science in Physical Education', '2nd Year', 'PE-2A', '', 'Active'),
(34, 13, '2024-10013', 'Angelo', 'Torres', '2004-01-01', 'Technological and Livelihood Education', '1st Year', 'TLE-1B', '', 'Active'),
(35, 14, '2024-10014', 'Claire', 'Mendoza', '2004-01-01', 'Bachelor of Science in Elementary Education', '3rd Year', 'ELED-3A', '', 'Active'),
(36, 15, '2024-10015', 'Danilo', 'Pascual', '2004-01-01', 'Bachelor of Science in Secondary Education', '2nd Year', 'SEED-2C', '', 'Active'),
(37, 16, '2024-10016', 'Rowena', 'Espinosa', '2004-01-01', 'Bachelor of Science in Library Information Science', '3rd Year', 'LIS-3A', '', 'Active'),
(38, NULL, '2024-10017', 'Brian Joshua', 'Tanael', '2004-01-01', 'Bachelor of Science in Information Technology', '3rd Year', 'IT-3A', '', 'Active')
ON DUPLICATE KEY UPDATE `user_id`=VALUES(`user_id`), `student_number`=VALUES(`student_number`), `course`=VALUES(`course`), `year_level`=VALUES(`year_level`), `section`=VALUES(`section`);

