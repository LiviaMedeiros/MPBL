<?php declare(strict_types = 1);

class MPBL {
	private int $cs = 64;
	private int $rs = 58;
	private int $pd = 3;
	private string $datapath;
	private string $srcdir;
	private string $format = 'png';
	private array $tdl;
	private array $cache_src = [];
	private array $cache_grid = [];

	function __construct(string $file, ?string $srcdir = null) {
		$this->datapath = $file;
		is_readable($this->datapath) && ([ // throws TypeError "Cannot assign null to property" if not found
			'cellSize' => $this->cs,
			'padding' => $this->pd,
			'textureDataList' => $this->tdl,
		] = json_decode(file_get_contents($this->datapath), true)) || throw new Exception("Bad file [$file]");
		$this->rs = $this->cs - 2 * $this->pd;
		$this->set_dir($srcdir);
	}
	function __destruct() {
		foreach ($this->cache_src as $src)
			$src->clear();
	}
	private function extract_cell(Imagick $src, int $x, int $y): Imagick {
		return $src->getImageRegion($this->cs, $this->cs, $x, $y);
	}
	private function generate_grid(string $srcpath, array $res = []): array {
		if (isset($this->cache_grid[$srcpath]))
			return $this->cache_grid[$srcpath];
		$src = $this->cache_src[$srcpath] ??= new Imagick($srcpath);
		$src_width = $src->getImageWidth();
		$y = $src->getImageHeight();
		while (($y -= $this->cs) >= 0)
			for ($x = 0; $x < $src_width; $x += $this->cs)
				$res[] = $this->extract_cell($src, $x, $y);
		return $this->cache_grid[$srcpath] = $res;
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
		$res->cropImage($width, $height, $this->pd, $this->pd);
		$res->setImagePage(0, 0, 0, 0); // safety measure
		$res->setImageProperty('nep:width', strval($td['width']));
		$res->setImageProperty('nep:height', strval($td['height']));
		return $res;
	}
	private function canonical_image(array $td): Imagick {
		return $this->build_image($td, $this->generate_grid($this->srcdir.'/'.$td['atlasName'].'.png'));
	}
	private function gen_sprites(): Generator {
		foreach ($this->tdl as $td)
			yield $td['name'] => $this->canonical_image($td);
	}
	private function get_blob(Imagick $img): string {
		$img->setImageFormat($this->format); // throws ImagickException
		return $img->getImagesBlob();
	}
	private function data_byname(string $name): array {
		$key = array_search($name, array_column($this->tdl, 'name'));
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
	public function print_and_exit(string $name, ?string $mime = null): void /* 'never' for 8.1+ */ {
		headers_sent() && throw new Exception("Headers already sent");
		$img = $this->img_byname($name);
		$blob = $this->get_blob($img);
		$mime ??= "image/{$this->format}";
		header("Content-Type: $mime");
		header("Content-Disposition: inline; filename=$name.{$this->format}");
		header("Content-Length: {$img->getImageLength()}");
		header("X-Nep-Width: {$img->getImageProperty('nep:width')}");
		header("X-Nep-Height: {$img->getImageProperty('nep:height')}");
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
