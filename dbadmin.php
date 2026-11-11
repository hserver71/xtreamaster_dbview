<?php
/**
 * Lightweight phpMyAdmin-style browser for the Xtream Masters panel DB.
 * Credentials mirror balancer --preflight (licence DSN).
 */
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);
set_time_limit(0);

const DB_HOST = '45.155.90.47';
const DB_PORT = 7999;
const DB_USER = 'xtream_masters_user';
const DB_PASS = 'X2417eoNPsmTlyb';
const DB_NAME = 'xtreammasters';
const PAGE_SIZE = 50;

function db(): mysqli
{
    static $m = null;
    if ($m instanceof mysqli) {
        return $m;
    }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $m = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
    $m->set_charset('utf8mb4');
    return $m;
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function valid_table(mysqli $m, string $name): bool
{
    if ($name === '' || !preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        return false;
    }
    $st = $m->prepare(
        'SELECT 1 FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
    );
    $st->bind_param('s', $name);
    $st->execute();
    $st->store_result();
    return $st->num_rows > 0;
}

function table_list(mysqli $m): array
{
    $sql = "SELECT TABLE_NAME AS name,
                   TABLE_ROWS AS approx_rows,
                   ENGINE AS engine,
                   DATA_LENGTH + INDEX_LENGTH AS bytes
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
            ORDER BY TABLE_NAME";
    $out = [];
    $res = $m->query($sql);
    while ($row = $res->fetch_assoc()) {
        $out[] = $row;
    }
    return $out;
}

function sql_literal($v): string
{
    if ($v === null) {
        return 'NULL';
    }
    if (is_int($v) || is_float($v)) {
        return (string)$v;
    }
    return "'" . db()->real_escape_string((string)$v) . "'";
}

function dump_table(mysqli $m, string $table): void
{
    $safe = '`' . str_replace('`', '``', $table) . '`';
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $table . '_' . date('Ymd_His') . '.sql"');
    header('X-Content-Type-Options: nosniff');

    echo "-- Dump of {$table}\n";
    echo '-- Host: ' . DB_HOST . ':' . DB_PORT . " / " . DB_NAME . "\n";
    echo '-- Generated: ' . date('c') . "\n\n";
    echo "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";

    $createRes = $m->query("SHOW CREATE TABLE {$safe}");
    $createRow = $createRes->fetch_assoc();
    $createSql = $createRow['Create Table'] ?? $createRow['Create View'] ?? null;
    if ($createSql) {
        echo "DROP TABLE IF EXISTS {$safe};\n";
        echo $createSql . ";\n\n";
    }

    $res = $m->query("SELECT * FROM {$safe}", MYSQLI_USE_RESULT);
    $cols = [];
    $fields = $res->fetch_fields();
    foreach ($fields as $f) {
        $cols[] = '`' . str_replace('`', '``', $f->name) . '`';
    }
    $colList = implode(', ', $cols);
    $batch = [];
    $batchSize = 100;
    $n = 0;

    while ($row = $res->fetch_row()) {
        $vals = [];
        foreach ($row as $v) {
            $vals[] = sql_literal($v);
        }
        $batch[] = '(' . implode(', ', $vals) . ')';
        $n++;
        if (count($batch) >= $batchSize) {
            echo "INSERT INTO {$safe} ({$colList}) VALUES\n" . implode(",\n", $batch) . ";\n";
            $batch = [];
            if (function_exists('ob_flush')) {
                @ob_flush();
            }
            @flush();
        }
    }
    if ($batch) {
        echo "INSERT INTO {$safe} ({$colList}) VALUES\n" . implode(",\n", $batch) . ";\n";
    }
    echo "\nSET FOREIGN_KEY_CHECKS=1;\n-- Done: {$n} rows\n";
    $res->free();
    exit;
}

$m = db();
$action = $_GET['action'] ?? '';
$table = (string)($_GET['table'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));

if ($action === 'dump') {
    if (!valid_table($m, $table)) {
        http_response_code(400);
        exit('Invalid table');
    }
    dump_table($m, $table);
}

$tables = table_list($m);
$selected = valid_table($m, $table) ? $table : null;
$rows = [];
$cols = [];
$total = 0;
$pages = 0;
$error = '';

