-- PBB Maestro fresh-install schema baseline.
-- Generated from release v1-1.0.0 current Laravel schema for MySQL/MariaDB targets.

SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(191) NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
  `email` varchar(191) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sessions` (
  `id` varchar(191) NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text,
  `payload` longtext NOT NULL,
  `last_activity` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cache` (
  `key` varchar(191) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cache_locks` (
  `key` varchar(191) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(191) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` tinyint unsigned NOT NULL,
  `reserved_at` int unsigned DEFAULT NULL,
  `available_at` int unsigned NOT NULL,
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `job_batches` (
  `id` varchar(191) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int NOT NULL,
  `pending_jobs` int NOT NULL,
  `failed_jobs` int NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext,
  `cancelled_at` int DEFAULT NULL,
  `created_at` int NOT NULL,
  `finished_at` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `failed_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(191) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `maestro_applications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `app_code` varchar(191) NOT NULL,
  `display_name` varchar(255) NOT NULL,
  `environment` varchar(255) NOT NULL DEFAULT 'local',
  `base_url` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `meta_json` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `maestro_applications_app_code_unique` (`app_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `maestro_telemetry_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `maestro_application_id` bigint unsigned NOT NULL,
  `label` varchar(255) NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `maestro_telemetry_tokens_token_hash_unique` (`token_hash`),
  KEY `maestro_telemetry_tokens_maestro_application_id_foreign` (`maestro_application_id`),
  CONSTRAINT `maestro_telemetry_tokens_maestro_application_id_foreign` FOREIGN KEY (`maestro_application_id`) REFERENCES `maestro_applications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `maestro_workers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `maestro_application_id` bigint unsigned NOT NULL,
  `worker_id` varchar(191) NOT NULL,
  `host_name` varchar(255) DEFAULT NULL,
  `queue_name` varchar(191) DEFAULT NULL,
  `process_id` bigint unsigned DEFAULT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'starting',
  `started_at` timestamp NULL DEFAULT NULL,
  `last_heartbeat_at` timestamp NULL DEFAULT NULL,
  `last_job_started_at` timestamp NULL DEFAULT NULL,
  `last_job_finished_at` timestamp NULL DEFAULT NULL,
  `current_job_type` varchar(255) DEFAULT NULL,
  `current_job_id` varchar(255) DEFAULT NULL,
  `processed_count` bigint unsigned NOT NULL DEFAULT 0,
  `failed_count` bigint unsigned NOT NULL DEFAULT 0,
  `memory_mb` decimal(10,2) DEFAULT NULL,
  `stopped_at` timestamp NULL DEFAULT NULL,
  `meta_json` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `maestro_workers_worker_id_unique` (`worker_id`),
  KEY `maestro_workers_maestro_application_id_foreign` (`maestro_application_id`),
  KEY `maestro_workers_queue_name_index` (`queue_name`),
  KEY `maestro_workers_status_index` (`status`),
  KEY `maestro_workers_last_heartbeat_at_index` (`last_heartbeat_at`),
  CONSTRAINT `maestro_workers_maestro_application_id_foreign` FOREIGN KEY (`maestro_application_id`) REFERENCES `maestro_applications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `maestro_worker_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `maestro_worker_id` bigint unsigned NOT NULL,
  `event_id` char(36) NOT NULL,
  `worker_id` varchar(191) NOT NULL,
  `event_type` varchar(191) NOT NULL,
  `queue_name` varchar(191) DEFAULT NULL,
  `job_type` varchar(255) DEFAULT NULL,
  `job_id` varchar(255) DEFAULT NULL,
  `outcome` varchar(255) DEFAULT NULL,
  `notes` text,
  `payload_json` json DEFAULT NULL,
  `occurred_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `maestro_worker_events_event_id_unique` (`event_id`),
  KEY `maestro_worker_events_maestro_worker_id_foreign` (`maestro_worker_id`),
  KEY `maestro_worker_events_worker_id_index` (`worker_id`),
  KEY `maestro_worker_events_event_type_index` (`event_type`),
  KEY `maestro_worker_events_queue_name_index` (`queue_name`),
  KEY `maestro_worker_events_occurred_at_index` (`occurred_at`),
  CONSTRAINT `maestro_worker_events_maestro_worker_id_foreign` FOREIGN KEY (`maestro_worker_id`) REFERENCES `maestro_workers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '0001_01_01_000000_create_users_table', 1
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '0001_01_01_000000_create_users_table');
INSERT INTO `migrations` (`migration`, `batch`)
SELECT '0001_01_01_000001_create_cache_table', 1
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '0001_01_01_000001_create_cache_table');
INSERT INTO `migrations` (`migration`, `batch`)
SELECT '0001_01_01_000002_create_jobs_table', 1
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '0001_01_01_000002_create_jobs_table');
INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_03_17_040500_create_maestro_applications_table', 1
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_03_17_040500_create_maestro_applications_table');
INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_03_17_040510_create_maestro_telemetry_tokens_table', 1
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_03_17_040510_create_maestro_telemetry_tokens_table');
INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_03_17_040520_create_maestro_workers_table', 1
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_03_17_040520_create_maestro_workers_table');
INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_03_17_040530_create_maestro_worker_events_table', 1
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_03_17_040530_create_maestro_worker_events_table');

SET FOREIGN_KEY_CHECKS=1;
