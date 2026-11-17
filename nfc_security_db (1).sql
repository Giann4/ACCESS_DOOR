-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 17, 2026 at 07:49 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `nfc_security_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `access_logs`
--

CREATE TABLE `access_logs` (
  `id` int(11) NOT NULL,
  `student_id` int(11) DEFAULT NULL,
  `card_uid` varchar(100) NOT NULL,
  `room_name` varchar(100) NOT NULL,
  `tap_time` datetime NOT NULL DEFAULT current_timestamp(),
  `access_result` enum('granted','denied','unregistered') NOT NULL,
  `remarks` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `access_logs`
--

INSERT INTO `access_logs` (`id`, `student_id`, `card_uid`, `room_name`, `tap_time`, `access_result`, `remarks`) VALUES
(1, NULL, '04:A1:B2:C3:D4:56:80', '143', '2026-10-27 13:39:53', 'granted', 'SIMULATED TAP - software testing only'),
(2, NULL, '04:A1:B2:C3:D4:56:80', '143', '2026-10-27 13:55:03', 'granted', 'SIMULATED TAP - software testing only'),
(3, NULL, '04:A1:B2:C3:D4:56:80', '143', '2026-10-27 13:55:28', 'granted', 'SIMULATED TAP - software testing only'),
(4, NULL, '04:A1:B2:C3:D4:56:80', '143', '2026-10-27 14:05:51', 'granted', 'SIMULATED TAP - software testing only'),
(5, NULL, '04:A1:B2:C3:D4:56:80', '143', '2026-10-27 14:06:30', 'granted', 'SIMULATED TAP - software testing only'),
(6, NULL, '04:A1:B2:C3:D4:56:80', '143', '2026-10-27 14:10:21', 'granted', 'SIMULATED TAP - software testing only'),
(7, NULL, '04:A1:B2:C3:D4:56:81', '143', '2026-10-27 14:10:28', 'granted', 'SIMULATED TAP - software testing only'),
(8, NULL, '04:A1:B2:C3:D4:56:81', '143', '2026-10-27 15:02:06', 'granted', 'SIMULATED TAP - software testing only'),
(9, NULL, '04:A1:B2:C3:D4:56:81', '101', '2026-10-27 15:02:22', 'granted', 'SIMULATED TAP - software testing only'),
(10, NULL, '04:A1:B2:C3:D4:56:81', '101', '2026-10-27 15:33:02', 'granted', 'SIMULATED TAP - software testing only'),
(11, NULL, '04:A1:B2:C3:D4:56:81', '143', '2026-10-27 15:36:51', 'granted', 'SIMULATED TAP - software testing only'),
(12, NULL, '04:A1:B2:C3:D4:56:81', '143', '2026-10-27 16:06:58', 'granted', 'SIMULATED TAP - software testing only'),
(13, NULL, '04:A1:B2:C3:D4:56:81', '143', '2026-10-27 16:07:05', 'granted', 'SIMULATED TAP - software testing only'),
(14, NULL, '04:A1:B2:C3:D4:56:80', '143', '2026-10-27 16:09:31', 'granted', 'SIMULATED TAP - software testing only'),
(15, NULL, '04:A1:B2:C3:D4:56:80', '143', '2026-10-27 16:11:36', 'granted', 'SIMULATED TAP - software testing only'),
(16, NULL, '04:A1:B2:C3:D4:56:82', '143', '2026-10-27 16:11:42', 'granted', 'SIMULATED TAP - software testing only'),
(17, NULL, '04:A1:B2:C3:D4:56:83', '143', '2026-10-28 10:00:42', 'granted', 'SIMULATED TAP - software testing only'),
(18, NULL, '04:A1:B2:C3:D4:56:80', '143', '2026-10-28 11:36:24', 'granted', 'SIMULATED TAP - software testing only'),
(19, NULL, '04:A1:B2:C3:D4:56:80', '111', '2026-10-28 11:49:38', 'granted', 'SIMULATED TAP - software testing only'),
(20, NULL, '04:A1:B2:C3:D4:56:80', '111', '2026-10-28 11:59:07', 'granted', 'SIMULATED TAP - software testing only'),
(21, NULL, '04:A1:B2:C3:D4:56:83', '111', '2026-10-28 12:16:57', 'granted', 'SIMULATED TAP - software testing only'),
(22, NULL, '04:A1:B2:C3:D4:56:84', '111', '2026-10-29 14:34:07', 'granted', 'SIMULATED TAP - software testing only'),
(23, NULL, '04:A1:B2:C3:D4:56:84', '143', '2026-10-29 14:34:49', 'granted', 'SIMULATED TAP - software testing only'),
(24, NULL, '04:A1:B2:C3:D4:56:85', '111', '2026-10-30 15:05:28', 'granted', 'SIMULATED TAP - software testing only'),
(25, NULL, '04:A1:B2:C3:D4:56:85', '111', '2026-10-30 15:08:34', 'granted', 'SIMULATED TAP - software testing only'),
(26, NULL, '04:A1:B2:C3:D4:56:85', '111', '2026-10-30 15:19:08', 'granted', 'SIMULATED TAP - software testing only'),
(27, NULL, '04:A1:B2:C3:D4:56:85', '111', '2026-10-30 15:21:41', 'granted', 'SIMULATED TAP - software testing only'),
(28, NULL, '04:A1:B2:C3:D4:56:85', '111', '2026-10-30 15:21:51', 'granted', 'SIMULATED TAP - software testing only'),
(29, NULL, '04:A1:B2:C3:D4:56:85', '111', '2026-10-31 12:18:04', 'granted', 'SIMULATED TAP - software testing only'),
(30, 7, '04:A1:B2:C3:D4:56:85', '143', '2026-11-13 15:26:03', 'granted', 'SIMULATED TAP - software testing only'),
(31, 7, '04:A1:B2:C3:D4:56:85', '143', '2026-11-13 15:27:43', 'granted', 'SIMULATED TAP - software testing only'),
(32, 7, '04:A1:B2:C3:D4:56:85', '143', '2026-11-13 15:27:49', 'granted', 'SIMULATED TAP - software testing only'),
(33, 7, '04:A1:B2:C3:D4:56:85', '143', '2026-11-13 15:27:49', 'granted', 'SIMULATED TAP - software testing only'),
(34, 7, '04:A1:B2:C3:D4:56:85', '143', '2026-11-13 15:27:49', 'granted', 'SIMULATED TAP - software testing only'),
(35, 7, '04:A1:B2:C3:D4:56:85', '143', '2026-11-13 15:47:27', 'granted', 'SIMULATED TAP - software testing only'),
(36, 8, '04:A1:B2:C3:D4:56:81', '143', '2026-11-13 15:47:33', 'granted', 'SIMULATED TAP - software testing only'),
(37, 8, '04:A1:B2:C3:D4:56:81', '143', '2026-11-13 15:47:38', 'granted', 'SIMULATED TAP - software testing only'),
(38, 8, '04:A1:B2:C3:D4:56:81', '143', '2026-11-13 15:49:30', 'granted', 'SIMULATED TAP - software testing only'),
(39, 8, '04:A1:B2:C3:D4:56:81', '143', '2026-11-13 15:49:50', 'granted', 'SIMULATED TAP - software testing only'),
(40, 8, '04:A1:B2:C3:D4:56:81', '143', '2026-11-13 16:14:34', 'granted', 'SIMULATED TAP - software testing only'),
(41, 9, '04:A1:B2:C3:D4:56:82', 'COMLAB', '2026-11-13 16:14:43', 'granted', 'SIMULATED TAP - software testing only'),
(42, 9, '04:A1:B2:C3:D4:56:82', 'COMLAB', '2026-11-13 16:18:00', 'granted', 'SIMULATED TAP - software testing only'),
(43, 9, '04:A1:B2:C3:D4:56:82', 'COMLAB', '2026-11-13 16:18:06', 'granted', 'SIMULATED TAP - software testing only'),
(44, 9, '04:A1:B2:C3:D4:56:82', 'COMLAB', '2026-11-13 16:18:06', 'granted', 'SIMULATED TAP - software testing only'),
(45, 9, '04:A1:B2:C3:D4:56:82', 'COMLAB', '2026-11-13 16:18:06', 'granted', 'SIMULATED TAP - software testing only'),
(46, 9, '04:A1:B2:C3:D4:56:82', 'COMLAB', '2026-11-13 16:18:06', 'granted', 'SIMULATED TAP - software testing only'),
(47, 9, '04:A1:B2:C3:D4:56:82', 'COMLAB', '2026-11-13 16:18:06', 'granted', 'SIMULATED TAP - software testing only');

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

CREATE TABLE `attendance` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `schedule_id` int(11) NOT NULL,
  `class_date` date NOT NULL,
  `status` enum('present','late','absent') NOT NULL DEFAULT 'present',
  `first_tap_time` datetime DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `parent_email_sent` tinyint(1) NOT NULL DEFAULT 0,
  `tap_email_sent` tinyint(1) NOT NULL DEFAULT 0,
  `absence_email_sent` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `attendance`
--

INSERT INTO `attendance` (`id`, `student_id`, `schedule_id`, `class_date`, `status`, `first_tap_time`, `remarks`, `created_at`, `updated_at`, `parent_email_sent`, `tap_email_sent`, `absence_email_sent`) VALUES
(20, 7, 5, '2026-11-13', 'late', '2026-11-13 15:26:03', 'Recorded through simulated NFC tap', '2026-11-13 07:26:03', '2026-11-13 07:47:27', 1, 0, 0),
(21, 8, 5, '2026-11-13', 'absent', NULL, 'Automatically marked absent after absence-check time', '2026-11-13 07:43:34', '2026-11-13 08:14:34', 1, 0, 1),
(22, 9, 6, '2026-11-13', 'present', '2026-11-13 16:14:43', 'Recorded through simulated NFC tap', '2026-11-13 08:14:43', '2026-11-13 08:18:06', 1, 0, 0);

-- --------------------------------------------------------

--
-- Table structure for table `class_schedules`
--

CREATE TABLE `class_schedules` (
  `id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `schedule_day` enum('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `late_after` time NOT NULL,
  `absence_check_time` time NOT NULL,
  `room_name` varchar(100) NOT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `class_schedules`
--

INSERT INTO `class_schedules` (`id`, `subject_id`, `schedule_day`, `start_time`, `end_time`, `late_after`, `absence_check_time`, `room_name`, `status`, `created_at`) VALUES
(5, 4, 'Friday', '14:00:00', '17:00:00', '14:15:00', '14:30:00', '143', 'active', '2026-11-13 07:23:38'),
(6, 5, 'Friday', '16:00:00', '18:00:00', '16:15:00', '16:30:00', 'COMLAB', 'active', '2026-11-13 08:10:41');

-- --------------------------------------------------------

--
-- Table structure for table `email_notifications`
--

CREATE TABLE `email_notifications` (
  `id` int(11) NOT NULL,
  `attendance_id` int(11) NOT NULL,
  `recipient_email` varchar(150) NOT NULL,
  `notification_type` enum('arrival','absence') NOT NULL,
  `subject_line` varchar(255) NOT NULL,
  `message_body` text NOT NULL,
  `status` enum('pending','sent','failed') NOT NULL DEFAULT 'pending',
  `sent_at` datetime DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `nfc_cards`
--

CREATE TABLE `nfc_cards` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `card_uid` varchar(100) NOT NULL,
  `status` enum('active','inactive','lost') NOT NULL DEFAULT 'active',
  `registered_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `nfc_cards`
--

INSERT INTO `nfc_cards` (`id`, `student_id`, `card_uid`, `status`, `registered_at`) VALUES
(7, 7, '04:A1:B2:C3:D4:56:85', 'active', '2026-11-13 07:20:50'),
(8, 8, '04:A1:B2:C3:D4:56:81', 'active', '2026-11-13 07:41:09'),
(9, 9, '04:A1:B2:C3:D4:56:82', 'active', '2026-11-13 08:13:53');

-- --------------------------------------------------------

--
-- Table structure for table `schedule_students`
--

CREATE TABLE `schedule_students` (
  `id` int(11) NOT NULL,
  `schedule_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `enrolled_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `schedule_students`
--

INSERT INTO `schedule_students` (`id`, `schedule_id`, `student_id`, `enrolled_at`) VALUES
(15, 5, 7, '2026-11-13 07:24:28'),
(16, 5, 8, '2026-11-13 07:41:28'),
(17, 6, 9, '2026-11-13 08:14:19'),
(18, 6, 7, '2026-11-13 08:39:04');

-- --------------------------------------------------------

--
-- Table structure for table `students`
--

CREATE TABLE `students` (
  `id` int(11) NOT NULL,
  `student_number` varchar(50) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `grade_level` varchar(20) NOT NULL,
  `section` varchar(50) NOT NULL,
  `parent_name` varchar(150) DEFAULT NULL,
  `parent_email` varchar(150) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `students`
--

INSERT INTO `students` (`id`, `student_number`, `first_name`, `last_name`, `middle_name`, `grade_level`, `section`, `parent_name`, `parent_email`, `status`, `created_at`) VALUES
(7, '23-4908-01', 'Gian Benedict', 'Esio', 'Climet', 'Grade 12', 'STEM 12 -A', 'Genalyn', 'gianbenedictclimetesio@gmail.com', 'active', '2026-11-13 07:20:17'),
(8, '23-4877-01', 'Mark', 'Paredes', 'Isanan', 'Grade 12', 'STEM 12 -A', 'Eric Paredes', 'markparedes54321@gmail.com', 'active', '2026-11-13 07:39:46'),
(9, '23-4871-01', 'Gerald', 'Mamay', 'Traifalgar', 'Grade 12', 'STEM 12 -A', 'Rhoda Traifalgar', 'rfaller@spist.edu.ph', 'active', '2026-11-13 08:13:20');

-- --------------------------------------------------------

--
-- Table structure for table `subjects`
--

CREATE TABLE `subjects` (
  `id` int(11) NOT NULL,
  `subject_code` varchar(30) NOT NULL,
  `subject_name` varchar(150) NOT NULL,
  `grade_level` varchar(20) NOT NULL,
  `section` varchar(50) NOT NULL,
  `teacher_id` int(11) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `subjects`
--

INSERT INTO `subjects` (`id`, `subject_code`, `subject_name`, `grade_level`, `section`, `teacher_id`, `status`, `created_at`) VALUES
(4, 'NW2026', 'NETWORKING', 'Grade 12', 'STEM 12 -A', 4, 'active', '2026-11-13 07:19:09'),
(5, 'PROG101', 'Programming 1', 'Grade 12', 'STEM 12 -A', 5, 'active', '2026-11-13 08:09:16');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','teacher') NOT NULL DEFAULT 'teacher',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `full_name`, `email`, `password`, `role`, `status`, `created_at`) VALUES
(1, 'GianBenedictClimetEsio', 'gianbenedictclimetesio@gmail.com', '$2y$10$LEfWi4WgczFnZwEBE7m3xOBx0S87wOpUjINnNVWzIJc4XBlgBfyHy', 'admin', 'active', '2026-10-26 05:49:53'),
(2, 'Alette Manzanero', 'gianbenedictesio@gmail.com', '$2y$10$FL8Dsps1OuxOvoYRxA3O9OmPdOhbD/CwDCrKaPW7ax.FwYikG0uU2', 'teacher', 'active', '2026-10-26 07:45:17'),
(4, 'KENJI UY', 'markparedes54321@gmail.com', '$2y$10$VkBEuxuGxBxOXcDgJM/Ir.mrj7kKYy4So17rqMfsf2hrB6Qs1iohe', 'teacher', 'active', '2026-11-13 07:16:51'),
(5, 'Raymart Faller', 'rfaller@spist.edu.ph', '$2y$10$pd3efX1t7lD9ajVl71kYv./41UA1Ck4UjSuUUJ8cc9uGcFG6O6Te2', 'teacher', 'active', '2026-11-13 08:07:31');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `access_logs`
--
ALTER TABLE `access_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_access_student_time` (`student_id`,`tap_time`),
  ADD KEY `idx_access_tap_time` (`tap_time`);

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_student_schedule_date` (`student_id`,`schedule_id`,`class_date`),
  ADD KEY `fk_attendance_schedule` (`schedule_id`);

--
-- Indexes for table `class_schedules`
--
ALTER TABLE `class_schedules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_schedules_subject` (`subject_id`);

--
-- Indexes for table `email_notifications`
--
ALTER TABLE `email_notifications`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_attendance_notification` (`attendance_id`,`notification_type`);

--
-- Indexes for table `nfc_cards`
--
ALTER TABLE `nfc_cards`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `card_uid` (`card_uid`),
  ADD KEY `fk_nfc_cards_student` (`student_id`);

--
-- Indexes for table `schedule_students`
--
ALTER TABLE `schedule_students`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_schedule_student` (`schedule_id`,`student_id`),
  ADD KEY `fk_schedule_students_student` (`student_id`);

--
-- Indexes for table `students`
--
ALTER TABLE `students`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `student_number` (`student_number`);

--
-- Indexes for table `subjects`
--
ALTER TABLE `subjects`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `subject_code` (`subject_code`),
  ADD KEY `fk_subjects_teacher` (`teacher_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `access_logs`
--
ALTER TABLE `access_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=48;

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT for table `class_schedules`
--
ALTER TABLE `class_schedules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `email_notifications`
--
ALTER TABLE `email_notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=161;

--
-- AUTO_INCREMENT for table `nfc_cards`
--
ALTER TABLE `nfc_cards`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `schedule_students`
--
ALTER TABLE `schedule_students`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `students`
--
ALTER TABLE `students`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `subjects`
--
ALTER TABLE `subjects`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `access_logs`
--
ALTER TABLE `access_logs`
  ADD CONSTRAINT `fk_access_logs_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `attendance`
--
ALTER TABLE `attendance`
  ADD CONSTRAINT `fk_attendance_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `class_schedules` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_attendance_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `class_schedules`
--
ALTER TABLE `class_schedules`
  ADD CONSTRAINT `fk_schedules_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `email_notifications`
--
ALTER TABLE `email_notifications`
  ADD CONSTRAINT `fk_email_attendance` FOREIGN KEY (`attendance_id`) REFERENCES `attendance` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `nfc_cards`
--
ALTER TABLE `nfc_cards`
  ADD CONSTRAINT `fk_nfc_cards_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `schedule_students`
--
ALTER TABLE `schedule_students`
  ADD CONSTRAINT `fk_schedule_students_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `class_schedules` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_schedule_students_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `subjects`
--
ALTER TABLE `subjects`
  ADD CONSTRAINT `fk_subjects_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
