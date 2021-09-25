<?php declare(strict_types = 1);
require(__DIR__.'/src/MPBL.php');

$nep = new MPBL($argv[1] ?? '/megamira/texture/character/stand/1130700_stand.json'); // 憧れた紫色の輝き

if (!empty($_GET['name'])) {
	$nep->set_format('webp');
	$nep->print_and_exit($_GET['name']);
}

$ret = $nep($argv[2] ?? '/tmp/MPBL'); // directory must exist

exit($ret ? 0 : 1);
