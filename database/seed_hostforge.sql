-- ============================================================
-- Direct Seed SQL for HostForge Database Console
-- ============================================================

INSERT INTO `users` (`id`, `username`, `email`, `first_name`, `last_name`, `password_hash`, `role`, `status`) VALUES
(1, 'scc.admin', 'pangilinanarisguillersaret@gmail.com', 'System', 'Admin', '$2y$10$1eWtd8e2yKk6dJ3t8dS61.d0JlRxu6BE6CWdTXMLWTjU9cM7b2Aj6', 'admin', 'Active'),
(2, 'ssc.officer', 'pangilinanarisguillersaret@gmail.com', 'SSC', 'Officer', '$2y$10$2fGrs.bDf48tITaJZhzdEeLv5O9sEN7KbD/B/CO9aT1iaCqLOTy1W', 'ssc', 'Active'),
(3, 'cssec.adviser', 'cssec@adviser.bcp.edu.ph', 'Alex', 'Reyes', '$2y$10$aGCq9K57fltmXNclRm70R.EKHl9fdczy5P9hz/ziU/dPcIWQ4UtjS', 'club_adviser', 'Active'),
(4, 'bsit.student', 'bsit@student.bcp.edu.ph', 'Juan', 'Santos', '$2y$10$NLObraSEveHCl5pDsYi8ZeQwQI9xqMaC85J4Hw4PgL9LW7yXQQMYC', 'student', 'Active')
ON DUPLICATE KEY UPDATE 
  `email` = VALUES(`email`),
  `password_hash` = VALUES(`password_hash`),
  `role` = VALUES(`role`),
  `status` = 'Active';

INSERT INTO `students` (`user_id`, `student_number`, `first_name`, `last_name`, `birthday`, `course`, `year_level`, `section`, `phone`, `status`) VALUES
(4, '2024-10001', 'Juan', 'Santos', '2004-01-01', 'Bachelor of Science in Information Technology', '2nd Year', 'IT-2A', '09123456789', 'Active')
ON DUPLICATE KEY UPDATE `status`='Active';
