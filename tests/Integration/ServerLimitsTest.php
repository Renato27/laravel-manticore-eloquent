<?php

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * What the driver says Manticore does not do, run against the server with raw SQL. docs/compatibility.md
 * points here wherever the manual does not state the limit. When a release adds one of these, this
 * test fails, and the driver and the docs can take advantage of it.
 */
beforeEach(function () {
    try {
        DB::connection('manticore')->getPdo();
    } catch (Throwable $e) {
        $this->markTestSkipped('Manticore not reachable on the MySQL port: '.$e->getMessage());
    }

    $db = DB::connection('manticore');
    $db->statement('DROP TABLE IF EXISTS limits_a');
    $db->statement('DROP TABLE IF EXISTS limits_b');
    $db->statement('CREATE TABLE limits_a (title text, name string, n int, f float, ts timestamp, j json)');
    $db->statement('CREATE TABLE limits_b (a_id bigint, note string)');
    $db->statement("INSERT INTO limits_a (id, title, name, n, f, ts, j) VALUES (1, 'one', 'alpha', 1, 1.5, 1000, '{\"l\":[1,2]}')");
    $db->statement("INSERT INTO limits_a (id, title, name, n, f, ts, j) VALUES (2, 'two', 'beta', 2, 2.5, 2000, '{\"l\":[3]}')");
    $db->statement("INSERT INTO limits_b (id, a_id, note) VALUES (11, 1, 'x')");
});

afterEach(function () {
    try {
        DB::connection('manticore')->statement('DROP TABLE IF EXISTS limits_a');
        DB::connection('manticore')->statement('DROP TABLE IF EXISTS limits_b');
    } catch (Throwable) {
        // Nothing to clean up when the server is not reachable.
    }
});

