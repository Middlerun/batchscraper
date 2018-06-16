<?php

// Get last known beers
$SAVE_FILE = "last_known_beers.json";
$last_known_beers_json = @file_get_contents($SAVE_FILE);
if ($last_known_beers_json) {
  $last_known_beers = json_decode($last_known_beers_json, true);
}
if (!$last_known_beers_json || is_null($last_known_beers)) {
  $last_known_beers = [
    'on-tap' => [],
    'in-bottle' => []
  ];
}

function typeDisplayName($availability_type) {
  if ($availability_type === 'on-tap') return 'On tap';
  if ($availability_type === 'in-bottle') return 'In bottle';
  return 'in some form, apparently';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Current beers at Batch</title>
</head>
<body>
  <h1>Current beers at <a href="http://www.batchbrewingco.com.au/">Batch</a></h1>

  <?php foreach (array_keys($last_known_beers) as $availability_type): ?>
    <h2><?=typeDisplayName($availability_type)?></h2>

    <?php foreach ($last_known_beers[$availability_type] as $beer): ?>
      <p>
        <strong><?=htmlentities($beer['name_desc'])?></strong><br />
        <i><?=htmlentities($beer['style'])?> - <?=htmlentities($beer['abv'])?></i><br />
        <?=htmlentities($beer['description'])?>
      </p>
    <?php endforeach; ?>

  <?php endforeach; ?>
</body>
</html>
