<?php
require __DIR__ . '/include/pagination.php';

$filename = 'fileset.php';
$stylesheet = 'style.css';
$jquery_file = 'https://code.jquery.com/jquery-3.7.0.min.js';
$js_file = 'js_functions.js';
echo "<link rel='stylesheet' href='{$stylesheet}'>\n";
echo "<script type='text/javascript' src='{$jquery_file}'></script>\n";
echo "<script type='text/javascript' src='{$js_file}'></script>\n";

function get_log_page($log_id) {
  $records_per_page = 25; // FIXME: Fetch this directly from logs.php
  return intdiv($log_id, $records_per_page) + 1;
}

$mysql_cred = json_decode(file_get_contents(__DIR__ . '/mysql_config.json'), true);
$servername = $mysql_cred["servername"];
$username = $mysql_cred["username"];
$password = $mysql_cred["password"];
$dbname = $mysql_cred["dbname"];

// Create connection
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli($servername, $username, $password);
$conn->set_charset('utf8mb4');
$conn->autocommit(FALSE);

// Check connection
if ($conn->connect_errno) {
  die("Connect failed: " . $conn->connect_error);
}

$conn->query("USE " . $dbname);

$min_id = $conn->query("SELECT MIN(id) FROM fileset")->fetch_array()[0];
if (!isset($_GET['id'])) {
  $id = $min_id;
}
else {
  $max_id = $conn->query("SELECT MAX(id) FROM fileset")->fetch_array()[0];
  $id = max($min_id, min($_GET['id'], $max_id));
  if ($conn->query("SELECT id FROM fileset WHERE id = {$id}")->num_rows == 0)
    $id = $conn->query("SELECT fileset FROM history WHERE oldfileset = {$id}")->fetch_array()[0];
}

$history = $conn->query("SELECT `timestamp`, oldfileset, log
FROM history WHERE fileset = {$id}
ORDER BY `timestamp`");


// Display fileset details
echo "<h2><u>Fileset: {$id}</u></h2>";

$result = $conn->query("SELECT * FROM fileset WHERE id = {$id}")->fetch_assoc();

echo "<h3>Fileset details</h3>";
echo "<table>\n";
if ($result['game']) {
  $temp = $conn->query("SELECT game.name as 'game name', engineid, gameid, extra, platform, language
FROM fileset JOIN game ON game.id = fileset.game JOIN engine ON engine.id = game.engine
WHERE fileset.id = {$id}");
  $result = array_merge($result, $temp->fetch_assoc());
}
else {
  unset($result['key']);
  unset($result['status']);
  unset($result['delete']);
}

foreach (array_keys($result) as $column) {
  if ($column == 'id' || $column == 'game')
    continue;

  echo "<th>{$column}</th>\n";
}

echo "<tr>\n";
foreach ($result as $column => $value) {
  if ($column == 'id' || $column == 'game')
    continue;

  echo "<td>{$value}</td>";
}
echo "</tr>\n";
echo "</table>\n";

echo "<h3>Files in the fileset</h3>";
echo "<form>";
// Preserve GET variables on form submit
foreach ($_GET as $k => $v) {
  if ($k == 'widetable')
    continue;

  $k = htmlspecialchars($k);
  $v = htmlspecialchars($v);
  echo "<input type='hidden' name='{$k}' value='{$v}'>";
}

// Come up with a better solution to set widetable=true on button click
// Currently uses hidden text input
if (isset($_GET['widetable']) && $_GET['widetable'] == 'true') {
  echo "<input class='hidden' type='text' name='widetable' value='false' />";
  echo "<input type='submit' value='Hide extra checksums' />";
}
else {
  echo "<input class='hidden' type='text' name='widetable' value='true' />";
  echo "<input type='submit' value='Expand Table' />";
}

echo "</form>";

// Table
echo "<table>\n";

$result = $conn->query("SELECT file.id, name, size, checksum, detection
  FROM file WHERE fileset = {$id}")->fetch_all(MYSQLI_ASSOC);

if (isset($_GET['widetable']) && $_GET['widetable'] == 'true') {
  foreach (array_values($result) as $index => $file) {
    $spec_checksum_res = $conn->query("SELECT checksum, checksize, checktype
    FROM filechecksum WHERE file = {$file['id']}");

    while ($spec_checksum = $spec_checksum_res->fetch_assoc()) {
      // md5-0 is skipped since it is already shown as file.checksum
      if ($spec_checksum['checksize'] == 0)
        continue;

      $result[$index][$spec_checksum['checktype'] . '-' . $spec_checksum['checksize']] = $spec_checksum['checksum'];
    }
  }
}

$counter = 1;
foreach ($result as $row) {
  if ($counter == 1) {
    echo "<th/>\n"; // Numbering column
    foreach (array_keys($row) as $index => $key) {
      if ($key == 'id')
        continue;

      echo "<th>{$key}</th>\n";
    }
  }

  echo "<tr>\n";
  echo "<td>{$counter}.</td>\n";
  foreach ($row as $key => $value) {
    if ($key == 'id')
      continue;

    echo "<td>{$value}</td>\n";
  }
  echo "</tr>\n";

  $counter++;
}
echo "</table>\n";

// Dev Actions
echo "<h3>Developer Actions</h3>";
echo "<button id='delete-button' type='button' onclick='delete_id({$id})'>Mark Fileset for Deletion</button>";

if (isset($_POST['delete'])) {
  $conn->query("UPDATE fileset SET `delete` = TRUE WHERE id = {$_POST['delete']}");
  $conn->commit();
}

echo "<p id='delete-confirm' class='hidden'>Fileset marked for deletion</p>"; // Hidden


// Display history
echo "<h3>Fileset history</h3>";
if ($history->num_rows == 0) {
  echo "<p>Fileset has no history.</p>";
}
else {
  echo "<table>\n";
  echo "<th>Old ID</th>";
  echo "<th>Changed on</th>";
  echo "<th>Log ID</th>";
  while ($row = $history->fetch_assoc()) {
    $log_page = get_log_page($row['log']);
    echo "<tr>\n";
    echo "<td>{$row['oldfileset']}</td>\n";
    echo "<td>{$row['timestamp']}</td>\n";
    echo "<td><a href='logs.php?page={$log_page}'>{$row['log']}</a></td>\n";
    echo "</tr>\n";
  }
  echo "</table>\n";
}

?>