it('rejects this SQL', function (string $sql) {
    DB::connection('manticore')->select($sql);
})->with([
    // Sub-selects only exist in FROM: SELECT * FROM (SELECT ...) ORDER BY ... LIMIT ...
    // https://manual.manticoresearch.com/Searching/Sub-selects
    'a subquery in IN' => 'SELECT id FROM limits_a WHERE id IN (SELECT a_id FROM limits_b)',
    'EXISTS, used by whereHas' => 'SELECT * FROM limits_a WHERE EXISTS (SELECT * FROM limits_b WHERE limits_a.id = limits_b.a_id)',
    'a subquery in the select list, used by withCount' => 'SELECT limits_a.*, (SELECT COUNT(*) FROM limits_b WHERE limits_a.id = limits_b.a_id) AS c FROM limits_a',
    'SELECT EXISTS, used by exists()' => 'SELECT EXISTS(SELECT 1 FROM limits_a)',
    'a sub-select with an alias' => 'SELECT * FROM (SELECT id FROM limits_a LIMIT 5) AS t ORDER BY id ASC',
    'a sub-select without an order outside' => 'SELECT * FROM (SELECT id FROM limits_a LIMIT 5)',
    'a sub-select with columns outside' => 'SELECT id FROM (SELECT id FROM limits_a LIMIT 5) ORDER BY id ASC',
    'an aggregate outside a sub-select' => 'SELECT COUNT(*) FROM (SELECT id FROM limits_a LIMIT 5) ORDER BY id ASC',

    // The syntax is {INNER | LEFT} JOIN, once: https://manual.manticoresearch.com/Searching/Joining
    'a cross join' => 'SELECT * FROM limits_a CROSS JOIN limits_b',
    'two joins' => 'SELECT * FROM limits_a INNER JOIN limits_b ON limits_a.id = limits_b.a_id INNER JOIN limits_b ON limits_a.id = limits_b.a_id',

    // The manual only documents COUNT(*) and COUNT(DISTINCT):
    // https://manual.manticoresearch.com/Searching/Grouping
    'COUNT of a column' => 'SELECT COUNT(n) FROM limits_a',
    // The manual gives ORDER BY random(), which this server rejects. RAND() works, as
    // the rand_seed option documents, and it is what Laravel writes.
    // https://manual.manticoresearch.com/Searching/Sorting_and_ranking
    'ORDER BY random()' => 'SELECT id FROM limits_a ORDER BY random()',

    // Not stated in the manual, so proved here.
    'MAX of a string' => 'SELECT MAX(name) FROM limits_a',
    'MIN of a string' => 'SELECT MIN(name) FROM limits_a',
    'backticks in a join' => 'SELECT * FROM limits_a INNER JOIN limits_b ON `limits_a`.`id` = `limits_b`.`a_id`',
    'a table alias in a join' => 'SELECT * FROM limits_a AS a INNER JOIN limits_b AS b ON a.id = b.a_id',
    'a star of the other table' => 'SELECT limits_b.* FROM limits_a INNER JOIN limits_b ON limits_a.id = limits_b.a_id',
    'a named text field with its table in a join' => 'SELECT limits_a.title FROM limits_a INNER JOIN limits_b ON limits_a.id = limits_b.a_id',
    'UNION' => 'SELECT id FROM limits_a UNION SELECT id FROM limits_a',
    'LIKE, which the manual does not list among the operators of WHERE' => "SELECT id FROM limits_a WHERE name LIKE 'al%'",
    'SELECT DISTINCT' => 'SELECT DISTINCT name FROM limits_a',
    '<=>, which the manual does not list among the operators' => 'SELECT id FROM limits_a WHERE n <=> 1',
    'FOR UPDATE' => 'SELECT id FROM limits_a WHERE id = 1 FOR UPDATE',
    'LOCK IN SHARE MODE' => 'SELECT id FROM limits_a WHERE id = 1 LOCK IN SHARE MODE',

    // A function or expression cannot be used directly in WHERE: filter on its alias.
    // https://manual.manticoresearch.com/Searching/Filters
    'two columns compared, used by whereColumn' => 'SELECT id FROM limits_a WHERE n > id',
    'a date function, used by whereDate' => "SELECT id FROM limits_a WHERE DATE(ts) = '2026-01-01'",
    'REGEX in WHERE' => "SELECT id FROM limits_a WHERE REGEX(name, '^al') = 1",
    'ANY over a JSON array in WHERE' => 'SELECT id FROM limits_a WHERE ANY(x = 1 FOR x IN j.l)',
    'LENGTH in WHERE' => 'SELECT id FROM limits_a WHERE LENGTH(j.l) = 2',

    // https://manual.manticoresearch.com/Searching/Full_text_matching/Basic_usage
    'two MATCH' => "SELECT id FROM limits_a WHERE MATCH('one') AND MATCH('two')",
    'MATCH with OR' => "SELECT id FROM limits_a WHERE MATCH('one') OR n = 2",

    // IS NULL is for JSON: https://manual.manticoresearch.com/Searching/Expressions
    'IS NULL on an integer' => 'SELECT id FROM limits_a WHERE n IS NULL',
    'a filter on a text field' => "SELECT id FROM limits_a WHERE title = 'one'",

    // A quoted number is only taken in some forms, which the manual does not state. The driver
    // sends numbers unquoted, so none of this reaches the server.
    'a quoted number with >=' => "SELECT id FROM limits_a WHERE n >= '1'",
    'a quoted number with no spaces' => "SELECT id FROM limits_a WHERE n='1'",
    'a quoted number in IN' => "SELECT id FROM limits_a WHERE n IN ('1')",
    'a quoted number in BETWEEN' => "SELECT id FROM limits_a WHERE n BETWEEN '1' AND '2'",
    'a quoted number on a float' => "SELECT id FROM limits_a WHERE f = '1.5'",
    'a quoted number on a timestamp' => "SELECT id FROM limits_a WHERE ts = '1000'",
    'a number on a string' => 'SELECT id FROM limits_a WHERE name = 1',
])->throws(QueryException::class);

