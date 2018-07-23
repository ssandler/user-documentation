<?hh

require_once(__DIR__.'/../src/autoload.php');

$request = \Zend\Diactoros\ServerRequestFactory::fromGlobals();

HH\Asio\join(
  HHVMDocumentationSite::respondToAsync($request)
);
