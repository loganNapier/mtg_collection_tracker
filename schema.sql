CREATE DATABASE IF NOT EXISTS mtg
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
;-- -. . -..- - / . -. - .-. -.--
USE mtg;
;-- -. . -..- - / . -. - .-. -.--
CREATE TABLE users (
                       id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                       username VARCHAR(32) NOT NULL,
                       email VARCHAR(255) NOT NULL,
                       password_hash VARCHAR(255) NOT NULL,
                       created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

                       UNIQUE KEY uniq_users_username (username),
                       UNIQUE KEY uniq_users_email (email)
) ENGINE=InnoDB;
;-- -. . -..- - / . -. - .-. -.--
CREATE TABLE cards (
                       id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Scryfall IDs
                       scryfall_id CHAR(36) NOT NULL,
                       oracle_id CHAR(36) NULL,

                       name VARCHAR(255) NOT NULL,
                       type_line VARCHAR(255) NULL,
                       oracle_text TEXT NULL,

                       set_code VARCHAR(16) NULL,
                       set_name VARCHAR(128) NULL,
                       collector_number VARCHAR(32) NULL,

                       image_small VARCHAR(512) NULL,
                       image_normal VARCHAR(512) NULL,

                       price_usd DECIMAL(10,2) NULL,
                       price_usd_foil DECIMAL(10,2) NULL,
                       price_usd_etched DECIMAL(10,2) NULL,
                       price_updated_at TIMESTAMP NULL,

                       created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

                       UNIQUE KEY uniq_cards_scryfall_id (scryfall_id),

    -- Prevent duplicate printings
                       UNIQUE KEY uniq_card_print (oracle_id, set_code, collector_number),

                       KEY idx_cards_name (name),
                       KEY idx_cards_oracle_id (oracle_id)
) ENGINE=InnoDB;
;-- -. . -..- - / . -. - .-. -.--
CREATE TABLE user_collection (
                                 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

                                 user_id INT UNSIGNED NOT NULL,
                                 card_id INT UNSIGNED NOT NULL,

                                 qty INT UNSIGNED NOT NULL DEFAULT 1,

                                 card_condition ENUM('NM','LP','MP','HP','DMG') NOT NULL DEFAULT 'NM',
                                 card_language VARCHAR(32) NOT NULL DEFAULT 'English',

                                 finish ENUM('nonfoil','foil','etched') NOT NULL DEFAULT 'nonfoil',

                                 is_signed TINYINT(1) NOT NULL DEFAULT 0,
                                 is_altered TINYINT(1) NOT NULL DEFAULT 0,

                                 notes VARCHAR(500) NULL,
                                 acquired_at DATE NULL,
                                 purchase_price DECIMAL(10,2) NULL,

                                 batch_id VARCHAR(32) NULL,

                                 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                     ON UPDATE CURRENT_TIMESTAMP,

    -- Foreign keys
                                 CONSTRAINT fk_uc_user
                                     FOREIGN KEY (user_id) REFERENCES users(id)
                                         ON DELETE CASCADE,

                                 CONSTRAINT fk_uc_card
                                     FOREIGN KEY (card_id) REFERENCES cards(id)
                                         ON DELETE CASCADE,

    -- Validation
                                 CONSTRAINT chk_uc_qty CHECK (qty >= 1),
                                 CONSTRAINT chk_uc_purchase_price CHECK (purchase_price IS NULL OR purchase_price >= 0),

    -- One row per unique card variant
                                 UNIQUE KEY uniq_uc_user_card_variant (
                                                                       user_id,
                                                                       card_id,
                                                                       card_condition,
                                                                       card_language,
                                                                       is_signed,
                                                                       is_altered,
                                                                       finish
                                     ),

                                 KEY idx_uc_user (user_id),
                                 KEY idx_uc_card (card_id),
                                 KEY idx_uc_user_card (user_id, card_id),
                                 KEY idx_uc_batch (user_id, batch_id)

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
;-- -. . -..- - / . -. - .-. -.--
CREATE TABLE decks (
                       id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

                       user_id INT UNSIGNED NOT NULL,

                       name VARCHAR(80) NOT NULL,
                       format VARCHAR(32) NULL,
                       description VARCHAR(800) NULL,
                       is_public TINYINT(1) NOT NULL DEFAULT 0,

                       created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                       updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                           ON UPDATE CURRENT_TIMESTAMP,

                       CONSTRAINT fk_decks_user
                           FOREIGN KEY (user_id) REFERENCES users(id)
                               ON DELETE CASCADE,

                       KEY idx_decks_user (user_id),
                       KEY idx_decks_name (name)

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
;-- -. . -..- - / . -. - .-. -.--
CREATE TABLE deck_cards (
                            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

                            deck_id INT UNSIGNED NOT NULL,
                            card_id INT UNSIGNED NOT NULL,

                            section ENUM('main','side') NOT NULL DEFAULT 'main',

                            qty INT UNSIGNED NOT NULL DEFAULT 1,

                            finish ENUM('nonfoil','foil','etched') NOT NULL DEFAULT 'nonfoil',

                            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                ON UPDATE CURRENT_TIMESTAMP,

                            CONSTRAINT fk_deckcards_deck
                                FOREIGN KEY (deck_id) REFERENCES decks(id)
                                    ON DELETE CASCADE,

                            CONSTRAINT fk_deckcards_card
                                FOREIGN KEY (card_id) REFERENCES cards(id)
                                    ON DELETE RESTRICT,

                            CONSTRAINT chk_deckcards_qty CHECK (qty >= 1),

                            UNIQUE KEY uniq_deck_card_variant (deck_id, card_id, section, finish),

                            KEY idx_deckcards_deck (deck_id),
                            KEY idx_deckcards_card (card_id),
                            KEY idx_deckcards_deck_section (deck_id, section)

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
;-- -. . -..- - / . -. - .-. -.--
CREATE TABLE wishlist (
                          id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

                          user_id INT UNSIGNED NOT NULL,
                          card_id INT UNSIGNED NOT NULL,

                          qty INT UNSIGNED NOT NULL DEFAULT 1,

                          finish ENUM('nonfoil','foil','etched') NOT NULL DEFAULT 'nonfoil',

                          notes VARCHAR(500) NULL,

                          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                              ON UPDATE CURRENT_TIMESTAMP,

                          CONSTRAINT fk_wishlist_user
                              FOREIGN KEY (user_id) REFERENCES users(id)
                                  ON DELETE CASCADE,

                          CONSTRAINT fk_wishlist_card
                              FOREIGN KEY (card_id) REFERENCES cards(id)
                                  ON DELETE RESTRICT,

                          CONSTRAINT chk_wishlist_qty CHECK (qty >= 1),

                          UNIQUE KEY uniq_wishlist_variant (user_id, card_id, finish),

                          KEY idx_wishlist_user (user_id),
                          KEY idx_wishlist_card (card_id)

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
;-- -. . -..- - / . -. - .-. -.--

ALTER TABLE cards
    ADD COLUMN legalities JSON NULL
        AFTER price_updated_at;


