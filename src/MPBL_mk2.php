<?php declare(strict_types = 1);

class MPBL implements ArrayAccess, Countable {
	private int $cs = 64;
	private int $rs = 58;
	private int $pd = 3;
	private string $datapath;
	private string $srcdir;
	private string $format = 'png';
	private string $crop = 'default';
	private array $tdl;
	private array $cache_src = [];
	private WeakMap $cache_grid;

	function __construct(string $file, ?string $srcdir = null) {
		$this->datapath = $file;
		is_readable($this->datapath) && ([ // throws TypeError "Cannot assign null to property" if not found
			'cellSize' => $this->cs,
			'padding' => $this->pd,
			'textureDataList' => $this->tdl,
		] = json_decode( // throws JsonException
			json: file_get_contents($this->datapath),
			associative: true,
			flags: JSON_THROW_ON_ERROR
		)) || throw new Exception("Bad file [$file]");
		$this->rs = $this->cs - 2 * $this->pd;
		$this->set_dir($srcdir);
		$this->cache_grid = new WeakMap();
	}
	function __destruct() {
		foreach ($this->cache_src as $src)
			$src->clear();
	}
	private function extract_cell(Imagick $src, int $x, int $y): Imagick {
		return $src->getImageRegion($this->cs, $this->cs, $x, $y);
	}
	private function extract_inner(Imagick $src, int $x, int $y): Imagick {
		return $src->getImageRegion($this->rs, $this->rs, $x + $this->pd, $y + $this->pd);
	}
	private function gen_map(int $width, int $height): Generator {
		while (($height -= $this->cs) >= 0) // bottom to top
			for ($x = 0; $x < $width; $x += $this->cs)
				yield [$x, $height];
	}
	private function get_src(string $srcpath): Imagick {
		return $this->cache_src[$srcpath] ??= new Imagick($srcpath);
	}
	private function gen_grid(Imagick $src): Generator {
		foreach ($this->gen_map($src->getImageWidth(), $src->getImageHeight()) as [$x, $y])
			yield $this->extract_cell($src, $x, $y);
	}
	private function get_grid(Imagick $src): array {
		return $this->cache_grid[$src] ??= iterator_to_array($this->gen_grid($src));
	}
	private function build_image(array $td, array $grid): Imagick {
		$res = new Imagick();
		$width = intval(ceil($td['width'] / $this->rs) * $this->rs);
		$height = intval(ceil($td['height'] / $this->rs) * $this->rs);
		$res->newImage($width + 2 * $this->pd, $height + 2 * $this->pd, 'transparent');
		$i = 0;
		for ($y = $height - $this->rs; $y >= 0; $y -= $this->rs) // bottom to top
			for ($x = 0; $x < $width; $x += $this->rs) // negative offsets would break colorspace inheritance
				if ($td['transparentIndex'] != $cell = $td['cellIndexList'][$i++] ?? throw new Exception("Too few indexes [$i]"))
					$res->compositeImage($grid[$cell] ?? throw new Exception("Bad cell index [$cell]"), Imagick::COMPOSITE_COPY, $x, $y);
		match($this->crop) {
			'none' => true,
			'full' => $res->cropImage($td['width'], $td['height'], 0, $height - $td['height'] + $this->pd),
			default => $res->cropImage($width, $height, $this->pd, $this->pd)
		} || throw new Exception("Crop failed [{$this->crop}]");
		$res->setImagePage(0, 0, 0, 0); // safety measure

		$res->setImageProperty('nep:width', strval($td['width']));
		$res->setImageProperty('nep:height', strval($td['height']));
		$res->setImageProperty('nep:crop', $this->crop);
		return $res;
	}
	private function canonical_image(array $td): Imagick {
		return $this->build_image($td, $this->get_grid($this->get_src($this->srcdir.'/'.$td['atlasName'].'.png')));
	}
	private function gen_sprites(): Generator {
		foreach ($this->tdl as $td)
			yield $td['name'] => $this->canonical_image($td);
	}
	private function get_blob(Imagick $img): string {
		$img->setImageFormat($this->format); // throws ImagickException
		return $img->getImagesBlob();
	}
	private function name2key(string $name): bool|int {
		return array_search($name, array_column($this->tdl, 'name'));
	}
	private function data_byname(string $name): array {
		$key = $this->name2key($name);
		$key === false && throw new Exception("Sprite not found [$name]");
		return $this->tdl[$key];
	}
	private function img_byname(string $name): Imagick {
		return $this->canonical_image($this->data_byname($name));
	}
	private function write_img(Imagick $img, string $filepath, bool $keep = false): bool {
		//$img->stripImage(); // EXIF software is still a mess btw
		$img->writeImage($filepath); // throws ImagickException
		return $keep || $img->clear();
	}

	// ArrayAccess
	public function offsetExists(mixed $offset): bool {
		return match(gettype($offset)) {
			'integer' => isset($this->tdl[$offset]),
			'string' => $this->name2key($offset) !== false,
			default => false
		};
	}
	public function offsetGet(mixed $offset): mixed {
		return match(gettype($offset)) { // promiscuity is intended
			'integer' => $this->tdl[$offset] ?? throw new OutOfBoundsException("Bad offset [$offset]"), // array
			'string' => $this->img_byname($offset), // Imagick
			default => throw new TypeError("Bad offset [$offset]")
		};
	}
	public function offsetSet(mixed $offset, mixed $value): void { // I wonder if 'never' will work here lol
		throw new Exception("Nep [$offset=$value]");
	}
	public function offsetUnset(mixed $offset): void {
		throw new Exception("Nep [$offset]");
	}

	// Countable
	public function count(): int {
		return count($this->tdl);
	}

	// MPBL
	public function set_dir(?string $srcdir = null): bool {
		$this->srcdir = $srcdir ?? dirname($this->datapath);
		return is_dir($this->srcdir);
	}
	public function set_format(string $format = 'png'): bool {
		if (!Imagick::queryFormats(strtoupper($format)))
			return false;
		$this->format = strtolower($format);
		return true;
	}
	public function set_crop(string $crop = 'default'): bool {
		$this->crop = $crop; // should be implemented as enum
		return true;
	}
	public function print_and_exit(string $name, ?string $mime = null, ?callable $mutator = null): void /* 'never' for 8.1+ */ {
		$img = $this->img_byname($name);
		$img = $mutator === null ?: $mutator($img);
		$blob = $this->get_blob($img);
		$mime ??= "image/{$this->format}";
		headers_sent() || array_map('header', [
			"Content-Type: $mime",
			"Content-Disposition: inline; filename=$name.{$this->format}",
			"Content-Length: {$img->getImageLength()}",
			"X-Nep-Width: {$img->getImageProperty('nep:width')}",
			"X-Nep-Height: {$img->getImageProperty('nep:height')}",
			"X-Nep-Crop: {$img->getImageProperty('nep:crop')}"
		]); // getImageLength won't give the number until get_blob is called
		exit($blob);
	}
	public function get_sprites(): array {
		return iterator_to_array($this->gen_sprites());
	}
	function __invoke(string $outdir): bool {
		is_dir($outdir) && is_writable($outdir) || throw new Exception("Bad output directory [$outdir]");
		foreach ($this->gen_sprites() as $name => $img)
			$this->write_img($img, $outdir.'/'.$name.'.png');
		return true;
	}
}
