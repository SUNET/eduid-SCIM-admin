CREATE TABLE `invites` (
  `instance` varchar(20) DEFAULT NULL,
  `hash` varchar(20) DEFAULT NULL,
  `session` varchar(27) DEFAULT NULL,
  `modified` datetime DEFAULT NULL,
  `attributes` text DEFAULT NULL
)

CREATE TABLE `params` (
  `instance` varchar(20) DEFAULT NULL,
  `id` varchar(20) DEFAULT NULL,
  `value` text DEFAULT NULL
)

INSERT INTO `params` (`instance`, `id`, `value`) VALUES ('', 'dbVersion', '1');
