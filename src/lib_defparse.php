<?hh // strict

/*
 * DefParse --
 *
 *    A definition parser, suitable for use in an autoloader. It walks a directory looking
 *    for files that match a client-supplied pattern, and returns a forward index of file->definition
 *    mappings, a reverse index of symbol-type -> symbol>name -> file mappings suitable for
 *    \HH\autoload_set_paths, and an mtime in case clients want to cache this mapping.
 */

namespace DefParse;

type mtime_t = int;

const string DEBUG_ENV = 'SLACK_AUTOLOAD_DEBUG';

enum autoload_kind_t : string as string {
	CLS = 'class';
	FUNC = 'function';
	TYPE = 'type';
	CONSTANT = 'constant';
};

type autoload_map_t = array<autoload_kind_t, array<string, string>>;

function defparse_empty_autoload_map(): autoload_map_t {
	return [
		autoload_kind_t::CLS		=> [],
		autoload_kind_t::FUNC		=> [],
		autoload_kind_t::TYPE		=> [],
		autoload_kind_t::CONSTANT	=> [],
	];
}

function defparse_merge_map(autoload_map_t $l, autoload_map_t $r): autoload_map_t {
	foreach ($r as $kind => $symbols){
		foreach ($symbols as $sym => $file){
			$l[$kind][$sym] = $file;
		}
	}
	return $l;
}

type factparse_type_t = shape(
	'name'		=> string,
	'kindOf'	=> autoload_kind_t,
	'flags'		=> int,
	'baseTypes'	=> array<string>,
);

/*
 * Sight enhancement of the under-documented shape that \HH\facts_parse returns
 * per-file.
 */
type file_index_t = shape(
	'mtime'		=> mtime_t,
	'md5sum0'	=> int,
	'md5sum1'	=> int,

	'types'		=> array<factparse_type_t>,
	'functions'	=> array<string>,
	'constants'	=> array<string>,
	'typeAliases'	=> array<string>,
);

type index_t = shape(
	'mtime'	=> mtime_t,
	'path'	=> \Stringish,
	'map'	=> autoload_map_t,
	'files'	=> array<string, file_index_t>,
);

function defparse_dprintf(string $fmt, array<mixed> $args): void {
	if (\getenv(DEBUG_ENV)){
		\vprintf($fmt, $args);
	}
}

class DefParseImpl {
	protected index_t $index;

	private function loadFromCache(): bool {
		$rslt = $this->cache->get();
		if (!\is_null($rslt)){
			$this->index = $rslt;
			return true;
		}
		return false;
	}

	private function getMatchingFiles(): array<string> {
		$a = [];
		invariant($this->recursive, "Not implemented: non-recursion");
		$it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->path, \RecursiveDirectoryIterator::FOLLOW_SYMLINKS  | \RecursiveDirectoryIterator::SKIP_DOTS));
		$pat = \sprintf("/%s/", $this->filePattern);
		foreach ($it as $fi){
			if (\preg_match($pat, $fi->getPathname())){
				$a []= $fi->getPathname();
			}
		}
		return $a;
	}

	private function getRelativePath(string $path): string {
		# dirname strips /include off of the path, leaving something like /var/www/html/slack
		$webapp_root = \dirname(__DIR__);
		# trim any leading slashes after replacing out the web root
		return \ltrim(\str_replace($webapp_root, '', $path), \DIRECTORY_SEPARATOR);
	}

	private function updateFromParsed(autoload_kind_t $kind, string $file, array<string> $scanned_syms): void {
		foreach ($scanned_syms as $scanned_sym){
			$this->index['map'][$kind][$scanned_sym] = $file;
			defparse_dprintf("['$kind']['$scanned_sym'] = $file\n", []);
		}
	}

	private static function getClassNames(file_index_t $fi): array<string> {
		// Classes have to be lower-case in the autoload map, however
		// they are defined or used.
		return $fi |>
			\array_map(($fptype) ==> $fptype['name'], $$['types']) |>
			\array_map(fun('\strtolower'), $$);
	}

	private static function lowerNames(file_index_t $fi): file_index_t {
		// Case matters for constants. Classes, functions and strings
		// _must_ be lower-case in the autoload map, however they are
		// defined and used in source, though.
		$ret = $fi;
		$ret['functions'] = \array_map(fun('\strtolower'), $fi['functions']);
		$ret['typeAliases'] = \array_map(fun('\strtolower'), $fi['typeAliases']);
		return $ret;
	}

	private function loadFromScratch(): void {
		/* HH_FIXME[2049]: I swear this interface exists! */
		/* HH_FIXME[4107]: I swear this interface exists! */
		$deffos = \HH\facts_parse($this->path,
			$this->getMatchingFiles(),
			false, // force_hh
			true); // Use threads
		foreach ($deffos as $file => $file_data){
			if (\is_null($file_data)) continue;

			/* HH_FIXME[4110]: HHVM doesn't know file_index_t's shape. */
			$file_data = self::lowerNames($file_data);
			$fi = shape(
				'mtime'		=> \filemtime($file),
				'md5sum0'	=> $file_data['md5sum0'],
				'md5sum1'	=> $file_data['md5sum1'],
				'functions'	=> $file_data['functions'],
				'constants'	=> $file_data['constants'],
				'typeAliases'	=> $file_data['typeAliases'],
				'types'		=> $file_data['types'],
			);

			$file_relative = $this->getRelativePath($file);

			/* HH_FIXME[4110]: facts_parse has an imprecise hhi */
			$this->index['files'][$file_relative] = $fi;
			$fi = $this->index['files'][$file_relative];
			$symbols = [
				/* HH_FIXME[4110]: facts_parse has an imprecise hhi */
				autoload_kind_t::CLS => self::getClassNames($fi),
				autoload_kind_t::FUNC => $fi['functions'],
				autoload_kind_t::TYPE => $fi['typeAliases'],
				autoload_kind_t::CONSTANT => $fi['constants'],
			];
			foreach ($symbols as $kind => $syms){
				$this->updateFromParsed($kind, $file_relative, $syms);
			}
			$total = $symbols
				|> \array_map(($x) ==> \count($x), $$)
				|> \array_reduce($$, ($x, $y) ==> $x + $y);
			defparse_dprintf("updated %d symbols from %s\n", [$total, $file]);
		}
		$this->index['mtime'] = defparse_get_recursive_mtime($this->path);
	}

	public function __construct(
		protected CacheStore<index_t> $cache,
		protected string $path,
		protected string $filePattern = '\.php$',
		protected bool $recursive = true){
		$this->index = shape(
			'mtime'	=> 0,
			'path'	=> $path,
			'map'	=> defparse_empty_autoload_map(),
			'files' => [],
		);

		if (!$this->loadFromCache()){
			$this->loadFromScratch();
			$this->cache->set($this->index);
		}
		invariant(!\is_null($this->index), 'Init failed somehow!');
	}

	public function getIndex(): index_t {
		return $this->index;
	}
}

