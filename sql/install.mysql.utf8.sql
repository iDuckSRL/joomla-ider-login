DROP TABLE IF EXISTS `#__ider_user_data`;

CREATE TABLE `#__ider_user_data` (
	`id`       INT(11)     NOT NULL AUTO_INCREMENT,
	`uid`      INT(11)     NOT NULL,
	`user_field` VARCHAR(255) NOT NULL,
	`user_value` VARCHAR(255) NOT NULL,
	PRIMARY KEY (`id`)
)
	ENGINE =MyISAM
	AUTO_INCREMENT =0
	DEFAULT CHARSET =utf8