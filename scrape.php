<?php

require 'vendor/autoload.php';
use \Mailjet\Resources;
$dotenv = new Dotenv\Dotenv(__DIR__);
$dotenv->load();

$mailjet_apikey = getenv('MAILJET_API_KEY');
$mailjet_apisecret = getenv('MAILJET_API_SECRET');

$mj = new \Mailjet\Client($mailjet_apikey, $mailjet_apisecret, true, ['version' => 'v3.1']);

$new_beers = getNewBeers();
$new_sours = array_filter($new_beers, function($beer) {
  return preg_match("/sour/i", $beer['style']);
});

echo count($new_sours) . " new sours found" . PHP_EOL;

if (count($new_sours) > 0) {
  emailBeerList($mj, $new_sours, 'sour');
}

function emailBeerList($mj, $beer_list, $beer_type) {
  $count = count($beer_list);
  $s = $count == 1 ? "" : "s";

  $body = [
    'Messages' => [
      [
        'From' => [
          'Email' => "middlerun@gmail.com",
          'Name' => "Batch beer notifier"
        ],
        'To' => [
          [
            'Email' => "middlerun@gmail.com",
            'Name' => "Eddie"
          ]
        ],
        'Subject' => "{$count} new {$beer_type} beer{$s} at Batch!",
        'HTMLPart' => "<h2>{$count} new {$beer_type} beer{$s} at Batch!</h2>" .
          implode('', array_map(function ($beer) {
            return "<p>" .
              "<strong>{$beer['name_desc']}</strong><br />" .
              "<i>{$beer['style']} - {$beer['abv']} - {$beer['availability_type']}</i><br />" .
              $beer['description'] .
            "</p>";
          }, $beer_list))
      ]
    ]
  ];

  $response = $mj->post(Resources::$Email, ['body' => $body]);
  echo ($response->success() ? 'sent' : 'not sent') . PHP_EOL;
  echo !$response->success() && var_dump($response->getData());
}

function getNewBeers() {
  $SAVE_FILE = "last_known_beers.json";

  // Fetch HTML from Batch website
  $html = file_get_contents('http://www.batchbrewingco.com.au/');
  $dom = new DOMDocument();
  $loaded = @$dom->loadHTML($html);

  if (!$loaded) {
    die("Failed to load website");
  }

  // Get last known beers
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

  // Parse HTML
  $body = $dom->getElementsByTagName('body')[0];
  $new_beers = [];

  foreach (['on-tap', 'in-bottle'] as $availability_type) {
    $beer_list = getBeersInDiv([$availability_type, 'accordion'], $body);
    $new_beers_of_type = array_udiff($beer_list, $last_known_beers[$availability_type], function ($beer1, $beer2) {
      return strcmp($beer1['name'], $beer2['name']);
    });
    $last_known_beers[$availability_type] = $beer_list;
    $new_beers_of_type = array_map(function ($beer) use ($availability_type) {
      $beer['availability_type'] = typeDisplayName($availability_type);
      return $beer;
    }, $new_beers_of_type);
    $new_beers = array_merge($new_beers, $new_beers_of_type);
  }

  $saved = file_put_contents($SAVE_FILE, json_encode($last_known_beers, JSON_PRETTY_PRINT));

  return $new_beers;
}

function typeDisplayName($availability_type) {
  if ($availability_type === 'on-tap') return 'on tap';
  if ($availability_type === 'in-bottle') return 'in bottle';
  return 'in some form, apparently';
}

function getBeersInDiv($div_classes, $body) {
  $main_div = searchForClass($body, $div_classes)[0];
  $beer_elements = searchForClass($main_div, 'accordion-item');
  $list = [];

  foreach ($beer_elements as $beer_element) {
    $name_elements = searchForClass($beer_element, 'product-link');
    $name_text = count($name_elements) > 0 ? getElementText($name_elements[0]) : '';
    $name = preg_replace("/ - .*$/", "", $name_text);
    $short_description = strpos($name_text, ' - ') !== false ? preg_replace("/^.* - /", "", $name_text) : null;

    $style_elements = searchForClass($beer_element, 'style');
    $style = count($style_elements) > 0 ? preg_replace("/^Style:\s*/", "", getElementText($style_elements[0])) : '';

    $abv_elements = searchForClass($beer_element, 'abv');
    $abv = count($abv_elements) > 0 ? preg_replace("/^ABV:\s*/", "", getElementText($abv_elements[0])) : '';

    $description_elements = searchForClass($beer_element, 'description');
    $description = count($description_elements) > 0 ? getElementText($description_elements[0]) : '';

    array_push($list, [
      'name' => $name,
      'name_desc' => $name_text,
      'short_description' => $short_description,
      'style' => $style,
      'abv' => $abv,
      'description' => $description
    ]);
  }

  return $list;
}

function getElementText(DOMElement $element) {
  $text = "";
  traverseDomNodes($element, function ($node) use (&$text) {
    if (get_class($node) === "DOMText") {
      $text .= $node->wholeText;
    }
  });
  return $text;
}

function searchForClass(DOMElement $element, $class, $recursive = true, $nested_matches = false, $debug = false) {
  return findElements($element, function ($element) use ($class) {
    return elementHasClass($element, $class);
  }, $recursive, $nested_matches, $debug);
}

function elementHasClass(DOMElement $element, $classes) {
  if (is_string($classes)) {
    $classes = [$classes];
  }
  $element_classes = ' ' . $element->getAttribute('class') . ' ';
  foreach ($classes as $class) {
    if (strpos($element_classes, ' ' . $class . ' ') === false)
      return false;
  }
  return true;
}

function findElements(DOMElement $element, callable $match_function, $recursive = true, $nested_matches = false, $debug = false, $depth = 0) {
  $results = [];
  $child_nodes = $element->childNodes;
  foreach ($child_nodes as $child) {
    if (get_class($child) != "DOMElement") continue;
    if ($debug) {
      for ($i=0; $i<=$depth; $i++) echo "  ";
      echo $child->tagName . PHP_EOL;
    }
    $should_recurse = $recursive;
    if ($match_function($child)) {
      array_push($results, $child);
      if (!$nested_matches) $should_recurse = false;
    }
    if ($should_recurse) {
      $results = array_merge($results, findElements($child, $match_function, true, $nested_matches, $debug, $depth + 1));
    }
  }

  return $results;
}

function traverseDomElements(DOMElement $element, $function = null) {
  if (is_callable($function)) {
    $function($element);
  }
  foreach ($element->childNodes as $child) {
    if (get_class($child) == "DOMElement") {
      traverseDomElements($child, $function);
    }
  }
}

function traverseDomNodes(DOMNode $node, $function = null) {
  if (is_callable($function)) {
    $function($node);
  }
  if ($node->childNodes) {
    foreach ($node->childNodes as $child) {
      traverseDomNodes($child, $function);
    }
  }
}

function domElementToString($element) {
  $newdoc = new DOMDocument();
  $cloned = $element->cloneNode(TRUE);
  $newdoc->appendChild($newdoc->importNode($cloned,false));
  return $newdoc->saveHTML();
}
