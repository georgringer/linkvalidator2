# Define tables and fields since they have no TCA
CREATE TABLE tx_linkvalidator2_target (
	uid int(11) unsigned NOT NULL auto_increment,
	link_type varchar(30) DEFAULT '' NOT NULL,
	identifier text,
	identifier_hash varchar(40) DEFAULT '' NOT NULL,
	status varchar(10) DEFAULT '' NOT NULL,
	error_type varchar(64) DEFAULT '' NOT NULL,
	error_details text,
	last_checked int(11) unsigned DEFAULT '0' NOT NULL,
	failure_count int(11) unsigned DEFAULT '0' NOT NULL,
	first_failed_at int(11) unsigned DEFAULT '0' NOT NULL,

	PRIMARY KEY (uid),
	UNIQUE KEY identifier_hash (identifier_hash)
);

CREATE TABLE tx_linkvalidator2_broken_link (
	uid int(11) unsigned NOT NULL auto_increment,
	target int(11) unsigned DEFAULT '0' NOT NULL,
	table_name varchar(255) DEFAULT '' NOT NULL,
	record_uid int(11) unsigned DEFAULT '0' NOT NULL,
	field varchar(255) DEFAULT '' NOT NULL,
	pid int(11) DEFAULT '0' NOT NULL,
	language int(11) DEFAULT '0' NOT NULL,
	workspace int(11) DEFAULT '0' NOT NULL,
	link_string text,
	link_text text,
	status varchar(10) DEFAULT '' NOT NULL,
	error_type varchar(64) DEFAULT '' NOT NULL,
	error_details text,
	first_detected int(11) unsigned DEFAULT '0' NOT NULL,
	last_seen int(11) unsigned DEFAULT '0' NOT NULL,

	PRIMARY KEY (uid),
	KEY record (table_name, record_uid),
	KEY pid (pid),
	KEY target (target)
);

CREATE TABLE tx_linkvalidator2_record_status (
	uid int(11) unsigned NOT NULL auto_increment,
	table_name varchar(255) DEFAULT '' NOT NULL,
	record_uid int(11) unsigned DEFAULT '0' NOT NULL,
	pid int(11) DEFAULT '0' NOT NULL,
	language int(11) DEFAULT '0' NOT NULL,
	workspace int(11) DEFAULT '0' NOT NULL,
	last_checked int(11) unsigned DEFAULT '0' NOT NULL,
	broken_count int(11) unsigned DEFAULT '0' NOT NULL,
	excluded_count int(11) unsigned DEFAULT '0' NOT NULL,

	PRIMARY KEY (uid),
	UNIQUE KEY record (table_name, record_uid, workspace),
	KEY last_checked (last_checked),
	KEY pid (pid)
);
