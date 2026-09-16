-- tests/mysql/bootstrap.sql — the empty schema tests/mysql/run.php expects.
--
-- Deliberately minimal. The point of the database CI job is to exercise the
-- module's OWN migration against a real server, so this file must not create
-- any of the module's tables: doing so would test that MySQL can read a fixture
-- rather than that Schema::migrate() can install one.
--
-- Only a database and a utf8mb4 default. Everything else comes from Schema.php.
--
-- THE NAME IS ALSO A DEFAULT RATHER THAN A FACT. run.php reads UV_DB_NAME, and
-- will mint and drop a private database of its own when UV_DB_SCHEMA_PREFIX is
-- set - which is what to use when anyone else might be on the same server, since
-- this suite drops the module's tables to prove the migration installs them.
-- CI sets neither, so the workflow's closing "nothing of ours is left behind"
-- step inspects the same schema the run actually used.
CREATE DATABASE IF NOT EXISTS uv_test
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;
