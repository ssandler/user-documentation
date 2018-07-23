<?hh

enum autoload_failure_handler_t: int as int {
	Failure = 0;
	Success = 1;
	StopAutoloading = 2;
	ContinueAutoloading = 3;
	RetryAutoloading = 4;
}

require_once(__DIR__."/lib_defparse.php");

class AutoLoadConfig {
	const string FILE_PATTERN = '\.php$';

	public static function dirs(): array<string> {
		return [__DIR__, __DIR__."/../vendor"];
	}
}

function _autoload_map(\DefParse\autoload_map_t $map): void {

	# autoload paths are relative to webapp root, so we prepend the full path to webapp root using the second param
	if (!\HH\autoload_set_paths($map, dirname(__DIR__).DIRECTORY_SEPARATOR)) {
		throw new Exception("could not set autoload paths :(\n");
	}
}

# this is used during the staging process for production to build the autoload map for autoloaded directories
# it can then be shipped off to each host so that they don't have to build it themselves
function autoload_build_map(): \DefParse\autoload_map_t {
	return \DefParse\defparse_dirs(AutoLoadConfig::dirs(), true, AutoLoadConfig::FILE_PATTERN);
}

$map = \DefParse\defparse_dirs(AutoLoadConfig::dirs(), false, AutoLoadConfig::FILE_PATTERN);
_autoload_map($map);
