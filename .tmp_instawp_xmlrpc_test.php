<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);
require 'wp-includes/class-IXR.php';
$client = new IXR_Client('https://thankful-tapir-8706fd.instawp.site/xmlrpc.php');
$result = $client->query('wp.getUsersBlogs', 'jodasedoto0501', '8vmIJx3FLsHMQ5juDf1W');
var_dump($result);
if ($client->isError()) {
  var_dump($client->getErrorCode(), $client->getErrorMessage());
} else {
  var_dump($client->getResponse());
}
?>
