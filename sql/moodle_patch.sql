CREATE TABLE `serviceconsumer` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `access_key` varchar(20) NOT NULL,
  `secret_access_key` varchar(40) NOT NULL,
  `domain` varchar(100) NOT NULL,
  `fk_user_id` int(11) unsigned NOT NULL,
  `rawdomain` varchar(100) NOT NULL,
  `ipaddress` varchar(45) NOT NULL DEFAULT '',
  `subscriptionstart` bigint(10) unsigned NOT NULL DEFAULT '0',
  `subscriptionend` bigint(10) unsigned NOT NULL DEFAULT '0',
  `notifyexpiration` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `timecreated` bigint(10) unsigned NOT NULL DEFAULT '0',
  `timemodified` bigint(10) unsigned NOT NULL DEFAULT '0',
  `enabled` tinyint(1) unsigned NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `domainforuser_UNIQUE` (`domain`,`fk_user_id`),
  UNIQUE KEY `access_key_UNIQUE` (`access_key`),
  KEY `fk_serviceconsumer_1` (`fk_user_id`),
  CONSTRAINT `fk_serviceconsumer_1` FOREIGN KEY (`fk_user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `serviceconsumer_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `time` bigint(10) unsigned NOT NULL DEFAULT '0',
  `method` TEXT NOT NULL,
  `statuscode` int(11) NOT NULL DEFAULT '500',
  `message` varchar(45) NOT NULL DEFAULT '',
  `ipaddress` varchar(45) NOT NULL DEFAULT '',
  `origin` varchar(255) NOT NULL DEFAULT '',
  `referer` varchar(255) NOT NULL DEFAULT '',
  `fk_serviceconsumer_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
