-- SPDX-License-Identifier: AGPL-3.0-or-later
-- What a restore must give back, company by company, one line each: the issued invoices and what they come to, the
-- head of every invoice chain, what the stock movements add up to, and the files with the sha256 each row records.
-- The restore drill (scripts/backup/restore-drill.sh) reads it before and after each restore and wants the same lines:
-- a count of companies alone passed a restore that lost an invoice or a movement.
-- A chain's head is the issued document no other names as the one before it, so no series has to be known here.
SELECT c.id
    || ' invoices=' || (SELECT count(*) FROM invoice i WHERE i.company_id = c.id AND i.issued_at IS NOT NULL)
    || ' total=' || (SELECT coalesce(sum(i.total_gross), 0) FROM invoice i WHERE i.company_id = c.id AND i.issued_at IS NOT NULL)
    || ' heads=' || (SELECT coalesce(string_agg(i.chain_position || ':' || i.chain_hash, ',' ORDER BY i.chain_hash), '')
                     FROM invoice i
                     WHERE i.company_id = c.id AND i.chain_hash IS NOT NULL
                       AND NOT EXISTS (SELECT 1 FROM invoice n WHERE n.company_id = c.id AND n.chain_previous = i.chain_hash))
    || ' stock=' || (SELECT count(*) || ':' || coalesce(sum(s.quantity), 0) FROM stock_movement s WHERE s.company_id = c.id)
    || ' files=' || (SELECT count(*) || ':' || coalesce(md5(string_agg(f.sha256, ',' ORDER BY f.id)), '') FROM file f WHERE f.company_id = c.id)
FROM company c
ORDER BY c.id;
