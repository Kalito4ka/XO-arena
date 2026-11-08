BEGIN;

ALTER TABLE games
    ADD COLUMN x_guest_key VARCHAR(64),
    ADD COLUMN o_guest_key VARCHAR(64),
    ADD COLUMN rematch_x BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN rematch_o BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN rematch_id VARCHAR(32) REFERENCES games(id) ON DELETE SET NULL;

CREATE INDEX games_waiting_expiry_idx ON games(created_at)
    WHERE status = 'playing' AND o_token_hash IS NULL;

-- удаляет просроченные комнаты и лишние ожидания аккаунтов
DELETE FROM games g
WHERE g.status = 'playing' AND g.o_token_hash IS NULL
  AND (
      g.created_at <= clock_timestamp() - INTERVAL '3 minutes'
      OR (g.x_user_id IS NOT NULL AND EXISTS (
          SELECT 1 FROM games other
          WHERE (other.x_user_id = g.x_user_id OR other.o_user_id = g.x_user_id)
            AND other.status = 'playing' AND other.id <> g.id
            AND (
                (other.o_token_hash IS NOT NULL AND other.mode = g.mode)
                OR (other.o_token_hash IS NULL
                    AND (other.created_at, other.id) > (g.created_at, g.id))
            )
      ))
  );

COMMIT;
