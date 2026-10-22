BEGIN;

CREATE TABLE games (
    id VARCHAR(32) PRIMARY KEY,

    x_token_hash VARCHAR(64) NOT NULL,
    o_token_hash VARCHAR(64),

    moves JSONB NOT NULL DEFAULT '[]'::jsonb,

    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT games_moves_array
        CHECK (jsonb_typeof(moves) = 'array'),

    CONSTRAINT games_moves_limit
        CHECK (jsonb_array_length(moves) <= 9)
);

COMMIT;