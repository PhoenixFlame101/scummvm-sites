<?php
require $_SERVER['DOCUMENT_ROOT'] . '/include/user_fileset_functions.php';

header('Access-Contol-Allow-Origin: *');
header('Content-Type: application/json');

$conn = db_connect();

$error_codes = array(
  "unknown" => -1,
  "success" => 0,
  "empty" => 2
);

$json_string = file_get_contents('php://input');
$json_object = json_decode($json_string);

$game_metadata = array();
foreach ($json_object as $key => $value) {
  if ($key == 'files')
    continue;

  $game_metadata[$key] = $value;
}

$json_response = array(
  'error' => $error_codes['success'],
  'files' => array()
);

if (count($game_metadata) == 0) {
  if (count($json_object->files) == 0) {
    $json_response['error'] = $error_codes['empty'];
    unset($json_response['files']);
    $json_response['status'] = 'empty_fileset';


    $json_response = json_encode($json_response);
    echo $json_response;
    return;
  }

  $json_response['error'] = $error_codes['unknown'];
  unset($json_response['files']);
  $json_response['status'] = 'unknown_variant';

  $fileset_id = user_insert_fileset($json_object->files, $conn);
  $json_response['fileset'] = $fileset_id;

  $json_response = json_encode($json_response);
  echo $json_response;
  return;
}

// Find game(s) that fit the metadata
$query = "SELECT game.id FROM game
JOIN engine ON game.engine = engine.id
WHERE gameid = '{$game_metadata['gameid']}'
AND engineid = '{$game_metadata['engineid']}'
AND extra = '{$game_metadata['extra']}'
AND platform = '{$game_metadata['platform']}'
AND language = '{$game_metadata['language']}'";
$games = $conn->query($query);

if ($games->num_rows == 0) {
  $json_response['error'] = $error_codes['unknown'];
  unset($json_response['files']);
  $json_response['status'] = 'unknown_variant';

  $fileset_id = user_insert_fileset($json_object->files, $conn);
  $json_response['fileset'] = $fileset_id;
}

// Check if all files in fullmatch filesets are present with user
while ($game = $games->fetch_array()) {
  $fileset = $conn->query("SELECT file.id, name, size FROM file
  JOIN fileset ON fileset.id = file.fileset
  WHERE fileset.game = {$game['id']} AND fileset.status = 'fullmatch'");

  if ($fileset->num_rows == 0)
    continue;

  // Convert checktype, checksize to checkcode
  $fileset = $fileset->fetch_all(MYSQLI_ASSOC);
  foreach (array_values($fileset) as $index => $file) {
    $spec_checksum_res = $conn->query("SELECT checksum, checksize, checktype
    FROM filechecksum WHERE file = {$file['id']}");

    while ($spec_checksum = $spec_checksum_res->fetch_assoc()) {
      $fileset[$index][$spec_checksum['checktype'] . '-' . $spec_checksum['checksize']] = $spec_checksum['checksum'];
    }
  }

  $file_object = $json_object->files;

  // Sort the filesets by filename
  usort($file_object, function ($a, $b) {
    return strcmp($a->name, $b->name);
  });
  usort($fileset, function ($a, $b) {
    return strcmp($a['name'], $b['name']);
  });

  for ($i = 0, $j = 0; $i < count($fileset), $j < count($file_object); $i++, $j++) {
    $status = 'ok';
    $db_file = $fileset[$i];
    $user_file = $file_object[$j];
    $filename = $user_file->name;

    if ($db_file['name'] != $user_file->name) {
      if ($db_file['name'] > $user_file->name) {
        $status = 'unknown_file';
        $i--; // Retain same db_file for next iteration
      }
      else {
        $status = 'missing';
        $filename = $db_file['name'];
        $j--; // Retain same user_file for next iteration
      }
    }
    elseif ($db_file['size'] != $user_file->size && $status == 'ok') {
      $status = 'size_mismatch';
    }

    if ($status == 'ok') {
      foreach ($user_file->checksums as $checksum_data) {
        foreach ($checksum_data as $key => $value) {
          $user_checkcode = $checksum_data->type;
          // If it's not the full checksum
          if (strpos($user_checkcode, '-') !== false)
            continue;

          $user_checksum = $checksum_data->checksum;
          $user_checkcode .= '-0';

          if (strcasecmp($db_file[$user_checkcode], $user_checksum) != 0)
            $status = 'checksum_mismatch';

          break;
        }
      }
    }

    if ($status != 'ok')
      $json_response['error'] = 1;

    array_push($json_response['files'], array('status' => $status, 'name' => $filename));
  }
}

$json_response = json_encode($json_response);
echo $json_response;
?>

