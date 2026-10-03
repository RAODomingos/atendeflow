-- Unidades deixam de ser salvas (busca ao vivo na API): colapsa os
-- vínculos por unidade em 1 linha por loja e remove as colunas de unidade.
DELETE cs1 FROM contact_stores cs1
INNER JOIN contact_stores cs2
  ON cs2.contact_id = cs1.contact_id
 AND cs2.customer_id = cs1.customer_id
 AND cs2.network_name = cs1.network_name
 AND cs2.id < cs1.id;
ALTER TABLE contact_stores ADD UNIQUE KEY uq_contact_network (contact_id, customer_id);
ALTER TABLE contact_stores DROP INDEX uq_contact_store;
ALTER TABLE contact_stores DROP COLUMN store_id;
ALTER TABLE contact_stores DROP COLUMN store_name;
