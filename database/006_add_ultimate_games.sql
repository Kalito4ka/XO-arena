BEGIN;

ALTER TABLE games
    ADD CONSTRAINT games_mode_valid
    CHECK (mode IN ('classic', 'ultimate'));

ALTER TABLE games
    DROP CONSTRAINT games_moves_limit;

ALTER TABLE games
    ADD CONSTRAINT games_moves_limit
    CHECK (
        (
            mode = 'classic'
            AND jsonb_array_length(moves) <= 9
        )
        OR (
            mode = 'ultimate'
            AND jsonb_array_length(moves) <= 81
        )
    );

DROP INDEX games_timeout_idx;

CREATE INDEX games_timeout_idx
    ON games (updated_at, id)
    WHERE status = 'playing'
      AND o_token_hash IS NOT NULL
      AND mode IN ('classic', 'ultimate');

COMMIT;