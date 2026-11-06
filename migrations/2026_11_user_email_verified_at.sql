-- The account email can be changed on the profile without confirming the new address, so a
-- confirmed registration says nothing about the address on the account now. emailVerifiedAt records
-- when the current address was confirmed and is cleared whenever it changes. An organization's
-- allowed email domains policy only accepts a confirmed address.
--
-- No backfill: for existing accounts it is unknown whether the current address is the confirmed one,
-- so they start unconfirmed and can confirm from the profile page.
--
-- Purely additive, run before deploying the code.
ALTER TABLE fos_user ADD emailVerifiedAt DATETIME DEFAULT NULL, ALGORITHM=INSTANT;