/*
 * Caching. We may want to cache in a file, in APC, in MemCache, etc.
 * Here's how we'll make this thinger work.
 */
abstract class CacheStore<TPayload> {
	const int INDEX_VERSION = 2;
	const string INDEX_PREFIX = 'tree_cache:/';

	public function __construct(){ }

	public function get(): ?TPayload {
		$result = $this->fetchImpl();
		if (\is_null($result)) return null;
		if (!$this->validate($result)) return null;
		return $result;
	}

	abstract protected function fetchImpl(): ?TPayload;
	abstract public function set(TPayload $val): void;
	abstract protected function validate(TPayload $val): bool;

	abstract public function flush(): void;
}

class APCCache extends CacheStore<index_t> {
	protected string $key;

	public function __construct(
		protected string $path,
		protected string $filePattern = '\.php$',
		protected bool $recursive = true
	){
		parent::__construct();
		$this->key = \sprintf("%s:%d:%s:%s:%d",
			self::INDEX_PREFIX,
			self::INDEX_VERSION,
			$this->path,
			$this->filePattern,
			(int)\intval($this->recursive));
	}

	public function fetchImpl(): ?index_t {
		$success = false;
		$val = \apc_fetch($this->key, &$success);
		if (!$success) return null;
		return $val;
	}

	public function set(index_t $val): void {
		\apc_store($this->key, $val);
	}

	public function flush(): void {
		\apc_delete($this->key);
	}

	protected function validate(index_t $val): bool {
		$mtime = defparse_get_recursive_mtime($this->path);
		# 5 second fudge factor to avoid race conditions during environment syncing, since mtime resolution is in seconds
		if ($mtime > $val['mtime'] + 5){
			defparse_dprintf("Cache load failed: mtime!: %d vs %d", [$mtime, $val['mtime']]);
			return false;
		}
		return true;
	}
}

function defparse_dirs(array<string> $dirs = [__DIR__], bool $flush_cache = false, string $file_pattern = '\.php$'): autoload_map_t {
	$map = \DefParse\defparse_empty_autoload_map();
	foreach ($dirs as $dir){
		if (!\is_dir($dir)) throw new \Exception("No such directory: $dir");
		$cache = new APCCache($dir, $file_pattern);
		if ($flush_cache){
			$cache->flush();
		}
		$parser = new DefParseImpl($cache, $dir, $file_pattern);
		$map = defparse_merge_map($map, $parser->getIndex()['map']);
	}
	return $map;
}

function defparse_flush(string $path = __DIR__,
	string $file_pattern = '\.php$',
	bool $recursive = true): void {
	(new APCCache($path, $file_pattern, $recursive))->flush();
}

# mtime on a directory is only modified when files immediately in that directory are updated
# for the purposes of APC caching in this library, we need to know the largest mtime of any subdirectory of the directories we autoload
# this means traversing into each subdir
<<_Memoize>>
function defparse_get_recursive_mtime(string $path): int {
	$it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::FOLLOW_SYMLINKS | \RecursiveDirectoryIterator::SKIP_DOTS));

	$max_mtime = (int)\filemtime($path);
	# find all subdirectories of $path
	foreach ($it as $fi){
		# don't bother checking mtime on ".." subdirs which point back to their parents
		if ($fi->isDir() && $fi->getBasename() !== '..'){
			$mtime = $fi->getMTime();
			if ($mtime > $max_mtime){
				$max_mtime = $mtime;
			}
		}
	}

	return $max_mtime;
}
