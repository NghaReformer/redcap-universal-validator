-- tests/mysql/bootstrap.sql — the empty schema tests/mysql/run.php expects.
--
-- Deliberately minimal. The point of the database CI job is to exercise the
-- module's OWN migration against a real server, so this file must not create
-- any of the module's tables: doing so would test that MySQL can read a fixture
-- rather than that Schema::migrate() can install one.
--
-- Only a database and a utf8mb4 default. Everything else comes from Schema.php.
CREATE DATABASE IF NOT EXISTS uv_test
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;
