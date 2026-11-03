<?php
$src = '/tmp/badge_src.jpg';
$dst = '/www/wwwroot/biaobai/badge.png';
if (!function_exists('imagecreatefromjpeg')) { echo "NO_GD\n"; exit; }
$im = imagecreatefromjpeg($src);
if (!$im) { echo "LOAD_FAIL\n"; exit; }
$w = imagesx($im); $h = imagesy($im);
imagealphablending($im, false);
imagesavealpha($im, true);
$c0 = imagecolorat($im, 0, 0);
$c1 = imagecolorat($im, $w - 1, 0);
$c2 = imagecolorat($im, 0, $h - 1);
$c3 = imagecolorat($im, $w - 1, $h - 1);
$sr = $sg = $sb = 0;
foreach ([$c0, $c1, $c2, $c3] as $c) { $sr += (($c >> 16) & 0xFF); $sg += (($c >> 8) & 0xFF); $sb += ($c & 0xFF); }
$bgr = $sr / 4; $bgg = $sg / 4; $bgb = $sb / 4;
$tol = 72;
$trans = imagecolorallocatealpha($im, 255, 255, 255, 127);
$visited = new SplFixedArray($w * $h);
$stack = [[0, 0]];
while ($stack) {
    $p = array_pop($stack); $x = $p[0]; $y = $p[1];
    if ($x < 0 || $y < 0 || $x >= $w || $y >= $h) continue;
    $idx = $y * $w + $x;
    if ($visited[$idx]) continue;
    $visited[$idx] = true;
    $c = imagecolorat($im, $x, $y);
    $r = (($c >> 16) & 0xFF); $g = (($c >> 8) & 0xFF); $b = ($c & 0xFF);
    if (abs($r - $bgr) + abs($g - $bgg) + abs($b - $bgb) <= $tol) {
        imagesetpixel($im, $x, $y, $trans);
        $stack[] = [$x + 1, $y]; $stack[] = [$x - 1, $y]; $stack[] = [$x, $y + 1]; $stack[] = [$x, $y - 1];
    }
}
imagepng($im, $dst);
echo "MATTE_OK $dst (" . filesize($dst) . " bytes)\n";
