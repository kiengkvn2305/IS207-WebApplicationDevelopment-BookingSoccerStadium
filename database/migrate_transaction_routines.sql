-- MariaDB/MySQL procedures centralize transaction boundaries used by the PHP models.
-- Re-runnable: these routines are replaced on every migration run.
DROP PROCEDURE IF EXISTS sannha_tx_begin;
DROP PROCEDURE IF EXISTS sannha_tx_commit;
DROP PROCEDURE IF EXISTS sannha_tx_rollback;
DROP FUNCTION IF EXISTS sannha_cancel_fee;

DELIMITER //

CREATE PROCEDURE sannha_tx_begin()
BEGIN
  IF @@session.in_transaction <> 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A transaction is already active.';
  END IF;
  START TRANSACTION;
END//

CREATE PROCEDURE sannha_tx_commit()
BEGIN
  IF @@session.in_transaction = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No active transaction to commit.';
  END IF;
  COMMIT;
END//

CREATE PROCEDURE sannha_tx_rollback()
BEGIN
  IF @@session.in_transaction <> 0 THEN
    ROLLBACK;
  END IF;
END//

CREATE FUNCTION sannha_cancel_fee(total_amount INT UNSIGNED)
RETURNS INT UNSIGNED
DETERMINISTIC
NO SQL
RETURN FLOOR((CAST(total_amount AS DECIMAL(20,0)) * 70 + 50) / 100)//

DELIMITER ;
