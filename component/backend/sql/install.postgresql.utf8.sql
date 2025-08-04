/**
 * @package   AkeebaEngage
 * @copyright Copyright (c)2020-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

CREATE TABLE IF NOT EXISTS "#__engage_comments"
(
    "id"          SERIAL       NOT NULL,
    "parent_id"   BIGINT       NULL,
    "asset_id"    INTEGER      NOT NULL,
    "body"        TEXT         NOT NULL,
    "name"        VARCHAR(255)          DEFAULT NULL,
    "email"       VARCHAR(255)          DEFAULT NULL,
    "ip"          VARCHAR(64)           DEFAULT NULL,
    "user_agent"  VARCHAR(255) NOT NULL,
    "enabled"     SMALLINT     NOT NULL DEFAULT 0,
    "created"     TIMESTAMP             DEFAULT NULL,
    "created_by"  INTEGER               DEFAULT NULL,
    "modified"    TIMESTAMP             DEFAULT NULL,
    "modified_by" INTEGER               DEFAULT NULL,
    PRIMARY KEY ("id")
);

CREATE INDEX IF NOT EXISTS "#__engage_comments_asset" ON "#__engage_comments" ("asset_id");
CREATE INDEX IF NOT EXISTS "#__engage_comments_created_on" ON "#__engage_comments" ("created" DESC);

COMMENT ON TABLE "#__engage_comments" IS 'Content comments';

CREATE TABLE IF NOT EXISTS "#__engage_unsubscribe"
(
    "asset_id" BIGINT       NOT NULL,
    "email"    VARCHAR(255) NOT NULL,
    PRIMARY KEY ("asset_id", "email")
);

COMMENT ON TABLE "#__engage_unsubscribe" IS 'Unsubscribed emails';

DROP TABLE IF EXISTS "#__engage_emailtemplates";