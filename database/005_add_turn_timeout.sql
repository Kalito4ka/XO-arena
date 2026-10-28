BEGIN;

ALTER TABLE games
    ADD COLUMN end_reason VARCHAR(16);

ALTER TABLE games
    ADD CONSTRAINT games_end_reason_valid
    CHECK (
        end_reason IS NULL
        OR (
            end_reason = 'timeout'
            AND status = 'won'
        )
    );

CREATE INDEX games_timeout_idx
    ON games (updated_at, id)
    WHERE status = 'playing'
      AND o_token_hash IS NOT NULL
      AND mode = 'classic';

COMMIT;