it('rejects this statement', function (string $sql) {
    DB::connection('manticore')->statement($sql);
})->with([
    // The syntax has no LIMIT or ORDER BY, and requires WHERE:
    // https://manual.manticoresearch.com/Data_creation_and_modification/Updating_documents/UPDATE
    'UPDATE without WHERE' => 'UPDATE limits_a SET n = 1',
    'UPDATE with LIMIT' => 'UPDATE limits_a SET n = 1 WHERE id = 1 LIMIT 1',
    'UPDATE with ORDER BY' => 'UPDATE limits_a SET n = 1 WHERE id = 1 ORDER BY id',
    'an expression in UPDATE, used by increment()' => 'UPDATE limits_a SET n = n + 1 WHERE id = 1',
    'a full-text field in UPDATE' => "UPDATE limits_a SET title = 'x' WHERE id = 1",
    'NULL in UPDATE' => 'UPDATE limits_a SET n = NULL WHERE id = 1',
    'a string at a JSON path, which takes numbers only' => "UPDATE limits_a SET j.l = 'x' WHERE id = 1",

    // https://manual.manticoresearch.com/Data_creation_and_modification/Deleting_documents
    'DELETE without WHERE' => 'DELETE FROM limits_a',
    'DELETE with LIMIT' => 'DELETE FROM limits_a WHERE id = 1 LIMIT 1',

    // Not stated in the manual, so proved here.
    'a foreign key' => 'ALTER TABLE limits_a ADD CONSTRAINT c FOREIGN KEY (n) REFERENCES limits_b (id)',
    'a unique index' => 'CREATE UNIQUE INDEX i ON limits_a (n)',
    'an index' => 'CREATE INDEX i ON limits_a (n)',
    'a view' => 'CREATE VIEW v AS SELECT id FROM limits_a',
    'a default' => 'CREATE TABLE limits_c (n int default 1)',
    'not null' => 'CREATE TABLE limits_c (n int not null)',
    'a table without columns' => 'CREATE TABLE limits_c ()',
    'INSERT ... SELECT, used by insertUsing' => 'INSERT INTO limits_b (id) SELECT id FROM limits_a',
    'INSERT IGNORE' => "INSERT IGNORE INTO limits_a (id, name) VALUES (1, 'x')",
    'ON DUPLICATE KEY' => "INSERT INTO limits_a (id, name) VALUES (1, 'x') ON DUPLICATE KEY UPDATE name = 'y'",
    'NULL in INSERT' => 'INSERT INTO limits_a (id, n) VALUES (3, NULL)',

    // ALTER only adds, drops and widens columns:
    // https://manual.manticoresearch.com/Updating_table_schema_and_settings
    'RENAME COLUMN' => 'ALTER TABLE limits_a RENAME COLUMN n TO m',
    'MODIFY COLUMN to anything but bigint' => 'ALTER TABLE limits_a MODIFY COLUMN name text',
])->throws(QueryException::class);

it('ignores these writes without an error, which the driver works around', function () {
    $db = DB::connection('manticore');

    $db->statement("UPDATE limits_a SET n = '77' WHERE id = 1");
    $db->statement("UPDATE limits_a SET f = '9.5' WHERE id = 1");
    $db->statement('UPDATE limits_a SET name = 5 WHERE id = 1');

    $row = $db->selectOne('SELECT n, f, name FROM limits_a WHERE id = 1');

    expect($row->n)->toBe(1)
        ->and($row->f)->toBe(1.5)
        ->and($row->name)->toBe('alpha');
});

it('stores what cannot go in a numeric column without an error, which the driver refuses', function () {
    $db = DB::connection('manticore');

    $db->statement("INSERT INTO limits_a (id, n) VALUES (3, 'abc')");
    $db->statement("INSERT INTO limits_a (id, n) VALUES ('7abc', 1)");
    $db->statement('UPDATE limits_a SET n = 1.7 WHERE id = 1');
    $db->statement("INSERT INTO limits_a (id, j) VALUES (4, 'not json')");

    expect($db->selectOne('SELECT n FROM limits_a WHERE id = 3')->n)->toBe(0)
        ->and($db->select('SELECT id FROM limits_a WHERE id = 7'))->toHaveCount(1)
        ->and($db->selectOne('SELECT n FROM limits_a WHERE id = 1')->n)->toBe(1071225242)
        ->and($db->selectOne('SELECT j FROM limits_a WHERE id = 4')->j)->toBeNull();
});

it('trims out-of-range numbers, as the manual says', function () {
    // https://manual.manticoresearch.com/Data_creation_and_modification/Updating_documents/UPDATE
    $db = DB::connection('manticore');
    $db->statement('INSERT INTO limits_a (id, n) VALUES (3, -1)');

    expect($db->selectOne('SELECT n FROM limits_a WHERE id = 3')->n)->toBe(4294967295);
});

it('reports a duplicate id with an error of its own, not the 1062 of MySQL', function () {
    DB::connection('manticore')->statement('INSERT INTO limits_a (id, n) VALUES (1, 1)');
})->throws(UniqueConstraintViolationException::class, 'duplicate id');

it('orders at random with RAND(), which Laravel writes', function () {
    expect(DB::connection('manticore')->select('SELECT id FROM limits_a ORDER BY RAND()'))->toHaveCount(2);
});

