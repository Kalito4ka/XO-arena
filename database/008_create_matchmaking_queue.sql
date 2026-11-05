BEGIN;

CREATE TABLE matchmaking_queue (
    user_id BIGINT PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    mode VARCHAR(24) NOT NULL
        CHECK (mode IN ('classic', 'ultimate', 'gobblers')),
    game_id VARCHAR(32) REFERENCES games(id) ON DELETE CASCADE,
    queued_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
    seen_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp()
);

CREATE INDEX matchmaking_waiting_idx
    ON matchmaking_queue (mode, queued_at, user_id)
    WHERE game_id IS NULL;

COMMIT;