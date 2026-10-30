<?php
/*
 * The Data Tables page became the Uploaded tables tab of the Data Sources page. This keeps old
 * bookmarks and links working by sending them there.
 */

/** @var \UKModules\ADIF\ADIF $module */

header('Location: ' . $module->getUrl('pages/Data_Sources.php') . '&tab=tables', true, 302);
exit;
