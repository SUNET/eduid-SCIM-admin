CREATE TABLE `invites` (
	`id` int(10) unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `instance` varchar(20) DEFAULT NULL,
  `hash` varchar(65) DEFAULT NULL,
  `status` tinyint unsigned,
  `session` varchar(27) DEFAULT NULL,
  `modified` datetime DEFAULT NULL,
  `attributes` text DEFAULT NULL,
  `inviteInfo` text DEFAULT NULL,
  `migrateInfo` text DEFAULT NULL
)

CREATE TABLE `params` (
  `instance` varchar(20) DEFAULT NULL,
  `id` varchar(20) DEFAULT NULL,
  `value` text DEFAULT NULL
)

INSERT INTO `params` (`instance`, `id`, `value`) VALUES ('', 'dbVersion', '2');
