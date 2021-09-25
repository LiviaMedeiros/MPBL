<?php declare(strict_types = 1);

class MPBL {
	private int $cs = 64;
	private int $rs = 58;
	private int $pd = 3;
	private string $datapath;
	private string $srcdir;
	private array $tdl;
	private array $cache_src = [];
	private array $cache_cells = [];
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
		foreach ($this->cache_cells as $cells)
			if (is_array($cells))
				foreach ($cells as $cell)
					$cell->clear();
	}
	private function extract_cell(string $path, Imagick $src, int $x, int $y): Imagick {
		if (isset($this->cache_cells[$path][$x.'x'.$y]))
			return $this->cache_cells[$path][$x.'x'.$y];
		$tmp = clone $src;
		$tmp->cropImage($this->rs, $this->rs, $x + $this->pd, $y + $this->pd);
		$this->cache_cells[$path][$x.'x'.$y] = $tmp;
		return $tmp;
	}
	private function generate_grid(string $srcpath, array $res = []): array {
		if (isset($this->cache_grid[$srcpath]))
			return $this->cache_grid[$srcpath];
		$src = $this->cache_src[$srcpath] ??= new Imagick($srcpath);
		$src_width = $src->getImageWidth();
		$y = $src->getImageHeight();
		while (($y -= $this->cs) >= 0)
			for ($x = 0; $x < $src_width; $x += $this->cs)
				$res[] = $this->extract_cell($srcpath, $src, $x, $y);
		return $this->cache_grid[$srcpath] = $res;
	}
	private function build_rows(array $td, array $grid, array $res = []): array {
		$col = 0;
		$row = new Imagick();
		foreach ($td['cellIndexList'] as $cell) {
			$cell == $td['transparentIndex']
				? $row->newImage($this->rs, $this->rs, 'transparent')
				: $row->addImage($grid[$cell] ?? throw new Exception("Bad cell index [$cell]"));
			if (($col += $this->rs) > $td['width']) {
				$row->resetIterator();
				array_unshift($res, $row->appendImages(false)); // reverse order
				$row->clear();
				$row = new Imagick();
				$col = 0;
			}
		}
		$row->clear();
		return $res;
	}
	private function build_image(array $td, array $grid): Imagick {
		$full = new Imagick();
		foreach ($this->build_rows($td, $grid) as $row) {
			$full->addImage($row);
			$row->clear();
		}
		$full->resetIterator();
		$res = $full->appendImages(true);
		$full->clear();
		return $res;
	}
	private function canonical_image(array $td): array {
		return [
			'width' => $td['width'],
			'height' => $td['height'],
			'img' => $this->build_image($td, $this->generate_grid($this->srcdir.'/'.$td['atlasName'].'.png'))
		];
	}
	private function gen_textures(): Generator {
		foreach ($this->tdl as $td)
			yield $td['name'] => $this->canonical_image($td)['img'];
	}
	private function get_file(Imagick $img, string $format = 'png'): string {
		$img->setImageFormat($format); // throws ImagickException
		return $img->getImagesBlob();
	}
	public function data_byname(string $name): array {
		$key = array_search($name, array_column($this->tdl, 'name'));
		$key === false && throw new Exception("Texture not found [$name]");
		return $this->tdl[$key];
	}
	private function img_byname(string $name): array {
		return $this->canonical_image($this->data_byname($name));
	}
	private function print_raw(Imagick $img, string $format = 'png'): bool {
		return print($this->get_file($img, $format));
	}
	private function write_img(Imagick $img, string $filepath, bool $keep = false): bool {
		$img->setImagePage(0, 0, 0, 0);
		$img->stripImage();
		$img->writeImage($filepath); // throws ImagickException
		return $keep || $img->clear();
	}

	public function set_dir(?string $srcdir = null): bool {
		$this->srcdir = $srcdir ?? dirname($this->datapath);
		return is_dir($this->srcdir);
	}
	public function print_and_exit(string $name, string $format = 'png', ?string $mime = null): void /* 'never' for 8.1+ */ {
		headers_sent() && throw new Exception("Headers already sent");
		$imgdata = $this->img_byname($name);
		$mime ??= "image/$format";
		header("Content-Type: $mime");
		header("Content-disposition: inline; filename=$name.$format");
		header("X-Nep-Width: {$imgdata['width']}");
		header("X-Nep-Height: {$imgdata['height']}");
		$this->print_raw($imgdata['img'], $format) && exit();
	}
	public function get_textures(): array {
		return iterator_to_array($this->gen_textures());
	}
	function __invoke(string $outdir): bool {
		is_dir($outdir) && is_writable($outdir) || throw new Exception("Bad output directory [$outdir]");
		foreach ($this->gen_textures() as $name => $img)
			$this->write_img($img, $outdir.'/'.$name.'.png');
		return true;
	}
}
