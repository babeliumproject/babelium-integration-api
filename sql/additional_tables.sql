CREATE TABLE `serviceconsumer` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `access_key` varchar(20) NOT NULL,
  `secret_access_key` varchar(40) NOT NULL,
  `name` varchar(255) NOT NULL,
  `domain` varchar(255) NOT NULL,
  `ipaddress` varchar(45) NOT NULL DEFAULT '',
  `timecreated` bigint(10) unsigned NOT NULL DEFAULT '0',
  `timemodified` bigint(10) unsigned NOT NULL DEFAULT '0',
  `requestlimit` int(10) unsigned NOT NULL DEFAULT '100',
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `salt` varchar(45) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `access_key_UNIQUE` (`access_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `serviceconsumer_log` (
  `id` int(11) NOT NULL,
  `fk_serviceconsumer_id` int(10) unsigned NOT NULL,
  `time` bigint(10) unsigned NOT NULL DEFAULT '0',
  `method` varchar(45) NOT NULL DEFAULT '',
  `statuscode` int(11) NOT NULL DEFAULT '500',
  `ip` varchar(45) NOT NULL DEFAULT '',
  `referer` varchar(255) NOT NULL DEFAULT '',
  `origin` varchar(255) NOT NULL DEFAULT '',
  `consumertime` bigint(10) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `fk_serviceconsumer_log_1` (`fk_serviceconsumer_id`),
  CONSTRAINT `fk_serviceconsumer_log_1` FOREIGN KEY (`fk_serviceconsumer_id`) REFERENCES `serviceconsumer` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `role` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8 DEFAULT '',
  `shortname` varchar(255) NOT NULL,
  `description` varchar(45) DEFAULT '',
  `sortorder` int(11) NOT NULL,
  `archetype` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `course` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `category` int(11) NOT NULL DEFAULT '0',
  `fullname` varchar(255) NOT NULL DEFAULT '',
  `fk_serviceconsumer_id` int(10) unsigned NOT NULL,
  `idnumber` int(11) NOT NULL DEFAULT '0',
  `shortname` varchar(255) NOT NULL DEFAULT '',
  `summary` longtext,
  `format` varchar(21) NOT NULL DEFAULT 'topics',
  `startdate` bigint(10) NOT NULL DEFAULT '0',
  `visible` tinyint(1) NOT NULL DEFAULT '1',
  `language` varchar(45) NOT NULL DEFAULT '',
  `timecreated` bigint(10) NOT NULL DEFAULT '0',
  `timemodified` bigint(10) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `fk_course_1` (`fk_serviceconsumer_id`),
  CONSTRAINT `fk_course_1` FOREIGN KEY (`fk_serviceconsumer_id`) REFERENCES `serviceconsumer` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `course_groups` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `fk_course_id` int(10) unsigned NOT NULL,
  `name` varchar(255) NOT NULL DEFAULT '',
  `description` longtext,
  `timecreated` bigint(10) NOT NULL DEFAULT '0',
  `timemodified` bigint(10) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `fk_course_groups_1` (`fk_course_id`),
  CONSTRAINT `fk_course_groups_1` FOREIGN KEY (`fk_course_id`) REFERENCES `course` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `assignment` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `fk_course_id` int(10) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` longtext NOT NULL,
  `duedate` bigint(10) unsigned NOT NULL DEFAULT '0',
  `allowsubmissionsfromdate` bigint(10) unsigned NOT NULL DEFAULT '0',
  `grade` varchar(45) DEFAULT NULL,
  `timemodified` bigint(10) unsigned NOT NULL DEFAULT '0',
  `teamsubmission` tinyint(1) NOT NULL DEFAULT '0',
  `requireallteammemberssubmit` tinyint(1) NOT NULL DEFAULT '0',
  `maxattempts` int(10) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `fk_assignment_1` (`fk_course_id`),
  CONSTRAINT `fk_assignment_1` FOREIGN KEY (`fk_course_id`) REFERENCES `course` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `assignment_submission` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `fk_assignment_id` int(10) unsigned NOT NULL,
  `fk_user_id` int(10) unsigned NOT NULL,
  `timecreated` bigint(10) NOT NULL DEFAULT '0',
  `timemodified` bigint(10) NOT NULL DEFAULT '0',
  `status` varchar(255) NOT NULL DEFAULT '',
  `fk_group_id` int(10) unsigned DEFAULT NULL,
  `attempnumber` int(10) unsigned DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `fk_assignment_submission_1` (`fk_assignment_id`),
  KEY `fk_assignment_submission_2` (`fk_user_id`),
  KEY `fk_assignment_submission_3` (`fk_group_id`),
  CONSTRAINT `fk_assignment_submission_1` FOREIGN KEY (`fk_assignment_id`) REFERENCES `assignment` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_assignment_submission_2` FOREIGN KEY (`fk_user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_assignment_submission_3` FOREIGN KEY (`fk_group_id`) REFERENCES `course_groups` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `rel_course_role_user` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `fk_role_id` int(10) unsigned NOT NULL,
  `fk_course_id` int(10) unsigned NOT NULL,
  `fk_user_id` int(10) unsigned NOT NULL,
  `timemodified` bigint(10) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `fk_rel_course_role_user_1` (`fk_course_id`),
  KEY `fk_rel_course_role_user_2` (`fk_role_id`),
  KEY `fk_rel_course_role_user_3` (`fk_user_id`),
  CONSTRAINT `fk_rel_course_role_user_1` FOREIGN KEY (`fk_course_id`) REFERENCES `course` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_rel_course_role_user_2` FOREIGN KEY (`fk_role_id`) REFERENCES `role` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_rel_course_role_user_3` FOREIGN KEY (`fk_user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `rel_coursegroup_user` (
  `id` int(10) unsigned NOT NULL,
  `fk_group_id` int(10) unsigned NOT NULL,
  `fk_user_id` int(10) unsigned NOT NULL,
  `timeadded` bigint(10) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `fk_rel_coursegroup_user_1` (`fk_group_id`),
  KEY `fk_rel_coursegroup_user_2` (`fk_user_id`),
  CONSTRAINT `fk_rel_coursegroup_user_1` FOREIGN KEY (`fk_group_id`) REFERENCES `course_groups` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_rel_coursegroup_user_2` FOREIGN KEY (`fk_user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;




