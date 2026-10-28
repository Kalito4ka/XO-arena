BEGIN;

ALTER TABLE games
    ADD COLUMN mode VARCHAR(24) NOT NULL DEFAULT 'classic',
    ADD COLUMN status VARCHAR(16) NOT NULL DEFAULT 'playing',
    ADD COLUMN winner CHAR(1),
    ADD COLUMN finished_at TIMESTAMPTZ,
    ADD COLUMN stats_recorded_at TIMESTAMPTZ;

ALTER TABLE games
    ADD CONSTRAINT games_status_valid
    CHECK (status IN ('playing', 'won', 'draw'));

ALTER TABLE games
    ADD CONSTRAINT games_result_valid
    CHECK (
        (
            status = 'playing'
            AND winner IS NULL
            AND finished_at IS NULL
            AND stats_recorded_at IS NULL
        )
        OR (
            status = 'won'
            AND winner IS NOT NULL
            AND winner IN ('X', 'O')
            AND finished_at IS NOT NULL
        )
        OR (
            status = 'draw'
            AND winner IS NULL
            AND finished_at IS NOT NULL
        )
    );

CREATE TABLE user_stats (
    user_id BIGINT NOT NULL REFERENCES users(id),
    mode VARCHAR(24) NOT NULL,

    wins BIGINT NOT NULL DEFAULT 0 CHECK (wins >= 0),
    losses BIGINT NOT NULL DEFAULT 0 CHECK (losses >= 0),
    draws BIGINT NOT NULL DEFAULT 0 CHECK (draws >= 0),

    PRIMARY KEY (user_id, mode)
);

CREATE INDEX games_finished_at_idx
    ON games (finished_at DESC, id DESC)
    WHERE finished_at IS NOT NULL;

COMMIT;