if ($selected) {
    $safe = '`' . str_replace('`', '``', $selected) . '`';
    try {
        $total = (int)$m->query("SELECT COUNT(*) FROM {$safe}")->fetch_row()[0];
        $pages = max(1, (int)ceil($total / PAGE_SIZE));
        $page = min($page, $pages);
        $offset = ($page - 1) * PAGE_SIZE;
        $res = $m->query("SELECT * FROM {$safe} LIMIT " . PAGE_SIZE . " OFFSET {$offset}");
        $cols = array_map(static fn($f) => $f->name, $res->fetch_fields());
        while ($r = $res->fetch_assoc()) {
            $rows[] = $r;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$self = h(basename($_SERVER['SCRIPT_NAME'] ?? 'dbadmin.php'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>DB · <?= h(DB_NAME) ?></title>
<style>
:root {
  --bg: #1a1d23;
  --panel: #22262e;
  --panel2: #2a303a;
  --border: #3a4250;
  --text: #e8ecf1;
  --muted: #9aa3b2;
  --accent: #3d8bfd;
  --accent2: #2f6fdb;
  --hover: #2f3642;
  --ok: #3dd68c;
  --warn: #f0b429;
}
* { box-sizing: border-box; }
html, body { height: 100%; margin: 0; }
body {
  font: 13px/1.45 ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
  background: var(--bg);
  color: var(--text);
  display: flex;
  flex-direction: column;
}
a { color: var(--accent); text-decoration: none; }
a:hover { text-decoration: underline; }
header {
  display: flex; align-items: center; gap: 16px;
  padding: 10px 16px; background: var(--panel);
  border-bottom: 1px solid var(--border); flex-shrink: 0;
}
header .brand { font-weight: 700; letter-spacing: .02em; }
header .meta { color: var(--muted); font-size: 12px; }
header .meta code { color: var(--ok); }
.layout { display: flex; flex: 1; min-height: 0; }
aside {
  width: 280px; flex-shrink: 0; overflow: auto;
  background: var(--panel); border-right: 1px solid var(--border);
}
aside .side-head {
  position: sticky; top: 0; z-index: 1;
  padding: 10px 12px; background: var(--panel2);
  border-bottom: 1px solid var(--border);
  display: flex; justify-content: space-between; align-items: center;
}
aside .side-head input {
  width: 100%; margin-top: 8px; padding: 6px 8px;
  border: 1px solid var(--border); border-radius: 4px;
  background: var(--bg); color: var(--text);
}
aside table { width: 100%; border-collapse: collapse; }
aside td { padding: 6px 10px; border-bottom: 1px solid #2c323c; }
aside tr:hover td { background: var(--hover); }
aside tr.active td { background: #1e3a5f; }
aside .tname { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 12px; }
aside .cnt { text-align: right; color: var(--muted); font-variant-numeric: tabular-nums; white-space: nowrap; }
main { flex: 1; overflow: auto; padding: 16px; }
.toolbar {
  display: flex; flex-wrap: wrap; gap: 10px; align-items: center;
  margin-bottom: 12px;
}
.toolbar h1 {
  margin: 0; font-size: 18px; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}
.btn {
  display: inline-block; padding: 6px 12px; border-radius: 4px;
  background: var(--accent); color: #fff; border: 0; cursor: pointer;
  font-weight: 600; text-decoration: none;
}
.btn:hover { background: var(--accent2); text-decoration: none; color: #fff; }
.btn.secondary { background: var(--panel2); border: 1px solid var(--border); color: var(--text); }
.pager { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; color: var(--muted); }
.pager a, .pager span {
  display: inline-block; min-width: 28px; text-align: center;
  padding: 4px 8px; border-radius: 4px; border: 1px solid var(--border);
  background: var(--panel); color: var(--text); text-decoration: none;
}
.pager a:hover { border-color: var(--accent); }
.pager .cur { background: var(--accent); border-color: var(--accent); color: #fff; }
.grid-wrap { overflow: auto; border: 1px solid var(--border); border-radius: 6px; background: var(--panel); }
table.data { border-collapse: collapse; min-width: 100%; }
table.data th, table.data td {
  padding: 6px 10px; border-bottom: 1px solid var(--border);
  border-right: 1px solid #2c323c; vertical-align: top;
  max-width: 360px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
table.data th {
  position: sticky; top: 0; background: var(--panel2);
  text-align: left; font-size: 12px; color: var(--muted); z-index: 1;
}
table.data tr:hover td { background: var(--hover); }
table.data td.null { color: #6b7380; font-style: italic; }
.empty { color: var(--muted); padding: 40px; text-align: center; }
.err { background: #3b1d1d; color: #ffb4b4; padding: 10px 12px; border-radius: 6px; margin-bottom: 12px; }
.badge { font-size: 11px; color: var(--muted); }
</style>
</head>
<body>
<header>
  <div class="brand">DB Admin</div>
  <div class="meta">
    <code><?= h(DB_NAME) ?></code>
    @ <?= h(DB_HOST) ?>:<?= DB_PORT ?>
    · user <?= h(DB_USER) ?>
    · <?= count($tables) ?> tables
  </div>
</header>
<div class="layout">
  <aside>
    <div class="side-head">
      <div>
        <strong>Tables</strong>
        <div class="badge">approx rows (InnoDB)</div>
        <input type="search" id="filter" placeholder="Filter tables…" autocomplete="off">
      </div>
    </div>
    <table id="tables">
      <tbody>
      <?php foreach ($tables as $t):
          $name = $t['name'];
          $active = $selected === $name;
          $cnt = number_format((int)$t['approx_rows']);
      ?>
        <tr class="<?= $active ? 'active' : '' ?>" data-name="<?= h(strtolower($name)) ?>">
          <td class="tname">
            <a href="?table=<?= urlencode($name) ?>"><?= h($name) ?></a>
          </td>
          <td class="cnt"><?= h($cnt) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </aside>
  <main>
    <?php if ($error): ?>
      <div class="err"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if (!$selected): ?>
      <div class="empty">Select a table on the left to browse rows.</div>
    <?php else: ?>
      <div class="toolbar">
        <h1><?= h($selected) ?></h1>
        <span class="badge"><?= number_format($total) ?> rows · page <?= $page ?>/<?= $pages ?> · <?= PAGE_SIZE ?>/page</span>
        <a class="btn" href="?action=dump&amp;table=<?= urlencode($selected) ?>">Dump SQL</a>
        <a class="btn secondary" href="?table=<?= urlencode($selected) ?>&amp;page=1">Refresh</a>
      </div>

      <?php if ($pages > 1): ?>
      <div class="pager" style="margin-bottom:12px">
        <?php
          $mk = static function (int $p) use ($selected): string {
              return '?table=' . urlencode($selected) . '&page=' . $p;
          };
          if ($page > 1) {
              echo '<a href="' . h($mk(1)) . '">&laquo;</a>';
              echo '<a href="' . h($mk($page - 1)) . '">&lsaquo;</a>';
          }
          $start = max(1, $page - 4);
          $end = min($pages, $page + 4);
          for ($i = $start; $i <= $end; $i++) {
              if ($i === $page) {
                  echo '<span class="cur">' . $i . '</span>';
              } else {
                  echo '<a href="' . h($mk($i)) . '">' . $i . '</a>';
              }
          }
          if ($page < $pages) {
              echo '<a href="' . h($mk($page + 1)) . '">&rsaquo;</a>';
              echo '<a href="' . h($mk($pages)) . '">&raquo;</a>';
          }
        ?>
      </div>
      <?php endif; ?>

      <div class="grid-wrap">
        <?php if (!$rows): ?>
          <div class="empty">No rows.</div>
        <?php else: ?>
        <table class="data">
          <thead>
            <tr>
              <?php foreach ($cols as $c): ?>
                <th title="<?= h($c) ?>"><?= h($c) ?></th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $row): ?>
              <tr>
                <?php foreach ($cols as $c):
                    $v = $row[$c];
                    if ($v === null) {
                        echo '<td class="null">NULL</td>';
                    } else {
                        $s = (string)$v;
                        $short = strlen($s) > 200 ? substr($s, 0, 200) . '…' : $s;
                        echo '<td title="' . h($s) . '">' . h($short) . '</td>';
                    }
                endforeach; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>

      <?php if ($pages > 1): ?>
      <div class="pager" style="margin-top:12px">
        <?php
          if ($page > 1) {
              echo '<a href="' . h($mk(1)) . '">&laquo;</a>';
              echo '<a href="' . h($mk($page - 1)) . '">&lsaquo;</a>';
          }
          for ($i = $start; $i <= $end; $i++) {
              if ($i === $page) {
                  echo '<span class="cur">' . $i . '</span>';
              } else {
                  echo '<a href="' . h($mk($i)) . '">' . $i . '</a>';
              }
          }
          if ($page < $pages) {
              echo '<a href="' . h($mk($page + 1)) . '">&rsaquo;</a>';
              echo '<a href="' . h($mk($pages)) . '">&raquo;</a>';
          }
        ?>
      </div>
      <?php endif; ?>
    <?php endif; ?>
  </main>
</div>
<script>
document.getElementById('filter').addEventListener('input', function () {
  var q = this.value.trim().toLowerCase();
  document.querySelectorAll('#tables tr').forEach(function (tr) {
    tr.style.display = !q || tr.dataset.name.indexOf(q) !== -1 ? '' : 'none';
  });
});
</script>
</body>
</html>
