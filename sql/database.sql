CREATE TABLE `params` (
	`id` varchar(20) DEFAULT NULL,
	`value` text DEFAULT NULL
);

CREATE TABLE `invites` (
	`hash` varchar(20) DEFAULT NULL,
	`session` varchar(27) DEFAULT NULL,
	`modified` datetime DEFAULT NULL,
	`attributes` text DEFAULT NULL
);