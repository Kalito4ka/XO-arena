BEGIN;

ALTER TABLE games
    ADD COLUMN x_user_id BIGINT REFERENCES users(id),
    ADD COLUMN o_user_id BIGINT REFERENCES users(id);

ALTER TABLE games
    ADD CONSTRAINT games_different_users
    CHECK (
        x_user_id IS NULL
        OR o_user_id IS NULL
        OR x_user_id <> o_user_id
    );

CREATE INDEX games_x_user_id_idx
    ON games (x_user_id);

CREATE INDEX games_o_user_id_idx
    ON games (o_user_id);

COMMIT;