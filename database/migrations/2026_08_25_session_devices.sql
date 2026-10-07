-- Add device model, client hints and hardware resolution columns to user_sessions
ALTER TABLE `user_sessions`
  ADD COLUMN `device_brand` VARCHAR(64) NULL AFTER `user_agent`,
  ADD COLUMN `device_model` VARCHAR(100) NULL AFTER `device_brand`,
  ADD COLUMN `device_code` VARCHAR(64) NULL AFTER `device_model`,
  ADD COLUMN `device_type` VARCHAR(32) NULL DEFAULT 'desktop' AFTER `device_code`,
  ADD COLUMN `os_name` VARCHAR(64) NULL AFTER `device_type`,
  ADD COLUMN `os_version` VARCHAR(32) NULL AFTER `os_name`,
  ADD COLUMN `browser_name` VARCHAR(64) NULL AFTER `os_version`,
  ADD COLUMN `browser_version` VARCHAR(32) NULL AFTER `browser_name`,
  ADD COLUMN `client_hints` TEXT NULL AFTER `browser_version`;