it('returns 20 rows and stops at 1000 unless told otherwise', function () {
    // https://manual.manticoresearch.com/Searching/Pagination
    $db = DB::connection('manticore');

    foreach (range(3, 40) as $id) {
        $db->statement("INSERT INTO limits_a (id, name) VALUES ({$id}, 'x')");
    }

    expect($db->select('SELECT id FROM limits_a'))->toHaveCount(20)
        ->and(fn () => $db->select('SELECT id FROM limits_a LIMIT 1 OFFSET 1000'))->toThrow(QueryException::class);
});

it('counts the documents of the left table in COUNT(*) on a left join, not the rows', function () {
    $db = DB::connection('manticore');
    $db->statement("INSERT INTO limits_b (id, a_id, note) VALUES (12, 1, 'y')");

    expect($db->select('SELECT limits_a.id FROM limits_a LEFT JOIN limits_b ON limits_a.id = limits_b.a_id'))->toHaveCount(3);

    $count = (array) $db->selectOne('SELECT COUNT(*) FROM limits_a LEFT JOIN limits_b ON limits_a.id = limits_b.a_id');

    expect((int) reset($count))->toBe(2);
});

it('takes a quoted number in these forms, so the rule is not that it rejects them', function () {
    $db = DB::connection('manticore');

    expect($db->select("SELECT id FROM limits_a WHERE n = '1'"))->toHaveCount(1)
        ->and($db->select("SELECT id FROM limits_a WHERE n > '1'"))->toHaveCount(1);
});

it('reads any join type but inner and left as an alias, and runs an inner join', function () {
    // The syntax is {INNER | LEFT} JOIN: https://manual.manticoresearch.com/Searching/Joining
    $db = DB::connection('manticore');
    $db->statement("INSERT INTO limits_b (id, a_id, note) VALUES (12, 99, 'no document')");

    foreach (['RIGHT', 'FULL', 'ANYTHING'] as $type) {
        expect($db->select("SELECT limits_a.id, limits_b.id FROM limits_a {$type} JOIN limits_b ON limits_a.id = limits_b.a_id"))->toHaveCount(1);
    }
});

it('returns no rows for an alias that repeats the column in a join', function () {
    expect(DB::connection('manticore')->select('SELECT limits_a.id AS id FROM limits_a INNER JOIN limits_b ON limits_a.id = limits_b.a_id'))->toHaveCount(0);
});

it('finds elements of a JSON array of strings with IN, but not of numbers', function () {
    $db = DB::connection('manticore');
    $db->statement('REPLACE INTO limits_a (id, j) VALUES (3, \'{"s":["x"],"l":[2]}\')');

    expect($db->select("SELECT id FROM limits_a WHERE j.s IN ('x')"))->toHaveCount(1)
        ->and($db->select('SELECT id FROM limits_a WHERE j.l IN (2)'))->toHaveCount(0);
});

it('takes these alternatives the manual gives for what WHERE cannot do', function () {
    $db = DB::connection('manticore');

    expect($db->select('SELECT id, n > id AS c FROM limits_a WHERE c = 1'))->toHaveCount(0)
        ->and($db->select("SELECT id, REGEX(name, '^al') AS r FROM limits_a WHERE r = 1"))->toHaveCount(1)
        ->and($db->select('SELECT id, ANY(x = 1 FOR x IN j.l) AS c FROM limits_a WHERE c = 1'))->toHaveCount(1)
        ->and($db->select('SELECT id, LENGTH(j.l) AS c FROM limits_a WHERE c = 2'))->toHaveCount(1)
        ->and($db->select('SELECT id, YEAR(ts) AS y FROM limits_a WHERE y = 1970'))->toHaveCount(2);
});

it('rolls back inserts but not updates, as the manual says', function () {
    // https://manual.manticoresearch.com/Data_creation_and_modification/Transactions
    $db = DB::connection('manticore');

    $db->unprepared('BEGIN');
    $db->statement('UPDATE limits_a SET n = 50 WHERE id = 1');
    $db->statement("INSERT INTO limits_a (id, name) VALUES (9, 'x')");
    $db->unprepared('ROLLBACK');

    expect($db->selectOne('SELECT n FROM limits_a WHERE id = 1')->n)->toBe(50)
        ->and($db->select('SELECT id FROM limits_a WHERE id = 9'))->toHaveCount(0);
});
