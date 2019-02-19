/* Create required tables for Stripe */
  CREATE TABLE IF NOT EXISTS `civicrm_stripe_customers` (
    `id` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
    `contact_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'FK ID from civicrm_contact',
    `is_live` tinyint(4) NOT NULL COMMENT 'Whether this is a live or test transaction',
    `processor_id` int(10) DEFAULT NULL COMMENT 'ID from civicrm_payment_processor',
    UNIQUE KEY `id` (`id`),
    CONSTRAINT `FK_civicrm_stripe_customers_contact_id` FOREIGN KEY (`contact_id`)
      REFERENCES `civicrm_contact` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
