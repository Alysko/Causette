CREATE TABLE IF NOT EXISTS settings (
	id INT AUTO_INCREMENT PRIMARY KEY,
	`key` VARCHAR(50) UNIQUE NOT NULL,
	`value` TEXT NOT NULL
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
	id INT AUTO_INCREMENT PRIMARY KEY,
	username VARCHAR(32) UNIQUE NOT NULL,
	username_changed_at TIMESTAMP NULL,
	email VARCHAR(255) UNIQUE NOT NULL,
	password VARCHAR(255) NOT NULL,
	user_number CHAR(16) UNIQUE NOT NULL,
	avatar_filename VARCHAR(64) NULL,
	presence_status ENUM('online','busy','offline') NOT NULL DEFAULT 'online',
	last_active_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
	email_verified_at TIMESTAMP NULL,
	role ENUM('user','admin') DEFAULT 'user',
	is_banned TINYINT(1) NOT NULL DEFAULT 0,
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	CONSTRAINT chk_users_username_format CHECK (username REGEXP '^[a-z][a-z0-9]{3,31}$')
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS username_history (
	id INT AUTO_INCREMENT PRIMARY KEY,
	user_id INT NOT NULL,
	username VARCHAR(32) UNIQUE NOT NULL,
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_verification_codes (
	id INT AUTO_INCREMENT PRIMARY KEY,
	user_id INT NOT NULL,
	code CHAR(6) NOT NULL,
	expires_at TIMESTAMP NOT NULL,
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
	UNIQUE KEY (user_id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_change_requests (
	id INT AUTO_INCREMENT PRIMARY KEY,
	user_id INT NOT NULL,
	new_email VARCHAR(255) NOT NULL,
	code CHAR(6) NOT NULL,
	expires_at TIMESTAMP NOT NULL,
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
	UNIQUE KEY (user_id),
	UNIQUE KEY (new_email)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_trusted_ips (
	id INT AUTO_INCREMENT PRIMARY KEY,
	user_id INT NOT NULL,
	ip VARCHAR(45) NOT NULL,
	last_login TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
	UNIQUE KEY (user_id, ip)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_sessions (
	id BIGINT AUTO_INCREMENT PRIMARY KEY,
	user_id INT NOT NULL,
	session_token CHAR(64) NOT NULL,
	remember_token CHAR(64) NULL,
	ip_address VARCHAR(45) NOT NULL,
	browser VARCHAR(120) NOT NULL,
	logged_in_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	last_seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	revoked_at TIMESTAMP NULL,
	FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
	UNIQUE KEY (session_token),
	UNIQUE KEY (remember_token),
	KEY idx_user_sessions_user_active (user_id, revoked_at, logged_in_at)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_attempt_limits (
	id BIGINT AUTO_INCREMENT PRIMARY KEY,
	ip_address VARCHAR(45) NOT NULL,
	action_type ENUM('login_failed','register_invite_failed') NOT NULL,
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	KEY idx_auth_attempt_limits_lookup (ip_address, action_type, created_at)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS twofa_codes (
	id INT AUTO_INCREMENT PRIMARY KEY,
	user_id INT NOT NULL,
	code CHAR(6) NOT NULL,
	ip VARCHAR(45) NOT NULL,
	expires_at TIMESTAMP NOT NULL,
	FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS totp_secrets (
	id INT AUTO_INCREMENT PRIMARY KEY,
	user_id INT NOT NULL,
	secret_encrypted TEXT NOT NULL,
	confirmed_at TIMESTAMP NULL,
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
	UNIQUE KEY (user_id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS totp_recovery_codes (
	id INT AUTO_INCREMENT PRIMARY KEY,
	user_id INT NOT NULL,
	code_hash CHAR(64) NOT NULL,
	used_at TIMESTAMP NULL,
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
	KEY idx_totp_recovery_user (user_id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
	id INT AUTO_INCREMENT PRIMARY KEY,
	user_id INT NOT NULL,
	token VARCHAR(64) NOT NULL,
	expires_at TIMESTAMP NOT NULL,
	FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invite_codes (
	id INT AUTO_INCREMENT PRIMARY KEY,
	code VARCHAR(20) UNIQUE NOT NULL,
	creator_id INT NOT NULL,
	used_by INT NULL,
	used_at TIMESTAMP NULL,
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	FOREIGN KEY (creator_id) REFERENCES users(id) ON DELETE CASCADE,
	FOREIGN KEY (used_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS friends (
	id INT AUTO_INCREMENT PRIMARY KEY,
	user_id INT NOT NULL,
	friend_id INT NOT NULL,
	status ENUM('pending','accepted','rejected') DEFAULT 'pending',
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
	FOREIGN KEY (friend_id) REFERENCES users(id) ON DELETE CASCADE,
	UNIQUE KEY (user_id, friend_id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS friend_favorites (
	id INT AUTO_INCREMENT PRIMARY KEY,
	user_id INT NOT NULL,
	favorite_user_id INT NOT NULL,
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
	FOREIGN KEY (favorite_user_id) REFERENCES users(id) ON DELETE CASCADE,
	UNIQUE KEY (user_id, favorite_user_id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chats (
	id INT AUTO_INCREMENT PRIMARY KEY,
	chat_number CHAR(16) UNIQUE NOT NULL,
	type ENUM('personal','group') DEFAULT 'personal',
	title VARCHAR(80) NULL,
	non_member_visibility ENUM('none','requestable','public') NOT NULL DEFAULT 'none',
	created_by INT NOT NULL,
	deleted_at TIMESTAMP NULL,
	deleted_by INT NULL,
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	FOREIGN KEY (created_by) REFERENCES users(id),
	FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL,
	KEY idx_chats_deleted_at (deleted_at)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_members (
	id INT AUTO_INCREMENT PRIMARY KEY,
	chat_id INT NOT NULL,
	user_id INT NOT NULL,
	role ENUM('member','moderator') NOT NULL DEFAULT 'member',
	is_muted TINYINT(1) NOT NULL DEFAULT 0,
	muted_by INT NULL,
	muted_at TIMESTAMP NULL,
	last_seen_message_id INT NULL,
	joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	FOREIGN KEY (chat_id) REFERENCES chats(id) ON DELETE CASCADE,
	FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
	FOREIGN KEY (muted_by) REFERENCES users(id) ON DELETE SET NULL,
	KEY idx_chat_members_role (chat_id, role),
	UNIQUE KEY (chat_id, user_id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS group_join_requests (
	id BIGINT AUTO_INCREMENT PRIMARY KEY,
	chat_id INT NOT NULL,
	requester_user_id INT NOT NULL,
	status ENUM('pending','approved','denied','cancelled') NOT NULL DEFAULT 'pending',
	handled_by INT NULL,
	handled_at TIMESTAMP NULL,
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	FOREIGN KEY (chat_id) REFERENCES chats(id) ON DELETE CASCADE,
	FOREIGN KEY (requester_user_id) REFERENCES users(id) ON DELETE CASCADE,
	FOREIGN KEY (handled_by) REFERENCES users(id) ON DELETE SET NULL,
	UNIQUE KEY uniq_group_join_requests_chat_user (chat_id, requester_user_id),
	KEY idx_group_join_requests_status (chat_id, status)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS polls (
	id BIGINT AUTO_INCREMENT PRIMARY KEY,
	chat_id INT NOT NULL,
	creator_user_id INT NOT NULL,
	question VARCHAR(40) NOT NULL,
	status ENUM('active','expired') NOT NULL DEFAULT 'active',
	expires_at TIMESTAMP NOT NULL,
	expired_at TIMESTAMP NULL,
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	FOREIGN KEY (chat_id) REFERENCES chats(id) ON DELETE CASCADE,
	FOREIGN KEY (creator_user_id) REFERENCES users(id) ON DELETE CASCADE,
	KEY idx_polls_chat_status (chat_id, status, expires_at, created_at)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS poll_options (
	id BIGINT AUTO_INCREMENT PRIMARY KEY,
	poll_id BIGINT NOT NULL,
	option_text VARCHAR(40) NOT NULL,
	sort_order TINYINT NOT NULL,
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE,
	UNIQUE KEY uniq_poll_options_order (poll_id, sort_order)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS poll_votes (
	id BIGINT AUTO_INCREMENT PRIMARY KEY,
	poll_id BIGINT NOT NULL,
	poll_option_id BIGINT NOT NULL,
	user_id INT NOT NULL,
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE,
	FOREIGN KEY (poll_option_id) REFERENCES poll_options(id) ON DELETE CASCADE,
	FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
	UNIQUE KEY uniq_poll_votes_poll_user (poll_id, user_id),
	KEY idx_poll_votes_option (poll_option_id, poll_id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS messages (
	id INT AUTO_INCREMENT PRIMARY KEY,
	chat_id INT NOT NULL,
	user_id INT NOT NULL,
	content TEXT NOT NULL,
	quoted_message_id INT NULL,
	quoted_user_id INT NULL,
	quoted_content TEXT NULL,
	bot_name VARCHAR(100) NULL,
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	edited_at TIMESTAMP NULL,
	FOREIGN KEY (chat_id) REFERENCES chats(id) ON DELETE CASCADE,
	FOREIGN KEY (user_id) REFERENCES users(id),
	FOREIGN KEY (quoted_message_id) REFERENCES messages(id) ON DELETE SET NULL,
	FOREIGN KEY (quoted_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS message_reactions (
	id BIGINT AUTO_INCREMENT PRIMARY KEY,
	message_id INT NOT NULL,
	user_id INT NOT NULL,
	reaction_code VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
	FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
	UNIQUE KEY uniq_message_reactions_message_user (message_id, user_id),
	KEY idx_message_reactions_message (message_id),
	KEY idx_message_reactions_user (user_id),
	CONSTRAINT chk_message_reactions_code_v4 CHECK (CHAR_LENGTH(reaction_code) BETWEEN 2 AND 128)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS posts (
	id BIGINT AUTO_INCREMENT PRIMARY KEY,
	user_id INT NOT NULL,
	content VARCHAR(500) NOT NULL,
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
	KEY idx_posts_user_created (user_id, created_at),
	CONSTRAINT chk_posts_content_length CHECK (CHAR_LENGTH(content) BETWEEN 1 AND 500)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS post_reactions (
	id BIGINT AUTO_INCREMENT PRIMARY KEY,
	post_id BIGINT NOT NULL,
	user_id INT NOT NULL,
	reaction_code VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
	FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
	UNIQUE KEY uniq_post_reactions_post_user (post_id, user_id),
	KEY idx_post_reactions_post (post_id),
	KEY idx_post_reactions_user (user_id),
	CONSTRAINT chk_post_reactions_code_v4 CHECK (CHAR_LENGTH(reaction_code) BETWEEN 2 AND 128)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attachments (
	id BIGINT AUTO_INCREMENT PRIMARY KEY,
	chat_id INT NOT NULL,
	user_id INT NOT NULL,
	message_id INT NULL,
	original_name VARCHAR(255) NOT NULL,
	file_name CHAR(16) NOT NULL,
	file_extension VARCHAR(8) NOT NULL,
	mime_type VARCHAR(64) NOT NULL,
	file_size BIGINT NOT NULL,
	width INT NULL,
	height INT NULL,
	file_hash CHAR(64) NULL,
	dedup_source_id BIGINT NULL,
	status ENUM('pending','submitted') NOT NULL DEFAULT 'pending',
	expires_at TIMESTAMP NULL,
	deleted_at TIMESTAMP NULL,
	delete_reason ENUM('manual','expired','message_deleted') NULL,
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	submitted_at TIMESTAMP NULL,
	FOREIGN KEY (chat_id) REFERENCES chats(id) ON DELETE CASCADE,
	FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
	FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
	FOREIGN KEY (dedup_source_id) REFERENCES attachments(id) ON DELETE SET NULL,
	UNIQUE KEY uniq_attachment_user_file (user_id, file_name),
	KEY idx_attachments_message (message_id),
	KEY idx_attachments_pending (user_id, status, created_at),
	KEY idx_attachments_hash (file_hash, file_extension),
	KEY idx_attachments_expiry (status, deleted_at, expires_at)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_system_events (
	id INT AUTO_INCREMENT PRIMARY KEY,
	chat_id INT NOT NULL,
	event_type VARCHAR(32) NOT NULL,
	content TEXT NOT NULL,
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	FOREIGN KEY (chat_id) REFERENCES chats(id) ON DELETE CASCADE,
	KEY idx_chat_system_events_chat (chat_id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_typing_status (
	id INT AUTO_INCREMENT PRIMARY KEY,
	chat_id INT NOT NULL,
	user_id INT NOT NULL,
	updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	FOREIGN KEY (chat_id) REFERENCES chats(id) ON DELETE CASCADE,
	FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
	UNIQUE KEY (chat_id, user_id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS calls (
	id INT AUTO_INCREMENT PRIMARY KEY,
	chat_id INT NOT NULL,
	started_by INT NOT NULL,
	status ENUM('active','ended') DEFAULT 'active',
	started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	ended_at TIMESTAMP NULL,
	FOREIGN KEY (chat_id) REFERENCES chats(id),
	FOREIGN KEY (started_by) REFERENCES users(id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS call_participants (
	id INT AUTO_INCREMENT PRIMARY KEY,
	call_id INT NOT NULL,
	user_id INT NOT NULL,
	offer TEXT NULL,
	answer TEXT NULL,
	ice_candidates TEXT NULL,
	muted TINYINT(1) DEFAULT 0,
	video TINYINT(1) DEFAULT 0,
	screen_sharing TINYINT(1) DEFAULT 0,
	joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	left_at TIMESTAMP NULL,
	FOREIGN KEY (call_id) REFERENCES calls(id) ON DELETE CASCADE
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS call_signals (
	id BIGINT AUTO_INCREMENT PRIMARY KEY,
	call_id INT NOT NULL,
	from_user_id INT NOT NULL,
	to_user_id INT NOT NULL,
	signal_type ENUM('offer','answer','ice','meta') NOT NULL,
	payload LONGTEXT NOT NULL,
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	INDEX idx_call_signals_to (call_id, to_user_id, id),
	INDEX idx_call_signals_from (call_id, from_user_id, id),
	FOREIGN KEY (call_id) REFERENCES calls(id) ON DELETE CASCADE,
	FOREIGN KEY (from_user_id) REFERENCES users(id) ON DELETE CASCADE,
	FOREIGN KEY (to_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
	id INT AUTO_INCREMENT PRIMARY KEY,
	user_id INT NOT NULL,
	type ENUM('message','call','friend_request','friend_request_accepted','report','poke','group_join_request','group_join_approved','group_join_denied') NOT NULL,
	title VARCHAR(100) NOT NULL,
	message TEXT NOT NULL,
	link VARCHAR(255) NULL,
	`read` TINYINT(1) DEFAULT 0,
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS push_subscriptions (
	id BIGINT AUTO_INCREMENT PRIMARY KEY,
	user_id INT NOT NULL,
	endpoint VARCHAR(500) NOT NULL,
	p256dh_key TEXT NOT NULL,
	auth_key TEXT NOT NULL,
	user_agent VARCHAR(255) NULL,
	fail_count INT NOT NULL DEFAULT 0,
	last_http_status SMALLINT NULL,
	last_error TEXT NULL,
	failed_at TIMESTAMP NULL,
	last_used_at TIMESTAMP NULL,
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
	UNIQUE KEY uniq_push_subscriptions_endpoint (endpoint),
	KEY idx_push_subscriptions_user (user_id),
	KEY idx_push_subscriptions_failed (failed_at, fail_count)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS push_delivery_logs (
	id BIGINT AUTO_INCREMENT PRIMARY KEY,
	subscription_id BIGINT NOT NULL,
	status ENUM('success','retryable','permanent_fail') NOT NULL,
	http_status SMALLINT NULL,
	error_message TEXT NULL,
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	FOREIGN KEY (subscription_id) REFERENCES push_subscriptions(id) ON DELETE CASCADE,
	KEY idx_push_delivery_logs_subscription (subscription_id, created_at)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reports (
	id INT AUTO_INCREMENT PRIMARY KEY,
	reporter_id INT NOT NULL,
	target_type ENUM('user','chat','message') NOT NULL,
	target_id INT NOT NULL,
	reason TEXT NOT NULL,
	status ENUM('pending','reviewed','dismissed') DEFAULT 'pending',
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	FOREIGN KEY (reporter_id) REFERENCES users(id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS servers (
	id INT AUTO_INCREMENT PRIMARY KEY,
	server_number CHAR(16) UNIQUE NOT NULL,
	name VARCHAR(100) NOT NULL,
	owner_id INT NOT NULL,
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS server_members (
	id INT AUTO_INCREMENT PRIMARY KEY,
	server_id INT NOT NULL,
	user_id INT NOT NULL,
	role ENUM('owner','member') DEFAULT 'member',
	joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	UNIQUE KEY (server_id, user_id),
	FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE,
	FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS channels (
	id INT AUTO_INCREMENT PRIMARY KEY,
	channel_number CHAR(16) UNIQUE NOT NULL,
	server_id INT NOT NULL,
	name VARCHAR(100) NOT NULL,
	type ENUM('text','voice') DEFAULT 'text',
	created_by INT NOT NULL,
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE,
	FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_keys (
	id INT AUTO_INCREMENT PRIMARY KEY,
	user_id INT NOT NULL,
	api_key CHAR(64) UNIQUE NOT NULL,
	name VARCHAR(100) NOT NULL,
	type ENUM('bot','user') NOT NULL,
	status ENUM('active','expired') NOT NULL DEFAULT 'active',
	allowed_ips TEXT NULL,
	allowed_chats TEXT NULL,
	expires_at TIMESTAMP NULL,
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
	KEY idx_api_keys_user (user_id, status),
	KEY idx_api_keys_lookup (api_key, status)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_key_logs (
	id BIGINT AUTO_INCREMENT PRIMARY KEY,
	api_key_id INT NOT NULL,
	ip_address VARCHAR(45) NOT NULL,
	endpoint VARCHAR(191) NOT NULL,
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE CASCADE,
	KEY idx_api_key_logs_key (api_key_id, created_at)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_rate_limits (
	id BIGINT AUTO_INCREMENT PRIMARY KEY,
	bucket_start DATETIME NOT NULL,
	ip_address VARCHAR(45) NOT NULL,
	user_id INT NOT NULL DEFAULT 0,
	route_key VARCHAR(191) NOT NULL,
	method VARCHAR(10) NOT NULL,
	request_count INT NOT NULL DEFAULT 0,
	updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	UNIQUE KEY uniq_api_rate_limit (bucket_start, ip_address, user_id, route_key, method),
	KEY idx_api_rate_limit_bucket (bucket_start),
	KEY idx_api_rate_limit_user (user_id, bucket_start)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (`key`, `value`) VALUES ('invite_codes_per_user', '3');

INSERT INTO settings (`key`, `value`) VALUES ('invites_enabled', '1');

INSERT INTO settings (`key`, `value`) VALUES ('attachments_accepted_file_types', 'png,jpg');

INSERT INTO settings (`key`, `value`) VALUES ('attachments_maximum_file_size_mb', '10');

INSERT INTO settings (`key`, `value`) VALUES ('mail_host', '');

INSERT INTO settings (`key`, `value`) VALUES ('mail_port', '587');

INSERT INTO settings (`key`, `value`) VALUES ('mail_user', '');

INSERT INTO settings (`key`, `value`) VALUES ('mail_pass', '');

INSERT INTO settings (`key`, `value`) VALUES ('mail_from', '');

INSERT INTO settings (`key`, `value`) VALUES ('mail_from_name', 'Causette');

INSERT INTO settings (`key`, `value`) VALUES ('invite_code_required', '1');

INSERT INTO settings (`key`, `value`) VALUES ('email_verification_required', '1');

INSERT INTO settings (`key`, `value`) VALUES ('error_display', '0');

INSERT INTO settings (`key`, `value`) VALUES ('new_user_notification', '0');

INSERT INTO settings (`key`, `value`) VALUES ('check_for_updates', '0');

INSERT INTO settings (`key`, `value`) VALUES ('announcement_style', 'orange');

INSERT INTO settings (`key`, `value`) VALUES ('announcement_message', 'Welcome to Causette! Be sure to configure SMTP in Settings > Admin > Mail and then remove this announcement in Settings > Admin > Announcement. Enjoy!');
