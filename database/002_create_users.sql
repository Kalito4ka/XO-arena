BEGIN;

CREATE TABLE users (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,

    nickname VARCHAR(24) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,

    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT users_nickname_format
        CHECK (nickname ~ '^[A-Za-z0-9_]{3,24}$')
);

CREATE UNIQUE INDEX users_nickname_lower_unique
    ON users (LOWER(nickname));

COMMIT;