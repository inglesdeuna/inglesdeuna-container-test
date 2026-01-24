<?php
$pdf = __DIR__ . "/test.pdf";
$out = __DIR__ . "/pages";

if (!is_dir($out)) {
  mkdir($out, 0777, true);
}

$cmd = "pdftoppm $pdf $out/page -png 2>&1";
echo "<pre>";
echo shell_exec($cmd);
echo "</pre>";
