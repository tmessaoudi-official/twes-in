-- Runs once on first start (docker-entrypoint-initdb.d). The application database comes from POSTGRES_DB;
-- the test database is what Symfony's when@test dbname_suffix points at.
CREATE DATABASE twes_test OWNER twes;
