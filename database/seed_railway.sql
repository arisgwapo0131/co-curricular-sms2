-- ============================================================
-- Direct Seed SQL for Railway MySQL Database Console
-- Ensures sms_db exists and official accounts are provisioned
-- ============================================================

CREATE DATABASE IF NOT EXISTS `sms_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `sms_db`;

-- Core System Accounts
INSERT INTO `users` (`id`, `username`, `email`, `first_name`, `last_name`, `password_hash`, `role`, `status`) VALUES
(1, 'super.admin', 'pangilinanarisguillersaret@gmail.com', 'Super', 'Admin', '$2y$10$1eWtd8e2yKk6dJ3t8dS61.d0JlRxu6BE6CWdTXMLWTjU9cM7b2Aj6', 'super_admin', 'Active'),
(2, 'scc.admin', 'pangilinanarisguillersaret@gmail.com', 'System', 'Admin', '$2y$10$1eWtd8e2yKk6dJ3t8dS61.d0JlRxu6BE6CWdTXMLWTjU9cM7b2Aj6', 'admin', 'Active'),
(3, 'osa.staff', 'pangilinanarisguillersaret@gmail.com', 'OSA', 'Staff', '$2y$10$1eWtd8e2yKk6dJ3t8dS61.d0JlRxu6BE6CWdTXMLWTjU9cM7b2Aj6', 'staff', 'Active'),
(4, 'ssc.officer', 'pangilinanarisguillersaret@gmail.com', 'SSC', 'Officer', '$2y$10$2fGrs.bDf48tITaJZhzdEeLv5O9sEN7KbD/B/CO9aT1iaCqLOTy1W', 'ssc', 'Active'),
(5, 'cssec.adviser', 'cssec@adviser.bcp.edu.ph', 'Alex', 'Reyes', '$2y$10$aGCq9K57fltmXNclRm70R.EKHl9fdczy5P9hz/ziU/dPcIWQ4UtjS', 'club_adviser', 'Active'),
(6, 'bsit.student', 'bsit@student.bcp.edu.ph', 'Juan', 'Santos', '$2y$10$NLObraSEveHCl5pDsYi8ZeQwQI9xqMaC85J4Hw4PgL9LW7yXQQMYC', 'student', 'Active')
ON DUPLICATE KEY UPDATE 
  `email` = VALUES(`email`),
  `password_hash` = VALUES(`password_hash`),
  `role` = VALUES(`role`),
  `status` = 'Active';

INSERT INTO `students` (`user_id`, `student_number`, `first_name`, `last_name`, `birthday`, `course`, `year_level`, `section`, `phone`, `status`) VALUES
(6, '2024-10001', 'Juan', 'Santos', '2004-01-01', 'Bachelor of Science in Information Technology', '2nd Year', 'IT-2A', '09123456789', 'Active')
ON DUPLICATE KEY UPDATE `status`='Active';
