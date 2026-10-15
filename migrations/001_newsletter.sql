-- A subscriber exists the moment someone asks to be added and is removed
-- again on unsubscribing - there is no "unsubscribed" status to keep: an
-- address that leaves has no further reason to be on file. confirmed_at is
-- NULL until the confirmation link is used; an unconfirmed row is pruned by
-- the extension's own cron task once it is old enough (see Extension.php).

CREATE TABLE x_newsletter_subscriber (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    confirmed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_x_newsletter_subscriber_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per campaign actually sent, for the history on the admin page.
CREATE TABLE x_newsletter_send (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subject VARCHAR(200) NOT NULL,
    body MEDIUMTEXT NOT NULL,
    recipient_count INT UNSIGNED NOT NULL,
    sent_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
