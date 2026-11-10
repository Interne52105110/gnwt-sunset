<?php

/**
 * @file
 * Draws a 1200x630 share card (Open Graph image) with PHP GD.
 *
 * Usage: php social-card.php <out.png> <font-regular.ttf> <font-bold.ttf>
 *   "<line 1>" "<line 2>" "<accent line>" "<subtitle>" "<footer>" [monogram]
 *
 * Without a monogram, the Sunset mark (sun, horizon, arrow) is drawn.
 */

declare(strict_types=1);

[, $out, $regular, $bold, $line1, $line2, $accent, $subtitle, $footer, $monogram] = $argv + array_fill(0, 10, '');

$w = 1200;
$h = 630;
$img = imagecreatetruecolor($w, $h);
imagealphablending($img, TRUE);
imagefill($img, 0, 0, imagecolorallocate($img, 8, 23, 34));

// Aurora: soft blobs of colour, blurred.
$blobs = [
  [230, 120, [142, 240, 176]],
  [640, 40, [46, 196, 182]],
  [1050, 160, [108, 124, 255]],
];
foreach ($blobs as [$cx, $cy, [$r, $g, $b]]) {
  for ($radius = 420; $radius > 0; $radius -= 6) {
    $alpha = (int) max(0, min(127, 127 - (420 - $radius) / 420 * 30));
    imagefilledellipse($img, $cx, $cy, $radius * 2, (int) ($radius * 1.2), imagecolorallocatealpha($img, $r, $g, $b, $alpha));
  }
}
for ($i = 0; $i < 25; $i++) {
  imagefilter($img, IMG_FILTER_GAUSSIAN_BLUR);
}
// Darken the lower part so text stays readable.
for ($y = 0; $y < $h; $y++) {
  $alpha = (int) max(0, 127 - ($y / $h) * 110);
  imageline($img, 0, $y, $w, $y, imagecolorallocatealpha($img, 8, 23, 34, $alpha));
}

$white = imagecolorallocate($img, 255, 255, 255);
$soft = imagecolorallocatealpha($img, 255, 255, 255, 30);
$mint = imagecolorallocate($img, 142, 240, 176);
$night = imagecolorallocate($img, 15, 42, 61);

// Mark: a rounded square, then either a monogram or the Sunset mark.
$x0 = 80;
$y0 = 70;
$fill = $monogram !== '' ? $mint : $night;
imagefilledrectangle($img, $x0 + 14, $y0, $x0 + 82, $y0 + 96, $fill);
imagefilledrectangle($img, $x0, $y0 + 14, $x0 + 96, $y0 + 82, $fill);
foreach ([[14, 14], [82, 14], [14, 82], [82, 82]] as [$dx, $dy]) {
  imagefilledellipse($img, $x0 + $dx, $y0 + $dy, 28, 28, $fill);
}
if ($monogram !== '') {
  $box = imagettfbbox(36, 0, $bold, $monogram);
  $textWidth = $box[2] - $box[0];
  imagettftext($img, 36, 0, (int) ($x0 + 48 - $textWidth / 2), $y0 + 66, imagecolorallocate($img, 8, 23, 34), $bold, $monogram);
}
else {
  imagefilledarc($img, $x0 + 48, $y0 + 58, 48, 48, 180, 360, $mint, IMG_ARC_PIE);
  imagesetthickness($img, 5);
  imageline($img, $x0 + 16, $y0 + 58, $x0 + 80, $y0 + 58, $white);
  imageline($img, $x0 + 30, $y0 + 72, $x0 + 60, $y0 + 72, $white);
  imageline($img, $x0 + 60, $y0 + 72, $x0 + 53, $y0 + 66, $white);
  imageline($img, $x0 + 60, $y0 + 72, $x0 + 53, $y0 + 78, $white);
}

$y = 290;
foreach ([[$line1, $white], [$line2, $white], [$accent, $mint]] as [$text, $color]) {
  if ($text !== '') {
    imagettftext($img, 54, 0, 80, $y, $color, $bold, $text);
    $y += 74;
  }
}
imagettftext($img, 24, 0, 82, $y + 14, $soft, $regular, $subtitle);
imagettftext($img, 20, 0, 82, 585, $soft, $regular, $footer);

imagepng($img, $out, 9);
imagedestroy($img);
echo "Written: $out\n";
