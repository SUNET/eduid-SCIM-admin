CREATE TABLE `instances` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `instance` varchar(30) DEFAULT NULL,
  PRIMARY KEY (`id`)
);

INSERT INTO `instances` VALUES
(1,'Admin');

CREATE TABLE `invites` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `instance_id` int(10) unsigned NOT NULL,
  `hash` varchar(65) DEFAULT NULL,
  `status` tinyint(3) unsigned DEFAULT NULL,
  `session` varchar(27) DEFAULT NULL,
  `modified` datetime DEFAULT NULL,
  `attributes` text DEFAULT NULL,
  `inviteInfo` text DEFAULT NULL,
  `migrateInfo` text DEFAULT NULL,
  `lang` varchar(2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `instance_id` (`instance_id`),
  CONSTRAINT `invites_ibfk_1` FOREIGN KEY (`instance_id`) REFERENCES `instances` (`id`) ON DELETE CASCADE
);

CREATE TABLE `params` (
  `instance_id` int(10) unsigned NOT NULL,
  `id` varchar(20) DEFAULT NULL,
  `value` text DEFAULT NULL,
  KEY `instance_id` (`instance_id`),
  CONSTRAINT `params_ibfk_1` FOREIGN KEY (`instance_id`) REFERENCES `instances` (`id`) ON DELETE CASCADE
);

INSERT INTO `params` VALUES
(1,'dbVersion','3');

CREATE TABLE `users` (
  `instance_id` int(10) unsigned NOT NULL,
  `ePPN` varchar(40) DEFAULT NULL,
  KEY `users_ibfk_1` (`instance_id`),
  CONSTRAINT `users_ibfk_1` FOREIGN KEY (`instance_id`) REFERENCES `instances` (`id`) ON DELETE CASCADE
);


